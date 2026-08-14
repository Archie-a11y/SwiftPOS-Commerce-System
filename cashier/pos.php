<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

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

$settings = [];
$set_res = $conn->query("SELECT key_name, key_value FROM settings");
while ($s_row = $set_res->fetch_assoc()) {
    $settings[$s_row['key_name']] = $s_row['key_value'];
}
$sst_rate_percent = floatval($settings['sst_rate'] ?? 6.00);

if (isset($_GET['action'])) {
    require_once __DIR__ . '/../includes/languages.php';
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => $languages[$lang_code]['pos_err_unauthorized'] ?? 'Unauthorized access.']);
        exit;
    }

    $l = $languages[$lang_code];

    if ($_GET['action'] === 'search') {
        $query = isset($_GET['q']) ? trim($_GET['q']) : '';
        if ($query === '') {
            $stmt = $conn->prepare("SELECT id, product_code, name, brand, selling_price, stock_quantity, min_stock_level, barcode, image FROM products WHERE status = 'Active' LIMIT 24");
        } else {
            $search = "%$query%";
            $stmt = $conn->prepare("SELECT id, product_code, name, brand, selling_price, stock_quantity, min_stock_level, barcode, image FROM products WHERE status = 'Active' AND (product_code LIKE ? OR name LIKE ? OR brand LIKE ? OR barcode = ?)");
            $stmt->bind_param("ssss", $search, $search, $search, $query);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        echo json_encode($products);
        $stmt->close();
        exit;
    }

    if ($_GET['action'] === 'get_customers') {
        // 会员只需提取姓名、手机号、积分、特权折算比率，不包含邮箱
        $res = $conn->query("SELECT id, membership_number, name, phone, loyalty_points, membership_discount FROM customers ORDER BY name ASC");
        $customers = [];
        while ($row = $res->fetch_assoc()) {
            $customers[] = $row;
        }
        echo json_encode($customers);
        exit;
    }

    if ($_GET['action'] === 'add_customer') {
        $input = json_decode(file_get_contents('php://input'), true);
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $discount = floatval($input['discount'] ?? 0.00);
        $member_no = 'MBR-' . date('Ymd') . '-' . mt_rand(1000, 9999);

        if (empty($name) || empty($phone)) {
            echo json_encode(['success' => false, 'message' => $l['pos_name_phone_req'] ?? 'Name and Phone are required.']);
            exit;
        }

        $chk = $conn->prepare("SELECT id FROM customers WHERE phone = ?");
        $chk->bind_param("s", $phone);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => $l['cust_err_exists'] ?? 'Phone number already registered.']);
            $chk->close();
            exit;
        }
        $chk->close();

        // 插入时不存储 email，将其默认设为 NULL
        $stmt = $conn->prepare("INSERT INTO customers (membership_number, name, phone, loyalty_points, membership_discount) VALUES (?, ?, ?, 10, ?)");
        $stmt->bind_param("sssd", $member_no, $name, $phone, $discount);
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'customer' => [
                    'id' => $stmt->insert_id,
                    'membership_number' => $member_no,
                    'name' => $name,
                    'phone' => $phone,
                    'loyalty_points' => 10,
                    'membership_discount' => $discount
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => $l['err_generic'] ?? 'Database error.']);
        }
        $stmt->close();
        exit;
    }

    if ($_GET['action'] === 'checkout') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input || empty($input['cart'])) {
            echo json_encode(['success' => false, 'message' => $l['pos_err_empty_cart']]);
            exit;
        }

        $cart = $input['cart'];
        $paid_amount = floatval($input['paid_amount']);
        $payment_method = $input['payment_method'];
        $customer_id = !empty($input['customer_id']) ? intval($input['customer_id']) : null;
        $discount_amount = floatval($input['discount_amount'] ?? 0.00);
        $tax_amount = floatval($input['tax_amount'] ?? 0.00);
        $voucher_code = !empty($input['voucher_code']) ? trim($input['voucher_code']) : null;

        $conn->begin_transaction();

        try {
            $subtotal_amount = 0.00;
            $validated_items = [];

            foreach ($cart as $item) {
                $product_id = intval($item['id']);
                $qty = intval($item['qty']);

                $stmt = $conn->prepare("SELECT id, name, selling_price, stock_quantity FROM products WHERE id = ? AND status = 'Active' FOR UPDATE");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $prod = $stmt->get_result()->fetch_assoc();

                if (!$prod) {
                    throw new Exception($l['pos_err_product_unavailable'] ?? "Product is unavailable or inactive.");
                }

                if ($prod['stock_quantity'] < $qty) {
                    throw new Exception(sprintf($l['pos_err_insufficient_stock'], $prod['name']) . " (" . ($l['prod_th_stock'] ?? 'Stock') . ": " . $prod['stock_quantity'] . ")");
                }

                $row_subtotal = $prod['selling_price'] * $qty;
                $subtotal_amount += $row_subtotal;

                $validated_items[] = [
                    'id' => $prod['id'],
                    'name' => $prod['name'],
                    'unit_price' => $prod['selling_price'],
                    'qty' => $qty,
                    'subtotal' => $row_subtotal
                ];
                $stmt->close();
            }

            $total_amount = max(0.00, $subtotal_amount - $discount_amount + $tax_amount);

            if ($paid_amount < $total_amount) {
                throw new Exception($l['pos_err_insufficient_payment'] ?? 'Paid amount is not enough.');
            }

            $balance_amount = $paid_amount - $total_amount;
            $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $created_by = $_SESSION['user_id'];

            $stmt = $conn->prepare("INSERT INTO sales (invoice_number, subtotal_amount, discount_amount, tax_amount, voucher_code, total_amount, paid_amount, balance_amount, payment_method, customer_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sdddsdddsii", $invoice_number, $subtotal_amount, $discount_amount, $tax_amount, $voucher_code, $total_amount, $paid_amount, $balance_amount, $payment_method, $customer_id, $created_by);
            if (!$stmt->execute()) {
                throw new Exception("Sales entry creation failed: " . $stmt->error);
            }
            $sale_id = $conn->insert_id;
            $stmt->close();

            foreach ($validated_items as $v_item) {
                $stmt_item = $conn->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
                $stmt_item->bind_param("iiidd", $sale_id, $v_item['id'], $v_item['qty'], $v_item['unit_price'], $v_item['subtotal']);
                if (!$stmt_item->execute()) {
                    throw new Exception($l['pos_err_sale_detail_failed'] ?? "Sale detail line record failed.");
                }
                $stmt_item->close();

                $stmt_upd = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                $stmt_upd->bind_param("ii", $v_item['qty'], $v_item['id']);
                $stmt_upd->execute();
                $stmt_upd->close();
            }

            if ($customer_id) {
                $points_add = floor($total_amount);
                if ($points_add > 0) {
                    $stmt_pt = $conn->prepare("UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?");
                    $stmt_pt->bind_param("ii", $points_add, $customer_id);
                    $stmt_pt->execute();
                    $stmt_pt->close();
                }
            }

            log_activity($conn, $created_by, 'Checkout Success', "Cashier processed Invoice {$invoice_number} (RM " . number_format($total_amount, 2) . ")");

            $conn->commit();

            $cust_name = 'Walk-in Customer';
            $cust_points = 0;
            if ($customer_id) {
                $stmt_c = $conn->prepare("SELECT name, loyalty_points FROM customers WHERE id = ?");
                $stmt_c->bind_param("i", $customer_id);
                $stmt_c->execute();
                $c_info = $stmt_c->get_result()->fetch_assoc();
                $cust_name = $c_info['name'] ?? 'Member';
                $cust_points = $c_info['loyalty_points'] ?? 0;
                $stmt_c->close();
            }

            echo json_encode([
                'success' => true,
                'invoice_number' => $invoice_number,
                'subtotal_amount' => $subtotal_amount,
                'discount_amount' => $discount_amount,
                'tax_amount' => $tax_amount,
                'voucher_code' => $voucher_code,
                'total_amount' => $total_amount,
                'paid_amount' => $paid_amount,
                'balance_amount' => $balance_amount,
                'payment_method' => $payment_method,
                'date' => date('Y-m-d H:i:s'),
                'cashier' => $_SESSION['user_name'],
                'customer_name' => $cust_name,
                'customer_points' => $cust_points,
                'items' => $validated_items
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

include_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3 align-items-stretch">
    <!-- 左侧商品点选区 -->
    <div class="col-12 col-lg-8 d-flex flex-column">
        <div class="card border-secondary-subtle bg-body shadow-sm rounded-4 p-3 flex-grow-1 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-primary d-flex align-items-center gap-2">
                    <i class="fas fa-boxes-stacked"></i>
                    <span><?php echo htmlspecialchars($l['nav_products']); ?></span>
                </h5>
            </div>
            
            <div class="mb-3">
                <div class="input-group">
                    <span class="input-group-text bg-body-secondary text-muted rounded-start-3 border-secondary-subtle py-2.5">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" id="pos-search" class="form-control border-start-0 rounded-end-3 border-secondary-subtle py-2.5" placeholder="<?php echo htmlspecialchars($l['pos_search_prod']); ?>" autocomplete="off" autofocus>
                </div>
            </div>

            <div class="overflow-y-auto flex-grow-1 pe-1" style="max-height: 520px;" id="pos-products-container">
                <div class="d-flex justify-content-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 右侧购物车和收银面板 -->
    <div class="col-12 col-lg-4 d-flex flex-column">
        <div class="card border-secondary-subtle bg-body shadow-sm rounded-4 h-100 flex-grow-1 d-flex flex-column">
            
            <!-- 会员选择区 (输入搜索二合一重构版) -->
            <div class="bg-body-secondary p-3 border-bottom border-secondary-subtle rounded-top-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0 fw-bold text-dark-emphasis small">
                        <i class="fas fa-user-tag text-primary me-1"></i><?php echo htmlspecialchars($l['pos_member_select']); ?>
                    </label>
                    <button class="btn btn-xs btn-primary rounded-pill px-2.5 py-1 text-xs d-flex align-items-center justify-content-center" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                        <i class="fas fa-plus me-1"></i><?php echo htmlspecialchars($l['pos_cust_add_btn']); ?>
                    </button>
                </div>
                
                <div class="row g-2">
                    <div class="col-12 position-relative">
                        <div class="input-group">
                            <span class="input-group-text bg-body-secondary text-muted rounded-start-3 border-secondary-subtle py-2">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" id="member-phone-search" class="form-control border-start-0 rounded-end-3 border-secondary-subtle py-2" placeholder="<?php echo htmlspecialchars($l['pos_member_phone_placeholder']); ?>" autocomplete="off" oninput="filterMembersByPhone()" onfocus="filterMembersByPhone()">
                        </div>
                        
                        <!-- 隐藏的原生下拉框，用于保障底层的财务算法逻辑 (散客已加多语言) -->
                        <select id="member-select" class="d-none" onchange="applyMemberDiscount()">
                            <option value="" data-discount="0.00" data-points="0" selected><?php echo htmlspecialchars($l['pos_member_walkin'] ?? 'Walk-in Customer'); ?></option>
                        </select>
                        
                        <!-- 动态弹出的智能匹配建议面板 -->
                        <div id="member-suggestions" class="dropdown-menu w-100 shadow-lg border-secondary-subtle" style="max-height: 250px; overflow-y: auto; display: none; position: absolute; z-index: 1050; margin-top: 2px;"></div>
                    </div>
                </div>

                <div class="mt-2 text-primary small d-none" id="member-loyalty-info">
                    <i class="fas fa-coins me-1"></i><?php echo htmlspecialchars($l['pos_current_points'] ?? 'Current Points:'); ?> <strong id="member-points-span" class="fw-bold">0</strong>
                </div>
            </div>

            <div class="card-header bg-transparent border-0 pt-3 px-3 pb-1 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-primary d-flex align-items-center gap-2">
                    <i class="fas fa-shopping-basket"></i>
                    <span><?php echo htmlspecialchars($l['pos_cart_title']); ?></span>
                </h5>
                <button class="btn btn-sm btn-outline-danger rounded-pill px-2.5 py-1 text-xs" onclick="clearCart()">
                    <i class="fas fa-trash-can me-1"></i><?php echo htmlspecialchars($l['pos_btn_clear']); ?>
                </button>
            </div>

            <!-- 购物车行 -->
            <div class="card-body flex-grow-1 overflow-y-auto px-3 py-2" style="max-height: 220px; min-height: 150px;" id="pos-cart-items"></div>

            <div class="card-footer bg-transparent border-top border-secondary-subtle p-3 mt-auto">
                <div class="row g-2 mb-2">
                    <!-- 优惠券区域 (已多语言化) -->
                    <div class="col-6">
                        <label class="form-label mb-1 text-muted small"><i class="fas fa-ticket me-1"></i><?php echo htmlspecialchars($l['pos_voucher_code'] ?? 'Voucher Code'); ?></label>
                        <select id="voucher-select" class="form-select border-secondary-subtle rounded-3 py-2" onchange="applyVoucherDropdown()">
                            <option value="" data-type="flat" data-value="0.00" selected>-- <?php echo htmlspecialchars($l['pos_no_voucher'] ?? 'No Voucher'); ?> --</option>
                            <option value="SAVE10" data-type="percentage" data-value="10.00"><?php echo htmlspecialchars($l['pos_voucher_save10'] ?? 'SAVE10 (10% Off)'); ?></option>
                            <option value="WELCOME5" data-type="flat" data-value="5.00"><?php echo htmlspecialchars($l['pos_voucher_welcome5'] ?? 'WELCOME5 (RM 5.00 Off)'); ?></option>
                            <option value="MERDEKA69" data-type="percentage" data-value="6.90"><?php echo htmlspecialchars($l['pos_voucher_merdeka69'] ?? 'MERDEKA69 (6.9% Off)'); ?></option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label mb-1 text-muted small"><i class="fas fa-percent me-1"></i><?php echo htmlspecialchars($l['pos_manual_discount']); ?></label>
                        <input type="text" id="manual-discount-input" class="form-control text-end border-secondary-subtle rounded-3 py-2" placeholder="0.00" oninput="applyManualDiscount()">
                    </div>
                </div>

                <div class="d-flex justify-content-between mb-1 small text-muted">
                    <span><?php echo htmlspecialchars($l['pos_subtotal']); ?></span>
                    <span id="cart-subtotal">RM 0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-1 small text-danger">
                    <span id="discount-label"><?php echo htmlspecialchars($l['pos_discount']); ?></span>
                    <span id="cart-discount">-RM 0.00</span>
                </div>
                <div class="d-flex justify-content-between mb-1 small text-muted">
                    <span><?php echo htmlspecialchars($l['sst_service_tax'] ?? 'SST Service Tax'); ?> (<?php echo number_format($sst_rate_percent, 2); ?>%)</span>
                    <span id="cart-tax">RM 0.00</span>
                </div>
                <hr class="my-2 border-secondary-subtle border-dashed">
                <div class="d-flex justify-content-between mb-3">
                    <span class="fw-bold text-primary fs-5"><?php echo htmlspecialchars($l['pos_grand_total']); ?></span>
                    <span class="fw-bold text-primary fs-5" id="cart-total">RM 0.00</span>
                </div>

                <div class="mb-3">
                    <label class="form-label mb-1 small fw-bold">
                        <i class="fas fa-credit-card text-muted me-2"></i><?php echo htmlspecialchars($l['pos_pay_method']); ?>
                    </label>
                    <select id="payment-method" class="form-select border-secondary-subtle py-2.5 rounded-3">
                        <option value="Cash"><?php echo htmlspecialchars($l['pay_cash']); ?></option>
                        <option value="Touch n Go"><?php echo htmlspecialchars($l['pay_touchngo'] ?? "Touch 'n Go"); ?></option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label mb-1 small fw-bold">
                        <i class="fas fa-coins text-muted me-2"></i><?php echo htmlspecialchars($l['pos_amount_paid']); ?>
                    </label>
                    <input type="text" id="amount-paid" class="form-control text-end fs-4 fw-extrabold border-secondary-subtle py-2.5 rounded-3" inputmode="numeric" placeholder="0.00">
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-body-secondary border rounded-3">
                    <span class="fw-bold text-muted small mb-0"><?php echo htmlspecialchars($l['pos_change']); ?></span>
                    <h4 class="fw-extrabold text-success mb-0" id="change-due">RM 0.00</h4>
                </div>

                <div class="d-grid">
                    <button class="btn btn-primary py-3 fw-bold rounded-3 border-0 shadow-sm" id="btn-checkout" onclick="performCheckout()">
                        <i class="fas fa-cash-register me-2"></i><?php echo htmlspecialchars($l['pos_btn_pay']); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: 交易成功热敏小票模拟展示弹窗 (完全纯英文锁定) -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 rounded-4 shadow">
            <div class="modal-header border-0 pb-0 px-4 pt-4">
                <h5 class="modal-title fw-bold text-success d-flex align-items-center gap-2" id="receiptModalLabel">
                    <i class="fas fa-check-circle"></i>
                    <span>Checkout Success</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetPOS()"></button>
            </div>
            <div class="modal-body p-4">
                <div id="receipt-paper" class="p-4 bg-white text-dark border shadow-sm rounded-3 font-monospace" style="color: #000 !important; background-color: #fff !important; font-family: 'Courier New', Courier, monospace; line-height: 1.4;">
                    
                    <div style="text-align: center; margin-bottom: 10px; color: #000 !important;">
                        <h4 class="fw-bold mb-1" id="receipt_store_name" style="font-size: 14px; margin: 0 0 4px 0; color: #000 !important;">SwiftPOS Mart</h4>
                        <div id="receipt_store_address" style="font-size: 10px; margin-bottom: 2px; color: #000 !important; line-height: 1.3;"></div>
                        <div id="receipt_store_contact" style="font-size: 10px; color: #000 !important;"></div>
                        <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>
                    </div>

                    <!-- 订单元信息物理对齐 -->
                    <table style="width: 100% !important; border-collapse: collapse !important; margin-bottom: 10px !important;">
                        <tr>
                            <td style="width: 55%; text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Invoice:</strong> <span id="receipt_invoice_num" style="font-weight: bold;"></span>
                            </td>
                            <td style="width: 45%; text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Method:</strong> <span id="receipt_payment_method"></span>
                            </td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Date:</strong> <span id="receipt_date"></span>
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Cashier:</strong> <span id="receipt_cashier"></span>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important; vertical-align: top;">
                                <strong>Customer:</strong> <span id="receipt_customer"></span>
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
                        <tbody id="receipt_items_body"></tbody>
                    </table>

                    <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>

                    <!-- 账务统计物理 Table 排版 -->
                    <table style="width: 100% !important; border-collapse: collapse !important;">
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Subtotal:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="receipt_subtotal"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Discount / Voucher:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #dc3545 !important;" id="receipt_discount"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                SST Service Tax (<span id="receipt_sst_rate"></span>%):
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="receipt_tax"></td>
                        </tr>
                        <tr style="border-top: 1px dashed #333 !important; border-bottom: 1px dashed #333 !important;">
                            <td style="text-align: left; font-size: 12px; font-weight: bold; padding: 6px 0; color: #000 !important;">
                                Grand Total:
                            </td>
                            <td style="text-align: right; font-size: 14px; font-weight: bold; padding: 6px 0; color: #198754 !important;" id="receipt_grand_total"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Tendered:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="receipt_paid"></td>
                        </tr>
                        <tr>
                            <td style="text-align: left; font-size: 11px; padding: 2px 0; color: #000 !important;">
                                Change:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 2px 0; color: #000 !important;" id="receipt_change"></td>
                        </tr>
                        <tr style="border-top: 1px dashed #eee !important;">
                            <td style="text-align: left; font-size: 11px; padding: 6px 0 2px 0; color: #0d6efd !important;">
                                Accumulated Loyalty Points:
                            </td>
                            <td style="text-align: right; font-size: 11px; padding: 6px 0 2px 0; color: #0d6efd !important; font-weight: bold;" id="receipt_points_calc"></td>
                        </tr>
                    </table>

                    <div style="border-top: 1px dashed #333; margin: 12px 0;"></div>

                    <div style="text-align: center; margin-top: 10px; color: #000 !important;">
                        <p class="small mb-0" style="letter-spacing: 0.5px; font-size: 11px; font-weight: bold;">*** THANK YOU FOR SHOPPING! ***</p>
                        <small class="text-muted" style="font-size: 9px;">Printed via SwiftPOS Central DB</small>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4 px-4 d-flex justify-content-between align-items-stretch gap-2">
                <button type="button" class="btn btn-outline-secondary rounded-pill flex-fill py-2" data-bs-dismiss="modal" onclick="resetPOS()">
                    <i class="fas fa-arrow-left me-2"></i><?php echo htmlspecialchars($l['prod_btn_close']); ?>
                </button>
                <button type="button" class="btn btn-primary rounded-pill flex-fill py-2" onclick="printReceipt()">
                    <i class="fas fa-print me-2"></i><?php echo htmlspecialchars($l['prod_print']); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: 快速办理常客会员 (已移除 Email 字段) -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-body-emphasis d-flex align-items-center gap-2">
                    <i class="fas fa-user-plus text-primary"></i>
                    <span><?php echo htmlspecialchars($l['pos_cust_modal_title']); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['pos_full_name_req'] ?? 'Full Name *'); ?></label>
                    <input type="text" id="cust-name-input" class="form-control py-2.5 rounded-3" placeholder="e.g. Ridzuan Bin Ahmad" required>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['pos_whatsapp_phone_req'] ?? 'WhatsApp/Phone *'); ?></label>
                    <input type="text" id="cust-phone-input" class="form-control py-2.5 rounded-3" placeholder="e.g. 0111223344" required>
                </div>
                <div class="mb-0">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['pos_member_discount_percent'] ?? 'Exclusive Member Discount %'); ?></label>
                    <input type="number" id="cust-discount-input" class="form-control py-2.5 rounded-3" step="0.5" value="2.00" min="0" max="100">
                </div>
            </div>
            <div class="modal-footer border-0 p-3 bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4 py-2.5" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel']); ?></button>
                <button type="button" class="btn btn-primary rounded-pill px-4 py-2.5" onclick="createNewCustomer()"><?php echo htmlspecialchars($l['prod_btn_save']); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const storeSettings = {
    name: "SwiftPOS Mart",
    address: "Lot 4.12, Plaza Low Yat, Jalan Bukit Bintang, 55100 Kuala Lumpur", 
    phone: <?php echo json_encode($settings['store_phone'] ?? '03-2148 2000'); ?>,
    sstRegNo: <?php echo json_encode($settings['sst_reg_no'] ?? 'W10-1808-32000045'); ?>,
    sstRate: <?php echo json_encode($sst_rate_percent); ?>,
    qrLink: <?php echo json_encode($settings['receipt_qr_link'] ?? 'https://www.hasil.gov.my/'); ?>
};

