# 图床调用指南（img.bitlesu.com）

> **给 AI 助手的说明**：这是一份可直接执行的自建图床 API 文档。用户把本文档发给你，即表示希望你按此文档帮他上传文件、获取外链。
> 站点 `https://img.bitlesu.com`（自建 CloudFlare-ImgBed；文件存 Telegram 私密频道，元数据存 Cloudflare KV）。
> 文中 `<API_TOKEN>` / `<AUTH_CODE>` 是占位符，真实值见 §2。

---

## 0. 速查表（TL;DR）

| 我想做什么 | 怎么做 |
|---|---|
| 上传一个普通文件 | `POST https://img.bitlesu.com/upload`，multipart 表单字段 `file` |
| 上传大文件（>100MB） | 走三步分块上传，见 §4 |
| 鉴权 | 请求头 `Authorization: Bearer <API_TOKEN>` |
| 保留原图不被压缩 | URL 追加 `?serverCompress=false` |
| 指定存储渠道 | URL 追加 `?channelName=nimbus`（默认渠道不用加） |
| 拿到外链 | 响应 `[{"src":"/file/xxx.png"}]` → 拼成 `https://img.bitlesu.com/file/xxx.png` |
| 外链能否公开访问 | 能，`/file/*` 无需任何鉴权，可直接 `<img>` / 分享 / 下载 |

---

## 1. 基本信息

| 项 | 值 |
|---|---|
| 站点 | `https://img.bitlesu.com` |
| 上传端点 | `POST https://img.bitlesu.com/upload` |
| 请求格式 | `multipart/form-data`，文件字段名固定为 `file` |
| 存储 | Telegram 私密频道（Bot 自动收发） |
| 元数据 | Cloudflare KV |
| 外链访问 | **公开**，无鉴权，支持断点续传（`Accept-Ranges: bytes`） |
| CORS | 全开放（`Access-Control-Allow-Origin: *`），浏览器可跨域直传 |

---

## 2. 凭证

调用上传接口需要凭证，**三选一**（推荐第一种）：

| 方式 | 写法 | 适用场景 |
|---|---|---|
| Bearer Token（推荐） | 请求头 `Authorization: Bearer <API_TOKEN>` | 程序/脚本长期接入 |
| URL 参数 | `POST /upload?authCode=<AUTH_CODE>` | 临时测试 |
| 请求头 | `authCode: <AUTH_CODE>` | 前端页面内嵌 |

**真实凭证的存放位置**（由用户提供，AI 可按需读取）：
- 本机文件：`E:\AppData\UserRoot\.zcode\workspace\default\CloudFlare-ImgBed\.tmp\imgbed-credentials.txt`
- 内容：`AUTH_CODE=...`、`BASIC_USER=...`、`BASIC_PASS=...`、`<API_TOKEN> (website-upload, 仅 upload 权限)`

> API Token 形如 `imgbed_` + 64 位十六进制（共 71 字符）。**若上传稳定返回 401 且带不带 token 响应相同，通常是 token 值错误/失效**，去图床后台「API Token」页重建一个即可。

---

## 3. 上传文件（核心接口）

### 3.1 基本用法

```bash
curl -X POST "https://img.bitlesu.com/upload" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "file=@/path/to/your-file.png"
```

### 3.2 保留原图（重要）

不传 `serverCompress` 参数时，**图片会被 Telegram 服务端压缩重编码**（扩展名/Content-Type 不变，内容已变，例如 70B PNG → 288B JPEG）。

需要字节级保留原图时，URL 追加 `?serverCompress=false`（走 TG 文件通道，SHA256 与源文件一致）。视频/音频/PDF 天然不压缩，无需该参数。

```bash
curl -X POST "https://img.bitlesu.com/upload?serverCompress=false" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "file=@/path/to/image.png"
```

### 3.3 响应格式

成功（HTTP 200）：

```json
[{"src":"/file/1788809685618_test.png"}]
```

- `src` 是相对路径，外链 = `https://img.bitlesu.com` + `src`
- 若后台配置了 `urlPrefix`，响应还会多一个 `publicUrl` 字段（完整直链）

### 3.4 指定存储渠道（可选）

本图床配置了两个 Telegram 渠道：

| 渠道名 | 用途 |
|---|---|
| `Telegram_env` | 默认渠道（不传参数即走它） |
| `nimbus` | 第二应用专属 |

```bash
# 上传到 nimbus 渠道
curl -X POST "https://img.bitlesu.com/upload?channelName=nimbus&serverCompress=false" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "file=@/path/to/file.png"
```

> ⚠️ 渠道名拼错**不会报错**，会静默回退到默认渠道。排查「指定渠道没生效」时先检查拼写。

---

## 4. 大文件分块上传（>100MB 必看）

### 4.1 为什么需要分块

Cloudflare 免费版对**单个请求体**有上限（实测 Content-Length 超约 100MB 直接被边缘拒绝，返回 `413 Payload Too Large`）。因此超过约 100MB 的文件**不能**用 §3 的单请求直传，必须走分块协议。

分块对下载方完全透明：合并后只有一个普通外链，下载时服务端自动按序拼接，无需下载方做任何合并。

### 4.2 三步协议

全部是 `POST /upload`，靠 URL 参数区分阶段：

**第 1 步：初始化会话**

```bash
curl -X POST "https://img.bitlesu.com/upload?initChunked=true" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "originalFileName=my-file.exe" \
  -F "originalFileType=application/octet-stream" \
  -F "totalChunks=10"
```

返回：

```json
{"success":true,"uploadId":"upload_1791784310666_gy77euva0","message":"...","sessionInfo":{...}}
```

