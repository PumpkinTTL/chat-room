-- 好感度等级配置表
CREATE TABLE `ch_intimacy_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `level` int(11) NOT NULL COMMENT '等级',
  `name` varchar(50) NOT NULL COMMENT '等级名称',
  `required_exp` int(11) NOT NULL COMMENT '升级所需经验值',
  `color` varchar(20) DEFAULT '#ec4899' COMMENT '等级颜色',
  `icon` varchar(50) DEFAULT 'fa-heart' COMMENT '等级图标',
  `description` varchar(200) DEFAULT NULL COMMENT '等级描述',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `level` (`level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='好感度等级配置表';

-- 删除旧的等级数据
DELETE FROM `ch_intimacy_levels`;

-- 插入默认等级数据（优化配色，每个等级颜色明显区分）
INSERT INTO `ch_intimacy_levels` (`level`, `name`, `required_exp`, `color`, `icon`, `description`) VALUES
(1, '恋人', 0, '#ec4899', 'fa-heart', '相爱的恋人'),
(2, '热恋', 500, '#f9a916ff', 'fa-heart', '热恋中的情侣'),
(3, '甜蜜', 1500, '#f85712ff', 'fa-heart', '甜蜜的恋人'),
(4, '深爱', 3000, '#ef4444', 'fa-heart', '深深相爱'),
(5, '挚爱', 5000, '#dc2626', 'fa-heart', '挚爱的伴侣'),
(6, '相守', 8000, '#c026d3', 'fa-heart', '相守一生'),
(7, '伴侣', 12000, '#9333ea', 'fa-heart', '生命中的伴侣'),
(8, '灵魂伴侣', 18000, '#7c3aed', 'fa-heart', '灵魂深处的契合');

-- 用户好感度经验表
CREATE TABLE `ch_intimacy_exp` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL COMMENT '房间ID（私密房间）',
  `user_id` int(11) NOT NULL COMMENT '用户ID',
  `partner_id` int(11) NOT NULL COMMENT '伴侣ID',
  `current_exp` int(11) DEFAULT 0 COMMENT '当前经验值',
  `current_level` int(11) DEFAULT 1 COMMENT '当前等级',
  `total_messages` int(11) DEFAULT 0 COMMENT '总消息数',
  `last_message_time` datetime DEFAULT NULL COMMENT '最后发消息时间',
  `create_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `update_time` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_user_partner` (`room_id`, `user_id`, `partner_id`),
  KEY `idx_room_id` (`room_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_partner_id` (`partner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户好感度经验表';