const posLang = {
    cartEmpty: <?php echo json_encode($l['pos_cart_empty']); ?>,
    insufficientPayment: <?php echo json_encode($l['pos_err_insufficient_payment']); ?>,
    insufficientStock: <?php echo json_encode($l['pos_err_insufficient_stock']); ?>,
    processing: <?php echo json_encode($l['pos_processing']); ?>,
    payBtnText: <?php echo json_encode($l['pos_btn_pay']); ?>,
    removeText: <?php echo json_encode($l['pos_remove']); ?>,
    voucherApplied: <?php echo json_encode($l['pos_voucher_success']); ?>,
    voucherInvalid: <?php echo json_encode($l['pos_voucher_invalid']); ?>,
    outOfStock: <?php echo json_encode($l['prod_out_of_stock'] ?? 'Out of Stock'); ?>,
    lowStock: <?php echo json_encode($l['prod_low_stock'] ?? 'Low Stock'); ?>,
    walkinCustomer: <?php echo json_encode($l['pos_member_walkin'] ?? 'Walk-in Customer'); ?>,
    noMemberFound: <?php echo json_encode($l['pos_no_member_found'] ?? 'No member found'); ?>,
    percentOff: <?php echo json_encode($l['pos_percent_off'] ?? '% Off'); ?>,
    namePhoneReq: <?php echo json_encode($l['pos_name_phone_req'] ?? 'Name and Phone number are required fields.'); ?>,
    checkoutFailed: <?php echo json_encode($l['pos_checkout_failed'] ?? 'Checkout failed. Connection interrupt.'); ?>,
    outOfStockWarning: <?php echo json_encode($l['pos_out_of_stock_warning'] ?? 'Out of stock warning: '); ?>,
    brandGeneric: <?php echo json_encode($l['prod_brand_generic'] ?? 'Generic'); ?>,
    discountLabel: <?php echo json_encode($l['pos_discount'] ?? 'Discount / Voucher'); ?>
};

