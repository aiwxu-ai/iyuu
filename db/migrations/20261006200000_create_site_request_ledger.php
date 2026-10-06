<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 站点请求全局台账（跨进程记账：每日上限/最小间隔/连续失败熔断）
 * 单行每站：day != 今天时请求数视为0（惰性重置），cooldown_until 跨天持续生效
 */
final class CreateSiteRequestLedger extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        if (!$this->hasTable('cn_site_request_ledger')) {
            $sql = "CREATE TABLE IF NOT EXISTS `cn_site_request_ledger` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `site` varchar(30) NOT NULL COMMENT '站点名称',
  `day` date NOT NULL COMMENT '记账日',
  `requests` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '当日请求数(含失败)',
  `last_ts` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最近请求时间戳',
  `consec_fail` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '连续失败次数',
  `cooldown_until` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '熔断截止时间戳',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site` (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点请求全局台账'";
            $this->execute($sql);
        }
    }
}