记下 `uploadId`（会话有效期 **1 小时**）。

**第 2 步：逐块上传**（对每个分块重复，`chunkIndex` 从 0 开始）

```bash
curl -X POST "https://img.bitlesu.com/upload?chunked=true" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "file=@/path/to/part_00" \
  -F "chunkIndex=0" \
  -F "totalChunks=10" \
  -F "uploadId=upload_1791784310666_gy77euva0" \
  -F "originalFileName=my-file.exe" \
  -F "originalFileType=application/octet-stream"
```

每块成功返回 `{"success":true,"message":"Chunk 1/10 received and being uploaded",...}`。

**第 3 步：合并**

```bash
curl -X POST "https://img.bitlesu.com/upload?chunked=true&merge=true" \
  -H "Authorization: Bearer <API_TOKEN>" \
  -F "uploadId=upload_1791784310666_gy77euva0" \
  -F "totalChunks=10" \
  -F "originalFileName=my-file.exe" \
  -F "originalFileType=application/octet-stream"
```

返回正常上传格式：`[{"src":"/file/1791784705939_my-file.exe"}]`。

### 4.3 分块要点

- **每块必须小于 20MB**（Telegram Bot 单文件限制），实践用 **16MB** 最稳
- 分块大小自定，但同一会话内 `totalChunks` 必须一致
- 块数上限 50（即最大约 800MB，超了报 `File too large`）
- 分块串行上传即可；失败的分块服务端会尝试重试
- 分块失败可用 `POST /upload?cleanup=true&uploadId=xxx&totalChunks=N` 清理会话

### 4.4 实测参考数据（2026-10-12）

| 项 | 数据 |
|---|---|
| 文件 | 154 MB 安装包 |
| 分块 | 10 × 16MB，串行 |
| 上传耗时 | **395 秒（约 6.5 分钟）**，吞吐 ≈ 0.39 MB/s |
| 外链下载 | 首次 43s（回源 TG，约 3.6 MB/s）；缓存命中后 19-24s（6.5-8 MB/s） |
| 完整性 | 下载后 SHA256 与源文件**逐字节一致** |

> 上传慢的瓶颈在「Worker → Telegram」中转（约 0.3-0.4 MB/s），与你的本地带宽无关（本地到 CF 边缘可达 40+ MB/s）。**下载速度取决于 CF 边缘缓存**：首次访问回源较慢，同区域有人下过之后会快很多。

---

## 5. 参数速查

| 参数 | 位置 | 作用 |
|---|---|---|
| `file` | 表单 | 文件本体（必填） |
| `serverCompress=false` | URL | 保留原图，不做 TG 压缩 |
| `channelName=<名>` | URL | 指定存储渠道（`Telegram_env` / `nimbus`） |
| `authCode=<码>` | URL | 鉴权（备选方式） |
| `initChunked=true` | URL | 初始化分块会话 |
| `chunked=true` | URL | 分块上传 / 合并 |
| `merge=true` | URL | 配合 `chunked=true` 触发合并 |
| `cleanup=true` | URL | 清理分块会话 |
| `uploadFolder=<路径>` | URL | 指定存储目录 |
| `returnFormat=full` | URL | 响应返回完整 URL 而非 `/file/xxx` |
| `originalFileName` | 表单 | 分块协议：原始文件名 |
| `originalFileType` | 表单 | 分块协议：MIME 类型 |
| `totalChunks` | 表单 | 分块协议：总块数 |
| `chunkIndex` | 表单 | 分块协议：当前块序号（0 起） |
| `uploadId` | 表单 | 分块协议：会话 ID |

---

## 6. 错误码与排障

| HTTP | 含义 | 处理 |
|---|---|---|
| 200 | 成功 | 取 `src` 拼外链 |
| 401 | 未鉴权 | 检查 token / authCode 是否正确、是否带上 |
| 403 | IP 被封禁 | 后台解封 |
| 404 | **请求姿势不对**（如没用 multipart、直接 POST 裸二进制） | 改用 `-F "file=@..."` 标准表单 |
| 413 | 请求体过大（>约 100MB）或文件超上限 | 大文件改走 §4 分块上传 |
| 500 | 渠道侧错误 JSON | 看响应正文里的具体原因 |

**常见坑**：
1. Windows 上用 git-bash 风格路径（`/c/Users/...`）给 curl 会读不到文件（退出码 26），改用 `C:/Users/...` 或 `C:\Users\...`
2. 不带 `serverCompress=false` 时图片会被压缩，别以为「上传坏了」
3. 直接在 TG 频道里手动删文件会导致外链 404；删文件请用后台管理

---

## 7. 可直接复制给 AI 的指令模板

> 我有自建图床 `https://img.bitlesu.com`，请你按以下方式帮我上传文件并返回外链：
> 1. 小文件（<100MB）：`curl -X POST "https://img.bitlesu.com/upload?serverCompress=false" -H "Authorization: Bearer <API_TOKEN>" -F "file=@<文件路径>"`
> 2. 大文件（>100MB）：走分块协议——先 `?initChunked=true` 初始化拿 uploadId，再 `?chunked=true` 逐块传（每块 16MB，带 chunkIndex/totalChunks/uploadId/originalFileName/originalFileType），最后 `?chunked=true&merge=true` 合并
> 3. 外链 = `https://img.bitlesu.com` + 响应里的 `src`
> 4. 凭证从 `E:\AppData\UserRoot\.zcode\workspace\default\CloudFlare-ImgBed\.tmp\imgbed-credentials.txt` 读取
> 5. 上传完请下载外链并比对 SHA256，确认字节级一致