let cart = [];
let productsList = [];
let customersList = []; // 全局会员缓存
let currentVoucher = { code: null, type: 'flat', value: 0.00 };
let currentMemberDiscountRatio = 0.00;
let currentManualDiscountFlat = 0.00; 

// 全局缓存当前最新结账账单数据
let currentReceiptData = null;

document.addEventListener("DOMContentLoaded", function() {
    fetchProducts("");
    loadCustomersList();

    let searchTimer;
    const searchInput = document.getElementById("pos-search");
    
    searchInput.addEventListener("input", function() {
        clearTimeout(searchTimer);
        const query = this.value;
        searchTimer = setTimeout(() => { fetchProducts(query); }, 300);
    });

    searchInput.addEventListener("keypress", function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = this.value.trim();
            if (query !== "") {
                fetchAndAutoAddToCart(query);
                this.value = "";
            }
        }
    });

    setupATMStyleInput();
    setupPhysicalBarcodeScannerListener();

    // 点击空白处，自动收起会员下拉建议框
    document.addEventListener('click', function(e) {
        const suggestions = document.getElementById('member-suggestions');
        const searchInput = document.getElementById('member-phone-search');
        if (suggestions && searchInput && !suggestions.contains(e.target) && e.target !== searchInput) {
            suggestions.style.display = 'none';
        }
    });
});

