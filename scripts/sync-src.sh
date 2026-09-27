#!/usr/bin/env bash
# 把 V2board 源码同步到 ./src（绝不写入源码仓库或目录）。默认是 wyx2685 版：
#   ./scripts/sync-src.sh                                      # GitHub 上 wyx2685/v2board 的 master
#   V2BOARD_REF=<分支、标签或提交> ./scripts/sync-src.sh          # 同一仓库的其他版本
#   V2BOARD_REPO=<git 地址或本地仓库> V2BOARD_REF=<...> ...       # 换一个仓库（例如自己的 fork）
#   V2BOARD_DIR=../V2board ./scripts/sync-src.sh                 # 直接同步本地目录的工作区（含未提交的修改）
# git 方式只浅拉这一个提交到 .cache/v2board，再同步到 src；src/.v2board-source 记录来源与提交。
# 被排除的运行时文件（vendor、.env、config/v2board.php、日志等）在 src 中会保留，不会被 --delete 删除。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p src

# .env 里的 V2BOARD_REPO / V2BOARD_REF / V2BOARD_DIR（命令行传入的优先；只读这几项，不 source 整个 .env）
if [ -f .env ]; then
  while IFS='=' read -r key value; do
    if [ -z "${!key:-}" ] && [ -n "$value" ]; then export "$key=$value"; fi
  done < <(grep -E '^V2BOARD_(REPO|REF|DIR)=' .env || true)
fi

if [ -n "${V2BOARD_DIR:-}" ]; then
  if [ ! -f "$V2BOARD_DIR/artisan" ]; then
    echo "找不到 $V2BOARD_DIR/artisan，请检查 V2BOARD_DIR" >&2
    exit 1
  fi
  rsync -a --delete --exclude-from=scripts/sync.exclude "$V2BOARD_DIR"/ src/
  echo "dir $(cd "$V2BOARD_DIR" && pwd)" > src/.v2board-source
  echo "已同步 $V2BOARD_DIR → src/"
  exit 0
fi

REPO="${V2BOARD_REPO:-https://github.com/wyx2685/v2board.git}"
REF="${V2BOARD_REF:-master}"
# 本地仓库转成绝对路径（下面的 git 命令在缓存目录里执行）
if [ -d "$REPO" ]; then REPO="$(cd "$REPO" && pwd)"; fi
CACHE=.cache/v2board
[ -d "$CACHE/.git" ] || git init -q "$CACHE"
git -C "$CACHE" fetch --depth 1 --no-tags "$REPO" "$REF"
git -C "$CACHE" checkout -q --force --detach FETCH_HEAD
git -C "$CACHE" clean -qfdx
if [ ! -f "$CACHE/artisan" ]; then
  echo "$REPO 的 $REF 里没有 artisan，不像 V2board 源码" >&2
  exit 1
fi
rsync -a --delete --exclude-from=scripts/sync.exclude "$CACHE"/ src/
printf '%s %s %s\n' "$REPO" "$REF" "$(git -C "$CACHE" rev-parse HEAD)" > src/.v2board-source
echo "已同步 ${REPO} ${REF}（$(git -C "$CACHE" log -1 --format='%h %ci %s')）→ src/"
