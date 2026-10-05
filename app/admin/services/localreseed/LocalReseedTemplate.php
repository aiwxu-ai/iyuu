<?php

namespace app\admin\services\localreseed;

use app\command\LocalReseedCommand;
use app\model\enums\DownloaderMarkerEnums;
use app\model\enums\NotifyChannelEnums;
use Ledc\Element\GenerateInterface;
use plugin\cron\app\interfaces\CrontabAbstract;
use plugin\cron\app\services\CrontabRocket;
use Workerman\Crontab\Crontab as WorkermanCrontab;

/**
 * 计划任务：本地辅种配置模板
 */
class LocalReseedTemplate extends CrontabAbstract
{
    /**
     * 枚举条目转为数组
     * - 文本描述 => 值
     * @return array
     */
    public static function select(): array
    {
        return LocalReseedSelectEnums::select();
    }

    /**
     * 生成Layui计划任务配置模板
     * @param int $type
     * @return GenerateInterface|null
     */
    public function generate(int $type): ?GenerateInterface
    {
        return match ($type) {
            LocalReseedSelectEnums::localReseed->value => $this,
            default => null,
        };
    }

    /**
     * 启动器
     * @param CrontabRocket $rocket
     * @return WorkermanCrontab|null
     */
    public function start(CrontabRocket $rocket): ?WorkermanCrontab
    {
        return static::startCrontab(LocalReseedSelectEnums::localReseed->value, '本地辅种', $rocket);
    }

