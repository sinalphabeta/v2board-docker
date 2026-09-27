#!/usr/bin/env bash
# 按当前时间重新生成演示数据（seed/demo/reset.php）：用户、订单、佣金、流量、工单、每日统计全部按真实流程生成，
# 在一个事务里整体替换，自检不通过时回滚、保留原来的数据。已登录的令牌仍然有效。
#   ./scripts/demo-reset.sh              本机：只换数据，不动系统配置（你自己改的主题色等保持不变）
#   ./scripts/demo-reset.sh --config     同时把系统配置、主题配置写回演示站的模板
#   ./scripts/demo-reset.sh --dry-run    只试生成、打印统计，不写库
#   ./scripts/demo-reset.sh --now="2026-10-01 00:05"   按指定时间生成（测试用）
# 公开演示站不用手动执行：demo 服务每小时整点自动复原（DEMO_ENABLE=1，会同时写回配置）。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; [ -f .env ] && . ./.env; set +a
docker compose exec -T \
  -e DEMO_DAYS="${DEMO_DAYS:-120}" \
  -e V2B_SECURE_PATH="${V2B_SECURE_PATH:-devadmin123}" \
  -e V2B_APP_NAME="${V2B_APP_NAME:-V2Board}" \
  -e V2B_APP_URL="${V2B_APP_URL:-http://localhost:6600}" \
  -e V2B_ADMIN_EMAIL="${V2B_ADMIN_EMAIL:-admin@example.com}" \
  -e V2B_ADMIN_PASSWORD="${V2B_ADMIN_PASSWORD:-admin123456}" \
  php php -d memory_limit=1G /seed/demo/reset.php "$@"
