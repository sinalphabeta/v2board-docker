<?php
// 在开发环境的数据库上执行一条 SQL（请求比对等工具准备 / 恢复测试数据用，后台接口没有对应操作的数据，
// 例如删除测试订单、把关闭的工单重新打开）。由 scripts/sql.sh 调用：
//   php /seed/sql.php "SELECT ..."   输出查询结果（JSON 数组）
//   php /seed/sql.php "UPDATE ..."   输出受影响的行数（JSON）
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$sql = $argv[1] ?? '';
if ($sql === '') {
    fwrite(STDERR, "用法：php /seed/sql.php \"SQL\"\n");
    exit(1);
}
if (preg_match('/^\s*(select|show)\b/i', $sql)) {
    echo json_encode(DB::select($sql), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo json_encode(['affected' => DB::affectingStatement($sql)]), "\n";
}
