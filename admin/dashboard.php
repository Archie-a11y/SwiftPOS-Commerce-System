<?php
// 引入头部 (已自动开启会话、加载多语言字典包 $l 并捕获数据库连接 $conn)
require_once '../includes/header.php';

// ==================== 1. 业务指标数据查询 ====================

$total_products = $conn->query("SELECT COUNT(*) AS total FROM products")->fetch_assoc()['total'] ?? 0;
$total_categories = $conn->query("SELECT COUNT(*) AS total FROM categories")->fetch_assoc()['total'] ?? 0;
$total_suppliers = $conn->query("SELECT COUNT(*) AS total FROM suppliers")->fetch_assoc()['total'] ?? 0;

$total_sales_res = $conn->query("SELECT SUM(total_amount) AS total FROM sales")->fetch_assoc();
$total_sales = $total_sales_res['total'] ?? 0.00;

// 新增：总订单数统计
$total_orders = $conn->query("SELECT COUNT(*) AS total FROM sales")->fetch_assoc()['total'] ?? 0;

$total_purchases_res = $conn->query("SELECT SUM(total_amount) AS total FROM purchases")->fetch_assoc();
$total_purchases = $total_purchases_res['total'] ?? 0.00;

$today_sales_res = $conn->query("SELECT SUM(total_amount) AS total FROM sales WHERE DATE(sale_date) = CURDATE()")->fetch_assoc();
$today_sales = $today_sales_res['total'] ?? 0.00;

