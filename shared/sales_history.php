<?php
// 1. 强制声明浏览器以 UTF-8 字符集解析，防止因系统默认字符集不同导致乱码
header('Content-Type: text/html; charset=utf-8');

// 2. 强制开启错误报告
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 安全拦截：未登录用户禁止访问
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/languages.php';

// 确保语言字典安全加载
$lang_code = $_COOKIE['lang'] ?? $_SESSION['lang'] ?? 'en';
$l = $languages[$lang_code] ?? $languages['en'] ?? [];

// 提取当前配置字典
$settings = [];
$set_res = $conn->query("SELECT key_name, key_value FROM settings");
while ($s_row = $set_res->fetch_assoc()) {
    $settings[$s_row['key_name']] = $s_row['key_value'];
}
$store_address = $settings['store_address'] ?? 'Kuala Lumpur, Malaysia';
$store_phone = $settings['store_phone'] ?? '03-2148 2000';
$sst_reg_no = $settings['sst_reg_no'] ?? 'W10-1808-32000045';
$sst_rate_percent = floatval($settings['sst_rate'] ?? 6.00);

// =========================================================================
// 1. AJAX 获取特定账单详情及对应商品条目接口
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] == 'get_invoice_details') {
    header('Content-Type: application/json; charset=utf-8');
    $sale_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($sale_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
        exit();
    }

    $stmt = $conn->prepare("SELECT s.*, DATE_FORMAT(s.sale_date, '%d/%m/%Y %H:%i') AS sale_date_formatted, u.full_name AS cashier_name, c.name AS customer_name, c.loyalty_points AS customer_points FROM sales s LEFT JOIN users u ON s.created_by = u.id LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare SQL statement failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }

    $stmt = $conn->prepare("SELECT si.*, p.name AS product_name, p.product_code, p.barcode FROM sale_items si JOIN products p ON si.product_id = p.id WHERE si.sale_id = ?");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare items SQL statement failed: ' . $conn->error]);
        exit();
    }
    $stmt->bind_param("i", $sale_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    echo json_encode([
        'success' => true,
        'sale' => $sale,
        'items' => $items
    ]);
    exit();
}

// =========================================================================
// 2. 销售账单过滤逻辑
// =========================================================================
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_filter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$where_clauses = [];
$params = [];
$types = '';

if ($_SESSION['role'] === 'Cashier') {
    $where_clauses[] = "s.created_by = ?";
    $params[] = intval($_SESSION['user_id']);
    $types .= 'i';
}

if (!empty($search)) {
    $where_clauses[] = "(s.invoice_number LIKE ? OR u.full_name LIKE ?)";
    $like_term = "%$search%";
    $params[] = $like_term;
    $params[] = $like_term;
    $types .= 'ss';
}

if (!empty($date_filter)) {
    if ($date_filter === 'today') {
        $where_clauses[] = "DATE(s.sale_date) = CURDATE()";
    } elseif ($date_filter === 'yesterday') {
        $where_clauses[] = "DATE(s.sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
    } elseif ($date_filter === 'this_week') {
        $where_clauses[] = "YEARWEEK(s.sale_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($date_filter === 'this_month') {
        $where_clauses[] = "YEAR(s.sale_date) = YEAR(CURDATE()) AND MONTH(s.sale_date) = MONTH(CURDATE())";
    } elseif ($date_filter === 'custom' && !empty($start_date) && !empty($end_date)) {
        $where_clauses[] = "DATE(s.sale_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= 'ss';
    }
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
}

$count_query = "SELECT COUNT(*) as total FROM sales s LEFT JOIN users u ON s.created_by = u.id" . $where_sql;
$stmt = $conn->prepare($count_query);
if (!$stmt) {
    die("<div class='alert alert-danger m-3'>Count query failed: " . htmlspecialchars($conn->error) . "</div>");
}
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total_records = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$limit = 10;
$total_pages = ceil($total_records / $limit);
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;
$offset = ($page - 1) * $limit;

$list_query = "SELECT s.*, u.full_name AS cashier_name FROM sales s LEFT JOIN users u ON s.created_by = u.id" . $where_sql . " ORDER BY s.sale_date DESC LIMIT ? OFFSET ?";

$list_params = $params;
$list_params[] = $limit;
$list_params[] = $offset;
$list_types = $types . 'ii';

$stmt = $conn->prepare($list_query);
if (!$stmt) {
    die("<div class='alert alert-danger m-3'>List query failed: " . htmlspecialchars($conn->error) . "</div>");
}
$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$sales_result = $stmt->get_result();
$sales_list = [];
while ($row = $sales_result->fetch_assoc()) {
    $sales_list[] = $row;
}
$stmt->close();

include_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid px-0">
    <div class="mb-4">
        <p class="text-muted mb-0"><?php echo htmlspecialchars($l['dash_subtitle']); ?></p>
    </div>

    <!-- 过滤器 -->
    <form method="GET" action="sales_history.php" class="card shadow-sm border-0 mb-4 rounded-4 d-print-none">
        <div class="card-body p-3">
            <div class="row g-2 align-items-center m-0">
                <div class="col-12 col-md-3 p-1">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0 text-muted rounded-start-3 px-3 py-2.5"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 ps-0 rounded-end-3 py-2.5" 
                               placeholder="<?php echo htmlspecialchars($l['rep_search_placeholder']); ?>" 
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                
                <div class="col-12 col-md-2 p-1">
                    <select name="date_filter" id="date_filter" class="form-select rounded-3 py-2.5">
                        <option value=""><?php echo htmlspecialchars($l['rep_date_filter']); ?></option>
                        <option value="today" <?php echo ($date_filter === 'today') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['today_sales']); ?></option>
                        <option value="yesterday" <?php echo ($date_filter === 'yesterday') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['yesterday_sales']); ?></option>
                        <option value="this_week" <?php echo ($date_filter === 'this_week') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['this_week_sales']); ?></option>
                        <option value="this_month" <?php echo ($date_filter === 'this_month') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['this_month_sales']); ?></option>
                        <option value="custom" <?php echo ($date_filter === 'custom') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_date_custom']); ?></option>
                    </select>
                </div>
                
                <div class="col-12 col-md-4 p-1 d-none" id="custom_date_fields">
                    <div class="row g-2 w-100 m-0">
                        <div class="col-6 p-0 pe-1">
                            <input type="date" name="start_date" class="form-control rounded-3 py-2.5" 
                                   placeholder="<?php echo htmlspecialchars($l['rep_start_date']); ?>" 
                                   value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="col-6 p-0 ps-1">
                            <input type="date" name="end_date" class="form-control rounded-3 py-2.5" 
                                   placeholder="<?php echo htmlspecialchars($l['rep_end_date']); ?>" 
                                   value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="col-12 col-md d-flex gap-2 justify-content-end p-1 ms-auto">
                    <button type="submit" class="btn btn-primary rounded-3 py-2.5 px-4 flex-grow-1">
                        <i class="fas fa-filter me-2"></i><?php echo htmlspecialchars($l['prod_filter_btn_filter']); ?>
                    </button>
                    <a href="sales_history.php" class="btn btn-outline-secondary rounded-3 py-2.5 px-3" title="Reset">
                        <i class="fas fa-arrows-rotate"></i>
                    </a>
                </div>
            </div>
        </div>
    </form>

    <?php if (empty($sales_list)): ?>
        <div class="card shadow-sm border-0 py-5 text-center d-print-none rounded-4">
            <div class="card-body">
                <i class="fas fa-file-invoice text-muted fa-3x mb-3 opacity-50"></i>
                <h5 class="text-muted mb-0"><?php echo htmlspecialchars($l['rep_no_data']); ?></h5>
            </div>
        </div>
    <?php else: ?>
        
        <!-- PC 端列表 -->
        <div class="d-none d-lg-block card shadow-sm border-0 mb-4 rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4"><?php echo htmlspecialchars($l['invoice_number']); ?></th>
                            <th><?php echo htmlspecialchars($l['rep_col_date']); ?></th>
                            <th><?php echo htmlspecialchars($l['payment_method']); ?></th>
                            <th class="text-end"><?php echo htmlspecialchars($l['rep_col_total']); ?></th>
                            <th class="text-end"><?php echo htmlspecialchars($l['paid_amount']); ?></th>
                            <th class="text-end"><?php echo htmlspecialchars($l['balance_amount']); ?></th>
                            <th><?php echo htmlspecialchars($l['rep_col_cashier']); ?></th>
                            <th class="text-center d-print-none pe-4"><?php echo htmlspecialchars($l['prod_th_actions']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales_list as $row): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($row['invoice_number']); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($row['sale_date'])); ?></td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <?php 
                                            $pay_method_key = 'pay_' . strtolower(str_replace([' ', "'", '-'], '', $row['payment_method'])); 
                                            echo htmlspecialchars($l[$pay_method_key] ?? $row['payment_method']); 
                                        ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-dark-emphasis">RM <?php echo number_format($row['total_amount'], 2); ?></td>
                                <td class="text-end text-success">RM <?php echo number_format($row['paid_amount'], 2); ?></td>
                                <td class="text-end text-muted">RM <?php echo number_format($row['balance_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['cashier_name'] ?: 'N/A'); ?></td>
                                <td class="text-center d-print-none pe-4">
                                    <button class="btn btn-sm btn-outline-primary rounded-pill px-3" 
                                            onclick="showInvoiceDetails(<?php echo $row['id']; ?>)">
                                        <i class="fas fa-eye me-1"></i><?php echo htmlspecialchars($l['prod_view_details']); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 移动端流式卡片 -->
        <div class="d-block d-lg-none d-print-none">
            <?php foreach ($sales_list as $row): ?>
                <div class="card shadow-sm mb-3 rounded-4 border-start border-4 border-primary border-top-0 border-end-0 border-bottom-0">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold text-primary">#<?php echo htmlspecialchars($row['invoice_number']); ?></span>
                            <span class="badge bg-light text-dark border">
                                <?php 
                                    $pay_method_key = 'pay_' . strtolower(str_replace([' ', "'", '-'], '', $row['payment_method'])); 
                                    echo htmlspecialchars($l[$pay_method_key] ?? $row['payment_method']); 
                                ?>
                            </span>
                        </div>
                        <div class="small text-muted mb-3">
                            <i class="far fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($row['sale_date'])); ?>
                        </div>
                        <div class="row g-2 mb-3 bg-light rounded p-2 mx-0">
                            <div class="col-4">
                                <small class="text-muted d-block"><?php echo htmlspecialchars($l['rep_col_total']); ?></small>
                                <span class="fw-bold">RM <?php echo number_format($row['total_amount'], 2); ?></span>
                            </div>
                            <div class="col-4 text-center">
                                <small class="text-muted d-block"><?php echo htmlspecialchars($l['paid_amount']); ?></small>
                                <span class="text-dark">RM <?php echo number_format($row['paid_amount'], 2); ?></span>
                            </div>
                            <div class="col-4 text-end">
                                <small class="text-muted d-block"><?php echo htmlspecialchars($l['balance_amount']); ?></small>
                                <span class="text-muted">RM <?php echo number_format($row['balance_amount'], 2); ?></span>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="small text-muted">
                                <i class="far fa-user me-1"></i> <?php echo htmlspecialchars($row['cashier_name'] ?: 'N/A'); ?>
                            </span>
                            <button class="btn btn-sm btn-primary rounded-pill px-3" 
                                    onclick="showInvoiceDetails(<?php echo $row['id']; ?>)">
                                <i class="fas fa-eye me-1"></i><?php echo htmlspecialchars($l['prod_view_details']); ?>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <nav class="d-flex justify-content-between align-items-center mt-4 d-print-none">
                <div class="text-muted small">
                    <?php 
                        $showing_start = $offset + 1;
                        $showing_end = min($offset + $limit, $total_records);
                        echo htmlspecialchars($l['prod_showing'] . " " . $showing_start . " " . $l['prod_to'] . " " . $showing_end . " " . $l['prod_of'] . " " . $total_records . " " . $l['prod_records']); 
                    ?>
                </div>
                <ul class="pagination pagination-sm mb-0 rounded-pill overflow-hidden">
                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&date_filter=<?php echo urlencode($date_filter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                            <?php echo htmlspecialchars($l['prod_prev']); ?>
                        </a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&date_filter=<?php echo urlencode($date_filter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&date_filter=<?php echo urlencode($date_filter); ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                            <?php echo htmlspecialchars($l['prod_next']); ?>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- MODAL: 消费收据详情展示 (完全纯英文锁定) -->
<div class="modal fade" id="invoiceDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md">
        <div class="modal-content rounded-4 border-0 shadow-lg" style="overflow: hidden;">
            <div class="modal-header border-0 pb-0 d-print-none">
                <h5 class="modal-title fw-bold text-primary"><i class="fas fa-file-invoice-dollar me-2"></i>Official Receipt</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <div class="modal-body px-4 py-2">
                <div id="receiptPrintContent" class="p-4 bg-white text-dark rounded-3" style="font-family: 'Courier New', Courier, monospace; color: #000 !important; background-color: #fff !important; line-height: 1.4;">
                    
                    <div style="text-align: center; margin-bottom: 10px; color: #000 !important;">
                        <h4 class="fw-bold mb-1" style="font-size: 14px; margin: 0 0 4px 0; color: #000 !important;">SwiftPOS Mart</h4>
                        <div style="font-size: 10px; margin-bottom: 2px; color: #000 !important; line-height: 1.3;"><?php echo htmlspecialchars($store_address); ?></div>
                        <div style="font-size: 10px; color: #000 !important;">Tel: <?php echo htmlspecialchars($store_phone); ?> | SST ID: <?php echo htmlspecialchars($sst_reg_no); ?></div>
                        <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>
                    </div>

                    <!-- 订单元信息物理对齐 -->
                    <table style="width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important;">
                        <tr>
                            <td style="width: 55%; text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Invoice:</strong> <span id="modal_invoice_num" style="font-weight: bold;"></span>
                            </td>
                            <td style="width: 45%; text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Method:</strong> <span id="modal_invoice_payment"></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Date:</strong> <span id="modal_invoice_date"></span>
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Cashier:</strong> <span id="modal_invoice_cashier"></span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Customer:</strong> <span id="modal_invoice_customer"></span>
                            </td>
                        </tr>
                    </table>

                    <div style="border-top: 1px dashed #333; margin: 10px 0;"></div>

                    <!-- 账目细则商品列表 -->
                    <table style="width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important;">
                        <thead>
                            <tr style="border-bottom: 1px dashed #333 !important; font-weight: bold;">
                                <th style="text-align: left; padding: 4px 0; font-size: 11px; color: #000 !important;">Item</th>
                                <th style="text-align: center; padding: 4px 0; font-size: 11px; width: 40px; color: #000 !important;">Qty</th>
                                <th style="text-align: right; padding: 4px 0; font-size: 11px; width: 70px; color: #000 !important;">Price</th>
                                <th style="text-align: right; padding: 4px 0; font-size: 11px; width: 80px; color: #000 !important;">Total</th>
                            </tr>
                        </thead>
                        <tbody id="modal_items_body"></tbody>
                    </table>

                    <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>

                    <!-- 账务统计物理 Table 排版 -->
                    <table style="width: 100% !important; border-collapse: collapse !important;">
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Subtotal:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="modal_subtotal"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Discount / Voucher:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #dc3545 !important;" id="modal_discount"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                SST Service Tax (<?php echo number_format($sst_rate_percent, 2); ?>%):
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="modal_tax"></td>
                        </tr>
                        <tr style="border-top: 1px dashed #333 !important; border-bottom: 1px dashed #333 !important;">
                            <td style="text-align: left; font-size: 12px; font-weight: bold; padding: 6px 0; color: #000 !important;">
                                Grand Total:
                            </td>
                            <td style="text-align: right; font-size: 14px; font-weight: bold; padding: 6px 0; color: #198754 !important;" id="modal_grand_total"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Tendered:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="modal_paid"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Change:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="modal_change"></td>
                        </tr>
                        <tr style="border-top: 1px dashed #eee !important;">
                            <td style="text-align: left; font-size: 11px; padding: 6px 0 2px 0; color: #0d6efd !important;">
                                Accumulated Loyalty Points:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 6px 0 2px 0; color: #0d6efd !important; font-weight: bold;" id="modal_points_calc"></td>
                        </tr>
                    </table>

                    <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>

                    <div style="text-align: center; margin-top: 10px; color: #000 !important;">
                        <p class="small mb-0" style="letter-spacing: 0.5px; font-size: 11px; font-weight: bold;">*** THANK YOU FOR SHOPPING! ***</p>
                        <small class="text-muted" style="font-size: 9px;">Printed via Central DB</small>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer border-0 p-3 d-print-none bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_close']); ?></button>
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="printModalInvoice()"><i class="fas fa-print me-2"></i><?php echo htmlspecialchars($l['prod_print']); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const langSalesHistory = {
    failedLoad: <?php echo json_encode($l['failed_load_details'] ?? 'Failed to load details'); ?>,
    connectionError: <?php echo json_encode($l['connection_error_details'] ?? 'Connection error when fetching invoice details.'); ?>,
    printInvoiceTitle: <?php echo json_encode($l['print_invoice_title'] ?? 'Print Invoice'); ?>
};

document.addEventListener("DOMContentLoaded", function() {
    const filterSelect = document.getElementById('date_filter');
    if (filterSelect) {
        filterSelect.addEventListener('change', toggleDateFields);
        toggleDateFields();
    }
});

function toggleDateFields() {
    const filterSelect = document.getElementById('date_filter');
    const customFields = document.getElementById('custom_date_fields');
    if (!filterSelect || !customFields) return;
    
    if (filterSelect.value === 'custom') {
        customFields.classList.remove('d-none');
    } else {
        customFields.classList.add('d-none');
    }
}

function showInvoiceDetails(id) {
    fetch(`sales_history.php?action=get_invoice_details&id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const sale = data.sale;
                const items = data.items;

                document.getElementById('modal_invoice_num').innerText = sale.invoice_number;
                document.getElementById('modal_invoice_payment').innerText = sale.payment_method;
                document.getElementById('modal_invoice_date').innerText = sale.sale_date_formatted;
                document.getElementById('modal_invoice_cashier').innerText = sale.cashier_name || 'System';
                document.getElementById('modal_invoice_customer').innerText = sale.customer_name || 'Walk-in Customer';

                document.getElementById('modal_subtotal').innerText = "RM " + parseFloat(sale.subtotal_amount).toFixed(2);
                document.getElementById('modal_discount').innerText = "-RM " + parseFloat(sale.discount_amount).toFixed(2);
                document.getElementById('modal_tax').innerText = "RM " + parseFloat(sale.tax_amount).toFixed(2);
                document.getElementById('modal_grand_total').innerText = "RM " + parseFloat(sale.total_amount).toFixed(2);
                document.getElementById('modal_paid').innerText = "RM " + parseFloat(sale.paid_amount).toFixed(2);
                document.getElementById('modal_change').innerText = "RM " + parseFloat(sale.balance_amount).toFixed(2);

                const pointsEarned = Math.floor(parseFloat(sale.total_amount));
                document.getElementById('modal_points_calc').innerText = `+${pointsEarned} pts (Total: ${sale.customer_points || 0} pts)`;

                let itemsHtml = '';
                items.forEach(item => {
                    itemsHtml += `
                        <tr style="border-bottom: 1px dashed #eee !important;">
                            <td style="padding: 4px 0; text-align: left; font-size: 11px; vertical-align: top; color: #000 !important;">
                                ${item.product_name}
                            </td>
                            <td style="padding: 4px 0; text-align: center; font-size: 11px; vertical-align: top; color: #000 !important;">${item.quantity}</td>
                            <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; color: #000 !important;">RM ${parseFloat(item.unit_price).toFixed(2)}</td>
                            <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; font-weight: bold; color: #000 !important;">RM ${parseFloat(item.subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                });
                document.getElementById('modal_items_body').innerHTML = itemsHtml;

                const detailModal = new bootstrap.Modal(document.getElementById('invoiceDetailModal'));
                detailModal.show();
            } else {
                alert(data.message || langSalesHistory.failedLoad);
            }
        })
        .catch(err => {
            console.error("AJAX Error: ", err);
            alert(langSalesHistory.connectionError);
        });
}

function printModalInvoice() {
    const printContent = document.getElementById("receiptPrintContent");
    if (!printContent) return;

    let iframe = document.getElementById("history-print-iframe-sandbox");
    if (iframe) iframe.remove();

    iframe = document.createElement("iframe");
    iframe.id = "history-print-iframe-sandbox";
    // 💡 核心修改 1：离屏隐藏定位并强行声明宽度，使浏览器强制解析 @page 规则，彻底隐藏页眉页脚
    iframe.style.position = "absolute";
    iframe.style.left = "-9999px";
    iframe.style.top = "-9999px";
    iframe.style.width = "74mm";
    iframe.style.height = "1px";
    iframe.style.opacity = "0";
    iframe.style.border = "none";
    document.body.appendChild(iframe);

    const doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title></title> 
            <style>
                @page { 
                    size: 80mm auto; 
                    margin: 0mm !important; 
                }
                @media print {
                    @page { margin: 0 !important; }
                    body { margin: 0 !important; }
                }
                html {
                    height: auto !important;
                    overflow: visible !important;
                }
                body { 
                    margin: 0 !important; 
                    /* 💡 核心修改 2：Padding 底部预留 15mm 安全走纸距离，防止切纸时切坏文字 */
                    padding: 2mm 3mm 15mm 3mm !important; 
                    background-color: #fff !important; 
                    width: 74mm !important; 
                    box-sizing: border-box !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    height: auto !important;
                    overflow: visible !important;
                }
                * { 
                    font-size: 11px !important; 
                    line-height: 1.4 !important;
                    font-family: 'Courier New', Courier, monospace !important; 
                    color: #000 !important;
                    height: auto !important;
                    min-height: 0 !important;
                    position: static !important;
                    box-sizing: border-box !important;
                    page-break-inside: avoid !important;
                    break-inside: avoid !important;
                }
                table { 
                    width: 100% !important; 
                    table-layout: fixed !important; 
                    word-wrap: break-word !important; 
                    border-collapse: collapse !important;
                }
                th, td { 
                    border: none !important; 
                    padding: 2px 0 !important;
                }
            </style>
        </head>
        <body>
            <div>
                ${printContent.innerHTML}
            </div>
        </body>
        </html>
    `);
    doc.close();

    // 💡 核心修改 3：清空父页面和子 iframe 的 Title 以彻底屏蔽“BOH”文字
    const originalParentTitle = window.parent.document.title || document.title;
    window.parent.document.title = ""; 
    doc.title = "";

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(() => {
            window.parent.document.title = originalParentTitle; // 还原标题
            if (iframe) iframe.remove();
        }, 1000);
    }, 300);
}
</script>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>