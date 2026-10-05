<?php

namespace app\admin\services\localreseed;

/**
 * 搜索预算控制器
 * - 时间预算：单轮最长运行秒数（必须小于计划任务硬超时1200秒）
 * - 请求预算：每站点每轮最大请求数（搜索+元数据下载合计）
 * - 请求间隔：相邻请求最小间隔秒数（自动抬升至站点限速配置）
 */
final class SearchBudget
{
    /**
     * 每站请求计数
     * @var array<string, int>
     */
    private array $perSite = [];

    /**
     * 起始时间戳(微秒)
     */
    private readonly float $startTime;

    /**
     * @param int $maxRunSeconds 单轮时间预算(秒)
     * @param int $maxRequests 每站每轮最大请求数
     * @param int $interval 相邻请求最小间隔(秒)
     */
    public function __construct(
        private readonly int $maxRunSeconds,
        private readonly int $maxRequests,
        private readonly int $interval
    )
    {
        $this->startTime = microtime(true);
    }

    /**
     * 单轮时间是否已耗尽
     * @return bool
     */
    public function timeUp(): bool
    {
        return (microtime(true) - $this->startTime) >= $this->maxRunSeconds;
    }

    /**
     * 站点是否还允许发起请求
     * @param string $site
     * @return bool
     */
    public function allow(string $site): bool
    {
        if ($this->timeUp()) {
            return false;
        }

        return ($this->perSite[$site] ?? 0) < $this->maxRequests;
    }

    /**
     * 记录一次请求并休眠
     * @param string $site
     * @return void
     */
    public function hit(string $site): void
    {
        $this->perSite[$site] = ($this->perSite[$site] ?? 0) + 1;
        if (0 < $this->interval) {
            sleep($this->interval);
        }
    }

    /**
     * 抬升请求间隔（不低于站点限速配置）
     * @param int $seconds
     * @return void
     */
    public function intervalAtLeast(int $seconds): void
    {
        if ($seconds > $this->interval) {
            $this->interval = min($seconds, 60);
        }
    }

    /**
     * 站点已用请求数
     * @param string $site
     * @return int
     */
    public function used(string $site): int
    {
        return $this->perSite[$site] ?? 0;
    }
}
