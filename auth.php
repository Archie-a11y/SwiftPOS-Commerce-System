<?php
ob_start();
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';
require_once 'includes/languages.php';

// 统一使用全局通用的 $lang 变量字典
$lang = $languages[$lang_code] ?? $languages['en'];

$view = $_GET['view'] ?? 'login';
if (!in_array($view, ['login', 'forgot', 'reset'])) {
    $view = 'login';
}

$theme = $_COOKIE['theme'] ?? 'light';
$message = "";
$message_type = ""; 

// 自建本地日志辅助方法
function log_activity_inline($db_conn, $u_id, $act, $desc) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $log_stmt = $db_conn->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
    if ($log_stmt) {
        $log_stmt->bind_param("isss", $u_id, $act, $desc, $ip);
        $log_stmt->execute();
        $log_stmt->close();
    }
}

// 自动检测并使用 Remember Me 凭证登录
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token']) && $view === 'login') {
    $r_token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT id, username, full_name, role, status FROM users WHERE remember_token = ? AND status = 'Active'");
    $stmt->bind_param("s", $r_token);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($user = $result->fetch_assoc()) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        
        log_activity_inline($conn, $user['id'], 'Login', 'Logged in via auto-login remember token');

        if ($user['role'] == 'Administrator') {
            header("Location: admin/dashboard.php");
        } else {
            header("Location: cashier/dashboard.php");
        }
        $stmt->close();
        exit();
    }
    $stmt->close();
}

// 处理 POST 提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 1. 登录逻辑 (集成密码重试锁定与记住我)
    if ($view == 'login') {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        $stmt = $conn->prepare("SELECT id, username, full_name, password, role, status, failed_attempts, lockout_until FROM users WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $identity, $identity);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            $now = time();
            $lockout_time = $user['lockout_until'] ? strtotime($user['lockout_until']) : 0;

            if ($lockout_time > $now) {
                $wait_mins = ceil(($lockout_time - $now) / 60);
                $message = sprintf($lang['auth_err_locked'] ?? 'Account locked. Try again after %d minutes.', $wait_mins);
                $message_type = "danger";
                
                log_activity_inline($conn, $user['id'], 'Lockout Block', "Rejected login attempt for locked-out account: {$user['username']}");
            } elseif ($user['status'] !== 'Active') {
                $message = $lang['err_inactive'] ?? "Account is inactive!";
                $message_type = "danger";
            } elseif (password_verify($password, $user['password'])) {
                $reset_stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE id = ?");
                $reset_stmt->bind_param("i", $user['id']);
                $reset_stmt->execute();
                $reset_stmt->close();

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $upd_tok = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
                    $upd_tok->bind_param("si", $token, $user['id']);
                    $upd_tok->execute();
                    $upd_tok->close();
                    setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                } else {
                    setcookie('remember_token', '', time() - 3600, '/');
                }
                
                log_activity_inline($conn, $user['id'], 'Login', "User logged in manually successfully");

                if ($user['role'] == 'Administrator') {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: cashier/dashboard.php");
                }
                $stmt->close();
                exit();
            } else {
                $attempts = $user['failed_attempts'] + 1;
                if ($attempts >= 5) {
                    $lockout_until = date('Y-m-d H:i:s', time() + 600);
                    $lock_stmt = $conn->prepare("UPDATE users SET failed_attempts = ?, lockout_until = ? WHERE id = ?");
                    $lock_stmt->bind_param("isi", $attempts, $lockout_until, $user['id']);
                    $lock_stmt->execute();
                    $lock_stmt->close();
                    $message = sprintf($lang['auth_err_locked'] ?? 'Locked out. Try again after 10 minutes.', 10);
                    
                    log_activity_inline($conn, $user['id'], 'Lockout Trigger', "Account locked for 10 mins due to 5 failures");
                } else {
                    $lock_stmt = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
                    $lock_stmt->bind_param("ii", $attempts, $user['id']);
                    $lock_stmt->execute();
                    $lock_stmt->close();
                    $message = $lang['err_login'] ?? "Invalid identity or password!";
                }
                $message_type = "danger";
            }
        } else {
            $message = $lang['err_login'] ?? "Invalid identity or password!";
            $message_type = "danger";
        }
        $stmt->close();
    }

    // 2. 忘记密码逻辑 (大马便利店高合规 6位纯数字验证码机制)
    elseif ($view == 'forgot') {
        $email = trim($_POST['email'] ?? '');
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $otp_code = strval(mt_rand(100000, 999999));
            $update = $conn->prepare("UPDATE users SET reset_token = ? WHERE email = ?");
            $update->bind_param("ss", $otp_code, $email);
            $update->execute();

            log_activity_inline($conn, $user['id'], 'Password OTP Reset Request', "Generated 6-digit verification reset code for: $email");

            $message = "<b>" . ($lang['success_forgot'] ?? "Reset verification code generated:") . "</b>";
            $message .= '<div class="alert alert-secondary text-center fs-3 fw-bold border-primary text-primary tracking-wider my-3" style="letter-spacing: 5px;">
                            ' . $otp_code . '
                         </div>';
            $message .= '<small class="d-block text-muted text-center mt-2">Please copy this 6-digit code and click <a href="auth.php?view=reset" class="fw-bold">Reset Password Page</a> to securely reset your credentials.</small>';
            
            $message_type = "success";
        } else {
            $message = $lang['invalid_login'] ?? "Email not found!";
            $message_type = "danger";
        }
        $stmt->close();
    }

    // 3. 重置密码逻辑
    elseif ($view == 'reset') {
        $email = trim($_POST['email'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $new_pwd = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND reset_token = ? AND reset_token IS NOT NULL");
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows > 0) {
            $user = $res->fetch_assoc();
            $update = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, failed_attempts = 0, lockout_until = NULL WHERE id = ?");
            $update->bind_param("si", $new_pwd, $user['id']);
            $update->execute();

            log_activity_inline($conn, $user['id'], 'Password Reset Success', "Password reset securely using 6-digit code");

            header("Location: auth.php?view=login&reset_success=1");
            exit();
        } else {
            $message = $lang['err_token'] ?? "Invalid 6-digit verification code or email mismatch!";
            $message_type = "danger";
        }
        $stmt->close();
    }
}

