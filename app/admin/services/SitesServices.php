<?php

namespace app\admin\services;

use app\model\Site;
use plugin\admin\app\common\Util;
use plugin\admin\app\model\Option;
use plugin\cron\app\support\PushNotify;
use think\helper\Str;
use Throwable;

/**
 * 站点服务层
 */
class SitesServices
{
    /**
     * IYUU 浏览器助手配置键名
     */
    public const string SYSTEM_IYUU_HELPER = 'system_iyuu_helper';

    /**
     * 客户端内置站点（IYUU服务端未收录，来源：savept.icu 国内+在线）
     * - sid使用9000+段，避免与服务端分配的sid冲突（列无符号，不能用负数）
     * - 结构与辅种服务器返回的站点数据保持一致
     */
    private const array LOCAL_SITES = [
        '52movie' => [
            'id' => 9001,
            'nickname' => '52Movie',
            'base_url' => 'www.52movie.top',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'xdypt' => [
            'id' => 9002,
            'nickname' => '修道院',
            'base_url' => 'xdypt.vip',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'daxiangjiao' => [
            'id' => 9003,
            'nickname' => '大香蕉',
            'base_url' => 'pt.daxiangjiao.org',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'vclib' => [
            'id' => 9004,
            'nickname' => 'VC-Lib',
            'base_url' => 'pt.vclib.online',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'musopia' => [
            'id' => 9005,
            'nickname' => '音乐乌托邦',
            'base_url' => 'www.musopia.vip',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'dstudio' => [
            'id' => 9006,
            'nickname' => 'DepthStudio',
            'base_url' => 'dstudio.me',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'xingwan' => [
            'id' => 9007,
            'nickname' => '星湾',
            'base_url' => 'xingwan.cc',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'momentpt' => [
            'id' => 9008,
            'nickname' => '瞬间',
            'base_url' => 'www.momentpt.top',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'ptfans' => [
            'id' => 9009,
            'nickname' => 'PTFans',
            'base_url' => 'ptfans.cc',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'sbpt' => [
            'id' => 9010,
            'nickname' => 'SBPT',
            'base_url' => 'sbpt.link',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'alingpt' => [
            'id' => 9011,
            'nickname' => 'alingPT',
            'base_url' => 'pt.aling.de',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'tey' => [
            'id' => 9012,
            'nickname' => '太乙',
            'base_url' => 'pt.tey.cc',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'tokyomanga' => [
            'id' => 9013,
            'nickname' => 'Tokyo漫画',
            'base_url' => 'www.tokyo-manga.top',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'ptlao' => [
            'id' => 9014,
            'nickname' => '忘年桥',
            'base_url' => 'ptlao.top',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'kelu' => [
            'id' => 9015,
            'nickname' => 'Kelu',
            'base_url' => 'our.kelu.one',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'azusa' => [
            'id' => 9016,
            'nickname' => '梓喵',
            'base_url' => 'azusa.wiki',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'itzmx' => [
            'id' => 9017,
            'nickname' => 'itzmx',
            'base_url' => 'pt.itzmx.com',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
        'ptneko' => [
            'id' => 9018,
            'nickname' => '超科学PT喵',
            'base_url' => 'ptneko.com',
            'download_page' => 'download.php?id={}&passkey={passkey}',
            'details_page' => 'details.php?id={}',
            'is_https' => 1,
            'cookie_required' => 0,
        ],
    ];

    /**
     * 获取IYUU 浏览器助手密钥
     * @return Option
     */
    public static function getIyuuHelper(): string
    {
        $option = Option::where('name', '=', self::SYSTEM_IYUU_HELPER)->first();
        if (!$option) {
            $option = new Option();
            $option->name = self::SYSTEM_IYUU_HELPER;
            $option->value = Str::random(40, 0);
            $option->save();
        }
        return $option->value;
    }

    /**
     * 同步站点表
     * @return void
     */
    public static function sync(): void
    {
        try {
            if (!Util::schema()->hasTable(Site::TABLE_NAME)) {
                return;
            }

            $reseedClient = iyuu_reseed_client();
            $list = array_merge($reseedClient->sites(), self::LOCAL_SITES);
            file_put_contents(runtime_path('sync.json'), json_encode($list, JSON_UNESCAPED_UNICODE));
            foreach ($list as $site => $item) {
                $siteModel = Site::uniqueSite($site);
                if (!$siteModel) {
                    $siteModel = new Site();
                    $siteModel->sid = $item['id'];
                    $siteModel->site = $site;
                }

                $siteModel->nickname = $item['nickname'];
                $siteModel->base_url = $item['base_url'];
                $siteModel->download_page = $item['download_page'] ?? '';
                $siteModel->details_page = $item['details_page'] ?? '';
                $siteModel->is_https = $item['is_https'] ?? 1;
                $siteModel->cookie_required = $item['cookie_required'] ?? 0;
                $siteModel->save();
            }
        } catch (Throwable $throwable) {
            $msg = '同步站点列表失败：' . $throwable->getMessage();
            PushNotify::error($msg);
            echo $msg . PHP_EOL;
        }
    }
}
