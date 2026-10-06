<?php

namespace app\admin\services\localreseed;

use app\model\enums\LocalReseedStatusEnums;

/**
 * 单次搜索匹配结果
 */
final readonly class MatchResult
{
    public function __construct(
        public LocalReseedStatusEnums $status,
        public int                    $candidates = 0,
        public int                    $torrentId = 0,
        public string                 $infoHash = '',
        public string                 $name = '',
        public string                 $message = ''
    )
    {
    }

    /**
     * 无匹配
     * @param int $candidates
     * @param string $message
     * @return self
     */
    public static function noMatch(int $candidates = 0, string $message = ''): self
    {
        return new self(LocalReseedStatusEnums::NoMatch, $candidates, 0, '', '', $message);
    }

    /**
     * 命中
     * @param int $torrentId
     * @param string $infoHash
     * @param string $name
     * @param int $candidates
     * @param string $message 附加说明（如size-only标注）
     * @return self
     */
    public static function matched(int $torrentId, string $infoHash, string $name, int $candidates, string $message = ''): self
    {
        return new self(LocalReseedStatusEnums::Matched, $candidates, $torrentId, $infoHash, $name, $message);
    }

    /**
     * 失败
     * @param string $message
     * @return self
     */
    public static function failed(string $message): self
    {
        return new self(LocalReseedStatusEnums::Failed, 0, 0, '', '', $message);
    }

    /**
     * 跳过
     * @param string $message
     * @return self
     */
    public static function skipped(string $message): self
    {
        return new self(LocalReseedStatusEnums::Skipped, 0, 0, '', '', $message);
    }
}
