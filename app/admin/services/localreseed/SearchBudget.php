<?php

namespace app\admin\services\localreseed;

use app\admin\support\NotifyAdmin;
use app\model\SiteRequestLedger;

/**
 * 搜索预算控制器（进程内预算 + 跨进程全局台账）
 *
 * 进程内：时间预算(单轮最长运行秒数)、每站每轮请求上限、站点时间分片
 * 跨进程：cn_site_request_ledger 台账——每站每日请求硬上限、最小间隔(带抖动)、
 *         活跃窗口(默认09:00-23:00)、连续失败熔断(3次→冷却24小时)
 *
 * 封禁教训：多进程并发各记各的账 = 封号。所有对站请求必须经过本类的 allow/hit。
 */
final class SearchBudget
{
    /**
     * 每站请求计数(本进程本轮)
     * @var array<string, int>
     */
    private array $perSite = [];

    /**
     * 每站最近一次请求时刻(进程内, microtime)
     * @var array<string, float>
     */
    private array $lastHitAt = [];

    /**
     * 台账行缓存 site => SiteRequestLedger
     * @var array<string, SiteRequestLedger>
     */
    private array $ledgerCache = [];

    /**
     * 熔断/窗口外 已提示过的站点(每轮每站只提示一次)
     * @var array<string, bool>
     */
    private array $notifiedOnce = [];

    /**
     * 起始时间戳(微秒)
     */
    private readonly float $startTime;

    /**
     * 当前站点的截止时刻(微秒, 时间分片)
     */
    private float $siteDeadline = PHP_FLOAT_MAX;

    /**
     * 相邻请求最小间隔(秒)，可被站点限速配置抬升
     */
    private int $interval;

    /**
     * @param int $maxRunSeconds 单轮时间预算(秒) 必须小于计划任务硬超时1200秒
     * @param int $maxRequests 每站每轮最大请求数
     * @param int $interval 相邻请求最小间隔(秒)
     * @param int $dailyRequests 每站每日请求硬上限(台账强制, 含失败请求)
     */
    public function __construct(
        private readonly int $maxRunSeconds,
        private readonly int $maxRequests,
        int $interval,
        private readonly int $dailyRequests = 800
    )
    {
        $this->interval = max($interval, 8);    // 下限8秒：3秒级固定节律是bot签名
        $this->startTime = microtime(true);
    }

    /**
     * 单轮时间是否已耗尽
     */
    public function timeUp(): bool
    {
        return (microtime(true) - $this->startTime) >= $this->maxRunSeconds;
    }

    /**
     * 当前站点时间片是否已耗尽
     */
    public function siteSliceUp(): bool
    {
        return microtime(true) >= $this->siteDeadline;
    }

    /**
     * 当前站点时间片剩余秒数
     */
    public function sliceRemainingSeconds(): float
    {
        return max(0, $this->siteDeadline - microtime(true));
    }

    /**
     * 站点是否还允许发起请求
     * 检查链：时间片→轮时间→轮请求上限→台账(熔断/日限/跨进程间隔/活跃窗口)
     */
    public function allow(string $site): bool
    {
        if ($this->timeUp() || $this->siteSliceUp()) {
            return false;
        }
        if (($this->perSite[$site] ?? 0) >= $this->maxRequests) {
            return false;
        }

        $ledger = $this->ledger($site);
        if ($ledger->inCooldown()) {
            $this->warnOnce($site, '站点熔断中至 ' . date('H:i', (int)$ledger->cooldown_until) . '：' . $site . '（连续失败触发24小时冷却）');
            return false;
        }
        if ($ledger->todayRequests() >= $this->dailyRequests) {
            $this->warnOnce($site, "站点日限已满：{$site} 今日 {$ledger->todayRequests()}/{$this->dailyRequests} 请求，明日自动恢复");
            return false;
        }
        if (!self::inActiveWindow()) {
            $this->warnOnce($site, '活跃窗口外(默认09:00-23:00)暂停站点请求：' . $site);
            return false;
        }
        // 跨进程最小间隔：距台账最近一次请求不足间隔(含抖动余量)则暂缓
        $elapsed = time() - (int)$ledger->last_ts;
        if ((int)$ledger->last_ts > 0 && $elapsed < $this->interval) {
            return false;
        }
        return true;
    }

    /**
     * 记录一次请求并休眠到间隔满足
     * 顺序：先记账(即使后续请求失败也计费) → 休眠 → 发请求
     * 休眠必须保证"距本次记账 ≥ interval"：allow()的台账间隔判定以记账时刻为准，
     * 若只按抖动间隔休眠(可能小于interval甚至首次不休眠)，下一次allow()必然返回false，
     * 调用方会误判为预算耗尽而整轮提前退出（每站每阶段只剩1个请求）
     */
    public function hit(string $site): void
    {
        $chargedAt = microtime(true);
        SiteRequestLedger::charge($site);
        unset($this->ledgerCache[$site]);    // 缓存失效，下次allow重新读
        $this->perSite[$site] = ($this->perSite[$site] ?? 0) + 1;

        // 休眠到 max(距本次记账≥interval, 距上一请求≥抖动间隔)，两者都满足才放行
        $now = microtime(true);
        $wait = max(
            $chargedAt + $this->interval - $now,
            $this->jitteredInterval() - ($now - ($this->lastHitAt[$site] ?? 0.0))
        );
        if ($wait > 0) {
            usleep((int)($wait * 1000000));
        }
        $this->lastHitAt[$site] = microtime(true);
    }

    /**
     * 带抖动的间隔(最小间隔之上叠加0~+40%随机量)：固定节律是爬虫签名
     */
    private function jitteredInterval(): float
    {
        return $this->interval * mt_rand(100, 140) / 100;
    }

    /**
     * 活跃窗口（凌晨全静默；夜间匀速抓取是典型爬虫特征）
     */
    public static function inActiveWindow(): bool
    {
        $hour = (int)date('G');
        return $hour >= 9 && $hour < 23;
    }

    /**
     * 抬升请求间隔（不低于站点限速配置）
     */
    public function intervalAtLeast(int $seconds): void
    {
        if ($seconds > $this->interval) {
            $this->interval = min($seconds, 60);
        }
    }

    /**
     * 时间预算剩余秒数
     */
    public function remainingSeconds(): float
    {
        return max(0, $this->maxRunSeconds - (microtime(true) - $this->startTime));
    }

    /**
     * 设置当前站点的时间片截止(秒后)
     */
    public function setSiteDeadline(float $seconds): void
    {
        $this->siteDeadline = microtime(true) + $seconds;
    }

    /**
     * 每站请求上限
     */
    public function maxRequests(): int
    {
        return $this->maxRequests;
    }

    /**
     * 站点已用请求数(本进程本轮)
     */
    public function used(string $site): int
    {
        return $this->perSite[$site] ?? 0;
    }

    /**
     * 台账行(带缓存)
     */
    private function ledger(string $site): SiteRequestLedger
    {
        return $this->ledgerCache[$site] ??= SiteRequestLedger::getOrNew($site);
    }

    /**
     * 每轮每站只提示一次
     */
    private function warnOnce(string $site, string $message): void
    {
        if (isset($this->notifiedOnce[$site])) {
            return;
        }
        $this->notifiedOnce[$site] = true;
        echo $message . PHP_EOL;
        NotifyAdmin::warning($message);
    }
}
