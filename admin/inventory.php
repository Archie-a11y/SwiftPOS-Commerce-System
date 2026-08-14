<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';
require_once '../includes/alerts.php';

if ($_SESSION['role'] !== 'Administrator') {
    echo "<div class='alert alert-danger m-4'><i class='fas fa-ban me-2'></i>" . htmlspecialchars($l['inv_access_denied'] ?? 'Access Denied. Only Administrators can access this module.') . "</div>";
    require_once '../includes/footer.php';
    exit();
}

$success_message = '';
$error_message = '';

// -------------------------------------------------------------------------
// 1. 处理 POST 业务：库存校准与库存物理划拨
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // A. 基础库存校准逻辑
    if ($action === 'adjust_stock') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $adjust_type = $_POST['type'] ?? ''; 
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $reason = trim($_POST['reason'] ?? '');
        $user_id = $_SESSION['user_id'];

        if (!$product_id || !in_array($adjust_type, ['Add', 'Subtract']) || $quantity <= 0 || empty($reason)) {
            $error_message = $l['inv_err_input'];
        } else {
            $stmt = $conn->prepare("SELECT stock_quantity, name FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();

            if (!$product) {
                $error_message = $l['prod_no_found'];
            } elseif ($adjust_type === 'Subtract' && $product['stock_quantity'] < $quantity) {
                $error_message = $l['inv_err_insufficient'];
            } else {
                $conn->begin_transaction();
                try {
                    $stmt_adj = $conn->prepare("INSERT INTO stock_adjustments (product_id, type, quantity, reason, adjusted_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt_adj->bind_param("isssi", $product_id, $adjust_type, $quantity, $reason, $user_id);
                    $stmt_adj->execute();

                    if ($adjust_type === 'Add') {
                        $stmt_upd = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    } else {
                        $stmt_upd = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                    }
                    $stmt_upd->bind_param("ii", $quantity, $product_id);
                    $stmt_upd->execute();

                    // 写入系统合规日志
                    log_activity($conn, $user_id, 'Stock Adjustment', "Manually adjusted {$product['name']} (Quantity: {$quantity}, Action: {$adjust_type})");

                    $conn->commit();
                    $success_message = $l['inv_success_adjusted'] . " (" . htmlspecialchars($product['name']) . ")";
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = $l['err_generic'] . " (" . $e->getMessage() . ")";
                }
            }
        }
    }

    // B. 物理仓库/分店货架库存物理划拨业务控制 (Stock Transfer)
    elseif ($action === 'transfer_stock') {
        $product_id = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
        $from_loc = trim($_POST['from_location'] ?? '');
        $to_loc = trim($_POST['to_location'] ?? '');
        $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
        $user_id = $_SESSION['user_id'];

        if (!$product_id || empty($from_loc) || empty($to_loc) || $quantity <= 0 || $from_loc === $to_loc) {
            $error_message = $l['inv_err_distinct_locations'] ?? "Invalid transfer parameters. Source and Destination locations must be distinct.";
        } else {
            $stmt = $conn->prepare("SELECT stock_quantity, name FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();

            // 若从前台(Shelf)调离到其他地方，需要验证当前上架库存是否充盈
            if (!$product) {
                $error_message = $l['prod_no_found'];
            } elseif ($from_loc === 'Shelf' && $product['stock_quantity'] < $quantity) {
                $error_message = $l['inv_err_transfer_insufficient'] ?? "Transfer failed. Insufficient stock on the Front Shelf.";
            } else {
                $conn->begin_transaction();
                try {
                    // 1. 录入 stock_transfers 明细账
                    $stmt_tr = $conn->prepare("INSERT INTO stock_transfers (product_id, from_location, to_location, quantity, transferred_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt_tr->bind_param("isssi", $product_id, $from_loc, $to_loc, $quantity, $user_id);
                    $stmt_tr->execute();

                    // 2. 调出前台
                    if ($from_loc === 'Shelf') {
                        $stmt_upd = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                        $stmt_upd->bind_param("ii", $quantity, $product_id);
                        $stmt_upd->execute();
                    }
                    // 3. 调入前台货架
                    if ($to_loc === 'Shelf') {
                        $stmt_upd = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                        $stmt_upd->bind_param("ii", $quantity, $product_id);
                        $stmt_upd->execute();
                    }

                    // 写入系统审计日志
                    log_activity($conn, $user_id, 'Stock Transfer', "Transferred {$product['name']} (Qty: {$quantity}) from [{$from_loc}] to [{$to_loc}]");

                    $conn->commit();
                    $success_message = sprintf($l['inv_success_transfer_committed'] ?? "Stock transfer committed successfully! [%s - Qty: %d]", $product['name'], $quantity);
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = $l['err_generic'] . " (" . $e->getMessage() . ")";
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// 2. 统计四大卡片核心指标
// -------------------------------------------------------------------------
$total_stock_qty = $conn->query("SELECT SUM(stock_quantity) FROM products WHERE status = 'Active'")->fetch_row()[0] ?? 0;
$low_stock_count = $conn->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_level AND stock_quantity > 0 AND status = 'Active'")->fetch_row()[0] ?? 0;
$out_of_stock_count = $conn->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0 AND status = 'Active'")->fetch_row()[0] ?? 0;
$total_inventory_value = $conn->query("SELECT SUM(cost_price * stock_quantity) FROM products WHERE status = 'Active'")->fetch_row()[0] ?? 0.00;

// -------------------------------------------------------------------------
// 3. 数据过滤筛选、标签页及分页检索
// -------------------------------------------------------------------------
$tab = $_GET['tab'] ?? 'status';
if (!in_array($tab, ['status', 'logs', 'transfers'])) {
    $tab = 'status';
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status_filter']) ? $_GET['status_filter'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// A. 实时库存状态标签数据流查询
$where_clauses = ["status = 'Active'"];
if (!empty($search)) {
    $where_clauses[] = "(name LIKE ? OR product_code LIKE ? OR barcode LIKE ?)";
    $search_param = "%$search%";
}
if ($status_filter === 'low') {
    $where_clauses[] = "stock_quantity <= min_stock_level AND stock_quantity > 0";
} elseif ($status_filter === 'out') {
    $where_clauses[] = "stock_quantity = 0";
} elseif ($status_filter === 'normal') {
    $where_clauses[] = "stock_quantity > min_stock_level";
}
$where_sql = implode(" AND ", $where_clauses);

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE $where_sql");
if (!empty($search)) {
    $count_stmt->bind_param("sss", $search_param, $search_param, $search_param);
}
$count_stmt->execute();
$total_rows = $count_stmt->get_result()->fetch_row()[0] ?? 0;
$total_pages = ceil($total_rows / $limit);

$stmt_products = $conn->prepare("SELECT * FROM products WHERE $where_sql ORDER BY name ASC LIMIT ? OFFSET ?");
if (!empty($search)) {
    $stmt_products->bind_param("sssii", $search_param, $search_param, $search_param, $limit, $offset);
} else {
    $stmt_products->bind_param("ii", $limit, $offset);
}
$stmt_products->execute();
$products = $stmt_products->get_result()->fetch_all(MYSQLI_ASSOC);

// B. 日志审计标签页数据查询
$log_search = isset($_GET['log_search']) ? trim($_GET['log_search']) : '';
$log_page = isset($_GET['log_page']) ? (int)$_GET['log_page'] : 1;
if ($log_page < 1) $log_page = 1;
$log_offset = ($log_page - 1) * $limit;

$log_where = ["1=1"];
if (!empty($log_search)) {
    $log_where[] = "(p.name LIKE ? OR p.product_code LIKE ? OR u.full_name LIKE ?)";
    $log_search_param = "%$log_search%";
}
$log_where_sql = implode(" AND ", $log_where);

$log_count_stmt = $conn->prepare("SELECT COUNT(*) FROM stock_adjustments sa JOIN products p ON sa.product_id = p.id JOIN users u ON sa.adjusted_by = u.id WHERE $log_where_sql");
if (!empty($log_search)) {
    $log_count_stmt->bind_param("sss", $log_search_param, $log_search_param, $log_search_param);
}
$log_count_stmt->execute();
$log_total_rows = $log_count_stmt->get_result()->fetch_row()[0] ?? 0;
$log_total_pages = ceil($log_total_rows / $limit);

$stmt_logs = $conn->prepare("SELECT sa.*, p.name as product_name, p.product_code, u.full_name as user_name FROM stock_adjustments sa JOIN products p ON sa.product_id = p.id JOIN users u ON sa.adjusted_by = u.id WHERE $log_where_sql ORDER BY sa.adjustment_date DESC LIMIT ? OFFSET ?");
if (!empty($log_search)) {
    $stmt_logs->bind_param("sssii", $log_search_param, $log_search_param, $log_search_param, $limit, $log_offset);
} else {
    $stmt_logs->bind_param("ii", $limit, $log_offset);
}
$stmt_logs->execute();
$logs = $stmt_logs->get_result()->fetch_all(MYSQLI_ASSOC);

// C. 物理划拨历史数据流查询
$tr_search = isset($_GET['tr_search']) ? trim($_GET['tr_search']) : '';
$tr_page = isset($_GET['tr_page']) ? (int)$_GET['tr_page'] : 1;
if ($tr_page < 1) $tr_page = 1;
$tr_offset = ($tr_page - 1) * $limit;

$tr_where = ["1=1"];
if (!empty($tr_search)) {
    $tr_where[] = "(p.name LIKE ? OR p.product_code LIKE ? OR u.full_name LIKE ?)";
    $tr_search_param = "%$tr_search%";
}
$tr_where_sql = implode(" AND ", $tr_where);

$tr_count_stmt = $conn->prepare("SELECT COUNT(*) FROM stock_transfers st JOIN products p ON st.product_id = p.id JOIN users u ON st.transferred_by = u.id WHERE $tr_where_sql");
if (!empty($tr_search)) {
    $tr_count_stmt->bind_param("sss", $tr_search_param, $tr_search_param, $tr_search_param);
}
$tr_count_stmt->execute();
$tr_total_rows = $tr_count_stmt->get_result()->fetch_row()[0] ?? 0;
$tr_total_pages = ceil($tr_total_rows / $limit);

$stmt_transfers = $conn->prepare("SELECT st.*, p.name as product_name, p.product_code, u.full_name as user_name FROM stock_transfers st JOIN products p ON st.product_id = p.id JOIN users u ON st.transferred_by = u.id WHERE $tr_where_sql ORDER BY st.transfer_date DESC LIMIT ? OFFSET ?");
if (!empty($tr_search)) {
    $stmt_transfers->bind_param("sssii", $tr_search_param, $tr_search_param, $tr_search_param, $limit, $tr_offset);
} else {
    $stmt_transfers->bind_param("ii", $limit, $tr_offset);
}
$stmt_transfers->execute();
$transfers = $stmt_transfers->get_result()->fetch_all(MYSQLI_ASSOC);

// 获取全部激活商品用于划拨下拉框
$active_all_products = $conn->query("SELECT id, product_code, name FROM products WHERE status = 'Active' ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

// 本地化划拨地点对照字典
$loc_translation_map = [
    'Warehouse' => $l['inv_loc_warehouse'] ?? 'Back Warehouse',
    'Shelf' => $l['inv_loc_shelf'] ?? 'Front Shelf',
    'Branch_Store' => $l['inv_loc_branch'] ?? 'Sub-Branch'
];
?>

<!-- 模块头部区域 -->
<div class="row mb-3">
    <div class="col-md-8">
        <h2 class="fw-bold text-primary mb-1"><?= htmlspecialchars($l['inv_title']) ?></h2>
        <p class="text-muted"><?= htmlspecialchars($l['inv_subtitle']) ?></p>
    </div>
</div>

<!-- 处理状态警示反馈区域 -->
<?php render_shared_alerts($l, $success_message, $error_message); ?>

<!-- 看板核心指标区域 -->
<div class="row g-3 mb-4">
    <!-- 当前总库存 -->
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 rounded-4">
            <div class="card-body d-flex align-items-center p-3">
                <div class="bg-primary bg-opacity-10 text-primary rounded-3 p-3 me-3 d-none d-md-block">
                    <i class="fas fa-boxes-stacked fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted d-block text-truncate"><?= htmlspecialchars($l['inv_card_current']) ?></small>
                    <h3 class="fw-bold mb-0 mt-1"><?= number_format($total_stock_qty) ?></h3>
                </div>
            </div>
        </div>
    </div>
    
    <!-- 低库存警告 -->
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 rounded-4">
            <div class="card-body d-flex align-items-center p-3">
                <div class="bg-warning bg-opacity-10 text-warning rounded-3 p-3 me-3 d-none d-md-block">
                    <i class="fas fa-triangle-exclamation fa-2x <?= $low_stock_count > 0 ? 'animate-pulse' : '' ?>"></i>
                </div>
                <div>
                    <small class="text-muted d-block text-truncate"><?= htmlspecialchars($l['inv_card_low']) ?></small>
                    <h3 class="fw-bold mb-0 mt-1 <?= $low_stock_count > 0 ? 'text-warning' : '' ?>"><?= number_format($low_stock_count) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- 缺货预警 -->
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 rounded-4">
            <div class="card-body d-flex align-items-center p-3">
                <div class="bg-danger bg-opacity-10 text-danger rounded-3 p-3 me-3 d-none d-md-block">
                    <i class="fas fa-circle-xmark fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted d-block text-truncate"><?= htmlspecialchars($l['inv_card_out']) ?></small>
                    <h3 class="fw-bold mb-0 mt-1 <?= $out_of_stock_count > 0 ? 'text-danger' : '' ?>"><?= number_format($out_of_stock_count) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- 预估估值 -->
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 rounded-4">
            <div class="card-body d-flex align-items-center p-3">
                <div class="bg-success bg-opacity-10 text-success rounded-3 p-3 me-3 d-none d-md-block">
                    <i class="fas fa-sack-dollar fa-2x"></i>
                </div>
                <div>
                    <small class="text-muted d-block text-truncate"><?= htmlspecialchars($l['inv_card_value']) ?></small>
                    <h3 class="fw-bold mb-0 mt-1 text-success">RM <?= number_format($total_inventory_value, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 选项卡页面控制 -->
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link rounded-pill px-4 <?= $tab === 'status' ? 'active' : 'bg-white text-secondary shadow-sm' ?>" href="inventory.php?tab=status">
            <i class="fas fa-warehouse me-2"></i><?= htmlspecialchars($l['inv_tab_status']) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link rounded-pill px-4 <?= $tab === 'logs' ? 'active' : 'bg-white text-secondary shadow-sm' ?>" href="inventory.php?tab=logs">
            <i class="fas fa-clock-rotate-left me-2"></i><?= htmlspecialchars($l['inv_tab_log']) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link rounded-pill px-4 <?= $tab === 'transfers' ? 'active' : 'bg-white text-secondary shadow-sm' ?>" href="inventory.php?tab=transfers">
            <i class="fas fa-right-left me-2"></i><?= htmlspecialchars($l['inv_tab_transfers'] ?? 'Stock Transfers') ?>
        </a>
    </li>
</ul>

<!-- -------------------------------------------------------------------------
     A. 实时库存台账标签页 (Tab: Stock Status)
     ------------------------------------------------------------------------- -->
<?php if ($tab === 'status'): ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="GET" action="inventory.php" class="row g-3 mb-4 align-items-center">
                <input type="hidden" name="tab" value="status">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0 rounded-start-3 text-muted">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="search" class="form-control border-start-0 rounded-end-3" placeholder="<?= htmlspecialchars($l['inv_search_placeholder']) ?>" value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="status_filter" class="form-select rounded-3">
                        <option value=""><?= htmlspecialchars($l['inv_alert_status_all']) ?></option>
                        <option value="normal" <?= $status_filter === 'normal' ? 'selected' : '' ?>><?= htmlspecialchars($l['inv_alert_status_normal']) ?></option>
                        <option value="low" <?= $status_filter === 'low' ? 'selected' : '' ?>><?= htmlspecialchars($l['inv_alert_status_low']) ?></option>
                        <option value="out" <?= $status_filter === 'out' ? 'selected' : '' ?>><?= htmlspecialchars($l['inv_alert_status_out']) ?></option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 rounded-3">
                        <i class="fas fa-sliders me-2"></i><?= htmlspecialchars($l['inv_filter_btn']) ?>
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr class="table-light">
                            <th><?= htmlspecialchars($l['inv_th_sku']) ?></th>
                            <th><?= htmlspecialchars($l['inv_th_product']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_th_stock']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_th_min']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_th_status']) ?></th>
                            <th class="text-end d-print-none"><?= htmlspecialchars($l['prod_th_actions']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-folder-open fa-2x d-block mb-2"></i><?= htmlspecialchars($l['inv_no_records']) ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $row): 
                                $current_qty = $row['stock_quantity'];
                                $min_qty = $row['min_stock_level'];
                                if ($current_qty == 0) {
                                    $badge_html = '<span class="badge bg-danger rounded-pill px-3 py-2">' . htmlspecialchars($l['prod_out_of_stock']) . '</span>';
                                } elseif ($current_qty <= $min_qty) {
                                    $badge_html = '<span class="badge bg-warning text-dark rounded-pill px-3 py-2">' . sprintf($l['prod_low_stock_format'], $min_qty) . '</span>';
                                } else {
                                    $badge_html = '<span class="badge bg-success rounded-pill px-3 py-2">' . htmlspecialchars($l['inv_normal_label']) . '</span>';
                                }
                            ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($row['product_code']) ?></td>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($row['name']) ?></div>
                                        <?php if (!empty($row['barcode'])): ?>
                                            <small class="text-muted"><i class="fas fa-barcode me-1"></i><?= htmlspecialchars($row['barcode']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center fw-bold fs-5 <?= $current_qty <= $min_qty ? 'text-danger' : 'text-success' ?>"><?= number_format($current_qty) ?></td>
                                    <td class="text-center text-muted"><?= number_format($min_qty) ?></td>
                                    <td class="text-center"><?= $badge_html ?></td>
                                    <td class="text-end d-print-none">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#adjustStockModal" 
                                                data-id="<?= $row['id'] ?>" data-name="<?= htmlspecialchars($row['name']) ?>" data-qty="<?= $row['stock_quantity'] ?>">
                                            <i class="fas fa-scale-balanced me-1"></i><?= htmlspecialchars($l['inv_btn_adjust']) ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=status&page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>"><?= htmlspecialchars($l['prod_prev']) ?></a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= $page == $i ? 'active' : '' ?>">
                                <a class="page-link" href="inventory.php?tab=status&page=<?= $i ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=status&page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status_filter=<?= urlencode($status_filter) ?>"><?= htmlspecialchars($l['prod_next']) ?></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- -------------------------------------------------------------------------
     B. 库存变更历史日志审计板 (Tab: Adjustment Logs)
     ------------------------------------------------------------------------- -->
<?php if ($tab === 'logs'): ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form method="GET" action="inventory.php" class="row g-3 mb-4 align-items-center">
                <input type="hidden" name="tab" value="logs">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0 rounded-start-3 text-muted">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="log_search" class="form-control border-start-0 rounded-end-3" placeholder="<?= htmlspecialchars($l['inv_log_search_placeholder']) ?>" value="<?= htmlspecialchars($log_search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 rounded-3">
                        <i class="fas fa-filter me-2"></i><?= htmlspecialchars($l['inv_filter_btn']) ?>
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr class="table-light">
                            <th><?= htmlspecialchars($l['inv_log_th_date']) ?></th>
                            <th><?= htmlspecialchars($l['inv_th_sku']) ?></th>
                            <th><?= htmlspecialchars($l['inv_th_product']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_log_th_qty']) ?></th>
                            <th><?= htmlspecialchars($l['inv_lbl_reason']) ?></th>
                            <th><?= htmlspecialchars($l['inv_log_th_user']) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-file-excel fa-2x d-block mb-2"></i><?= htmlspecialchars($l['inv_log_no_records']) ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= date('Y-m-d', strtotime($log['adjustment_date'])) ?></div>
                                        <small class="text-muted"><?= date('H:i:s', strtotime($log['adjustment_date'])) ?></small>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($log['product_code']) ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($log['product_name']) ?></td>
                                    <td class="text-center">
                                        <?php if ($log['type'] === 'Add'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 fw-bold"><i class="fas fa-plus me-1"></i><?= $log['quantity'] ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-2 fw-bold"><i class="fas fa-minus me-1"></i><?= $log['quantity'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary rounded px-2 py-1 mb-1 d-inline-block">
                                            <?= htmlspecialchars($log['type'] === 'Add' ? ($l['inv_opt_add'] ?? 'Stock In') : ($l['inv_opt_sub'] ?? 'Stock Out')) ?>
                                        </span>
                                        <div class="text-secondary small"><?= htmlspecialchars($log['reason']) ?></div>
                                    </td>
                                    <td><div class="fw-semibold text-dark"><?= htmlspecialchars($log['user_name']) ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($log_total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $log_page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=logs&log_page=<?= $log_page - 1 ?>&log_search=<?= urlencode($log_search) ?>"><?= htmlspecialchars($l['prod_prev']) ?></a>
                        </li>
                        <?php for ($i = 1; $i <= $log_total_pages; $i++): ?>
                            <li class="page-item <?= $log_page == $i ? 'active' : '' ?>">
                                <a class="page-link" href="inventory.php?tab=logs&log_page=<?= $i ?>&log_search=<?= urlencode($log_search) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $log_page >= $log_total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=logs&log_page=<?= $log_page + 1 ?>&log_search=<?= urlencode($log_search) ?>"><?= htmlspecialchars($l['prod_next']) ?></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- -------------------------------------------------------------------------
     C. 物理库房/货架划拨台账标签页 (Tab: Stock Transfers)
     ------------------------------------------------------------------------- -->
<?php if ($tab === 'transfers'): ?>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-right-left text-primary me-2"></i><?= htmlspecialchars($l['inv_transfers_title'] ?? 'Internal Stock Transfers') ?></h5>
                <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addTransferModal">
                    <i class="fas fa-plus-circle me-1"></i><?= htmlspecialchars($l['inv_btn_new_transfer'] ?? 'New Stock Transfer') ?>
                </button>
            </div>

            <form method="GET" action="inventory.php" class="row g-3 mb-4 align-items-center">
                <input type="hidden" name="tab" value="transfers">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0 rounded-start-3 text-muted">
                            <i class="fas fa-search"></i>
                        </span>
                        <input type="text" name="tr_search" class="form-control border-start-0 rounded-end-3" placeholder="<?= htmlspecialchars($l['inv_transfer_search_placeholder'] ?? 'Search by SKU, product, or operator...') ?>" value="<?= htmlspecialchars($tr_search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 rounded-3">
                        <i class="fas fa-filter me-2"></i><?= htmlspecialchars($l['inv_filter_btn']) ?>
                    </button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr class="table-light">
                            <th><?= htmlspecialchars($l['inv_tr_th_date'] ?? 'Transfer Date') ?></th>
                            <th><?= htmlspecialchars($l['inv_th_sku']) ?></th>
                            <th><?= htmlspecialchars($l['inv_th_product']) ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_tr_th_from'] ?? 'From Location') ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_tr_th_to'] ?? 'To Location') ?></th>
                            <th class="text-center"><?= htmlspecialchars($l['inv_tr_th_qty'] ?? 'Quantity') ?></th>
                            <th><?= htmlspecialchars($l['inv_tr_th_user'] ?? 'Transferred By') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-arrows-spin fa-2x d-block mb-2"></i><?= htmlspecialchars($l['inv_transfer_no_records'] ?? 'No transfer records found.') ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $tr): 
                                $display_from = $loc_translation_map[$tr['from_location']] ?? $tr['from_location'];
                                $display_to = $loc_translation_map[$tr['to_location']] ?? $tr['to_location'];
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold text-dark"><?= date('Y-m-d', strtotime($tr['transfer_date'])) ?></div>
                                        <small class="text-muted"><?= date('H:i:s', strtotime($tr['transfer_date'])) ?></small>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($tr['product_code']) ?></td>
                                    <td class="fw-semibold text-dark"><?= htmlspecialchars($tr['product_name']) ?></td>
                                    <td class="text-center"><span class="badge bg-light text-dark border px-3 py-1.5"><?= htmlspecialchars($display_from) ?></span></td>
                                    <td class="text-center"><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-1.5"><?= htmlspecialchars($display_to) ?></span></td>
                                    <td class="text-center fw-bold text-success"><?= number_format($tr['quantity']) ?></td>
                                    <td><div class="fw-semibold text-dark"><?= htmlspecialchars($tr['user_name']) ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($tr_total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $tr_page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=transfers&tr_page=<?= $tr_page - 1 ?>&tr_search=<?= urlencode($tr_search) ?>"><?= htmlspecialchars($l['prod_prev']) ?></a>
                        </li>
                        <?php for ($i = 1; $i <= $tr_total_pages; $i++): ?>
                            <li class="page-item <?= $tr_page == $i ? 'active' : '' ?>">
                                <a class="page-link" href="inventory.php?tab=transfers&tr_page=<?= $i ?>&tr_search=<?= urlencode($tr_search) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $tr_page >= $tr_total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="inventory.php?tab=transfers&tr_page=<?= $tr_page + 1 ?>&tr_search=<?= urlencode($tr_search) ?>"><?= htmlspecialchars($l['prod_next']) ?></a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- MODAL 1: 库存手动校准弹窗 -->
<div class="modal fade" id="adjustStockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-scale-unbalanced text-primary me-2"></i><?= htmlspecialchars($l['inv_adjust_title']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="inventory.php?tab=status" method="POST" onsubmit="return confirmAdjustment(event);">
                <input type="hidden" name="action" value="adjust_stock">
                <input type="hidden" name="product_id" id="modal_product_id">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label mb-1 text-muted"><?= htmlspecialchars($l['inv_lbl_product']) ?></label>
                        <div class="form-control bg-light fw-bold text-dark border-0 p-3 rounded-3" id="modal_product_name"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted"><?= htmlspecialchars($l['inv_lbl_type'] ?? 'Adjustment Type') ?></label>
                        <div class="d-flex gap-3 mt-1">
                            <input type="radio" class="btn-check" name="type" id="type_add" value="Add" checked autocomplete="off">
                            <label class="btn btn-outline-success w-100 rounded-3 py-2 fw-semibold" for="type_add"><i class="fas fa-plus-circle me-1"></i><?= htmlspecialchars($l['inv_opt_add'] ?? 'Stock In') ?></label>
                            <input type="radio" class="btn-check" name="type" id="type_sub" value="Subtract" autocomplete="off">
                            <label class="btn btn-outline-danger w-100 rounded-3 py-2 fw-semibold" for="type_sub"><i class="fas fa-minus-circle me-1"></i><?= htmlspecialchars($l['inv_opt_sub'] ?? 'Stock Out') ?></label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="modal_qty" class="form-label text-muted"><?= htmlspecialchars($l['inv_lbl_qty']) ?></label>
                        <input type="number" name="quantity" id="modal_qty" class="form-control p-3 rounded-3" min="1" required placeholder="0">
                    </div>
                    <div class="mb-2">
                        <label for="modal_reason" class="form-label text-muted"><?= htmlspecialchars($l['inv_lbl_reason']) ?></label>
                        <textarea name="reason" id="modal_reason" class="form-control rounded-3" rows="3" required placeholder="<?= htmlspecialchars($l['inv_reason_placeholder']) ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= htmlspecialchars($l['inv_btn_cancel']) ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= htmlspecialchars($l['inv_btn_submit']) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: 新增库房划拨弹窗 (Stock Transfer) -->
<div class="modal fade" id="addTransferModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-right-left text-primary me-2"></i><?= htmlspecialchars($l['inv_tr_modal_title'] ?? 'New Stock Transfer') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="inventory.php?tab=transfers" method="POST">
                <input type="hidden" name="action" value="transfer_stock">
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold"><?= htmlspecialchars($l['inv_tr_lbl_product'] ?? 'Select Product *') ?></label>
                        <select name="product_id" class="form-select rounded-3 p-2.5" required>
                            <option value="" disabled selected><?= htmlspecialchars($l['inv_tr_opt_select'] ?? '-- Select Active Product --') ?></option>
                            <?php foreach ($active_all_products as $ap): ?>
                                <option value="<?= $ap['id'] ?>">[<?= htmlspecialchars($ap['product_code']) ?>] <?= htmlspecialchars($ap['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold"><?= htmlspecialchars($l['inv_tr_lbl_from'] ?? 'From Location *') ?></label>
                            <select name="from_location" class="form-select rounded-3 p-2.5" required>
                                <option value="Warehouse" selected><?= htmlspecialchars($l['inv_loc_warehouse'] ?? 'Back Warehouse') ?></option>
                                <option value="Shelf"><?= htmlspecialchars($l['inv_loc_shelf'] ?? 'Front Shelf') ?></option>
                                <option value="Branch_Store"><?= htmlspecialchars($l['inv_loc_branch'] ?? 'Sub-Branch') ?></option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label text-muted fw-bold"><?= htmlspecialchars($l['inv_tr_lbl_to'] ?? 'To Location *') ?></label>
                            <select name="to_location" class="form-select rounded-3 p-2.5" required>
                                <option value="Shelf" selected><?= htmlspecialchars($l['inv_loc_shelf'] ?? 'Front Shelf') ?></option>
                                <option value="Warehouse"><?= htmlspecialchars($l['inv_loc_warehouse'] ?? 'Back Warehouse') ?></option>
                                <option value="Branch_Store"><?= htmlspecialchars($l['inv_loc_branch'] ?? 'Sub-Branch') ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label text-muted fw-bold"><?= htmlspecialchars($l['inv_tr_lbl_qty'] ?? 'Transfer Quantity *') ?></label>
                        <input type="number" name="quantity" class="form-control rounded-3 p-2.5" min="1" required placeholder="0">
                        <small class="text-muted mt-1 d-block"><i class="fas fa-circle-info me-1"></i><?= htmlspecialchars($l['inv_tr_note'] ?? 'Note: Transferring *to* "Front Shelf" will automatically increase POS sale inventory; transferring *from* "Front Shelf" will deduct it.') ?></small>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?= htmlspecialchars($l['prod_btn_cancel'] ?? 'Cancel') ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?= htmlspecialchars($l['inv_btn_execute_transfer'] ?? 'Execute Transfer') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    var adjustModal = document.getElementById('adjustStockModal');
    if (adjustModal) {
        adjustModal.addEventListener('show.bs.modal', function(event) {
            var button = event.relatedTarget;
            var productId = button.getAttribute('data-id');
            var productName = button.getAttribute('data-name');
            
            var modalIdInput = adjustModal.querySelector('#modal_product_id');
            var modalNameDiv = adjustModal.querySelector('#modal_product_name');
            var modalQtyInput = adjustModal.querySelector('#modal_qty');
            var modalReasonTextarea = adjustModal.querySelector('#modal_reason');
            
            modalIdInput.value = productId;
            modalNameDiv.textContent = productName;
            
            modalQtyInput.value = '';
            modalReasonTextarea.value = '';
        });
    }
});

function confirmAdjustment(event) {
    var confirmMessage = "<?= addslashes($l['inv_confirm_js']) ?>";
    if (!confirm(confirmMessage)) {
        event.preventDefault();
        return false;
    }
    return true;
}
</script>

<?php
require_once '../includes/footer.php';
?>