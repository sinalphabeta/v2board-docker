<?php
// 演示站的系统配置、主题配置与管理员保护。只在公开演示站（DEMO_ENABLE=1）上复原配置、守护关键配置；
// 本机开发不动你自己改的配置。
//   - demo_restore_config：整点复原时把 config/v2board.php 与主题配置写回模板
//   - demo_guard_config：每分钟检查一次会让所有人进不去或用不了的配置，被改了就改回模板值
//   - demo_ensure_triggers：MySQL 触发器，禁止删除管理员、修改其邮箱 / 密码 / 管理员身份 / 封禁状态（复原时设 @demo_reset 放行）

require_once __DIR__ . '/lib.php';

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** 守护的配置：被改了就改回模板值 */
const DEMO_GUARDED_KEYS = ['secure_path', 'subscribe_path', 'app_url', 'safe_mode_enable', 'password_limit_enable'];

function demo_config_path(): string
{
    return base_path('config/v2board.php');
}

/** 当前的配置文件（CLI 没有 OPcache，读到的就是文件里的内容） */
function demo_read_config(): array
{
    $path = demo_config_path();
    return is_file($path) ? (array)(include $path) : [];
}

/** 演示站的配置模板：后台路径、站点名称与地址取 .env；server_token 沿用已有的 */
function demo_golden_config(array $current): array
{
    $appUrl = (string)demo_env('V2B_APP_URL', 'http://localhost:6600');
    return [
        'secure_path' => (string)demo_env('V2B_SECURE_PATH', $current['secure_path'] ?? 'devadmin123'),
        'app_name' => (string)demo_env('V2B_APP_NAME', 'V2Board'),
        'app_url' => $appUrl,
        'app_description' => 'V2Board 管理端演示站，数据每小时整点复原',
        'server_token' => $current['server_token'] ?? substr(hash('sha256', 'server-token:' . config('app.key')), 0, 32),
        'force_https' => strpos($appUrl, 'https://') === 0 ? 1 : 0,
        'frontend_theme' => 'default',
        'frontend_theme_sidebar' => 'light',
        'frontend_theme_header' => 'dark',
        'frontend_theme_color' => 'default',
        'frontend_background_url' => '',
        // 演示账号的邮箱是公开的：开着「密码错误次数限制」时，有人故意输错几次，新访客就一小时登录不了
        'password_limit_enable' => 0,
        'safe_mode_enable' => 0,
        'email_whitelist_enable' => 0,
        'recaptcha_enable' => 0,
        'register_limit_by_ip_enable' => 0,
        'telegram_bot_enable' => 0,
        'telegram_bot_token' => null,
        'telegram_discuss_link' => null,
        'commission_distribution_enable' => 0,
        'try_out_plan_id' => 0,
        'show_subscribe_method' => 0,
        // 以下与后端默认值相同，写出来便于看清演示数据按什么规则生成（world.php）
        'invite_commission' => 10,
        'commission_first_time_enable' => 1,
        'commission_auto_check_enable' => 1,
        'reset_traffic_method' => 0,
    ];
}

const DEMO_THEME_CONFIG = [
    'theme_color' => 'default',
    'background_url' => '',
    'theme_sidebar' => 'light',
    'theme_header' => 'dark',
    'custom_html' => '',
];

/** 写配置文件（与 ConfigController::save 的格式相同），重建配置缓存；php-fpm 以 www-data 运行，文件要可写 */
function demo_write_config(array $config): void
{
    $path = demo_config_path();
    file_put_contents($path, "<?php\n return " . var_export($config, true) . " ;");
    @chmod($path, 0666);
    Artisan::call('config:cache');
    @chmod(base_path('bootstrap/cache/config.php'), 0666);
    // php-fpm 的 OPcache 每 2 秒按修改时间重新检查文件（revalidate_freq=2），不需要重启
}

function demo_restore_config(): void
{
    demo_write_config(demo_golden_config(demo_read_config()));
    $themeDir = base_path('config/theme');
    if (!is_dir($themeDir)) mkdir($themeDir, 0777, true);
    $themeFile = "{$themeDir}/default.php";
    file_put_contents($themeFile, "<?php\n return " . var_export(DEMO_THEME_CONFIG, true) . " ;");
    @chmod($themeFile, 0666);
    // 其他主题的配置（访客在「主题配置」里保存的）删掉，恢复默认
    foreach (glob("{$themeDir}/*.php") as $file) if (basename($file) !== 'default.php') @unlink($file);
}

/** 返回被改回来的配置项 */
function demo_guard_config(): array
{
    $current = demo_read_config();
    $golden = demo_golden_config($current);
    $changed = [];
    foreach (DEMO_GUARDED_KEYS as $key) {
        $want = $golden[$key] ?? null;
        $have = $current[$key] ?? null;
        if ((string)$want === (string)$have) continue;
        $changed[] = $key;
        if ($want === null) unset($current[$key]);
        else $current[$key] = $want;
    }
    if ($changed) demo_write_config($current);
    return $changed;
}

/** 管理员（id 1）保护：删除、修改邮箱 / 密码 / 管理员身份 / 封禁状态都会报错；复原的连接设了 @demo_reset 时放行 */
function demo_ensure_triggers(): bool
{
    $existing = array_map(function ($row) { return $row->Trigger; }, DB::select("SHOW TRIGGERS LIKE 'v2_user'"));
    $created = false;
    if (!in_array('demo_protect_admin_delete', $existing, true)) {
        DB::unprepared("CREATE TRIGGER demo_protect_admin_delete BEFORE DELETE ON v2_user FOR EACH ROW
            BEGIN
                IF @demo_reset IS NULL AND OLD.id = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'demo: the admin account cannot be deleted';
                END IF;
            END");
        $created = true;
    }
    if (!in_array('demo_protect_admin_update', $existing, true)) {
        DB::unprepared("CREATE TRIGGER demo_protect_admin_update BEFORE UPDATE ON v2_user FOR EACH ROW
            BEGIN
                IF @demo_reset IS NULL AND OLD.id = 1 AND (NOT (NEW.email <=> OLD.email) OR NOT (NEW.password <=> OLD.password)
                    OR NOT (NEW.is_admin <=> OLD.is_admin) OR NOT (NEW.banned <=> OLD.banned)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'demo: the admin account cannot be changed';
                END IF;
            END");
        $created = true;
    }
    return $created;
}
