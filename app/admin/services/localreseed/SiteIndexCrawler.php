<?php

namespace app\admin\services\localreseed;

use app\model\Site;
use app\model\SiteIndex;
use app\model\SiteRequestLedger;
use app\model\SiteTorrent;
use DOMDocument;
use DOMXPath;
use Ledc\Curl\Curl;
use RuntimeException;
use Throwable;

/**
 * 站点种子索引爬虫
 * - 翻页抓取 torrents.php 列表页 → 解析(种子ID/主标题/体积/免费/下载链接) → 入库 cn_site_torrent
 * - 增量模式：从第0页(最新)开始，遇到已索引比例高的页即停
 * - 全库模式：持续翻页直到空页(断点续跑)
 */
final class SiteIndexCrawler
{
    /**
     * 已知比例阈值：非置顶种子中已索引占比超过此值时，增量模式停止
     */
    private const float KNOWN_RATIO_STOP = 0.6;

    /**
     * 空页三振：连续异常空页达到此值才置full_done（限流页/模板变化不再永久冻结索引）
     */
    private const int FULL_DONE_EMPTY_STREAK = 3;

    /**
     * @param Site $site 目标站点
     * @param SearchBudget $budget 搜索预算
     * @param int $incldead 0活种/1含死种/2仅死种
     */
    public function __construct(
        private readonly Site $site,
        private readonly SearchBudget $budget,
        private readonly int $incldead = 1
    )
    {
    }

    /**
     * 抓取索引
     * @param int $maxPages 本轮最多翻页数
     * @param bool $fullMode 全库模式(忽略已知比例，直到空页或预算耗尽)
     * @return array{pages:int, new_rows:int, total:int, full_done:bool} 统计
     */
    public function crawl(int $maxPages, bool $fullMode): array
    {
        $state = SiteIndex::getOrNew($this->site->sid, $this->site->site);
        $state->last_time = time();

        // 全库模式断点续建：从上次最大页码+1继续翻深；增量模式（或全库已完成）从第0页追新
        $resume = $fullMode && !(1 === (int)$state->full_done) && (int)$state->last_page >= 0;
        $page = $resume ? ((int)$state->last_page + 1) : 0;

        $pages = 0;
        $newRows = 0;
        $fullDone = false;
        $emptyStreak = 0;
        $parseAnomalyStreak = 0;
        while ($pages < $maxPages && $this->budget->allow($this->site->site)) {
            // 先记账再请求：失败的请求同样计入预算与台账
            $this->budget->hit($this->site->site);
            $html = $this->fetchPage($page);
            $pages++;

            $rows = $this->parse($html);
            if (empty($rows)) {
                // 空页三振判定：页面含种子表特征但解析0行=解析异常(不置完成)；连续真空页才到底
                $pageHasTable = str_contains($html, 'torrentname') || str_contains($html, 'details.php?id=');
                if ($pageHasTable) {
                    $emptyStreak = 0;    // 解析异常，不当到底
                    // 连续解析异常也三振出局：否则模板一变每轮都会把整站翻页预算烧光
                    if (++$parseAnomalyStreak >= self::FULL_DONE_EMPTY_STREAK) {
                        break;
                    }
                } elseif (++$emptyStreak >= self::FULL_DONE_EMPTY_STREAK && $fullMode) {
                    $fullDone = true;
                }
                if ($fullDone || $emptyStreak >= self::FULL_DONE_EMPTY_STREAK) {
                    break;
                }
                $page++;
                continue;
            }
            $emptyStreak = 0;
            $parseAnomalyStreak = 0;

            // 分页器最大页码：已到尾页则全库完成（NexusPHP超尾页会钳制回最后一页，不能靠空页判断）
            if ($fullMode && preg_match_all('/[?&]page=(\d+)/', $html, $mPages)) {
                $maxPage = (int)max($mPages[1]);
                if ($page >= $maxPage) {
                    $newRows += $this->upsert($rows);
                    $fullDone = true;
                    break;
                }
            }

            $inserted = $this->upsert($rows);
            $newRows += $inserted;

            // 增量模式：非置顶种子已索引比例高 → 停止翻页
            if (!$fullMode) {
                $nonSticky = array_filter($rows, static fn($r) => 0 === (int)$r['sticky']);
                $nonStickyCount = count($nonSticky) ?: count($rows);
                $knownCount = count($nonSticky) - $inserted;
                if ($nonStickyCount > 0 && ($knownCount / $nonStickyCount) >= self::KNOWN_RATIO_STOP) {
                    break;
                }
            }

            $page++;
        }

        if ($page > (int)$state->last_page) {
            $state->last_page = $page;
        }
        if ($fullDone) {
            $state->full_done = 1;
        }
        $state->total_indexed = SiteTorrent::getBySid($this->site->sid)->count();
        $state->save();

        return ['pages' => $pages, 'new_rows' => $newRows, 'total' => (int)$state->total_indexed, 'full_done' => $fullDone];
    }

