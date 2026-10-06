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
use app\model\SiteTorrent;
use app\model\SiteIndex;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use Iyuu\BittorrentClient\ClientEnums;
use Iyuu\BittorrentClient\Exception\NotFoundException;
use plugin\cron\app\model\Crontab;
use support\Log;
use Throwable;

/**
 * 本地辅种服务（索引模式）
 * - 阶段A 建库：翻页抓取目标站种子索引到 cn_site_torrent（请求只花在这里）
 * - 阶段B 匹配：本地做种种子 vs 索引库 本地比对（零请求），命中候选下载元数据做字节级确认
 * - 命中写入 cn_reseed 队列，复用现有投递管线
 */
final class LocalReseedServices
{
    /**
     * 单轮时间预算默认值(秒) 必须小于计划任务硬超时1200秒
     */
    public const int DEFAULT_MAX_RUN_SECONDS = 900;
    /**
     * 每站每轮最大请求数默认值（建库翻页+元数据下载合计）
     */
    public const int DEFAULT_MAX_REQUESTS = 100;
    /**
     * 相邻请求最小间隔默认值(秒)
     */
    public const int DEFAULT_INTERVAL = 5;
    /**
     * 每个本地种子最多校验的候选数默认值
     */
    public const int DEFAULT_MAX_CANDIDATES = 3;
    /**
     * 每轮建库默认翻页数
     */
    public const int DEFAULT_INDEX_PAGES = 100;

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
     * 含死种：0活种/1含死种/2仅死种
     */
    protected int $incldead = 1;
    /**
     * 搜索预算
     */
    protected SearchBudget $budget;
    /**
     * 每个本地种子最多校验的候选数
     */
    protected int $maxCandidates = self::DEFAULT_MAX_CANDIDATES;
    /**
     * 每轮建库翻页数
     */
    protected int $indexPages = self::DEFAULT_INDEX_PAGES;
    /**
     * 全库模式
     */
    protected bool $fullIndex = false;
    /**
     * 统计
     */
    protected int $statNewRows = 0;
    protected int $statIndexNew = 0;
    protected int $statMatched = 0;
    protected int $statNoMatch = 0;
    protected int $statFailed = 0;
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

        // 崩溃恢复
        LocalReseed::resetMatching();

        $clientIds = [];
        foreach ($this->crontabClients as $client_id => $on) {
            $clientIds[] = (int)$client_id;
        }

        // 同步本地做种到进度表
        foreach ($clientIds as $client_id) {
            $torrents = $this->collectSeeding($client_id);
            if (empty($torrents)) {
                continue;
            }
            foreach ($this->targetSites as $site) {
                $inserted = LocalReseed::syncProgress($this->crontab_id, $client_id, $torrents, $site);
                if ($inserted) {
                    echo "站点 {$site->nickname} 新增待匹配进度 {$inserted} 行" . PHP_EOL;
                    $this->statNewRows += $inserted;
                }
            }
        }

        foreach ($this->targetSites as $site) {
            // 阶段A 建库
            $this->buildIndex($site);
            // 阶段B 本地匹配
            $this->matchLocal($clientIds, $site);
            if ($this->budget->timeUp()) {
                echo '本轮时间预算已耗尽，剩余进度留待下一轮' . PHP_EOL;
                break;
            }
        }

