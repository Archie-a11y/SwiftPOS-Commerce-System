<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/languages.php';

// 统一使用系统通用的 $l 变量作为主语言字典
$l = $languages[$lang_code] ?? $languages['en'];
$theme = $_COOKIE['theme'] ?? 'light';
$role = $_SESSION['role'];
$user_name = $_SESSION['user_name'];

$current_page = basename($_SERVER['PHP_SELF'], ".php");
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

$admin_prefix   = ($current_dir === 'admin')   ? '' : '../admin/';
$shared_prefix  = ($current_dir === 'shared')  ? '' : '../shared/';
$cashier_prefix = ($current_dir === 'cashier') ? '' : '../cashier/';

$dashboard_link = ($role === 'Administrator') ? $admin_prefix . 'dashboard.php' : $cashier_prefix . 'dashboard.php';
$page_title_key = 'nav_' . $current_page;
$display_page_title = $l[$page_title_key] ?? str_replace('_', ' ', $current_page);

$role_translation_key = 'role_' . strtolower(str_replace(['/', ' '], '_', $role));
$display_role_name = $l[$role_translation_key] ?? $role;

// ==================== 动态抓取系统多维通知核心算法 ====================
$low_stock_alerts_list = [];

// 1. 低库存预警
$alert_res = $conn->query("SELECT name, stock_quantity, min_stock_level, 'low_stock' AS alert_type FROM products WHERE stock_quantity <= min_stock_level AND status = 'Active' LIMIT 3");
while ($alert_row = $alert_res->fetch_assoc()) {
    $desc_tpl = $l['alert_low_stock_desc'] ?? 'Stock: %d left (Min: %d)';
    $low_stock_alerts_list[] = [
        'title' => $alert_row['name'],
        'desc' => sprintf($desc_tpl, $alert_row['stock_quantity'], $alert_row['min_stock_level']),
        'type' => 'danger'
    ];
}

// 2. 未结供应商货款查询 (3天内到期)
$unpaid_res = $conn->query("SELECT p.purchase_number, p.total_amount, p.payment_due_date, s.company_name FROM purchases p JOIN suppliers s ON p.supplier_id = s.id WHERE p.payment_status = 'Unpaid' AND p.payment_due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) LIMIT 3");
while ($unpaid_row = $unpaid_res->fetch_assoc()) {
    $desc_tpl = $l['alert_po_due_desc'] ?? 'To: %s | RM %s due on %s';
    $formatted_date = date('d/m/Y', strtotime($unpaid_row['payment_due_date']));
    $formatted_amount = number_format($unpaid_row['total_amount'], 2);
    $low_stock_alerts_list[] = [
        'title' => 'PO Due: ' . $unpaid_row['purchase_number'],
        'desc' => sprintf($desc_tpl, $unpaid_row['company_name'], $formatted_amount, $formatted_date),
        'type' => 'warning'
    ];
}

// 3. 新注册用户警报 (24小时内创建的账号)
$new_users_res = $conn->query("SELECT username, full_name, role FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 3");
while ($new_user_row = $new_users_res->fetch_assoc()) {
    $title_tpl = $l['alert_new_user_title'] ?? 'New User: %s';
    $desc_tpl = $l['alert_new_user_desc'] ?? 'Operator %s (%s) registered recently.';
    $low_stock_alerts_list[] = [
        'title' => sprintf($title_tpl, $new_user_row['username']),
        'desc' => sprintf($desc_tpl, $new_user_row['full_name'], $new_user_row['role']),
        'type' => 'info'
    ];
}

// 4. 销售里程碑警报 (单笔交易额超过 RM 1,000)
$milestone_res = $conn->query("SELECT invoice_number, total_amount FROM sales WHERE total_amount >= 1000.00 AND sale_date >= DATE_SUB(NOW(), INTERVAL 1 DAY) LIMIT 3");
while ($ms_row = $milestone_res->fetch_assoc()) {
    $title_tpl = $l['alert_milestone_title'] ?? 'Milestone: RM %s';
    $desc_tpl = $l['alert_milestone_desc'] ?? 'Invoice %s surpassed RM 1,000.00 landmark!';
    $formatted_amount = number_format($ms_row['total_amount'], 2);
    $low_stock_alerts_list[] = [
        'title' => sprintf($title_tpl, $formatted_amount),
        'desc' => sprintf($desc_tpl, $ms_row['invoice_number']),
        'type' => 'success'
    ];
}

