-- JWT v2 刷新令牌表创建脚本
-- 文件路径：database/migrations/create_refresh_tokens_table.sql
-- 执行方式：mysql -u用户名 -p密码 数据库名 < create_refresh_tokens_table.sql

-- 创建刷新令牌表
CREATE TABLE IF NOT EXISTS `ch_refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id` int(11) NOT NULL COMMENT '用户ID（关联ch_users.id）',
  `refresh_token` varchar(255) NOT NULL COMMENT '刷新令牌（hash值）',
  `access_token_id` varchar(255) DEFAULT NULL COMMENT '关联的访问令牌jti（用于批量撤销）',
  `ip` varchar(50) DEFAULT NULL COMMENT 'IP地址',
  `user_agent` varchar(255) DEFAULT NULL COMMENT 'User-Agent标识',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：1=有效，0=已撤销',
  `expires_at` datetime NOT NULL COMMENT '过期时间',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_refresh_token` (`refresh_token`) COMMENT '刷新令牌唯一索引',
  KEY `idx_user_id` (`user_id`) COMMENT '用户ID索引',
  KEY `idx_access_token_id` (`access_token_id`) COMMENT '访问令牌ID索引',
  KEY `idx_expires_at` (`expires_at`) COMMENT '过期时间索引',
  KEY `idx_status_expires` (`status`, `expires_at`) COMMENT '状态和过期时间联合索引'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='JWT刷新令牌表';
