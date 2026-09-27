#!/usr/bin/env bash
# 以"前后端分离"的方式检查后端：预检、登录、管理接口、Horizon、CSV 导出的 CORS 头。
# 用法：./scripts/check-cors.sh [前端 Origin，默认 http://localhost:5173]
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

BASE="http://localhost:${HTTP_PORT:-6600}"
ORIGIN="${1:-http://localhost:5173}"
SP=$(docker compose exec -T php php -r '$c = include "config/v2board.php"; echo $c["secure_path"] ?? "";')
json() { node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);console.log(eval("j"+process.argv[1]))})' "$1"; }
hdr() { grep -iE '^(HTTP/|access-control-allow-(origin|headers|methods|credentials)|content-type)' | tr -d '\r' | sed 's/^/    /'; }

echo "后端 $BASE  secure_path=$SP  Origin=$ORIGIN"

echo "1) OPTIONS 预检 /api/v1/$SP/stat/getOverride"
curl -s -o /dev/null -D - -X OPTIONS "$BASE/api/v1/$SP/stat/getOverride" \
  -H "Origin: $ORIGIN" -H "Access-Control-Request-Method: GET" \
  -H "Access-Control-Request-Headers: authorization" | hdr

echo "2) 登录 /api/v1/passport/auth/login"
LOGIN=$(curl -s -X POST "$BASE/api/v1/passport/auth/login" -H "Origin: $ORIGIN" \
  --data-urlencode "email=${V2B_ADMIN_EMAIL}" --data-urlencode "password=${V2B_ADMIN_PASSWORD}")
AUTH=$(printf '%s' "$LOGIN" | json '.data.auth_data')
IS_ADMIN=$(printf '%s' "$LOGIN" | json '.data.is_admin')
echo "    is_admin=$IS_ADMIN auth_data=${AUTH:0:24}..."

echo "3) 管理接口 GET /api/v1/$SP/stat/getOverride"
curl -s -D - -o /dev/null "$BASE/api/v1/$SP/stat/getOverride" -H "Origin: $ORIGIN" -H "authorization: $AUTH" | hdr

echo "4) Horizon GET /monitor/api/stats"
STATUS=$(curl -s "$BASE/monitor/api/stats" -H "Origin: $ORIGIN" -H "authorization: $AUTH" | json '.status')
echo "    status=$STATUS"

echo "5) CSV 导出 POST /api/v1/$SP/user/dumpCSV（全部用户，通常 >4KB）"
OUT=$(curl -s -D - -o /dev/null -w 'BODY_SIZE=%{size_download}' -X POST "$BASE/api/v1/$SP/user/dumpCSV" \
  -H "Origin: $ORIGIN" -H "authorization: $AUTH")
printf '%s\n' "$OUT" | hdr
printf '%s\n' "$OUT" | grep -o 'BODY_SIZE=[0-9]*' | sed 's/BODY_SIZE=/    body=/; s/$/ bytes/'
echo "    （若上面没有 access-control-allow-origin，说明 CSV 超过 PHP output_buffering，跨域导出会被浏览器拦截）"
