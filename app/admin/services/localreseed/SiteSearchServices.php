<?php

namespace app\admin\services\localreseed;

use app\model\Site;
use app\model\SiteRequestLedger;
use Ledc\Curl\Curl;
use Rhilip\Bencode\TorrentFile;
use Throwable;

/**
 * 种子元数据校验服务
 * - 凭索引里的下载链接抓取种子元数据，做字节级裁决
 * - 终裁标准：元数据infohash(v1) === 本地infohash（跨站辅种的定义就是同infohash）
 *   体积全等只是预筛——不同种子可撞同体积；hash任一侧缺失(v2-only)才退回体积全等并标注size-only
 */
final class SiteSearchServices
{
    /**
     * @param Site $site 目标站点模型
     */
    public function __construct(private readonly Site $site)
    {
    }

    /**
     * 校验候选种子
     * @param array $candidate 索引行：torrent_id/download_uri/title/size_bytes/free
     * @param int $localSize 本地种子体积(字节)
     * @param string $localHash 本地种子infohash(v1 hex, 40位；空串表示未知)
     * @return MatchResult
     */
    public function verify(array $candidate, int $localSize, string $localHash = ''): MatchResult
    {
        $uri = (string)($candidate['download_uri'] ?? '');
        if ('' === $uri) {
            $uri = 'download.php?id=' . $candidate['torrent_id'];
        }

        $curl = new Curl();
        $curl->setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36')
            ->setTimeout(30, 90)
            ->setSslVerify()
            ->setCookies((string)$this->site->cookie);
        $curl->get($this->domain() . '/' . ltrim($uri, '/'));
        if (!$curl->isSuccess() || empty($curl->response)) {
            $message = (string)($curl->error_message ?: ('http=' . $curl->http_status_code));
            SiteRequestLedger::fail($this->site->site);
            if (302 === (int)$curl->http_status_code || str_contains($message, 'Cookie过期')) {
                throw new CookieInvalidException('站点cookie已失效：' . $this->site->site . ' ' . $message);
            }
            return MatchResult::failed('元数据下载失败：' . mb_substr($message, 0, 300));
        }

        $body = (string)$curl->response;
        // 站点侧软封禁嗅探：返回的是HTML而非bencode时的三类典型形态
        if (str_contains($body, 'Just a moment') || str_contains($body, 'cf-challenge')) {
            SiteRequestLedger::fail($this->site->site);
            throw new CookieInvalidException('站点触发Cloudflare挑战：' . $this->site->site . '（请求过频或cf_clearance过期）');
        }
        if (!str_starts_with($body, 'd') || !str_contains($body, '4:info')) {
            SiteRequestLedger::fail($this->site->site);
            if (str_contains($body, 'login.php')) {
                throw new CookieInvalidException('站点cookie已失效：' . $this->site->site);
            }
            return MatchResult::failed('元数据非bencode：' . mb_substr($body, 0, 150));
        }

        try {
            $torrentFile = TorrentFile::loadFromString($body);
        } catch (Throwable $throwable) {
            SiteRequestLedger::fail($this->site->site);
            return MatchResult::failed('元数据解析失败：' . mb_substr($throwable->getMessage(), 0, 200));
        }

        SiteRequestLedger::success($this->site->site);

        // 终裁：infohash全等（跨站辅种 = 同infohash；announce/passkey不在info字典内，同文件集跨站hash相同）
        $siteHash = (string)$torrentFile->getInfoHashV1();
        if ('' !== $localHash && '' !== $siteHash) {
            if (0 === strcasecmp($siteHash, $localHash)) {
                return MatchResult::matched((int)$candidate['torrent_id'], strtolower($siteHash), (string)($candidate['title'] ?? ''), 1);
            }
            return MatchResult::noMatch(1, 'infohash不符');
        }

        // 退路：任一侧hash缺失(v2-only等)时体积全等 + 标注size-only（可能有同体积异种风险）
        if ((int)$torrentFile->getSize() === $localSize) {
            // 入队hash必须是"实际投递给下载器的种子"的客户端可见hash：
            // 站种子v2-only时getInfoHashV1为空，此时用截断v2哈希（qB/Tr对v2种子的显示口径），
            // 否则下游ReseedDownloadServices按本地v1哈希打标签/发校验命令会打在客户端里不存在的种子上
            $enqueueHash = $siteHash ?: substr((string)$torrentFile->getInfoHashV2(), 0, 40);
            return MatchResult::matched((int)$candidate['torrent_id'], strtolower($enqueueHash ?: $localHash), (string)($candidate['title'] ?? ''), 1, 'size-only');
        }
        return MatchResult::noMatch(1, '元数据体积不符：' . $torrentFile->getSize() . ' vs ' . $localSize);
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
}
