<?php

namespace app\admin\services\localreseed;

/**
 * 本地做种种子值对象
 */
final class LocalTorrentItem
{
    /**
     * @param string $hash 种子infohash
     * @param string $name 种子名称
     * @param int $size 种子体积(字节)
     * @param string $directory 做种目录
     * @param int $clientId 所属下载器ID
     */
    public function __construct(
        public readonly string $hash,
        public readonly string $name,
        public readonly int    $size,
        public readonly string $directory,
        public readonly int    $clientId
    )
    {
    }
}
