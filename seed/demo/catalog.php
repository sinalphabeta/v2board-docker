<?php
// 演示站的固定部分：权限组、路由、套餐、节点、支付方式、优惠券、礼品卡、公告、知识库。
// 与原来的 seed.php 相同的内容；随机的密钥、编号改成由键决定，时间相对「开站日」$start 与「现在」$now。
// 返回 [模型类 => [行, ...]]，行里带固定的 id，由 reset.php 按模型写入（数组字段由模型的 casts 转成 JSON）。

/** x25519 密钥对（Reality 用），由键决定 */
function demo_x25519(string $key): array
{
    $private = hash('sha256', "x25519:{$key}", true);
    $public = sodium_crypto_scalarmult_base($private);
    $b64 = function ($bytes) { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); };
    return [$b64($private), $b64($public)];
}

/** 节点的权限组 / 路由 id 按后台表单提交后的样子存成字符串数组（原版节点列表按字符串匹配权限组筛选） */
function demo_ids(...$values): array
{
    return array_map('strval', $values);
}

function demo_catalog(int $start, int $now): array
{
    $at = $start + 8 * 3600;   // 开站当天上午配置好
    $ts = ['created_at' => $at, 'updated_at' => $at];

    $groups = [];
    foreach (['默认组', '高级组', '专线组'] as $i => $name) $groups[] = ['id' => $i + 1, 'name' => $name] + $ts;

    $routes = [
        ['id' => 1, 'remarks' => '屏蔽广告', 'match' => json_encode(['geosite:category-ads-all']), 'action' => 'block', 'action_value' => null],
        ['id' => 2, 'remarks' => '禁止 BT 下载', 'match' => json_encode(['bittorrent']), 'action' => 'protocol', 'action_value' => null],
        ['id' => 3, 'remarks' => 'Google 走 DNS', 'match' => json_encode(['domain:google.com', 'domain:youtube.com']),
         'action' => 'dns', 'action_value' => '8.8.8.8'],
    ];
    foreach ($routes as &$route) $route += $ts;
    unset($route);

    $plans = [
        ['id' => 1, 'name' => '体验套餐', 'group_id' => 1, 'transfer_enable' => 10, 'device_limit' => 1, 'speed_limit' => null,
         'show' => 1, 'renew' => 0, 'sort' => 1, 'content' => '<p>适合初次体验，10GB 流量，1 台设备。</p>',
         'month_price' => 100],
        ['id' => 2, 'name' => '基础套餐', 'group_id' => 1, 'transfer_enable' => 100, 'device_limit' => 3, 'speed_limit' => null,
         'show' => 1, 'renew' => 1, 'sort' => 2,
         'content' => json_encode([
             ['feature' => '每月 100GB 流量', 'support' => true],
             ['feature' => '最多 3 台设备同时在线', 'support' => true],
             ['feature' => '流媒体解锁', 'support' => false],
         ], JSON_UNESCAPED_UNICODE),
         'month_price' => 1500, 'quarter_price' => 4200, 'half_year_price' => 8000, 'year_price' => 15000,
         'reset_price' => 500],
        ['id' => 3, 'name' => '高级套餐', 'group_id' => 2, 'transfer_enable' => 300, 'device_limit' => 5, 'speed_limit' => 500,
         'show' => 1, 'renew' => 1, 'sort' => 3,
         'content' => json_encode([
             ['feature' => '每月 300GB 流量', 'support' => true],
             ['feature' => '最多 5 台设备同时在线', 'support' => true],
             ['feature' => '流媒体解锁', 'support' => true],
         ], JSON_UNESCAPED_UNICODE),
         'month_price' => 3000, 'quarter_price' => 8500, 'half_year_price' => 16000, 'year_price' => 30000,
         'two_year_price' => 55000, 'three_year_price' => 78000, 'reset_price' => 1000, 'capacity_limit' => 200],
        ['id' => 4, 'name' => '专线不限时', 'group_id' => 3, 'transfer_enable' => 500, 'device_limit' => null, 'speed_limit' => null,
         'show' => 0, 'renew' => 0, 'sort' => 4, 'content' => '<p>一次性购买，500GB 流量不限时。</p>',
         'onetime_price' => 19900, 'reset_traffic_method' => 2],
    ];
    foreach ($plans as &$plan) $plan += $ts;
    unset($plan);

    [$realityPrivate, $realityPublic] = demo_x25519('vless-1');
    [$v2nodePrivate, $v2nodePublic] = demo_x25519('v2node-1');
    $paddingScheme = ['stop=8', '0=30-30', '1=100-400', '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
        '3=9-9,500-1000', '4=500-1000', '5=500-1000', '6=500-1000', '7=500-1000'];

    $servers = [
        App\Models\ServerShadowsocks::class => [
            ['id' => 1, 'group_id' => demo_ids(1, 2), 'route_id' => demo_ids(1), 'parent_id' => null, 'tags' => ['香港', '流媒体'],
             'name' => '香港 01', 'rate' => '1', 'host' => 'hk01.example.com', 'port' => '443', 'server_port' => 443,
             'cipher' => '2022-blake3-aes-128-gcm', 'obfs' => null, 'obfs_settings' => null, 'show' => 1, 'sort' => 1],
            ['id' => 2, 'group_id' => demo_ids(1), 'route_id' => null, 'parent_id' => 1, 'tags' => ['香港', '中转'],
             'name' => '香港 01 中转', 'rate' => '1.5', 'host' => 'relay-hk.example.com', 'port' => '10443', 'server_port' => 443,
             'cipher' => '2022-blake3-aes-128-gcm', 'obfs' => null, 'obfs_settings' => null, 'show' => 1, 'sort' => 2],
        ],
        App\Models\ServerVmess::class => [
            ['id' => 1, 'group_id' => demo_ids(1), 'route_id' => demo_ids(1, 2), 'name' => '日本 01', 'parent_id' => null,
             'host' => 'jp01.example.com', 'port' => '443', 'server_port' => 10086, 'tls' => 1, 'tags' => ['日本'],
             'rate' => '1', 'network' => 'ws',
             'networkSettings' => ['path' => '/v2ray', 'headers' => ['Host' => 'jp01.example.com']],
             'tlsSettings' => ['serverName' => 'jp01.example.com', 'allowInsecure' => 0],
             'ruleSettings' => null, 'dnsSettings' => null, 'show' => 1, 'sort' => 3],
        ],
        App\Models\ServerTrojan::class => [
            ['id' => 1, 'group_id' => demo_ids(1, 2), 'route_id' => null, 'parent_id' => null, 'tags' => ['新加坡'],
             'name' => '新加坡 01', 'rate' => '1', 'host' => 'sg01.example.com', 'port' => '443', 'server_port' => 443,
             'network' => 'tcp', 'network_settings' => null, 'allow_insecure' => 0, 'server_name' => 'sg01.example.com',
             'show' => 1, 'sort' => 4],
        ],
        App\Models\ServerVless::class => [
            ['id' => 1, 'group_id' => demo_ids(2), 'route_id' => null, 'name' => '美国 01 Reality', 'parent_id' => null,
             'host' => 'us01.example.com', 'port' => 443, 'server_port' => 443, 'tls' => 2,
             'tls_settings' => [
                 'server_name' => 'www.microsoft.com', 'server_port' => '443', 'xver' => 0,
                 'private_key' => $realityPrivate, 'public_key' => $realityPublic,
                 'short_id' => substr(sha1('short-id:vless-1'), 0, 8), 'fingerprint' => 'chrome', 'allow_insecure' => '0',
             ],
             'flow' => 'xtls-rprx-vision', 'network' => 'tcp', 'network_settings' => null,
             'encryption' => null, 'encryption_settings' => null, 'tags' => ['美国', 'Reality'],
             'rate' => '1.5', 'show' => 1, 'sort' => 5],
        ],
        App\Models\ServerHysteria::class => [
            ['id' => 1, 'version' => 2, 'group_id' => demo_ids(2), 'route_id' => null, 'name' => '香港 Hysteria2', 'parent_id' => null,
             'host' => 'hk-hy2.example.com', 'port' => '443', 'server_port' => 443, 'tags' => ['香港'], 'rate' => '1',
             'show' => 1, 'sort' => 6, 'up_mbps' => 100, 'down_mbps' => 500, 'obfs' => 'salamander',
             'obfs_password' => substr(sha1('obfs:hysteria-1'), 0, 16), 'server_name' => 'hk-hy2.example.com', 'insecure' => 0],
        ],
        App\Models\ServerTuic::class => [
            ['id' => 1, 'group_id' => demo_ids(3), 'route_id' => null, 'name' => '台湾 TUIC', 'parent_id' => null,
             'host' => 'tw.example.com', 'port' => '443', 'server_port' => 443, 'tags' => ['台湾'], 'rate' => '1',
             'show' => 0, 'sort' => 7, 'server_name' => 'tw.example.com', 'insecure' => 0, 'disable_sni' => 0,
             'udp_relay_mode' => 'native', 'zero_rtt_handshake' => 0, 'congestion_control' => 'bbr'],
        ],
        App\Models\ServerAnytls::class => [
            ['id' => 1, 'group_id' => demo_ids(3), 'route_id' => null, 'name' => '韩国 AnyTLS', 'parent_id' => null,
             'host' => 'kr.example.com', 'port' => '443', 'server_port' => 443, 'tags' => ['韩国'], 'rate' => '1',
             'show' => 1, 'sort' => 8, 'server_name' => 'kr.example.com', 'insecure' => 0, 'padding_scheme' => $paddingScheme],
        ],
        App\Models\ServerV2node::class => [
            ['id' => 1, 'group_id' => demo_ids(1, 2, 3), 'route_id' => demo_ids(1), 'name' => '德国 v2node', 'parent_id' => null,
             'host' => 'de.example.com', 'listen_ip' => '0.0.0.0', 'port' => '443', 'server_port' => 443,
             'tags' => ['德国'], 'rate' => '2', 'show' => 1, 'sort' => 9, 'protocol' => 'vless', 'tls' => 2,
             'tls_settings' => [
                 'server_name' => 'www.apple.com', 'server_port' => '443', 'xver' => 0,
                 'private_key' => $v2nodePrivate, 'public_key' => $v2nodePublic,
                 'short_id' => substr(sha1('short-id:v2node-1'), 0, 8), 'fingerprint' => 'chrome', 'allow_insecure' => '0',
             ],
             'flow' => 'xtls-rprx-vision', 'network' => 'tcp', 'network_settings' => null,
             'trusted_x_forwarded_for' => null, 'encryption' => null, 'encryption_settings' => null,
             'disable_sni' => 0, 'udp_relay_mode' => null, 'zero_rtt_handshake' => 0, 'congestion_control' => null,
             'cipher' => null, 'up_mbps' => 0, 'down_mbps' => 0, 'obfs' => null, 'obfs_password' => null,
             'padding_scheme' => null],
        ],
    ];
    foreach ($servers as &$rows) {
        foreach ($rows as &$row) $row += $ts;
        unset($row);
    }
    unset($rows);

    $payments = [
        ['id' => 1, 'uuid' => substr(sha1('payment-1'), 0, 8), 'payment' => 'EPay', 'name' => '支付宝', 'icon' => null,
         'config' => ['url' => 'https://pay.example.com', 'pid' => '1001', 'key' => 'demo-epay-key', 'type' => 'alipay'],
         'notify_domain' => null, 'handling_fee_fixed' => null, 'handling_fee_percent' => null, 'enable' => 1, 'sort' => 1],
        ['id' => 2, 'uuid' => substr(sha1('payment-2'), 0, 8), 'payment' => 'EPay', 'name' => '微信支付', 'icon' => null,
         'config' => ['url' => 'https://pay.example.com', 'pid' => '1001', 'key' => 'demo-epay-key', 'type' => 'wxpay'],
         'notify_domain' => null, 'handling_fee_fixed' => null, 'handling_fee_percent' => '1.00', 'enable' => 0, 'sort' => 2],
    ];
    foreach ($payments as &$payment) $payment += $ts;
    unset($payment);

    // 优惠券：生效时间分布在整个窗口里（订单用到的优惠券在下单时有效），最后两张已过期
    $coupons = [];
    for ($i = 1; $i <= 12; $i++) {
        $isAmount = $i % 2 === 1;
        $startedAt = day_start($now) - rint("coupon:{$i}:start", 10, 100) * DAY;
        $coupons[] = [
            'id' => $i,
            'code' => fake_code("coupon:{$i}", 8),
            'name' => $isAmount ? sprintf('立减 %d 元', $i * 2) : sprintf('%d 折优惠', 10 - ($i % 5) - 1),
            'type' => $isAmount ? 1 : 2,
            'value' => $isAmount ? $i * 200 : ($i % 5 + 1) * 10,
            'show' => rchance("coupon:{$i}:show", 0.7) ? 1 : 0,
            'limit_use' => rchance("coupon:{$i}:limit", 0.5) ? rint("coupon:{$i}:limit-n", 10, 200) : null,
            'limit_use_with_user' => rchance("coupon:{$i}:per-user", 0.5) ? 1 : null,
            'limit_plan_ids' => rchance("coupon:{$i}:plans", 0.3) ? ['2', '3'] : null,
            'limit_period' => rchance("coupon:{$i}:period", 0.3) ? ['month_price', 'year_price'] : null,
            'started_at' => $startedAt,
            'ended_at' => $i > 10 ? day_start($now) - rint("coupon:{$i}:end", 1, 5) * DAY : day_start($now) + rint("coupon:{$i}:end", 10, 90) * DAY,
            'created_at' => $startedAt - DAY,
            'updated_at' => $startedAt - DAY,
        ];
    }

    $giftcards = [];
    $giftcardDefs = [
        ['name' => '余额 50 元', 'type' => 1, 'value' => 5000, 'plan_id' => null],
        ['name' => '时长 30 天', 'type' => 2, 'value' => 30, 'plan_id' => null],
        ['name' => '流量 100GB', 'type' => 3, 'value' => 100, 'plan_id' => null],
        ['name' => '重置流量', 'type' => 4, 'value' => null, 'plan_id' => null],
        ['name' => '基础套餐 90 天', 'type' => 5, 'value' => 90, 'plan_id' => 2],
        ['name' => '高级套餐 永久', 'type' => 5, 'value' => 0, 'plan_id' => 3],
    ];
    foreach ($giftcardDefs as $i => $def) {
        $giftcards[] = $def + [
            'id' => $i + 1,
            'code' => fake_code('giftcard:' . ($i + 1), 16),
            'limit_use' => $i % 2 ? 10 : 1,
            'used_user_ids' => null,
            'started_at' => day_start($now) - 7 * DAY,
            'ended_at' => day_start($now) + 60 * DAY,
            'created_at' => day_start($now) - 8 * DAY,
            'updated_at' => day_start($now) - 8 * DAY,
        ];
    }

    $notices = [];
    $noticeDefs = [
        ['title' => '欢迎使用 V2Board', 'content' => '<p>感谢您的使用，如有问题请提交工单。</p>', 'tags' => ['公告'], 'show' => 1, 'img_url' => null],
        ['title' => '国庆节限时优惠', 'content' => '<p>国庆期间全场套餐 8 折，优惠码见活动页。</p>', 'tags' => ['活动', '优惠'], 'show' => 1,
         'img_url' => 'https://example.com/images/national-day.png'],
        ['title' => '节点维护通知', 'content' => '<p>本周六凌晨 2:00-4:00 对日本节点进行维护。</p>', 'tags' => ['维护'], 'show' => 0, 'img_url' => null],
        ['title' => '客户端更新提醒', 'content' => '<p>请将客户端更新到最新版本以获得更好的体验。</p>', 'tags' => null, 'show' => 1, 'img_url' => null],
    ];
    foreach ($noticeDefs as $i => $def) {
        $noticeAt = $i === 0 ? $at : day_start($now) - (count($noticeDefs) - $i) * 3 * DAY + 10 * 3600;
        $notices[] = ['id' => $i + 1] + $def + ['created_at' => $noticeAt, 'updated_at' => $noticeAt];
    }

    $knowledge = [];
    $articles = [
        ['zh-CN', '使用教程', 'Windows 使用教程'], ['zh-CN', '使用教程', 'macOS 使用教程'],
        ['zh-CN', '使用教程', 'iOS 使用教程'], ['zh-CN', '使用教程', 'Android 使用教程'],
        ['zh-CN', '常见问题', '如何更换订阅地址'], ['zh-CN', '常见问题', '无法连接怎么办'],
        ['en-US', 'Tutorial', 'Getting started'], ['en-US', 'FAQ', 'Frequently asked questions'],
    ];
    foreach ($articles as $i => [$language, $category, $title]) {
        $articleAt = $at + ($i + 1) * 1800;
        $knowledge[] = [
            'id' => $i + 1, 'language' => $language, 'category' => $category, 'title' => $title,
            'body' => "## {$title}\n\n1. 下载客户端\n2. 复制订阅地址：`{{subscribeUrl}}`\n3. 导入并连接\n\n<!--access start-->\n仅有效订阅用户可见的内容\n<!--access end-->\n",
            'sort' => $i + 1, 'show' => $i === 5 ? 0 : 1, 'created_at' => $articleAt, 'updated_at' => $articleAt,
        ];
    }

    return [
        App\Models\ServerGroup::class => $groups,
        App\Models\ServerRoute::class => $routes,
        App\Models\Plan::class => $plans,
    ] + $servers + [
        App\Models\Payment::class => $payments,
        App\Models\Coupon::class => $coupons,
        App\Models\Giftcard::class => $giftcards,
        App\Models\Notice::class => $notices,
        App\Models\Knowledge::class => $knowledge,
    ];
}

/**
 * 用户可以用的节点：套餐权限组里、显示（show=1）的节点，含子节点。
 * 返回 [[类型, id, 倍率, 父节点 id], ...]；流量统计按节点 id 记，节点状态按父节点
 */
function demo_usable_nodes(array $catalog, int $groupId): array
{
    $types = [
        App\Models\ServerShadowsocks::class => 'shadowsocks', App\Models\ServerVmess::class => 'vmess',
        App\Models\ServerTrojan::class => 'trojan', App\Models\ServerVless::class => 'vless',
        App\Models\ServerHysteria::class => 'hysteria', App\Models\ServerTuic::class => 'tuic',
        App\Models\ServerAnytls::class => 'anytls', App\Models\ServerV2node::class => 'v2node',
    ];
    $nodes = [];
    foreach ($types as $model => $type) {
        foreach ($catalog[$model] as $row) {
            if (!$row['show'] || !in_array((string)$groupId, $row['group_id'], true)) continue;
            $nodes[] = [$type, $row['id'], (float)$row['rate'], $row['parent_id']];
        }
    }
    return $nodes;
}
