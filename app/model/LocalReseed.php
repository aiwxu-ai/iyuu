<?php

namespace app\model;

use app\model\enums\LocalReseedStatusEnums;
use Illuminate\Database\Eloquent\Builder;
use plugin\admin\app\model\Base;

/**
 * 本地搜索式辅种进度
 * @property integer $id 主键
 * @property integer $crontab_id 首次同步时的计划任务ID
 * @property integer $client_id 本地做种下载器ID
 * @property string $info_hash 本地种子infohash
 * @property integer $target_sid 目标站点ID
 * @property string $target_site 目标站点名称
 * @property string $torrent_name 本地种子名称
 * @property integer $torrent_size 本地种子体积(字节)
 * @property string $directory 本地做种目录
 * @property string $keyword 实际使用的搜索词
 * @property integer $status 状态
 * @property integer $candidates 搜索返回候选数
 * @property integer $reseed_id 命中后写入cn_reseed的主键
 * @property integer $search_time 最近搜索时间戳
 * @property string $message 异常信息
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class LocalReseed extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cn_local_reseed';

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
     * 获取状态枚举对象
     * @return LocalReseedStatusEnums
     */
    public function getStatusEnums(): LocalReseedStatusEnums
    {
        return LocalReseedStatusEnums::from((int)$this->getAttribute('status'));
    }

    /**
     * 重置搜索中的状态（进程被杀后的崩溃恢复）
     * @return int
     */
    public static function resetMatching(): int
    {
        return static::where('status', LocalReseedStatusEnums::Matching->value)->update(['status' => LocalReseedStatusEnums::Pending->value]);
    }

    /**
     * 取一条待搜索的进度行
     * @param int $target_sid
     * @param array $client_ids
     * @return self|null
     */
    public static function nextPending(int $target_sid, array $client_ids): ?self
    {
        /** @var self|null $model */
        $model = static::where('target_sid', '=', $target_sid)
            ->whereIn('client_id', $client_ids)
            ->where('status', '=', LocalReseedStatusEnums::Pending->value)
            ->orderBy('id')
            ->first();
        return $model;
    }

    /**
     * 终态同步到兄弟行：同hash同站在多个下载器各有一行，一处得出结论全家共享
     * （跨轮的去重通道：进程内verifyCache只在单轮有效）
     * @param string $infoHash
     * @param int $targetSid
     * @param int $exceptId 已单独落库的行ID
     * @param array $attrs 要同步的字段
     * @return int 影响行数
     */
    public static function syncSiblings(string $infoHash, int $targetSid, int $exceptId, array $attrs): int
    {
        if (empty($attrs)) {
            return 0;
        }
        return static::where('info_hash', '=', $infoHash)
            ->where('target_sid', '=', $targetSid)
            ->where('id', '<>', $exceptId)
            ->where('status', '=', LocalReseedStatusEnums::Pending->value)
            ->update($attrs);
    }

    /**
     * 同步本地做种种子到进度表（幂等，唯一键去重）
     * @param int $crontab_id
     * @param int $client_id
     * @param array $torrents LocalTorrentItem对象数组
     * @param Site $site
     * @return int 新增行数
     */
    public static function syncProgress(int $crontab_id, int $client_id, array $torrents, Site $site): int
    {
        $now = date('Y-m-d H:i:s');
        $data = [];
        foreach ($torrents as $torrent) {
            $data[] = [
                'crontab_id' => $crontab_id,
                'client_id' => $client_id,
                'info_hash' => $torrent->hash,
                'target_sid' => $site->sid,
                'target_site' => $site->site,
                'torrent_name' => mb_substr($torrent->name, 0, 480),
                'torrent_size' => $torrent->size,
                'directory' => mb_substr($torrent->directory, 0, 880),
                'keyword' => '',
                'status' => LocalReseedStatusEnums::Pending->value,
                'candidates' => 0,
                'reseed_id' => 0,
                'search_time' => 0,
                'message' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $inserted = 0;
        foreach (array_chunk($data, 500) as $chunk) {
            $inserted += static::insertOrIgnore($chunk);
        }
        return $inserted;
    }

    /**
     * 构造器：获取统计
     * @param array $target_sids
     * @return Builder
     */
    public static function getStat(array $target_sids): Builder
    {
        return static::whereIn('target_sid', $target_sids)
            ->selectRaw('target_site, status, count(*) as total')
            ->groupBy('target_site', 'status');
    }
}
