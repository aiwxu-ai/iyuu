<?php

namespace app\admin\services\localreseed;

/**
 * 搜索词构造器
 * - 过滤不适合搜索的名称：太短、纯数字、类哈希
 */
final class SearchKeywordBuilder
{
    /**
     * 最小搜索词长度
     */
    public const int MIN_LENGTH = 6;

    /**
     * 构造搜索词
     * @param string $name 种子名称
     * @param int $minLength 最小长度
     * @return string|null 不适合搜索时返回null
     */
    public static function build(string $name, int $minLength = self::MIN_LENGTH): ?string
    {
        $keyword = trim($name);
        if (str_ends_with(strtolower($keyword), '.torrent')) {
            $keyword = trim(substr($keyword, 0, -8));
        }

        if (!self::isSearchable($keyword, $minLength)) {
            return null;
        }

        // 压缩空白字符
        $keyword = trim(preg_replace('/[\s]+/u', ' ', $keyword) ?? '');
        if (!self::isSearchable($keyword, $minLength)) {
            return null;
        }

        return mb_substr($keyword, 0, 80);
    }

    /**
     * 名称是否适合搜索
     * @param string $name
     * @param int $minLength
     * @return bool
     */
    public static function isSearchable(string $name, int $minLength = self::MIN_LENGTH): bool
    {
        if (mb_strlen($name) < $minLength) {
            return false;
        }

        // 纯数字、字母数字哈希样式
        if (preg_match('/^[\d\s\-_.]+$/', $name)) {
            return false;
        }

        if (preg_match('/^[0-9a-fA-F]{32}$|^[0-9a-fA-F]{40}$/', str_replace([' ', '-', '.', '_'], '', $name))) {
            return false;
        }

        return true;
    }
}
