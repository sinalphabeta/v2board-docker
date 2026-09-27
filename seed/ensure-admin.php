<?php
// 确保管理员账号存在且 is_admin=1（幂等）。密码只在首次创建时设置，之后改密码不会被覆盖。
// 由 init 容器执行：php /seed/ensure-admin.php

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Utils\Helper;

$email = getenv('V2B_ADMIN_EMAIL') ?: 'admin@example.com';
$password = getenv('V2B_ADMIN_PASSWORD') ?: 'admin123456';

if (strlen($password) < 8) {
    fwrite(STDERR, "[v2b-init] V2B_ADMIN_PASSWORD 至少 8 位（后端登录校验 min:8）\n");
    exit(1);
}

$user = User::where('email', $email)->first();
if (!$user) {
    $user = new User();
    $user->email = $email;
    $user->password = password_hash($password, PASSWORD_DEFAULT);
    $user->uuid = Helper::guid(true);
    $user->token = Helper::guid();
    $user->is_admin = 1;
    $user->save();
    echo "[v2b-init] 已创建管理员 {$email} / {$password}\n";
    exit(0);
}

if (!$user->is_admin) {
    $user->is_admin = 1;
    $user->save();
}
echo "[v2b-init] 管理员已存在：{$email}\n";