$notification_count = count($low_stock_alerts_list);

// ==================== 统一操作审计日志全局辅助函数 ====================
if (!function_exists('log_activity')) {
    function log_activity($db_conn, $user_id, $action_type, $description) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt_log = $db_conn->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address) VALUES (?, ?, ?, ?)");
        if ($stmt_log) {
            $stmt_log->bind_param("isss", $user_id, $action_type, $description, $ip_address);
            $stmt_log->execute();
            $stmt_log->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>" data-bs-theme="<?php echo $theme; ?>" data-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($l['system_name']); ?> - <?php echo htmlspecialchars($display_page_title); ?></title>
    
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
    
    <!-- 全局高优先级深色模式修复样式表 -->
    <style>
        /* 针对深色模式的数据展示和输入框高优先级重置 */
        [data-bs-theme="dark"], [data-theme="dark"] {
            --bs-body-bg: #1a1d20;
            --bs-body-color: #f8f9fa;
            --bs-secondary-color: #adb5bd;
            --bs-tertiary-bg: #2b3035;
        }

        [data-bs-theme="dark"] body, [data-theme="dark"] body {
            background-color: #121416 !important;
            color: #f8f9fa !important;
        }

        /* 覆盖硬编码了 text-dark / text-body-emphasis 等浅色文字类 */
        [data-bs-theme="dark"] .text-dark,
        [data-bs-theme="dark"] .text-dark-emphasis,
        [data-bs-theme="dark"] .text-body-emphasis,
        [data-bs-theme="dark"] .text-main,
        [data-bs-theme="dark"] .text-body,
        [data-bs-theme="dark"] h1,
        [data-bs-theme="dark"] h2,
        [data-bs-theme="dark"] h3,
        [data-bs-theme="dark"] h4,
        [data-bs-theme="dark"] h5,
        [data-bs-theme="dark"] h6 {
            color: #f8f9fa !important;
        }

        /* 解决次级文字颜色在深色背景下不清晰的问题 */
        [data-bs-theme="dark"] .text-secondary {
            color: #ced4da !important;
        }
        [data-bs-theme="dark"] .text-muted {
            color: #adb5bd !important;
        }

        /* 覆盖硬编码了 bg-white / bg-light 导致的大白块 */
        [data-bs-theme="dark"] .bg-white {
            background-color: #212529 !important;
            color: #f8f9fa !important;
        }
        [data-bs-theme="dark"] .bg-light,
        [data-bs-theme="dark"] .bg-body-secondary,
        [data-bs-theme="dark"] .bg-body-tertiary {
            background-color: #2b3035 !important;
            color: #f8f9fa !important;
        }

        /* 确保各种边框不会在深色模式下过于突兀 */
        [data-bs-theme="dark"] .border,
        [data-bs-theme="dark"] .border-top,
        [data-bs-theme="dark"] .border-bottom,
        [data-bs-theme="dark"] .border-start,
        [data-bs-theme="dark"] .border-end,
        [data-bs-theme="dark"] .border-secondary-subtle {
            border-color: #495057 !important;
        }

        /* 表格背景及高亮变色修复 */
        [data-bs-theme="dark"] .table {
            --bs-table-color: #f8f9fa !important;
            --bs-table-bg: #212529 !important;
            --bs-table-border-color: #495057 !important;
            --bs-table-hover-color: #f8f9fa !important;
            --bs-table-hover-bg: #2b3035 !important;
        }
        [data-bs-theme="dark"] .table-light,
        [data-bs-theme="dark"] .table thead,
        [data-bs-theme="dark"] .table thead tr,
        [data-bs-theme="dark"] .table-light th,
        [data-bs-theme="dark"] .table-light td {
            background-color: #2b3035 !important;
            color: #f8f9fa !important;
        }

        /* 输入框、下拉选择框、文本域样式增强 */
        [data-bs-theme="dark"] input,
        [data-bs-theme="dark"] select,
        [data-bs-theme="dark"] textarea,
        [data-bs-theme="dark"] .form-control,
        [data-bs-theme="dark"] .form-select {
            background-color: #2b3035 !important;
            color: #f8f9fa !important;
            border-color: #495057 !important;
        }
        [data-bs-theme="dark"] input:focus,
        [data-bs-theme="dark"] select:focus,
        [data-bs-theme="dark"] textarea:focus,
        [data-bs-theme="dark"] .form-control:focus,
        [data-bs-theme="dark"] .form-select:focus {
            background-color: #343a40 !important;
            color: #ffffff !important;
            border-color: #0d6efd !important;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
        }
        [data-bs-theme="dark"] input::placeholder,
        [data-bs-theme="dark"] textarea::placeholder {
            color: #6c757d !important;
        }
        [data-bs-theme="dark"] .input-group-text {
            background-color: #343a40 !important;
            color: #f8f9fa !important;
            border-color: #495057 !important;
        }

        /* 模态弹窗在深色模式下的统一样式 */
        [data-bs-theme="dark"] .modal-content {
            background-color: #212529 !important;
            color: #f8f9fa !important;
            border-color: #495057 !important;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5) !important;
        }
        [data-bs-theme="dark"] .modal-header,
        [data-bs-theme="dark"] .modal-footer {
            border-color: #343a40 !important;
            background-color: #212529 !important;
        }

        /* 卡片基础面板 */
        [data-bs-theme="dark"] .card {
            background-color: #212529 !important;
            color: #f8f9fa !important;
            border-color: #343a40 !important;
        }

        /* 下拉菜单和列表项 */
        [data-bs-theme="dark"] .dropdown-menu {
            background-color: #2b3035 !important;
            color: #f8f9fa !important;
            border-color: #495057 !important;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.5) !important;
        }
        [data-bs-theme="dark"] .dropdown-item {
            color: #f8f9fa !important;
        }
        [data-bs-theme="dark"] .dropdown-item:hover,
        [data-bs-theme="dark"] .dropdown-item:focus {
            background-color: #343a40 !important;
            color: #ffffff !important;
        }

        [data-bs-theme="dark"] .list-group-item {
            background-color: #212529 !important;
            color: #f8f9fa !important;
            border-color: #343a40 !important;
        }
        [data-bs-theme="dark"] .list-group-item-action:hover,
        [data-bs-theme="dark"] .list-group-item-action:focus {
            background-color: #2b3035 !important;
            color: #f8f9fa !important;
        }

        /* 侧边导航栏及顶部控制栏 */
        [data-bs-theme="dark"] #sidebarMenu {
            background-color: #1a1d20 !important;
            border-color: #2b3035 !important;
        }
        [data-bs-theme="dark"] .navbar {
            background-color: #212529 !important;
            border-color: #2b3035 !important;
        }

        /* 标签 Badge 修正 */
        [data-bs-theme="dark"] .badge.bg-light {
            background-color: #343a40 !important;
            color: #f8f9fa !important;
            border: 1px solid #495057 !important;
        }

        /* 特例排除：强制保持热敏小票模拟区域为白底黑字（不参与深色变色） */
        [data-bs-theme="dark"] #receipt-paper,
        [data-bs-theme="dark"] #receipt-paper *,
        [data-bs-theme="dark"] #receiptPrintContent,
        [data-bs-theme="dark"] #receiptPrintContent *,
        [data-bs-theme="dark"] #printArea,
        [data-bs-theme="dark"] #printArea * {
            color: #000000 !important;
            background-color: #ffffff !important;
        }
    </style>
