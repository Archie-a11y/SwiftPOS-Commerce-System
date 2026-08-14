<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once '../includes/header.php';

$report_type  = $_GET['report_type'] ?? 'daily_sales';
$date_filter  = $_GET['date_filter'] ?? 'this_month';
$start_date   = $_GET['start_date'] ?? '';
$end_date     = $_GET['end_date'] ?? '';
$search_query = $_GET['search_query'] ?? '';

$sql_where = "";
$bind_params = [];
$bind_types = "";

if ($report_type === 'daily_sales' || $report_type === 'monthly_sales' || $report_type === 'weekly_sales' || $report_type === 'yearly_sales') {
    $date_field = "s.sale_date";
} elseif ($report_type === 'purchase') {
    $date_field = "p.purchase_date";
} elseif ($report_type === 'stock_movement') {
    $date_field = "sa.adjustment_date";
} else {
    $date_field = "";
}

// 时间过滤逻辑
if (!empty($date_field)) {
    if ($date_filter === 'today') {
        $sql_where .= " AND DATE($date_field) = CURDATE()";
    } elseif ($date_filter === 'yesterday') {
        $sql_where .= " AND DATE($date_field) = SUBDATE(CURDATE(), 1)";
    } elseif ($date_filter === 'this_week') {
        $sql_where .= " AND YEARWEEK($date_field, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($date_filter === 'this_month') {
        $sql_where .= " AND YEAR($date_field) = YEAR(CURDATE()) AND MONTH($date_field) = MONTH(CURDATE())";
    } elseif ($date_filter === 'custom' && !empty($start_date) && !empty($end_date)) {
        $sql_where .= " AND DATE($date_field) BETWEEN ? AND ?";
        $bind_params[] = $start_date;
        $bind_params[] = $end_date;
        $bind_types .= "ss";
    }
}

// 关联商品报告时间过滤
$product_date_where = "";
if ($report_type === 'product' || $report_type === 'slow_moving') {
    if ($date_filter === 'today') {
        $product_date_where = " AND DATE(s.sale_date) = CURDATE()";
    } elseif ($date_filter === 'yesterday') {
        $product_date_where = " AND DATE(s.sale_date) = SUBDATE(CURDATE(), 1)";
    } elseif ($date_filter === 'this_week') {
        $product_date_where = " AND YEARWEEK(s.sale_date, 1) = YEARWEEK(CURDATE(), 1)";
    } elseif ($date_filter === 'this_month') {
        $product_date_where = " AND YEAR(s.sale_date) = YEAR(CURDATE()) AND MONTH(s.sale_date) = MONTH(CURDATE())";
    } elseif ($date_filter === 'custom' && !empty($start_date) && !empty($end_date)) {
        $product_date_where = " AND DATE(s.sale_date) BETWEEN ? AND ?";
        $bind_params[] = $start_date;
        $bind_params[] = $end_date;
        $bind_types .= "ss";
    }
}

// 检索过滤
if (!empty($search_query)) {
    $search_like = "%" . $search_query . "%";
    
    if ($report_type === 'daily_sales') {
        $sql_where .= " AND (s.invoice_number LIKE ? OR u.full_name LIKE ?)";
        $bind_params[] = $search_like; $bind_params[] = $search_like;
        $bind_types .= "ss";
    } elseif ($report_type === 'monthly_sales') {
        $sql_where .= " AND (DATE_FORMAT(s.sale_date, '%Y-%m') LIKE ?)";
        $bind_params[] = $search_like; $bind_types .= "s";
    } elseif ($report_type === 'weekly_sales') {
        $sql_where .= " AND (YEARWEEK(s.sale_date, 1) LIKE ?)";
        $bind_params[] = $search_like; $bind_types .= "s";
    } elseif ($report_type === 'yearly_sales') {
        $sql_where .= " AND (YEAR(s.sale_date) LIKE ?)";
        $bind_params[] = $search_like; $bind_types .= "s";
    } elseif ($report_type === 'purchase') {
        $sql_where .= " AND (p.purchase_number LIKE ? OR sup.company_name LIKE ? OR u.full_name LIKE ?)";
        $bind_params[] = $search_like; $bind_params[] = $search_like; $bind_params[] = $search_like;
        $bind_types .= "sss";
    } elseif ($report_type === 'inventory') {
        $sql_where .= " AND (pr.product_code LIKE ? OR pr.name LIKE ? OR pr.brand LIKE ? OR c.name LIKE ?)";
        $bind_params[] = $search_like; $bind_params[] = $search_like; $bind_params[] = $search_like; $bind_params[] = $search_like;
        $bind_types .= "ssss";
    } elseif ($report_type === 'product' || $report_type === 'slow_moving') {
        $sql_where .= " AND (pr.product_code LIKE ? OR pr.name LIKE ? OR pr.brand LIKE ?)";
        $bind_params[] = $search_like; $bind_params[] = $search_like; $bind_params[] = $search_like;
        $bind_types .= "sss";
    } elseif ($report_type === 'stock_movement') {
        $sql_where .= " AND (pr.product_code LIKE ? OR pr.name LIKE ? OR sa.reason LIKE ?)";
        $bind_params[] = $search_like; $bind_params[] = $search_like; $bind_params[] = $search_like;
        $bind_types .= "sss";
    }
}

