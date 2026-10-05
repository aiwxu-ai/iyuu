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

        try {
            $output->writeln(date('Y-m-d H:i:s') . "即将执行本地辅种，任务id：{$crontab_id}");
            $localReseedServices = new LocalReseedServices((int)$crontab_id);
            $localReseedServices->run();
        } catch (\Error|\Exception|\Throwable $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
        } finally {
            echo_system_info();
            $output->writeln('本地辅种执行完毕！');
        }

        return self::SUCCESS;
    }
}
