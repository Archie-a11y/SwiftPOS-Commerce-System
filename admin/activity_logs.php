<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/header.php';

// 安全拦截：仅允许管理员访问
if ($_SESSION['role'] !== 'Administrator') {
    echo "<div class='alert alert-danger m-4'><i class='fas fa-ban me-2'></i>" . htmlspecialchars($l['audit_access_denied'] ?? 'Access Denied. Only Administrators can access this module.') . "</div>";
    require_once '../includes/footer.php';
    exit();
}

/**
 * 审计日志动作分类本地化转换函数
 */
if (!function_exists('translate_audit_action')) {
    function translate_audit_action($action, $l) {
        $lang = $_COOKIE['lang'] ?? 'en';
        $normalized = trim($action);

        // 针对语言字典可能缺失的特定安全/验证分类提供本地容错词条
        $local_action_extra = [
            'zh' => [
                'audit_act_login_success'            => '登录成功',
                'audit_act_login_failed'             => '登录失败',
                'audit_act_logout'                   => '安全退出',
                'audit_act_checkout'                 => '收银结账成功',
                'audit_act_reset_password'           => '重置密码成功',
                'audit_act_password_otp_request'     => '申请重置验证码',
                'audit_act_stock_adjustment'         => '手动库存修正',
            ],
            'ms' => [
                'audit_act_login_success'            => 'Log Masuk Berjaya',
                'audit_act_login_failed'             => 'Log Masuk Gagal',
                'audit_act_logout'                   => 'Log Keluar',
                'audit_act_checkout'                 => 'Pembayaran Berjaya',
                'audit_act_reset_password'           => 'Reset Kata Laluan Berjaya',
                'audit_act_password_otp_request'     => 'Permintaan OTP Kata Laluan',
                'audit_act_stock_adjustment'         => 'Pelarasan Stok Fizikal',
            ],
            'en' => [
                'audit_act_login_success'            => 'Login Success',
                'audit_act_login_failed'             => 'Login Failed',
                'audit_act_logout'                   => 'Logout',
                'audit_act_checkout'                 => 'Checkout Success',
                'audit_act_reset_password'           => 'Password Reset Success',
                'audit_act_password_otp_request'     => 'Password OTP Request',
                'audit_act_stock_adjustment'         => 'Stock Adjustment',
            ]
        ];

        // 数据库常见原始行为与其多语言键名之间的精确映射表
        $mapping = [
            'login'                       => 'audit_act_login_success',
            'login success'               => 'audit_act_login_success',
            'login failed'                => 'audit_act_login_failed',
            'logout'                      => 'audit_act_logout',
            'checkout success'            => 'audit_act_checkout',
            'checkout'                    => 'audit_act_checkout',
            'pos checkout'                => 'audit_act_checkout',
            'password reset success'      => 'audit_act_reset_password',
            'password reset'              => 'audit_act_reset_password',
            'password otp reset request'  => 'audit_act_password_otp_request',
            'password otp request'        => 'audit_act_password_otp_request',
            'add product'                 => 'audit_act_add_product',
            'edit product'                => 'audit_act_edit_product',
            'delete product'              => 'audit_act_delete_product',
            'add category'                => 'audit_act_add_category',
            'edit category'               => 'audit_act_edit_category',
            'delete category'             => 'audit_act_delete_category',
            'add supplier'                => 'audit_act_add_supplier',
            'edit supplier'               => 'audit_act_edit_supplier',
            'delete supplier'             => 'audit_act_delete_supplier',
            'stock adjustment'            => 'audit_act_stock_adjustment',
            'stock adjust'                => 'audit_act_stock_adjustment',
            'settings update'             => 'audit_act_settings_update',
            'register member'             => 'audit_act_register_member',
            'edit member'                 => 'audit_act_edit_member',
            'delete member'               => 'audit_act_delete_member',
            'stock transfer'              => 'audit_act_stock_transfer',
            'register user'               => 'audit_act_register_user',
            'edit user'                   => 'audit_act_edit_user',
            'delete user'                 => 'audit_act_delete_user'
        ];

        $lookup_key = strtolower($normalized);
        if (isset($mapping[$lookup_key])) {
            $key = $mapping[$lookup_key];
            return $l[$key] ?? ($local_action_extra[$lang][$key] ?? $normalized);
        }

        // 回退机制：动态生成键名
        $dynamic_key = 'audit_act_' . str_replace([' ', '/'], '_', $lookup_key);
        return $l[$dynamic_key] ?? ($local_action_extra[$lang][$dynamic_key] ?? $action);
    }
}

