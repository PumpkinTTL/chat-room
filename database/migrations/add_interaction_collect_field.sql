-- 亲密互动好感度领取功能 - 数据库迁移
-- 添加 last_interaction_collect 字段到好感度经验表

-- 添加最后领取互动好感度的时间字段
ALTER TABLE `ch_intimacy_exp`
ADD COLUMN `last_interaction_collect` datetime DEFAULT NULL COMMENT '最后领取亲密互动好感度时间'
AFTER `last_message_time`;
