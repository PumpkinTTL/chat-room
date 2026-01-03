# 轻量聊天室 (Lightweight Chat Room)

基于 ThinkPHP 8 + Workerman + Vue 3 构建的实时聊天应用，支持 WebSocket 实时通信。

## 功能特性

- 🚀 **实时通信** - 基于 WebSocket 的实时消息推送，自动降级到轮询模式
- 👥 **多房间支持** - 支持创建和加入多个聊天房间
- 🔐 **用户认证** - Token 认证机制，支持记住登录状态
- 📱 **响应式设计** - 完美适配 PC 和移动端
- 🌙 **深色模式** - 支持明暗主题切换
- ⌨️ **输入状态** - 实时显示"正在输入"提示
- 🔥 **消息焚毁** - 支持删除自己发送的消息
- 🖼️ **图片消息** - 支持发送图片，粘贴上传
- 😊 **表情面板** - 丰富的 Emoji 表情支持
- 📊 **在线状态** - 实时显示房间在线人数

## 技术栈

- **后端框架**: ThinkPHP 8.0
- **WebSocket**: Workerman 4.x
- **前端框架**: Vue 3 (CDN)
- **数据库**: MySQL 5.7+
- **缓存**: Redis
- **运行环境**: PHP 8.0+

## 目录结构

```
├── app/                    # 应用目录
│   ├── controller/         # 控制器
│   ├── model/              # 模型
│   ├── service/            # 服务层
│   └── middleware/         # 中间件
├── config/                 # 配置文件
├── public/                 # 公共资源
│   └── static/             # 静态资源 (CSS/JS)
├── view/                   # 视图模板
├── server.php              # WebSocket 服务器
└── README.md
```

## 安装部署

### 1. 环境要求

- PHP >= 8.0
- MySQL >= 5.7
- Redis
- Composer

### 2. 安装依赖

```bash
composer install
```

### 3. 配置数据库

复制 `.env.example` 为 `.env`，配置数据库连接：

```env
DB_TYPE=mysql
DB_HOST=127.0.0.1
DB_NAME=chat_room
DB_USER=root
DB_PASS=your_password
DB_PORT=3306
```

### 4. 配置 Redis

在 `config/cache.php` 中配置 Redis 连接信息。

### 5. 导入数据库

导入 SQL 文件创建数据表（如有提供）。

### 6. 启动服务

**启动 Web 服务：**

```bash
php think run
```

**启动 WebSocket 服务：**

```bash
php server.php start
```

Windows 用户可使用：
```bash
start_server.bat
```

Linux 用户可使用：
```bash
./start_server.sh
```

### 7. Nginx 配置（生产环境）

```nginx
# WebSocket 代理
location /ws {
    proxy_pass http://127.0.0.1:2346;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_read_timeout 86400;
}
```

## API 接口

### 用户认证
- `POST /api/login` - 用户登录
- `POST /api/register` - 用户注册

### 房间管理
- `GET /api/roomUser/userRooms` - 获取用户房间列表
- `POST /api/roomUser/join` - 加入房间
- `GET /api/roomUser/count/{roomId}` - 获取房间人数

### 消息
- `GET /api/message/list` - 获取消息列表
- `POST /api/message/sendText` - 发送文本消息
- `POST /api/message/sendImage` - 发送图片消息
- `POST /api/message/burn` - 焚毁消息

## WebSocket 协议

### 消息类型

| 类型 | 说明 |
|------|------|
| `auth` | 认证请求 |
| `join_room` | 加入房间 |
| `message` | 发送消息 |
| `typing` | 输入状态 |
| `ping` | 心跳检测 |

### 服务端推送

| 类型 | 说明 |
|------|------|
| `auth_success` | 认证成功 |
| `room_joined` | 加入房间成功 |
| `message` | 新消息 |
| `user_joined` | 用户加入 |
| `user_left` | 用户离开 |
| `typing` | 输入状态 |

## 开源协议

本项目基于 [Apache 2.0](LICENSE.txt) 协议开源。

## 致谢

- [ThinkPHP](https://www.thinkphp.cn/)
- [Workerman](https://www.workerman.net/)
- [Vue.js](https://vuejs.org/)