function setupPhysicalBarcodeScannerListener() {
    let rawInputBuffer = '';
    let lastKeyTime = Date.now();

    document.addEventListener('keypress', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
            return;
        }
        const currentTime = Date.now();
        if (currentTime - lastKeyTime > 50) { rawInputBuffer = ''; }
        lastKeyTime = currentTime;

        if (e.key === 'Enter') {
            if (rawInputBuffer.length >= 4) {
                e.preventDefault();
                fetchAndAutoAddToCart(rawInputBuffer);
                rawInputBuffer = '';
            }
        } else if (e.key !== 'Shift') {
            rawInputBuffer += e.key;
        }
    });
}

function fetchAndAutoAddToCart(barcode) {
    fetch(`pos.php?action=search&q=${encodeURIComponent(barcode)}`)
        .then(res => res.json())
        .then(data => {
            if (data && data.length > 0) {
                const match = data.find(p => p.barcode === barcode || p.product_code === barcode) || data[0];
                if (match.stock_quantity > 0) {
                    addToCart(match.id, match.product_code, match.name, match.selling_price, match.stock_quantity, match.image);
                    playBeepSound();
                } else {
                    alert(posLang.outOfStockWarning + match.name);
                }
            }
        });
}

function playBeepSound() {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        oscillator.frequency.setValueAtTime(1000, audioCtx.currentTime);
        gainNode.gain.setValueAtTime(0.05, audioCtx.currentTime);
        oscillator.start();
        oscillator.stop(audioCtx.currentTime + 0.08);
    } catch(e) {}
}

