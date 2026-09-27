#!/usr/bin/env bash
# v2b-demo 初始化（init 容器每次启动时运行，幂等）：
#   src/.env → composer install → APP_KEY → config/v2board.php → config:cache → 管理员 → 演示数据 → 权限
set -euo pipefail
cd /var/www/html

log() { echo "[v2b-init] $*"; }

if [ ! -f artisan ]; then
  log "未找到 src/artisan，请先在宿主机运行 scripts/sync-src.sh"
  exit 1
fi

# 设置 .env 中的 KEY=VALUE（存在则替换，不存在则追加）
set_env() {
  php -r '
    [$f, $k, $v] = [".env", $argv[1], $argv[2]];
    $c = file_get_contents($f);
    $re = "/^" . preg_quote($k, "/") . "=.*$/m";
    if (preg_match($re, $c)) {
        $c = preg_replace_callback($re, function () use ($k, $v) { return $k . "=" . $v; }, $c);
    } else {
        $c = rtrim($c, "\n") . "\n" . $k . "=" . $v . "\n";
    }
    file_put_contents($f, $c);
  ' "$1" "$2"
}

# 1. src/.env（只在不存在时生成；之后以 src/.env 为准）
if [ ! -f .env ]; then
  log "生成 src/.env"
  cp .env.example .env
  set_env APP_ENV local
  set_env APP_DEBUG false
  set_env APP_URL "${V2B_APP_URL}"
  set_env DB_HOST mysql
  set_env DB_PORT 3306
  set_env DB_DATABASE "${V2B_DB_DATABASE}"
  set_env DB_USERNAME "${V2B_DB_USERNAME}"
  set_env DB_PASSWORD "${V2B_DB_PASSWORD}"
  set_env REDIS_HOST redis
  set_env REDIS_PORT 6379
  set_env CACHE_DRIVER redis
  set_env QUEUE_CONNECTION redis
  set_env MAIL_DRIVER log
  set_env HORIZON_MAX_PROCESSES "${V2B_HORIZON_MAX_PROCESSES}"
fi

# Horizon 只在 local 环境下启动 worker、放行 /monitor 接口
if ! grep -qE '^APP_ENV=local$' .env; then
  log "警告：src/.env 的 APP_ENV 不是 local，Horizon 将不会启动 worker，/monitor 接口会返回 403"
fi

# 2. composer install（vendor 缺失或 composer.json 更新时）
if [ ! -f vendor/autoload.php ] || [ composer.json -nt vendor/autoload.php ]; then
  log "composer install（首次较慢）"
  # Laravel 8 已停止维护，新版 composer 可能因安全公告拒绝安装；只改容器内全局配置，不改 composer.json
  composer config --global audit.block-insecure false >/dev/null 2>&1 || true
  export COMPOSER_NO_AUDIT=1
  if [ -n "${V2B_COMPOSER_MIRROR:-}" ]; then
    log "使用 composer 镜像 ${V2B_COMPOSER_MIRROR}"
    php -r '
      $j = json_decode(file_get_contents("composer.json"), true);
      $j["repositories"] = ["packagist" => ["type" => "composer", "url" => $argv[1]]];
      file_put_contents("composer.docker.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    ' "${V2B_COMPOSER_MIRROR}"
    export COMPOSER=composer.docker.json
  fi
  composer install --no-interaction --prefer-dist --no-progress --no-dev --optimize-autoloader
  touch vendor/autoload.php
fi

mkdir -p storage/app storage/logs storage/framework/cache/data storage/framework/sessions \
         storage/framework/views bootstrap/cache config/theme
php artisan config:clear --no-interaction >/dev/null

# 3. APP_KEY
if ! grep -qE '^APP_KEY=base64:' .env; then
  log "生成 APP_KEY"
  php artisan key:generate --force --no-interaction
fi

# 4. config/v2board.php（固定后台路径，避免默认值随 APP_KEY 变化）
if [ ! -f config/v2board.php ]; then
  if ! [[ "${V2B_SECURE_PATH}" =~ ^[A-Za-z0-9_-]{8,}$ ]]; then
    log "V2B_SECURE_PATH 必须至少 8 位，且只能包含字母、数字、下划线、横线"
    exit 1
  fi
  log "写入 config/v2board.php（secure_path=${V2B_SECURE_PATH}）"
  php -r '
    $c = [
        "secure_path" => getenv("V2B_SECURE_PATH"),
        "app_name" => getenv("V2B_APP_NAME"),
        "app_url" => getenv("V2B_APP_URL"),
        "server_token" => bin2hex(random_bytes(16)),
        "frontend_theme" => "default",
        "frontend_theme_sidebar" => "light",
        "frontend_theme_header" => "dark",
        "frontend_theme_color" => "default",
    ];
    file_put_contents("config/v2board.php", "<?php\n return " . var_export($c, true) . ";\n");
  '
fi

# 5. 缓存配置、确保管理员、演示数据
php artisan config:cache --no-interaction
php /seed/ensure-admin.php
# 演示数据只在全新的库（没有套餐）上生成；之后由 ./scripts/demo-reset.sh 或演示站的 demo 服务按当前时间重新生成
if [ "${V2B_SEED_DEMO:-1}" = "1" ]; then
  php -d memory_limit=1G /seed/demo/reset.php --if-empty --no-config
fi

# 6. php-fpm 以 www-data 运行，后台保存配置时要写 config/ 与 bootstrap/cache/
chmod -R a+rwX storage bootstrap/cache config

SECURE_PATH=$(php -r '$c = include "config/v2board.php"; echo $c["secure_path"] ?? "";')
log "完成：旧管理端 ${V2B_APP_URL}/${SECURE_PATH}   API ${V2B_APP_URL}/api/v1/${SECURE_PATH}/..."
