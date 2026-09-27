<?php
// 守护（每分钟）：把会让所有人进不去或用不了的配置改回模板值（后台路径、订阅路径、站点地址、安全模式、密码错误次数限制），
// 补上管理员保护触发器；并写入定时任务的心跳（系统状态里的「定时任务」据此显示正常，原本由 schedule 每分钟写入）。
// 只在公开演示站（DEMO_ENABLE=1）上改配置。用法（在 php 容器里）：php /seed/demo/guard.php

require_once __DIR__ . '/config.php';

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
if (demo_ensure_triggers()) demo_log('守护：管理员保护触发器不在了，已补上');
if (demo_enabled()) {
    $changed = demo_guard_config();
    if ($changed) demo_log('守护：以下配置被改过，已改回：' . implode('、', $changed));
}