// 动态编译报表 SQL 
$sql = "";
if ($report_type === 'daily_sales') {
    $sql = "SELECT s.invoice_number, s.sale_date, s.subtotal_amount, s.discount_amount, s.tax_amount, s.total_amount, s.paid_amount, s.balance_amount, s.payment_method, u.full_name AS cashier_name
            FROM sales s
            LEFT JOIN users u ON s.created_by = u.id
            WHERE 1=1 " . $sql_where . "
            ORDER BY s.sale_date DESC";
} elseif ($report_type === 'weekly_sales') {
    $sql = "SELECT YEARWEEK(s.sale_date, 1) AS sale_week, COUNT(s.id) AS total_invoices, SUM(s.total_amount) AS total_revenue
            FROM sales s
            WHERE 1=1 " . $sql_where . "
            GROUP BY YEARWEEK(s.sale_date, 1)
            ORDER BY sale_week DESC";
} elseif ($report_type === 'monthly_sales') {
    $sql = "SELECT DATE_FORMAT(s.sale_date, '%Y-%m') AS sale_month, COUNT(s.id) AS total_invoices, SUM(s.total_amount) AS total_revenue
            FROM sales s
            WHERE 1=1 " . $sql_where . "
            GROUP BY DATE_FORMAT(s.sale_date, '%Y-%m')
            ORDER BY sale_month DESC";
} elseif ($report_type === 'yearly_sales') {
    $sql = "SELECT YEAR(s.sale_date) AS sale_year, COUNT(s.id) AS total_invoices, SUM(s.total_amount) AS total_revenue
            FROM sales s
            WHERE 1=1 " . $sql_where . "
            GROUP BY YEAR(s.sale_date)
            ORDER BY sale_year DESC";
} elseif ($report_type === 'purchase') {
    $sql = "SELECT p.purchase_number, p.purchase_date, sup.company_name AS supplier_name, p.total_amount, p.remarks, u.full_name AS operator_name
            FROM purchases p
            LEFT JOIN suppliers sup ON p.supplier_id = sup.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE 1=1 " . $sql_where . "
            ORDER BY p.purchase_date DESC";
} elseif ($report_type === 'inventory') {
    $sql = "SELECT pr.product_code, pr.name AS product_name, pr.brand, c.name AS category_name, sup.company_name AS supplier_name, pr.stock_quantity, pr.min_stock_level, pr.cost_price, pr.selling_price, (pr.stock_quantity * pr.cost_price) AS inventory_value, pr.status
            FROM products pr
            LEFT JOIN categories c ON pr.category_id = c.id
            LEFT JOIN suppliers sup ON pr.supplier_id = sup.id
            WHERE 1=1 " . $sql_where . "
            ORDER BY pr.product_code ASC";
} elseif ($report_type === 'product') {
    $sql = "SELECT pr.product_code, pr.name AS product_name, pr.brand, c.name AS category_name, pr.stock_quantity, pr.cost_price, pr.selling_price,
                   IFNULL(SUM(si.quantity), 0) AS total_units_sold,
                   IFNULL(SUM(si.subtotal), 0.00) AS total_revenue,
                   IFNULL(SUM(si.quantity * (pr.selling_price - pr.cost_price)), 0.00) AS total_profit
            FROM products pr
            LEFT JOIN categories c ON pr.category_id = c.id
            LEFT JOIN sale_items si ON pr.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id " . $product_date_where . "
            WHERE 1=1 " . $sql_where . "
            GROUP BY pr.id
            ORDER BY total_units_sold DESC, pr.product_code ASC";
} elseif ($report_type === 'slow_moving') {
    $sql = "SELECT pr.product_code, pr.name AS product_name, pr.brand, c.name AS category_name, pr.stock_quantity, pr.selling_price,
                   IFNULL(SUM(si.quantity), 0) AS total_units_sold,
                   IFNULL(SUM(si.subtotal), 0.00) AS total_revenue
            FROM products pr
            LEFT JOIN categories c ON pr.category_id = c.id
            LEFT JOIN sale_items si ON pr.id = si.product_id
            LEFT JOIN sales s ON si.sale_id = s.id " . $product_date_where . "
            WHERE 1=1 " . $sql_where . "
            GROUP BY pr.id
            HAVING total_units_sold < 5
            ORDER BY total_units_sold ASC, pr.stock_quantity DESC";
} elseif ($report_type === 'stock_movement') {
    // 库存变动日志报表 (融合手动校准的出入库变动明细)
    $sql = "SELECT sa.adjustment_date AS movement_date, pr.product_code, pr.name AS product_name, pr.brand, 
                   sa.type AS movement_type, sa.quantity, sa.reason, u.full_name AS operator_name
            FROM stock_adjustments sa
            LEFT JOIN products pr ON sa.product_id = pr.id
            LEFT JOIN users u ON sa.adjusted_by = u.id
            WHERE 1=1 " . $sql_where . "
            ORDER BY sa.adjustment_date DESC";
}

