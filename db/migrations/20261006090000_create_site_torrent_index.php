<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 站点种子本地索引（建库模式的存储）
 */
final class CreateSiteTorrentIndex extends AbstractMigration
{
    /**
     * Change Method.
     */
    public function change(): void
    {
        if (!$this->hasTable('cn_site_torrent')) {
            $sql = "CREATE TABLE IF NOT EXISTS `cn_site_torrent` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `sid` int(10) UNSIGNED NOT NULL COMMENT '站点ID',
  `site` varchar(30) NOT NULL DEFAULT '' COMMENT '站点名称',
  `torrent_id` int(10) UNSIGNED NOT NULL COMMENT '站内种子ID',
  `title` varchar(500) NOT NULL DEFAULT '' COMMENT '主标题(发布名)',
  `title_key` varchar(500) NOT NULL DEFAULT '' COMMENT '归一化标题(匹配键)',
  `size_bytes` bigint(20) UNSIGNED NOT NULL DEFAULT '0' COMMENT '体积(字节,页面精度)',
  `free` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '是否免费',
  `sticky` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '是否置顶',
  `download_uri` varchar(500) NOT NULL DEFAULT '' COMMENT '下载链接(相对URI)',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sid_tid` (`sid`,`torrent_id`),
  KEY `idx_title_key` (`title_key`(100)),
  KEY `idx_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点种子本地索引'";
            $this->execute($sql);
        }

        if (!$this->hasTable('cn_site_index')) {
            $sql = "CREATE TABLE IF NOT EXISTS `cn_site_index` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `sid` int(10) UNSIGNED NOT NULL COMMENT '站点ID',
  `site` varchar(30) NOT NULL DEFAULT '' COMMENT '站点名称',
  `last_page` int(10) NOT NULL DEFAULT '-1' COMMENT '已抓取到的最大页码',
  `total_indexed` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '已索引种子总数',
  `full_done` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '全库抓取完成标记',
  `last_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最近抓取时间戳',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点索引抓取进度'";
            $this->execute($sql);
        }
    }
}
