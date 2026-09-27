<?php
// 复原演示数据：按当前时间从头生成（world.php），在一个事务里整体替换，访客在替换过程中只看得到旧数据。
// 用法（在 php 容器里）：php /seed/demo/reset.php [--if-empty] [--dry-run] [--now=时间] [--config | --no-config]
//   --if-empty   库里已经有套餐时什么也不做（init 在全新的库上生成演示数据用）
//   --dry-run    只生成、打印统计，不写库
//   --now=...    按指定时间生成（strtotime 能识别的格式或时间戳，测试用）
//   --config     同时把系统配置、主题配置写回模板；公开演示站（DEMO_ENABLE=1）默认就会写，--no-config 不写
// 提交之后：重置自增值、确保管理员触发器、清理 Redis（保留登录会话）、写入节点状态与在线用户

require_once __DIR__ . '/world.php';
require_once __DIR__ . '/check.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/config.php';

use App\Services\StatisticalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/** 整体替换的表：固定部分按模型写入，其余按行批量写入；v2_stat 由 StatisticalService 算出；最后几张只清空 */
const DEMO_TABLES = [
    'v2_server_group', 'v2_server_route', 'v2_plan', 'v2_server_shadowsocks', 'v2_server_vmess', 'v2_server_trojan',
    'v2_server_vless', 'v2_server_hysteria', 'v2_server_tuic', 'v2_server_anytls', 'v2_server_v2node', 'v2_payment',
    'v2_coupon', 'v2_giftcard', 'v2_notice', 'v2_knowledge', 'v2_user', 'v2_order', 'v2_commission_log', 'v2_ticket',
    'v2_ticket_message', 'v2_stat', 'v2_stat_user', 'v2_stat_server', 'v2_invite_code',
    // 只清空：邮件记录、请求日志（后端把每个 POST 请求的原文记在这里，包括登录时输入的密码）、失败的队列任务
    'v2_mail_log', 'v2_log', 'failed_jobs',
];

$options = getopt('', ['if-empty', 'dry-run', 'now:', 'config', 'no-config']);
$now = isset($options['now']) ? (ctype_digit((string)$options['now']) ? (int)$options['now'] : strtotime($options['now'])) : time();
$days = max(30, (int)demo_env('DEMO_DAYS', 120));
$withConfig = isset($options['config']) || (demo_enabled() && !isset($options['no-config']));

if (isset($options['if-empty']) && DB::table('v2_plan')->count() > 0) {
    demo_log('已有套餐数据，跳过演示数据');
    exit(0);
}

$started = microtime(true);
$world = demo_world($now, $days);
if (isset($options['dry-run'])) {
    demo_log('试生成（不写库）：' . json_encode($world['summary'], JSON_UNESCAPED_UNICODE));
    exit(0);
}

foreach (array_diff(demo_v2_tables(), DEMO_TABLES) as $table) {
    demo_log("注意：表 {$table} 不在复原范围内，保持原样（后端新增的表？）");
}
// 旧脚本 simulate-traffic 的记录表（已退役）
DB::statement('DROP TABLE IF EXISTS demo_traffic_journal');

try {
    DB::beginTransaction();
    DB::statement('SET @demo_reset = 1');
    foreach (DEMO_TABLES as $table) DB::table($table)->delete();

    // 只写表里有的列：wyx2685 的不同版本表结构不同（例如较早的版本没有 v2_server_v2node.trusted_x_forwarded_for），
    // 新增的列用表的默认值
    Model::unguard();
    foreach ($world['catalog'] as $class => $rows) {
        $model = new $class();
        $columns = array_flip(Schema::getColumnListing($model->getTable()));
        foreach ($rows as $row) (new $class())->forceFill(array_intersect_key($row, $columns))->save();
    }
    Model::reguard();
    foreach ($world['tables'] as $table => $rows) {
        $columns = array_flip(Schema::getColumnListing($table));
        $rows = array_map(function ($row) use ($columns) { return array_intersect_key($row, $columns); }, $rows);
        foreach (array_chunk($rows, 1000) as $chunk) DB::table($table)->insert($chunk);
    }

    // 每日统计：与 v2board:statistics（每天 0:10 统计前一天）相同；0:10 之前昨天还没有统计
    $stats = [];
    foreach ($world['stat_days'] as $day) {
        $createdAt = $day + DAY + 600 + rint("stat:{$day}", 0, 30);
        if ($createdAt > $now) continue;
        $service = new StatisticalService();
        $service->setStartAt($day);
        $service->setEndAt($day + DAY);
        $stats[] = ['id' => count($stats) + 1] + $service->generateStatData()
            + ['record_at' => $day, 'record_type' => 'd', 'created_at' => $createdAt, 'updated_at' => $createdAt];
    }
    foreach (array_chunk($stats, 500) as $chunk) DB::table('v2_stat')->insert($chunk);

    $problems = demo_check($now);
    if ($problems) throw new RuntimeException('自检不通过：' . implode('；', $problems));
    DB::statement('SET @demo_reset = NULL');
    DB::commit();
} catch (Throwable $e) {
    DB::rollBack();
    DB::statement('SET @demo_reset = NULL');
    demo_log('复原失败，已回滚，数据保持原样：' . mb_substr($e->getMessage(), 0, 800));
    exit(1);
}

