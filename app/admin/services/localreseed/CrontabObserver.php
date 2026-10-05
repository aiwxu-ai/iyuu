<?php

namespace app\admin\services\localreseed;

use app\command\LocalReseedCommand;
use app\model\Site;
use Iyuu\SiteManager\BaseCookie;
use InvalidArgumentException;
use plugin\cron\app\model\Crontab;

/**
 * 模型观察者：cn_crontab（本地辅种）
 * @usage Crontab::observe(CrontabObserver::class);
 */
class CrontabObserver
{
    /**
     * 监听数据即将保存的事件。
     *
     * @param Crontab $model
     * @return void
     */
    public function saving(Crontab $model): void
    {
        $task_type = $model->task_type;
        if (LocalReseedSelectEnums::localReseed->value === (int)$task_type) {
            $model->target = LocalReseedCommand::COMMAND_NAME;
            $parameter = $model->parameter;
            if (empty($parameter)) {
                throw new InvalidArgumentException('目标站点、来源下载器必填');
            }

            $parameter = is_array($parameter) ? $parameter : json_decode($parameter, true);
            if (empty($parameter['sites'])) {
                throw new InvalidArgumentException('目标站点必填');
            }
            if (empty($parameter['clients'])) {
                throw new InvalidArgumentException('来源下载器必填');
            }

            // 目标站点必须：存在、未禁用、已适配爬虫、已配置cookie
            foreach (array_keys($parameter['sites']) as $site) {
                $siteModel = Site::uniqueSite((string)$site);
                if (!$siteModel) {
                    throw new InvalidArgumentException('站点不存在：' . $site);
                }
                if ($siteModel->disabled) {
                    throw new InvalidArgumentException('站点已禁用：' . $site);
                }
                if (empty($siteModel->cookie)) {
                    throw new InvalidArgumentException('站点未配置cookie：' . $site);
                }
                if (!is_subclass_of(BaseCookie::siteToClass((string)$site), BaseCookie::class)) {
                    throw new InvalidArgumentException('站点未适配爬虫驱动：' . $site);
                }
            }
        }
    }
}
