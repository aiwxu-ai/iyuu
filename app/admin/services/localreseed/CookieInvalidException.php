<?php

namespace app\admin\services\localreseed;

use RuntimeException;

/**
 * 站点cookie失效异常（站点级中止信号）
 */
class CookieInvalidException extends RuntimeException
{
}
