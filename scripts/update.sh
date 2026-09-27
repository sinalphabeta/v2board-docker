#!/usr/bin/env bash
# 一键更新（流程与 wyx2685 自己的 update.sh 相同：拉代码 → 删 lock 重新装依赖 → php artisan v2board:update）：
#   1. 更新本仓库（git pull，拿到新的 docker 配置与演示数据生成器）
#   2. 备份数据库到 backups/（保留最近 5 份）
#   3. 同步 wyx2685 的最新 master（.env 里写 V2BOARD_REF 可以固定到某个分支 / 标签 / 提交）
#   4. 重建 PHP 镜像（docker 配置没变时直接用缓存）
#   5. 删掉 composer.lock，按新的 composer.json 重新解析依赖（新增、去掉的依赖都会跟着变）
#   6. php artisan v2board:update：执行 database/update.sql 里的数据库迁移，并重启队列
#   7. 重启服务；公开演示站（DEMO_ENABLE=1）重启 demo 服务，它启动时按新版本复原一次演示数据，这里等它完成并检查结果
#   8. 打印升级前后的 wyx2685 提交与 GitHub 上的对比链接
# 用法：./scripts/update.sh [--no-pull]（--no-pull：不更新本仓库，例如本机正在改生成器时）
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] || { echo "没有 .env：先 cp .env.example .env 并修改，再运行 ./scripts/up.sh" >&2; exit 1; }

PULL=1
for arg in "$@"; do
  case "$arg" in
    --no-pull) PULL=0 ;;
    *) echo "未知参数：$arg" >&2; exit 1 ;;
  esac
done

step() { echo; echo "== $*"; }

if [ "$PULL" = "1" ] && [ -d .git ]; then
  step "更新本仓库"
  git pull --ff-only
fi
set -a; . ./.env; set +a

OLD=$(awk '{print $3}' src/.v2board-source 2>/dev/null || true)

step "备份数据库"
mkdir -p backups
BACKUP="backups/db-$(date +%Y%m%d-%H%M%S).sql"
docker compose exec -T mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE" 2>/dev/null' > "$BACKUP" < /dev/null
echo "已备份：$BACKUP"
ls -1t backups/db-*.sql | tail -n +6 | while read -r old; do rm -f "$old"; done

step "同步 wyx2685 的源码"
./scripts/sync-src.sh
NEW=$(awk '{print $3}' src/.v2board-source)
REPO=$(awk '{print $1}' src/.v2board-source)

step "重建 PHP 镜像"
docker compose build init

step "重新解析依赖、初始化"
rm -f src/composer.lock src/composer.docker.lock
touch src/composer.json
docker compose up --exit-code-from init init

step "数据库迁移（v2board:update）"
docker compose up -d
docker compose exec -T php php artisan v2board:update < /dev/null

step "重启服务"
docker compose restart php horizon nginx
if [ "${DEMO_ENABLE:-0}" = "1" ]; then
  SINCE=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  docker compose --profile demo up -d --no-deps --force-recreate demo
  echo "等待演示数据复原……"
  RESULT=""
  for _ in $(seq 1 90); do
    RESULT=$(docker compose logs --since "$SINCE" --no-log-prefix demo 2>/dev/null | grep -E "复原完成|复原失败" | tail -1 || true)
    [ -n "$RESULT" ] && break
    sleep 2
  done
  echo "${RESULT:-没有等到复原结果，用 docker compose logs demo 查看}"
  case "$RESULT" in *复原失败*|"") exit 1 ;; esac
fi

step "完成"
echo "wyx2685：${OLD:-（无记录）} → $NEW"
if [ -n "$OLD" ] && [ "$OLD" != "$NEW" ] && [[ "$REPO" == https://github.com/* ]]; then
  echo "改动：${REPO%.git}/compare/${OLD:0:10}...${NEW:0:10}"
fi
