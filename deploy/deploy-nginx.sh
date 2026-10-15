#!/bin/bash
# 部署 webchat.bitlesu.com 反代（先 HTTP 版，证书后补）
set -euo pipefail
SRC=/opt/chat-room/deploy/nginx-webchat.conf
DST=/opt/1panel/www/conf.d/webchat.bitlesu.com.conf
OR=1Panel-openresty-FKmK

# 备份已存在的同名配置（若有）
[ -f "$DST" ] && cp "$DST" "$DST.bak-$(date +%Y%m%d-%H%M%S)" && echo "已备份旧配置"

cp "$SRC" "$DST"
echo "已写入 $DST"

# 语法测试
if docker exec "$OR" openresty -t 2>&1; then
  docker exec "$OR" openresty -s reload
  echo "reload 完成"
else
  echo "语法测试失败，回滚"
  rm -f "$DST"
  exit 1
fi
