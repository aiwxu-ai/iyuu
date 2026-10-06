<?php

namespace Iyuu\SiteManager\Cookie;

use Iyuu\SiteManager\BaseCookie;
use Iyuu\SiteManager\Frameworks\NexusPhp\HasCookie;
use Iyuu\SiteManager\Spider\Pagination;

/**
 * daxiangjiao 大香蕉
 * - 凭cookie解析HTML列表页
 * @link https://pt.daxiangjiao.org/
 */
class CookieDaxiangjiao extends BaseCookie
{
    use HasCookie, Pagination;

    /**
     * 站点名称
     */
    public const string SITE_NAME = 'daxiangjiao';
}
