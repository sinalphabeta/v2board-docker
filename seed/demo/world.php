<?php
// 演示数据的模拟：从开站日到现在，逐日按真实流程生成注册、订单、佣金、流量、工单。
// 规则照 V2board 本身（见各处注释里的出处）；随机数由「日期 + 用户 + 事件」的键决定，所以：
//   - 同一时刻重复生成，结果完全相同；
//   - 过去的日子不随「现在」变化（开站日随窗口滑动时，id 会整体前移，但每天发生的事情不变）；
//   - 今天只生成到当前时刻，随时间推移自然增加。
// 返回 ['catalog' => 固定部分, 'tables' => [表名 => 行], 'stat_days' => 需要生成 v2_stat 的日子, 'summary' => 统计]

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/catalog.php';

const DEMO_INVITE_COMMISSION = 10;      // 与 v2board.invite_commission 的默认值相同（%）
const DEMO_TODAY_MIN_REG = 6;           // 刚过 0 点时也保证今天至少有这么多人注册（把最早的几个人按比例挪到现在之前）

function demo_world(int $now, int $days): array
{
    $today = day_start($now);
    $start = $today - ($days - 1) * DAY;
    $catalog = demo_catalog($start, $now);
    $plans = array_column($catalog[App\Models\Plan::class], null, 'id');
    $coupons = $catalog[App\Models\Coupon::class];
    $couponUsed = [];            // 优惠券 id => 已用次数
    $couponUsedBy = [];          // "优惠券 id:用户序号" => true
    $nodesByGroup = [];
    foreach ([1, 2, 3] as $groupId) $nodesByGroup[$groupId] = demo_usable_nodes($catalog, $groupId);

    $users = [];                 // 按注册先后，序号即 id - 1
    $orders = [];
    $events = [];                // 待发生的购买事件：[时间, 用户序号, 种类, 附加]
    $tickets = [];
    $statUser = [];              // "user:rate:day" => 行
    $statServer = [];            // "type:id:day" => 行
    $commissionLogs = [];

    // 今天的时间映射：刚过 0 点时把前 DEMO_TODAY_MIN_REG 个注册的时刻按比例压缩到现在之前（见 demo_today_warp）
    $warp = demo_today_warp($today, $now, demo_day_registrations(date('Y-m-d', $today), $days - 1, $days, $today));

    // ---------- 管理员（id 1，开站当天注册）----------
    $admin = demo_new_user('admin', 1, $start + 9 * 3600, null);
    $admin['is_admin'] = 1;
    $users[] = $admin;

    for ($k = 0; $k < $days; $k++) {
        $dayStart = $start + $k * DAY;
        $date = date('Y-m-d', $dayStart);
        $isToday = $dayStart === $today;
        $dayEnd = $isToday ? $now : $dayStart + DAY;

        // 1. 每月 1 号 0 点重置流量（ResetTraffic：系统默认按月 1 号；只处理未过期、非一次性套餐的用户；专线套餐不重置）
        if (date('d', $dayStart) === '01') {
            foreach ($users as &$user) {
                if ($user['expired_at'] === null || $user['expired_at'] <= $dayStart) continue;
                if (($plans[$user['plan_id']]['reset_traffic_method'] ?? null) !== null) continue;
                $user['u'] = $user['d'] = 0;
                $user['reset_at'] = $dayStart;
            }
            unset($user);
        }

        // 2. 注册：逐渐增长，周末多一些。今天的事件先按原本的时刻（natural）安排，处理时再映射（见 demo_today_warp），
        //    这样映射后下单仍然晚于注册
        foreach (demo_day_registrations($date, $k, $days, $dayStart) as $i => $offset) {
            $natural = $dayStart + $offset;
            $createdAt = $isToday ? $warp($natural) : $natural;
            if ($createdAt > $now) break;
            $key = "u:{$date}:{$i}";
            $id = count($users) + 1;
            // 约 30% 由更早注册的用户邀请（推广的人集中在少数用户身上）
            $inviter = null;
            if ($id > 3 && rchance("{$key}:invited", 0.3)) {
                $candidates = count($users);
                $inviter = rchance("{$key}:promoter", 0.6)
                    ? rint("{$key}:inviter", 2, min($candidates, 2 + (int)($candidates * 0.1)))
                    : rint("{$key}:inviter", 2, $candidates);
            }
            $user = demo_new_user($key, $id, $createdAt, $inviter);
            $users[] = $user;
            // 首单：78% 的人会买，一半在 2 小时内，其余在 3 天内
            if (rchance("{$key}:buy", 0.78)) {
                $delay = rchance("{$key}:quick", 0.5) ? rint("{$key}:delay", 120, 7200) : rint("{$key}:delay", 7200, 3 * DAY);
                // 第 5 项：时刻是原本的时刻，落在今天时要映射
                $events[] = [$natural + $delay, $id - 1, 'first', null, true, "{$key}:first"];
            }
            // 工单：约 5% 的用户在用了一段时间之后开一个
            if (rchance("{$key}:ticket", 0.05)) {
                $tickets[] = ['user' => $id - 1, 'at' => $natural + rint("{$key}:ticket-at", 2 * 3600, 25 * DAY), 'key' => "{$key}:ticket"];
            }
        }

        // 3. 当天的购买事件，按时间先后处理（处理过程中可能产生新的事件，例如付款失败后重试；这些按实际时刻安排，不再映射）
        while (true) {
            usort($events, function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
            if (!$events || $events[0][0] >= $dayStart + DAY) break;
            [$time, $index, $kind, $extra, $natural, $eventKey] = array_shift($events) + [4 => false, 5 => null];
            if ($isToday && $natural) $time = $warp($time);
            if ($time > $now) continue;
            demo_purchase($users, $orders, $events, $plans, $coupons, $couponUsed, $couponUsedBy, $index, $time, $kind, $extra, $now, $eventKey);
        }

        // 4. 当天的流量
        demo_day_traffic($users, $events, $plans, $nodesByGroup, $statUser, $statServer, $dayStart, $dayEnd, $date, $isToday, $now);
    }

    // 订单按下单先后编号、生成订单号（佣金记录要用订单号）
    $orders = demo_order_rows($orders);

    // 5. 佣金：开通 3 天后由每 15 分钟一次的 check:commission 确认并发放（autoCheck + autoPayCommission）
    foreach ($orders as &$order) {
        if (!$order['invite_user_id'] || !in_array($order['status'], [3, 4], true)) continue;
        $due = $order['updated_at'] + 3 * DAY;
        $checkAt = (int)(ceil($due / 900) * 900);
        if ($checkAt > $now) continue;
        $order['commission_status'] = 2;
        $order['updated_at'] = $checkAt;
        if ($order['commission_balance'] > 0) {
            $order['actual_commission_balance'] = $order['commission_balance'];
            $users[$order['invite_user_id'] - 1]['commission_balance'] += $order['commission_balance'];
            $commissionLogs[] = [
                'invite_user_id' => $order['invite_user_id'], 'user_id' => $order['user_id'], 'trade_no' => $order['trade_no'],
                'order_amount' => $order['total_amount'], 'get_amount' => $order['commission_balance'],
                'created_at' => $checkAt, 'updated_at' => $checkAt,
            ];
        }
    }
    unset($order);

    // 6. 工单与回复
    [$ticketRows, $messageRows] = demo_tickets($users, $tickets, $today, $now, $warp);

    // 7. 邀请码：邀请过别人的用户各有一个
    $inviteCodes = demo_invite_codes($users, $now);

    return [
        'catalog' => $catalog,
        'tables' => [
            'v2_user' => demo_user_rows($users, $now),
            'v2_order' => $orders,
            'v2_commission_log' => demo_with_ids(demo_sort_by($commissionLogs, 'created_at')),
            'v2_ticket' => $ticketRows,
            'v2_ticket_message' => $messageRows,
            'v2_stat_user' => demo_with_ids(demo_sort_by(array_values($statUser), 'record_at')),
            'v2_stat_server' => demo_with_ids(demo_sort_by(array_values($statServer), 'record_at')),
            'v2_invite_code' => $inviteCodes,
        ],
        'stat_days' => range($start, $today - DAY, DAY),
        'summary' => [
            'start' => date('Y-m-d', $start), 'users' => count($users), 'orders' => count($orders),
            'paid_orders' => count(array_filter($orders, function ($o) { return in_array($o['status'], [3, 4], true); })),
            'stat_user' => count($statUser), 'stat_server' => count($statServer), 'tickets' => count($ticketRows),
            'commission_logs' => count($commissionLogs),
        ],
    ];
}

/**
 * 今天的时间映射。按平常的节奏，刚过 0 点时今天几乎还没有人注册、下单，仪表盘顶部是空的：
 * 取今天第 DEMO_TODAY_MIN_REG 个注册的时刻为支点，支点之前的事件按比例压缩到「现在 - 1 分钟」之前，
 * 支点之后的不变（仍在未来，不生成）。随着时间推移比例变成 1，就是原本的时刻。
 */
function demo_today_warp(int $today, int $now, array $todayOffsets): callable
{
    $pivot = $todayOffsets[min(count($todayOffsets), DEMO_TODAY_MIN_REG) - 1] ?? DAY;
    $elapsed = $now - $today - 60;
    $scale = $pivot > 0 && $elapsed < $pivot ? max(0.0, $elapsed / $pivot) : 1.0;
    return function (int $time) use ($today, $pivot, $scale): int {
        $offset = $time - $today;
        if ($scale >= 1.0 || $offset < 0 || $offset > $pivot) return $time;
        return $today + (int)floor($offset * $scale);
    };
}

/** 某天注册的时刻（距 0 点的秒数，从早到晚）：人数逐渐增长，周末多一些 */
function demo_day_registrations(string $date, int $k, int $days, int $dayStart): array
{
    $progress = $k / max(1, $days - 1);
    $weekend = in_array((int)date('N', $dayStart), [6, 7], true) ? 1.2 : 1.0;
    $count = max(2, (int)round((4.5 + 4.0 * $progress) * $weekend * (0.6 + 0.8 * rnd("reg-n:{$date}"))));
    $offsets = [];
    for ($i = 0; $i < $count; $i++) {
        $offset = diurnal_offset("reg-t:{$date}:{$i}");
        // 开站当天 9 点管理员才注册（id 1），用户从 9:30 开始
        if ($k === 0) $offset = 34200 + (int)($offset * (DAY - 34200) / DAY);
        $offsets[] = $offset;
    }
    sort($offsets);
    return $offsets;
}

function demo_new_user(string $key, int $id, int $createdAt, ?int $inviteUserId): array
{
    // 约 2% 的用户在注册一段时间后被封禁（之后不能下单，也没有流量）
    $bannedAt = $key !== 'admin' && rchance("{$key}:banned", 0.02) ? $createdAt + rint("{$key}:banned-at", 3 * DAY, 40 * DAY) : null;
    return [
        'key' => $key, 'id' => $id, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        'invite_user_id' => $inviteUserId, 'is_admin' => 0,
        // 使用习惯：每天用的概率、用量系数、偏好的节点
        'activity' => 0.25 + 0.65 * rnd("{$key}:activity"),
        'intensity' => 0.15 + 1.25 * rnd("{$key}:intensity"),
        'renew' => rnd("{$key}:renew"),
        // 套餐状态（与 v2_user 对应）
        'plan_id' => null, 'group_id' => null, 'transfer_enable' => 0, 'expired_at' => 0,
        'device_limit' => null, 'speed_limit' => null, 'u' => 0, 'd' => 0, 't' => 0,
        'plan_from' => null, 'reset_at' => null, 'has_valid_order' => false, 'last_period' => null,
        'commission_balance' => 0, 'banned_at' => $bannedAt, 'last_order_at' => null,
    ];
}

/** 一次购买：建订单，付款后按 OrderService::open 开通，并安排之后的事件（续费、重试） */
function demo_purchase(array &$users, array &$orders, array &$events, array $plans, array $coupons, array &$couponUsed,
                       array &$couponUsedBy, int $index, int $time, string $kind, $extra, int $now, ?string $eventKey): void
{
    $user = &$users[$index];
    // 随机数的键：事件自带的稳定键（首单、重试、续费、重置包各有来源），没有时按时刻
    $key = $eventKey ?? "{$user['key']}:order:{$kind}:" . date('YmdHis', $time);
    $valid = $user['expired_at'] === null || $user['expired_at'] > $time;
    if ($user['banned_at'] !== null && $user['banned_at'] <= $time) return;

    // 套餐与周期
    if ($kind === 'renew') {
        if (!$valid || !$user['plan_id']) return;
        $planId = $user['plan_id'];
        $period = rchance("{$key}:same", 0.8) && $user['last_period'] ? $user['last_period'] : demo_pick_period($plans[$planId], $key);
    } elseif ($kind === 'reset') {
        if (!$valid || !$user['plan_id'] || empty($plans[$user['plan_id']]['reset_price'])) return;
        $planId = $user['plan_id'];
        $period = 'reset_price';
    } else {
        // 新购（首单、过期后回来、付款失败后重试）：订阅还有效时不新购（否则会变成升级订单）
        if ($valid && $user['plan_id']) return;
        $planId = $extra['plan_id'] ?? (int)rweighted("{$key}:plan", $user['plan_id'] === 1 ? [2 => 70, 3 => 30] : [1 => 18, 2 => 52, 3 => 25, 4 => 5]);
        $period = $extra['period'] ?? demo_pick_period($plans[$planId], $key);
    }
    $plan = $plans[$planId];
    $price = (int)$plan[$period];

    // 订单类型（OrderService::setOrderType）
    if ($period === 'reset_price') $type = 4;
    elseif ($user['expired_at'] !== null && $user['expired_at'] > $time && $user['plan_id'] === $planId) $type = 2;
    else $type = 1;

    // 优惠券（约 10% 的订单）：下单时有效、适用于该套餐与周期、没有用完
    $couponId = null;
    $discount = null;
    if ($type !== 4 && rchance("{$key}:coupon", 0.1)) {
        $candidates = array_values(array_filter($coupons, function ($c) use ($time, $planId, $period, $couponUsed, $couponUsedBy, $index) {
            return $c['started_at'] <= $time && $c['ended_at'] > $time
                && (!$c['limit_plan_ids'] || in_array((string)$planId, $c['limit_plan_ids'], true))
                && (!$c['limit_period'] || in_array($period, $c['limit_period'], true))
                && (!$c['limit_use'] || ($couponUsed[$c['id']] ?? 0) < $c['limit_use'])
                && (!$c['limit_use_with_user'] || empty($couponUsedBy["{$c['id']}:{$index}"]));
        }));
        if ($candidates) {
            $coupon = rpick("{$key}:coupon-pick", $candidates);
            $couponId = $coupon['id'];
            // CouponService::use：1 按金额，2 按比例；不超过订单金额
            $discount = $coupon['type'] === 1 ? $coupon['value'] : (int)round($price * $coupon['value'] / 100);
            $discount = min($discount, $price);
            $couponUsed[$couponId] = ($couponUsed[$couponId] ?? 0) + 1;
            $couponUsedBy["{$couponId}:{$index}"] = true;
        }
    }
    $total = $price - (int)$discount;

    // 邀请佣金（OrderService::setInvite）：金额为 0 时不记邀请人；默认只有首个有效订单有佣金
    $inviteUserId = $user['invite_user_id'] && $total > 0 ? $user['invite_user_id'] : null;
    $commission = $inviteUserId && !$user['has_valid_order'] ? (int)round($total * DEMO_INVITE_COMMISSION / 100) : 0;

    // 付款：约 85% 付款（续费、重置流量更高），30 秒到 10 分钟内完成，1–5 秒后开通
    $payRate = ['first' => 0.8, 'retry' => 0.85, 'return' => 0.85, 'renew' => 0.93, 'reset' => 0.9][$kind];
    $paidAt = rchance("{$key}:pay", $payRate) ? $time + rint("{$key}:pay-delay", 30, 600) : null;
    if ($paidAt !== null && $paidAt > $now) $paidAt = null;

    $order = [
        'invite_user_id' => $inviteUserId, 'user_id' => $user['id'], 'plan_id' => $planId, 'coupon_id' => $couponId,
        'payment_id' => 1, 'type' => $type, 'period' => $period, 'trade_no' => null, 'callback_no' => null,
        'total_amount' => $total, 'handling_amount' => null, 'discount_amount' => $discount, 'surplus_amount' => null,
        'refund_amount' => null, 'balance_amount' => null, 'surplus_order_ids' => null,
        'status' => 0, 'commission_status' => 0, 'commission_balance' => $commission, 'actual_commission_balance' => null,
        'paid_at' => null, 'created_at' => $time, 'updated_at' => $time, 'key' => $key,
    ];
    $user['last_order_at'] = $time;

    if ($paidAt === null) {
        // 未付款：2 小时后由 check:order（每分钟一次）取消；还没到 2 小时的保持待支付
        $cancelAt = $time + 7200 + rint("{$key}:cancel", 0, 59);
        if ($cancelAt <= $now) {
            $order['status'] = 2;
            $order['updated_at'] = $cancelAt;
        }
        $orders[] = $order;
        // 一部分人过一会儿重新下单
        if ($kind !== 'reset' && rchance("{$key}:retry", 0.55)) {
            $events[] = [$time + rint("{$key}:retry-delay", 300, 3600), $index, $kind === 'renew' ? 'renew' : 'retry',
                ['plan_id' => $planId, 'period' => $period], false, "{$key}:retry"];
        }
        return;
    }

    // 开通（OrderService::open）
    $openAt = $paidAt + rint("{$key}:open", 1, 5);
    $order['status'] = 3;
    $order['paid_at'] = $paidAt;
    $order['updated_at'] = $openAt;
    $order['callback_no'] = 'CB' . sprintf('%010d', rint("{$key}:cb", 1, 2147483647));
    $orders[] = $order;
    $user['has_valid_order'] = true;
    $user['updated_at'] = max($user['updated_at'], $openAt);

    if ($period === 'onetime_price') {
        // buyByOneTime：原来也是一次性套餐时，剩余流量累加到新套餐
        $transfer = $plan['transfer_enable'] * GB;
        if ($user['expired_at'] === null) $transfer += max(0, $user['transfer_enable'] - ($user['u'] + $user['d']));
        $user['u'] = $user['d'] = 0;
        $user['reset_at'] = $openAt;
        $user['transfer_enable'] = $transfer;
        $user['device_limit'] = $plan['device_limit'];
        $user['plan_id'] = $planId;
        $user['group_id'] = $plan['group_id'];
        $user['expired_at'] = null;
        $user['plan_from'] = $openAt;
    } elseif ($period === 'reset_price') {
        $user['u'] = $user['d'] = 0;
        $user['reset_at'] = $openAt;
    } else {
        // buyByPeriod：新购或从一次性转为周期时清零流量；续费在到期日之前完成，不清零
        $user['transfer_enable'] = $plan['transfer_enable'] * GB;
        $user['device_limit'] = $plan['device_limit'];
        if ($user['expired_at'] === null || $type === 1) {
            $user['u'] = $user['d'] = 0;
            $user['reset_at'] = $openAt;
            $user['plan_from'] = $openAt;
        }
        $user['plan_id'] = $planId;
        $user['group_id'] = $plan['group_id'];
        $user['expired_at'] = add_period($period, (int)$user['expired_at'], $openAt);
        $user['last_period'] = $period;
        // 续费：可续费的套餐按用户习惯在到期前 1–3 天续；不续的一部分人过期后回来重新买
        $expiry = $user['expired_at'];
        $cycleKey = "{$user['key']}:cycle:" . date('Ymd', $expiry);
        if ($plan['renew'] && $user['renew'] < 0.7 && rchance("{$cycleKey}:renew", 0.9)) {
            $renewAt = day_start($expiry) - rint("{$cycleKey}:renew-day", 1, 3) * DAY + diurnal_offset("{$cycleKey}:renew-t");
            if ($renewAt > $openAt) $events[] = [$renewAt, $index, 'renew', null, false, "{$cycleKey}:renew-order"];
        } elseif (rchance("{$cycleKey}:return", $planId === 1 ? 0.55 : 0.25)) {
            $events[] = [$expiry + rint("{$cycleKey}:return-delay", 3600, 20 * DAY), $index, 'return', null, false, "{$cycleKey}:return-order"];
        }
    }
    $user['speed_limit'] = $plan['speed_limit'];
    unset($user);
}

function demo_pick_period(array $plan, string $key): string
{
    $weights = ['month_price' => 50, 'quarter_price' => 20, 'half_year_price' => 12, 'year_price' => 12,
        'two_year_price' => 4, 'three_year_price' => 2, 'onetime_price' => 100];
    $available = [];
    foreach ($weights as $period => $weight) if (isset($plan[$period]) && $plan[$period] !== null) $available[$period] = $weight;
    return (string)rweighted("{$key}:period", $available);
}

/**
 * 一天的流量：订阅有效、没被封禁的用户按习惯决定今天用不用、用多少，分到权限组里的 1–3 个节点上。
 *   - v2_stat_user：原始流量，按（用户、节点倍率、日期）汇总（StatUserJob）
 *   - v2_stat_server：原始流量，按（节点、日期）汇总（StatServerJob）
 *   - v2_user.u / d：原始流量 × 倍率（TrafficFetchJob）；不超过流量上限（用完就停）
 * 原始流量取偶数，乘 1.5 倍率后仍是整数，已用流量与统计行可以精确对上。
 */
function demo_day_traffic(array &$users, array &$events, array $plans, array $nodesByGroup, array &$statUser, array &$statServer,
                          int $dayStart, int $dayEnd, string $date, bool $isToday, int $now): void
{
    foreach ($users as $index => &$user) {
        if (!$user['plan_id'] || $user['is_admin']) continue;
        $from = max($dayStart, (int)$user['plan_from']);
        $to = $dayEnd;
        if ($user['expired_at'] !== null) $to = min($to, $user['expired_at']);
        if ($user['banned_at'] !== null) $to = min($to, $user['banned_at']);
        if ($to - $from < 600) continue;
        $key = "{$user['key']}:traffic:{$date}";
        if (!rchance("{$key}:active", $user['activity'])) continue;
        $remaining = $user['transfer_enable'] - ($user['u'] + $user['d']);
        if ($remaining <= 0) continue;

        $plan = $plans[$user['plan_id']];
        $perDay = $plan['transfer_enable'] * GB / ($user['expired_at'] === null ? 60 : 30);
        $volume = $perDay * $user['intensity'] * (0.4 + 1.2 * rnd("{$key}:volume")) * (($to - $from) / DAY);
        $nodes = $nodesByGroup[$user['group_id']];
        if (!$nodes) continue;
        // 每个用户固定偏好 2 个节点，偶尔用第 3 个
        $favorites = [];
        foreach ($nodes as $i => $node) $favorites[$i] = rnd("{$user['key']}:node:{$node[0]}{$node[1]}");
        asort($favorites);
        $use = array_slice(array_keys($favorites), 0, rchance("{$key}:third", 0.2) ? 3 : min(2, count($nodes)));
        if (rchance("{$key}:single", 0.4)) $use = [$use[0]];

        $parts = [];
        $charged = 0;
        foreach ($use as $j => $nodeIndex) {
            $node = $nodes[$nodeIndex];
            $share = $j === 0 ? 0.6 + 0.4 * rnd("{$key}:share") : 0.1 + 0.3 * rnd("{$key}:share:{$j}");
            $raw = (int)($volume * $share);
            $parts[] = [$node, $raw];
            $charged += $raw * $node[2];
        }
        // 不超过剩余流量：按比例缩小
        $ratio = $charged > $remaining ? $remaining / $charged : 1.0;
        $last = $from + rint("{$key}:last", (int)(($to - $from) * 0.5), $to - $from);
        $first = $from + rint("{$key}:first", 0, max(0, $last - $from));
        foreach ($parts as $j => [$node, $raw]) {
            $raw = (int)($raw * $ratio);
            $raw -= $raw % 2;
            if ($raw < 2 * 1024 * 1024) continue;
            $up = (int)($raw * (0.08 + 0.12 * rnd("{$key}:up:{$j}")));
            $up -= $up % 2;
            $down = $raw - $up;
            [$type, $nodeId, $rate] = $node;
            $user['u'] += (int)($up * $rate);
            $user['d'] += (int)($down * $rate);

            $rateText = number_format($rate, 2, '.', '');
            $userKey = "{$user['id']}:{$rateText}:{$dayStart}";
            if (!isset($statUser[$userKey])) {
                $statUser[$userKey] = ['user_id' => $user['id'], 'server_rate' => $rateText, 'u' => 0, 'd' => 0,
                    'record_type' => 'd', 'record_at' => $dayStart, 'created_at' => $first, 'updated_at' => $last];
            }
            $statUser[$userKey]['u'] += $up;
            $statUser[$userKey]['d'] += $down;
            $statUser[$userKey]['created_at'] = min($statUser[$userKey]['created_at'], $first);
            $statUser[$userKey]['updated_at'] = max($statUser[$userKey]['updated_at'], $last);

            $serverKey = "{$type}:{$nodeId}:{$dayStart}";
            if (!isset($statServer[$serverKey])) {
                $statServer[$serverKey] = ['server_id' => $nodeId, 'server_type' => $type, 'u' => 0, 'd' => 0,
                    'record_type' => 'd', 'record_at' => $dayStart, 'created_at' => $first, 'updated_at' => $last];
            }
            $statServer[$serverKey]['u'] += $up;
            $statServer[$serverKey]['d'] += $down;
            $statServer[$serverKey]['created_at'] = min($statServer[$serverKey]['created_at'], $first);
            $statServer[$serverKey]['updated_at'] = max($statServer[$serverKey]['updated_at'], $last);
        }
        $user['t'] = max($user['t'], $last);
        $user['updated_at'] = max($user['updated_at'], $last);

        // 流量快用完：有重置包的套餐，一部分人第二天买重置包
        if (!$isToday && $user['transfer_enable'] - ($user['u'] + $user['d']) < $user['transfer_enable'] * 0.03
            && !empty($plan['reset_price']) && rchance("{$key}:reset", 0.45)) {
            $events[] = [$dayStart + DAY + diurnal_offset("{$key}:reset-t"), $index, 'reset', null, false, "{$key}:reset-order"];
        }
    }
    unset($user);
}

/** 工单：用户开单，管理员在工作时间（9–23 点）回复；最后一条是管理员回复且 24 小时没动静的，由 check:ticket 关闭 */
function demo_tickets(array $users, array $tickets, int $today, int $now, callable $warp): array
{
    $subjects = ['无法连接节点', '如何修改密码', '申请提现', '订阅导入失败', '续费后流量没有重置', '发票问题',
        '速度很慢', '想换一个套餐', '支付成功但没有开通', '设备数量不够用', '客户端闪退', '账号被封禁申诉'];
    $admin = $users[0];
    // 每天另有 2–3 个新工单（保证任何时候都可能有待处理的）
    for ($day = $today - 20 * DAY; $day <= $today; $day += DAY) {
        $date = date('Y-m-d', $day);
        for ($i = 0; $i < 2 + rint("tickets:{$date}", 0, 1); $i++) {
            $at = $day + diurnal_offset("tickets:{$date}:{$i}:t");
            if ($day === $today) $at = $warp($at);
            $candidates = array_values(array_filter($users, function ($u) use ($at) {
                return !$u['is_admin'] && $u['created_at'] < $at - 3600 && $u['plan_id'];
            }));
            if (!$candidates) continue;
            $tickets[] = ['user' => rpick("tickets:{$date}:{$i}:u", $candidates)['id'] - 1, 'at' => $at,
                'key' => "tickets:{$date}:{$i}", 'actual' => true];
        }
    }
    // 用户自己开的工单按原本的时刻安排，落在今天的要映射（映射保持先后，工单仍晚于注册）
    foreach ($tickets as &$ticket) {
        if (empty($ticket['actual']) && $ticket['at'] >= $today) $ticket['at'] = $warp($ticket['at']);
    }
    unset($ticket);
    $tickets = array_values(array_filter($tickets, function ($t) use ($now) { return $t['at'] <= $now - 60; }));
    usort($tickets, function ($a, $b) { return $a['at'] <=> $b['at']; });

    $rows = [];
    $messages = [];
    foreach ($tickets as $i => $ticket) {
        $user = $users[$ticket['user']];
        $key = $ticket['key'];
        $subject = rpick("{$key}:subject", $subjects);
        $id = $i + 1;
        $status = 0;
        $replyStatus = 0;
        $updatedAt = $ticket['at'];
        $messages[] = ['user_id' => $user['id'], 'ticket_id' => $id, 'message' => "您好，{$subject}，麻烦帮忙看一下，谢谢！",
            'created_at' => $ticket['at'], 'updated_at' => $ticket['at']];
        // 管理员回复，之后约 30% 的人追问一次，管理员再回复
        $at = $ticket['at'];
        for ($round = 0; $round < 2; $round++) {
            $replyAt = demo_working_time($at + rint("{$key}:reply:{$round}", 20 * 60, 6 * 3600), "{$key}:reply:{$round}");
            if ($replyAt > $now) break;
            $messages[] = ['user_id' => $admin['id'], 'ticket_id' => $id,
                'message' => $round === 0 ? '您好，已经为您处理，请重新更新订阅后再试。' : '已经为您核实并处理完毕，如还有问题请继续回复。',
                'created_at' => $replyAt, 'updated_at' => $replyAt];
            $replyStatus = 1;
            $updatedAt = $replyAt;
            if ($round === 1 || !rchance("{$key}:follow", 0.3)) break;
            $followAt = $replyAt + rint("{$key}:follow-at", 3600, 20 * 3600);
            if ($followAt > $now) break;
            $messages[] = ['user_id' => $user['id'], 'ticket_id' => $id, 'message' => '好的，还是有点问题，能再帮我看看吗？',
                'created_at' => $followAt, 'updated_at' => $followAt];
            $replyStatus = 0;
            $updatedAt = $followAt;
            $at = $followAt;
        }
        if ($replyStatus === 1 && $updatedAt + DAY + 59 <= $now) {
            $status = 1;
            $updatedAt = $updatedAt + DAY + rint("{$key}:close", 0, 59);
        }
        $rows[] = ['id' => $id, 'user_id' => $user['id'], 'subject' => $subject, 'level' => rint("{$key}:level", 0, 2),
            'status' => $status, 'reply_status' => $replyStatus, 'created_at' => $ticket['at'], 'updated_at' => $updatedAt];
    }
    usort($messages, function ($a, $b) { return $a['created_at'] <=> $b['created_at'] ?: $a['ticket_id'] <=> $b['ticket_id']; });
    return [$rows, demo_with_ids($messages)];
}

/** 管理员的工作时间：9–23 点之外的回复顺延到次日 9 点后 */
function demo_working_time(int $time, string $key): int
{
    $hour = (int)date('G', $time);
    if ($hour >= 9 && $hour < 23) return $time;
    $next = day_start($time) + ($hour >= 23 ? DAY : 0) + 9 * 3600;
    return $next + rint("{$key}:morning", 0, 90 * 60);
}

function demo_invite_codes(array $users, int $now): array
{
    $invitees = [];
    foreach ($users as $user) if ($user['invite_user_id']) $invitees[$user['invite_user_id']][] = $user['created_at'];
    $rows = [];
    foreach ($invitees as $inviterId => $times) {
        $inviter = $users[$inviterId - 1];
        $createdAt = max($inviter['created_at'] + 60, min($times) - rint("invite:{$inviterId}", 3600, 2 * DAY));
        $createdAt = min($createdAt, min($times) - 60);
        $rows[] = ['user_id' => $inviterId, 'code' => strtolower(fake_code("invite:{$inviterId}", 8)), 'status' => 0,
            'pv' => count($times) * rint("invite:{$inviterId}:pv", 2, 8), 'created_at' => $createdAt, 'updated_at' => $createdAt];
    }
    return demo_with_ids(demo_sort_by($rows, 'created_at'));
}

function demo_user_rows(array $users, int $now): array
{
    $password = fixed_bcrypt('user123456', 'demo-users');
    $adminPassword = fixed_bcrypt((string)demo_env('V2B_ADMIN_PASSWORD', 'admin123456'), 'demo-admin');
    $domains = ['example.com', 'example.net', 'example.org'];
    $rows = [];
    foreach ($users as $user) {
        $key = $user['key'];
        $isAdmin = (bool)$user['is_admin'];
        $banned = $user['banned_at'] !== null && $user['banned_at'] <= $now;
        if ($banned) $user['updated_at'] = max($user['updated_at'], $user['banned_at']);
        $email = $isAdmin ? (string)demo_env('V2B_ADMIN_EMAIL', 'admin@example.com')
            : sprintf('user%03d@%s', $user['id'], $domains[$user['id'] % 3]);
        $lastActive = max($user['t'], (int)$user['last_order_at'], $user['created_at']);
        $lastLoginAt = $isAdmin ? $now - rint('admin:login', 600, 7200)
            : min($now, max($user['created_at'] + 60, $lastActive - rint("{$key}:login", 0, 3 * DAY)));
        $rows[] = [
            'id' => $user['id'], 'invite_user_id' => $user['invite_user_id'],
            'telegram_id' => !$isAdmin && rchance("{$key}:tg", 0.1) ? rint("{$key}:tg-id", 100000000, 2000000000) : null,
            'email' => $email, 'password' => $isAdmin ? $adminPassword : $password,
            'password_algo' => null, 'password_salt' => null,
            'balance' => !$isAdmin && rchance("{$key}:balance", 0.06) ? rint("{$key}:balance-n", 1, 100) * 100 : 0,
            'discount' => null, 'commission_type' => 0, 'commission_rate' => null,
            'commission_balance' => $user['commission_balance'],
            't' => $user['t'], 'u' => $user['u'], 'd' => $user['d'], 'transfer_enable' => $user['transfer_enable'],
            'device_limit' => $user['device_limit'], 'banned' => $banned ? 1 : 0,
            'is_admin' => $isAdmin ? 1 : 0, 'last_login_at' => $lastLoginAt,
            'is_staff' => !$isAdmin && $user['id'] === 5 ? 1 : 0,
            'last_login_ip' => null,   // 后端从不写这一列
            'uuid' => fake_guid("{$key}:uuid", true), 'group_id' => $user['group_id'], 'plan_id' => $user['plan_id'],
            'speed_limit' => $user['speed_limit'], 'auto_renewal' => 0, 'remind_expire' => 1, 'remind_traffic' => 1,
            'token' => fake_guid("{$key}:token"), 'expired_at' => $user['expired_at'],
            'remarks' => !$isAdmin && rchance("{$key}:remarks", 0.05) ? rpick("{$key}:remarks-v", ['老用户', '代理商', '测试账号', '已联系续费']) : null,
            'created_at' => $user['created_at'], 'updated_at' => max($user['updated_at'], $user['created_at']),
        ];
    }
    return $rows;
}

function demo_order_rows(array $orders): array
{
    $orders = demo_sort_by($orders, 'created_at');
    $rows = [];
    foreach ($orders as $i => $order) {
        $id = $i + 1;
        $order['id'] = $id;
        $order['trade_no'] = date('YmdHis', $order['created_at']) . sprintf('%06d', $id) . rint("{$order['key']}:trade", 10000, 99999);
        unset($order['key']);
        $rows[] = ['id' => $id] + $order;
    }
    return $rows;
}

function demo_sort_by(array $rows, string $column): array
{
    usort($rows, function ($a, $b) use ($column) { return $a[$column] <=> $b[$column]; });
    return $rows;
}

function demo_with_ids(array $rows): array
{
    foreach ($rows as $i => &$row) $row = ['id' => $i + 1] + $row;
    unset($row);
    return $rows;
}
