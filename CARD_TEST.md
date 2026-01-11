# 互动卡片功能测试指南

## 已完成的集成

✅ 后端支持
- Message模型添加TYPE_CARD常量（类型6）
- MessageService添加sendCardMessage方法
- Message控制器添加sendCard方法
- 路由配置完成

✅ 前端集成
- HTML引入interactive-cards.css
- HTML引入interactive-cards.js模块
- 附件面板添加"互动卡片"按钮
- 添加卡片选择器UI
- 消息列表支持渲染卡片消息
- index.js集成卡片模块（仅增加约50行代码）
- WebSocket支持卡片消息广播

## 测试步骤

### 1. 发送卡片

1. 打开聊天界面
2. 点击输入框左侧的 **+** 按钮
3. 在附件面板中点击 **互动卡片** 按钮（紫色渐变图标）
4. 选择一种卡片类型：
   - 🎉 祝福卡片
   - 💕 表白卡片
   - ✅ 打卡卡片
   - 📊 投票卡片
   - 🌤️ 天气卡片
   - 🎵 音乐卡片

### 2. 填写卡片内容

目前需要在浏览器控制台手动测试发送：

```javascript
// 发送祝福卡片
sendCardMessage('blessing', {
    title: '生日快乐',
    message: '愿你的每一天都充满阳光和欢笑！',
    emoji: '🎂'
});

// 发送表白卡片
sendCardMessage('confession', {
    message: '遇见你是我最美好的意外，喜欢你是我做过最勇敢的事。'
});

// 发送打卡卡片
sendCardMessage('checkin', {
    activity: '学习打卡',
    days: 7
});

// 发送投票卡片
sendCardMessage('poll', {
    question: '今天中午吃什么？',
    options: ['火锅', '烧烤', '日料', '西餐'],
    votes: {}
});

// 发送天气卡片
sendCardMessage('weather', {
    city: '北京',
    temp: 25,
    weather: '晴',
    humidity: 45,
    wind: 3,
    visibility: 10
});

// 发送音乐卡片
sendCardMessage('music', {
    title: '晴天',
    artist: '周杰伦',
    cover: 'https://example.com/cover.jpg'
});
```

### 3. 查看效果

- 卡片应该显示在聊天消息列表中
- 卡片有精美的渐变背景和动画效果
- 支持暗色模式
- 其他用户通过WebSocket实时收到卡片消息

## 下一步优化（可选）

1. **添加卡片编辑表单**
   - 点击卡片类型后弹出表单
   - 填写卡片内容
   - 预览卡片效果

2. **投票功能**
   - 点击投票选项可以投票
   - 实时更新投票结果
   - 显示投票人列表

3. **音乐播放**
   - 点击播放按钮播放音乐
   - 显示播放进度

4. **卡片互动**
   - 点赞、评论
   - 转发卡片

## 注意事项

- 卡片数据存储在messages表的extra_data字段（JSON格式）
- 卡片类型存储在message_type字段（值为6）
- 所有卡片样式都在独立的CSS文件中
- 卡片逻辑都在独立的JS模块中
- 不影响现有功能

## 数据库字段

messages表：
- message_type: 6 (卡片消息)
- content: JSON字符串（卡片数据）
- extra_data: JSON对象，包含card_type和card_data

示例：
`