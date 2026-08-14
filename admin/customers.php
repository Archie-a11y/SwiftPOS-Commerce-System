<?php
// =========================================================================
// SwiftPOS Commerce Management System - Customer Loyalty Module
// =========================================================================

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 引入全局公共组件和头文件
require_once '../includes/header.php';
require_once '../includes/alerts.php';

// 安全防范：仅允许 Administrator 角色访问
if ($_SESSION['role'] !== 'Administrator') {
    echo "<div class='alert alert-danger m-4'><i class='fas fa-ban me-2'></i>" . htmlspecialchars($l['pos_err_unauthorized'] ?? 'Access Denied. Only Administrators can access this module.') . "</div>";
    require_once '../includes/footer.php';
    exit();
}

$success_message = '';
$error_message = '';

// -------------------------------------------------------------------------
// 1. AJAX 获取特定会员的历史消费记录接口 (返回 JSON 数据)
// -------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_history') {
    header('Content-Type: application/json; charset=utf-8');
    $customer_id = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);
    
    if (!$customer_id) {
        echo json_encode([]);
        exit();
    }
    
    $stmt = $conn->prepare("SELECT id, invoice_number, sale_date, total_amount, payment_method FROM sales WHERE customer_id = ? ORDER BY sale_date DESC");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $history = [];
    while ($row = $res->fetch_assoc()) {
        $history[] = [
            'id' => $row['id'],
            'invoice_number' => $row['invoice_number'],
            'sale_date' => date('d/m/Y H:i', strtotime($row['sale_date'])),
            'total_amount' => number_format($row['total_amount'], 2),
            'payment_method' => $row['payment_method']
        ];
    }
    
    echo json_encode($history);
    exit();
}

// -------------------------------------------------------------------------
// 2. 处理表单提交 (POST Action - 增、改、删)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // A. 添加新会员 (去除 Email 字段)
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $discount = filter_input(INPUT_POST, 'membership_discount', FILTER_VALIDATE_FLOAT) ?? 0.00;
        $points = filter_input(INPUT_POST, 'loyalty_points', FILTER_VALIDATE_INT) ?? 0;
        
        $membership_number = 'MBR-' . date('Ymd') . '-' . mt_rand(1000, 9999);

        if (empty($name) || empty($phone)) {
            $error_message = $l['prod_err_invalid_fields'];
        } else {
            // 查重 (手机号唯一约束)
            $chk = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
            $chk->bind_param("s", $phone);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error_message = $l['cust_err_exists'];
            } else {
                $stmt = $conn->prepare("INSERT INTO customers (membership_number, name, phone, loyalty_points, membership_discount) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("sssid", $membership_number, $name, $phone, $points, $discount);
                if ($stmt->execute()) {
                    $success_message = $l['cust_success_add'];
                } else {
                    $error_message = $l['err_generic'];
                }
                $stmt->close();
            }
            $chk->close();
        }
    }

    // B. 编辑修改会员信息 (去除 Email 字段)
    elseif ($action === 'edit') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $discount = filter_input(INPUT_POST, 'membership_discount', FILTER_VALIDATE_FLOAT) ?? 0.00;
        $points = filter_input(INPUT_POST, 'loyalty_points', FILTER_VALIDATE_INT) ?? 0;

        if (!$id || empty($name) || empty($phone)) {
            $error_message = $l['prod_err_invalid_fields'];
        } else {
            // 查重手机号，排除自身
            $chk = $conn->prepare("SELECT id FROM customers WHERE phone = ? AND id != ?");
            $chk->bind_param("si", $phone, $id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $error_message = $l['cust_err_exists'];
            } else {
                $stmt = $conn->prepare("UPDATE customers SET name = ?, phone = ?, loyalty_points = ?, membership_discount = ? WHERE id = ?");
                $stmt->bind_param("ssidi", $name, $phone, $points, $discount, $id);
                if ($stmt->execute()) {
                    $success_message = $l['cust_success_edit'];
                } else {
                    $error_message = $l['err_generic'];
                }
                $stmt->close();
            }
            $chk->close();
        }
    }

    // C. 物理删除会员档案
    elseif ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $success_message = $l['cust_success_delete'];
            } else {
                $error_message = $l['err_generic'];
            }
            $stmt->close();
        }
    }
}