        try {
            $this->sendNotify();
        } catch (Throwable $throwable) {
            Log::error('本地辅种后发送通知时异常：' . $throwable->getMessage());
        }
        echo '本地辅种本轮完毕' . PHP_EOL;
    }

    /**
     * 阶段A：建库
     * @param Site $site
     * @return void
     */
    protected function buildIndex(Site $site): void
    {
        // 站点限速配置抬升间隔
        try {
            $limit = (new \Iyuu\SiteManager\Config($site->toArray()))->getLimit();
            $this->budget->intervalAtLeast((int)($limit['sleep'] ?? 0));
        } catch (Throwable) {
        }

        try {
            $crawler = new SiteIndexCrawler($site, $this->budget, $this->incldead);
            $stat = $crawler->crawl($this->indexPages, $this->fullIndex);
            $this->statIndexNew += $stat['new_rows'];
            echo "站点 {$site->nickname} 建库：翻 {$stat['pages']} 页 新增 {$stat['new_rows']} 条 索引总量 {$stat['total']}" . ($stat['full_done'] ? '（全库完成）' : '') . PHP_EOL;
        } catch (CookieInvalidException $exception) {
            echo $exception->getMessage() . '，本站本轮中止' . PHP_EOL;
            NotifyAdmin::warning($exception->getMessage() . '，本站本轮中止');
            return;
        } catch (Throwable $throwable) {
            echo "站点 {$site->nickname} 建库异常：" . $throwable->getMessage() . PHP_EOL;
            NotifyAdmin::warning("站点 {$site->site} 建库异常：" . mb_substr($throwable->getMessage(), 0, 300));
        }
    }

    /**
     * 阶段B：本地匹配（零站点请求，命中候选才下载元数据确认）
     * - 索引持续增长，无匹配不落终态（始终可重跑）
     * - 已核验不符的候选ID记录在message，避免重复下载元数据
     * @param array $client_ids
     * @param Site $site
     * @return void
     */
    protected function matchLocal(array $client_ids, Site $site): void
    {
        // 载入站点索引到内存：title_key => 候选行列表
        $index = [];
        SiteTorrent::getBySid($site->sid)
            ->select(['torrent_id', 'title', 'title_key', 'size_bytes', 'free', 'download_uri'])
            ->chunk(1000, function ($chunk) use (&$index) {
                foreach ($chunk as $row) {
                    $index[$row->title_key][] = [
                        'torrent_id' => (int)$row->torrent_id,
                        'title' => (string)$row->title,
                        'size_bytes' => (int)$row->size_bytes,
                        'free' => (int)$row->free,
                        'download_uri' => (string)$row->download_uri,
                    ];
                }
            });
        if (empty($index)) {
            echo "站点 {$site->nickname} 索引为空，跳过匹配" . PHP_EOL;
            return;
        }

        $verifyServices = new SiteSearchServices($site);
        $lastId = 0;
        while (!$this->budget->timeUp()) {
            /** @var LocalReseed[] $rows 无匹配(2)与失败(4)均可重跑（索引会增长） */
            $rows = LocalReseed::where('target_sid', '=', $site->sid)
                ->whereIn('client_id', $client_ids)
                ->whereIn('status', [LocalReseedStatusEnums::Pending->value, LocalReseedStatusEnums::NoMatch->value, LocalReseedStatusEnums::Failed->value])
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(300)
                ->get();
            if ($rows->isEmpty()) {
                break;
            }

            $skipIds = [];
            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                $key = TitleNormalizer::key((string)$row->torrent_name);
                if ('' === $key) {
                    $skipIds[] = $row->id;
                    continue;
                }

                $candidates = $index[$key] ?? [];
                if (empty($candidates)) {
                    $this->statNoMatch++;
                    continue;
                }

                // 体积预筛（页面精度容差1%）
                $sizeOk = array_values(array_filter($candidates, static fn($c) => TitleNormalizer::sizeClose((int)$c['size_bytes'], (int)$row->torrent_size)));
                if (empty($sizeOk)) {
                    $this->statNoMatch++;
                    continue;
                }

                // 免费优先
                usort($sizeOk, static fn($a, $b) => ($b['free'] <=> $a['free']));

                // 已核验不符的候选ID（避免重复下载元数据）
                $verified = [];
                if (preg_match('/已核:([\d,]+)/u', (string)$row->message, $mV)) {
                    $verified = array_map('intval', explode(',', $mV[1]));
                }

                $row->keyword = mb_substr($key, 0, 190);
                $row->candidates = count($candidates);
                $row->search_time = time();

                $matched = false;
                foreach (array_slice($sizeOk, 0, $this->maxCandidates + count($verified)) as $candidate) {
                    if (in_array((int)$candidate['torrent_id'], $verified, true)) {
                        continue;
                    }
                    if (!$this->budget->allow($site->site)) {
                        break;
                    }
                    $this->budget->hit($site->site);
                    try {
                        $result = $verifyServices->verify($candidate, (int)$row->torrent_size);
                    } catch (CookieInvalidException $exception) {
                        $row->message = mb_substr($exception->getMessage(), 0, 900);
                        $row->save();
                        echo $exception->getMessage() . '，本站本轮中止' . PHP_EOL;
                        NotifyAdmin::warning($exception->getMessage() . '，本站本轮中止');
                        $this->batchClose($skipIds);
                        return;
                    } catch (Throwable $throwable) {
                        $this->statFailed++;
                        continue;
                    }

                    if (LocalReseedStatusEnums::Matched === $result->status) {
                        $this->enqueue($row, $site, $result);
                        $matched = true;
                        $this->statMatched++;
                        break;
                    }

                    // 核验不符：记录候选ID
                    $verified[] = (int)$candidate['torrent_id'];
                }

                if (!$matched) {
                    $row->status = LocalReseedStatusEnums::Pending->value;
                    $row->message = $verified ? '已核:' . implode(',', $verified) : '';
                    $row->save();
                    $this->statNoMatch++;
                }
                if ($this->budget->timeUp()) {
                    break;
                }
            }

            $this->batchClose($skipIds);
            if ($rows->count() < 300) {
                break;
            }
        }
    }

    /**
     * 批量关闭进度行（不可搜索名称）
     * @param array $skipIds
     * @return void
     */
    protected function batchClose(array $skipIds): void
    {
        if ($skipIds) {
            foreach (array_chunk($skipIds, 500) as $chunk) {
                LocalReseed::whereIn('id', $chunk)->update(['status' => LocalReseedStatusEnums::Skipped->value, 'search_time' => time()]);
            }
            $this->statSkipped += count($skipIds);
        }
    }

    /**
     * 命中入队 cn_reseed（复用投递管线）
     * @param LocalReseed $row
     * @param Site $site
     * @param MatchResult $result
     * @return void
     */
    protected function enqueue(LocalReseed $row, Site $site, MatchResult $result): void
    {
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
        $row->save();
        echo "命中 站点 {$site->site} 种子ID {$result->torrentId} 体积 {$row->torrent_size} 标题 {$result->name}" . PHP_EOL;
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
        $desp .= '**索引新增：' . $this->statIndexNew . '**  [本轮建库新增的站内种子数]' . $br;
        $desp .= '**新增进度：' . $this->statNewRows . '**  [本地新做种数]' . $br;
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
        if ($path_filter = $parameter['path_filter'] ?? []) {
            $this->path_filter = Folder::whereIn('folder_id', explode(',', $path_filter))->pluck('folder_value')->toArray();
        }

        $notify_channel = $parameter['notify_channel'] ?? '';
        $marker = DownloaderMarkerEnums::from((string)($parameter['marker'] ?? DownloaderMarkerEnums::Empty->value));
        $auto_check = $parameter['auto_check'] ?? '';
        $this->incldead = (int)($parameter['incldead'] ?? 1);
        $max_candidates = (int)($parameter['max_candidates'] ?? self::DEFAULT_MAX_CANDIDATES);
        $this->maxCandidates = max(1, min(10, $max_candidates));
        $this->indexPages = max(1, (int)($parameter['index_pages'] ?? self::DEFAULT_INDEX_PAGES));
        $this->fullIndex = (bool)($parameter['full_index'] ?? false);

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
        $this->notifyEnum = NotifyChannelEnums::tryFrom((string)$notify_channel);
        $this->downloaderMarkerEnums = $marker;
        $this->auto_check = $auto_check;
    }
}
