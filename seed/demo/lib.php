<?php
// 演示数据的公共部分：加载 Laravel、确定性随机数、时间与日志工具。
// 所有随机数都由「键」的散列决定（与调用顺序、运行次数无关）：同一个键永远得到同一个值，
// 所以同一时刻重复生成的结果逐字节相同，某一天的数据也不会因为别处的改动而变化。

require_once '/var/www/html/vendor/autoload.php';
$GLOBALS['app'] = require_once '/var/www/html/bootstrap/app.php';
$GLOBALS['app']->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

const GB = 1073741824;
const DAY = 86400;

function demo_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] [demo] {$message}\n");
}

/** 环境变量（未设置或为空时取默认值） */
function demo_env(string $name, $default = null)
{
    $value = getenv($name);
    return $value === false || $value === '' ? $default : $value;
}

/** 是否是公开演示站（DEMO_ENABLE=1）：复原系统配置、守护关键配置只在演示站上做，本机开发不动你自己改的配置 */
function demo_enabled(): bool
{
    return (string)demo_env('DEMO_ENABLE', '0') === '1';
}

// ---------- 确定性随机数 ----------

/** [0, 1) 之间的随机数 */
function rnd(string $key): float
{
    return hexdec(substr(sha1($key), 0, 13)) / 4503599627370496; // 2^52
}

/** [$min, $max] 之间的整数 */
function rint(string $key, int $min, int $max): int
{
    return $min + (int)floor(rnd($key) * ($max - $min + 1));
}

function rpick(string $key, array $items)
{
    $items = array_values($items);
    return $items[rint($key, 0, count($items) - 1)];
}

function rchance(string $key, float $probability): bool
{
    return rnd($key) < $probability;
}

/** 按权重取值：[值 => 权重] */
function rweighted(string $key, array $weights)
{
    $total = array_sum($weights);
    $x = rnd($key) * $total;
    foreach ($weights as $value => $weight) {
        if (($x -= $weight) < 0) return $value;
    }
    return array_key_last($weights);
}

/** 由键生成的 guid（与 Helper::guid 的格式相同） */
function fake_guid(string $key, bool $format = false): string
{
    $hash = md5('guid:' . $key);
    if (!$format) return $hash;
    return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-' . substr($hash, 12, 4) . '-'
        . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
}

/** 由键生成的大写字母数字串（优惠码、礼品卡码、邀请码） */
function fake_code(string $key, int $length): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) $code .= $chars[rint("{$key}:{$i}", 0, strlen($chars) - 1)];
    return $code;
}

/** 固定盐的 bcrypt（password_verify 能验证；每次生成结果相同，复原前后的导出可以逐字节比较） */
function fixed_bcrypt(string $password, string $saltKey): string
{
    $alphabet = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $salt = '';
    for ($i = 0; $i < 22; $i++) $salt .= $alphabet[rint("salt:{$saltKey}:{$i}", 0, 63)];
    return crypt($password, '$2y$10$' . $salt);
}

// ---------- 时间 ----------

/** 当天 0 点 */
function day_start(int $time): int
{
    return strtotime(date('Y-m-d', $time));
}

/** 一天里按作息分布的时刻（凌晨少、晚上多），返回距 0 点的秒数 */
function diurnal_offset(string $key): int
{
    $hour = (int)rweighted("{$key}:h", [
        0 => 4, 1 => 2, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 4, 8 => 6, 9 => 7, 10 => 8, 11 => 8,
        12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8, 17 => 8, 18 => 8, 19 => 9, 20 => 10, 21 => 11, 22 => 10, 23 => 7,
    ]);
    return $hour * 3600 + rint("{$key}:s", 0, 3599);
}

/** 与 OrderService::getTime 相同：从 $from（早于 $now 时取 $now）加上周期 */
function add_period(string $period, int $from, int $now): int
{
    if ($from < $now) $from = $now;
    $months = [
        'month_price' => 1, 'quarter_price' => 3, 'half_year_price' => 6, 'year_price' => 12,
        'two_year_price' => 24, 'three_year_price' => 36,
    ][$period];
    return strtotime("+{$months} month", $from);
}

/** 保留地址段（RFC 5737）里的 IP：演示用，不会指向真实主机 */
function demo_ip(string $key): string
{
    $block = rpick("{$key}:b", ['192.0.2', '198.51.100', '203.0.113']);
    return $block . '.' . rint("{$key}:h", 1, 254);
}
