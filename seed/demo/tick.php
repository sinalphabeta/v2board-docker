<?php
// 心跳（每 5 分钟）：刷新节点状态与在线用户，记录队列监控的快照（horizon:snapshot，原本由定时任务每 5 分钟执行）。
// 用法（在 php 容器里）：php /seed/demo/tick.php

require_once __DIR__ . '/nodes.php';

use Illuminate\Support\Facades\Artisan;

$now = time();
demo_write_node_status($now);
$online = demo_write_online_users($now);
Artisan::call('horizon:snapshot');
demo_log("心跳：节点状态已刷新，在线 {$online} 人");