// 以下在事务之外（ALTER / CREATE TRIGGER 会隐式提交）
foreach (DEMO_TABLES as $table) DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
if (demo_ensure_triggers()) demo_log('已创建管理员保护触发器');
if ($withConfig) {
    demo_restore_config();
    demo_log('系统配置、主题配置已写回模板');
}
$cleaned = demo_clean_redis();
demo_write_node_status($now);
$online = demo_write_online_users($now);

demo_log(sprintf('复原完成（%.1f 秒）：开站日 %s，用户 %d，订单 %d（已付 %d），佣金记录 %d，工单 %d，流量统计 %d + %d 行，每日统计 %d 天；在线 %d；清理缓存 %d 个',
    microtime(true) - $started, $world['summary']['start'], $world['summary']['users'], $world['summary']['orders'],
    $world['summary']['paid_orders'], $world['summary']['commission_logs'], $world['summary']['tickets'],
    $world['summary']['stat_user'], $world['summary']['stat_server'], count($stats), $online, $cleaned));

/** 库里所有 V2board 的表 */
function demo_v2_tables(): array
{
    return array_map(function ($row) { return array_values((array)$row)[0]; },
        DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND (table_name LIKE 'v2\\_%' OR table_name = 'failed_jobs')"));
}

/**
 * 清理 Redis，返回删除的键数：
 *   - 缓存库（cache 连接）：保留登录会话（USER_SESSIONS_* 与以 JWT 为键的缓存），删掉其余的，
 *     包括登录锁定、注册限流、验证码、节点状态、在线设备、订阅链接的临时令牌（之后重新写入节点状态与在线用户）
 *   - 队列库（default 连接）：删掉待处理的队列任务（例如访客触发的群发邮件）和未入库的流量
 */
function demo_clean_redis(): int
{
    $cachePrefix = config('cache.prefix') . ':';
    $deleted = demo_scan_delete(Redis::connection('cache')->client(), '*', function (string $key, string $connectionPrefix) use ($cachePrefix) {
        $name = substr($key, strlen($connectionPrefix . $cachePrefix));
        return strpos($name, 'USER_SESSIONS_') === 0 || strpos($name, 'eyJ') === 0;
    });
    $default = Redis::connection('default')->client();
    foreach (['queues:*', 'v2board_upload_traffic', 'v2board_download_traffic', 'traffic_reset_lock'] as $pattern) {
        $deleted += demo_scan_delete($default, $pattern, function () { return false; });
    }
    return $deleted;
}

/** 按模式（不含连接前缀）扫描并删除，$keep 返回 true 的保留 */
function demo_scan_delete($client, string $pattern, callable $keep): int
{
    $prefix = (string)$client->getOption(\Redis::OPT_PREFIX);
    $client->setOption(\Redis::OPT_PREFIX, '');
    $client->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
    $deleted = 0;
    try {
        $iterator = null;
        while (($keys = $client->scan($iterator, $prefix . $pattern, 1000)) !== false) {
            $remove = array_values(array_filter($keys, function ($key) use ($keep, $prefix) { return !$keep($key, $prefix); }));
            if ($remove) $deleted += (int)$client->del($remove);
            if (!$iterator) break;
        }
    } finally {
        $client->setOption(\Redis::OPT_PREFIX, $prefix);
    }
    return $deleted;
}
