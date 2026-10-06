<?php

namespace app\admin\services\localreseed;

use app\model\Site;
use Ledc\Curl\Curl;
use Rhilip\Bencode\TorrentFile;
use Throwable;

/**
 * 种子元数据校验服务
 * - 凭索引里的下载链接抓取种子元数据，做字节级精确体积比对
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
     * @return MatchResult
     */
    public function verify(array $candidate, int $localSize): MatchResult
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
            if (302 === (int)$curl->http_status_code || str_contains($message, 'Cookie过期')) {
                throw new CookieInvalidException('站点cookie已失效：' . $this->site->site . ' ' . $message);
            }
            return MatchResult::failed('元数据下载失败：' . mb_substr($message, 0, 300));
        }

        try {
            $torrentFile = TorrentFile::loadFromString((string)$curl->response);
        } catch (Throwable $throwable) {
            return MatchResult::failed('元数据解析失败：' . mb_substr($throwable->getMessage(), 0, 200));
        }

        if ((int)$torrentFile->getSize() === $localSize) {
            return MatchResult::matched((int)$candidate['torrent_id'], $torrentFile->getInfoHashV1(), (string)($candidate['title'] ?? ''), 1);
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
