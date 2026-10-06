START TRANSACTION;

CREATE TABLE IF NOT EXISTS `cn_client` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `brand` varchar(50) NOT NULL COMMENT '下载器品牌',
  `title` varchar(100) NOT NULL COMMENT '标题',
  `hostname` varchar(200) NOT NULL COMMENT '协议主机',
  `endpoint` varchar(100) NOT NULL COMMENT '接入点',
  `username` varchar(50) NOT NULL COMMENT '用户名',
  `password` varchar(80) NOT NULL COMMENT '密码',
  `watch_path` varchar(200) NOT NULL DEFAULT '' COMMENT '监控目录',
  `save_path` varchar(200) NOT NULL DEFAULT '' COMMENT '资源保存路径',
  `torrent_path` varchar(200) NOT NULL DEFAULT '' COMMENT '种子目录',
  `root_folder` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '创建多文件子目录',
  `is_debug` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '调试',
  `is_default` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '默认',
  `seeding_after_completed` tinyint(4) UNSIGNED NOT NULL DEFAULT '1' COMMENT '校验后做种',
  `enabled` tinyint(1) UNSIGNED NOT NULL DEFAULT '1' COMMENT '启用',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `hostname` (`hostname`,`endpoint`),
  KEY `brand` (`brand`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='客户端';

CREATE TABLE IF NOT EXISTS `cn_folder` (
  `folder_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `folder_alias` varchar(100) NOT NULL COMMENT '目录别名',
  `folder_value` varchar(300) NOT NULL COMMENT '数据目录',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`folder_id`),
  UNIQUE KEY `folder_alias` (`folder_alias`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='数据目录';

CREATE TABLE IF NOT EXISTS `cn_local_reseed` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='本地搜索式辅种进度';

CREATE TABLE IF NOT EXISTS `cn_site_torrent` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点种子本地索引';

CREATE TABLE IF NOT EXISTS `cn_site_index` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点索引抓取进度';

CREATE TABLE IF NOT EXISTS `cn_reseed` (
  `reseed_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `client_id` int(10) UNSIGNED NOT NULL COMMENT '客户端ID',
  `site` varchar(30) NOT NULL COMMENT '站点名字',
  `sid` int(10) UNSIGNED NOT NULL COMMENT '站点ID',
  `torrent_id` int(10) UNSIGNED NOT NULL COMMENT '种子ID',
  `group_id` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '种子分组ID',
  `info_hash` varchar(80) NOT NULL DEFAULT '' COMMENT '种子infohash',
  `directory` varchar(900) NOT NULL DEFAULT '' COMMENT '目标文件夹',
  `dispatch_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '调度时间',
  `status` tinyint(3) UNSIGNED NOT NULL DEFAULT '0' COMMENT '状态',
  `subtype` tinyint(4) UNSIGNED NOT NULL DEFAULT '0' COMMENT '业务子类型',
  `payload` text COMMENT '有效载荷',
  `message` text COMMENT '异常信息',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`reseed_id`),
  KEY `reseed_client_id` (`client_id`),
  KEY `reseed_sid` (`sid`),
  KEY `dispatch_time` (`dispatch_time`),
  KEY `status` (`status`),
  KEY `info_hash` (`info_hash`),
  KEY `site` (`site`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自动辅种';

CREATE TABLE IF NOT EXISTS `cn_sites` (
  `id` mediumint(5) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `sid` int(10) UNSIGNED NOT NULL COMMENT '站点ID',
  `site` varchar(30) NOT NULL COMMENT '站点名称',
  `nickname` varchar(60) NOT NULL COMMENT '昵称',
  `base_url` varchar(100) NOT NULL COMMENT '域名',
  `mirror` varchar(150) NOT NULL DEFAULT '' COMMENT '镜像域名',
  `cookie` varchar(2000) DEFAULT '' COMMENT 'cookie',
  `download_page` varchar(200) NOT NULL DEFAULT '' COMMENT '下载种子页',
  `details_page` varchar(200) NOT NULL DEFAULT '' COMMENT '详情页',
  `is_https` tinyint(3) UNSIGNED NOT NULL DEFAULT '1' COMMENT '可选：0http，1https，2http+https',
  `cookie_required` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT 'cookie必须',
  `options` longtext COMMENT '用户配置值',
  `disabled` tinyint(3) UNSIGNED NOT NULL DEFAULT '1' COMMENT '禁用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `site` (`site`),
  UNIQUE KEY `sid` (`sid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='站点配置';

CREATE TABLE IF NOT EXISTS `cn_totp` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(200) NOT NULL COMMENT '名称',
  `secret` varchar(128) NOT NULL COMMENT '密钥',
  `issuer` varchar(200) NOT NULL DEFAULT '' COMMENT '发行方',
  `t0` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '开始纪元',
  `t1` smallint(5) UNSIGNED NOT NULL DEFAULT '30' COMMENT '时间周期',
  `algo` varchar(50) NOT NULL DEFAULT 'sha1' COMMENT '散列算法',
  `digits` tinyint(3) UNSIGNED NOT NULL DEFAULT '6' COMMENT '令牌位数',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态令牌';

CREATE TABLE IF NOT EXISTS `cn_transfer` (
  `transfer_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
  `from_client_id` int(10) UNSIGNED NOT NULL COMMENT '来源',
  `to_client_id` int(10) UNSIGNED NOT NULL COMMENT '目标',
  `info_hash` varchar(80) NOT NULL DEFAULT '' COMMENT '种子infohash',
  `directory` varchar(900) NOT NULL DEFAULT '' COMMENT '转换前目录',
  `convert_directory` varchar(900) NOT NULL DEFAULT '' COMMENT '转换后目录',
  `torrent_file` varchar(900) NOT NULL DEFAULT '' COMMENT '种子文件路径',
  `message` text COMMENT '结果消息',
  `state` tinyint(1) UNSIGNED NOT NULL DEFAULT '0' COMMENT '状态',
  `last_time` int(10) UNSIGNED NOT NULL DEFAULT '0' COMMENT '最后操作',
  `created_at` datetime DEFAULT NULL COMMENT '创建时间',
  `updated_at` datetime DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`transfer_id`),
  KEY `from_client_id` (`from_client_id`),
  KEY `to_client_id` (`to_client_id`),
  KEY `info_hash` (`info_hash`),
  KEY `state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='自动转移';
COMMIT;