$report_data = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($bind_types)) {
        $stmt->bind_param($bind_types, ...$bind_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $report_data[] = $row;
    }
    $stmt->close();
}

$report_titles = [
    'daily_sales'   => $l['rep_daily_sales'],
    'weekly_sales'  => $l['rep_weekly_sales'] ?? 'Weekly Sales Report',
    'monthly_sales' => $l['rep_monthly_sales'],
    'yearly_sales'  => $l['rep_yearly_sales'] ?? 'Yearly Sales Report',
    'purchase'      => $l['rep_purchase'],
    'inventory'     => $l['rep_inventory'],
    'product'       => $l['rep_product_ranking'] ?? 'Product Sales & Profit Ranking',
    'slow_moving'   => $l['rep_slow_moving'] ?? 'Slow Moving (Dead Stock) Report',
    'stock_movement'=> $l['rep_stock_movement'] ?? 'Stock Movement Log'
];
$active_report_title = $report_titles[$report_type] ?? "Report";
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div class="container-fluid py-2">
    <div class="mb-4">
        <h3 class="fw-bold mb-1"><?php echo htmlspecialchars($l['nav_reports']); ?></h3>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars($l['rep_subtitle']); ?></p>
    </div>

    <!-- 过滤器 -->
    <div class="card border-0 shadow-sm mb-4 d-print-none rounded-4">
        <div class="card-body p-4">
            <form method="GET" action="reports.php" class="row g-3 align-items-end">
                <div class="col-lg-10 col-md-9 col-sm-12">
                    <div class="row g-3">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label mb-1 fw-bold text-muted small"><?php echo $l['rep_type']; ?></label>
                            <select name="report_type" id="report_type" class="form-select rounded-3 py-2.5">
                                <option value="daily_sales" <?php echo ($report_type == 'daily_sales') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_daily_sales']); ?></option>
                                <option value="weekly_sales" <?php echo ($report_type == 'weekly_sales') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_weekly_sales'] ?? 'Weekly Sales Report'); ?></option>
                                <option value="monthly_sales" <?php echo ($report_type == 'monthly_sales') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_monthly_sales']); ?></option>
                                <option value="yearly_sales" <?php echo ($report_type == 'yearly_sales') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_yearly_sales'] ?? 'Yearly Sales Report'); ?></option>
                                <option value="purchase" <?php echo ($report_type == 'purchase') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_purchase']); ?></option>
                                <option value="inventory" <?php echo ($report_type == 'inventory') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_inventory']); ?></option>
                                <option value="product" <?php echo ($report_type == 'product') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_product']); ?> (<?php echo htmlspecialchars($l['rep_selling_profit'] ?? 'Selling & Profit'); ?>)</option>
                                <option value="slow_moving" <?php echo ($report_type == 'slow_moving') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_slow_moving'] ?? 'Slow Moving Products Analysis'); ?></option>
                                <option value="stock_movement" <?php echo ($report_type == 'stock_movement') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_stock_movement'] ?? 'Stock Movement Log'); ?></option>
                            </select>
                        </div>

                        <div class="col-md-4 col-sm-6">
                            <label class="form-label mb-1 fw-bold text-muted small"><?php echo $l['rep_date_filter']; ?></label>
                            <select name="date_filter" id="date_filter" class="form-select rounded-3 py-2.5" onchange="toggleCustomDates()">
                                <option value="today" <?php echo ($date_filter == 'today') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['today_sales'] ?? 'Today'); ?></option>
                                <option value="yesterday" <?php echo ($date_filter == 'yesterday') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['yesterday_sales'] ?? 'Yesterday'); ?></option>
                                <option value="this_week" <?php echo ($date_filter == 'this_week') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['this_week_sales'] ?? 'This Week'); ?></option>
                                <option value="this_month" <?php echo ($date_filter == 'this_month') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['this_month_sales'] ?? 'This Month'); ?></option>
                                <option value="custom" <?php echo ($date_filter == 'custom') ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['rep_date_custom'] ?? 'Custom Range'); ?></option>
                            </select>
                        </div>

                        <div class="col-md-4 col-sm-12">
                            <label class="form-label mb-1 fw-bold text-muted small"><?php echo $l['prod_filter_search_placeholder']; ?></label>
                            <div class="input-group rounded-3 overflow-hidden">
                                <span class="input-group-text bg-transparent text-muted rounded-start-3 border-end-0 py-2.5">
                                    <i class="fas fa-search small"></i>
                                </span>
                                <input type="text" name="search_query" class="form-control rounded-end-3 border-start-0 py-2.5" placeholder="<?php echo htmlspecialchars($l['rep_search_placeholder_custom']); ?>" value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>

                        <div class="col-md-6 col-sm-6 custom-date-group" style="display: none;">
                            <label class="form-label mb-1 fw-bold text-muted small"><?php echo $l['rep_start_date']; ?></label>
                            <input type="date" name="start_date" id="start_date" class="form-control rounded-3 py-2.5" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>

                        <div class="col-md-6 col-sm-6 custom-date-group" style="display: none;">
                            <label class="form-label mb-1 fw-bold text-muted small"><?php echo $l['rep_end_date']; ?></label>
                            <input type="date" name="end_date" id="end_date" class="form-control rounded-3 py-2.5" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                    </div>
                </div>

                <div class="col-lg-2 col-md-3 col-sm-12 d-grid">
                    <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-2 fw-bold rounded-3 py-2.5">
                        <i class="fas fa-sync-alt"></i>
                        <span><?php echo htmlspecialchars($l['rep_btn_generate']); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 报表预览大盘 -->
    <?php if (empty($report_data)): ?>
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body text-center py-5">
                <div class="fs-1 text-muted mb-3"><i class="fas fa-folder-open"></i></div>
                <h5 class="text-secondary fw-bold mb-1"><?php echo htmlspecialchars($l['rep_no_data']); ?></h5>
            </div>
        </div>
    <?php else: ?>
        <!-- 统计面板与导出工具箱 -->
        <div class="row g-3 mb-4 d-print-none align-items-center">
            <div class="col-xl-6 col-lg-5 d-flex align-items-center">
                <div class="bg-light rounded-pill px-4 py-2 border d-flex flex-wrap align-items-center gap-2">
                    <span class="text-muted small fw-bold"><i class="fas fa-chart-bar text-primary me-1"></i> <?php echo htmlspecialchars($l['rep_summary_label'] ?? 'Summary:'); ?></span>
                    <span class="badge bg-secondary rounded-pill px-3"><?php echo htmlspecialchars($l['rep_records_label'] ?? 'Records:'); ?> <?php echo count($report_data); ?></span>
                    
                    <?php if ($report_type === 'daily_sales'): 
                        $total_sum = array_sum(array_column($report_data, 'total_amount'));
                    ?>
                        <span class="badge bg-success rounded-pill px-3"><?php echo htmlspecialchars($l['rep_revenue_label'] ?? 'Revenue:'); ?> RM <?php echo number_format($total_sum, 2); ?></span>
                    <?php elseif ($report_type === 'monthly_sales' || $report_type === 'weekly_sales' || $report_type === 'yearly_sales'): 
                        $total_sum = array_sum(array_column($report_data, 'total_revenue'));
                    ?>
                        <span class="badge bg-success rounded-pill px-3"><?php echo htmlspecialchars($l['rep_revenue_label'] ?? 'Revenue:'); ?> RM <?php echo number_format($total_sum, 2); ?></span>
                    <?php elseif ($report_type === 'inventory'): 
                        $total_qty = array_sum(array_column($report_data, 'stock_quantity'));
                        $total_val = array_sum(array_column($report_data, 'inventory_value'));
                    ?>
                        <span class="badge bg-info rounded-pill px-3"><?php echo htmlspecialchars($l['rep_total_qty_label'] ?? 'Total Qty:'); ?> <?php echo $total_qty; ?></span>
                        <span class="badge bg-success rounded-pill px-3"><?php echo htmlspecialchars($l['rep_valuation_label'] ?? 'Valuation:'); ?> RM <?php echo number_format($total_val, 2); ?></span>
                    <?php elseif ($report_type === 'product'): 
                        $total_qty = array_sum(array_column($report_data, 'total_units_sold'));
                        $total_profit = array_sum(array_column($report_data, 'total_profit'));
                    ?>
                        <span class="badge bg-info rounded-pill px-3"><?php echo htmlspecialchars($l['rep_sold_label'] ?? 'Sold:'); ?> <?php echo $total_qty; ?></span>
                        <span class="badge bg-success rounded-pill px-3"><?php echo htmlspecialchars($l['rep_net_profit_label'] ?? 'Net Profit:'); ?> RM <?php echo number_format($total_profit, 2); ?></span>
                    <?php elseif ($report_type === 'stock_movement'): 
                        $total_adds = 0; $total_subs = 0;
                        foreach ($report_data as $r) {
                            if ($r['movement_type'] === 'Add') $total_adds += $r['quantity'];
                            else $total_subs += $r['quantity'];
                        }
                    ?>
                        <span class="badge bg-success rounded-pill px-3"><?php echo htmlspecialchars($l['rep_total_in_label'] ?? 'Total In:'); ?> +<?php echo $total_adds; ?></span>
                        <span class="badge bg-danger rounded-pill px-3"><?php echo htmlspecialchars($l['rep_total_out_label'] ?? 'Total Out:'); ?> -<?php echo $total_subs; ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-6 col-lg-7 d-flex justify-content-lg-end gap-2 flex-wrap align-items-center">
                <button class="btn btn-outline-success d-flex align-items-center gap-2 fw-bold px-3 py-2 rounded-3"
                        onclick="exportTableToExcel('reportTable', '<?php echo $report_type; ?>_Report.xlsx', 'Report')">
                    <i class="fas fa-file-excel fs-5 text-success"></i>
                    <span><?php echo htmlspecialchars($l['rep_btn_excel'] ?? 'Export Excel'); ?></span>
                </button>
                <button class="btn btn-outline-info d-flex align-items-center gap-2 fw-bold px-3 py-2 rounded-3"
                        onclick="exportTableToCSV('reportTable', '<?php echo $report_type; ?>_Report.csv')">
                    <i class="fas fa-file-csv fs-5 text-info"></i>
                    <span><?php echo htmlspecialchars($l['rep_btn_csv'] ?? 'Export CSV'); ?></span>
                </button>
                <button class="btn btn-outline-danger d-flex align-items-center gap-2 fw-bold px-3 py-2 rounded-3"
                        onclick="exportTableToPDF('reportTable', '<?php echo htmlspecialchars($active_report_title); ?>', '<?php echo $report_type; ?>_Report.pdf')">
                    <i class="fas fa-file-pdf fs-5 text-danger"></i>
                    <span><?php echo htmlspecialchars($l['rep_btn_pdf'] ?? 'Export PDF'); ?></span>
                </button>
                <button class="btn btn-outline-primary d-flex align-items-center gap-2 fw-bold px-3 py-2 rounded-3"
                        onclick="printCurrentPage()">
                    <i class="fas fa-print fs-5 text-primary"></i>
                    <span><?php echo htmlspecialchars($l['rep_btn_print'] ?? 'Print Report'); ?></span>
                </button>
            </div>
        </div>

        <!-- 核心报表数据表区 -->
        <div class="card border-0 shadow-sm print-area rounded-4">
            <div class="card-header bg-transparent border-0 p-4 pb-0 d-print-none">
                <h5 class="fw-bold text-dark-emphasis mb-0 d-flex align-items-center gap-2">
                    <i class="fas fa-file-invoice-dollar text-primary"></i>
                    <span><?php echo htmlspecialchars($active_report_title); ?></span>
                </h5>
            </div>
            
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="reportTable">
                        <thead>
                            <?php if ($report_type === 'daily_sales'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['invoice_number'] ?? 'Invoice Number'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_date'] ?? 'Sale Date'); ?></th>
                                    <th class="text-end"><?php echo htmlspecialchars($l['pos_subtotal'] ?? 'Subtotal'); ?></th>
                                    <th class="text-end"><?php echo htmlspecialchars($l['pos_discount'] ?? 'Discount'); ?></th>
                                    <th class="text-end"><?php echo htmlspecialchars($l['rep_col_sst'] ?? 'SST Tax (RM)'); ?></th>
                                    <th class="text-end"><?php echo htmlspecialchars($l['pos_grand_total'] ?? 'Grand Total'); ?></th>
                                    <th><?php echo htmlspecialchars($l['payment_method'] ?? 'Payment Method'); ?></th>
                                    <th class="d-print-none"><?php echo htmlspecialchars($l['rep_col_cashier'] ?? 'Cashier'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'weekly_sales'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['rep_col_yearweek'] ?? 'Year-Week'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_invoices'] ?? 'Invoices Issued'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_weekly_revenue'] ?? 'Weekly Revenue'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'monthly_sales'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['rep_col_month'] ?? 'Year-Month'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_invoices'] ?? 'Invoices Issued'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_monthly_revenue'] ?? 'Monthly Revenue'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'yearly_sales'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['rep_col_year'] ?? 'Year'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_invoices'] ?? 'Invoices Issued'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_yearly_revenue'] ?? 'Yearly Revenue'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'purchase'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['purchase_number'] ?? 'Purchase Number'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_date'] ?? 'Purchase Date'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_supplier'] ?? 'Supplier Name'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_amount'] ?? 'Total Cost'); ?></th>
                                    <th><?php echo htmlspecialchars($l['remarks'] ?? 'Remarks'); ?></th>
                                    <th class="d-print-none"><?php echo htmlspecialchars($l['adjusted_by'] ?? 'Operator'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'inventory'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['prod_th_code'] ?? 'Code'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_name'] ?? 'Name'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_brand'] ?? 'Brand'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_category'] ?? 'Category'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_supplier'] ?? 'Supplier'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_stock'] ?? 'Stock'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_lbl_min_stock'] ?? 'Min Stock'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_lbl_cost'] ?? 'Cost Price'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_lbl_selling'] ?? 'Selling Price'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_value'] ?? 'Valuation (Cost)'); ?></th>
                                    <th class="d-print-none"><?php echo htmlspecialchars($l['prod_th_status'] ?? 'Status'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'product'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['prod_th_code'] ?? 'Code'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_name'] ?? 'Name'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_brand'] ?? 'Brand'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_category'] ?? 'Category'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_current_stock'] ?? 'Current Stock'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_lbl_selling'] ?? 'Selling Price'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_units_sold'] ?? 'Units Sold'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_revenue_generated'] ?? 'Revenue Generated'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_net_profit'] ?? 'Net Profit (RM)'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'slow_moving'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['prod_th_code'] ?? 'Code'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_name'] ?? 'Name'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_brand'] ?? 'Brand'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_category'] ?? 'Category'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_stock_left'] ?? 'Stock Left'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_lbl_selling'] ?? 'Selling Price'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_dead_stock'] ?? 'Units Sold (Dead Stock)'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_total_revenue'] ?? 'Total Revenue'); ?></th>
                                </tr>
                            <?php elseif ($report_type === 'stock_movement'): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($l['inv_log_th_date'] ?? 'Adjustment Date'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_code'] ?? 'Product Code'); ?></th>
                                    <th><?php echo htmlspecialchars($l['prod_th_name'] ?? 'Product Name'); ?></th>
                                    <th><?php echo htmlspecialchars($l['rep_col_brand'] ?? 'Brand'); ?></th>
                                    <th class="text-center"><?php echo htmlspecialchars($l['inv_lbl_type'] ?? 'Movement Type'); ?></th>
                                    <th class="text-center"><?php echo htmlspecialchars($l['inv_log_th_qty'] ?? 'Quantity Change'); ?></th>
                                    <th><?php echo htmlspecialchars($l['inv_lbl_reason'] ?? 'Reason / Description'); ?></th>
                                    <th class="d-print-none"><?php echo htmlspecialchars($l['adjusted_by'] ?? 'Operator'); ?></th>
                                </tr>
                            <?php endif; ?>
                        </thead>
                        
                        <tbody>
                            <?php foreach ($report_data as $row): ?>
                                <?php if ($report_type === 'daily_sales'): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['invoice_number']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($row['sale_date'])); ?></td>
                                        <td class="text-end">RM <?php echo number_format($row['subtotal_amount'], 2); ?></td>
                                        <td class="text-end text-danger">-RM <?php echo number_format($row['discount_amount'], 2); ?></td>
                                        <td class="text-end text-warning">RM <?php echo number_format($row['tax_amount'], 2); ?></td>
                                        <td class="text-end fw-bold">RM <?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['payment_method']); ?></span></td>
                                        <td class="d-print-none text-muted small"><?php echo htmlspecialchars($row['cashier_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'weekly_sales'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['sale_week']); ?></td>
                                        <td><?php echo htmlspecialchars($row['total_invoices']); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'monthly_sales'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['sale_month']); ?></td>
                                        <td><?php echo htmlspecialchars($row['total_invoices']); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'yearly_sales'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['sale_year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['total_invoices']); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'purchase'): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($row['purchase_number']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($row['purchase_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($row['supplier_name'] ?? 'Unknown'); ?></td>
                                        <td class="fw-bold text-danger">RM <?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($row['remarks'] ?? '-'); ?></small></td>
                                        <td class="d-print-none text-muted small"><?php echo htmlspecialchars($row['operator_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'inventory'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($row['brand'] ?: 'Generic'); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></span></td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($row['supplier_name'] ?? '-'); ?></small></td>
                                        <td class="fw-bold <?php echo ($row['stock_quantity'] <= $row['min_stock_level']) ? 'text-danger' : ''; ?>">
                                            <?php echo htmlspecialchars($row['stock_quantity']); ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($row['min_stock_level']); ?></small></td>
                                        <td>RM <?php echo number_format($row['cost_price'], 2); ?></td>
                                        <td>RM <?php echo number_format($row['selling_price'], 2); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['inventory_value'], 2); ?></td>
                                        <td class="d-print-none">
                                            <span class="badge <?php echo ($row['status'] === 'Active') ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo ($row['status'] === 'Active') ? $l['prod_active'] : $l['prod_inactive']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php elseif ($report_type === 'product'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($row['brand'] ?: 'Generic'); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['stock_quantity']); ?></td>
                                        <td>RM <?php echo number_format($row['selling_price'], 2); ?></td>
                                        <td class="fw-bold text-info"><?php echo htmlspecialchars($row['total_units_sold']); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_profit'], 2); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'slow_moving'): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($row['brand'] ?: 'Generic'); ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($row['category_name'] ?? '-'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['stock_quantity']); ?></td>
                                        <td>RM <?php echo number_format($row['selling_price'], 2); ?></td>
                                        <td class="fw-bold text-danger"><?php echo htmlspecialchars($row['total_units_sold']); ?></td>
                                        <td class="fw-bold text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'stock_movement'): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i:s', strtotime($row['movement_date'])); ?></td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($row['product_code']); ?></td>
                                        <td><?php echo htmlspecialchars($row['product_name']); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($row['brand'] ?: 'Generic'); ?></td>
                                        <td class="text-center">
                                            <?php if ($row['movement_type'] === 'Add'): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1"><?php echo htmlspecialchars($l['inv_opt_add'] ?? 'Stock In'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill px-3 py-1"><?php echo htmlspecialchars($l['inv_opt_sub'] ?? 'Stock Out'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-bold <?php echo ($row['movement_type'] === 'Add') ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo ($row['movement_type'] === 'Add') ? '+' : '-'; ?><?php echo number_format($row['quantity']); ?>
                                        </td>
                                        <td><small class="text-muted"><?php echo htmlspecialchars($row['reason'] ?? '-'); ?></small></td>
                                        <td class="d-print-none text-muted small"><?php echo htmlspecialchars($row['operator_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                        
                        <tfoot class="table-light">
                            <?php if ($report_type === 'daily_sales'): 
                                $total_sum = array_sum(array_column($report_data, 'total_amount'));
                            ?>
                                <tr>
                                    <td colspan="5" class="text-end fw-bold"><?php echo htmlspecialchars($l['rep_total_label'] ?? 'Total:'); ?></td>
                                    <td class="fw-bold text-success text-end">RM <?php echo number_format($total_sum, 2); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php elseif ($report_type === 'monthly_sales' || $report_type === 'weekly_sales' || $report_type === 'yearly_sales'): 
                                $total_sum = array_sum(array_column($report_data, 'total_revenue'));
                            ?>
                                <tr>
                                    <td colspan="2" class="text-end fw-bold"><?php echo htmlspecialchars($l['rep_total_label'] ?? 'Total:'); ?></td>
                                    <td class="fw-bold text-success">RM <?php echo number_format($total_sum, 2); ?></td>
                                </tr>
                            <?php elseif ($report_type === 'inventory'): 
                                $total_qty = array_sum(array_column($report_data, 'stock_quantity'));
                                $total_val = array_sum(array_column($report_data, 'inventory_value'));
                            ?>
                                <tr>
                                    <td colspan="5" class="text-end fw-bold"><?php echo htmlspecialchars($l['rep_total_label'] ?? 'Total:'); ?></td>
                                    <td class="fw-bold text-info"><?php echo $total_qty; ?></td>
                                    <td colspan="3"></td>
                                    <td class="fw-bold text-success">RM <?php echo number_format($total_val, 2); ?></td>
                                    <td class="d-print-none"></td>
                                </tr>
                            <?php elseif ($report_type === 'product'): 
                                $total_qty = array_sum(array_column($report_data, 'total_units_sold'));
                                $total_profit = array_sum(array_column($report_data, 'total_profit'));
                            ?>
                                <tr>
                                    <td colspan="6" class="text-end fw-bold"><?php echo htmlspecialchars($l['rep_total_label'] ?? 'Total:'); ?></td>
                                    <td class="fw-bold text-info"><?php echo $total_qty; ?></td>
                                    <td></td>
                                    <td class="fw-bold text-success">RM <?php echo number_format($total_profit, 2); ?></td>
                                </tr>
                            <?php elseif ($report_type === 'stock_movement'): 
                                $total_adds = 0; $total_subs = 0;
                                foreach ($report_data as $r) {
                                    if ($r['movement_type'] === 'Add') $total_adds += $r['quantity'];
                                    else $total_subs += $r['quantity'];
                                }
                            ?>
                                <tr>
                                    <td colspan="5" class="text-end fw-bold"><?php echo htmlspecialchars($l['rep_summary_label'] ?? 'Summary:'); ?></td>
                                    <td class="text-center fw-bold">
                                        <span class="text-success">+<?php echo $total_adds; ?></span> / 
                                        <span class="text-danger">-<?php echo $total_subs; ?></span>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php endif; ?>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleCustomDates() {
    const dateFilter = document.getElementById('date_filter').value;
    const customGroups = document.querySelectorAll('.custom-date-group');
    customGroups.forEach(group => {
        group.style.display = (dateFilter === 'custom') ? 'block' : 'none';
    });
}
function printCurrentPage() { window.print(); }
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll("tr");
    for (let i = 0; i < rows.length; i++) {
        let row = [];
        const cols = rows[i].querySelectorAll("td, th");
        for (let j = 0; j < cols.length; j++) {
            if (cols[j].classList.contains("no-print") || cols[j].classList.contains("d-print-none")) continue;
            let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, "").replace(/(\s\s+)/g, ' ');
            data = data.replace(/"/g, '""');
            row.push('"' + data + '"');
        }
        if (row.length > 0) csv.push(row.join(","));
    }
    const csvFile = new Blob([csv.join("\n")], { type: "text/csv;charset=utf-8;" });
    const downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
document.addEventListener('DOMContentLoaded', toggleCustomDates);
</script>

<?php include_once '../includes/footer.php'; ?>