// -------------------------------------------------------------------------
// 3. 统计核心指标看板
// -------------------------------------------------------------------------
$total_members = $conn->query("SELECT COUNT(*) FROM customers")->fetch_row()[0] ?? 0;
$active_members = $conn->query("SELECT COUNT(*) FROM customers WHERE loyalty_points > 100")->fetch_row()[0] ?? 0;
$avg_discount = $conn->query("SELECT AVG(membership_discount) FROM customers")->fetch_row()[0] ?? 0.00;
$top_member_res = $conn->query("SELECT name, loyalty_points FROM customers ORDER BY loyalty_points DESC LIMIT 1")->fetch_assoc();
$top_member_name = $top_member_res['name'] ?? 'N/A';
$top_member_pts = $top_member_res['loyalty_points'] ?? 0;

// -------------------------------------------------------------------------
// 4. 数据查询、搜索及分页逻辑
// -------------------------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$where_clause = "1=1";
if (!empty($search)) {
    $where_clause = "(name LIKE ? OR phone LIKE ? OR membership_number LIKE ?)";
    $search_param = "%$search%";
}

// 分页记录数查询
$count_sql = "SELECT COUNT(*) FROM customers WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
if (!empty($search)) {
    $count_stmt->bind_param("sss", $search_param, $search_param, $search_param);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_row()[0] ?? 0;
$total_pages = ceil($total_rows / $limit);

if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// 检索会员列表
$sql = "SELECT * FROM customers WHERE $where_clause ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt_cust = $conn->prepare($sql);
if (!empty($search)) {
    $stmt_cust->bind_param("sssii", $search_param, $search_param, $search_param, $limit, $offset);
} else {
    $stmt_cust->bind_param("ii", $limit, $offset);
}
$stmt_cust->execute();
$customers = $stmt_cust->get_result()->fetch_all(MYSQLI_ASSOC);
?>

<div class="container-fluid px-0">
    <!-- 头部区域 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1 text-primary"><?= htmlspecialchars($l['cust_title']) ?></h2>
            <p class="text-muted small mb-0 d-none d-sm-block"><?= htmlspecialchars($l['cust_subtitle']) ?></p>
        </div>
        <button class="btn btn-primary rounded-pill px-4 py-2.5" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="fas fa-user-plus me-2"></i><?= htmlspecialchars($l['cust_add_btn']) ?>
        </button>
    </div>

    <!-- 消息提示 -->
    <?php render_shared_alerts($l, $success_message, $error_message); ?>

    <!-- 看板核心指标区域 -->
    <div class="row g-3 mb-4">
        <!-- 注册会员总数 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-primary bg-opacity-10 text-primary me-3">
                        <i class="fas fa-users fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?= htmlspecialchars($l['nav_customers']) ?></small>
                        <h4 class="fw-bold mb-0 text-body-emphasis"><?= number_format($total_members) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 活跃会员 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-success bg-opacity-10 text-success me-3">
                        <i class="fas fa-user-check fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?= htmlspecialchars($l['users_tbl_status']) ?> (<?= htmlspecialchars($l['prod_active'] ?? 'Active') ?>)</small>
                        <h4 class="fw-bold mb-0 text-body-emphasis"><?= number_format($active_members) ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 会员平均特权折扣 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-info bg-opacity-10 text-info me-3">
                        <i class="fas fa-percent fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?= htmlspecialchars($l['cust_avg_discount'] ?? 'Avg. Discount') ?></small>
                        <h4 class="fw-bold mb-0 text-body-emphasis"><?= number_format($avg_discount, 2) ?>%</h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 积分榜首会员 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-warning bg-opacity-10 text-warning me-3">
                        <i class="fas fa-crown fa-lg"></i>
                    </div>
                    <div class="min-width-0">
                        <small class="text-muted d-block text-truncate"><?= htmlspecialchars($l['cust_top_member'] ?? 'Top Member') ?></small>
                        <h5 class="fw-bold mb-0 text-truncate text-body-emphasis" title="<?= htmlspecialchars($top_member_name) ?> (<?= $top_member_pts ?> Pts)">
                            <?= htmlspecialchars($top_member_name) ?>
                        </h5>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 过滤器与搜索栏 -->
    <div class="card border-0 shadow-sm p-3 mb-4 rounded-4">
        <form method="GET" class="row g-3 align-items-center">
            <div class="col-12 col-md-9">
                <div class="input-group">
                    <span class="input-group-text bg-transparent border-end-0 text-muted rounded-start-3 py-2.5">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0 rounded-end-3 py-2.5" placeholder="<?= htmlspecialchars($l['cust_search_placeholder']) ?>" value="<?= htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-12 col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-secondary w-100 rounded-3 py-2.5">
                    <i class="fas fa-filter me-2"></i><?= htmlspecialchars($l['prod_filter_btn_filter']) ?>
                </button>
                <a href="customers.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-center rounded-3 px-3 py-2.5" title="<?= htmlspecialchars($l['prod_undo'] ?? 'Reset') ?>">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- 会员台账列表 (已去除邮箱字段并解决深色模式字体消失问题) -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= htmlspecialchars($l['cust_tbl_mbr_no']) ?></th>
                        <th><?= htmlspecialchars($l['cust_tbl_name']) ?></th>
                        <th><?= htmlspecialchars($l['cust_tbl_phone']) ?></th>
                        <th class="text-center"><?= htmlspecialchars($l['cust_tbl_points']) ?></th>
                        <th class="text-center"><?= htmlspecialchars($l['cust_tbl_discount']) ?></th>
                        <th class="text-end d-print-none"><?= htmlspecialchars($l['cust_tbl_actions']) ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted"><?= htmlspecialchars($l['prod_no_found']) ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($c['membership_number']) ?></td>
                                <td class="fw-semibold text-body-emphasis"><?= htmlspecialchars($c['name']) ?></td>
                                <td class="text-body"><?= htmlspecialchars($c['phone']) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-warning text-dark px-3 rounded-pill">
                                        <i class="fas fa-coins me-1"></i><?= $c['loyalty_points'] ?>
                                    </span>
                                </td>
                                <td class="text-center fw-bold text-success"><?= number_format($c['membership_discount'], 2) ?>%</td>
                                <td class="text-end d-print-none">
                                    <div class="d-inline-flex gap-1">
                                        <button class="btn btn-sm btn-outline-info rounded-pill px-2" title="<?= htmlspecialchars($l['cust_modal_history']) ?>" onclick="viewHistory(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-2" title="<?= htmlspecialchars($l['prod_edit_product']) ?>" onclick="editCustomer(<?= htmlspecialchars(json_encode($c)) ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger rounded-pill px-2" title="<?= htmlspecialchars($l['prod_delete']) ?>" onclick="confirmDelete(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['name'])) ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页控制器 -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center p-4">
                <span class="small text-muted">
                    <?= htmlspecialchars($l['prod_showing']) ?> <?= $offset + 1 ?> <?= htmlspecialchars($l['prod_to']) ?> <?= min($offset + $limit, $total_rows) ?> <?= htmlspecialchars($l['prod_of']) ?> <?= $total_rows ?> <?= htmlspecialchars($l['prod_records']) ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>"><?= htmlspecialchars($l['prod_prev']) ?></a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>"><?= htmlspecialchars($l['prod_next']) ?></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ==================== 模态框组 (Modals) ==================== -->

<!-- A. 添加会员 Modal (移除 Email 输入) -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-user-plus me-2 text-primary"></i><?= htmlspecialchars($l['cust_modal_add']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_name']) ?> *</label>
                        <input type="text" name="name" class="form-control rounded-3 py-2" placeholder="e.g. Ahmad Daniel" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_phone']) ?> *</label>
                        <input type="text" name="phone" class="form-control rounded-3 py-2" placeholder="e.g. 0123456789" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_discount']) ?> (%)</label>
                            <input type="number" step="0.01" name="membership_discount" class="form-control rounded-3 py-2" value="2.00" min="0" max="100">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_points']) ?></label>
                            <input type="number" name="loyalty_points" class="form-control rounded-3 py-2" value="10">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= htmlspecialchars($l['prod_btn_cancel']) ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= htmlspecialchars($l['prod_btn_save']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- B. 编辑会员 Modal (移除 Email 输入) -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-edit me-2 text-primary"></i><?= htmlspecialchars($l['cust_modal_edit']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_name']) ?> *</label>
                        <input type="text" name="name" id="edit_name" class="form-control rounded-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_phone']) ?> *</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control rounded-3 py-2" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_discount']) ?> (%)</label>
                            <input type="number" step="0.01" name="membership_discount" id="edit_discount" class="form-control rounded-3 py-2" min="0" max="100">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?= htmlspecialchars($l['cust_tbl_points']) ?></label>
                            <input type="number" name="loyalty_points" id="edit_points" class="form-control rounded-3 py-2">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= htmlspecialchars($l['prod_btn_cancel']) ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= htmlspecialchars($l['prod_btn_update']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- C. 安全删除确认 Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border-0">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-body p-4 text-center">
                    <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                    <h5 class="fw-bold text-body-emphasis"><?= htmlspecialchars($l['prod_modal_delete_title']) ?></h5>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($l['prod_delete_warning']) ?> <strong id="delete_cust_name" class="text-body-emphasis"></strong>.</p>
                </div>
                <div class="modal-footer border-0 d-flex justify-content-between pt-0">
                    <button type="button" class="btn btn-outline-secondary px-4 rounded-pill" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-danger px-4 rounded-pill"><?php echo htmlspecialchars($l['prod_btn_delete_confirm']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- D. 会员历史消费账单查询 Modal -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-receipt text-primary me-2"></i><span id="history_member_title"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="table-responsive overflow-auto">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= htmlspecialchars($l['invoice_number'] ?? 'Invoice Number') ?></th>
                                <th><?= htmlspecialchars($l['rep_col_date'] ?? 'Transaction Date') ?></th>
                                <th><?= htmlspecialchars($l['payment_method'] ?? 'Payment Method') ?></th>
                                <th class="text-end"><?= htmlspecialchars($l['paid_amount'] ?? 'Paid Amount') ?></th>
                            </tr>
                        </thead>
                        <tbody id="history_tbody">
                            <!-- JS 动态注入节点 -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= htmlspecialchars($l['prod_btn_close']) ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function editCustomer(c) {
    document.getElementById('edit_id').value = c.id;
    document.getElementById('edit_name').value = c.name;
    document.getElementById('edit_phone').value = c.phone;
    document.getElementById('edit_discount').value = c.membership_discount;
    document.getElementById('edit_points').value = c.loyalty_points;

    new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
}

function confirmDelete(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_cust_name').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}

function viewHistory(id, name) {
    document.getElementById('history_member_title').innerText = name + ' - <?= addslashes($l['cust_modal_history']) ?>';
    const tbody = document.getElementById('history_tbody');
    tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></td></tr>`;
    
    new bootstrap.Modal(document.getElementById('historyModal')).show();

    fetch(`customers.php?action=get_history&customer_id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-muted"><?= addslashes($l['cust_no_history']) ?></td></tr>`;
            } else {
                let html = '';
                data.forEach(row => {
                    html += `
                        <tr>
                            <td class="fw-bold text-primary">#${row.invoice_number}</td>
                            <td class="text-body">${row.sale_date}</td>
                            <td><span class="badge bg-light text-dark border">${row.payment_method}</span></td>
                            <td class="text-end fw-bold text-success">RM ${row.total_amount}</td>
                        </tr>
                    `;
                });
                tbody.innerHTML = html;
            }
        })
        .catch(err => {
            console.error("AJAX error: ", err);
            tbody.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-4"><?= addslashes($l['cust_err_fetch_history'] ?? 'Failed to fetch data.') ?></td></tr>`;
        });
}
</script>

<?php
require_once '../includes/footer.php';
?>