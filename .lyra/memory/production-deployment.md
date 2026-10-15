---
name: 生产部署（webchat.bitlesu.com）
description: 聊天室 chat-room 生产环境已部署到 US Main：架构、路径、更新流程、已知问题与硬约束
---

## 事实

**状态：2026-10-15 已部署上线并全链路验证通过。**

- **生产域名** `webchat.bitlesu.com`（HTTPS，Let's Encrypt 证书，2027-01-12 到期，`acme-renew.timer` 每天 04:30 自动续期）。
- **目标服务器** US Main `38.76.190.158`。连通/连接方式见全局记忆 [服务器基建](servers-infra.md)；DNS 权限见 [Cloudflare DNS](cloudflare-dns-access.md)。
- **部署形态（真机 Docker，非内网穿透）**：单容器 `chat-room`（PHP 8.2-FPM + Workerman `server.php`），复用共享资源：
  - 共享 MariaDB 容器 `1Panel-mariadb-db78` → 独立库 `chat_room` + 专用账号（仅授权 `chat_room.*`）
  - 共享 Redis 容器 `1Panel-redis-jVt3` → `REDIS_SELECT=5` + `REDIS_PREFIX=chat:`
  - 既有 OpenResty `1Panel-openresty-FKmK` 反代（静态前端直出 / `/api`·`/storage` 走 fastcgi / `/ws` 反代 WS）
- **端口**：容器仅绑 `127.0.0.1:9017`（fpm）与 `127.0.0.1:2346`（WS），对外经 OpenResty。
- **路径**：应用 `/opt/chat-room/`；生产 `.env` `/opt/chat-room/.env`（**640 root:www-data**）；前端站点 `/opt/1panel/www/sites/webchat.bitlesu.com/index/`；反代 `/opt/1panel/www/conf.d/webchat.bitlesu.com.conf`；部署资产与说明 `/opt/chat-room/deploy/`（含 `README.md`）。
- **前端**：旧版（`view/`+`static/js`）已淘汰，**上线新版 `vue-webchat`**；构建用 `pnpm build:prod`（`pnpm build` 带 vue-tsc 会因既存类型错误失败）。
- **环境变量分离**：`SERVER_PORT` 全项目零引用（已废弃）；前端靠 Vite `.env`（本地）/`.env.production`（生产构建）区分；后端 `.env` 分本地/生产两份（`.env.example` 为模板）。

## Why
- 用户明确要求：服务器上真机部署（不再走内网穿透，穿透只是本地开发方式），环境变量区分两地；能复用的服务器资源必须复用（共享 DB/Redis/反代），不得重复造、不得弄乱服务器（禁止破坏性/删库操作，不碰其他库数据）。
- 前端旧版已淘汰，必须用 Vue 版构建上线。

## How to apply
- **更新部署**：本地 `pnpm build:prod` → 打包源码（排除 `.env`/`runtime`/`node_modules`/`vue-webchat`）与 dist → 上传 → `cd /opt/chat-room && docker compose -f deploy/docker-compose.yml up -d --build` → 前端 dist 覆盖站点 index 目录 → 验证。
- **关键坑**：`.env` 权限必须让 `www-data` 可读（640 root:www-data）；给过 `600 root` 会导致 FPM 读不到 env → 回退默认 DB → `Connection refused`（本次已踩并修复）。
- **验证**：`curl -sI https://webchat.bitlesu.com/`（200）、`POST /api/user/login`（业务 JSON）、`docker logs chat-room`。
- **已知既存缺陷（非部署引入，未擅自改）**：`/api/v2/auth/login` 返回 401「缺少token」——全局 `TokenAuth` 中间件未把该路径加入跳过列表；`Message::notifyReadStatus()` 未实现。
- 详细部署说明见 `deploy/README.md`。

## 2026-10-15 缺陷修复批次（已上线）

用子代理并行做了「头像/UI 审计」与「音频播放」，主线程修复核心链路。关键成果：

- **WS 健壮性（核心）**：`store/websocket.ts` 重写连接生命周期（指数退避重连 + pong watchdog + visibilitychange/online 恢复 + 认证后自动重新 join 房间）；`server.php` 增加僵尸连接清理（>75s 无活动关闭）。这是「上线特效/私密爱心/私密标签失效」的根因修复。
- **文件消息**：补齐本地占位 + 进度（此前静默消失需刷新）；图片/视频/文件统一换真实 id + `realId`（修复焚毁/引用 NaN 失败）。
- **引用消息**：广播预览用 `getReplyPreviewText`（图片显 `[图片]`）；跳转自动加载历史；消除「晃动后缩放」双重动画。
- **头像上传**：前端此前**完全缺失**，已补齐入口+API+回写+响应式（`UserCard` 改从 store 读）。
- **音频在线播放**：全链路新增（后端 `TYPE_AUDIO=6`/`uploadAudio`/`sendAudio`，前端渲染 `<audio controls>`）。
- **数据修复**：生产库 `ch_intimacy_levels` 缺 1–8 级 → 经验报 `array offset on null`；用 `database/migrations/seed_intimacy_levels_base.sql` 补齐（共 15 级）。**重装必跑**。
- **其它**：文件可下载、修 `messageRefs` 未定义、`handleRefresh` 重载消息、Sidebar 自动刷新真实化。

> 审计遗留的未做项（低优先）：联系人 `contactList` 仍是假数据、录音功能空实现、视频缩略图后端不生成（走原生 video）、仓库含 3 个重复登录页。
