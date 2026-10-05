<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 本地搜索式辅种进度表
 */
final class CreateLocalReseed extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        if (!$this->hasTable('cn_local_reseed')) {
            $sql = "CREATE TABLE IF NOT EXISTS `cn_local_reseed` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `crontab_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '首次同步时的计划任务ID',
  `client_id` int(10) UNSIGNED NOT NULL COMMENT '本地做种下载器ID',
  `info_hash` varchar(80) NOT NULL COMMENT '本地种子infohash',
  `target_sid` int(10) UNSIGNED NOT NULL COMMENT '目标站点ID',
  `target_site` varchar(30) NOT NULL DEFAULT '' COMMENT '目标站点名称',
  `torrent_name` varchar(500) NOT NULL DEFAULT '' COMMENT '本地种子名称',
  `torrent_size` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '本地种子体积(字节)',
  `directory` varchar(900) NOT NULL DEFAULT '' COMMENT '本地做种目录',
  `keyword` varchar(200) NOT NULL DEFAULT '' COMMENT '实际使用的搜索词',
  `status` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '状态：0待搜索1搜索中2无匹配3已命中4失败5跳过',
  `candidates` smallint(5) UNSIGNED NOT NULL DEFAULT '0' COMMENT '搜索返回候选数',
  `reseed_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '命中后写入cn_reseed的主键',
  `search_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最近搜索时间戳',
  `message` varchar(1000) NOT NULL DEFAULT '' COMMENT '异常信息',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_hash_site` (`client_id`,`info_hash`,`target_sid`),
  KEY `idx_status_id` (`status`,`id`),
  KEY `idx_target_sid` (`target_sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='本地搜索式辅种进度'";
            $this->execute($sql);
        }
    }
}