/**
 * 审计日志描述明细动态格式化翻译函数
 */
if (!function_exists('translate_audit_desc')) {
    function translate_audit_desc($desc, $l) {
        $lang = $_COOKIE['lang'] ?? 'en';

        // 针对没有预置语言键名的系统流程与验证描述提供本地翻译
        $local_desc_extra = [
            'zh' => [
                'audit_desc_otp_request'             => '系统操作员 "%s" 申请了重置密码验证码。',
                'audit_desc_otp_sent'                => '重置密码验证码已成功发送至操作员 "%s" 的邮箱。',
                'audit_desc_login_failed_attempts'   => '操作账户 "%s" 由于尝试登录失败次数过多被系统锁定。',
                'audit_desc_password_reset_success'  => '系统用户 "%s" 的登录密码已成功重设修改。',
                
                // 登录类字面量（无具体用户名时）与带用户名翻译
                'audit_desc_login_manually_success_literal' => '用户成功手动登录系统。',
                'audit_desc_login_manually_success_user'    => '用户 "%s" 手动登录成功。',
                'audit_desc_auto_login_token'               => '通过自动登录记住我凭证（Token）安全登录系统。',
                'audit_desc_auto_login_token_user'          => '用户 "%s" 通过自动登录记住我凭证（Token）安全登录。',
                'audit_desc_login_success_literal'          => '用户成功登录系统。',
                'audit_desc_login_failed_literal'           => '用户尝试登录系统失败。',
                'audit_desc_logout_literal'                 => '用户安全登出系统。',

                // 进销存与其他安全行为明细词条
                'audit_desc_stock_transfer_detailed' => '成功划拨商品 "%s"（数量: %s），从 %s 移至 %s。',
                'audit_desc_cashier_processed_invoice'=> '收银员已处理销售账单 %s（金额: RM %s）。',
                'audit_desc_registered_new_product'  => '成功注册新商品: %s [商品编码/SKU: %s]。',
                'audit_desc_password_reset_secure_otp'=> '已通过 6 位验证码安全重置登录密码。',
                'audit_desc_generated_otp_code'      => '已为 "%s" 生成 6 位重置验证码。'
            ],
            'ms' => [
                'audit_desc_otp_request'             => 'Permintaan OTP Set Semula Kata Laluan untuk operator "%s".',
                'audit_desc_otp_sent'                => 'Kod OTP Set Semula Kata Laluan telah dihantar ke emel operator "%s".',
                'audit_desc_login_failed_attempts'   => 'Akaun operator "%s" telah disekat sementara kerana terlalu banyak cubaan log masuk gagal.',
                'audit_desc_password_reset_success'  => 'Kata laluan untuk operator "%s" telah berjaya diset semula.',
                
                // Variasi log masuk bahasa Melayu
                'audit_desc_login_manually_success_literal' => 'Pengguna berjaya log masuk secara manual.',
                'audit_desc_login_manually_success_user'    => 'Pengguna "%s" log masuk secara manual berjaya.',
                'audit_desc_auto_login_token'               => 'Log masuk secara selamat melalui token ingat saya auto-log masuk.',
                'audit_desc_auto_login_token_user'          => 'Pengguna "%s" log masuk melalui token ingat saya auto-log masuk.',
                'audit_desc_login_success_literal'          => 'Pengguna berjaya log masuk.',
                'audit_desc_login_failed_literal'           => 'Pengguna gagal log masuk.',
                'audit_desc_logout_literal'                 => 'Pengguna log keluar.',

                // Log operasi lain
                'audit_desc_stock_transfer_detailed' => 'Berjaya memindahkan produk "%s" (Kuantiti: %s) dari %s ke %s.',
                'audit_desc_cashier_processed_invoice'=> 'Juruwang telah memproses Invois %s (RM %s).',
                'audit_desc_registered_new_product'  => 'Berjaya mendaftar produk baru: %s [SKU: %s].',
                'audit_desc_password_reset_secure_otp'=> 'Kata laluan berjaya diset semula dengan selamat menggunakan kod 6-digit.',
                'audit_desc_generated_otp_code'      => 'Menjana kod verifikasi 6-digit set semula untuk: %s.'
            ],
            'en' => [
                'audit_desc_otp_request'             => 'Password OTP reset request initiated for operator "%s".',
                'audit_desc_otp_sent'                => 'Password OTP reset code successfully dispatched to operator "%s".',
                'audit_desc_login_failed_attempts'   => 'Operator account "%s" has been temporarily locked due to excessive failed attempts.',
                'audit_desc_password_reset_success'  => 'Password updated successfully for operator account "%s".',
                
                // Login variations
                'audit_desc_login_manually_success_literal' => 'User logged in manually successfully.',
                'audit_desc_login_manually_success_user'    => 'User "%s" logged in manually successfully.',
                'audit_desc_auto_login_token'               => 'Logged in securely via auto-login remember token.',
                'audit_desc_auto_login_token_user'          => 'User "%s" logged in securely via auto-login remember token.',
                'audit_desc_login_success_literal'          => 'User logged in successfully.',
                'audit_desc_login_failed_literal'           => 'User failed to log in.',
                'audit_desc_logout_literal'                 => 'User logged out.',

                // Other operations
                'audit_desc_stock_transfer_detailed' => 'Transferred "%s" (Qty: %s) from %s to %s.',
                'audit_desc_cashier_processed_invoice'=> 'Cashier processed Invoice %s (RM %s).',
                'audit_desc_registered_new_product'  => 'Registered new product: %s [SKU: %s].',
                'audit_desc_password_reset_secure_otp'=> 'Password reset securely using 6-digit code.',
                'audit_desc_generated_otp_code'      => 'Generated 6-digit verification reset code for: %s.'
            ]
        ];

        // 增强版正则模式列表（带具体用户的规则必须在字面量规则之前匹配，并采用 .++ 强制捕获）
        $patterns = [
            // A. 手动登录
            '/^User\s+logged\s+in\s+manually\s+successfully\.?$/i'                       => 'audit_desc_login_manually_success_literal',
            '/^User\s+(.+?)\s+logged\s+in\s+manually\s+successfully\.?$/i'               => 'audit_desc_login_manually_success_user',
            
            // B. 自动登录记住我凭证（Remember Me Token）
            '/^Logged\s+in\s+via\s+auto-login\s+remember\s+token\.?$/i'                 => 'audit_desc_auto_login_token',
            '/^User\s+(.+?)\s+logged\s+in\s+via\s+auto-login\s+remember\s+token\.?$/i'   => 'audit_desc_auto_login_token_user',

            // C. 基础登录与登出
            '/^User\s+logged\s+in\s+successfully\.?$/i'                                 => 'audit_desc_login_success_literal',
            '/^User\s+(.+?)\s+logged\s+in\s+successfully\.?$/i'                         => 'audit_desc_login_success',
            '/^User\s+failed\s+to\s+log\s+in\.?$/i'                                      => 'audit_desc_login_failed_literal',
            '/^User\s+(.+?)\s+failed\s+to\s+log\s+in\.?$/i'                              => 'audit_desc_login_failed',
            '/^User\s+logged\s+out\.?$/i'                                               => 'audit_desc_logout_literal',
            '/^User\s+(.+?)\s+logged\s+out\.?$/i'                                       => 'audit_desc_logout',

            // D. 精确仓储货架划拨（带括弧指示词）
            '/^(?:Transferred\s+)?(.*?)\s+\(Qty:\s*(\d+)\)\s+from\s+(\[.*?\]|.*?)\s+to\s+(\[.*?\]|.*?)\.?$/i' => 'audit_desc_stock_transfer_detailed',
            
            // E. 销售账单处理
            '/^Cashier\s+processed\s+Invoice\s+(.*?)\s+\((?:RM\s*)?(.*?)\)\.?$/i'       => 'audit_desc_cashier_processed_invoice',
            
            // F. 注册新商品
            '/^Registered\s+new\s+product:\s*(.*?)\s+\[SKU:\s*(.*?)\]\.?$/i'             => 'audit_desc_registered_new_product',
            
            // G. 密码安全重置与验证码
            '/^Password\s+reset\s+securely\s+using\s+6-digit\s+code\.?$/i'               => 'audit_desc_password_reset_secure_otp',
            '/^Generated\s+6-digit\s+verification\s+reset\s+code\s+for:\s*(.*?)\.?$/i'   => 'audit_desc_generated_otp_code',

            // H. 其他系统基础行为
            '/^Product\s+(.*?)\s+added\s+successfully\.?$/i'                             => 'audit_desc_add_product',
            '/^Product\s+(.*?)\s+updated\s+successfully\.?$/i'                           => 'audit_desc_edit_product',
            '/^Product\s+(.*?)\s+deleted\s+successfully\.?$/i'                           => 'audit_desc_delete_product',
            '/^Category\s+(.*?)\s+created\s+successfully\.?$/i'                          => 'audit_desc_add_category',
            '/^Category\s+(.*?)\s+updated\s+successfully\.?$/i'                          => 'audit_desc_edit_category',
            '/^Category\s+(.*?)\s+deleted\s+successfully\.?$/i'                          => 'audit_desc_delete_category',
            '/^Supplier\s+(.*?)\s+registered\s+successfully\.?$/i'                       => 'audit_desc_add_supplier',
            '/^Supplier\s+(.*?)\s+updated\s+successfully\.?$/i'                         => 'audit_desc_edit_supplier',
            '/^Supplier\s+(.*?)\s+deleted\s+successfully\.?$/i'                         => 'audit_desc_delete_supplier',
            '/^Stock\s+adjusted\s+for\s+(.*?)\.?\s+Qty\s+Change:\s*(.*?),\s*Reason:\s*(.*?)$/i' => 'audit_desc_stock_adjust',
            '/^Transaction\s+completed\.?\s+Invoice\s+No:\s*(.*?),\s*Grand\s+Total:\s*(?:RM\s*)?(.*?)$/i' => 'audit_desc_checkout',
            '/^Store\s+Settings\s+updated\s+successfully\.?$/i'                         => 'audit_desc_settings_update',
            '/^Customer\s+member\s+(.*?)\s+registered\s+successfully\.?$/i'               => 'audit_desc_add_member',
            '/^Customer\s+profile\s+(.*?)\s+updated\s+successfully\.?$/i'              => 'audit_desc_edit_member',
            '/^Customer\s+record\s+(.*?)\s+deleted\s+successfully\.?$/i'               => 'audit_desc_delete_member',
            '/^Stock\s+transfer\s+committed:\s*(.*?)\.?\s*Qty:\s*(.*?),\s*From:\s*(.*?),\s*To:\s*(.*?)$/i' => 'audit_desc_stock_transfer',
            '/^Password\s+reset\s+for\s+user\s+(.*?)\.?$/i'                             => 'audit_desc_reset_password',
            '/^Password\s+Reset\s+Success\s+for\s+user\s+(.*?)\.?$/i'                   => 'audit_desc_password_reset_success',
            '/^User\s+(.*?)\s+registered\s+successfully\.?$/i'                           => 'audit_desc_add_user',
            '/^User\s+(.*?)\s+updated\s+successfully\.?$/i'                             => 'audit_desc_edit_user',
            '/^User\s+account\s+(.*?)\s+deleted\s+successfully\.?$/i'                     => 'audit_desc_delete_user',
            '/^Password\s+OTP\s+Reset\s+Request\s+for\s+user\s+(.*?)\.?$/i'             => 'audit_desc_otp_request',
            '/^OTP\s+sent\s+to\s+user\s+(.*?)\.?$/i'                                     => 'audit_desc_otp_sent',
            '/^User\s+(.*?)\s+temporarily\s+locked\s+due\s+to\s+too\s+many\s+failed\s+login\s+attempts\.?$/i' => 'audit_desc_login_failed_attempts',
        ];

        foreach ($patterns as $pattern => $key) {
            if (preg_match($pattern, trim($desc), $matches)) {
                // 移出匹配结果的首个元素，保留捕获的分组变量
                array_shift($matches);

                // 获取翻译模板（优先全局字典，未命中时调用脚本自带本地容错字典）
                $tpl = $l[$key] ?? ($local_desc_extra[$lang][$key] ?? null);

                if ($tpl !== null) {
                    // 支持剥离外层中括号进行语义翻译，翻译完毕后自适应还原中括号
                    foreach ($matches as $k => $match) {
                        $match_clean = trim($match, " \t\n\r\0\x0B[]");
                        $match_lower = strtolower($match_clean);
                        
                        if ($match_lower === 'back warehouse' || $match_lower === 'warehouse') {
                            $translated_loc = $l['inv_loc_warehouse'] ?? 'Back Warehouse';
                            $matches[$k] = (strpos($match, '[') !== false) ? '[' . $translated_loc . ']' : $translated_loc;
                        } elseif ($match_lower === 'front shelf' || $match_lower === 'shelf') {
                            $translated_loc = $l['inv_loc_shelf'] ?? 'Front Shelf';
                            $matches[$k] = (strpos($match, '[') !== false) ? '[' . $translated_loc . ']' : $translated_loc;
                        } elseif ($match_lower === 'sub-branch' || $match_lower === 'branch') {
                            $translated_loc = $l['inv_loc_branch'] ?? 'Sub-Branch';
                            $matches[$k] = (strpos($match, '[') !== false) ? '[' . $translated_loc . ']' : $translated_loc;
                        }
                    }

                    // 计算翻译模版中实际需要的参数个数，动态截取，防止参数溢出报错
                    $placeholder_count = substr_count($tpl, '%s') + substr_count($tpl, '%d');
                    $sliced_matches = array_slice($matches, 0, $placeholder_count);

                    return vsprintf($tpl, $sliced_matches);
                }
            }
        }
        return $desc; // 回退原样返回，防止意外漏译导致空白显示
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$action_filter = isset($_GET['action_filter']) ? trim($_GET['action_filter']) : '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 15;
$offset = ($page - 1) * $limit;

$where_clauses = ["1=1"];
$params = [];
$types = "";

// 模糊搜索：支持描述、操作员姓名、IP地址
if (!empty($search)) {
    $where_clauses[] = "(al.description LIKE ? OR u.full_name LIKE ? OR al.ip_address LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

// 操作类型精确过滤
if (!empty($action_filter)) {
    $where_clauses[] = "al.action_type = ?";
    $params[] = $action_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// 1. 统计符合条件的总记录数
$count_query = "SELECT COUNT(*) FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE $where_sql";
$stmt_cnt = $conn->prepare($count_query);
if (!empty($params)) {
    $stmt_cnt->bind_param($types, ...$params);
}
$stmt_cnt->execute();
$total_rows = $stmt_cnt->get_result()->fetch_row()[0] ?? 0;
$stmt_cnt->close();

$total_pages = ceil($total_rows / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// 2. 查询分页后的日志列表
$list_query = "SELECT al.*, u.full_name, u.username, u.role FROM activity_logs al 
               LEFT JOIN users u ON al.user_id = u.id 
               WHERE $where_sql 
               ORDER BY al.created_at DESC 
               LIMIT ? OFFSET ?";

$list_params = $params;
$list_params[] = $limit;
$list_params[] = $offset;
$list_types = $types . "ii";

$stmt_list = $conn->prepare($list_query);
$stmt_list->bind_param($list_types, ...$list_params);
$stmt_list->execute();
$logs = $stmt_list->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_list->close();

// 3. 获取系统中已记录的独特操作类型（用于筛选下拉框）
$types_res = $conn->query("SELECT DISTINCT action_type FROM activity_logs ORDER BY action_type ASC");
$unique_types = [];
while ($t_row = $types_res->fetch_assoc()) {
    if (!empty($t_row['action_type'])) {
        $unique_types[] = $t_row['action_type'];
    }
}
?>

<div class="container-fluid px-0">
    <!-- 标题区域 -->
    <div class="mb-4">
        <h2 class="fw-bold mb-1 text-primary">
            <i class="fas fa-user-shield me-2"></i><?php echo htmlspecialchars($l['audit_title'] ?? 'System Audit Trail'); ?>
        </h2>
        <p class="text-muted small mb-0">
            <?php echo htmlspecialchars($l['audit_subtitle'] ?? 'Monitor and trace employee actions, transaction checkout, inventory modifications, and security events.'); ?>
        </p>
    </div>

    <!-- 过滤器卡片 -->
    <div class="card border-0 shadow-sm p-3 mb-4 rounded-4">
        <form method="GET" class="row g-2 align-items-stretch">
            <!-- 模糊搜索框 -->
            <div class="col-12 col-md-6 col-lg-5 d-flex">
                <div class="input-group w-100">
                    <span class="input-group-text border-end-0 bg-transparent text-muted rounded-start-3">
                        <i class="fas fa-search"></i>
                    </span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0 py-2 rounded-end-3" 
                           placeholder="<?php echo htmlspecialchars($l['audit_search_placeholder'] ?? 'Search by details, operator name, or IP address...'); ?>" 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            
            <!-- 操作类型筛选 -->
            <div class="col-12 col-md-3 col-lg-3 d-flex">
                <select name="action_filter" class="form-select py-2 rounded-3">
                    <option value=""><?php echo htmlspecialchars($l['audit_all_actions'] ?? 'All Action Types'); ?></option>
                    <?php foreach ($unique_types as $utype): ?>
                        <option value="<?php echo htmlspecialchars($utype); ?>" <?php echo $action_filter === $utype ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(translate_audit_action($utype, $l)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- 操作按钮 -->
            <div class="col-12 col-md-3 col-lg-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1 py-2 rounded-3 d-flex align-items-center justify-content-center">
                    <i class="fas fa-filter me-2"></i><?php echo htmlspecialchars($l['audit_btn_filter'] ?? 'Filter Logs'); ?>
                </button>
                <a href="activity_logs.php" class="btn btn-outline-secondary py-2 rounded-3 d-flex align-items-center justify-content-center" 
                   title="<?php echo htmlspecialchars($l['audit_btn_reset'] ?? 'Reset Filters'); ?>" style="width: 42px;">
                    <i class="fas fa-arrows-rotate"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- 日志明细看板 -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 15%;"><?php echo htmlspecialchars($l['audit_th_timestamp'] ?? 'Timestamp'); ?></th>
                        <th style="width: 15%;"><?php echo htmlspecialchars($l['audit_th_user_account'] ?? 'User Account'); ?></th>
                        <th style="width: 15%;"><?php echo htmlspecialchars($l['audit_th_action_type'] ?? 'Action Type'); ?></th>
                        <th style="width: 40%;"><?php echo htmlspecialchars($l['audit_th_description'] ?? 'Description'); ?></th>
                        <th style="width: 15%;" class="text-end"><?php echo htmlspecialchars($l['audit_th_ip_address'] ?? 'IP Address'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fas fa-receipt fa-2x d-block mb-2"></i><?php echo htmlspecialchars($l['audit_no_records'] ?? 'No matching audit logs found.'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): 
                            // 为不同类型的操作分配颜色
                            $badge_color = 'secondary';
                            $action_lower = strtolower($log['action_type']);
                            if (strpos($action_lower, 'login') !== false || strpos($action_lower, 'otp') !== false) {
                                $badge_color = 'info';
                            } elseif (strpos($action_lower, 'checkout') !== false || strpos($action_lower, 'success') !== false) {
                                $badge_color = 'success';
                            } elseif (strpos($action_lower, 'delete') !== false || strpos($action_lower, 'lockout') !== false) {
                                $badge_color = 'danger';
                            } elseif (strpos($action_lower, 'adjust') !== false || strpos($action_lower, 'transfer') !== false) {
                                $badge_color = 'warning text-dark';
                            } elseif (strpos($action_lower, 'edit') !== false || strpos($action_lower, 'update') !== false) {
                                $badge_color = 'primary';
                            }
                        ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold text-dark-emphasis"><?php echo date('Y-m-d', strtotime($log['created_at'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?php echo htmlspecialchars($log['full_name'] ?: ($l['audit_lbl_system'] ?? 'System')); ?></div>
                                    <small class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill" style="font-size: 0.7rem;">
                                        <?php 
                                        $role_key = 'role_' . strtolower(str_replace(['/', ' '], '_', $log['role']));
                                        echo htmlspecialchars($l[$role_key] ?? ($log['role'] ?: ($l['audit_lbl_system_process'] ?? 'System Process'))); 
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $badge_color; ?> rounded px-2.5 py-1.5 small fw-bold">
                                        <?php echo htmlspecialchars(translate_audit_action($log['action_type'], $l)); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="text-secondary small fw-medium" style="word-break: break-all; max-width: 480px;">
                                        <?php echo htmlspecialchars(translate_audit_desc($log['description'], $l)); ?>
                                    </div>
                                </td>
                                <td class="text-end font-monospace small text-muted">
                                    <?php echo htmlspecialchars($log['ip_address'] ?: '127.0.0.1'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 响应式分页组件 -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center p-4">
                <span class="small text-muted">
                    <?php echo htmlspecialchars($l['prod_showing'] ?? 'Showing'); ?> 
                    <strong><?php echo $offset + 1; ?></strong> 
                    <?php echo htmlspecialchars($l['prod_to'] ?? 'to'); ?> 
                    <strong><?php echo min($offset + $limit, $total_rows); ?></strong> 
                    <?php echo htmlspecialchars($l['prod_of'] ?? 'of'); ?> 
                    <strong><?php echo $total_rows; ?></strong> 
                    <?php echo htmlspecialchars($l['audit_records_suffix'] ?? 'audit log entries'); ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>">
                                <?php echo htmlspecialchars($l['prod_prev'] ?? 'Previous'); ?>
                            </a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&action_filter=<?php echo urlencode($action_filter); ?>">
                                <?php echo htmlspecialchars($l['prod_next'] ?? 'Next'); ?>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once '../includes/footer.php';
?>