# 生产部署说明（webchat.bitlesu.com）

> 目标服务器：US Main `38.76.190.158`（Debian 13，1Panel + Docker + OpenResty）
> 部署形态：Docker 单容器（PHP 8.2-FPM + Workerman），复用共享 MariaDB / Redis 与既有 OpenResty 反代。
> 密钥/密码一律**不入库、不写入本文件**；生产 `.env` 在服务器 `/opt/chat-room/.env`（权限 640 root:www-data）。

## 架构

```
浏览器 ──https──> OpenResty(1Panel-openresty-FKmK)
                    ├─ /             → 静态前端（/www/sites/webchat.bitlesu.com/index，Vue 构建产物）
                    ├─ /api, /storage → php-fpm 127.0.0.1:9017（Phinx 入口 → ThinkPHP）
                    └─ /ws           → 127.0.0.1:2346（Workerman WebSocket）
容器 chat-room：php-fpm(9000) + server.php(2346)
    ├─ DB  → 共享 MariaDB 容器 1Panel-mariadb-db78（独立库 chat_room）
    └─ Redis→ 共享 Redis 容器 1Panel-redis-jVt3（独立 DB index + 前缀 chat:）
```

## 关键路径

| 项 | 路径 |
|---|---|
| 应用代码 | `/opt/chat-room/` |
| 生产 .env | `/opt/chat-room/.env`（640 root:www-data） |
| 部署资产 | `/opt/chat-room/deploy/` |
| 前端站点 | `/opt/1panel/www/sites/webchat.bitlesu.com/index/` |
| 站点 SSL | `/opt/1panel/www/sites/webchat.bitlesu.com/ssl/` |
| Nginx 配置 | `/opt/1panel/www/conf.d/webchat.bitlesu.com.conf` |
| 容器 | `chat-room`（127.0.0.1:9017→9000，127.0.0.1:2346→2346） |

## 部署 / 更新流程

```bash
# 1) 本地构建前端（生产配置自动用 vue-webchat/.env.production）
cd vue-webchat && pnpm build:prod        # 注意：pnpm build 带 vue-tsc 会因既有类型错误失败，用 build:prod

# 2) 上传源码与前端
#    源码打包（含 vendor，排除 .env / runtime / node_modules / vue-webchat）
#    前端：vue-webchat/dist → /opt/1panel/www/sites/webchat.bitlesu.com/index/

# 3) 重建并重启容器
cd /opt/chat-room && docker compose -f deploy/docker-compose.yml up -d --build

# 4) 反代（配置有改动时）
bash /opt/chat-room/deploy/deploy-nginx.sh
```

## 首次初始化（已执行，勿重复）

1. 建独立库 + 专用账号（只授权 `chat_room.*`），导入 `database/init_local.sql`（库名 `chats`→`chat_room`）+ migrations。
2. 生成生产 `.env`（DB/Redis 指向 `mariadb`/`redis` 容器别名；`JWT_SECRET` 服务器端随机生成）。
3. DNS：`webchat.bitlesu.com` A → `38.76.190.158`（灰云直连）。
4. 证书：`acme.sh --issue --server letsencrypt --dns dns_cf -d webchat.bitlesu.com --keylength ec-256`，`--install-cert` 到站点 ssl 目录；自动续期走既有 `acme-renew.timer`（每天 04:30）。

## 验证命令

```bash
curl -sI https://webchat.bitlesu.com/                          # 200
curl -s -X POST -H 'Content-Type: application/json' \
  -d '{"username":"<id>","password":"<pwd>"}' \
  https://webchat.bitlesu.com/api/user/login                   # 业务 JSON
docker logs chat-room | tail                                   # fpm + Workerman 运行日志
```

## 注意事项 / 已知问题

- **`.env` 权限**：必须 `640 root:www-data`（或 644）。曾因 `600 root` 导致 www-data 读不到 → FPM 回退默认 DB 地址 → `SQLSTATE Connection refused`。
- **前端构建**：用 `pnpm build:prod`（跳过 `vue-tsc`）；仓库存在大量既存 TS 类型错误（接口声明滞后），不影响产物。
- **共享实例隔离**：DB 用独立库 `chat_room`，Redis 用 `REDIS_SELECT=5` + `REDIS_PREFIX=chat:`，不会影响 nimbus/casdoor。
- **服务只绑 127.0.0.1**，对外一律经 OpenResty；勿改成 0.0.0.0。
- **v2 JWT 登录**（`/api/v2/auth/login`）返回 401「缺少token」——是项目既有中间件问题（全局 TokenAuth 未把该路径加入跳过列表），前端实际走 v1（`/api/user/login`）。属既存缺陷，非部署引入。
- 服务器无 swap（内存 7.8G），Workerman 进程数不宜调大（当前 1）。
- **好感度等级基础数据**：生产库曾缺 1–8 级（只导入了 9–15），导致送礼/发消息时报 `Trying to access array offset on value of type null`。已用 `database/migrations/seed_intimacy_levels_base.sql` 幂等补齐，共 15 级。重装时务必执行。

## 2026-10-15 缺陷修复批次（已上线）

修复了大量既存缺陷（前端 `vue-webchat` + 后端），要点：

1. **WebSocket 连接健壮性**（核心）：`store/websocket.ts` 重写连接生命周期 —— 指数退避重连、pong 心跳 watchdog（25s ping / 10s 超时判死）、`visibilitychange`+`online` 前台/网络恢复探活重连、认证成功后**自动重新 join 房间**（`desiredRoomId`），解决「对方上线特效不触发 / 私密爱心不点亮」。
2. **后端僵尸连接清理**：`server.php` 记录 `last_active`，每 30s 清理 >75s 无活动的连接，避免后台/挂起客户端留下幽灵在线。
3. **重复 user_joined**：同一连接重复 join 同房间不再重复广播。
4. **文件消息**：`handleSendFile` 补齐本地占位 + 上传进度（此前静默消失、需刷新）。
5. **消息 id 统一**：图片/视频/文件成功后换真实 id 并设 `realId`（此前 temp id 导致焚毁/引用 `Number(temp)` = NaN 失败）；`store/chat.ts` 去重同时认 `id`/`realId`。
6. **引用消息**：广播的引用预览改用 `getReplyPreviewText`（图片显示 `[图片]` 而非 URL）；点击引用跳转时若目标未加载会自动加载历史（最多 5 轮）；跳转高亮前先移除入场动画 class，消除「晃动后又缩放」。
7. **头像上传**：前端此前完全缺失 —— 新增 `apis/auth.uploadAvatar`、`UserCard` 点击换头像入口 + 上传中遮罩、`updateUserInfo` 回写；`UserCard` 改为从 store 响应式读取（此前直读 localStorage，改头像要刷新才变），并修正头像 URL 拼接（`/storage` 前缀不再产生 `//`）。
8. **音频在线播放**：全链路新增音频消息类型（后端 `TYPE_AUDIO=6`、`uploadAudio`、`sendAudio`、路由、WS 广播；前端 API/上传/渲染 `<audio controls>`/引用预览 `[音频]`）。
9. **文件可下载**：文件消息卡片可点击下载/打开。
10. **杂项**：`MessageList` 修复未定义的 `messageRefs`；`handleRefresh` 同时重载当前房间消息；Sidebar「自动刷新」从假实现改为真实 15s 轮询；移除 `store/chat.ts` 重复的视频处理块。