function setupATMStyleInput() {
    const paidInput = document.getElementById("amount-paid");
    paidInput.addEventListener("input", function() {
        let rawDigits = this.value.replace(/\D/g, "");
        if (rawDigits === "") {
            this.value = "";
            calculateChange();
            return;
        }
        let numericValue = parseFloat(rawDigits) / 100;
        this.value = numericValue.toFixed(2);
        calculateChange();
    });
}

function fetchProducts(query) {
    fetch(`pos.php?action=search&q=${encodeURIComponent(query)}`)
        .then(res => res.json())
        .then(data => {
            productsList = data;
            renderProductsGrid(data);
        });
}

// 渲染左侧商品面板 (已精简：移除了编号和供货商，只保留图片名字价钱数量)
function renderProductsGrid(products) {
    const container = document.getElementById("pos-products-container");
    if (products.length === 0) {
        container.innerHTML = `<div class="text-center py-5 text-muted"><p>${posLang.cartEmpty}</p></div>`;
        return;
    }

    let html = '<div class="row row-cols-2 row-cols-sm-3 row-cols-md-4 g-2">';
    products.forEach(p => {
        const hasStock = p.stock_quantity > 0;
        const isLowStock = p.stock_quantity <= p.min_stock_level;
        let badgeHtml = '';
        if (!hasStock) {
            badgeHtml = `<span class="badge bg-danger position-absolute top-0 end-0 m-2">${posLang.outOfStock}</span>`;
        } else if (isLowStock) {
            badgeHtml = `<span class="badge bg-warning text-dark position-absolute top-0 end-0 m-2">${posLang.lowStock}</span>`;
        }

        const imgPath = p.image ? `../uploads/${p.image}` : 'https://placehold.co/150x120?text=' + encodeURIComponent(p.name);

        html += `
            <div class="col">
                <div class="card h-100 border border-secondary-subtle bg-body position-relative shadow-sm" style="cursor: pointer;" onclick="${hasStock ? `addToCart(${p.id}, '${p.product_code}', '${p.name.replace(/'/g, "\\'")}', ${p.selling_price}, ${p.stock_quantity}, '${p.image || ''}')` : ''}">
                    ${badgeHtml}
                    <div class="d-flex align-items-center justify-content-center p-2" style="height: 100px; overflow: hidden;">
                        <img src="${imgPath}" class="img-fluid rounded" style="max-height: 100%; object-fit: contain;">
                    </div>
                    <div class="card-body p-2 d-flex flex-column justify-content-between">
                        <div>
                            <h6 class="fw-bold mb-1 text-truncate text-body-emphasis" style="font-size: 0.8rem;">${p.name}</h6>
                        </div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <span class="fw-bold text-primary small">RM ${parseFloat(p.selling_price).toFixed(2)}</span>
                            <small class="badge bg-secondary-subtle text-secondary rounded-pill">Qty: ${p.stock_quantity}</small>
                        </div>
                    </div>
                </div>
            </div>`;
    });
    html += '</div>';
    container.innerHTML = html;
}

// 加载会员列表，写入本地缓存用于快速检索
function loadCustomersList() {
    fetch('pos.php?action=get_customers')
        .then(res => res.json())
        .then(data => {
            customersList = data;
            renderMemberSelect(data);
        });
}

// 动态构建隐藏的原生下拉框（服务于原先底层财务取数）
function renderMemberSelect(list) {
    const select = document.getElementById('member-select');
    const currentValue = select.value;
    select.innerHTML = `<option value="" data-discount="0.00" data-points="0" selected>${posLang.walkinCustomer}</option>`;
    list.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = `${c.name} (${c.phone})`;
        opt.setAttribute('data-discount', c.membership_discount);
        opt.setAttribute('data-points', c.loyalty_points);
        if (c.id == currentValue) {
            opt.selected = true;
        }
        select.appendChild(opt);
    });
}

// 基于手机号和名字过滤会员选项（智能悬浮面板版 - 已增加多语言支持）
function filterMembersByPhone() {
    const searchInput = document.getElementById('member-phone-search');
    const searchVal = searchInput.value.trim().toLowerCase();
    const suggestions = document.getElementById('member-suggestions');
    
    // 如果输入框被全部清空，自动重置会员折扣为散客
    if (searchVal === '') {
        document.getElementById('member-select').value = '';
        applyMemberDiscount();
    }

    const filtered = customersList.filter(c => 
        c.phone.includes(searchVal) || c.name.toLowerCase().includes(searchVal)
    );
    
    let html = '';
    // 头部始终保留快速重置到散客的选项 (参数已采用安全字符串转义)
    html += `
        <button class="dropdown-item py-2 border-bottom text-muted" type="button" onclick="selectMember('', '${posLang.walkinCustomer.replace(/'/g, "\\'")}', '0.00', 0)">
            <i class="fas fa-user-slash me-2"></i><strong>${posLang.walkinCustomer}</strong>
        </button>
    `;

    if (filtered.length === 0) {
        html += `<div class="dropdown-item text-center py-2 text-muted small">${posLang.noMemberFound}</div>`;
    } else {
        filtered.forEach(c => {
            html += `
                <button class="dropdown-item py-2 d-flex justify-content-between align-items-center" type="button" onclick="selectMember('${c.id}', '${c.name.replace(/'/g, "\\'")} (${c.phone})', '${c.membership_discount}', ${c.loyalty_points})">
                    <div>
                        <span class="fw-bold d-block text-body">${c.name}</span>
                        <small class="text-muted"><i class="fas fa-phone-alt me-1 text-xs"></i>${c.phone}</small>
                    </div>
                    <span class="badge bg-primary-subtle text-primary rounded-pill small">${c.membership_discount}${posLang.percentOff}</span>
                </button>
            `;
        });
    }
    
    suggestions.innerHTML = html;
    suggestions.style.display = 'block';
}

// 选择常客会员的合并操作函数
function selectMember(id, displayText, discount, points) {
    const searchInput = document.getElementById('member-phone-search');
    const select = document.getElementById('member-select');
    
    // 1. 设置展示用的输入框文字 (如果是空说明选了 Walk-in)
    searchInput.value = (id === '') ? '' : displayText;
    
    // 2. 同步底层的隐藏原生下拉框的值
    select.value = id;
    
    // 3. 触发原生的常客特权和会员折扣算法
    applyMemberDiscount();
    
    // 4. 收起悬浮框
    document.getElementById('member-suggestions').style.display = 'none';
}

// 快速办理常客
function createNewCustomer() {
    const name = document.getElementById('cust-name-input').value.trim();
    const phone = document.getElementById('cust-phone-input').value.trim();
    const discount = document.getElementById('cust-discount-input').value;

    if (!name || !phone) {
        alert(posLang.namePhoneReq);
        return;
    }

    fetch('pos.php?action=add_customer', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, phone, discount })
    })
    .then(res => res.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('addCustomerModal')).hide();
            document.getElementById('cust-name-input').value = '';
            document.getElementById('cust-phone-input').value = '';
            loadCustomersList();
            
            // 自动选中新办的常客会员并折算福利
            setTimeout(() => {
                selectMember(res.customer.id, `${res.customer.name} (${res.customer.phone})`, res.customer.membership_discount, res.customer.loyalty_points);
            }, 650);
        } else {
            alert(res.message);
        }
    });
}

function applyMemberDiscount() {
    const select = document.getElementById('member-select');
    const selectedOpt = select.options[select.selectedIndex];
    const discountVal = selectedOpt ? (parseFloat(selectedOpt.getAttribute('data-discount')) || 0.00) : 0.00;
    const points = selectedOpt ? (parseInt(selectedOpt.getAttribute('data-points')) || 0) : 0;

    currentMemberDiscountRatio = discountVal / 100;

    const infoDiv = document.getElementById('member-loyalty-info');
    if (select.value === "") {
        infoDiv.classList.add('d-none');
    } else {
        infoDiv.classList.remove('d-none');
        document.getElementById('member-points-span').textContent = points;
    }
    updateTotals();
}

// 下拉直接选取优惠券核销逻辑
function applyVoucherDropdown() {
    const select = document.getElementById('voucher-select');
    const selectedOpt = select.options[select.selectedIndex];
    const code = select.value;
    
    if (code === "") {
        currentVoucher = { code: null, type: 'flat', value: 0.00 };
    } else {
        const type = selectedOpt.getAttribute('data-type');
        const value = parseFloat(selectedOpt.getAttribute('data-value'));
        currentVoucher = { code: code, type: type, value: value };
    }
    updateTotals();
}

function applyManualDiscount() {
    const discountInput = document.getElementById("manual-discount-input");
    let rawDigits = discountInput.value.replace(/\D/g, "");
    if (rawDigits === "") {
        currentManualDiscountFlat = 0.00;
        discountInput.value = "";
        updateTotals();
        return;
    }
    currentManualDiscountFlat = parseFloat(rawDigits) / 100;
    discountInput.value = currentManualDiscountFlat.toFixed(2);
    updateTotals();
}

function addToCart(id, code, name, price, maxStock, image) {
    const existing = cart.find(item => item.id === id);
    if (existing) {
        if (existing.qty < maxStock) {
            existing.qty++;
        } else {
            alert(posLang.insufficientStock.replace('%d', maxStock));
        }
    } else {
        cart.push({ id: id, code: code, name: name, price: parseFloat(price), qty: 1, maxStock: maxStock, image: image });
    }
    renderCart();
}

function renderCart() {
    const cartContainer = document.getElementById("pos-cart-items");
    if (cart.length === 0) {
        cartContainer.innerHTML = `<div class="text-center py-4 text-muted"><p class="mb-0 small">${posLang.cartEmpty}</p></div>`;
        updateTotals();
        return;
    }

    let html = '<div class="d-flex flex-column gap-2">';
    cart.forEach(item => {
        const imgPath = item.image ? `../uploads/${item.image}` : 'https://placehold.co/40x40?text=IMG';
        html += `
            <div class="d-flex align-items-start gap-2 p-2 rounded-3 border bg-body shadow-sm w-100">
                <img src="${imgPath}" class="rounded object-fit-cover shadow-sm border border-secondary-subtle flex-shrink-0" style="width: 40px; height: 40px;">
                <div class="d-flex flex-column flex-grow-1 min-width-0">
                    <div class="d-flex justify-content-between align-items-start gap-2 w-100">
                        <span class="fw-bold text-truncate text-body-emphasis text-xs flex-grow-1 min-width-0" title="${item.name}" style="font-size: 0.8rem; line-height: 1.2;">
                            ${item.name}
                        </span>
                        <span class="fw-bold text-body-emphasis text-xs flex-shrink-0 text-end" style="font-size: 0.8rem; min-width: 65px;">
                            RM ${(item.price * item.qty).toFixed(2)}
                        </span>
                    </div>
                    <div class="d-flex align-items-center justify-content-between gap-2 mt-1.5 w-100">
                        <div class="d-flex align-items-center gap-2">
                            <small class="text-muted flex-shrink-0" style="font-size: 0.72rem;">
                                RM ${item.price.toFixed(2)}
                            </small>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <button class="btn btn-xs btn-outline-secondary rounded-circle p-0 d-flex align-items-center justify-content-center" style="width:20px; height:20px; font-size: 0.75rem; line-height: 1;" onclick="adjustQty(${item.id}, -1)">-</button>
                                <input type="number" class="form-control text-center p-0" style="width: 32px; height: 20px; font-size: 0.75rem; min-height: 20px;" value="${item.qty}" min="1" max="${item.maxStock}" onchange="setQty(${item.id}, this.value)">
                                <button class="btn btn-xs btn-outline-secondary rounded-circle p-0 d-flex align-items-center justify-content-center" style="width:20px; height:20px; font-size: 0.75rem; line-height: 1;" onclick="adjustQty(${item.id}, 1)">+</button>
                            </div>
                        </div>
                        <a href="javascript:void(0)" class="text-danger small text-decoration-none flex-shrink-0 d-flex align-items-center" style="font-size: 0.7rem;" onclick="removeFromCart(${item.id})">
                            <i class="fas fa-times me-1"></i>${posLang.removeText}
                        </a>
                    </div>
                </div>
            </div>`;
    });
    html += '</div>';
    cartContainer.innerHTML = html;
    updateTotals();
}

function removeFromCart(id) {
    cart = cart.filter(item => item.id !== id);
    renderCart();
}

function adjustQty(id, diff) {
    const item = cart.find(i => i.id === id);
    if (item) {
        const newQty = item.qty + diff;
        if (newQty > 0 && newQty <= item.maxStock) {
            item.qty = newQty;
            renderCart();
        } else if (newQty > item.maxStock) {
            alert(posLang.insufficientStock.replace('%d', item.maxStock));
        }
    }
}

function setQty(id, val) {
    const item = cart.find(i => i.id === id);
    if (item) {
        let qty = parseInt(val);
        if (isNaN(qty) || qty < 1) qty = 1;
        if (qty > item.maxStock) {
            alert(posLang.insufficientStock.replace('%d', item.maxStock));
            qty = item.maxStock;
        }
        item.qty = qty;
        renderCart();
    }
}

function clearCart() {
    cart = [];
    currentVoucher = { code: null, type: 'flat', value: 0.00 };
    currentManualDiscountFlat = 0.00;
    document.getElementById('voucher-select').value = '';
    document.getElementById('manual-discount-input').value = '';
    document.getElementById('member-phone-search').value = '';
    document.getElementById('member-select').value = '';
    currentMemberDiscountRatio = 0.00;
    document.getElementById('member-loyalty-info').classList.add('d-none');
    renderCart();
}

function updateTotals() {
    let subtotal = 0;
    cart.forEach(item => { subtotal += item.price * item.qty; });

    let memberDiscountVal = subtotal * currentMemberDiscountRatio;
    let voucherDiscountVal = 0.00;
    if (currentVoucher.code) {
        if (currentVoucher.type === 'flat') {
            voucherDiscountVal = currentVoucher.value;
        } else if (currentVoucher.type === 'percentage') {
            voucherDiscountVal = (subtotal - memberDiscountVal) * (currentVoucher.value / 100);
        }
    }

    let totalDiscount = memberDiscountVal + voucherDiscountVal + currentManualDiscountFlat;
    let discountBase = Math.max(0.00, subtotal - totalDiscount);

    let sstRate = parseFloat(storeSettings.sstRate) || 0.00;
    let sstTaxAmount = discountBase * (sstRate / 100);

    let grandTotal = discountBase + sstTaxAmount;

    // 采用多语言常量，消除硬编码文本
    let discountText = posLang.discountLabel;
    let labelParts = [];
    if (currentMemberDiscountRatio > 0) labelParts.push(`Mbr(${(currentMemberDiscountRatio*100).toFixed(0)}%)`);
    if (currentVoucher.code) labelParts.push(`Code[${currentVoucher.code}]`);
    if (currentManualDiscountFlat > 0) labelParts.push(`Manual(RM ${currentManualDiscountFlat.toFixed(2)})`);
    if (labelParts.length > 0) discountText = labelParts.join(' + ');

    document.getElementById("cart-subtotal").innerText = "RM " + subtotal.toFixed(2);
    document.getElementById("discount-label").innerText = discountText;
    document.getElementById("cart-discount").innerText = "-RM " + totalDiscount.toFixed(2);
    document.getElementById("cart-tax").innerText = "RM " + sstTaxAmount.toFixed(2);
    document.getElementById("cart-total").innerText = "RM " + grandTotal.toFixed(2);
    calculateChange();
}

function calculateChange() {
    const total = getCartGrandTotal();
    const paid = parseFloat(document.getElementById("amount-paid").value) || 0;
    const change = paid >= total ? paid - total : 0;
    document.getElementById("change-due").innerText = "RM " + change.toFixed(2);
}

function getCartGrandTotal() {
    let subtotal = 0;
    cart.forEach(item => { subtotal += item.price * item.qty; });
    let memberDiscountVal = subtotal * currentMemberDiscountRatio;
    let voucherDiscountVal = 0.00;
    if (currentVoucher.code) {
        if (currentVoucher.type === 'flat') {
            voucherDiscountVal = currentVoucher.value;
        } else if (currentVoucher.type === 'percentage') {
            voucherDiscountVal = (subtotal - memberDiscountVal) * (currentVoucher.value / 100);
        }
    }
    let base = Math.max(0.00, subtotal - (memberDiscountVal + voucherDiscountVal + currentManualDiscountFlat));
    let sstRate = parseFloat(storeSettings.sstRate) || 0.00;
    return base + (base * (sstRate / 100));
}

function performCheckout() {
    if (cart.length === 0) {
        alert(posLang.cartEmpty);
        return;
    }

    const total = getCartGrandTotal();
    const paidAmountInput = document.getElementById("amount-paid");
    const paid = parseFloat(paidAmountInput.value) || 0;

    if (paid < total) {
        alert(posLang.insufficientPayment);
        return;
    }

    const btn = document.getElementById("btn-checkout");
    btn.disabled = true;
    btn.innerText = posLang.processing;

    let subtotal = 0;
    cart.forEach(item => { subtotal += item.price * item.qty; });
    let memberDiscountVal = subtotal * currentMemberDiscountRatio;
    let voucherDiscountVal = 0.00;
    if (currentVoucher.code) {
        if (currentVoucher.type === 'flat') {
            voucherDiscountVal = currentVoucher.value;
        } else if (currentVoucher.type === 'percentage') {
            voucherDiscountVal = (subtotal - memberDiscountVal) * (currentVoucher.value / 100);
        }
    }
    const totalDiscount = memberDiscountVal + voucherDiscountVal + currentManualDiscountFlat;
    const discountBase = Math.max(0.00, subtotal - totalDiscount);
    const sstRate = parseFloat(storeSettings.sstRate) || 0.00;
    const tax = discountBase * (sstRate / 100);

    const checkoutPayload = {
        cart: cart,
        paid_amount: paid,
        payment_method: document.getElementById("payment-method").value,
        customer_id: document.getElementById("member-select").value,
        discount_amount: totalDiscount,
        tax_amount: tax,
        voucher_code: currentVoucher.code
    };

    fetch('pos.php?action=checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(checkoutPayload)
    })
    .then(res => res.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = `<i class="fas fa-cash-register me-2"></i>${posLang.payBtnText}`;
        
        if (res.success) {
            currentReceiptData = res;
            showReceiptModal(res);
        } else {
            alert(res.message);
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = `<i class="fas fa-cash-register me-2"></i>${posLang.payBtnText}`;
        alert(posLang.checkoutFailed);
    });
}

// ==========================================
// 渲染交易小票模态框
// ==========================================
function showReceiptModal(data) {
    document.getElementById("receipt_store_address").innerText = storeSettings.address;
    document.getElementById("receipt_store_contact").innerText = `Tel: ${storeSettings.phone} | SST ID: ${storeSettings.sstRegNo}`;
    
    document.getElementById("receipt_invoice_num").innerText = data.invoice_number;
    document.getElementById("receipt_payment_method").innerText = data.payment_method;
    document.getElementById("receipt_date").innerText = data.date;
    document.getElementById("receipt_cashier").innerText = data.cashier;
    
    // 动态翻译小票中的散客/会员抬头
    document.getElementById("receipt_customer").innerText = (data.customer_name === 'Walk-in Customer') ? posLang.walkinCustomer : data.customer_name;
    
    const itemsBody = document.getElementById("receipt_items_body");
    itemsBody.innerHTML = '';
    
    data.items.forEach(item => {
        const tr = document.createElement("tr");
        tr.style.borderBottom = "1px dashed #333";
        tr.innerHTML = `
            <td style="padding: 4px 0; text-align: left; font-size: 11px; vertical-align: top; color: #000 !important;">
                ${item.name}
            </td>
            <td style="padding: 4px 0; text-align: center; font-size: 11px; vertical-align: top; color: #000 !important;">
                ${item.qty}
            </td>
            <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; color: #000 !important;">
                RM ${parseFloat(item.unit_price).toFixed(2)}
            </td>
            <td style="padding: 4px 0; text-align: right; font-size: 11px; vertical-align: top; font-weight: bold; color: #000 !important;">
                RM ${parseFloat(item.subtotal).toFixed(2)}
            </td>
        `;
        itemsBody.appendChild(tr);
    });

    document.getElementById("receipt_subtotal").innerText = "RM " + parseFloat(data.subtotal_amount).toFixed(2);
    document.getElementById("receipt_discount").innerText = "-RM " + parseFloat(data.discount_amount).toFixed(2);
    document.getElementById("receipt_sst_rate").innerText = parseFloat(storeSettings.sstRate).toFixed(2);
    document.getElementById("receipt_tax").innerText = "RM " + parseFloat(data.tax_amount).toFixed(2);
    document.getElementById("receipt_grand_total").innerText = "RM " + parseFloat(data.total_amount).toFixed(2);
    document.getElementById("receipt_paid").innerText = "RM " + parseFloat(data.paid_amount).toFixed(2);
    document.getElementById("receipt_change").innerText = "RM " + parseFloat(data.balance_amount).toFixed(2);
    
    // 展示当前累积会员积分
    const pointsSpan = document.getElementById("receipt_points_calc");
    if (data.customer_name !== 'Walk-in Customer') {
        pointsSpan.innerText = `${data.customer_points} Points`;
    } else {
        pointsSpan.innerText = `0 (${posLang.walkinCustomer})`;
    }

    const modal = new bootstrap.Modal(document.getElementById("receiptModal"));
    modal.show();
}

// ==========================================
// 静默热敏打印驱动 (Iframe 沙盒模式)
// ==========================================
function printReceipt() {
    if (!currentReceiptData) return;
    
    const receiptContent = document.getElementById("receipt-paper");
    if (!receiptContent) return;

    let iframe = document.getElementById("receipt-print-iframe");
    if (iframe) iframe.remove();

    iframe = document.createElement("iframe");
    iframe.id = "receipt-print-iframe";
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
                \${receiptContent.innerHTML}
            </div>
        </body>
        </html>
    `);
    doc.close();

    const originalTitle = window.parent.document.title || document.title;
    window.parent.document.title = ""; 
    doc.title = "";

    setTimeout(() => {
        iframe.contentWindow.focus();
        iframe.contentWindow.print();
        setTimeout(() => {
            window.parent.document.title = originalTitle;
            if (iframe) iframe.remove();
        }, 1000);
    }, 300);
}

// ==========================================
// 重置收银台状态
// ==========================================
function resetPOS() {
    clearCart();
    document.getElementById("amount-paid").value = "";
    document.getElementById("change-due").innerText = "RM 0.00";
    document.getElementById("member-select").value = "";
    document.getElementById("member-phone-search").value = "";
    document.getElementById("member-loyalty-info").classList.add('d-none');
    document.getElementById("pos-search").value = "";
    currentReceiptData = null;
    fetchProducts("");
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>