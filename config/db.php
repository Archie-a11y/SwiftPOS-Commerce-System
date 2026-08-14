<?php
$host = "localhost"; // 你的数据库主机
$user = "root";     // 你的数据库用户名
$pass = "";         // 你的数据库密码
$dbname = "pos_db";
$conn = mysqli_connect($host, $user, $pass, $dbname);

if (!$conn) {
    die("连接失败: " . mysqli_connect_error());
}

// 设置字符集防止中文乱码
mysqli_set_charset($conn, "utf8mb4");

// 开启 Session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// ==================== 客户端/本地电脑时区适配 ====================
$client_tz = 'Asia/Kuala_Lumpur'; // 默认回退时区

if (isset($_COOKIE['client_timezone']) && !empty($_COOKIE['client_timezone'])) {
    $temp_tz = urldecode($_COOKIE['client_timezone']);
    // 验证客户端传来的时区是否为合法的 PHP 支持的时区
    if (in_array($temp_tz, timezone_identifiers_list())) {
        $client_tz = $temp_tz;
    }
}

// 1. 设置 PHP 默认运行时间时区
date_default_timezone_set($client_tz);

// 2. 动态计算时区偏移量（如 +08:00，-05:00），并对 MySQL 数据库连接同步该时区
$now = new DateTime();
$offset_sec = $now->getOffset();
$offset_hours = floor(abs($offset_sec) / 3600);
$offset_mins = floor((abs($offset_sec) % 3600) / 60);
$mysql_offset = sprintf('%s%02d:%02d', ($offset_sec >= 0 ? '+' : '-'), $offset_hours, $offset_mins);

mysqli_query($conn, "SET time_zone = '$mysql_offset'");
// =================================================================

$today = date('Y-m-d');
$this_month = date('Y-m');
?>