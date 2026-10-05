<?php

namespace Iyuu\SiteManager\Cookie;

use Iyuu\SiteManager\BaseCookie;
use Iyuu\SiteManager\Frameworks\NexusPhp\HasCookie;
use Iyuu\SiteManager\Spider\Pagination;

/**
 * 52movie
 * - 凭cookie解析HTML列表页
 * @link https://www.52movie.top/
 */
class Cookie52movie extends BaseCookie
{
    use HasCookie, Pagination;

    /**
     * 站点名称
     */
    public const string SITE_NAME = '52movie';
}
