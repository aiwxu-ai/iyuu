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
     * 体积预筛(元数据校验门槛)：比例容差 + 绝对下限
     * 真同种(同infohash跨站)页面体积差通常<0.1%；不同影片常撞进0.2%~1%区间
     * → 校验门槛取 max(0.25%, 6MB)：真阳性必过，假阳性大多死于本地判断（零请求）
     * @param int $sizeA
     * @param int $sizeB
     * @param float $tolerance 容差比例
     * @param int $floorBytes 绝对容差下限(字节)
     * @return bool
     */
    public static function sizeClose(int $sizeA, int $sizeB, float $tolerance = 0.0025, int $floorBytes = 6291456): bool
    {
        if ($sizeA <= 0 || $sizeB <= 0) {
            return false;
        }
        $diff = abs($sizeA - $sizeB);
        return $diff <= max((int)($tolerance * max($sizeA, $sizeB)), $floorBytes);
    }
}
