#!/usr/bin/env bash
# 在 php 容器里执行 artisan，例如：./scripts/artisan.sh config:cache
set -euo pipefail
cd "$(dirname "$0")/.."
docker compose exec php php artisan "$@"
