<?php

namespace process;

use app\admin\services\localreseed\LocalReseedSelectEnums;
use app\admin\services\localreseed\SearchBudget;
use plugin\cron\api\Install;
use plugin\cron\app\model\Crontab;
use support\Log;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;

/**
 * 本地辅种常驻触发进程
 *
 * 背景：plugin/cron 调度器对命令类任务存在不可靠场景（启动期MySQL竞态崩溃、
 * running_count因进程被杀不归零导致永久跳过、事件文件重载后不再触发）。
 * 本进程以固定节奏触发本地辅种任务，不依赖插件调度器的内存状态。
 *
 * 设计要点：
 * - 每 TICK_SECONDS 评估一次：活跃窗口(09-23)内、距上次触发≥触发间隔(带±抖动)才spawn
 * - 动态发现全部启用的 task_type=13 任务（新增任务无需重启本进程）
 * - 互斥由子命令 LocalReseedCommand 的 flock 保证（内核级、进程死亡自动释放），
 *   与插件调度器/CronTab触发/手动CLI同时存在也不会重入
 * - 任务自身的预算/台账仍是一切站点请求的最终闸门
 */
class LocalReseedTicker
{
    /**
     * 评估周期(秒)
     */
    protected const int TICK_SECONDS = 60;
    /**
     * 默认触发间隔(秒)，可用环境变量 LOCAL_RESEED_TICKER_INTERVAL 覆盖(下限600)
     */
    protected const int DEFAULT_INTERVAL = 1800;
    /**
     * 启动后首评延迟(秒)：给MySQL等依赖留出启动时间，之后按TICK_SECONDS周期评估
     */
    protected const int FIRST_TICK_DELAY = 75;

    /**
     * 每任务最近一次触发时刻( microtime )
     * @var array<int, float>
     */
    protected array $lastFireAt = [];

    /**
     * 子进程启动回调
     * @param Worker $worker
     * @return void
     */
    public function onWorkerStart(Worker $worker): void
    {
        if (!Install::isInstalled()) {
            return;
        }

        Timer::add(self::TICK_SECONDS, [$this, 'tick']);
        echo '[' . date('Y-m-d H:i:s') . '] LocalReseedTicker 已启动，评估周期 ' . self::TICK_SECONDS . 's，触发间隔 ' . $this->intervalSeconds() . 's' . PHP_EOL;
    }

    /**
     * 周期评估：窗口内、间隔到、任务启用 → spawn一轮
     * @return void
     */
    public function tick(): void
    {
        try {
            if (!SearchBudget::inActiveWindow()) {
                return;    // 凌晨静默：站点请求本就被拒，省去无谓的下载器采集
            }

            $interval = $this->intervalSeconds();
            /** @var Crontab[] $tasks */
            $tasks = Crontab::where('task_type', '=', LocalReseedSelectEnums::localReseed->value)
                ->where('enabled', '=', 1)
                ->get();
            foreach ($tasks as $task) {
                $id = (int)$task->crontab_id;
                $elapsed = microtime(true) - ($this->lastFireAt[$id] ?? 0.0);
                // 抖动：90%~115%，避免与整点对齐形成固定节律
                if ($elapsed < $interval * mt_rand(90, 115) / 100) {
                    continue;
                }
                $this->lastFireAt[$id] = microtime(true);
                $this->spawn($id);
            }
        } catch (Throwable $throwable) {
            // 评估循环绝不因单次异常中断
            echo '[' . date('Y-m-d H:i:s') . '] LocalReseedTicker tick异常：' . $throwable->getMessage() . PHP_EOL;
            Log::error('LocalReseedTicker tick异常：' . $throwable->getMessage());
        }
    }

    /**
     * 分离态spawn一轮本地辅种（互斥由命令内flock保证）
     * @param int $crontab_id
     * @return void
     */
    protected function spawn(int $crontab_id): void
    {
        $logFile = runtime_path() . '/logs/localreseed-' . date('Ymd') . '.log';
        $cmd = sprintf(
            'cd %s && %s %s/webman %s %d >> %s 2>&1 &',
            base_path(),
            PHP_BINARY,
            base_path(),
            \app\command\LocalReseedCommand::COMMAND_NAME,
            $crontab_id,
            $logFile
        );
        shell_exec($cmd);
        echo '[' . date('Y-m-d H:i:s') . "] LocalReseedTicker 触发任务 {$crontab_id}" . PHP_EOL;
    }

    /**
     * 触发间隔(秒)
     * @return int
     */
    protected function intervalSeconds(): int
    {
        $interval = (int)(getenv('LOCAL_RESEED_TICKER_INTERVAL') ?: self::DEFAULT_INTERVAL);
        return max(600, $interval);
    }
}
