<?php
// 公开演示站的调度（compose 的 demo 服务运行它）：启动时复原一次，之后每小时整点复原（reset.php），
// 每 5 分钟心跳（tick.php），每分钟守护（guard.php）。每个任务在单独的 PHP 进程里运行，互不影响；
// 某次失败只记日志，下一轮照常进行。日志：docker compose logs -f demo

date_default_timezone_set('Asia/Shanghai');

function daemon_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] [demo] {$message}\n");
}

function daemon_run(string $script): void
{
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1G ' . escapeshellarg(__DIR__ . '/' . $script);
    passthru($command, $code);
    if ($code !== 0) daemon_log("{$script} 退出码 {$code}");
}

daemon_log('演示站调度启动：每小时整点复原数据，每 5 分钟刷新在线状态，每分钟守护关键配置');
daemon_run('reset.php');
daemon_run('tick.php');
daemon_run('guard.php');
$lastHour = date('YmdH');

while (true) {
    // 睡到下一分钟的第 2 秒
    sleep(62 - time() % 60);
    $now = time();
    if (date('YmdH', $now) !== $lastHour) {
        $lastHour = date('YmdH', $now);
        daemon_run('reset.php');
    }
    if ((int)date('i', $now) % 5 === 0) daemon_run('tick.php');
    daemon_run('guard.php');
}
