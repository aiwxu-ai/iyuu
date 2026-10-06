<?php

namespace Iyuu\SiteManager\Cookie;

use Iyuu\SiteManager\BaseCookie;
use Iyuu\SiteManager\Frameworks\NexusPhp\HasCookie;
use Iyuu\SiteManager\Spider\Pagination;

/**
 * tokyomanga Tokyo漫画
 * - 凭cookie解析HTML列表页
 * @link https://www.tokyo-manga.top/
 */
class CookieTokyomanga extends BaseCookie
{
    use HasCookie, Pagination;

    /**
     * 站点名称
     */
    public const string SITE_NAME = 'tokyomanga';
}