if (isset($_GET['reset_success'])) {
    $message = $lang['success_reset'] ?? "Password updated!";
    $message_type = "success";
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>" data-bs-theme="<?php echo $theme; ?>" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($lang['system_name']); ?> - <?php echo htmlspecialchars($lang[$view . '_title'] ?? 'Auth'); ?></title>
    
    <script>
        (function() {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            const cookieName = "client_timezone";
            const currentCookie = document.cookie.split('; ').find(row => row.startsWith(cookieName + '='));
            if (!currentCookie || decodeURIComponent(currentCookie.split('=')[1]) !== tz) {
                document.cookie = cookieName + "=" + encodeURIComponent(tz) + ";path=/;max-age=" + (30*24*60*60) + ";SameSite=Lax";
                if (!sessionStorage.getItem('tz_redirected')) {
                    sessionStorage.setItem('tz_redirected', '1');
                    window.location.reload();
                }
            }
        })();
    </script>

    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-body-tertiary min-vh-100 d-flex align-items-center justify-content-center p-3">

    <!-- 顶栏快捷操作（已优化：主题切换使用内联 onclick，避免被浏览器强缓存拦截） -->
    <div class="position-absolute top-0 end-0 p-3 d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary theme-toggle-btn" 
                onclick="const newTheme = (document.documentElement.getAttribute('data-bs-theme') || 'light') === 'light' ? 'dark' : 'light'; document.cookie = 'theme=' + newTheme + ';path=/;max-age=2592000;SameSite=Lax'; window.location.reload();" 
                id="themeToggle" title="Toggle Theme">
            <i class="fas <?php echo ($theme === 'dark') ? 'fa-sun' : 'fa-moon'; ?>"></i>
        </button>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                <i class="fas fa-globe"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('en', event)">English</a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('zh', event)">简体中文</a></li>
                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('ms', event)">Bahasa Melayu</a></li>
            </ul>
        </div>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#guideModal">
            <i class="fas fa-circle-info"></i>
        </button>
    </div>

    <div class="card shadow-lg border-0 p-4 p-sm-5 rounded-4 w-100" style="max-width: 450px;">
        <div class="text-center mb-4">
            <div class="d-inline-flex p-3 rounded-circle bg-primary bg-opacity-10 text-primary mb-3">
                <i class="fas fa-calculator fa-2x"></i>
            </div>
            <h3 class="fw-bold text-primary mb-1">
                <?php 
                    if($view == 'reset') echo $lang['reset_title'] ?? 'Reset Password';
                    elseif($view == 'forgot') echo $lang['forgot_title'] ?? 'Forgot Password';
                    else echo $lang['welcome']; 
                ?>
            </h3>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show rounded-4 small" role="alert">
                <i class="fas <?php echo ($message_type == 'danger') ? 'fa-circle-xmark' : 'fa-circle-check'; ?> me-2"></i>
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-alert="dismiss" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
            
            <?php if($view == 'reset'): ?>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="fas fa-envelope me-2 text-secondary"></i> Registered Email *</label>
                    <input type="email" name="email" class="form-control" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold"><i class="fas fa-shield me-2 text-secondary"></i> 6-Digit Reset Code *</label>
                    <input type="text" name="code" class="form-control text-center font-monospace fw-bold fs-5" required minlength="6" maxlength="6" autocomplete="off" placeholder="000000">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-semibold">
                        <i class="fas fa-key me-2 text-secondary"></i> <?php echo $lang['new_password'] ?? 'New Password'; ?> *
                    </label>
                    <div class="input-group">
                        <input type="password" id="auth_password" name="password" class="form-control" required minlength="6">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
                            <i id="eyeIcon" class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

            <?php elseif($view == 'forgot'): ?>
                <div class="mb-4">
                    <label for="auth_email" class="form-label fw-semibold">
                        <i class="fas fa-envelope me-2 text-secondary"></i> <?php echo $lang['email']; ?>
                    </label>
                    <input type="email" id="auth_email" name="email" class="form-control" required>
                </div>

            <?php else: ?>
                <div class="mb-3">
                    <label for="auth_identity" class="form-label fw-semibold">
                        <i class="fas fa-user-shield me-2 text-secondary"></i> <?php echo $lang['login_identity']; ?>
                    </label>
                    <input type="text" id="auth_identity" name="identity" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="auth_password" class="form-label fw-semibold">
                        <i class="fas fa-key me-2 text-secondary"></i> <?php echo $lang['password']; ?>
                    </label>
                    <div class="input-group">
                        <input type="password" id="auth_password" name="password" class="form-control" required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility()">
                            <i id="eyeIcon" class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="mb-4 d-flex justify-content-between align-items-center">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember_me">
                        <label class="form-check-label text-muted small user-select-none" for="remember_me">
                            <?php echo htmlspecialchars($lang['auth_remember_me'] ?? 'Remember Me'); ?>
                        </label>
                    </div>
                    <a href="?view=forgot" class="small text-decoration-none text-primary fw-semibold"><?php echo $lang['forgot_link']; ?></a>
                </div>
            <?php endif; ?>

            <div class="mb-3 pt-2">
                <button type="submit" class="btn btn-primary w-100 py-2.5 rounded-3 fw-bold">
                    <?php 
                        if($view == 'login') echo $lang['btn_login'];
                        elseif($view == 'forgot') echo $lang['btn_send'] ?? 'Send Link';
                        elseif($view == 'reset') echo $lang['btn_reset'] ?? 'Update';
                    ?>
                </button>
            </div>

            <?php if($view !== 'login'): ?>
                <div class="text-center mt-3">
                    <a href="?view=login" class="text-decoration-none text-primary fw-semibold small">
                        <i class="fas fa-arrow-left me-1"></i><?php echo $lang['have_account']; ?>
                    </a>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- 用户指南 Modal -->
    <div class="modal fade" id="guideModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow border-0">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-circle-question me-2 text-primary"></i> <?php echo $lang['guide_title']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="lh-lg mb-0 text-secondary">
                        <?php 
                            $view_guide_key = 'guide_' . ($view ?? 'login');
                            echo $lang[$view_guide_key] ?? $lang['guide_default']; 
                        ?>
                    </p>
                </div>
                <div class="modal-footer border-top-0">
                    <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal"><?php echo $lang['ok']; ?></button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script src="assets/script.js"></script>
</body>
</html>