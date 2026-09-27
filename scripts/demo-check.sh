#!/usr/bin/env bash
# 演示数据的一致性自检（seed/demo/check.php）：id 与注册先后、邀请与下单的先后、佣金、已用流量与流量统计、每日统计等。
# 复原时会自动运行；这里单独检查当前的数据（例如访客改过之后）。
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec -T php php -d memory_limit=1G /seed/demo/check.php