// 新增：本周销售额统计 (Malaysia Format: 星期一至星期日为一周期)
$weekly_sales_res = $conn->query("SELECT SUM(total_amount) AS total FROM sales WHERE YEARWEEK(sale_date, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc();
$weekly_sales = $weekly_sales_res['total'] ?? 0.00;

$monthly_sales_res = $conn->query("SELECT SUM(total_amount) AS total FROM sales WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())")->fetch_assoc();
$monthly_sales = $monthly_sales_res['total'] ?? 0.00;

$low_stock = $conn->query("SELECT COUNT(*) AS total FROM products WHERE stock_quantity <= min_stock_level")->fetch_assoc()['total'] ?? 0;

// 新增：畅销商品排名 (Top Selling Products List)
$top_selling_res = $conn->query("
    SELECT p.product_code, p.name, SUM(si.quantity) AS total_qty_sold, SUM(si.subtotal) AS total_sales_value 
    FROM sale_items si 
    JOIN products p ON si.product_id = p.id 
    GROUP BY si.product_id 
    ORDER BY total_qty_sold DESC 
    LIMIT 5
");
$top_selling_products = [];
while ($ts_row = $top_selling_res->fetch_assoc()) {
    $top_selling_products[] = $ts_row;
}

// 准备最近 6 个月的销售走势数据
$chart_months = [];
$chart_sales = [];
for ($i = 5; $i >= 0; $i--) {
    $month_date = date('Y-m', strtotime("-$i months"));
    $month_name = date('M Y', strtotime("-$i months"));
    $chart_months[] = $month_name;
    
    $chart_res = $conn->query("SELECT SUM(total_amount) AS total FROM sales WHERE DATE_FORMAT(sale_date, '%Y-%m') = '$month_date'")->fetch_assoc();
    $chart_sales[] = (float)($chart_res['total'] ?? 0.00);
}

// 提取最新的 5 笔合并业务 activity (销售和采购)
$recent_activities = [];

$sales_query = $conn->query("SELECT 'Sale' AS act_type, invoice_number AS ref_no, total_amount, sale_date AS act_date FROM sales ORDER BY sale_date DESC LIMIT 5");
while($row = $sales_query->fetch_assoc()) {
    $recent_activities[] = [
        'type' => 'Sale',
        'ref' => $row['ref_no'],
        'amount' => $row['total_amount'],
        'date' => $row['act_date'],
        'badge' => 'success'
    ];
}

$purchases_query = $conn->query("SELECT 'Purchase' AS act_type, purchase_number AS ref_no, total_amount, purchase_date AS act_date FROM purchases ORDER BY purchase_date DESC LIMIT 5");
while($row = $purchases_query->fetch_assoc()) {
    $recent_activities[] = [
        'type' => 'Purchase',
        'ref' => $row['ref_no'],
        'amount' => $row['total_amount'],
        'date' => $row['act_date'],
        'badge' => 'danger'
    ];
}

usort($recent_activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});
$recent_activities = array_slice($recent_activities, 0, 5);
?>

<!-- 1. 首选高稳定性 Cloudflare CDNJS 节点 (使用符合 V4 规范的 UMD 格式包) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>

<!-- 2. 第一级备用方案：若 CDNJS 加载被阻断，动态加载 UNPKG 节点 -->
<script>
if (typeof Chart === 'undefined') {
    document.write('<script src="https://unpkg.com/chart.js@4.4.1/dist/chart.umd.js"><\/script>');
}
</script>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1"><?php echo $l['dash_title']; ?></h2>
            <p class="text-muted small mb-0"><?php echo $l['dash_subtitle']; ?></p>
        </div>
        <div class="text-end">
            <span class="badge bg-secondary p-2"><i class="fas fa-calendar-day me-1"></i> <?php echo date('Y-m-d'); ?></span>
        </div>
    </div>

    <!-- 1. 第一排指标：统计计数 -->
    <div class="row g-3 mb-4">
        <!-- 商品总数 -->
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-primary bg-opacity-10 text-primary me-3">
                        <i class="fas fa-box fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?php echo $l['dash_total_products']; ?></small>
                        <h4 class="fw-bold mb-0"><?php echo $total_products; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 分类总数 -->
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-info bg-opacity-10 text-info me-3">
                        <i class="fas fa-tags fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?php echo $l['dash_total_categories']; ?></small>
                        <h4 class="fw-bold mb-0"><?php echo $total_categories; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 供应商总数 -->
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-warning bg-opacity-10 text-warning me-3">
                        <i class="fas fa-truck fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?php echo $l['dash_total_suppliers']; ?></small>
                        <h4 class="fw-bold mb-0"><?php echo $total_suppliers; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <!-- 总账单/订单数量 -->
        <div class="col-12 col-md-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body">
                <div class="d-flex align-items-center">
                    <div class="p-3 rounded-circle bg-success bg-opacity-10 text-success me-3">
                        <i class="fas fa-file-invoice-dollar fa-lg"></i>
                    </div>
                    <div>
                        <small class="text-muted d-block"><?php echo $l['rep_total_invoices'] ?? 'Total Invoices'; ?></small>
                        <h4 class="fw-bold mb-0"><?php echo $total_orders; ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. 第二排指标：财务业绩与库存警戒 -->
    <div class="row g-3 mb-4">
        <!-- 今日营业额 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 text-white bg-success rounded-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-white-50 d-block"><?php echo $l['dash_today_sales']; ?></small>
                        <h3 class="fw-bold mb-0">RM <?php echo number_format($today_sales, 2); ?></h3>
                    </div>
                    <i class="fas fa-coins fa-2x text-white-50"></i>
                </div>
            </div>
        </div>
        <!-- 本周营业额 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 text-white bg-warning text-dark rounded-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-dark-50 d-block text-secondary"><?php echo $l['weekly_sales'] ?? 'Weekly Sales'; ?></small>
                        <h3 class="fw-bold mb-0">RM <?php echo number_format($weekly_sales, 2); ?></h3>
                    </div>
                    <i class="fas fa-calendar-week fa-2x text-dark-50 opacity-50"></i>
                </div>
            </div>
        </div>
        <!-- 本月营业额 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 text-white bg-primary rounded-4">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-white-50 d-block"><?php echo $l['dash_monthly_sales']; ?></small>
                        <h3 class="fw-bold mb-0">RM <?php echo number_format($monthly_sales, 2); ?></h3>
                    </div>
                    <i class="fas fa-chart-bar fa-2x text-white-50"></i>
                </div>
            </div>
        </div>
        <!-- 低库存警戒 -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 p-3 rounded-4 bg-body <?php echo ($low_stock > 0) ? 'border border-danger' : ''; ?>">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <small class="text-muted d-block"><?php echo $l['dash_low_stock']; ?></small>
                        <h4 class="fw-bold <?php echo ($low_stock > 0) ? 'text-danger' : ''; ?> mb-0">
                            <?php echo $low_stock; ?>
                        </h4>
                    </div>
                    <i class="fas fa-exclamation-triangle <?php echo ($low_stock > 0) ? 'text-danger animate-pulse' : 'text-muted'; ?> fa-lg"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. 第三排：走势图表与动态流水 -->
    <div class="row g-4 mb-4">
        <!-- 销售趋势图 -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm p-4 h-100 rounded-4 bg-body">
                <h5 class="fw-bold mb-4"><i class="fas fa-chart-line me-2 text-primary"></i><?php echo $l['dash_sales_perf']; ?></h5>
                <div class="position-relative w-100" style="height: 300px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>
        </div>

        <!-- 最新流水记录 -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-4 h-100 rounded-4 bg-body">
                <h5 class="fw-bold mb-4"><i class="fas fa-history me-2 text-warning"></i><?php echo $l['dash_recent_activities']; ?></h5>
                <ul class="list-group list-group-flush">
                    <?php if (empty($recent_activities)): ?>
                        <li class="list-group-item bg-transparent text-center text-muted py-4"><?php echo $l['dash_no_recent']; ?></li>
                    <?php else: ?>
                        <?php foreach($recent_activities as $act): ?>
                            <?php 
                            $display_type = ($act['type'] == 'Sale') ? $l['dash_sale_label'] : $l['dash_purchase_label'];
                            ?>
                            <li class="list-group-item bg-transparent px-0 py-3 border-bottom border-light">
                                <div class="d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-<?php echo $act['badge']; ?> me-3 px-2 py-2 rounded-3" style="min-width: 75px;">
                                            <?php echo htmlspecialchars($display_type); ?>
                                        </span>
                                        <div>
                                            <span class="fw-semibold d-block text-truncate" style="max-width: 120px;"><?php echo htmlspecialchars($act['ref']); ?></span>
                                            <small class="text-muted" style="font-size: 0.75rem;"><?php echo date('Y-m-d H:i', strtotime($act['date'])); ?></small>
                                        </div>
                                    </div>
                                    <span class="fw-bold <?php echo ($act['type'] == 'Sale') ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo ($act['type'] == 'Sale') ? '+' : '-'; ?>RM <?php echo number_format($act['amount'], 2); ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- 第四排 - 畅销商品排行面板 (Top Selling Products) -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm p-4 rounded-4 bg-body">
                <h5 class="fw-bold mb-3"><i class="fas fa-crown me-2 text-warning"></i><?php echo htmlspecialchars($l['dash_top_selling_title'] ?? 'Top Selling Products Ranking'); ?></h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo htmlspecialchars($l['dash_rank'] ?? 'Rank'); ?></th>
                                <th><?php echo htmlspecialchars($l['inv_th_sku'] ?? 'Product Code'); ?></th>
                                <th><?php echo htmlspecialchars($l['inv_th_product'] ?? 'Product Name'); ?></th>
                                <th class="text-center"><?php echo htmlspecialchars($l['rep_total_units_sold'] ?? 'Units Sold'); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars($l['dash_sales_value'] ?? 'Sales Value (RM)'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_selling_products)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4"><?php echo htmlspecialchars($l['dash_no_sales_history'] ?? 'No selling history recorded yet.'); ?></td>
                                </tr>
                            <?php else: ?>
                                <?php $rank = 1; foreach ($top_selling_products as $p): ?>
                                    <tr>
                                        <td>
                                            <?php if ($rank === 1): ?>
                                                <span class="badge bg-warning text-dark px-3 rounded-pill"><i class="fas fa-medal me-1"></i><?php echo htmlspecialchars($l['dash_rank_first'] ?? '1st'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary px-3 rounded-pill"><?php echo $rank; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="fw-bold"><?php echo htmlspecialchars($p['product_code']); ?></td>
                                        <td class="fw-semibold"><?php echo htmlspecialchars($p['name']); ?></td>
                                        <td class="text-center fw-bold text-primary"><?php echo number_format($p['total_qty_sold']); ?></td>
                                        <td class="text-end fw-bold text-success">RM <?php echo number_format($p['total_sales_value'], 2); ?></td>
                                    </tr>
                                <?php $rank++; endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    if (typeof Chart === 'undefined') {
        console.error("<?php echo $l['dash_chart_error'] ?? 'Chart.js loading error.'; ?>");
        return;
    }

    const ctx = document.getElementById('salesChart').getContext('2d');
    const isDarkMode = document.documentElement.getAttribute('data-bs-theme') === 'dark' || document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDarkMode ? '#adb5bd' : '#495057';
    const gridColor = isDarkMode ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.05)';

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_months); ?>,
            datasets: [{
                label: '<?php echo $l['nav_sales']; ?> (RM)',
                data: <?php echo json_encode($chart_sales); ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointBackgroundColor: '#0d6efd',
                pointHoverRadius: 7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    grid: { color: gridColor },
                    ticks: { color: textColor, font: { size: 11 } }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: textColor, font: { size: 11 } }
                }
            }
        }
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>