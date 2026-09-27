#!/usr/bin/env bash
# 一键启动：准备 .env → 同步源码 → 构建 PHP 镜像 → 前台运行 init（可看到进度）→ 后台启动全部服务
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { cp .env.example .env; echo "已从 .env.example 生成 .env"; }
./scripts/sync-src.sh

docker compose build init
# 前台跑一次 init（依赖的 mysql / redis 会先启动并等待健康），失败时直接退出
docker compose up --exit-code-from init init
docker compose up -d

set -a; . ./.env; set +a
# 公开演示站：启动 demo 服务（每小时复原数据、守护关键配置）
if [ "${DEMO_ENABLE:-0}" = "1" ]; then
  docker compose --profile demo up -d --no-deps demo
fi
SECURE_PATH=$(docker compose exec -T php php -r '$c = include "config/v2board.php"; echo $c["secure_path"] ?? "";')
echo
echo "旧管理端：http://localhost:${HTTP_PORT:-6600}/${SECURE_PATH}"
echo "API     ：http://localhost:${HTTP_PORT:-6600}/api/v1/${SECURE_PATH}/..."
echo "管理员  ：${V2B_ADMIN_EMAIL:-admin@example.com}（初始密码见 .env 的 V2B_ADMIN_PASSWORD）"
