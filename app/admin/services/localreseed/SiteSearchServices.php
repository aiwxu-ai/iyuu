<?php

namespace app\admin\services\localreseed;

use app\model\Site;
use Iyuu\SiteManager\Exception\EmptyListException;
use Iyuu\SiteManager\Spider\Helper;
use Iyuu\SiteManager\Spider\SpiderTorrents;
use Iyuu\SiteManager\SiteManager;
use Ledc\Container\App;
use Rhilip\Bencode\TorrentFile;
use RuntimeException;
use Throwable;

/**
 * 单站搜索匹配服务
 * - 构造站内搜索URI → 解析结果页 → 下载候选种子元数据 → 字节级精确体积比对
 */
final class SiteSearchServices
{
    /**
     * @param Site $site 目标站点模型
     * @param int $incldead 0活种/1含死种/2仅死种
     * @param int $maxCandidates 每次搜索最多下载元数据的候选数
     */
    public function __construct(
        private readonly Site $site,
        private readonly int $incldead = 1,
        private readonly int $maxCandidates = 3
    )
    {
    }

    /**
     * 搜索并匹配
     * @param string $keyword 搜索词
     * @param int $localSize 本地种子体积(字节)
     * @return MatchResult
     */
    public function searchAndMatch(string $keyword, int $localSize): MatchResult
    {
        $uri = 'torrents.php?search=' . rawurlencode($keyword) . '&incldead=' . $this->incldead . '&search_area=0';
        try {
            $items = (new Helper())->cookie($this->site->site, $uri);
        } catch (EmptyListException $exception) {
            $message = $exception->getMessage();
            if (self::isLoginHtml($message)) {
                throw new CookieInvalidException('站点cookie已失效：' . $this->site->site);
            }
            // 真·无结果
            return MatchResult::noMatch(0, self::truncate($message));
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'Cookie过期') || str_contains($message, '302')) {
                throw new CookieInvalidException('站点cookie已失效：' . $this->site->site . ' ' . $message);
            }
            return MatchResult::failed(self::truncate($message));
        }

        if (empty($items)) {
            return MatchResult::noMatch(0);
        }

        // 排序：免费优先，然后新种优先
        usort($items, static function (array $a, array $b): int {
            $freeA = (0 === (int)($a['type'] ?? 1)) ? 0 : 1;
            $freeB = (0 === (int)($b['type'] ?? 1)) ? 0 : 1;
            if ($freeA !== $freeB) {
                return $freeA <=> $freeB;
            }
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });

        $candidates = count($items);
        $driver = App::pull(SiteManager::class)->select($this->site->site);
        foreach (array_slice($items, 0, max(1, $this->maxCandidates)) as $item) {
            try {
                $payload = $driver->downloadMetadata(new SpiderTorrents($item));
                if (false === $payload || empty($payload)) {
                    continue;
                }

                $torrentFile = TorrentFile::loadFromString($payload);
                if ((int)$torrentFile->getSize() === $localSize) {
                    return MatchResult::matched(
                        (int)$item['id'],
                        $torrentFile->getInfoHashV1(),
                        (string)($item['h1'] ?? $torrentFile->getName()),
                        $candidates
                    );
                }
            } catch (Throwable $exception) {
                // 元数据下载遇到凭据失效，升级为站点级中止
                $message = $exception->getMessage();
                if (str_contains($message, 'Cookie过期') || str_contains($message, '302')) {
                    throw new CookieInvalidException('站点cookie已失效：' . $this->site->site . ' ' . $message);
                }
                continue;
            }
        }

        return MatchResult::noMatch($candidates);
    }

    /**
     * 判断返回的HTML是否为登录页（cookie失效被重定向）
     * @param string $html
     * @return bool
     */
    private static function isLoginHtml(string $html): bool
    {
        if (!str_contains($html, 'torrentname')) {
            return str_contains($html, 'login.php') || str_contains($html, '登录') || str_contains($html, 'Login');
        }
        return false;
    }

    /**
     * 截断消息（EmptyListException的消息内嵌整页HTML）
     * @param string $message
     * @param int $length
     * @return string
     */
    private static function truncate(string $message, int $length = 500): string
    {
        $message = trim($message);
        return mb_strlen($message) > $length ? mb_substr($message, 0, $length) . '...' : $message;
    }
}
