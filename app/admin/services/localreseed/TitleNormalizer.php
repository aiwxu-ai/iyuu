<?php

namespace app\admin\services\localreseed;

/**
 * 标题归一化器
 * - 本地种子名与站点索引标题使用同一算法归一化后精确匹配
 */
final class TitleNormalizer
{
    /**
     * 归一化为匹配键
     * @param string $title
     * @return string
     */
    public static function key(string $title): string
    {
        $key = html_entity_decode($title, ENT_QUOTES | ENT_HTML5);
        $key = mb_strtolower(trim($key));
        // 去除常见修饰标记
        $key = preg_replace('/\[置顶\]|\[热门\]|@本站限定/iu', '', $key) ?? $key;
        // 剥离前导的中文标题括号段：[赛马娘.芦毛灰姑娘].Umamusume... → umasmusume...
        $key = preg_replace('/^[\[【][^\]】]*[\]】][\s.]*/u', '', $key) ?? $key;
        // 剥离尾部的站水印修饰：...中英特效字幕￡CMCT南瓜 → 去掉￡及之后
        $key = preg_replace('/[￡￥#@].{0,20}$/u', '', $key) ?? $key;
        // 分隔符统一为单个空格
        $key = preg_replace('/[._\s\-—–]+/u', ' ', $key) ?? $key;
        // 压缩空白
        $key = preg_replace('/\s+/u', ' ', $key) ?? $key;
        return trim($key);
    }

    /**
     * 体积预筛：页面精度(两位小数)容差判断
     * @param int $sizeA
     * @param int $sizeB
     * @param float $tolerance 容差比例
     * @return bool
     */
    public static function sizeClose(int $sizeA, int $sizeB, float $tolerance = 0.01): bool
    {
        if ($sizeA <= 0 || $sizeB <= 0) {
            return false;
        }
        $diff = abs($sizeA - $sizeB);
        return $diff <= (int)($tolerance * max($sizeA, $sizeB));
    }
}
