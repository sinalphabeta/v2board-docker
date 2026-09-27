#!/usr/bin/env bash
# 在开发环境数据库上执行一条 SQL（表名带 v2_ 前缀）。查询输出 JSON 数组，其余语句输出受影响的行数：
#   ./scripts/sql.sh "SELECT id, email FROM v2_user LIMIT 3"
#   ./scripts/sql.sh "UPDATE v2_ticket SET status = 0 WHERE id = 5"
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T php php /seed/sql.php "$1"
