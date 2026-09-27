#!/usr/bin/env bash
# 重置数据：删除容器和数据卷（MySQL / Redis），重新导入 install.sql 并写入演示数据。
# src/ 下的 vendor、.env、config/v2board.php 会保留（后台路径与 APP_KEY 不变）。
set -euo pipefail
cd "$(dirname "$0")/.."
read -r -p "将删除 MySQL / Redis 数据卷并重建，确认？[y/N] " ans
[ "${ans:-N}" = "y" ] || [ "${ans:-N}" = "Y" ] || { echo "已取消"; exit 0; }
docker compose --profile cron down -v
./scripts/up.sh
