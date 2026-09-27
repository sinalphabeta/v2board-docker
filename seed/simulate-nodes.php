<?php
// 节点在线模拟：替代真实节点定时上报，写入后端判断节点状态用的缓存（逻辑见 demo/nodes.php，演示站的心跳也用它）。
// 用法（由 scripts/simulate-nodes.sh 调用）：php /seed/simulate-nodes.php [--hold=秒] [--clear]
//   --hold=N  把时间戳写成「当前时间 + N 秒」，让状态在 N 秒内保持不变（截图比对时用，避免 5 分钟后变成未运行）
//   --clear   清除所有节点的状态缓存（全部显示为未运行）

require_once __DIR__ . '/demo/nodes.php';

$options = getopt('', ['hold::', 'clear']);
$hold = max(0, (int)($options['hold'] ?? 0));
$now = time();
demo_write_node_status($now, $hold, isset($options['clear']), true);
if (!isset($options['clear']) && $hold > 0) demo_log('状态保持到 ' . date('Y-m-d H:i:s', $now + $hold));
