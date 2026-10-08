-- 本地主库初始化脚本（按 app/model 字段定义反推，仅用于本地开发）
-- 数据库：chats（.env 里 DB_NAME 配置）

CREATE DATABASE IF NOT EXISTS `chats` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `chats`;

-- 用户表
CREATE TABLE IF NOT EXISTS `ch_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nick_name` varchar(50) NOT NULL COMMENT '昵称',
  `password` varchar(255) NOT NULL COMMENT '密码',
  `avatar` varchar(255) DEFAULT '' COMMENT '头像URL',
  `sign` varchar(255) DEFAULT '' COMMENT '个性签名',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `is_ban` tinyint NOT NULL DEFAULT 0 COMMENT '是否封禁',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户';

-- 登录令牌表
CREATE TABLE IF NOT EXISTS `ch_tokens` (
  `id` varchar(64) NOT NULL COMMENT '令牌ID',
  `user_id` int NOT NULL,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0过期',
  `ip` varchar(50) DEFAULT '',
  `expire_time` datetime DEFAULT NULL,
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录令牌';

-- 房间表
CREATE TABLE IF NOT EXISTS `ch_rooms` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT '房间名',
  `description` varchar(255) DEFAULT '' COMMENT '描述',
  `owner_id` int NOT NULL COMMENT '房主ID',
  `password` varchar(64) NOT NULL DEFAULT '' COMMENT '房间密码(空为公开房间)',
  `private` tinyint NOT NULL DEFAULT 0 COMMENT '是否私密',
  `lock` tinyint NOT NULL DEFAULT 0 COMMENT '0未锁 1锁定',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房间';

-- 房间成员表
CREATE TABLE IF NOT EXISTS `ch_room_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1在房间 0已离开',
  `join_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_room_user` (`room_id`, `user_id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='房间成员';

-- 消息表
CREATE TABLE IF NOT EXISTS `ch_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room_id` int NOT NULL,
  `user_id` int DEFAULT NULL COMMENT '发送者ID，系统消息为NULL',
  `message_type` tinyint NOT NULL DEFAULT 1 COMMENT '1文本 2图片 3文件 4系统 5视频',
  `content` mediumtext COMMENT '内容/文件URL',
  `file_info` json DEFAULT NULL COMMENT '文件信息',
  `extra_data` json DEFAULT NULL COMMENT '扩展数据',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delete_time` datetime DEFAULT NULL COMMENT '软删除',
  PRIMARY KEY (`id`),
  KEY `idx_room_time` (`room_id`, `create_time`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息';

-- 消息已读表
CREATE TABLE IF NOT EXISTS `ch_message_reads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `room_id` int NOT NULL,
  `user_id` int NOT NULL,
  `read_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `read_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '已读时间(MessageReadService使用)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_msg_user` (`message_id`, `user_id`),
  KEY `idx_room_user` (`room_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息已读';

-- 页面访问记录表
CREATE TABLE IF NOT EXISTS `ch_access` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip` varchar(50) DEFAULT '',
  `platform` varchar(30) NOT NULL DEFAULT '' COMMENT '平台',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `user_agent` varchar(255) DEFAULT '',
  `page` varchar(100) DEFAULT '',
  `time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='访问记录';

-- 登录日志表
CREATE TABLE IF NOT EXISTS `ch_login_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `ip` varchar(50) DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `remark` varchar(255) DEFAULT '',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='登录日志';

-- 刷新令牌表
CREATE TABLE IF NOT EXISTS `ch_refresh_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `refresh_token` varchar(255) NOT NULL,
  `access_token_id` varchar(255) DEFAULT NULL,
  `ip` varchar(50) DEFAULT '',
  `user_agent` varchar(255) DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1 COMMENT '1有效 0撤销',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_refresh_token` (`refresh_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='刷新令牌';

-- 亲密互动经验表
CREATE TABLE IF NOT EXISTS `ch_intimacy_exp` (
  `id` int NOT NULL AUTO_INCREMENT,
  `room_id` int NOT NULL,
  `user_id` int NOT NULL,
  `partner_id` int NOT NULL,
  `current_exp` int NOT NULL DEFAULT 0,
  `current_level` int NOT NULL DEFAULT 1,
  `total_messages` int NOT NULL DEFAULT 0,
  `last_message_time` datetime DEFAULT NULL,
  `last_interaction_collect` datetime DEFAULT NULL COMMENT '上次互动收集时间',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_room_user_partner` (`room_id`, `user_id`, `partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='亲密互动经验';

-- 亲密度等级表
CREATE TABLE IF NOT EXISTS `ch_intimacy_levels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `level` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `required_exp` int NOT NULL DEFAULT 0,
  `color` varchar(20) DEFAULT '',
  `icon` varchar(50) DEFAULT '',
  `description` varchar(200) DEFAULT '',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='亲密度等级';