    /**
     * 抓取一页
     * @param int $page
     * @return string
     */
    private function fetchPage(int $page): string
    {
        $curl = new Curl();
        $this->applyCurlOptions($curl);
        $curl->get($this->domain() . '/torrents.php?incldead=' . $this->incldead . '&page=' . $page);
        if (!$curl->isSuccess() || empty($curl->response)) {
            SiteRequestLedger::fail($this->site->site);
            throw new RuntimeException('抓取列表页失败 page=' . $page . ' http=' . $curl->http_status_code);
        }

        $html = (string)$curl->response;
        if (str_contains($html, 'Just a moment') || str_contains($html, 'cf-challenge')) {
            SiteRequestLedger::fail($this->site->site);
            throw new CookieInvalidException('站点触发Cloudflare挑战：' . $this->site->site . '（cookie含cf_clearance可能过期）');
        }
        if (!str_contains($html, 'torrentname') && str_contains($html, 'login.php')) {
            SiteRequestLedger::fail($this->site->site);
            throw new CookieInvalidException('站点cookie已失效：' . $this->site->site);
        }
        SiteRequestLedger::success($this->site->site);    // 成功请求重置熔断计数：散发的元数据超时不再跨小时累积误熔断
        return $html;
    }

    /**
     * 站内关键词搜索（单token短语在NexusPHP下是整串模糊匹配，必须拆词）
     * @param string $term 搜索词（只传一个特征词）
     * @param int $page 页码
     * @return array<int, array{torrent_id:int,title:string,title_key:string,size_bytes:int,free:int,sticky:int,download_uri:string}>
     * @throws CookieInvalidException
     */
    public function search(string $term, int $page = 0): array
    {
        $curl = new Curl();
        $this->applyCurlOptions($curl);
        $curl->get($this->domain() . '/torrents.php?incldead=' . $this->incldead . '&search=' . rawurlencode($term) . '&page=' . $page);
        if (!$curl->isSuccess() || empty($curl->response)) {
            SiteRequestLedger::fail($this->site->site);
            throw new RuntimeException('搜索请求失败 term=' . $term . ' http=' . $curl->http_status_code);
        }

        $html = (string)$curl->response;
        if (str_contains($html, 'Just a moment') || str_contains($html, 'cf-challenge')) {
            SiteRequestLedger::fail($this->site->site);
            throw new CookieInvalidException('站点触发Cloudflare挑战：' . $this->site->site . '（cookie含cf_clearance可能过期）');
        }
        if (!str_contains($html, 'torrentname') && str_contains($html, 'login.php')) {
            SiteRequestLedger::fail($this->site->site);
            throw new CookieInvalidException('站点cookie已失效：' . $this->site->site);
        }
        SiteRequestLedger::success($this->site->site);
        return $this->parse($html);
    }

    /**
     * 外部行数据入库(幂等)，供搜索结果补充索引
     * @param array $rows
     * @return int 新增行数
     */
    public function ingest(array $rows): int
    {
        return $rows ? $this->upsert($rows) : 0;
    }

