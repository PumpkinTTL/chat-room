# 好感度系统安装说明

## 1. 执行SQL创建表

请在数据库中执行以下SQL文件：

```bash
mysql -u your_username -p your_database < database/intimacy_system.sql
```

或者直接在phpMyAdmin/Navicat等工具中执行 `database/intimacy_system.sql` 文件内容。

## 2. 验证表是否创建成功

执行以下SQL检查：

```sql
SHOW TABLES LIKE 'ch_intimacy%';
```

应该看到两个表：
- `ch_intimacy_levels` - 等级配置表
- `ch_intimacy_exp` - 用户经验表

## 3. 检查等级数据

```sql
SELECT * FROM ch_intimacy_levels ORDER BY level;
```

应该看到8个等级：恋人、热恋、甜蜜、深爱、挚爱、相守、伴侣、灵魂伴侣

## 4. 测试API

访问：`http://your_domain/api/intimacy/levels`

应该返回等级列表。

## 5. 使用说明

- 只有 `private=1` 的私密房间才有好感度系统
- 私密房间必须恰好有2个用户
- 发送消息自动增加经验值（1分钟冷却）
- 不同消息类型获得不同经验：
  - 文本消息：2经验
  - 图片消息：3经验
  - 视频消息：4经验
  - 文件消息：3经验
  - 引用回复：3经验

## 6. 等级经验值

| 等级 | 名称 | 所需经验 |
|------|------|----------|
| 1 | 恋人 | 0 |
| 2 | 热恋 | 500 |
| 3 | 甜蜜 | 1500 |
| 4 | 深爱 | 3000 |
| 5 | 挚爱 | 5000 |
| 6 | 相守 | 8000 |
| 7 | 伴侣 | 12000 |
| 8 | 灵魂伴侣 | 18000 |
