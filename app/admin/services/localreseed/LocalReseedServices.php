<?php

namespace app\admin\services\localreseed;

use app\admin\services\client\ClientServices;
use app\admin\support\NotifyAdmin;
use app\admin\support\NotifyHelper;
use app\model\Client;
use app\model\enums\DownloaderMarkerEnums;
use app\model\enums\LocalReseedStatusEnums;
use app\model\enums\NotifyChannelEnums;
use app\model\enums\ReseedStatusEnums;
use app\model\enums\ReseedSubtypeEnums;
use app\model\Folder;
use app\model\LocalReseed;
use app\model\payload\ReseedPayload;
use app\model\Reseed;
use app\model\Site;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Iyuu\BittorrentClient\ClientEnums;
use Iyuu\BittorrentClient\Exception\NotFoundException;
use plugin\cron\app\model\Crontab;
use support\Log;
use Throwable;

/**
 * 本地搜索式辅种服务
 * - 遍历下载器做种种子 → 站内搜索 → 字节级精确体积匹配 → 写入cn_reseed队列（复用投递管线）
 */
final class LocalReseedServices
{
    /**
     * 单轮时间预算默认值(秒) 必须小于计划任务硬超时1200秒
     */
    public const int DEFAULT_MAX_RUN_SECONDS = 900;
    /**
     * 每站每轮最大请求数默认值
     */
    public const int DEFAULT_MAX_REQUESTS = 100;
    /**
     * 相邻请求最小间隔默认值(秒)
     */
    public const int DEFAULT_INTERVAL = 5;
    /**
     * 每次搜索最多下载元数据的候选数默认值
     */
    public const int DEFAULT_MAX_CANDIDATES = 3;

    /**
     * 计划任务：数据模型
     * @var Crontab
     */
    protected Crontab $crontabModel;
    /**
     * 计划任务：目标站点（勾选的）
     * @var array
     */
    protected array $crontabSites = [];
    /**
     * 目标站点（可用的）
     * @var array<string, Site>
     */
    protected array $targetSites = [];
    /**
     * 来源下载器（已选择的）
     * @var array
     */
    protected array $crontabClients = [];
    /**
     * 主辅种下载器的数据模型
     * @var Client|null
     */
    protected ?Client $masterModel = null;
    /**
     * 路径过滤器
     * @var array
     */
    protected array $path_filter = [];
    /**
     * 通知渠道
     * @var NotifyChannelEnums|null
     */
    protected ?NotifyChannelEnums $notifyEnum;
    /**
     * 标记规则
     */
    protected DownloaderMarkerEnums $downloaderMarkerEnums;
    /**
     * 自动校验
     * @var string
     */
    protected string $auto_check = '';
    /**
     * 含死种搜索：0活种/1含死种/2仅死种
     */
    protected int $incldead = 1;
    /**
     * 搜索预算
     */
    protected SearchBudget $budget;
    /**
     * 每次搜索最多下载元数据的候选数
     */
    protected int $maxCandidates = self::DEFAULT_MAX_CANDIDATES;
    /**
     * 统计：本轮新增进度行数
     */
    protected int $statNewRows = 0;
    /**
     * 统计：命中数
     */
    protected int $statMatched = 0;
    /**
     * 统计：无匹配数
     */
    protected int $statNoMatch = 0;
    /**
     * 统计：失败数
     */
    protected int $statFailed = 0;
    /**
     * 统计：跳过数
     */
    protected int $statSkipped = 0;

    /**
     * @param int $crontab_id
     */
    public function __construct(public readonly int $crontab_id)
    {
        $this->parseCrontab($crontab_id);
    }

    /**
     * 执行本地辅种
     * @return void
     */
    public function run(): void
    {
        $this->resolveTargetSites();
        if (empty($this->targetSites)) {
            echo '没有可用的目标站点（需要：已启用+已适配+已配置cookie）' . PHP_EOL;
            return;
        }

        // 崩溃恢复：搜索中的行重置
        LocalReseed::resetMatching();

        $clientIds = [];
        foreach ($this->crontabClients as $client_id => $on) {
            $clientIds[] = (int)$client_id;
        }

        // 同步进度：本地做种种子 × 目标站点
        foreach ($clientIds as $client_id) {
            $torrents = $this->collectSeeding($client_id);
            if (empty($torrents)) {
                continue;
            }
            foreach ($this->targetSites as $site) {
                $inserted = LocalReseed::syncProgress($this->crontab_id, $client_id, $torrents, $site);
                if ($inserted) {
                    echo "站点 {$site->nickname} 新增待搜进度 {$inserted} 行" . PHP_EOL;
                    $this->statNewRows += $inserted;
                }
            }
        }

        $this->consume($clientIds);

        try {
            $this->sendNotify();
        } catch (Throwable $throwable) {
            Log::error('本地辅种后发送通知时异常：' . $throwable->getMessage());
        }
        echo '本地辅种本轮完毕' . PHP_EOL;
    }

