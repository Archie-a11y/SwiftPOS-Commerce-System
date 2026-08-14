<?php
// 引入数据库与核心配置 (内部已开启 session_start)
require_once 'config/db.php';

// 1. 检查用户是否已登录
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // 没登录，重定向到登录页面
    header("Location: auth.php?view=login");
    exit();
}

// 2. 如果已登录，根据角色进行智能跳转
$role = $_SESSION['role'] ?? '';

switch ($role) {
    case 'Administrator':
        // 管理员 -> 进入管理大盘
        header("Location: admin/dashboard.php");
        break;
        
    case 'Cashier':
        // 收银员 -> 进入收银大盘
        header("Location: cashier/dashboard.php");
        break;
        
    default:
        // 异常角色或未设置角色，强制销毁会话并重新登录
        session_destroy();
        header("Location: auth.php?view=login&err=invalid_role");
        break;
}

exit();
?>