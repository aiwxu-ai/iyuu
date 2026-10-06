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
 *   - 第一遍：标题键全等（title_key 或 latin第二键）——精度最高，优先吃校验预算
 *   - 第二遍：体积桶+token（默认关闭bucket_match，假阳性/请求比差2-3个数量级）
 * - 阶段A2 关键词引导搜索：IYUU跨站已验证hash用特征词单token搜索，直达索引未覆盖区
 * - 终裁标准：元数据infohash === 本地infohash（跨站辅种的定义）；体积只是预筛
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
    public const int DEFAULT_INTERVAL = 10;
    /**
     * 每站每日请求硬上限默认值(台账强制，含失败)
     */
    public const int DEFAULT_DAILY_REQUESTS = 800;
    /**
     * 每个本地种子最多校验的候选数默认值
     */
    public const int DEFAULT_MAX_CANDIDATES = 3;
    /**
     * 每轮建库默认翻页数
     */
    public const int DEFAULT_INDEX_PAGES = 100;
    /**
     * 关键词引导搜索的重搜间隔(秒)：12小时内搜过的行不再搜
     */
    public const int SEARCH_GUIDED_REQUEUE_SECONDS = 43200;
    /**
     * 引导搜索最多占用本站时间片的40%（剩余留给元数据校验）
     */
    protected const float SEARCH_GUIDED_SLICE_RATIO = 0.4;
    /**
     * 搜索词排除的通用词（无区分度；分辨率/编码类token由结构正则排除）
     */
    protected const array SEARCH_STOPWORDS = ['the', 'and', 'for', 'with', 'from', 'into', 'season', 'complete', 'multi', 'audio', 'dual', 'proper', 'repack', 'extended', 'remastered', 'internal', 'limited', 'unrated', 'runtime', 'subbed', 'chinese', 'english', 'jun', 'part',
        'bluray', 'blu', 'ray', 'uhd', 'hdr', 'dolby', 'vision', 'remux', 'flac', 'ddp', 'dts', 'ac3', 'ma', 'hevc', 'x264', 'x265', 'h264', 'h265', 'avc', 'web', 'webdl', 'hdtv', 'atmos', 'imax', 'hack', 'hackui', 'iuvc', 'hq', '10bit', 'webrip', 'bdrip', 'dvdrip', 'hdrip', 'truehd', '60fps', 'dovi',
        'movie', 'movies', 'film', 'films', 'series', 'show', 'ep', 'episode', 'episodes', 'vivid', 'hybrid', 'nv'];

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
     * 搜索预算（含跨进程全局台账）
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
     * 体积桶+token路径开关（默认关：黄金子集上边际贡献0.44%而假阳性≈100%）
     */
    protected bool $bucketMatch = false;
    /**
     * 站点级校验结果缓存 site => hash => ['ok'=>[tid,siteHash,name], 'no'=>[tid...]]
     * （进程内跨阶段共享：matchLocal得出的结论searchGuided直接复用）
     */
    protected array $siteVerifyCache = [];
    /**
     * 统计
     */
    protected int $statNewRows = 0;
    protected int $statIndexNew = 0;
    protected int $statSearchGuided = 0;
    protected int $statSearchIngest = 0;
    protected int $statVerify = 0;
    protected int $statMatchedHashes = 0;
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

        // 索引量少的站优先（新站空索引先建库；避免大站独吞本轮时间预算）
        $sizes = SiteIndex::whereIn('sid', array_column(array_values($this->targetSites), 'sid'))
            ->pluck('total_indexed', 'sid')->toArray();
        uasort($this->targetSites, static fn(Site $a, Site $b) => ($sizes[$a->sid] ?? 0) <=> ($sizes[$b->sid] ?? 0));

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

        $remainingSites = count($this->targetSites);
        foreach ($this->targetSites as $site) {
            // 时间分片：剩余时间均分给剩余站点，防止某站独吞整轮预算
            $this->budget->setSiteDeadline($this->budget->remainingSeconds() / max(1, $remainingSites));
            $this->siteVerifyCache = [];
            $remainingSites--;

            // 阶段A 建库
            $this->buildIndex($site);
            // 阶段B 本地匹配（标题键候选精度最高，优先吃校验预算）
            $this->matchLocal($clientIds, $site);
            // 阶段A2 关键词引导搜索（IYUU跨站已验证hash优先，用剩余预算）
            $this->searchGuided($site);
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
        } catch (Throwable $throwable) {
            echo "站点 {$site->nickname} 建库异常：" . $throwable->getMessage() . PHP_EOL;
            NotifyAdmin::warning("站点 {$site->site} 建库异常：" . mb_substr($throwable->getMessage(), 0, 300));
        }
    }

    /**
     * 阶段A2：关键词引导搜索（IYUU-hash优先，直接匹配）
     * - IYUU官方辅种成功的hash（cn_reseed记录）= 已确认在其他站存在 → 大概率目标站也有
     * - 按hash去重后逐个用特征词做站内单token搜索：结果入库索引 + 体积吻合候选当场infohash裁决入队
     * - NexusPHP的search是整串模糊匹配，多词短语必然零结果，故只取一个最具区分度的词
     * - 最多占用本站时间片的40%，剩余留给元数据校验
     * @param Site $site
     * @return void
     */
    protected function searchGuided(Site $site): void
    {
        // 站点限速配置抬升间隔
        try {
            $limit = (new \Iyuu\SiteManager\Config($site->toArray()))->getLimit();
            $this->budget->intervalAtLeast((int)($limit['sleep'] ?? 0));
        } catch (Throwable) {
        }

        try {
            $crawler = new SiteIndexCrawler($site, $this->budget, $this->incldead);
            $verifyServices = new SiteSearchServices($site);
        } catch (Throwable $throwable) {
            echo "站点 {$site->nickname} 创建爬虫失败：" . $throwable->getMessage() . PHP_EOL;
            return;
        }

        // 本站时间片内引导搜索的截止
        $guidedDeadline = microtime(true) + ($this->budget->remainingSeconds() * self::SEARCH_GUIDED_SLICE_RATIO);
        $minTs = time() - self::SEARCH_GUIDED_REQUEUE_SECONDS;
        $lastId = 0;
        $searched = 0;
        $ingested = 0;
        $firstError = '';
        while (!$this->budget->timeUp() && !$this->budget->siteSliceUp() && $this->budget->allow($site->site)) {
            if (microtime(true) >= $guidedDeadline) {
                echo "站点 {$site->nickname} 引导搜索：已到本站时间片40%配额，剩余留给元数据校验" . PHP_EOL;
                break;
            }

            /** @var LocalReseed[] $rows 只处理IYUU已跨站验证过的hash，按hash去重（每hash取代表行） */
            $ids = LocalReseed::where('target_sid', '=', $site->sid)
                ->where('status', '=', LocalReseedStatusEnums::Pending->value)
                ->where('torrent_size', '>', 0)
                ->where('search_time', '<', $minTs)
                ->whereRaw("EXISTS (SELECT 1 FROM cn_reseed r WHERE r.info_hash = cn_local_reseed.info_hash AND r.info_hash <> '')")
                ->where('id', '>', $lastId)
                ->selectRaw('MIN(id) AS id')
                ->groupBy('info_hash')
                ->orderBy('id')
                ->limit(50)
                ->pluck('id');
            $rows = $ids->isEmpty() ? collect() : LocalReseed::whereIn('id', $ids->all())->orderBy('id')->get();
            if ($rows->isEmpty()) {
                if (0 === $searched) {
                    echo "站点 {$site->nickname} 引导搜索：无可搜索行（已搜空或12小时内已搜）" . PHP_EOL;
                }
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row->id;
                if ($this->budget->timeUp() || $this->budget->siteSliceUp() || !$this->budget->allow($site->site) || microtime(true) >= $guidedDeadline) {
                    break 2;
                }

                // hash级短路：本进程内已有结论
                $hash = (string)$row->info_hash;
                if (isset($this->siteVerifyCache[$hash]['ok'])) {
                    continue;
                }

                $token = $this->pickSearchToken((string)$row->torrent_name);
                if ('' === $token) {
                    continue;
                }

                $this->budget->hit($site->site);
                try {
                    $resultRows = $crawler->search($token);
                } catch (CookieInvalidException $exception) {
                    echo $exception->getMessage() . '，本站本轮中止' . PHP_EOL;
                    NotifyAdmin::warning($exception->getMessage() . '，本站本轮中止');
                    return;
                } catch (Throwable $throwable) {
                    if ('' === $firstError) {
                        $firstError = get_class($throwable) . ': ' . $throwable->getMessage();
                    }
                    continue;
                }
                $searched++;

                // 搜索结果全部入库（索引增量，供阶段B本地匹配复用）
                $ingested += $crawler->ingest($resultRows);
                $row->keyword = mb_substr($token, 0, 190);
                $row->candidates = count($resultRows);
                $row->search_time = time();

                // 体积吻合候选：当场下载元数据做infohash裁决（体积差升序）
                $localSize = (int)$row->torrent_size;
                $cands = array_values(array_filter($resultRows, static fn($c) => (int)$c['size_bytes'] > 0 && TitleNormalizer::sizeClose((int)$c['size_bytes'], $localSize)));
                usort($cands, static fn($a, $b) => abs((int)$a['size_bytes'] - $localSize) <=> abs((int)$b['size_bytes'] - $localSize));
                foreach (array_slice($cands, 0, 2) as $cand) {
                    if (!$this->budget->allow($site->site)) {
                        break;
                    }
                    $this->budget->hit($site->site);
                    $this->statVerify++;
                    try {
                        $result = $verifyServices->verify($cand, $localSize, $hash);
                    } catch (CookieInvalidException $exception) {
                        $row->message = mb_substr($exception->getMessage(), 0, 900);
                        $row->save();
                        echo $exception->getMessage() . '，本站本轮中止' . PHP_EOL;
                        NotifyAdmin::warning($exception->getMessage() . '，本站本轮中止');
                        return;
                    } catch (Throwable $throwable) {
                        continue;
                    }

                    if (LocalReseedStatusEnums::Matched === $result->status) {
                        $this->enqueueFamily($row, $site, $result);
                        $this->siteVerifyCache[$hash]['ok'] = [(int)$result->torrentId, $result->infoHash, (string)$result->name];
                        break;
                    }
                    if (LocalReseedStatusEnums::NoMatch === $result->status) {
                        $this->siteVerifyCache[$hash]['no'][] = (int)$cand['torrent_id'];
                    }
                    // Failed(瞬时失败)不记录——下一轮自然重试
                }

                if (LocalReseedStatusEnums::Matched->value !== (int)$row->status) {
                    $row->save();
                }
            }
        }

        if ($searched > 0) {
            $this->statSearchGuided += $searched;
            $this->statSearchIngest += $ingested;
            echo "站点 {$site->nickname} 关键词引导：搜索 {$searched} 次 补充索引 {$ingested} 条" . ($firstError ? ' 首个异常：' . mb_substr($firstError, 0, 200) : '') . PHP_EOL;
        } elseif ('' !== $firstError) {
            echo "站点 {$site->nickname} 引导搜索全部异常，首个：" . mb_substr($firstError, 0, 300) . PHP_EOL;
            NotifyAdmin::warning("站点 {$site->site} 引导搜索全部异常：" . mb_substr($firstError, 0, 300));
        }
    }

    /**
     * 从本地种子名提取最具区分度的搜索词（纯ascii、非通用词、优先标题词）
     * @param string $name
     * @return string
     */
    protected function pickSearchToken(string $name): string
    {
        $key = TitleNormalizer::key($name);
        if ('' === $key) {
            return '';
        }

        $best = '';
        $bestScore = -1;
        foreach (explode(' ', $key) as $i => $token) {
            if (!preg_match('/^[a-z][a-z0-9\']{3,}$/', $token)) {
                continue;    // 跳过中文、纯数字、短词
            }
            // 结构性排除：集数/盘符/分辨率等无区分度token（对整站霰弹枪搜索）
            if (preg_match('/^(s\d+e\d+|e\d{1,3}|s\d+|disc?\d+|\d{3,4}p|\d+bit|\d+fps)$/', $token)) {
                continue;
            }
            if (in_array($token, self::SEARCH_STOPWORDS, true)) {
                continue;
            }
            // 标题词在前（分辨率/编码词之前）：长度×2再减位次，保证 salon(kitty) 赢 bluray
            $score = min(strlen($token), 10) * 2 - $i;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $token;
            }
        }
        return $best;
    }

    /**
     * 阶段B：本地匹配（零站点请求，命中候选才下载元数据确认）
     * - 第一遍标题键全等（title_key 或 latin第二键），第二遍体积桶（bucket_match开关控制）
     * - 索引持续增长，无匹配不落终态（始终可重跑）
     * - 已核不符的候选ID记录在message，瞬时失败(待重试)不掩埋候选
     * @param array $client_ids
     * @param Site $site
     * @return void
     */
    protected function matchLocal(array $client_ids, Site $site): void
    {
        echo "本地匹配开始 预算已用 {$this->budget->used($site->site)} 时间剩余 " . (int)$this->budget->remainingSeconds() . "s" . PHP_EOL;
        $t0 = microtime(true);

        // 载入站点索引：title_key => 行列表 + latin第二键（站内"中文.English"名的拉丁尾部）
        $index = [];
        $latinIndex = [];
        SiteTorrent::getBySid($site->sid)
            ->select(['torrent_id', 'title', 'title_key', 'size_bytes', 'free', 'download_uri'])
            ->chunk(1000, function ($chunk) use (&$index, &$latinIndex) {
                foreach ($chunk as $row) {
                    $c = [
                        'torrent_id' => (int)$row->torrent_id,
                        'title' => (string)$row->title,
                        'title_key' => (string)$row->title_key,
                        'size_bytes' => (int)$row->size_bytes,
                        'free' => (int)$row->free,
                        'download_uri' => (string)$row->download_uri,
                    ];
                    $index[$row->title_key][] = $c;
                    $lk = TitleNormalizer::latinKey((string)$row->title);
                    if ('' !== $lk && $lk !== (string)$row->title_key) {
                        $latinIndex[$lk][] = $c;
                    }
                }
            });
        if (empty($index)) {
            echo "站点 {$site->nickname} 索引为空，跳过匹配" . PHP_EOL;
            return;
        }

        // 构建体积桶索引：size_bytes => 候选行列表（页面精度舍入后聚合）
        $bySize = [];
        foreach ($index as $rows0) {
            foreach ($rows0 as $c) {
                if ((int)$c['size_bytes'] > 0) {
                    $bySize[(int)$c['size_bytes']][] = $c;
                }
            }
        }
        ksort($bySize);
        $sizeKeys = array_keys($bySize);
        echo "索引载入 " . count($index) . "键/" . count($sizeKeys) . "桶 耗时 " . round(microtime(true) - $t0, 1) . "s" . PHP_EOL;

        $verifyServices = new SiteSearchServices($site);
        foreach ([true, false] as $passExact) {
            if ($this->budget->timeUp() || $this->budget->siteSliceUp()) {
                break;
            }
            if (!$passExact && !$this->bucketMatch) {
                continue;    // 体积桶路径默认关闭
            }
            echo ($passExact ? '扫描第一遍：标题键全等行' : '扫描第二遍：体积桶行') . PHP_EOL;
            $this->scanRows($client_ids, $site, $index, $latinIndex, $bySize, $sizeKeys, $verifyServices, $passExact);
        }
    }

    /**
     * 扫描并校验一批进度行
     * @param array $client_ids
     * @param Site $site
     * @param array $index title_key => 候选行
     * @param array $latinIndex latinKey => 候选行（站内中文名的拉丁尾部）
     * @param array $bySize
     * @param array $sizeKeys
     * @param SiteSearchServices $verifyServices
     * @param bool $passExact 本遍是否只处理标题键全等行
     * @return void
     */
    protected function scanRows(array $client_ids, Site $site, array $index, array $latinIndex, array $bySize, array $sizeKeys, SiteSearchServices $verifyServices, bool $passExact): void
    {
        $lastId = 0;
        $statCandidates = 0;
        $verifyCache = &$this->siteVerifyCache;
        // NOT EXISTS 的client_id必须与enqueue实际落库口径一致（master设置时入队到master）
        $reseedClientId = $this->masterModel ? (int)$this->masterModel->id : -1;
        while (!$this->budget->timeUp() && !$this->budget->siteSliceUp()) {
            /** @var LocalReseed[] $rows 无匹配(2)与失败(4)均可重跑（索引会增长）
             *  排除已在cn_reseed存在同客户端同hash的行（该客户端已辅过此种子） */
            $query = LocalReseed::where('target_sid', '=', $site->sid)
                ->whereIn('client_id', $client_ids)
                ->whereIn('status', [LocalReseedStatusEnums::Pending->value, LocalReseedStatusEnums::NoMatch->value, LocalReseedStatusEnums::Failed->value]);
            if ($reseedClientId > 0) {
                $query->whereRaw("NOT EXISTS (SELECT 1 FROM cn_reseed cr WHERE cr.client_id = {$reseedClientId} AND cr.info_hash = cn_local_reseed.info_hash)");
            } else {
                $query->whereRaw('NOT EXISTS (SELECT 1 FROM cn_reseed cr WHERE cr.client_id = cn_local_reseed.client_id AND cr.info_hash = cn_local_reseed.info_hash)');
            }
            $rows = $query->where('id', '>', $lastId)
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

                // hash级短路：同hash行本进程内已有终态结论
                $hash = (string)$row->info_hash;
                if (isset($verifyCache[$hash]['ok'])) {
                    $cachedOk = $verifyCache[$hash]['ok'];
                    $this->enqueue($row, $site, MatchResult::matched((int)$cachedOk[0], (string)$cachedOk[1], (string)$cachedOk[2], 1));
                    $this->statMatched++;
                    continue;
                }
                $cachedNo = $verifyCache[$hash]['no'] ?? [];

                // 候选查找第一遍：标题键全等 + latin第二键（本地中文名的拉丁尾部 ↔ 站内行）
                $candidates = $index[$key] ?? [];
                if ($passExact) {
                    $lk = TitleNormalizer::latinKey((string)$row->torrent_name);
                    if ('' !== $lk) {
                        foreach ([$index[$lk] ?? [], $latinIndex[$lk] ?? []] as $extra) {
                            foreach ($extra as $c) {
                                $candidates[$c['torrent_id']] = $c;    // 按tid去重合并
                            }
                        }
                    }
                    $candidates = array_values($candidates);
                    $hasExact = 0 !== count($candidates);
                    if (!$hasExact) {
                        continue;    // 第一遍只处理有全等候选的行
                    }
                } else {
                    // 第二遍：体积桶+token（仅在开关开启时到达此处）
                    if (preg_match('/\p{Han}/u', (string)$row->torrent_name)) {
                        continue;    // 中文名行不进桶路径：token交集对CJK无意义且假阳性高
                    }
                    $candidates = [];
                    $localSize = (int)$row->torrent_size;
                    if ($localSize > 0) {
                        $tol = max((int)($localSize * 0.008), 6291456);
                        $lower = (int)($localSize - $tol);
                        $upper = (int)($localSize + $tol);
                        $lo = 0;
                        $hi = count($sizeKeys);
                        while ($lo < $hi) {
                            $mid = ($lo + $hi) >> 1;
                            if ($sizeKeys[$mid] < $lower) {
                                $lo = $mid + 1;
                            } else {
                                $hi = $mid;
                            }
                        }
                        $localTokens = array_flip(explode(' ', $key));
                        for ($i = $lo, $cnt = count($sizeKeys); $i < $cnt && $sizeKeys[$i] <= $upper; $i++) {
                            foreach ($bySize[$sizeKeys[$i]] as $c) {
                                // token交集：≥3个、≥短边50%、且含至少一个结构性区分词
                                $cTokens = array_flip(explode(' ', (string)$c['title_key']));
                                $common = array_intersect_key($localTokens, $cTokens);
                                $overlap = count($common);
                                if ($overlap < 3 || $overlap < (int)floor(min(count($localTokens), count($cTokens)) * 0.5)) {
                                    continue;
                                }
                                $hasDistinctive = false;
                                foreach ($common as $token => $_) {
                                    if ($this->isDistinctiveToken((string)$token)) {
                                        $hasDistinctive = true;
                                        break;
                                    }
                                }
                                if ($hasDistinctive) {
                                    $candidates[] = $c;
                                }
                            }
                        }
                    }
                    if (empty($candidates)) {
                        $this->statNoMatch++;
                        continue;
                    }
                }
                $statCandidates++;
                if (0 === $statCandidates % 50) {
                    echo "扫描至 id={$lastId} 候选行 {$statCandidates} 预算 {$this->budget->used($site->site)}/{$this->budget->maxRequests()} 站点剩余 " . (int)$this->budget->remainingSeconds() . "s" . PHP_EOL;
                }

                // 体积预筛(元数据校验门槛)：exact行用0.25%/12MB(2×页面舍入±5.4MB)，桶行用0.25%/6MB
                // 不回退——体积差>门限=不同内容，下载元数据必败，纯浪费请求（曾烧掉一半预算）
                $localSize = (int)$row->torrent_size;
                $floor = $passExact ? 12582912 : 6291456;
                $sizeOk = array_values(array_filter($candidates, static fn($c) => TitleNormalizer::sizeClose((int)$c['size_bytes'], $localSize, 0.0025, $floor)));
                if (empty($sizeOk)) {
                    $this->statNoMatch++;
                    continue;
                }

                // 候选排序：|体积差|升序（最小差最可能是同发布），差值相同免费优先
                usort($sizeOk, static function ($a, $b) use ($localSize) {
                    $d = abs((int)$a['size_bytes'] - $localSize) <=> abs((int)$b['size_bytes'] - $localSize);
                    return 0 !== $d ? $d : ($b['free'] <=> $a['free']);
                });

                // 已核验不符的候选ID（跨轮记忆；"待重试"的不算——瞬时失败要重试）
                $verified = [];
                if (preg_match('/已核:([\d,]+)/u', (string)$row->message, $mV)) {
                    $verified = array_map('intval', explode(',', $mV[1]));
                }

                $row->keyword = mb_substr($key, 0, 190);
                $row->candidates = count($candidates);
                $row->search_time = time();

                $matched = false;
                foreach (array_slice($sizeOk, 0, $this->maxCandidates + count($verified)) as $candidate) {
                    if (in_array((int)$candidate['torrent_id'], $verified, true) || in_array((int)$candidate['torrent_id'], $cachedNo, true)) {
                        continue;
                    }
                    if (!$this->budget->allow($site->site)) {
                        echo "校验预算耗尽(used={$this->budget->used($site->site)})，停止扫描" . PHP_EOL;
                        break 2;
                    }
                    $this->budget->hit($site->site);
                    $this->statVerify++;
                    try {
                        $result = $verifyServices->verify($candidate, $localSize, $hash);
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
                        $this->enqueueFamily($row, $site, $result);
                        $verifyCache[$hash]['ok'] = [(int)$result->torrentId, $result->infoHash, (string)$result->name];
                        $matched = true;
                        break;
                    }

                    if (LocalReseedStatusEnums::NoMatch === $result->status) {
                        // 真不符（infohash/体积裁决）：跨轮记忆，兄弟行共享
                        $verified[] = (int)$candidate['torrent_id'];
                        $verifyCache[$hash]['no'][] = (int)$candidate['torrent_id'];
                    }
                    // Failed(瞬时失败)不记录——下一轮自然重试，不掩埋候选
                }

                if (!$matched) {
                    $row->status = LocalReseedStatusEnums::Pending->value;
                    $row->message = $verified ? '已核:' . implode(',', $verified) : '';
                    $row->save();
                    if ($verified) {
                        // 结论同步兄弟行（同hash其他客户端）：免得兄弟行重复下载同一元数据
                        LocalReseed::syncSiblings($hash, (int)$site->sid, (int)$row->id, [
                            'message' => $row->message,
                            'search_time' => time(),
                        ]);
                    }
                    $this->statNoMatch++;
                }
                if ($this->budget->timeUp() || $this->budget->siteSliceUp()) {
                    break 2;
                }
            }

            $this->batchClose($skipIds);
            if ($rows->count() < 300) {
                break;
            }
        }
    }

    /**
     * token是否为结构性区分词（用于桶路径防撞车）
     * - 必须含至少两个连续字母（排除纯数字/单字母）
     * - 排除分辨率/位深/帧率/集数/盘符等规格token（1080p、10bit、60fps、s01e05、disc1——区分度为零）
     * - 排除通用停用词
     */
    protected function isDistinctiveToken(string $token): bool
    {
        if (!preg_match('/[a-z]{2}/', $token)) {
            return false;
        }
        if (preg_match('/^(\d{3,4}p|\d+bit|\d+fps|\d+kfps|\d+(e\d+)?|s\d+e\d+|disc\d+)$/', $token)) {
            return false;
        }
        return !in_array($token, self::SEARCH_STOPWORDS, true);
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
     * 命中入队 cn_reseed（复用投递管线），并把结论同步到同hash的兄弟行（各自客户端各自入队，零额外站点请求）
     * @param LocalReseed $row
     * @param Site $site
     * @param MatchResult $result
     * @return void
     */
    protected function enqueueFamily(LocalReseed $row, Site $site, MatchResult $result): void
    {
        $this->enqueue($row, $site, $result);
        $this->statMatchedHashes++;

        // 兄弟行：同hash其他客户端（master模式下入队同一目标客户端时firstOrCreate天然去重）
        if (!$this->masterModel) {
            $siblings = LocalReseed::where('info_hash', '=', (string)$row->info_hash)
                ->where('target_sid', '=', $site->sid)
                ->where('id', '<>', (int)$row->id)
                ->where('status', '=', LocalReseedStatusEnums::Pending->value)
                ->get();
            foreach ($siblings as $sibling) {
                $this->enqueue($sibling, $site, $result);
                $this->statMatched++;
            }
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
        $row->message = '命中：' . $result->name . ($result->message ? '（' . $result->message . '）' : '');
        $row->save();
        $this->statMatched++;
        echo "命中 站点 {$site->site} 种子ID {$result->torrentId} 标题 {$result->name}" . PHP_EOL;
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
        $desp .= '**引导搜索：' . $this->statSearchGuided . '**  [IYUU已验证hash的关键词搜索] 补充索引 ' . $this->statSearchIngest . ' 条' . $br;
        $desp .= '**元数据校验：' . $this->statVerify . '**  [下载站内种子做infohash裁决]' . $br;
        $desp .= '**命中唯一种子：' . $this->statMatchedHashes . '**  覆盖客户端行 ' . $this->statMatched . $br;
        $desp .= '**无匹配：' . $this->statNoMatch . '**' . $br;
        $desp .= '**瞬时失败：' . $this->statFailed . '**  [下轮自动重试]' . $br;
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
        $this->bucketMatch = (bool)($parameter['bucket_match'] ?? false);

        $master = $parameter['master'] ?? null;
        if ($master) {
            $this->masterModel = ClientServices::getClient((int)$master);
        }

        $max_run_seconds = (int)($parameter['max_run_seconds'] ?? self::DEFAULT_MAX_RUN_SECONDS);
        $max_run_seconds = max(60, min(1100, $max_run_seconds));
        $max_requests = (int)($parameter['max_requests'] ?? self::DEFAULT_MAX_REQUESTS);
        $max_requests = max(1, $max_requests);
        $interval = (int)($parameter['search_interval'] ?? self::DEFAULT_INTERVAL);
        $interval = max(8, min(60, $interval));    // 下限8秒：固定3秒节律是bot签名(封禁教训)
        $daily_requests = (int)($parameter['daily_requests'] ?? self::DEFAULT_DAILY_REQUESTS);
        $daily_requests = max(50, min(3000, $daily_requests));
        $this->budget = new SearchBudget($max_run_seconds, $max_requests, $interval, $daily_requests);

        $this->crontabModel = $crontabModel;
        $this->crontabSites = $sites;
        $this->crontabClients = $clients;
        $this->notifyEnum = NotifyChannelEnums::tryFrom((string)$notify_channel);
        $this->downloaderMarkerEnums = $marker;
        $this->auto_check = $auto_check;
    }
}
