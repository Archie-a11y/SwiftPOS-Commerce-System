<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once '../config/db.php';
require_once '../includes/languages.php';

$l = $languages[$lang_code];
$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$error_msg = '';
$success_msg = '';

// 确定当前的选项卡 (Tab)
$tab = $_GET['tab'] ?? 'suppliers';
if (!in_array($tab, ['suppliers', 'purchases'])) {
    $tab = 'suppliers';
}

// -------------------------------------------------------------------------
// 1. AJAX 接口 - 获取供应商 JSON
// -------------------------------------------------------------------------
if (isset($_GET['get_supplier_json'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['get_supplier_json']);
    
    $stmt = $conn->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $supplierData = $result->fetch_assoc();
    
    echo json_encode($supplierData ? $supplierData : []);
    $stmt->close();
    exit();
}

// -------------------------------------------------------------------------
// 2. 处理 POST 请求 (合并供应商与采购进货的增删改逻辑)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- 供应商操作 ---
    if ($action === 'add_supplier') {
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($company_name)) {
            $_SESSION['error_msg'] = $l['sup_err_empty'];
        } else {
            $stmt = $conn->prepare("INSERT INTO suppliers (company_name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $company_name, $contact_person, $phone, $email, $address);
            if ($stmt->execute()) {
                $_SESSION['success_msg'] = $l['sup_success_add'];
            } else {
                $_SESSION['error_msg'] = $l['err_generic'];
            }
            $stmt->close();
        }
        header("Location: suppliers.php?tab=suppliers");
        exit();
    }

    elseif ($action === 'edit_supplier') {
        $id = intval($_POST['id'] ?? 0);
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if (empty($company_name) || $id === 0) {
            $_SESSION['error_msg'] = $l['sup_err_empty'];
        } else {
            $stmt = $conn->prepare("UPDATE suppliers SET company_name = ?, contact_person = ?, phone = ?, email = ?, address = ? WHERE id = ?");
            $stmt->bind_param("sssssi", $company_name, $contact_person, $phone, $email, $address, $id);
            if ($stmt->execute()) {
                $_SESSION['success_msg'] = $l['sup_success_edit'];
            } else {
                $_SESSION['error_msg'] = $l['err_generic'];
            }
            $stmt->close();
        }
        header("Location: suppliers.php?tab=suppliers");
        exit();
    }

    elseif ($action === 'delete_supplier') {
        $id = intval($_POST['id'] ?? 0);

        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM purchases WHERE supplier_id = ?");
        $stmt_check->bind_param("i", $id);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        $row_check = $res_check->fetch_assoc();
        $hasReference = ($row_check['count'] > 0);
        $stmt_check->close();

        if ($hasReference) {
            $_SESSION['error_msg'] = $l['sup_err_delete_fk'];
        } else {
            $stmt = $conn->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $_SESSION['success_msg'] = $l['sup_success_delete'];
            } else {
                $_SESSION['error_msg'] = $l['err_generic'];
            }
            $stmt->close();
        }
        header("Location: suppliers.php?tab=suppliers");
        exit();
    }

    // --- 采购进货操作 ---
    elseif ($action === 'add_purchase') {
        $purchase_number = trim($_POST['purchase_number'] ?? '');
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        $payment_status = $_POST['payment_status'] ?? 'Paid';
        $payment_due_date = !empty($_POST['payment_due_date']) ? $_POST['payment_due_date'] : null;
        $product_ids = $_POST['products'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $costs = $_POST['costs'] ?? [];

        if (empty($purchase_number) || $supplier_id <= 0 || empty($product_ids)) {
            $_SESSION['error_msg'] = $l['prod_err_invalid_fields'];
        } else {
            $conn->begin_transaction();
            try {
                $stmt_chk = $conn->prepare("SELECT id FROM purchases WHERE purchase_number = ?");
                $stmt_chk->bind_param("s", $purchase_number);
                $stmt_chk->execute();
                if ($stmt_chk->get_result()->num_rows > 0) {
                    throw new Exception(sprintf($l['prod_err_duplicate_code'], $purchase_number));
                }
                $stmt_chk->close();

                $total_amount = 0.00;
                $stmt_pur = $conn->prepare("INSERT INTO purchases (purchase_number, purchase_date, supplier_id, total_amount, remarks, payment_status, payment_due_date, created_by) VALUES (?, ?, ?, 0.00, ?, ?, ?, ?)");
                $stmt_pur->bind_param("ssisssi", $purchase_number, $purchase_date, $supplier_id, $remarks, $payment_status, $payment_due_date, $user_id);
                $stmt_pur->execute();
                $purchase_id = $stmt_pur->insert_id;
                $stmt_pur->close();

                for ($i = 0; $i < count($product_ids); $i++) {
                    $prod_id = intval($product_ids[$i]);
                    $qty = intval($quantities[$i]);
                    $cost = floatval($costs[$i]);
                    $subtotal = $qty * $cost;
                    $total_amount += $subtotal;

                    if ($prod_id <= 0 || $qty <= 0 || $cost < 0) {
                        throw new Exception($l['prod_err_invalid_fields']);
                    }

                    $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, subtotal) VALUES (?, ?, ?, ?, ?)");
                    $stmt_item->bind_param("iiidd", $purchase_id, $prod_id, $qty, $cost, $subtotal);
                    $stmt_item->execute();
                    $stmt_item->close();

                    $stmt_stock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    $stmt_stock->bind_param("ii", $qty, $prod_id);
                    $stmt_stock->execute();
                    $stmt_stock->close();
                }

                $stmt_up_total = $conn->prepare("UPDATE purchases SET total_amount = ? WHERE id = ?");
                $stmt_up_total->bind_param("di", $total_amount, $purchase_id);
                $stmt_up_total->execute();
                $stmt_up_total->close();

                $conn->commit();
                $_SESSION['success_msg'] = $l['pur_success_add'];
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_msg'] = $e->getMessage();
            }
        }
        header("Location: suppliers.php?tab=purchases");
        exit();
    }

    elseif ($action === 'edit_purchase') {
        $purchase_id = intval($_POST['purchase_id'] ?? 0);
        $purchase_date = $_POST['purchase_date'] ?? date('Y-m-d');
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $remarks = trim($_POST['remarks'] ?? '');
        $payment_status = $_POST['payment_status'] ?? 'Paid';
        $payment_due_date = !empty($_POST['payment_due_date']) ? $_POST['payment_due_date'] : null;
        $product_ids = $_POST['products'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $costs = $_POST['costs'] ?? [];

        if ($purchase_id <= 0 || $supplier_id <= 0 || empty($product_ids)) {
            $_SESSION['error_msg'] = $l['prod_err_invalid_fields'];
        } else {
            $conn->begin_transaction();
            try {
                $old_items = [];
                $stmt_old = $conn->prepare("SELECT product_id, quantity FROM purchase_items WHERE purchase_id = ?");
                $stmt_old->bind_param("i", $purchase_id);
                $stmt_old->execute();
                $res_old = $stmt_old->get_result();
                while ($row = $res_old->fetch_assoc()) {
                    $old_items[] = $row;
                }
                $stmt_old->close();

                foreach ($old_items as $old) {
                    $stmt_revert = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                    $stmt_revert->bind_param("ii", $old['quantity'], $old['product_id']);
                    $stmt_revert->execute();
                    $stmt_revert->close();
                }

                $stmt_del_items = $conn->prepare("DELETE FROM purchase_items WHERE purchase_id = ?");
                $stmt_del_items->bind_param("i", $purchase_id);
                $stmt_del_items->execute();
                $stmt_del_items->close();

                $total_amount = 0.00;
                for ($i = 0; $i < count($product_ids); $i++) {
                    $prod_id = intval($product_ids[$i]);
                    $qty = intval($quantities[$i]);
                    $cost = floatval($costs[$i]);
                    $subtotal = $qty * $cost;
                    $total_amount += $subtotal;

                    if ($prod_id <= 0 || $qty <= 0 || $cost < 0) {
                        throw new Exception($l['prod_err_invalid_fields']);
                    }

                    $stmt_item = $conn->prepare("INSERT INTO purchase_items (purchase_id, product_id, quantity, unit_cost, subtotal) VALUES (?, ?, ?, ?, ?)");
                    $stmt_item->bind_param("iiidd", $purchase_id, $prod_id, $qty, $cost, $subtotal);
                    $stmt_item->execute();
                    $stmt_item->close();

                    $stmt_stock = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?");
                    $stmt_stock->bind_param("ii", $qty, $prod_id);
                    $stmt_stock->execute();
                    $stmt_stock->close();
                }

                $stmt_pur = $conn->prepare("UPDATE purchases SET purchase_date = ?, supplier_id = ?, total_amount = ?, remarks = ?, payment_status = ?, payment_due_date = ? WHERE id = ?");
                $stmt_pur->bind_param("sidsssi", $purchase_date, $supplier_id, $total_amount, $remarks, $payment_status, $payment_due_date, $purchase_id);
                $stmt_pur->execute();
                $stmt_pur->close();

                $conn->commit();
                $_SESSION['success_msg'] = $l['pur_success_edit'];
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_msg'] = $e->getMessage();
            }
        }
        header("Location: suppliers.php?tab=purchases");
        exit();
    }

    elseif ($action === 'delete_purchase') {
        $purchase_id = intval($_POST['purchase_id'] ?? 0);

        if ($purchase_id > 0) {
            $conn->begin_transaction();
            try {
                $stmt_items = $conn->prepare("SELECT product_id, quantity FROM purchase_items WHERE purchase_id = ?");
                $stmt_items->bind_param("i", $purchase_id);
                $stmt_items->execute();
                $res = $stmt_items->get_result();
                while ($item = $res->fetch_assoc()) {
                    $stmt_revert = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                    $stmt_revert->bind_param("ii", $item['quantity'], $item['product_id']);
                    $stmt_revert->execute();
                    $stmt_revert->close();
                }
                $stmt_items->close();

                $stmt_del = $conn->prepare("DELETE FROM purchases WHERE id = ?");
                $stmt_del->bind_param("i", $purchase_id);
                $stmt_del->execute();
                $stmt_del->close();

                $conn->commit();
                $_SESSION['success_msg'] = $l['pur_success_delete'];
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_msg'] = $e->getMessage();
            }
        }
        header("Location: suppliers.php?tab=purchases");
        exit();
    }
}

