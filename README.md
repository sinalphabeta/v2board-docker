# v2b-demo

用 Docker 跑 [wyx2685/v2board](https://github.com/wyx2685/v2board) 后端的开发与演示环境：
nginx + php-fpm 8.2 + MySQL 8.4 + Redis 7 + Horizon。

- **本机开发**：查看原版管理端，给前后端分离的新管理端 v2board-admin 做联调。
- **公开演示站**：给演示站当后端。每小时整点复原数据，访客随便点也不怕；数据始终是新的，仪表盘的今日、昨日、30 天曲线一直有内容。

它的几个特点：

- V2board 的源码不在本仓库里：`scripts/sync-src.sh` 自动从 GitHub 拉 wyx2685 的 master（也可以固定版本或换成别的仓库）。
- 演示数据按 V2board 的真实流程生成：注册 → 下单 → 开通 → 佣金 → 流量 → 工单。每日统计由后端自己的统计逻辑从记录算出，前后对得上。
- 一条命令更新到 wyx2685 的最新版本：`./scripts/update.sh`。流程与 wyx2685 自己的 update.sh 相同。

## 快速开始（本机）

```bash
cp .env.example .env      # 按需修改端口、后台路径、管理员账号
./scripts/up.sh           # 同步源码 → 构建镜像 → 初始化（首次会生成演示数据）→ 启动
```

首次启动要执行 `composer install`，需要几分钟。完成后：

| 项目 | 默认值 |
|---|---|
| 旧管理端（原版） | http://localhost:6600/devadmin123 |
| API | http://localhost:6600/api/v1/... |
| 管理员 | `admin@example.com` / `admin123456`（`.env` 的 `V2B_ADMIN_EMAIL`、`V2B_ADMIN_PASSWORD`） |
| 演示用户 | `user002@example.org`、`user003@example.com`……（编号就是 id），密码都是 `user123456` |

- **手机调试（同一内网）**：`.env` 里设 `HTTP_BIND=0.0.0.0` 后执行 `docker compose up -d nginx`。新管理端用 `pnpm dev --host` 启动，
  它的 `config.local.js` 里把 host 写成 `` `http://${location.hostname}:6600` ``，就能跟随访问地址。

## 演示数据

`seed/demo/` 是一个确定性的生成器，以「现在」为终点，生成最近 `DEMO_DAYS`（默认 120）天的数据：

- **固定部分**：3 个权限组、3 条路由、8 种协议的节点（含一个中转子节点）、4 个套餐、支付方式、优惠券、礼品卡、公告、知识库。
- **每天的流程**：
  - 注册：人数逐渐增长，周末多一些。用户 id 按注册先后递增，约 30% 由更早注册的用户邀请。
  - 下单：注册后下首单，到期前续费，流量快用完时买重置包；约 15% 的订单没付款，2 小时后自动取消；部分订单用优惠券。
  - 佣金：默认只有首单有佣金，开通 3 天后发放并写佣金记录。
  - 流量：只走套餐权限组里的节点，每月 1 号清零；已用流量与流量统计对得上。
  - 工单：管理员在 9–23 点回复，回复后 24 小时没有动静的自动关闭。
  - 每日统计：用后端的 `StatisticalService` 从上面的记录算出。
- **邮箱**：只用保留域名（example.com / .net / .org），不会有邮件真的发出去。
- **随机数由日期决定**：同一时刻重复生成，结果逐字节相同；过去的日子不随时间变化；今天只生成到当前时刻，数据随时间增长。
  刚过 0 点时，会把今天最早的几个人按比例挪到现在之前，保证至少 6 人注册。
- **自检**：生成后自动检查。检查项包括：id 与注册先后一致，邀请人早于被邀请人，订单晚于注册，没有未来的时间，
  佣金与记录一致，已用流量与流量统计一致，每日统计齐全等。不通过就回滚，原来的数据保持不变。

```bash
./scripts/demo-reset.sh              # 按当前时间重新生成（本机：只换数据，不动你自己改的系统配置）
./scripts/demo-reset.sh --config     # 同时把系统配置、主题配置写回演示站的模板
./scripts/demo-reset.sh --dry-run    # 只试生成、打印统计，不写库
./scripts/demo-check.sh              # 单独检查当前数据的一致性
```

整个替换在一个数据库事务里完成（约 1 秒），访客在替换过程中只看得到旧数据。已登录的令牌在复原后仍然有效：会话存在 Redis 里，复原时保留。

## 公开演示站（服务器部署）

以 Debian 13 + Docker 为例。HTTPS 交给 Cloudflare Tunnel，服务器不用开放任何端口。

1. 准备：`apt install -y git rsync`，并确认 `docker compose version` 可用。
2. 下载并配置：
   ```bash
   git clone https://github.com/sinalphabeta/v2b-demo.git /opt/v2b-demo
   cd /opt/v2b-demo && cp .env.example .env
   ```
   在 `.env` 里修改：
   | 变量 | 说明 |
   |---|---|
   | `DEMO_ENABLE=1` | 打开演示站模式 |
   | `V2B_APP_URL=https://<后端域名>` | 站点地址 |
   | `V2B_SECURE_PATH` | 后台路径（管理端的 `V2B_SECURE_PATH` 也要填同一个） |
   | `V2B_ADMIN_EMAIL` / `V2B_ADMIN_PASSWORD` | 演示账号，会公开给访客 |
   | `MYSQL_PASSWORD` / `MYSQL_ROOT_PASSWORD` | 用 `openssl rand -hex 16` 生成 |
   | `HTTP_BIND=127.0.0.1`、`HTTP_PORT=6600` | 只让本机的 cloudflared 访问 |
   | `PHP_OUTPUT_BUFFERING=16777216` | 跨域导出 CSV 时直接拿到后端文件（要写数字，写 On 不生效） |
3. 启动：`./scripts/up.sh`。它会拉 wyx2685 的代码、构建镜像、初始化，并因为 `DEMO_ENABLE=1` 一并启动 `demo` 服务。
4. Cloudflare Tunnel：添加一个 Public Hostname，`<后端域名>` → `http://localhost:6600`。
5. 检查：
   - `./scripts/check-cors.sh https://<管理端域名>`：以跨域方式检查预检、登录和管理接口。
   - `docker compose logs -f demo`：确认每小时都有「复原完成」。
6. 管理端：用 [v2board-admin](https://github.com/sinalphabeta/v2board-admin) README 里的「Deploy to Cloudflare」按钮部署到 Cloudflare Workers
   （或者在 Workers 里直接导入 v2board-admin 仓库），变量填 `V2B_API_HOST=https://<后端域名>`、`V2B_SECURE_PATH`。
   再在 Worker 的「设置 → 变量和机密」里加 `V2B_DEMO_EMAIL`、`V2B_DEMO_PASSWORD`，登录页就会预填并显示演示账号，
   顶栏显示「演示站 · 数据每小时整点复原」。

`demo` 服务（`seed/demo/daemon.php`）做的事：

- **每小时整点复原**：
  - 按当前时间重新生成全部数据；
  - 把系统配置、主题配置写回模板。模板里关掉了「密码错误次数限制」，否则有人故意输错几次，新访客就一小时登录不了；
  - 清掉 Redis 里的登录锁定、注册限流、待处理的队列任务（例如访客触发的群发邮件）；
  - 清空请求日志 `v2_log`：后端会把每个 POST 请求的原文记在这里，包括登录时输入的密码。
- **每 5 分钟心跳**：刷新节点状态（运行正常、上报异常、未运行三种都有）、在线用户与在线设备，记录队列监控的快照。
- **每分钟守护**：
  - 盯住后台路径、订阅路径、站点地址、安全模式、密码错误次数限制，被改了就马上改回来。其中后台路径一改，管理端就连不上了；
  - 写入定时任务的心跳，系统状态显示正常。
- **管理员保护**：用 MySQL 触发器挡住删除管理员，以及修改他的邮箱、密码、管理员身份、封禁状态。这不改 V2board 的代码。
  - 访客这样操作时后台提示「删除用户失败」或「保存失败」。
  - 但后端在删除、封禁之前会先清掉该账号的全部会话，所以在线的访客要重新登录一次（登录页已预填演示账号）。

V2board 自带的定时任务保持不启动：统计由生成器按同样的规则算，自带任务里还有到期提醒邮件等会访问外网的任务。

## 更新

```bash
./scripts/update.sh
```

1. 更新本仓库（`git pull`）；
2. 备份数据库到 `backups/`（保留最近 5 份）；
3. 同步 wyx2685 的最新 master；
4. 重建 PHP 镜像；
5. 删掉 composer.lock，按新的 composer.json 重新装依赖；
6. `php artisan v2board:update`：执行 update.sql 里的数据库迁移，并重启队列；
7. 重启服务；演示站上立即复原一次，确认生成器与新的表结构兼容；
8. 最后打印升级前后的版本和 GitHub 上的对比链接。

要固定在某个版本，在 `.env` 里写 `V2BOARD_REF=<分支 / 标签 / 提交>`。

## 常用命令

```bash
./scripts/update.sh                   # 更新到 wyx2685 的最新版本（见上）
./scripts/sync-src.sh                 # 只同步源码（不装依赖、不迁移；之后 docker compose restart php horizon）
./scripts/demo-reset.sh               # 按当前时间重新生成演示数据
./scripts/demo-check.sh               # 检查演示数据的一致性
./scripts/simulate-nodes.sh           # 节点在线模拟：列表显示运行正常 / 上报异常 / 未运行（5 分钟后失效）
./scripts/simulate-nodes.sh --hold=86400   # 同上，状态保持 24 小时（截图比对时用）；--clear 清除
./scripts/sql.sh "SELECT id, email FROM v2_user LIMIT 3"   # 在数据库上执行一条 SQL（查询输出 JSON）
./scripts/artisan.sh config:cache     # 改了 src/.env 之后需要重新缓存配置
./scripts/check-cors.sh               # 以跨域方式检查预检、登录、管理接口、Horizon、CSV 导出
./scripts/reset.sh                    # 删除数据卷，重新建库并生成演示数据
docker compose logs -f php nginx      # 查看日志
docker compose --profile cron up -d scheduler   # 需要 V2board 自带的定时任务时再启动（默认不启动）
docker compose down                   # 停止
```

## 源码从哪来

- 默认：`scripts/sync-src.sh` 从 GitHub 浅拉 wyx2685 的 master 到 `.cache/v2board`，再同步到 `src/`。
- `.env` 里 `V2BOARD_REF` 换分支、标签或提交，`V2BOARD_REPO` 换仓库（例如自己的 fork）。
- `V2BOARD_DIR=../V2board ./scripts/sync-src.sh`：直接只读同步本地目录的工作区，包括未提交的修改。
- `vendor`、`.env`、`config/v2board.php` 等运行时文件只在 `src/` 里，同步时保留。`src/.v2board-source` 记录当前来源与提交。

在两个仓库之间切换（例如自己的 fork ↔ wyx2685）并保留数据时，`update.sh` 会处理依赖和 update.sql。
但 update.sql 只会加东西，另一个版本多出来的表、列、`src/.env` 配置项要自己删。改完可以在临时库里导入新版本的 install.sql，
用 `mysqldump --no-data` 对比两边的结构（多出来的只应是你自己加的表）。

## 初始化做了什么（`docker/php/init.sh`，每次启动都会幂等执行）

1. `src/.env` 不存在时从 `.env.example` 生成：`APP_ENV=local`、`DB_HOST=mysql`、`REDIS_HOST=redis`、`MAIL_DRIVER=log` 等。
2. `composer install --no-dev`（vendor 缺失或 composer.json 变化时）。
3. 生成 `APP_KEY`；`config/v2board.php` 不存在时写入固定的 `secure_path`、`app_url`、`server_token`。
4. `config:cache`，确保管理员存在（`seed/ensure-admin.php`）；库里没有套餐时生成演示数据（`seed/demo/reset.php --if-empty`）。
5. 放开 `storage/`、`bootstrap/cache/`、`config/` 写权限（后台保存配置会写这些目录）。

MySQL 数据卷首次创建时，`docker/mysql/initdb/01-install.sh` 会导入 `src/database/install.sql`。

## 同源部署新管理端（演示）

`admin-nginx`（默认不启动）模拟常见的宝塔站点配置：V2Board 伪静态、宝塔默认的 js / css 缓存规则，
再加上同源部署新管理端的两段 location（见 `docker/nginx/admin-same-origin.conf.template`）。
新管理端在 `http://localhost:6601/devadmin123/`，访问 `/devadmin123` 会跳转过去；API、用户前台等其余路径与 6600 相同。
需要把 v2board-admin 放在本仓库的同级目录：

```bash
cd ../v2board-admin && V2B_SECURE_PATH=devadmin123 pnpm build    # 同源配置：不设置 V2B_API_HOST
cd ../v2b-demo && docker compose --profile admin up -d --no-deps admin-nginx
```

- `--no-deps`：php 等服务已经在运行，不用再启动一遍（否则会重新执行一次初始化）。
- 管理端目录默认是 `../v2board-admin/dist`（重新打包后直接生效），`.env` 里用 `ADMIN_DIST` 换成其他目录，端口用 `ADMIN_HTTP_PORT` 修改。
- 后台路径取 `.env` 的 `V2B_SECURE_PATH`。在后台改过后台路径时要同时改它，再执行
  `docker compose --profile admin up -d --no-deps --force-recreate admin-nginx`。
- 停止：`docker compose --profile admin stop admin-nginx`。

## 注意事项

- **APP_ENV 必须是 local**：Horizon 的 supervisor 和 `/monitor` 访问控制都只认 local，否则队列不跑、仪表盘显示队列异常。
- **opcache 保持默认**（fpm 启用）：后台保存配置时会调用 `opcache_reset()`，如果 opcache 加载了却没启用，它返回 false，接口直接 500。
- **output_buffering**：默认 4096，与常见生产环境一致。后端的 CSV 导出（用户导出、批量生成）是直接输出的，超过缓冲区大小时响应头会提前发出、
  缺少 CORS 头，跨域请求会被浏览器拦截。`.env` 设 `PHP_OUTPUT_BUFFERING=16777216`（要写数字；写 `On` 经过环境变量替换后不生效）后执行 `docker compose up -d --force-recreate php` 即可避开。
- **后台路径**：`config/v2board.php` 生成后，后台路径以它为准（可在后台「系统配置 → 安全」里修改）；改 `.env` 的 `V2B_SECURE_PATH`
  只对全新初始化生效（演示站例外：每次复原都按 `.env` 写回）。
- **MySQL 不开二进制日志**（`docker/mysql/conf.d/v2board.cnf`）：演示数据每小时整表重写一次，开着时日志会越积越多；
  开着时普通账号也建不了触发器。
- **时区**：PHP 与 MySQL 都是 Asia/Shanghai，演示数据的「每天」按这个时区算。
- **网络**：拉镜像、composer 慢时，可以在 `.env` 里替换 `*_IMAGE`、`APT_MIRROR`、`V2B_COMPOSER_MIRROR`。
