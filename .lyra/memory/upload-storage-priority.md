---
name: 上传存储优先级（图床/本地）
description: 聊天室文件上传的存储策略：图床 CloudFlare-ImgBed 与本地 public/storage 的优先/降级规则与开关
---

## 事实
- 上传入口：`app/service/UploadService.php`（uploadImage/uploadVideo/uploadDocument/uploadFile）+ `app/service/UserService::uploadAvatar()`。
- 图床客户端：`app/service/ImgbedService.php`，自建 CloudFlare-ImgBed，基址 `https://img.bitlesu.com`，接口 `POST {base}/upload?serverCompress=false`（Bearer Token）。**大文件无 20MB 硬限**：2026-10-12 实测 21/25/28MB 均上传成功且 SHA256 一致（该版本 chunkUpload 自动分块绕过 TG Bot 单文件限制）；代价是上传吞吐仅 ~0.3-0.4 MB/s，25MB 约需 70-80s。下载走 CF 边缘 ~2-3 MB/s 且波动大。
- **CF 免费版单请求上限（2026-10-12 实测）**：请求体 Content-Length 超限（约 100MB）直接被 CF 边缘 413，154MB 安装包整包 POST 在 ~24MB 处被拒。**聊天室 ImgbedService 是单请求上传，>100MB 文件会 413**；更大文件需走图床 chunked 分块 API（全部 `POST /upload` 用 URL 参数分发：`?initChunked=true` 建 session 拿 uploadId → `?chunked=true` 逐块传 `file`+`chunkIndex`(0起)+`totalChunks`+`uploadId`+`originalFileName`+`originalFileType`，每块 <20MB（惯例 16MB）→ `?chunked=true&merge=true` 合并返回 src；session 1 小时有效）。154MB 实测：10 块、395s（0.39 MB/s）、SHA256 一致，外链下载 43s（~3.6 MB/s）。
- 优先级开关（`.env`）：全局 `UPLOAD_PRIORITY=imgbed|local`，可按类型覆盖 `UPLOAD_PRIORITY_IMAGE/VIDEO/DOCUMENT/FILE/AVATAR`。默认全部 `imgbed`（图床优先）。
- 语义：`imgbed`=只传图床；`local`=只存本地。**二选一，不做自动降级**；选中侧失败时直接返回错误提示（图床失败带原因，如「图床上传失败：图床连接失败」），不再回落到另一侧。
- 本地存储：ThinkPHP `public` 磁盘，根目录 `public/storage`，URL 前缀 `/storage`；走图床成功则本地不落文件。
- 上传走**流式**（`ImgbedService::uploadFile()` 直接传文件路径，`CURLFile`），不把 200MB 视频读进内存。超时用 `IMGBED_TIMEOUT`（默认 300s）。
- 返回数据结构新增 `storage`（`imgbed`/`local`）与 `path`（local 时为磁盘相对路径；imgbed 时为空）。
- 删除：`UploadService::deleteFile()` 对 `http(s)://` 外链直接跳过，**图床文件不会被删除逻辑清理**；当 `imgbed` 优先且成功时 `path` 为空，`MessageService` 删除房间文件也只清理本地。
- **Token 有效性坑（2026-10-11 踩过并修复）**：`.env` 的 `IMGBED_TOKEN` 与图床真值必须一致。曾配成 55 字符的失效值 → 上传稳定 401 `Unauthorized`（带/不带 token 响应相同即为此症状）。正确 token 为 `imgbed_` + 64 位 hex（共 71 字符），来自图床 repo `.tmp/imgbed-credentials.txt`（website-upload，仅 upload 权限）。图床服务本身健在：`GET /api/channels?authCode=…` 200、外链 `/file/*` 公开 200。
- 前端（vue-webchat 与 public/static/js）已按 `startsWith('http')` 区分，绝对外链与 `/storage` 相对路径都能渲染，无需改动。
- **图床渠道**：两个 TG 渠道 `Telegram_env`（默认）与 `nimbus`；聊天室上传不指定渠道，走默认 `Telegram_env`。指定渠道用法（URL 查询参数 `?channelName=`，大小写敏感，拼错静默回退）见全局记忆「图床维护」。**面向 AI 的完整调用文档**：`docs/imgbed-api.md`（本项目内，自包含，可直接发给任意 AI 执行上传/取外链）。

## Why
- 本地磁盘空间不足，故默认全部图床优先，并保留切回本地优先的开关以应对图床不可用/成本变化。
- 明确不做自动降级：避免「以为存图床、实际落本地」或反之的隐蔽行为；失败要让用户看到明确原因。

## How to apply
- 新增上传类型时：在 `ImgbedService` 加 `TYPE_*` 常量，调用 `UploadService::store($file, $type, $dir, $ext, $mime)`（或头像按 `UserService` 的模式），并返回 `storage`/`path` 字段。
- 调优先级：改 `.env` 的 `UPLOAD_PRIORITY*`，不要改代码。
- 图床大文件：需自行调大图床侧上限；`IMGBED_TIMEOUT` 相应调大。
- 验证：`php -l` 三个 service；上传后看返回 `storage` 字段判断走了哪条通道。
