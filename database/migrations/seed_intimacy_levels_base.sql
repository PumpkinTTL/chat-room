-- 基础好感度等级（1-8）：生产库缺失导致经验系统报错，幂等补齐
-- 与 database/intimacy_system.sql 中 1-8 级一致
INSERT INTO `ch_intimacy_levels` (`level`, `name`, `required_exp`, `color`, `icon`, `description`) VALUES
(1, '恋人', 0, '#ec4899', 'fa-heart', '相爱的恋人'),
(2, '热恋', 500, '#f9a916', 'fa-heart', '热恋中的情侣'),
(3, '甜蜜', 1500, '#f85712', 'fa-heart', '甜蜜的恋人'),
(4, '深爱', 3000, '#ef4444', 'fa-heart', '深深相爱'),
(5, '挚爱', 5000, '#dc2626', 'fa-heart', '挚爱的伴侣'),
(6, '相守', 8000, '#c026d3', 'fa-heart', '相守一生'),
(7, '伴侣', 12000, '#9333ea', 'fa-heart', '生命中的伴侣'),
(8, '灵魂伴侣', 18000, '#7c3aed', 'fa-heart', '灵魂深处的契合')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `required_exp` = VALUES(`required_exp`),
  `color` = VALUES(`color`),
  `icon` = VALUES(`icon`),
  `description` = VALUES(`description`);