    /**
     * 解析列表页
     * @param string $html
     * @return array<int, array{torrent_id:int,title:string,title_key:string,size_bytes:int,free:int,sticky:int,download_uri:string}>
     */
    public function parse(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        $xp = new DOMXPath($doc);

        // 表头列定位：alt="size" 所在列号
        $sizeCol = -1;
        $headers = $xp->query('//table[contains(@class,"torrents")]//tr//td[contains(@class,"colhead")]');
        if ($headers) {
            foreach ($headers as $i => $td) {
                if (str_contains($td->ownerDocument->saveHTML($td), 'alt="size"')) {
                    $sizeCol = $i;
                    break;
                }
            }
        }

        $rows = [];
        $trList = $xp->query('//table[contains(@class,"torrents")]//tr');
        if (!$trList) {
            return $rows;
        }

        foreach ($trList as $tr) {
            $rowHtml = $doc->saveHTML($tr);
            if (!str_contains($rowHtml, 'details.php?id=')) {
                continue;    // 表头或分隔行
            }

            if (!preg_match('/details\.php\?id=(\d+)/', $rowHtml, $mId)) {
                continue;
            }
            $torrentId = (int)$mId[1];

            // 主标题：种子详情主锚点（href 以 details.php?id=N 或 &hit=1 结束，排除评论/锚点链接）
            $title = '';
            if (preg_match('/<a[^>]*href="details\.php\?id=\d+(?:&amp;hit=1)?"[^>]*>(.*?)<\/a>/us', $rowHtml, $mAnchor)) {
                $anchor = $mAnchor[0];
                if (preg_match('/title="([^"]+)"/u', $anchor, $mTitle)) {
                    $title = $mTitle[1];
                } else {
                    $title = trim(preg_replace('/<[^>]+>/', '', $mAnchor[1]) ?? '');
                }
            }
            $title = trim(html_entity_decode($title, ENT_QUOTES | ENT_HTML5));
            if (mb_strlen($title) < 2) {
                continue;
            }

            // 无效标题防御：纯数字/单字符类
            if (preg_match('/^[\d\s.,]+$/u', $title)) {
                continue;
            }

            // 体积：优先按表头定位列，回退行内正则
            $sizeBytes = 0;
            if ($sizeCol >= 0) {
                $tdList = $xp->query('./td', $tr);
                if ($tdList && $tdList->item($sizeCol)) {
                    $sizeBytes = self::parseSizeText($tdList->item($sizeCol)->textContent);
                }
            }
            if ($sizeBytes <= 0) {
                if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:<[^>]+>\s*)?(TB|GB|MB|KB)/iu', $rowHtml, $mSizes, PREG_SET_ORDER)) {
                    $sizeBytes = self::parseSizeText(end($mSizes)[0]);
                }
            }

            // 下载链接
            $downloadUri = '';
            if (preg_match('/(download\.php\?[^"\']+)/i', str_replace('&amp;', '&', $rowHtml), $mDl)) {
                $downloadUri = $mDl[1];
            }

            $rows[] = [
                'torrent_id' => $torrentId,
                'title' => mb_substr($title, 0, 480),
                'title_key' => mb_substr(TitleNormalizer::key($title), 0, 480),
                'size_bytes' => $sizeBytes,
                'free' => str_contains($rowHtml, 'class="pro_free') ? 1 : 0,
                'sticky' => str_contains($rowHtml, 'alt="Sticky"') ? 1 : 0,
                'download_uri' => mb_substr($downloadUri, 0, 480),
            ];
        }

        return $rows;
    }

    /**
     * 解析体积文本为字节
     * @param string $text
     * @return int
     */
    public static function parseSizeText(string $text): int
    {
        $text = trim(preg_replace('/\s+|<[^>]+>/', ' ', $text) ?? '');
        if (preg_match('/(\d+(?:\.\d+)?)\s*(TB|GB|MB|KB)/iu', $text, $m)) {
            $units = ['kb' => 1024, 'mb' => 1024 ** 2, 'gb' => 1024 ** 3, 'tb' => 1024 ** 4];
            $unit = strtolower($m[2]);
            return (int)round(((float)$m[1]) * $units[$unit]);
        }
        return 0;
    }

    /**
     * 批量入库(幂等)：insertOrIgnore保新增计数(供增量比例判断)，upsert刷新free/size等随时间变化的字段
     * @param array $rows
     * @return int 新增行数
     */
    private function upsert(array $rows): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'sid' => $this->site->sid,
                'site' => $this->site->site,
                'torrent_id' => $row['torrent_id'],
                'title' => $row['title'],
                'title_key' => $row['title_key'],
                'size_bytes' => $row['size_bytes'],
                'free' => $row['free'],
                'sticky' => $row['sticky'],
                'download_uri' => $row['download_uri'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($data, 200) as $chunk) {
            $inserted += SiteTorrent::insertOrIgnore($chunk);
            SiteTorrent::upsert($chunk, ['sid', 'torrent_id'], ['title', 'title_key', 'size_bytes', 'free', 'sticky', 'download_uri', 'updated_at']);
        }
        return $inserted;
    }

    /**
     * 站点域名
     * @return string
     */
    private function domain(): string
    {
        $host = $this->site->mirror ?: $this->site->base_url;
        return ($this->site->is_https ? 'https://' : 'http://') . rtrim((string)$host, '/');
    }

    /**
     * 设置Curl参数
     * @param Curl $curl
     * @return void
     */
    private function applyCurlOptions(Curl $curl): void
    {
        $curl->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36')
            ->setTimeout(30, 90)
            ->setSslVerify()
            ->setCookies((string)$this->site->cookie);
    }
}