    /**
     * 消费进度表：逐站逐行搜索匹配
     * @param array $client_ids
     * @return void
     */
    protected function consume(array $client_ids): void
    {
        foreach ($this->targetSites as $site) {
            // 站点限速配置抬升间隔
            $sleep = 0;
            try {
                $limit = (new \Iyuu\SiteManager\Config($site->toArray()))->getLimit();
                $sleep = (int)($limit['sleep'] ?? 0);
            } catch (Throwable) {
            }
            $this->budget->intervalAtLeast($sleep);

            $searchServices = new SiteSearchServices($site, $this->incldead, $this->maxCandidates);
            while (!$this->budget->timeUp() && $this->budget->allow($site->site)) {
                /** @var LocalReseed|null $row */
                $row = LocalReseed::nextPending($site->sid, $client_ids);
                if (!$row) {
                    echo "站点 {$site->nickname} 待搜进度已消费完毕" . PHP_EOL;
                    break;
                }

                $row->status = LocalReseedStatusEnums::Matching->value;
                $row->save();

                try {
                    $this->handleRow($row, $site, $searchServices);
                } catch (CookieInvalidException $exception) {
                    // cookie失效：本站本轮中止，剩余行留待搜索
                    $row->status = LocalReseedStatusEnums::Pending->value;
                    $row->message = mb_substr($exception->getMessage(), 0, 900);
                    $row->save();
                    echo $exception->getMessage() . '，本站本轮中止' . PHP_EOL;
                    NotifyAdmin::warning($exception->getMessage() . '，本站本轮中止');
                    break;
                } catch (Throwable $throwable) {
                    $row->status = LocalReseedStatusEnums::Failed->value;
                    $row->message = mb_substr($throwable->getMessage(), 0, 900);
                    $row->save();
                    $this->statFailed++;
                    echo '进度行处理异常：' . $throwable->getMessage() . PHP_EOL;
                }
            }

            if ($this->budget->timeUp()) {
                echo '本轮时间预算已耗尽，剩余进度留待下一轮' . PHP_EOL;
                break;
            }
        }
    }

    /**
     * 处理单行进度
     * @param LocalReseed $row
     * @param Site $site
     * @param SiteSearchServices $searchServices
     * @return void
     */
    protected function handleRow(LocalReseed $row, Site $site, SiteSearchServices $searchServices): void
    {
        $row->search_time = time();
        $keyword = SearchKeywordBuilder::build($row->torrent_name);
        if (null === $keyword) {
            $row->status = LocalReseedStatusEnums::Skipped->value;
            $row->message = '名称不适合搜索';
            $row->save();
            $this->statSkipped++;
            return;
        }
        $row->keyword = $keyword;

        $this->budget->hit($site->site);    // 搜索请求计数+休眠
        $result = $searchServices->searchAndMatch($keyword, (int)$row->torrent_size);

        $row->candidates = $result->candidates;
        if (LocalReseedStatusEnums::Matched === $result->status) {
            // 有效载荷
            $reseedPayload = new ReseedPayload();
            $reseedPayload->marker = $this->downloaderMarkerEnums->value;
            $reseedPayload->auto_check = $this->auto_check;

            $attributes = [
                'client_id' => $this->masterModel ? $this->masterModel->id : $row->client_id,
                'info_hash' => $result->infoHash,
            ];
            $values = [
                'site' => $site->site,
                'sid' => $site->sid,
                'torrent_id' => $result->torrentId,
                'group_id' => 0,
                'directory' => $row->directory,
                'dispatch_time' => 0,
                'status' => ReseedStatusEnums::Default->value,
                'subtype' => ReseedSubtypeEnums::Local->value,
                'payload' => (string)$reseedPayload
            ];
            $reseedModel = Reseed::firstOrCreate($attributes, $values);
            $row->reseed_id = (int)$reseedModel->reseed_id;
            $row->status = LocalReseedStatusEnums::Matched->value;
            $row->message = '命中：' . $result->name;
            $this->statMatched++;
            echo "命中 站点 {$site->site} 种子ID {$result->torrentId} 体积 {$row->torrent_size} 搜索词 {$keyword}" . PHP_EOL;
        } else {
            $row->status = $result->status;
            $row->message = $result->message;
            if (LocalReseedStatusEnums::NoMatch === $result->status) {
                $this->statNoMatch++;
            } elseif (LocalReseedStatusEnums::Failed === $result->status) {
                $this->statFailed++;
            } elseif (LocalReseedStatusEnums::Skipped === $result->status) {
                $this->statSkipped++;
            }
        }
        $row->save();

        // 元数据下载也计入预算（每个候选一次）
        $used = min($result->candidates, $this->maxCandidates);
        for ($i = 0; $i < $used; $i++) {
            $this->budget->allow($site->site) && $this->budget->hit($site->site);
        }
    }

