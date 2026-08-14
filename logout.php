<?php
// =========================================================================
// SwiftPOS Commerce Management System - Logout Process
// =========================================================================

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

// 1. 如果用户已登录，从数据库中清除其 remember_token，防止 Token 被盗用或复用
if (isset($_SESSION['user_id'])) {
    $user_id = intval($_SESSION['user_id']);
    $stmt = $conn->prepare("UPDATE users SET remember_token = NULL WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    }
}

// 2. 清空所有的 Session 变量
$_SESSION = array();

// 3. 销毁客户端 Session 的 Cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// 4. 彻底销毁服务器端的 Session 会话
session_destroy();

// 5. 显式清除客户端浏览器中的 Remember Me 免登录 Cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// 6. 安全重定向至登录界面
header("Location: auth.php?view=login");
exit();
?>