    /**
     * @return string
     */
    public function html(): string
    {
        $command = LocalReseedCommand::COMMAND_NAME;
        $markerEmpty = DownloaderMarkerEnums::Empty->value;
        $markerTag = DownloaderMarkerEnums::Tag->value;
        $markerCategory = DownloaderMarkerEnums::Category->value;
        $notify_iyuu = NotifyChannelEnums::notify_iyuu->value;
        $notify_server_chan = NotifyChannelEnums::notify_server_chan->value;
        $notify_bark = NotifyChannelEnums::notify_bark->value;
        $notify_qy_weixin = NotifyChannelEnums::notify_qy_weixin->value;
        $notify_webhook = NotifyChannelEnums::notify_webhook->value;
        return PHP_EOL . <<<EOF
<style>
.layui-input-wrap {
    width: 60px !important;
    line-height: 20px !important;
}
</style>
<div class="layui-form-item layui-hide">
    <label class="layui-form-label required">命令名称</label>
    <div class="layui-input-block">
        <input type="text" name="target" value="$command" required lay-verify="required" placeholder="请输入命令名称" class="layui-input" readonly>
    </div>
</div>
<div name="parameter" id="parameter" value="" class="layui-hide"></div>
<div class="layui-form-item">
    <label class="layui-form-label required">目标站点</label>
    <div class="layui-input-block">
        <div name="sites" id="sites" value=""></div>
    </div>
    <div class="layui-form-mid layui-text-em">在目标站内搜索本地做种名称并精确匹配体积（需已配置cookie）</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label required">来源下载器</label>
    <div class="layui-input-block">
        <div name="clients" id="clients" value=""></div>
    </div>
    <div class="layui-form-mid layui-text-em">遍历这些下载器内正在做种的种子</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label required">主辅分离</label>
    <div class="layui-input-block" id="master" value=""></div>
    <div class="layui-form-mid layui-text-em">辅种全部添加到此下载器（PS：数据目录需保持一致）</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">路径过滤器</label>
    <div class="layui-input-block">
        <div name="parameter[path_filter]" id="path_filter" value=""></div>
        <div class="layui-form-mid layui-text-em">排除目录内的资源，不辅种</div>
    </div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label required">搜索范围</label>
    <div class="layui-input-block">
        <input type="radio" name="parameter[incldead]" value="0" title="仅活种">
        <input type="radio" name="parameter[incldead]" value="1" title="含死种" checked>
        <input type="radio" name="parameter[incldead]" value="2" title="仅死种">
    </div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">候选上限</label>
    <div class="layui-input-inline layui-input-wrap">
        <input type="number" name="parameter[max_candidates]" value="3" min="1" max="10" lay-verify="number" class="layui-input">
    </div>
    <div class="layui-form-mid layui-text-em">每次搜索最多下载元数据比对的候选数(1-10)</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">请求间隔</label>
    <div class="layui-input-inline layui-input-wrap">
        <input type="number" name="parameter[search_interval]" value="5" min="0" max="60" lay-verify="number" class="layui-input">
    </div>
    <div class="layui-form-mid layui-text-em">对站点的相邻请求最小间隔(秒)，自动抬升至站点限速配置</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">每站请求上限</label>
    <div class="layui-input-inline layui-input-wrap">
        <input type="number" name="parameter[max_requests]" value="100" min="1" class="layui-input">
    </div>
    <div class="layui-form-mid layui-text-em">每站每轮最大请求数(搜索+元数据合计)，剩余下一轮续跑</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">单轮时间</label>
    <div class="layui-input-inline layui-input-wrap">
        <input type="number" name="parameter[max_run_seconds]" value="900" min="60" max="1100" lay-verify="number" class="layui-input">
    </div>
    <div class="layui-form-mid layui-text-em">单轮最长运行秒数(60-1100)，必须小于任务超时1200秒</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label required">通知渠道</label>
    <div class="layui-input-block">
        <input type="radio" name="parameter[notify_channel]" value="" title="不通知" checked>
        <input type="radio" name="parameter[notify_channel]" value="$notify_iyuu" title="爱语飞飞">
        <input type="radio" name="parameter[notify_channel]" value="$notify_server_chan" title="Server酱">
        <input type="radio" name="parameter[notify_channel]" value="$notify_bark" title="Bark">
        <input type="radio" name="parameter[notify_channel]" value="$notify_qy_weixin" title="企业微信群机器人">
        <input type="radio" name="parameter[notify_channel]" value="$notify_webhook" title="自定义通知">
    </div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label required" title="辅种成功后，对添加的种子做标记">标记规则</label>
    <div class="layui-input-block">
        <input type="radio" name="parameter[marker]" value="$markerEmpty" title="不操作" checked>
        <input type="radio" name="parameter[marker]" value="$markerTag" title="标记标签">
        <input type="radio" name="parameter[marker]" value="$markerCategory" title="标记分类">
    </div>
    <div class="layui-form-mid layui-text-em">辅种成功后，对种子做标记（需要下载器支持）</div>
</div>
<div class="layui-form-item">
    <label class="layui-form-label">自动校验</label>
    <div class="layui-input-inline layui-input-wrap">
        <input type="checkbox" name="parameter[auto_check]" lay-skin="switch" title="ON|OFF" lay-filter="auto_check" id="auto_check">
    </div>
    <div class="layui-form-mid layui-text-em">此功能在TR以及低版本QB中属于默认行为，是否勾选都会自动校验</div>
</div>
<!-- 目标站点模板 -->
<script type="text/html" id="sites_tpl">
<div class="layui-col-xs12 layui-col-space10">
    <div class="layui-col-xs6 layui-col-sm4 layui-col-md3">
        <button type="button" class="layui-btn layui-btn-sm layui-btn-fluid layui-btn-radius" lay-on="select_all" id="select_all">全选</button>
    </div>
    <div class="layui-col-xs6 layui-col-sm4 layui-col-md3">
        <button type="button" class="layui-btn layui-btn-sm layui-btn-fluid layui-btn-radius layui-btn-normal" lay-on="select_invert">反选</button>
    </div>
</div>
{{#  layui.each(d, function(index, item){ }}
<div class="layui-col-xs6 layui-col-sm4 layui-col-md3 layui-col-lg2">
    <input type="checkbox" name="parameter[sites][{{= item.value }}]" title="{{= item.name }}" lay-skin="tag">
</div>
{{#  }); }}
</script>
<!-- 来源下载器模板 -->
<script type="text/html" id="clients_tpl">
{{#  layui.each(d, function(index, item){ }}
<div class="layui-col-xs6 layui-col-sm4 layui-col-md3 layui-col-lg2">
    <input type="checkbox" name="parameter[clients][{{= item.value }}]" title="{{= item.name }}" lay-skin="tag">
</div>
{{#  }); }}
</script>

<!-- 主辅下载器分离模板 -->
<script type="text/html" id="master_tpl">
<input type="radio" name="parameter[master]" value="" title="不启用" checked>
{{#  layui.each(d, function(index, item){ }}
<input type="radio" name="parameter[master]" value="{{= item.value }}" title="{{= item.name }}" lay-skin="tag">
{{#  }); }}
</script>
EOF;
    }

    /**
     * @return string
     */
    public function js(): string
    {
        return PHP_EOL . file_get_contents(__DIR__ . '/localreseed.js');
    }
}