    /**
     * 收集下载器内做种种子
     * @param int $client_id
     * @return LocalTorrentItem[]
     */
    protected function collectSeeding(int $client_id): array
    {
        try {
            $clientModel = ClientServices::getClient($client_id);
            $bittorrentClient = ClientServices::createBittorrent($clientModel);
        } catch (Throwable $throwable) {
            echo "下载器 {$client_id} 创建客户端失败：" . $throwable->getMessage() . PHP_EOL;
            return [];
        }

        echo "正在从 {$clientModel->title} 下载器获取做种种子..." . PHP_EOL;
        $items = [];
        try {
            $rows = match ($clientModel->getClientEnums()) {
                ClientEnums::qBittorrent => $this->collectQbittorrent($bittorrentClient),
                ClientEnums::transmission => $this->collectTransmission($bittorrentClient),
            };
        } catch (NotFoundException) {
            echo "{$clientModel->title} 下载器内没有做种种子" . PHP_EOL;
            return [];
        } catch (Throwable $throwable) {
            echo "从 {$clientModel->title} 下载器获取种子失败：" . $throwable->getMessage() . PHP_EOL;
            return [];
        }

        foreach ($rows as $hash => $row) {
            $directory = (string)($row['save_path'] ?? $row['downloadDir'] ?? '');
            // 路径过滤器
            if ($this->isPathFiltered($directory)) {
                continue;
            }
            $size = (int)($row['total_size'] ?? $row['size'] ?? $row['totalSize'] ?? 0);
            $items[] = new LocalTorrentItem((string)$hash, (string)($row['name'] ?? ''), $size, $directory, $client_id);
        }

        echo "{$clientModel->title} 下载器做种种子数（过滤后）：" . count($items) . PHP_EOL;
        return $items;
    }

    /**
     * qBittorrent做种列表
     * @param \Iyuu\BittorrentClient\Clients $clients
     * @return array
     */
    protected function collectQbittorrent(\Iyuu\BittorrentClient\Clients $clients): array
    {
        return $clients->getTorrentList()['lists'] ?? [];
    }

    /**
     * transmission做种列表（getTorrentList无体积字段，用getList自行过滤）
     * @param \Iyuu\BittorrentClient\Clients $clients
     * @return array
     */
    protected function collectTransmission(\Iyuu\BittorrentClient\Clients $clients): array
    {
        $rows = $clients->getList();
        $rs = [];
        foreach ($rows as $row) {
            if (6 === (int)($row['status'] ?? -1)) {
                $rs[$row['hashString']] = $row;
            }
        }
        return $rs;
    }

