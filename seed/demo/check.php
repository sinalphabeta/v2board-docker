<?php
// 演示数据的一致性自检：直接查库（复原时在提交事务之前运行，失败则回滚；也可以单独运行：./scripts/demo-check.sh）。
// 返回问题列表（空表示通过）。检查的都是真实系统里不可能出现的情况。

require_once __DIR__ . '/lib.php';

use Illuminate\Support\Facades\DB;

function demo_check(int $now): array
{
    $problems = [];
    $count = function (string $sql, array $bindings = []) { return (int)DB::selectOne($sql, $bindings)->n; };
    $expect = function (string $what, int $n) use (&$problems) { if ($n) $problems[] = "{$what}：{$n}"; };

    // 时间先后
    $expect('用户 id 与注册先后不一致（逆序对）',
        $count('SELECT COUNT(*) n FROM v2_user a JOIN v2_user b ON a.id < b.id AND a.created_at > b.created_at'));
    $expect('被邀请人早于邀请人注册',
        $count('SELECT COUNT(*) n FROM v2_user u JOIN v2_user i ON u.invite_user_id = i.id WHERE u.created_at <= i.created_at'));
    $expect('邀请人不存在', $count('SELECT COUNT(*) n FROM v2_user u LEFT JOIN v2_user i ON u.invite_user_id = i.id WHERE u.invite_user_id IS NOT NULL AND i.id IS NULL'));
    $expect('订单早于用户注册', $count('SELECT COUNT(*) n FROM v2_order o JOIN v2_user u ON o.user_id = u.id WHERE o.created_at < u.created_at'));
    $expect('订单的用户不存在', $count('SELECT COUNT(*) n FROM v2_order o LEFT JOIN v2_user u ON o.user_id = u.id WHERE u.id IS NULL'));
    $expect('订单 id 与下单先后不一致', $count('SELECT COUNT(*) n FROM v2_order a JOIN v2_order b ON a.id < b.id AND a.created_at > b.created_at'));
    $expect('工单早于用户注册', $count('SELECT COUNT(*) n FROM v2_ticket t JOIN v2_user u ON t.user_id = u.id WHERE t.created_at < u.created_at'));
    $expect('工单消息早于工单', $count('SELECT COUNT(*) n FROM v2_ticket_message m JOIN v2_ticket t ON m.ticket_id = t.id WHERE m.created_at < t.created_at'));

    // 不晚于现在
    foreach ([
        'v2_user' => ['created_at', 'updated_at', 't', 'last_login_at'],
        'v2_order' => ['created_at', 'updated_at', 'paid_at'],
        'v2_commission_log' => ['created_at'], 'v2_ticket' => ['created_at', 'updated_at'],
        'v2_ticket_message' => ['created_at'], 'v2_stat_user' => ['record_at', 'updated_at'],
        'v2_stat_server' => ['record_at', 'updated_at'], 'v2_stat' => ['record_at'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            $expect("{$table}.{$column} 晚于现在", $count("SELECT COUNT(*) n FROM {$table} WHERE {$column} > ?", [$now]));
        }
    }

    // 订单状态
    $expect('已付款订单缺少付款时间，或未付款订单有付款时间',
        $count('SELECT COUNT(*) n FROM v2_order WHERE (status IN (1, 3, 4) AND paid_at IS NULL) OR (status IN (0, 2) AND paid_at IS NOT NULL)'));
    $expect('付款早于下单', $count('SELECT COUNT(*) n FROM v2_order WHERE paid_at < created_at'));
    $expect('超过 2 小时仍待支付（check:order 应已取消）', $count('SELECT COUNT(*) n FROM v2_order WHERE status = 0 AND created_at < ?', [$now - 7200 - 60]));
    $expect('优惠后金额不对', $count('SELECT COUNT(*) n FROM v2_order WHERE total_amount < 0'));

    // 佣金：开通满 3 天（下一次检查）的应已发放；已发放且有金额的要有记录；邀请人余额等于记录合计
    $expect('开通满 3 天的佣金仍待确认', $count(
        'SELECT COUNT(*) n FROM v2_order WHERE commission_status = 0 AND invite_user_id IS NOT NULL AND status IN (3, 4) AND updated_at <= ?',
        [$now - 3 * DAY - 900]));
    $expect('已发放的佣金没有记录', $count(
        'SELECT COUNT(*) n FROM v2_order o LEFT JOIN v2_commission_log l ON l.trade_no = o.trade_no
         WHERE o.commission_status = 2 AND o.commission_balance > 0 AND l.id IS NULL'));
    $expect('邀请人的佣金余额与记录合计不符', $count(
        'SELECT COUNT(*) n FROM v2_user u LEFT JOIN (SELECT invite_user_id, SUM(get_amount) s FROM v2_commission_log GROUP BY invite_user_id) l
         ON l.invite_user_id = u.id WHERE u.commission_balance <> COALESCE(l.s, 0)'));

    // 套餐：有套餐的用户都有已付订单；到期时间晚于开通；已用不超过上限
    $expect('有套餐但没有已付订单', $count(
        'SELECT COUNT(*) n FROM v2_user u WHERE u.plan_id IS NOT NULL AND NOT EXISTS
         (SELECT 1 FROM v2_order o WHERE o.user_id = u.id AND o.status IN (3, 4))'));
    $expect('已用流量超过上限', $count('SELECT COUNT(*) n FROM v2_user WHERE u + d > transfer_enable'));
    $expect('没有套餐却有流量', $count('SELECT COUNT(*) n FROM v2_user WHERE plan_id IS NULL AND (u > 0 OR d > 0)'));

    // 流量：每天各节点合计 = 各用户合计（都是原始流量）；用户流量只走权限组里的节点（节点统计里没有别的组的节点）
    $expect('节点与用户的流量合计对不上（天数）', $count(
        'SELECT COUNT(*) n FROM (SELECT record_at, SUM(u + d) s FROM v2_stat_server GROUP BY record_at) a
         LEFT JOIN (SELECT record_at, SUM(u + d) s FROM v2_stat_user GROUP BY record_at) b ON a.record_at = b.record_at
         WHERE a.s <> COALESCE(b.s, 0)'));
    $expect('流量统计里有被封禁后产生的流量', $count(
        'SELECT COUNT(*) n FROM v2_stat_user s JOIN v2_user u ON u.id = s.user_id WHERE u.banned = 1 AND s.record_at > u.updated_at'));

    // 已用流量 = 上次清零之后的流量统计 × 倍率。清零的时机：新购、重置包、一次性套餐（取最后一次的日期），
    // 以及有效期内每月 1 号 0 点（ResetTraffic；一次性套餐不重置）。从最后一次新购起订阅是连续的（中断后只能新购），
    // 所以最后一次清零 = max(最后一次新购的日期, 有效期结束前最后一个 1 号)。清零当天的流量都在清零之后产生
    $resets = [];
    foreach (DB::select('SELECT user_id, MAX(paid_at) at FROM v2_order WHERE status IN (3, 4) AND (type IN (1, 4) OR period = ?) GROUP BY user_id', ['onetime_price']) as $row) {
        $resets[$row->user_id] = day_start((int)$row->at);
    }
    $sums = [];
    foreach (DB::select('SELECT user_id, record_at, server_rate, u, d FROM v2_stat_user') as $row) {
        $sums[$row->user_id][] = [$row->record_at, (float)$row->server_rate, (int)$row->u, (int)$row->d];
    }
    $mismatch = 0;
    foreach (DB::select('SELECT id, u, d, expired_at, plan_id FROM v2_user WHERE plan_id IS NOT NULL') as $user) {
        $from = $resets[$user->id] ?? 0;
        if ($user->expired_at !== null) {
            $end = min($now, (int)$user->expired_at);
            $monthStart = strtotime(date('Y-m-01', $end));
            if ($monthStart < $end) $from = max($from, $monthStart);
        }
        $u = $d = 0;
        foreach ($sums[$user->id] ?? [] as [$recordAt, $rate, $su, $sd]) {
            if ($recordAt < $from) continue;
            $u += (int)($su * $rate);
            $d += (int)($sd * $rate);
        }
        if ($u !== (int)$user->u || $d !== (int)$user->d) $mismatch++;
    }
    $expect('已用流量与流量统计对不上的用户', $mismatch);

    // 每日统计：开站日（管理员注册那天）到昨天，每天一行（每天 0:10 统计前一天，之前昨天还没有）
    $siteStart = day_start((int)DB::selectOne('SELECT MIN(created_at) n FROM v2_user')->n);
    $expectedDays = (int)round((day_start($now) - $siteStart) / DAY);
    $rows = $count('SELECT COUNT(*) n FROM v2_stat WHERE record_type = ?', ['d']);
    if ($rows !== $expectedDays && !($rows === $expectedDays - 1 && $now < day_start($now) + 640)) {
        $problems[] = "每日统计有 {$rows} 行，应为 {$expectedDays} 行";
    }

    return $problems;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $problems = demo_check(time());
    if (!$problems) {
        demo_log('自检通过');
        exit(0);
    }
    foreach ($problems as $problem) demo_log("自检不通过：{$problem}");
    exit(1);
}
