<?php
// 节点在线状态与在线用户：替代真实节点的定时上报，写入后端判断状态用的缓存（与 UniProxyController 写的是同一组 key）。
//   SERVER_<TYPE>_LAST_CHECK_AT / _LAST_PUSH_AT / _ONLINE_USER：后台节点列表据此显示运行正常 / 上报异常 / 未运行
//     （超过 5 分钟没有拉取为「未运行」，拉取了但超过 5 分钟没有上报为「无人使用或服务端上报异常」）；子节点读父节点的缓存
//   v2_user.t：仪表盘的「在线人数」是 10 分钟内有上报的用户数（StatController::getOverride）
//   ALIVE_IP_USER_<用户 id>：用户列表里的在线设备（UniProxyController::alive 写入的格式）

require_once __DIR__ . '/lib.php';

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const DEMO_NODE_MODELS = [
    'shadowsocks' => App\Models\ServerShadowsocks::class,
    'vmess' => App\Models\ServerVmess::class,
    'trojan' => App\Models\ServerTrojan::class,
    'tuic' => App\Models\ServerTuic::class,
    'hysteria' => App\Models\ServerHysteria::class,
    'vless' => App\Models\ServerVless::class,
    'anytls' => App\Models\ServerAnytls::class,
    'v2node' => App\Models\ServerV2node::class,
];

/**
 * 写节点状态。按位置循环分配，保证三种状态都有：2 运行正常、1 无人使用或上报异常、0 未运行。
 * $hold：把时间戳写成「现在 + hold 秒」，状态保持 hold 秒不变（本机截图比对用）；$clear：全部清除（显示未运行）
 */
function demo_write_node_status(int $now, int $hold = 0, bool $clear = false, bool $verbose = false): array
{
    $pattern = [2, 2, 1, 2, 0];
    $labels = [0 => '未运行', 1 => '无人使用或上报异常', 2 => '运行正常'];
    $ttl = 3600 + $hold;
    $index = 0;
    $result = [];
    foreach (DEMO_NODE_MODELS as $type => $model) {
        $prefix = 'SERVER_' . strtoupper($type);
        foreach ($model::whereNull('parent_id')->orderBy('sort')->orderBy('id')->get(['id', 'name']) as $server) {
            $keys = [
                'check' => CacheKey::get("{$prefix}_LAST_CHECK_AT", $server->id),
                'push' => CacheKey::get("{$prefix}_LAST_PUSH_AT", $server->id),
                'online' => CacheKey::get("{$prefix}_ONLINE_USER", $server->id),
            ];
            if ($clear) {
                foreach ($keys as $key) Cache::forget($key);
                $result[] = "{$type} #{$server->id} {$server->name}：已清除";
                continue;
            }
            $status = $pattern[$index++ % count($pattern)];
            // 在线人数按 id 固定生成，便于截图比对
            $online = $status === 2 ? 3 + ($server->id * 7 + strlen($type) * 3) % 40 : 0;
            if ($status === 0) {
                foreach ($keys as $key) Cache::forget($key);
            } else {
                Cache::put($keys['check'], $now + $hold, $ttl);
                Cache::put($keys['online'], $online, $ttl);
                Cache::put($keys['push'], $status === 2 ? $now + $hold : $now - 600, $ttl);
            }
            $result[] = "{$type} #{$server->id} {$server->name}：{$labels[$status]}，在线 {$online}";
        }
    }
    if ($verbose) foreach ($result as $line) demo_log($line);
    return $result;
}

/**
 * 在线用户：从今天用过流量、订阅有效的用户里，每 5 分钟换一批（6–15 人），把 t 写成最近 5 分钟内，
 * 并写在线设备（1–3 个保留地址段的 IP）。返回在线人数
 */
function demo_write_online_users(int $now): int
{
    $slot = intdiv($now, 300);
    $today = day_start($now);
    $candidates = DB::table('v2_stat_user')->where('record_at', $today)->distinct()->pluck('user_id')->all();
    $valid = DB::table('v2_user')->whereIn('id', $candidates)->where('banned', 0)
        ->where(function ($q) use ($now) { $q->whereNull('expired_at')->orWhere('expired_at', '>', $now); })
        ->pluck('id')->all();
    if (!$valid) return 0;
    usort($valid, function ($a, $b) use ($slot) { return rnd("online:{$slot}:{$a}") <=> rnd("online:{$slot}:{$b}"); });
    $online = array_slice($valid, 0, min(count($valid), rint("online:{$slot}:n", 6, 15)));
    foreach ($online as $userId) {
        $t = $now - rint("online:{$slot}:{$userId}:t", 5, 240);
        DB::table('v2_user')->where('id', $userId)->where('t', '<', $t)->update(['t' => $t]);
        $devices = rint("online:{$slot}:{$userId}:devices", 1, 3);
        $ips = [];
        for ($i = 0; $i < $devices; $i++) $ips[] = demo_ip("online:{$userId}:{$i}") . '_' . rint("online:{$userId}:{$i}:node", 1, 3);
        Cache::put('ALIVE_IP_USER_' . $userId, ['vmess1' => ['aliveips' => $ips, 'lastupdateAt' => $now], 'alive_ip' => $devices], 400);
    }
    return count($online);
}