    /**
     * 目录是否被路径过滤器排除
     * @param string $directory
     * @return bool
     */
    protected function isPathFiltered(string $directory): bool
    {
        if (empty($this->path_filter)) {
            return false;
        }

        foreach ($this->path_filter as $prefix) {
            if (str_starts_with(rtrim($directory, DIRECTORY_SEPARATOR), rtrim($prefix, DIRECTORY_SEPARATOR))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 解析目标站点
     * @return void
     */
    protected function resolveTargetSites(): void
    {
        $sites = array_keys($this->crontabSites);
        if (empty($sites)) {
            throw new InvalidArgumentException('目标站点必填');
        }

        /** @var Site $siteModel */
        foreach (Site::getEnabled()->whereIn('site', $sites)->get() as $siteModel) {
            if (empty($siteModel->cookie)) {
                echo "站点 {$siteModel->site} 未配置cookie，跳过" . PHP_EOL;
                continue;
            }

            // 必须实现了HTML解析接口（Cookie驱动存在）
            $cookieClass = \Iyuu\SiteManager\BaseCookie::siteToClass($siteModel->site);
            if (!is_subclass_of($cookieClass, \Iyuu\SiteManager\BaseCookie::class)) {
                echo "站点 {$siteModel->site} 未适配爬虫驱动，跳过" . PHP_EOL;
                continue;
            }

            $this->targetSites[$siteModel->site] = $siteModel;
        }
    }

    /**
     * 发送通知
     * @return void
     * @throws GuzzleException
     */
    protected function sendNotify(): void
    {
        if (null === $this->notifyEnum) {
            return;
        }

        $br = PHP_EOL;
        $text = 'IYUU本地辅种-统计报表';
        $desp = '### 网站名称：' . get_system_title() . $br;
        $desp .= '**目标站点：' . implode('、', array_keys($this->targetSites)) . '**' . $br;
        $desp .= '**新增进度：' . $this->statNewRows . '**' . $br;
        $desp .= '**命中：' . $this->statMatched . '**' . $br;
        $desp .= '**无匹配：' . $this->statNoMatch . '**' . $br;
        $desp .= '**失败：' . $this->statFailed . '**' . $br;
        $desp .= '**跳过：' . $this->statSkipped . '**' . $br;

        $response = match ($this->notifyEnum) {
            NotifyChannelEnums::notify_iyuu => NotifyHelper::iyuu($text, $desp),
            NotifyChannelEnums::notify_server_chan => NotifyHelper::serverChan($text, $desp),
            NotifyChannelEnums::notify_bark => NotifyHelper::bark($text, $desp),
            NotifyChannelEnums::notify_qy_weixin => NotifyHelper::weWork($text . $br . $desp),
            NotifyChannelEnums::notify_webhook => NotifyHelper::webhook($text, $desp),
            default => null
        };
        if ($response) {
            Log::info('本地辅种结束发送通知后的响应：' . $response->getBody());
        }
    }

    /**
     * 解析任务
     * @param int $crontab_id
     */
    private function parseCrontab(int $crontab_id): void
    {
        $crontabModel = Crontab::find($crontab_id);
        if (!$crontabModel) {
            throw new InvalidArgumentException('计划任务数据不存在');
        }

        $parameter = $crontabModel->parameter;
        if (is_string($parameter)) {
            $parameter = json_decode($parameter, true);
        }
        $sites = $parameter['sites'] ?? [];
        $clients = $parameter['clients'] ?? [];
        // 路径过滤器
        if ($path_filter = $parameter['path_filter'] ?? []) {
            $this->path_filter = Folder::whereIn('folder_id', explode(',', $path_filter))->pluck('folder_value')->toArray();
        }

        $notify_channel = $parameter['notify_channel'] ?? '';
        $marker = DownloaderMarkerEnums::from($parameter['marker'] ?? DownloaderMarkerEnums::Empty->value);
        $auto_check = $parameter['auto_check'] ?? '';
        $this->incldead = (int)($parameter['incldead'] ?? 1);
        $max_candidates = (int)($parameter['max_candidates'] ?? self::DEFAULT_MAX_CANDIDATES);
        $this->maxCandidates = max(1, min(10, $max_candidates));

        // 主辅种下载的主键id
        $master = $parameter['master'] ?? null;
        if ($master) {
            $this->masterModel = ClientServices::getClient((int)$master);
        }

        $max_run_seconds = (int)($parameter['max_run_seconds'] ?? self::DEFAULT_MAX_RUN_SECONDS);
        $max_run_seconds = max(60, min(1100, $max_run_seconds));
        $max_requests = (int)($parameter['max_requests'] ?? self::DEFAULT_MAX_REQUESTS);
        $max_requests = max(1, $max_requests);
        $interval = (int)($parameter['search_interval'] ?? self::DEFAULT_INTERVAL);
        $interval = max(0, min(60, $interval));
        $this->budget = new SearchBudget($max_run_seconds, $max_requests, $interval);

        $this->crontabModel = $crontabModel;
        $this->crontabSites = $sites;
        $this->crontabClients = $clients;
        $this->notifyEnum = NotifyChannelEnums::tryFrom($notify_channel);
        $this->downloaderMarkerEnums = $marker;
        $this->auto_check = $auto_check;
    }
}
