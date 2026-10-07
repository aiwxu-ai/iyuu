<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use plugin\admin\app\model\Base;
use support\Db;
use Throwable;

/**
 * 站点请求全局台账
 * @property integer $id 主键
 * @property string $site 站点名称
 * @property string $day 记账日(Y-m-d)
 * @property integer $requests 当日请求数(含失败)
 * @property integer $last_ts 最近请求时间戳
 * @property integer $consec_fail 连续失败次数
 * @property integer $cooldown_until 熔断截止时间戳
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class SiteRequestLedger extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'cn_site_request_ledger';

    /**
     * The primary key associated with the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The attributes aren't mass assignable.
     *
     * @var array<string>|bool
     */
    protected $guarded = [];

    /**
     * 是否在熔断期
     * @return bool
     */
    public function inCooldown(): bool
    {
        return (int)$this->cooldown_until > time();
    }

    /**
     * 当日请求数（day 非今日视为0）
     * @return int
     */
    public function todayRequests(): int
    {
        return $this->day === date('Y-m-d') ? (int)$this->requests : 0;
    }

    /**
     * 取站点台账行（无则新建；并发进程同时首建时靠uk_site唯一键去重）
     * @param string $site
     * @return self
     */
    public static function getOrNew(string $site): self
    {
        /** @var self|null $model */
        $model = static::where('site', '=', $site)->first();
        if (null === $model) {
            $model = new static();
            $model->site = $site;
            $model->day = date('Y-m-d');
            try {
                $model->save();
            } catch (Throwable) {
                // 并发进程抢先建行触发唯一键冲突：重取现有行，避免异常炸掉整轮任务
                /** @var self|null $existing */
                $existing = static::where('site', '=', $site)->first();
                if (null !== $existing) {
                    $model = $existing;
                }
            }
        }
        return $model;
    }

    /**
     * 记一次请求（跨天自动重置计数）
     * 合并为单条原子UPDATE：原「先重置再自增」两条语句之间存在并发窗口——
     * 本进程的跨天重置会把另一进程刚写入的当日计数吞回0，日限计数少记。
     * 注意：MySQL的SET按书写顺序求值，requests/consec_fail的IF必须排在day赋值之前（读旧day）
     * @param string $site
     * @return void
     */
    public static function charge(string $site): void
    {
        $today = date('Y-m-d');
        $payload = [
            'requests' => Db::raw("IF(day = '{$today}', requests + 1, 1)"),
            'consec_fail' => Db::raw("IF(day = '{$today}', consec_fail, 0)"),
            'day' => $today,
            'last_ts' => time(),
        ];
        if (0 === static::where('site', '=', $site)->update($payload)) {
            // 行尚不存在（理论不可达：allow()已先经getOrNew建行）：补建后重试一次
            static::getOrNew($site);
            static::where('site', '=', $site)->update($payload);
        }
    }

    /**
     * 记一次失败（连续3次触发熔断）
     * 原子自增+条件更新：原「读-改-写」在并发下互相覆盖（两个进程同读consec_fail=1、
     * 各写2，丢一次计数），连续失败计数偏低会延迟甚至错失熔断触发
     * @param string $site
     * @param int $cooldownSeconds 熔断时长
     * @return bool 是否触发了熔断
     */
    public static function fail(string $site, int $cooldownSeconds = 86400): bool
    {
        static::where('site', '=', $site)->increment('consec_fail', 1);
        // 只有把计数推过阈值的那次更新才置熔断：条件更新天然去重，并发不会重复触发
        return (bool)static::where('site', '=', $site)
            ->where('consec_fail', '>=', 3)
            ->update(['cooldown_until' => time() + $cooldownSeconds, 'consec_fail' => 0]);
    }

    /**
     * 记一次成功（清零连续失败）
     * @param string $site
     * @return void
     */
    public static function success(string $site): void
    {
        static::where('site', '=', $site)->where('consec_fail', '>', 0)->update(['consec_fail' => 0]);
    }
}
