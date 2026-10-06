<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use plugin\admin\app\model\Base;

/**
 * 站点种子本地索引
 * @property integer $id 主键
 * @property integer $sid 站点ID
 * @property string $site 站点名称
 * @property integer $torrent_id 站内种子ID
 * @property string $title 主标题(发布名)
 * @property string $title_key 归一化标题(匹配键)
 * @property integer $size_bytes 体积(字节,页面精度)
 * @property integer $free 是否免费
 * @property integer $sticky 是否置顶
 * @property string $download_uri 下载链接(相对URI)
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SiteTorrent extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cn_site_torrent';

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
     * 构造器：按站点取索引
     * @param int $sid
     * @return Builder
     */
    public static function getBySid(int $sid): Builder
    {
        return static::where('sid', '=', $sid);
    }
}
