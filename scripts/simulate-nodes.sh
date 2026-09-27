#!/usr/bin/env bash
# 节点在线模拟：写入节点状态缓存（最后拉取 / 上报时间、在线人数），让后台节点列表显示三种运行状态。
#   ./scripts/simulate-nodes.sh              模拟一次上报（与真实节点一样，5 分钟后变回未运行）
#   ./scripts/simulate-nodes.sh --hold=86400 状态保持 24 小时（截图比对时用）
#   ./scripts/simulate-nodes.sh --clear      清除，全部显示为未运行
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T php php /seed/simulate-nodes.php "$@"