</head>
<body class="bg-body-tertiary">

<div class="container-fluid">
    <div class="row">
        
        <!-- 左侧导航 -->
        <nav class="col-lg-3 col-xl-2 offcanvas-lg offcanvas-start border-end bg-body-tertiary p-0 min-vh-100" tabindex="-1" id="sidebarMenu">
            <div class="d-flex flex-column h-100">
                <div class="d-flex align-items-center justify-content-between p-3 border-bottom border-secondary-subtle">
                    <span class="fs-5 fw-bold text-primary">
                        <i class="fas fa-calculator text-warning me-2"></i><?php echo htmlspecialchars($l['system_name']); ?>
                    </span>
                    <button class="btn-close d-lg-none" type="button" data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu"></button>
                </div>
                
                <div class="p-3 border-bottom border-secondary-subtle bg-body-secondary small">
                    <div class="fw-bold text-body-emphasis"><?php echo htmlspecialchars($user_name); ?></div>
                    <div class="text-muted"><?php echo htmlspecialchars($display_role_name); ?></div>
                </div>

                <div class="list-group list-group-flush flex-grow-1 overflow-y-auto">
                    <a href="<?php echo $dashboard_link; ?>" class="list-group-item list-group-item-action py-3 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'dashboard') ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt me-3 text-secondary"></i><span><?php echo $l['nav_dashboard']; ?></span>
                    </a>

                    <?php if ($role === 'Administrator'): ?>
                        <div class="text-uppercase small fw-bold px-4 py-2 mt-2 text-muted"><?php echo $l['nav_inv_mgmt']; ?></div>
                        <a href="<?php echo $admin_prefix; ?>products.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'products') ? 'active' : ''; ?>">
                            <i class="fas fa-box me-3 text-secondary"></i><span><?php echo $l['nav_products']; ?></span>
                        </a>
                        <a href="<?php echo $admin_prefix; ?>categories.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'categories') ? 'active' : ''; ?>">
                            <i class="fas fa-tags me-3 text-secondary"></i><span><?php echo $l['nav_categories']; ?></span>
                        </a>
                        
                        <!-- 合并后的供应商与采购进货页，使用专用语言键名 -->
                        <a href="<?php echo $admin_prefix; ?>suppliers.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'suppliers' || $current_page == 'purchases') ? 'active' : ''; ?>">
                            <i class="fas fa-truck-moving me-3 text-secondary"></i><span><?php echo $l['nav_suppliers_purchases'] ?? 'Suppliers & Purchases'; ?></span>
                        </a>
                        
                        <a href="<?php echo $admin_prefix; ?>customers.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'customers') ? 'active' : ''; ?>">
                            <i class="fas fa-users me-3 text-secondary"></i><span><?php echo $l['nav_customers']; ?></span>
                        </a>
                        <a href="<?php echo $admin_prefix; ?>inventory.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'inventory') ? 'active' : ''; ?>">
                            <i class="fas fa-warehouse me-3 text-secondary"></i><span><?php echo $l['nav_inventory']; ?></span>
                        </a>

                        <div class="text-uppercase small fw-bold px-4 py-2 mt-2 text-muted"><?php echo $l['nav_transactions']; ?></div>
                        <a href="<?php echo $shared_prefix; ?>sales_history.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'sales_history') ? 'active' : ''; ?>">
                            <i class="fas fa-receipt me-3 text-secondary"></i><span><?php echo $l['nav_sales']; ?></span>
                        </a>

                        <div class="text-uppercase small fw-bold px-4 py-2 mt-2 text-muted"><?php echo $l['nav_administration']; ?></div>
                        <a href="<?php echo $admin_prefix; ?>reports.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'reports') ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line me-3 text-secondary"></i><span><?php echo $l['nav_reports']; ?></span>
                        </a>
                        <a href="<?php echo $admin_prefix; ?>users.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'users') ? 'active' : ''; ?>">
                            <i class="fas fa-users-cog me-3 text-secondary"></i><span><?php echo $l['nav_users']; ?></span>
                        </a>
                        <a href="<?php echo $admin_prefix; ?>activity_logs.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'activity_logs') ? 'active' : ''; ?>">
                            <i class="fas fa-user-shield me-3 text-secondary"></i><span><?php echo $l['nav_activity_logs']; ?></span>
                        </a>
                        <a href="<?php echo $admin_prefix; ?>settings.php" class="list-group-item list-group-item-action py-2.5 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'settings') ? 'active' : ''; ?>">
                            <i class="fas fa-gears me-3 text-secondary"></i><span><?php echo $l['nav_settings']; ?></span>
                        </a>
                    <?php endif; ?>

                    <?php if ($role === 'Cashier'): ?>
                        <div class="text-uppercase small fw-bold px-4 py-2 mt-2 text-muted"><?php echo $l['nav_operations']; ?></div>
                        <a href="<?php echo $cashier_prefix; ?>pos.php" class="list-group-item list-group-item-action py-3 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'pos') ? 'active' : ''; ?>">
                            <i class="fas fa-cash-register me-3 text-secondary"></i><span><?php echo $l['nav_pos']; ?></span>
                        </a>
                        <a href="<?php echo $shared_prefix; ?>sales_history.php" class="list-group-item list-group-item-action py-3 px-4 border-0 d-flex align-items-center <?php echo ($current_page == 'sales_history') ? 'active' : ''; ?>">
                            <i class="fas fa-history me-3 text-secondary"></i><span><?php echo $l['nav_sales_history']; ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <!-- 主体结构 -->
        <main class="col-lg-9 col-xl-10 ms-auto min-vh-100 d-flex flex-column p-0">
            
            <header class="navbar navbar-expand-lg bg-body border-bottom border-secondary-subtle px-4 py-3 sticky-top">
                <div class="container-fluid p-0 d-flex justify-content-between">
                    <div class="d-flex align-items-center">
                        <button class="btn btn-outline-secondary d-lg-none me-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h4 class="mb-0 fw-bold text-capitalize d-none d-sm-block text-body-emphasis">
                            <?php echo htmlspecialchars($display_page_title); ?>
                        </h4>
                    </div>

                    <!-- 顶部快捷控制组件 -->
                    <div class="d-flex align-items-center gap-2">
                        
                        <!-- 动态通知中心 -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary position-relative" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bell"></i>
                                <?php if ($notification_count > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?php echo $notification_count; ?>
                                    </span>
                                <?php endif; ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm py-2" style="width: 320px; font-size:0.85rem;">
                                <li class="px-3 py-1 border-bottom fw-bold text-muted text-uppercase" style="font-size:0.75rem;">
                                    <?php echo htmlspecialchars($l['alert_sys_alerts_title'] ?? 'System Alerts & Notifications'); ?>
                                </li>
                                <?php if (empty($low_stock_alerts_list)): ?>
                                    <li class="px-3 py-3 text-center text-muted">
                                        <?php echo htmlspecialchars($l['alert_no_pending_alerts'] ?? 'No pending alerts. All system health status normal!'); ?>
                                    </li>
                                <?php else: ?>
                                    <?php foreach ($low_stock_alerts_list as $alert): ?>
                                        <li class="px-3 py-2 border-bottom">
                                            <div class="d-flex align-items-start gap-2">
                                                <i class="fas fa-circle-exclamation text-<?php echo $alert['type'] ?? 'info'; ?> mt-1"></i>
                                                <div>
                                                    <strong class="d-block text-body-emphasis text-truncate" style="max-width:250px;"><?php echo htmlspecialchars($alert['title']); ?></strong>
                                                    <span class="text-secondary small"><?php echo htmlspecialchars($alert['desc']); ?></span>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </ul>
                        </div>

                        <!-- 切换配色 -->
                        <button type="button" class="btn btn-sm btn-outline-secondary theme-toggle-btn" 
                                onclick="const newTheme = (document.documentElement.getAttribute('data-bs-theme') || 'light') === 'light' ? 'dark' : 'light'; document.cookie = 'theme=' + newTheme + ';path=/;max-age=2592000;SameSite=Lax'; window.location.reload();" 
                                title="Toggle Theme">
                            <i class="fas <?php echo ($theme === 'dark') ? 'fa-sun' : 'fa-moon'; ?>"></i>
                        </button>

                        <!-- 国际化语言 -->
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-globe"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('en', event)">English</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('zh', event)">简体中文</a></li>
                                <li><a class="dropdown-item" href="javascript:void(0)" onclick="changeLang('ms', event)">Bahasa Melayu</a></li>
                            </ul>
                        </div>

                        <!-- 帮助 -->
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#guideModal">
                            <i class="fas fa-circle-info"></i>
                        </button>
                        
                        <a href="../logout.php" class="btn btn-sm btn-danger rounded-pill px-3">
                            <i class="fas fa-sign-out-alt me-1"></i><span class="d-none d-sm-inline"><?php echo $l['logout']; ?></span>
                        </a>
                    </div>
                </div>
            </header>

            <div class="p-3 p-md-4 flex-grow-1">

            <!-- 全局通用页面指导 Modal -->
            <div class="modal fade" id="guideModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content rounded-4 border-0">
                        <div class="modal-header border-0 pb-0">
                            <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-circle-info text-primary me-2"></i><?php echo htmlspecialchars($l['guide_title'] ?? 'Page Guide'); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body py-4">
                            <p class="text-secondary small mb-0">
                                <?php 
                                $guide_key = 'guide_' . $current_page;
                                echo $l[$guide_key] ?? ($l['guide_default'] ?? 'No guide available for this page.');
                                ?>
                            </p>
                        </div>
                        <div class="modal-footer border-0 pt-0">
                            <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">
                                <?php echo htmlspecialchars($l['ok'] ?? 'Understood'); ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            function changeLang(lang, e) {
                e.preventDefault();
                document.cookie = "lang=" + lang + ";path=/;max-age=2592000;SameSite=Lax";
                window.location.reload();
            }
            </script>