// -------------------------------------------------------------------------
// 3. 读取选项卡对应的具体数据流与分页检索
// -------------------------------------------------------------------------

// A. 基础公共字典
$suppliers_all = [];
$res_sup_all = $conn->query("SELECT id, company_name FROM suppliers ORDER BY company_name ASC");
while ($row = $res_sup_all->fetch_assoc()) {
    $suppliers_all[] = $row;
}

$products_all = [];
$res_prod_all = $conn->query("SELECT id, product_code, name, cost_price, supplier_id FROM products WHERE status = 'Active' ORDER BY name ASC");
while ($row = $res_prod_all->fetch_assoc()) {
    $products_all[] = $row;
}

$settings = [];
$set_res = $conn->query("SELECT key_name, key_value FROM settings");
while ($s_row = $set_res->fetch_assoc()) {
    $settings[$s_row['key_name']] = $s_row['key_value'];
}

if ($tab === 'suppliers') {
    // 供应商搜索与分页
    $search = trim($_GET['search'] ?? '');
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $base_query = "SELECT s.*, 
                   IFNULL((SELECT SUM(total_amount) FROM purchases WHERE supplier_id = s.id AND payment_status = 'Unpaid'), 0.00) AS outstanding_balance 
                   FROM suppliers s";

    if (!empty($search)) {
        $count_sql = "SELECT COUNT(*) as count FROM suppliers WHERE company_name LIKE ? OR contact_person LIKE ? OR phone LIKE ?";
        $stmt_count = $conn->prepare($count_sql);
        $like_search = "%$search%";
        $stmt_count->bind_param("sss", $like_search, $like_search, $like_search);
        $stmt_count->execute();
        $total_records = $stmt_count->get_result()->fetch_assoc()['count'];
        $stmt_count->close();

        $data_sql = $base_query . " WHERE s.company_name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? ORDER BY s.id DESC LIMIT ? OFFSET ?";
        $stmt_data = $conn->prepare($data_sql);
        $stmt_data->bind_param("sssii", $like_search, $like_search, $like_search, $limit, $offset);
        $stmt_data->execute();
        $suppliers = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_data->close();
    } else {
        $total_records = $conn->query("SELECT COUNT(*) as count FROM suppliers")->fetch_assoc()['count'];

        $data_sql = $base_query . " ORDER BY s.id DESC LIMIT ? OFFSET ?";
        $stmt_data = $conn->prepare($data_sql);
        $stmt_data->bind_param("ii", $limit, $offset);
        $stmt_data->execute();
        $suppliers = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_data->close();
    }
    $total_pages = ceil($total_records / $limit);
} else {
    // 采购单搜索与分页
    $search_query = trim($_GET['search_query'] ?? '');
    $supplier_filter = intval($_GET['supplier_id'] ?? 0);
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $sql = "SELECT p.*, s.company_name, u.full_name as creator_name 
            FROM purchases p 
            LEFT JOIN suppliers s ON p.supplier_id = s.id 
            LEFT JOIN users u ON p.created_by = u.id 
            WHERE 1=1";

    $types = "";
    $params = [];

    if (!empty($search_query)) {
        $sql .= " AND (p.purchase_number LIKE ? OR s.company_name LIKE ?)";
        $search_param = "%" . $search_query . "%";
        $types .= "ss";
        $params[] = $search_param;
        $params[] = $search_param;
    }

    if ($supplier_filter > 0) {
        $sql .= " AND p.supplier_id = ?";
        $types .= "i";
        $params[] = $supplier_filter;
    }

    if (!empty($start_date)) {
        $sql .= " AND p.purchase_date >= ?";
        $types .= "s";
        $params[] = $start_date;
    }

    if (!empty($end_date)) {
        $sql .= " AND p.purchase_date <= ?";
        $types .= "s";
        $params[] = $end_date;
    }

    $sql .= " ORDER BY p.purchase_date DESC, p.id DESC";

    $limit = 10;
    $page = intval($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    $stmt_count = $conn->prepare($sql);
    if (!empty($types)) {
        $stmt_count->bind_param($types, ...$params);
    }
    $stmt_count->execute();
    $total_records = $stmt_count->get_result()->num_rows;
    $stmt_count->close();

    $total_pages = ceil($total_records / $limit);

    $sql .= " LIMIT ? OFFSET ?";
    $types .= "ii";
    $params[] = $limit;
    $params[] = $offset;

    $stmt_main = $conn->prepare($sql);
    if (!empty($types)) {
        $stmt_main->bind_param($types, ...$params);
    }
    $stmt_main->execute();
    $purchases_res = $stmt_main->get_result();
    $stmt_main->close();
}

include_once '../includes/header.php';
include_once '../includes/alerts.php';
?>

<!-- 页面页头 -->
<div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <h2 class="fw-bold mb-1 text-primary">
                <i class="fas fa-truck-moving me-2"></i><?= htmlspecialchars($l['nav_suppliers_purchases'] ?? 'Suppliers & Purchases') ?>
            </h2>
            <p class="text-muted mb-0 small"><?= htmlspecialchars($l['sup_subtitle']) ?></p>
        </div>
        <div>
            <?php if ($tab === 'suppliers'): ?>
                <button class="btn btn-primary rounded-pill px-4 py-2.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
                    <i class="fas fa-plus me-2"></i> <?php echo htmlspecialchars($l['sup_add_btn']); ?>
                </button>
            <?php else: ?>
                <button class="btn btn-primary rounded-pill px-4 py-2.5 shadow-sm" data-bs-toggle="modal" data-bs-target="#addPurchaseModal" onclick="generatePurchaseNumber()">
                    <i class="fas fa-plus me-2"></i> <?php echo htmlspecialchars($l['pur_add_btn']); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 提示框模块 -->
<?php render_shared_alerts($l); ?>

<!-- 选项卡控制中心 -->
<ul class="nav nav-pills mb-4 gap-2">
    <li class="nav-item">
        <a class="nav-link rounded-pill px-4 <?= $tab === 'suppliers' ? 'active' : 'bg-body text-secondary border shadow-sm' ?>" href="suppliers.php?tab=suppliers">
            <i class="fas fa-truck me-2"></i><?= htmlspecialchars($l['sup_title']) ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link rounded-pill px-4 <?= $tab === 'purchases' ? 'active' : 'bg-body text-secondary border shadow-sm' ?>" href="suppliers.php?tab=purchases">
            <i class="fas fa-file-invoice-dollar me-2"></i><?= htmlspecialchars($l['pur_title']) ?>
        </a>
    </li>
</ul>

<!-- =========================================================================
     A. 选项卡 1：供应商管理
     ========================================================================= -->
<?php if ($tab === 'suppliers'): ?>
    <!-- 检索工具栏 -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-3">
        <form action="suppliers.php" method="GET" class="row g-2 align-items-stretch">
            <input type="hidden" name="tab" value="suppliers">
            <div class="col-md-9 col-lg-10 d-flex">
                <div class="input-group rounded-3 overflow-hidden w-100 h-100">
                    <span class="input-group-text bg-body-secondary border-secondary-subtle border-end-0 text-muted rounded-start-3 px-3 d-flex align-items-center">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-secondary-subtle border-start-0 rounded-end-3 py-2.5"
                           placeholder="<?php echo htmlspecialchars($l['sup_search_placeholder']); ?>" 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-3 col-lg-2 d-flex">
                <button type="submit" class="btn btn-primary w-100 h-100 d-flex align-items-center justify-content-center rounded-3 py-2.5">
                    <i class="fas fa-filter me-2"></i> <?php echo htmlspecialchars($l['prod_filter_btn_filter'] ?? 'Filter'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- 数据展示表格 (修复深色模式 text-dark 隐没问题) -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr class="table-light">
                        <th class="ps-4"><?php echo htmlspecialchars($l['sup_tbl_company']); ?></th>
                        <th><?php echo htmlspecialchars($l['sup_tbl_contact']); ?></th>
                        <th><?php echo htmlspecialchars($l['sup_tbl_phone']); ?></th>
                        <th><?php echo htmlspecialchars($l['sup_tbl_outstanding'] ?? 'Outstanding Payment'); ?></th>
                        <th><?php echo htmlspecialchars($l['sup_tbl_email']); ?></th>
                        <th><?php echo htmlspecialchars($l['sup_tbl_address']); ?></th>
                        <th class="pe-4 text-end d-print-none"><?php echo htmlspecialchars($l['sup_tbl_actions']); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fs-2 mb-3 d-block"></i>
                                <?php echo htmlspecialchars($l['sup_no_records']); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($suppliers as $supplier): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-body-emphasis">
                                    <?php echo htmlspecialchars($supplier['company_name']); ?>
                                </td>
                                <td class="text-body"><?php echo htmlspecialchars($supplier['contact_person'] ?: '-'); ?></td>
                                <td class="text-body"><?php echo htmlspecialchars($supplier['phone'] ?: '-'); ?></td>
                                <td class="fw-bold <?php echo ($supplier['outstanding_balance'] > 0) ? 'text-danger' : 'text-success'; ?>">
                                    RM <?php echo number_format($supplier['outstanding_balance'], 2); ?>
                                </td>
                                <td>
                                    <?php if ($supplier['email']): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>" class="text-decoration-none text-secondary">
                                            <i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($supplier['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="text-truncate text-body" style="max-width: 220px;" title="<?php echo htmlspecialchars($supplier['address']); ?>">
                                    <?php echo htmlspecialchars($supplier['address'] ?: '-'); ?>
                                </td>
                                <td class="pe-4 text-end d-print-none">
                                    <div class="d-flex justify-content-end gap-1">
                                        <button class="btn btn-sm btn-outline-primary rounded-pill px-2.5 btn-edit-supplier" 
                                                data-id="<?php echo $supplier['id']; ?>" 
                                                data-bs-toggle="modal" data-bs-target="#editSupplierModal">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger rounded-pill px-2.5 btn-delete-supplier" 
                                                data-id="<?php echo $supplier['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($supplier['company_name']); ?>"
                                                data-bs-toggle="modal" data-bs-target="#deleteSupplierModal">
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

        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent border-0 py-3 px-4 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                <span class="text-muted small">
                    <?php echo htmlspecialchars($l['prod_showing'] ?? 'Showing'); ?> 
                    <strong><?php echo $offset + 1; ?></strong> 
                    <?php echo htmlspecialchars($l['prod_to'] ?? 'to'); ?> 
                    <strong><?php echo min($offset + $limit, $total_records); ?></strong> 
                    <?php echo htmlspecialchars($l['prod_of'] ?? 'of'); ?> 
                    <strong><?php echo $total_records; ?></strong> 
                    <?php echo htmlspecialchars($l['prod_records'] ?? 'records'); ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?tab=suppliers&search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                                <?php echo htmlspecialchars($l['prod_prev'] ?? 'Previous'); ?>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?tab=suppliers&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?tab=suppliers&search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                                <?php echo htmlspecialchars($l['prod_next'] ?? 'Next'); ?>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- =========================================================================
     B. 选项卡 2：采购进货
     ========================================================================= -->
<?php if ($tab === 'purchases'): ?>
    <!-- 过滤工具栏 -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 d-print-none">
        <div class="card-body p-4">
            <form method="GET" action="suppliers.php">
                <input type="hidden" name="tab" value="purchases">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold text-muted mb-1 small"><?php echo htmlspecialchars($l['prod_filter_search_placeholder']); ?></label>
                        <div class="input-group rounded-3 overflow-hidden bg-light border-0">
                            <span class="input-group-text bg-transparent border-0 text-muted py-2.5"><i class="fas fa-search"></i></span>
                            <input type="text" name="search_query" class="form-control bg-transparent border-0 py-2.5" 
                                   placeholder="<?php echo htmlspecialchars($l['prod_filter_search_placeholder']); ?>" value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label fw-semibold text-muted mb-1 small"><?php echo htmlspecialchars($l['pur_tbl_supplier']); ?></label>
                        <select name="supplier_id" class="form-select bg-light border-0 py-2.5 rounded-3">
                            <option value="0">-- <?php echo htmlspecialchars($l['prod_filter_all_suppliers']); ?> --</option>
                            <?php foreach ($suppliers_all as $sup): ?>
                                <option value="<?php echo $sup['id']; ?>" <?php echo $supplier_filter === $sup['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sup['company_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label class="form-label fw-semibold text-muted mb-1 small"><?php echo htmlspecialchars($l['rep_start_date']); ?></label>
                        <input type="date" name="start_date" class="form-control bg-light border-0 py-2.5 rounded-3" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-12 col-sm-6 col-md-2">
                        <label class="form-label fw-semibold text-muted mb-1 small"><?php echo htmlspecialchars($l['rep_end_date']); ?></label>
                        <input type="date" name="end_date" class="form-control bg-light border-0 py-2.5 rounded-3" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-12 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100 rounded-3 py-2.5 d-flex align-items-center justify-content-center gap-2 border-0">
                            <i class="fas fa-filter"></i><span class="text-nowrap"><?php echo htmlspecialchars($l['prod_filter_btn_filter']); ?></span>
                        </button>
                        <a href="suppliers.php?tab=purchases" class="btn btn-outline-secondary rounded-3 d-flex align-items-center justify-content-center py-2.5 px-3 border-0" style="background-color: var(--bs-secondary-bg);" title="Reset">
                            <i class="fas fa-rotate-left"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- 采购记录表单表格 (已修复深色模式 text-dark 隐没问题) -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="purchasesTable">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4 py-3"><?php echo htmlspecialchars($l['pur_tbl_number']); ?></th>
                            <th class="py-3"><?php echo htmlspecialchars($l['pur_tbl_date']); ?></th>
                            <th class="py-3"><?php echo htmlspecialchars($l['pur_tbl_supplier']); ?></th>
                            <th class="py-3 text-end"><?php echo htmlspecialchars($l['pur_tbl_total']); ?></th>
                            <th class="text-center py-3"><?php echo htmlspecialchars($l['pur_lbl_payment_status'] ?? 'Payment Status'); ?></th> 
                            <th class="text-center py-3"><?php echo htmlspecialchars($l['pur_lbl_due_date'] ?? 'Due Date'); ?></th> 
                            <th class="py-3"><?php echo htmlspecialchars($l['pur_tbl_remarks']); ?></th>
                            <th class="pe-4 py-3 text-end d-print-none"><?php echo htmlspecialchars($l['pur_tbl_actions']); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($purchases_res->num_rows === 0): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fas fa-receipt fs-1 mb-3 text-muted opacity-50"></i>
                                    <p class="mb-0"><?php echo htmlspecialchars($l['pur_no_records']); ?></p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php while ($row = $purchases_res->fetch_assoc()): 
                                $purchase_id = $row['id'];
                                $items = [];
                                $stmt_item_fetch = $conn->prepare("SELECT pi.*, p.name as product_name, p.product_code 
                                                                   FROM purchase_items pi 
                                                                   JOIN products p ON pi.product_id = p.id 
                                                                   WHERE pi.purchase_id = ?");
                                $stmt_item_fetch->bind_param("i", $purchase_id);
                                $stmt_item_fetch->execute();
                                $res_items = $stmt_item_fetch->get_result();
                                while ($item_row = $res_items->fetch_assoc()) {
                                    $items[] = $item_row;
                                }
                                $stmt_item_fetch->close();
                            ?>
                                <tr>
                                    <td class="ps-4 fw-bold text-primary"><?php echo htmlspecialchars($row['purchase_number']); ?></td>
                                    <td class="text-body"><?php echo htmlspecialchars($row['purchase_date']); ?></td>
                                    <td class="fw-semibold text-body-emphasis"><?php echo htmlspecialchars($row['company_name'] ?? '--'); ?></td>
                                    <td class="text-end fw-bold text-success">RM <?php echo number_format($row['total_amount'], 2); ?></td>
                                    <td class="text-center">
                                        <?php if ($row['payment_status'] === 'Paid'): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-1.5">
                                                <i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($l['pur_status_paid'] ?? 'Paid'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-1.5 animate-pulse">
                                                <i class="fas fa-circle-exclamation me-1"></i><?php echo htmlspecialchars($l['pur_status_unpaid'] ?? 'Unpaid'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center text-muted small"><?php echo $row['payment_due_date'] ? htmlspecialchars($row['payment_due_date']) : '--'; ?></td>
                                    <td class="text-muted text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($row['remarks']); ?>"><?php echo htmlspecialchars($row['remarks'] ?: '--'); ?></td>
                                    <td class="pe-4 text-end d-print-none">
                                        <div class="d-inline-flex gap-1">
                                            <button class="btn btn-outline-info btn-sm rounded-3 px-2" title="<?php echo htmlspecialchars($l['prod_print']); ?>"
                                                    onclick="openPrintInvoiceModal(<?php echo htmlspecialchars(json_encode($row)); ?>, <?php echo htmlspecialchars(json_encode($items)); ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <button class="btn btn-outline-primary btn-sm rounded-3 px-2" title="<?php echo htmlspecialchars($l['prod_edit_product']); ?>"
                                                    onclick="openEditPurchaseModal(<?php echo htmlspecialchars(json_encode($row)); ?>, <?php echo htmlspecialchars(json_encode($items)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm rounded-3 px-2" title="<?php echo htmlspecialchars($l['prod_delete']); ?>"
                                                    onclick="openDeletePurchaseModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['purchase_number']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center p-4 border-top">
                    <span class="text-muted small">
                        <?php echo htmlspecialchars($l['prod_showing']); ?> <?php echo $offset + 1; ?> <?php echo htmlspecialchars($l['prod_to']); ?> <?php echo min($offset + $limit, $total_records); ?> <?php echo htmlspecialchars($l['prod_of']); ?> <?php echo $total_records; ?> <?php echo htmlspecialchars($l['prod_records']); ?>
                    </span>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link rounded-start-3" href="?tab=purchases&page=<?php echo $page - 1; ?>&search_query=<?php echo urlencode($search_query); ?>&supplier_id=<?php echo $supplier_filter; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                    <?php echo htmlspecialchars($l['prod_prev']); ?>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="?tab=purchases&page=<?php echo $i; ?>&search_query=<?php echo urlencode($search_query); ?>&supplier_id=<?php echo $supplier_filter; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?tab=purchases&page=<?php echo $page + 1; ?>&search_query=<?php echo urlencode($search_query); ?>&supplier_id=<?php echo $supplier_filter; ?>&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>">
                                    <?php echo htmlspecialchars($l['prod_next']); ?>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- =========================================================================
     C. 模态框集群区域 (Modals Suite)
     ========================================================================= -->

<!-- MODAL: 新增供应商 -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="add_supplier">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-truck me-2 text-primary"></i> <?php echo htmlspecialchars($l['sup_modal_add']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_company']); ?></label>
                        <input type="text" name="company_name" class="form-control rounded-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_contact']); ?></label>
                        <input type="text" name="contact_person" class="form-control rounded-3 py-2">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_phone']); ?></label>
                            <input type="text" name="phone" class="form-control rounded-3 py-2">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_email']); ?></label>
                            <input type="email" name="email" class="form-control rounded-3 py-2">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_address']); ?></label>
                        <textarea name="address" class="form-control rounded-3 py-2" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel'] ?? 'Cancel'); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 mt-0 w-auto"><?php echo htmlspecialchars($l['prod_btn_save'] ?? 'Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 修改供应商 -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="edit_supplier">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-body-emphasis"><i class="fas fa-edit me-2 text-primary"></i> <?php echo htmlspecialchars($l['sup_modal_edit']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_company']); ?></label>
                        <input type="text" name="company_name" id="edit_company_name" class="form-control rounded-3 py-2" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_contact']); ?></label>
                        <input type="text" name="contact_person" id="edit_contact_person" class="form-control rounded-3 py-2">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_phone']); ?></label>
                            <input type="text" name="phone" id="edit_phone" class="form-control rounded-3 py-2">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_email']); ?></label>
                            <input type="email" name="email" id="edit_email" class="form-control rounded-3 py-2">
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['sup_lbl_address']); ?></label>
                        <textarea name="address" id="edit_address" class="form-control rounded-3 py-2" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel'] ?? 'Cancel'); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 mt-0 w-auto"><?php echo htmlspecialchars($l['prod_btn_update'] ?? 'Update'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 删除供应商 -->
<div class="modal fade" id="deleteSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <form action="suppliers.php" method="POST">
                <input type="hidden" name="action" value="delete_supplier">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger"><i class="fas fa-triangle-exclamation me-2"></i> <?php echo htmlspecialchars($l['sup_modal_delete']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-4">
                    <p class="mb-2 fw-bold text-body-emphasis fs-5" id="delete_supplier_text"><?php echo htmlspecialchars($l['sup_confirm_delete']); ?></p>
                    <div class="p-3 bg-light rounded-3 text-center mb-3">
                        <strong id="delete_supplier_name" class="text-primary fs-5"></strong>
                    </div>
                    <p class="text-danger small mb-0"><i class="fas fa-circle-exclamation me-1"></i> <?php echo htmlspecialchars($l['sup_delete_warn']); ?></p>
                </div>
                <div class="modal-footer border-0 pt-0 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel'] ?? 'Cancel'); ?></button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 mt-0 w-auto"><?php echo htmlspecialchars($l['prod_btn_delete_confirm'] ?? 'Confirm Delete'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 登记新采购单 -->
<div class="modal fade" id="addPurchaseModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="suppliers.php?tab=purchases" onsubmit="return validatePurchaseForm('add_items_tbody')">
                <input type="hidden" name="action" value="add_purchase">
                <div class="modal-header px-4 py-3 border-bottom-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="fas fa-file-invoice me-2"></i><?php echo htmlspecialchars($l['pur_add_btn']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_number'] ?? 'Purchase Number'); ?> *</label>
                            <input type="text" name="purchase_number" id="add_purchase_number" class="form-control py-2" required readonly>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_date'] ?? 'Purchase Date'); ?> *</label>
                            <input type="date" name="purchase_date" class="form-control py-2" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_supplier'] ?? 'Supplier'); ?> *</label>
                            <select name="supplier_id" id="add_supplier_id" class="form-select py-2" required>
                                <option value="" disabled selected><?php echo htmlspecialchars($l['prod_select_supplier_placeholder']); ?></option>
                                <?php foreach ($suppliers_all as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_payment_status'] ?? 'Payment Status'); ?> *</label>
                            <select name="payment_status" class="form-select py-2" required onchange="toggleDueDate(this, 'add_due_date_col')">
                                <option value="Paid" selected><?php echo htmlspecialchars($l['pur_status_paid'] ?? 'Paid'); ?></option>
                                <option value="Unpaid"><?php echo htmlspecialchars($l['pur_status_unpaid'] ?? 'Unpaid'); ?></option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 d-none" id="add_due_date_col">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_due_date'] ?? 'Payment Due Date'); ?> *</label>
                            <input type="date" name="payment_due_date" class="form-control py-2" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_tbl_remarks']); ?></label>
                            <textarea name="remarks" class="form-control py-2" rows="2" placeholder="<?php echo htmlspecialchars($l['pur_lbl_remarks']); ?>"></textarea>
                        </div>
                    </div>

                    <div class="card border border-light-subtle rounded-3 overflow-hidden mb-3">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
                            <span class="fw-bold text-muted"><?php echo htmlspecialchars($l['nav_products']); ?></span>
                            <button type="button" id="add_item_btn_add" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1" onclick="addPurchaseRow('add_items_tbody')" disabled>
                                <i class="fas fa-plus"></i><span><?php echo htmlspecialchars($l['pur_btn_add_item']); ?></span>
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3 py-2" style="width: 45%;"><?php echo htmlspecialchars($l['pur_item_product']); ?></th>
                                            <th class="py-2" style="width: 20%;"><?php echo htmlspecialchars($l['pur_item_qty']); ?></th>
                                            <th class="py-2" style="width: 20%;"><?php echo htmlspecialchars($l['pur_item_cost']); ?></th>
                                            <th class="py-2 text-end" style="width: 15%;"><?php echo htmlspecialchars($l['pur_item_total']); ?></th>
                                            <th class="pe-3 py-2 text-center" style="width: 50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="add_items_tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end p-2 mb-2">
                        <h4 class="fw-bold mb-0">
                            <?php echo htmlspecialchars($l['pos_grand_total'] ?? 'Grand Total'); ?>: 
                            <span class="text-success" id="add_grand_total">RM 0.00</span>
                        </h4>
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 border-top-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['pur_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?php echo htmlspecialchars($l['pur_btn_save']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 修正/更改采购单 -->
<div class="modal fade" id="editPurchaseModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="suppliers.php?tab=purchases" onsubmit="return validatePurchaseForm('edit_items_tbody')">
                <input type="hidden" name="action" value="edit_purchase">
                <input type="hidden" name="purchase_id" id="edit_purchase_id">
                <div class="modal-header px-4 py-3 border-bottom-0">
                    <h5 class="modal-title fw-bold text-primary"><i class="fas fa-edit me-2"></i><?php echo htmlspecialchars($l['prod_edit_product']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_number'] ?? 'Purchase Number'); ?> *</label>
                            <input type="text" id="edit_purchase_number" class="form-control py-2" readonly>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_date'] ?? 'Purchase Date'); ?> *</label>
                            <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control py-2" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_supplier'] ?? 'Supplier'); ?> *</label>
                            <select name="supplier_id" id="edit_supplier_id" class="form-select py-2" required>
                                <?php foreach ($suppliers_all as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_payment_status'] ?? 'Payment Status'); ?> *</label>
                            <select name="payment_status" id="edit_payment_status" class="form-select py-2" required onchange="toggleDueDate(this, 'edit_due_date_col')">
                                <option value="Paid"><?php echo htmlspecialchars($l['pur_status_paid'] ?? 'Paid'); ?></option>
                                <option value="Unpaid"><?php echo htmlspecialchars($l['pur_status_unpaid'] ?? 'Unpaid'); ?></option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4 d-none" id="edit_due_date_col">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_lbl_due_date'] ?? 'Payment Due Date'); ?> *</label>
                            <input type="date" name="payment_due_date" id="edit_payment_due_date" class="form-control py-2">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['pur_tbl_remarks']); ?></label>
                            <textarea name="remarks" id="edit_remarks" class="form-control py-2" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="card border border-light-subtle rounded-3 overflow-hidden mb-3">
                        <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
                            <span class="fw-bold text-muted"><?php echo htmlspecialchars($l['nav_products']); ?></span>
                            <button type="button" id="edit_item_btn_edit" class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1" onclick="addPurchaseRow('edit_items_tbody')">
                                <i class="fas fa-plus"></i><span><?php echo htmlspecialchars($l['pur_btn_add_item']); ?></span>
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3 py-2" style="width: 45%;"><?php echo htmlspecialchars($l['pur_item_product']); ?></th>
                                            <th class="py-2" style="width: 20%;"><?php echo htmlspecialchars($l['pur_item_qty']); ?></th>
                                            <th class="py-2" style="width: 20%;"><?php echo htmlspecialchars($l['pur_item_cost']); ?></th>
                                            <th class="py-2 text-end" style="width: 15%;"><?php echo htmlspecialchars($l['pur_item_total']); ?></th>
                                            <th class="pe-3 py-2 text-center" style="width: 50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="edit_items_tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end p-2 mb-2">
                        <h4 class="fw-bold mb-0">
                            <?php echo htmlspecialchars($l['pos_grand_total'] ?? 'Grand Total'); ?>: 
                            <span class="text-success" id="edit_grand_total">RM 0.00</span>
                        </h4>
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 border-top-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['pur_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?php echo htmlspecialchars($l['prod_btn_update']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 采购单收据打印 (完全英文硬编码) -->
<div class="modal fade" id="printInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header px-4 py-3 border-bottom-0 d-print-none">
                <h5 class="modal-title fw-bold text-primary"><i class="fas fa-file-invoice-dollar me-2"></i><?php echo htmlspecialchars($l['sup_official_invoice']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 pt-1">
                <div id="printArea" class="p-4 bg-white text-dark border shadow-sm rounded-3 font-monospace" style="color: #000 !important; background-color: #fff !important; font-family: 'Courier New', Courier, monospace;">
                </div>
            </div>
            <div class="modal-footer px-4 py-3 border-top-0 d-print-none d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_close']); ?></button>
                <button type="button" class="btn btn-primary rounded-pill px-4 py-2" onclick="printReceipt()"><i class="fas fa-print me-2"></i><?php echo htmlspecialchars($l['prod_print']); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: 物理删除采购单确认 -->
<div class="modal fade" id="deletePurchaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <form method="POST" action="suppliers.php?tab=purchases">
                <input type="hidden" name="action" value="delete_purchase">
                <input type="hidden" name="purchase_id" id="delete_purchase_id">
                <div class="modal-header px-4 py-3 border-bottom-0">
                    <h5 class="modal-title fw-bold text-danger"><i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($l['prod_modal_delete_title'] ?? 'Delete Purchase'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 py-2 text-center">
                    <div class="text-danger mb-3"><i class="fas fa-ban fs-1"></i></div>
                    <p class="mb-1 text-body-emphasis"><?php echo htmlspecialchars($l['prod_delete_warning'] ?? 'Are you sure you want to delete'); ?> <strong id="delete_purchase_num_text" class="text-primary"></strong>?</p>
                    <p class="small text-muted"><?php echo htmlspecialchars($l['pur_confirm_delete'] ?? 'This action cannot be undone.'); ?></p>
                </div>
                <div class="modal-footer px-4 py-3 border-top-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['pur_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4"><?php echo htmlspecialchars($l['prod_btn_delete_confirm']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const productsDictionary = <?php echo json_encode($products_all); ?>;

let currentAddSupplier = "";
let currentEditSupplier = "";

document.addEventListener("DOMContentLoaded", function() {
    // 自动绑定供应商事件
    const addSupSelect = document.getElementById('add_supplier_id');
    if (addSupSelect) {
        addSupSelect.addEventListener('change', function() {
            handleSupplierChange(this, 'add_items_tbody', 'currentAddSupplier');
        });
    }

    const editSupSelect = document.getElementById('edit_supplier_id');
    if (editSupSelect) {
        editSupSelect.addEventListener('change', function() {
            handleSupplierChange(this, 'edit_items_tbody', 'currentEditSupplier');
        });
    }

    // 绑定供应商编辑和删除按钮
    const editButtons = document.querySelectorAll('.btn-edit-supplier');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const supplierId = this.getAttribute('data-id');
            fetch('suppliers.php?get_supplier_json=' + supplierId)
                .then(response => response.json())
                .then(data => {
                    if (data && data.id) {
                        document.getElementById('edit_id').value = data.id;
                        document.getElementById('edit_company_name').value = data.company_name;
                        document.getElementById('edit_contact_person').value = data.contact_person || '';
                        document.getElementById('edit_phone').value = data.phone || '';
                        document.getElementById('edit_email').value = data.email || '';
                        document.getElementById('edit_address').value = data.address || '';
                    }
                })
                .catch(err => console.error('Error fetching supplier data:', err));
        });
    });

    const deleteButtons = document.querySelectorAll('.btn-delete-supplier');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function() {
            const supplierId = this.getAttribute('data-id');
            const supplierName = this.getAttribute('data-name');
            document.getElementById('delete_id').value = supplierId;
            document.getElementById('delete_supplier_name').textContent = supplierName;
        });
    });

    // 清洗新增采购单残留数据
    const addPurchaseModalEl = document.getElementById('addPurchaseModal');
    if (addPurchaseModalEl) {
        addPurchaseModalEl.addEventListener('shown.bs.modal', function() {
            const supSelect = document.getElementById('add_supplier_id');
            if (supSelect) supSelect.value = '';
            currentAddSupplier = '';
            toggleAddItemButton('add_items_tbody', '');
            const tbody = document.getElementById('add_items_tbody');
            if (tbody) tbody.innerHTML = '';
            calculateGrandTotal('add_items_tbody');
        });
    }
});

function handleSupplierChange(selectEl, tbodyId, lastSupplierVarName) {
    const tbody = document.getElementById(tbodyId);
    const rowsCount = tbody ? tbody.querySelectorAll('tr').length : 0;
    const newSupplierId = selectEl.value;

    if (rowsCount > 0) {
        const confirmClear = confirm("Warning: Changing the supplier will reset and clear all currently added products in the table. Do you want to proceed?");
        if (confirmClear) {
            tbody.innerHTML = '';
            calculateGrandTotal(tbodyId);
            if (lastSupplierVarName === 'currentAddSupplier') currentAddSupplier = newSupplierId;
            if (lastSupplierVarName === 'currentEditSupplier') currentEditSupplier = newSupplierId;
        } else {
            if (lastSupplierVarName === 'currentAddSupplier') selectEl.value = currentAddSupplier;
            if (lastSupplierVarName === 'currentEditSupplier') selectEl.value = currentEditSupplier;
        }
    } else {
        if (lastSupplierVarName === 'currentAddSupplier') currentAddSupplier = newSupplierId;
        if (lastSupplierVarName === 'currentEditSupplier') currentEditSupplier = newSupplierId;
    }

    toggleAddItemButton(tbodyId, selectEl.value);
}

function toggleAddItemButton(tbodyId, supplierId) {
    const btnId = tbodyId === 'add_items_tbody' ? 'add_item_btn_add' : 'edit_item_btn_edit';
    const btn = document.getElementById(btnId);
    if (btn) {
        if (supplierId && supplierId !== "") {
            btn.removeAttribute('disabled');
        } else {
            btn.setAttribute('disabled', 'true');
        }
    }
}

function toggleDueDate(selectEl, elementId) {
    const target = document.getElementById(elementId);
    if (selectEl.value === 'Unpaid') {
        target.classList.remove('d-none');
    } else {
        target.classList.add('d-none');
    }
}

function generatePurchaseNumber() {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    const randomHex = Math.floor(1000 + Math.random() * 9000);
    const generatedNo = `PO-${year}${month}${day}-${randomHex}`;
    
    const inputField = document.getElementById('add_purchase_number');
    if (inputField) {
        inputField.value = generatedNo;
    }
}

function addPurchaseRow(tbodyId, defaultProdId = '', defaultQty = 1, defaultCost = '') {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    const supplierSelectId = tbodyId === 'add_items_tbody' ? 'add_supplier_id' : 'edit_supplier_id';
    const supplierSelect = document.getElementById(supplierSelectId);
    const selectedSupplierId = supplierSelect ? supplierSelect.value : '';

    if (!selectedSupplierId || selectedSupplierId === '') {
        alert("Please select a supplier first.");
        return;
    }

    const rowId = 'row_' + Date.now() + '_' + Math.floor(Math.random() * 1000);
    const tr = document.createElement('tr');
    tr.id = rowId;

    let docLangPlaceholder = "<?php echo isset($l['prod_filter_search_placeholder']) ? $l['prod_filter_search_placeholder'] : 'Search'; ?>";
    let prodOptions = `<option value="" disabled ${defaultProdId === '' ? 'selected' : ''}>-- ${docLangPlaceholder} --</option>`;
    
    productsDictionary.forEach(p => {
        if (p.supplier_id == selectedSupplierId) {
            prodOptions += `<option value="${p.id}" data-cost="${p.cost_price}" ${parseInt(defaultProdId) === parseInt(p.id) ? 'selected' : ''}>[${p.product_code}] ${p.name}</option>`;
        }
    });

    tr.innerHTML = `
        <td class="ps-3">
            <select name="products[]" class="form-select select-product-field py-2" required onchange="onProductSelectChange(this, '${rowId}', '${tbodyId}')">
                ${prodOptions}
            </select>
        </td>
        <td>
            <input type="number" name="quantities[]" class="form-control qty-field py-2" min="1" step="1" value="${defaultQty}" required oninput="recalculateRowSubtotal('${rowId}', '${tbodyId}')">
        </td>
        <td>
            <input type="number" name="costs[]" class="form-control cost-field py-2" min="0" step="0.01" value="${defaultCost}" placeholder="0.00" required oninput="recalculateRowSubtotal('${rowId}', '${tbodyId}')">
        </td>
        <td class="text-end fw-semibold text-body-emphasis pe-2">
            <span class="subtotal-span align-middle">RM 0.00</span>
        </td>
        <td class="text-center pe-3">
            <button type="button" class="btn btn-sm btn-outline-danger border-0 rounded-circle p-1 d-inline-flex align-items-center justify-content-center" style="width: 28px; height: 28px;" onclick="removePurchaseRow('${rowId}', '${tbodyId}')">
                <i class="fas fa-minus-circle fs-5"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    recalculateRowSubtotal(rowId, tbodyId);
}

function removePurchaseRow(rowId, tbodyId) {
    const row = document.getElementById(rowId);
    if (row) {
        row.remove();
        calculateGrandTotal(tbodyId);
    }
}

function onProductSelectChange(selectEl, rowId, tbodyId) {
    const selectedOption = selectEl.options[selectEl.selectedIndex];
    const costPrice = selectedOption.getAttribute('data-cost');
    
    const row = document.getElementById(rowId);
    if (row && costPrice) {
        const costInput = row.querySelector('.cost-field');
        if (costInput) {
            costInput.value = parseFloat(costPrice).toFixed(2);
        }
    }
    recalculateRowSubtotal(rowId, tbodyId);
}

function recalculateRowSubtotal(rowId, tbodyId) {
    const row = document.getElementById(rowId);
    if (!row) return;

    const qty = parseInt(row.querySelector('.qty-field').value) || 0;
    const cost = parseFloat(row.querySelector('.cost-field').value) || 0.00;
    const subtotal = qty * cost;

    row.querySelector('.subtotal-span').innerText = 'RM ' + subtotal.toFixed(2);
    calculateGrandTotal(tbodyId);
}

function calculateGrandTotal(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    let grandTotal = 0.00;
    tbody.querySelectorAll('tr').forEach(row => {
        const qty = parseInt(row.querySelector('.qty-field').value) || 0;
        const cost = parseFloat(row.querySelector('.cost-field').value) || 0.00;
        grandTotal += (qty * cost);
    });

    const totalSpanId = tbodyId === 'add_items_tbody' ? 'add_grand_total' : 'edit_grand_total';
    const totalSpan = document.getElementById(totalSpanId);
    if (totalSpan) {
        totalSpan.innerText = 'RM ' + grandTotal.toFixed(2);
    }
}

function validatePurchaseForm(tbodyId) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody || tbody.querySelectorAll('tr').length === 0) {
        alert("Empty checkout cart!");
        return false;
    }
    return true;
}

function openEditPurchaseModal(purchase, items) {
    document.getElementById('edit_purchase_id').value = purchase.id;
    document.getElementById('edit_purchase_number').value = purchase.purchase_number;
    document.getElementById('edit_purchase_date').value = purchase.purchase_date;
    document.getElementById('edit_supplier_id').value = purchase.supplier_id;
    document.getElementById('edit_payment_status').value = purchase.payment_status;
    document.getElementById('edit_payment_due_date').value = purchase.payment_due_date || '';
    document.getElementById('edit_remarks').value = purchase.remarks || '';

    currentEditSupplier = purchase.supplier_id;
    toggleAddItemButton('edit_items_tbody', purchase.supplier_id);

    const dueCol = document.getElementById('edit_due_date_col');
    if (purchase.payment_status === 'Unpaid') {
        dueCol.classList.remove('d-none');
    } else {
        dueCol.classList.add('d-none');
    }

    const tbody = document.getElementById('edit_items_tbody');
    tbody.innerHTML = '';

    items.forEach(item => {
        addPurchaseRow('edit_items_tbody', item.product_id, item.quantity, item.unit_cost);
    });

    calculateGrandTotal('edit_items_tbody');

    const editModal = new bootstrap.Modal(document.getElementById('editPurchaseModal'));
    editModal.show();
}

function openPrintInvoiceModal(purchase, items) {
    const container = document.getElementById('printArea');
    
    let itemsHtml = '';
    items.forEach(item => {
        itemsHtml += `
            <tr style="border-bottom: 1px dashed #333 !important;">
                <td style="padding: 4px 0; text-align: left; font-size: 11px; vertical-align: top; color: #000 !important;">
                    ${item.product_name}
                </td>
                <td style="padding: 4px 0; text-align: center; font-size: 11px; vertical-align: top; color: #000 !important;">
                    ${item.quantity}
                </td>
                <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; color: #000 !important;">
                    RM ${parseFloat(item.unit_cost).toFixed(2)}
                </td>
                <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; font-weight: bold; color: #000 !important;">
                    RM ${parseFloat(item.subtotal).toFixed(2)}
                </td>
            </tr>
        `;
    });

    const markup = `
        <div style="text-align: center; margin-bottom: 10px; color: #000 !important;">
            <h4 style="font-weight: bold; font-size: 14px; margin: 0 0 4px 0; color: #000 !important;">SwiftPOS Mart</h4>
            <div style="font-size: 10px; margin-bottom: 2px; color: #000 !important; line-height: 1.3;">${<?php echo json_encode($settings['store_address'] ?? 'Kuala Lumpur, Malaysia'); ?>}</div>
            <div style="font-size: 10px; color: #000 !important;">Tel: ${<?php echo json_encode($settings['store_phone'] ?? '03-2148 2000'); ?>} | SST ID: ${<?php echo json_encode($settings['sst_reg_no'] ?? 'W10-1808-32000045'); ?>}</div>
            <div style="border-top: 1px dashed #333; margin: 10px 0;"></div>
        </div>

        <table style="width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important;">
            <tr>
                <td style="width: 55%; text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                    <strong>Purchase No:</strong> ${purchase.purchase_number}
                </td>
                <td style="width: 45%; text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                    <strong>Status:</strong> ${purchase.payment_status}
                </td>
            </tr>
            <tr>
                <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                    <strong>Date:</strong> ${purchase.purchase_date}
                </td>
                <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                    <strong>Operator:</strong> ${purchase.creator_name || '--'}
                </td>
            </tr>
            <tr>
                <td colspan="2" style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                    <strong>Supplier:</strong> ${purchase.company_name || '--'}
                </td>
            </tr>
        </table>

        <div style="border-top: 1px dashed #333; margin: 10px 0;"></div>

        <table style="width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important;">
            <thead>
                <tr style="border-bottom: 1px dashed #333 !important; font-weight: bold;">
                    <th style="text-align: left; padding: 4px 0; font-size: 11px; color: #000 !important;">Item</th>
                    <th style="text-align: center; padding: 4px 0; font-size: 11px; width: 40px; color: #000 !important;">Qty</th>
                    <th style="text-align: right; padding: 4px 0; font-size: 11px; width: 70px; color: #000 !important;">Cost</th>
                    <th style="text-align: right; padding: 4px 0; font-size: 11px; width: 80px; color: #000 !important;">Total</th>
                </tr>
            </thead>
            <tbody>
                ${itemsHtml}
            </tbody>
        </table>

        <div style="border-top: 1px dashed #333; margin: 10px 0;"></div>

        <table style="width: 100% !important; border-collapse: collapse !important;">
            <tr style="border-top: 1px dashed #333 !important; border-bottom: 1px dashed #333 !important;">
                <td style="text-align: left; font-size: 12px; font-weight: bold; padding: 6px 0; color: #000 !important;">
                    Grand Total:
                </td>
                <td style="text-align: right; font-size: 14px; font-weight: bold; padding: 6px 0; color: #198754 !important;">
                    RM ${parseFloat(purchase.total_amount).toFixed(2)}
                </td>
            </tr>
        </table>

        <div style="border-top: 1px dashed #333; margin: 10px 0;"></div>

        <div style="font-size: 11px; color: #000 !important; text-align: left; line-height: 1.4;">
            <strong>Remarks:</strong> ${purchase.remarks || '--'}
        </div>

        <div style="text-align: center; margin-top: 15px; color: #000 !important;">
            <p style="letter-spacing: 0.5px; font-size: 11px; margin: 0 0 4px 0; color: #000 !important; font-weight: bold;">*** STOCK REGISTERED SUCCESSFULLY ***</p>
            <small style="font-size: 9px; color: #666 !important; display: block;">Generated automatically via Terminal ESC/POS Web Agent</small>
        </div>
    `;

    container.innerHTML = markup;

    const printModal = new bootstrap.Modal(document.getElementById('printInvoiceModal'));
    printModal.show();
}

function printReceipt() {
    const printContent = document.getElementById("printArea");
    if (!printContent) return;

    let iframe = document.getElementById("purchase-print-iframe-sandbox");
    if (iframe) iframe.remove();

    iframe = document.createElement("iframe");
    iframe.id = "purchase-print-iframe-sandbox";
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
                h4 {
                    font-size: 14px !important;
                    font-weight: bold !important;
                    margin: 0 0 4px 0 !important;
                    text-align: center !important;
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

    const originalParentTitle = window.parent.document.title || document.title;
    window.parent.document.title = ""; 
    doc.title = "";

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(() => {
            window.parent.document.title = originalParentTitle;
            if (iframe) iframe.remove();
        }, 1000);
    }, 300);
}

function openDeletePurchaseModal(id, number) {
    document.getElementById('delete_purchase_id').value = id;
    document.getElementById('delete_purchase_num_text').innerText = number;

    const delModal = new bootstrap.Modal(document.getElementById('deletePurchaseModal'));
    delModal.show();
}
</script>

<?php require_once '../includes/footer.php'; ?>