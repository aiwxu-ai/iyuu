<?php

namespace app\command;

use app\admin\services\localreseed\LocalReseedServices;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 本地搜索式辅种
 * - 对IYUU服务端未收录的站点，用站内搜索+精确体积匹配实现辅种
 */
class LocalReseedCommand extends Command
{
    /**
     * 命令名称
     */
    public const string COMMAND_NAME = 'iyuu:local-reseed';

    /**
     * 命令名称
     * @var string
     */
    protected static string $defaultName = self::COMMAND_NAME;
    /**
     * @var string
     */
    protected static string $defaultDescription = 'IYUU：本地搜索式辅种';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addArgument('crontab_id', InputArgument::REQUIRED, '计划任务ID');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $crontab_id = $input->getArgument('crontab_id');

        // 内核级互斥：同任务并发触发（常驻Ticker/插件调度器/手动CLI三方并存）只放行一个；
        // flock在进程退出或被杀时由内核自动释放——不存在"计数不归零"的卡死形态
        $lockFile = runtime_path() . '/localreseed_' . $crontab_id . '.lock';
        $lockHandle = fopen($lockFile, 'c');
        if (false === $lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $output->writeln(date('Y-m-d H:i:s') . "任务 {$crontab_id} 已有实例在运行，本次触发跳过");
            return self::SUCCESS;
        }

        try {
            $output->writeln(date('Y-m-d H:i:s') . "即将执行本地辅种，任务id：{$crontab_id}");
            $localReseedServices = new LocalReseedServices((int)$crontab_id);
            $localReseedServices->run();
        } catch (\Error|\Exception|\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
        } finally {
            echo_system_info();
            $output->writeln('本地辅种执行完毕！');
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }

        return self::SUCCESS;
    }
}
