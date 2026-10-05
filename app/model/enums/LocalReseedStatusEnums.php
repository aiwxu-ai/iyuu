<?php

namespace app\model\enums;

/**
 * 本地辅种状态枚举
 */
enum LocalReseedStatusEnums: int
{
    /**
     * 待搜索
     */
    case Pending = 0;
    /**
     * 搜索中
     */
    case Matching = 1;
    /**
     * 无匹配
     */
    case NoMatch = 2;
    /**
     * 已命中
     */
    case Matched = 3;
    /**
     * 失败
     */
    case Failed = 4;
    /**
     * 跳过
     */
    case Skipped = 5;

    /**
     * 枚举的文本描述
     * @param self $enum
     * @return string
     */
    public static function text(self $enum): string
    {
        return match ($enum) {
            self::Pending => '待搜索',
            self::Matching => '搜索中',
            self::NoMatch => '无匹配',
            self::Matched => '已命中',
            self::Failed => '失败',
            self::Skipped => '跳过',
        };
    }

    /**
     * 枚举条目转为数组
     * - 文本描述 => 值
     * @return array
     */
    public static function select(): array
    {
        $rs = [];
        foreach (self::cases() as $enum) {
            $rs[self::text($enum)] = $enum->value;
        }
        return $rs;
    }

    /**
     * 枚举条目转为数组
     * - 名 => 值
     * @return array
     */
    public static function toArray(): array
    {
        return array_column(self::cases(), 'value', 'name');
    }
}
