<?php

namespace app\model;

use plugin\admin\app\model\Base;
use Throwable;

/**
 * 站点索引抓取进度
 * @property integer $id 主键
 * @property integer $sid 站点ID
 * @property string $site 站点名称
 * @property integer $last_page 已抓取到的最大页码
 * @property integer $total_indexed 已索引种子总数
 * @property integer $full_done 全库抓取完成标记
 * @property integer $last_time 最近抓取时间戳
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SiteIndex extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cn_site_index';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * 获取或创建站点进度模型
     * 并发进程同时首建时靠uk_sid唯一键去重：冲突方重取现有行（与SiteRequestLedger::getOrNew一致），
     * 否则重叠跑同站的任务会有一个直接以「建库异常」崩掉本轮
     * @param int $sid
     * @param string $site
     * @return self
     */
    public static function getOrNew(int $sid, string $site): self
    {
        /** @var self|null $model */
        $model = static::where('sid', '=', $sid)->first();
        if (!$model) {
            $model = new self();
            $model->sid = $sid;
            $model->site = $site;
            $model->last_page = -1;
            try {
                $model->save();
            } catch (Throwable) {
                /** @var self|null $existing */
                $existing = static::where('sid', '=', $sid)->first();
                if (null !== $existing) {
                    $model = $existing;
                }
            }
        }
        return $model;
    }
}
