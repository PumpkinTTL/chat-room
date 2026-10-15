#!/bin/bash
set -e
DBP=$(grep '^DB_PASS' /opt/chat-room/.env | cut -d= -f2)
ME="docker exec -i -e MYSQL_PWD=$DBP 1Panel-mariadb-db78 mariadb -uchat_room chat_room"

echo "=== 补齐 1-8 级 ==="
$ME < /opt/chat-room/database/migrations/seed_intimacy_levels_base.sql

echo "=== 结果 ==="
docker exec -e MYSQL_PWD="$DBP" 1Panel-mariadb-db78 mariadb -uchat_room chat_room -e "SELECT level,name,required_exp FROM ch_intimacy_levels ORDER BY level;" 2>&1
