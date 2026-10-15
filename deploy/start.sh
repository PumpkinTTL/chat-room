#!/bin/sh
# 容器入口：php-fpm（供 OpenResty fastcgi）+ Workerman WebSocket
set -e

# php-fpm 后台启动（监听 9000）
php-fpm -D

# Workerman 常驻前台（容器主进程；退出即容器退出）
exec php /app/server.php start
