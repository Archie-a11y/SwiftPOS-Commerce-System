<?php
// admin/users.php
ob_start(); // 开启输出缓冲区，避免在载入 header.php 的 HTML 输出后导致 header() 重定向失败
require_once '../includes/header.php';
require_once '../includes/alerts.php';

// 安全过滤：仅允许系统管理员角色访问该页面
if ($role !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

// ---------------------------------------------------------
// 核心逻辑: 预处理语句处理 POST 提交表单 (PRG 模式防表单重复提交)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. 新增操作人员
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user_role = $_POST['role'] ?? 'Cashier';
        $status = $_POST['status'] ?? 'Active';

        if (empty($username) || empty($full_name) || empty($email) || empty($password)) {
            $_SESSION['error_msg'] = $l['users_err_invalid'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error_msg'] = $l['users_err_invalid_email'];
        } elseif (strlen($password) < 6) {
            $_SESSION['error_msg'] = $l['users_err_password_short'];
        } else {
            // 检查用户名唯一性
            $chk_user = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
            mysqli_stmt_bind_param($chk_user, "s", $username);
            mysqli_stmt_execute($chk_user);
            mysqli_stmt_store_result($chk_user);
            $user_exists = mysqli_stmt_num_rows($chk_user) > 0;
            mysqli_stmt_close($chk_user);

            if ($user_exists) {
                $_SESSION['error_msg'] = sprintf($l['users_err_username_exists'], $username);
            } else {
                // 检查邮箱唯一性
                $chk_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
                mysqli_stmt_bind_param($chk_stmt, "s", $email);
                mysqli_stmt_execute($chk_stmt);
                mysqli_stmt_store_result($chk_stmt);
                
                if (mysqli_stmt_num_rows($chk_stmt) > 0) {
                    $_SESSION['error_msg'] = sprintf($l['users_err_email_exists'], $email);
                } else {
                    $hashed_pwd = password_hash($password, PASSWORD_DEFAULT);
                    $ins_stmt = mysqli_prepare($conn, "INSERT INTO users (username, full_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ins_stmt, "ssssss", $username, $full_name, $email, $hashed_pwd, $user_role, $status);
                    
                    if (mysqli_stmt_execute($ins_stmt)) {
                        $_SESSION['success_msg'] = $l['users_success_add'];
                    } else {
                        $_SESSION['error_msg'] = $l['err_generic'];
                    }
                    mysqli_stmt_close($ins_stmt);
                }
                mysqli_stmt_close($chk_stmt);
            }
        }
    }

    // 2. 修改操作员信息
    elseif ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $user_role = $_POST['role'] ?? 'Cashier';
        $status = $_POST['status'] ?? 'Active';

        if (empty($username) || empty($full_name) || empty($email)) {
            $_SESSION['error_msg'] = $l['users_err_invalid'];
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error_msg'] = $l['users_err_invalid_email'];
        } else {
            // 安全限制：防止管理员把自己设为停用状态 (Inactive)
            if ($id === (int)$_SESSION['user_id'] && $status === 'Inactive') {
                $_SESSION['error_msg'] = $l['users_err_deactivate_self'];
            } else {
                // 检查修改后的用户名是否被其他用户占用
                $chk_user = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ? AND id != ?");
                mysqli_stmt_bind_param($chk_user, "si", $username, $id);
                mysqli_stmt_execute($chk_user);
                mysqli_stmt_store_result($chk_user);
                $username_taken = mysqli_stmt_num_rows($chk_user) > 0;
                mysqli_stmt_close($chk_user);

                if ($username_taken) {
                    $_SESSION['error_msg'] = sprintf($l['users_err_username_exists'], $username);
                } else {
                    // 检查修改后的邮箱是否被其他用户占用
                    $chk_stmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? AND id != ?");
                    mysqli_stmt_bind_param($chk_stmt, "si", $email, $id);
                    mysqli_stmt_execute($chk_stmt);
                    mysqli_stmt_store_result($chk_stmt);
                    
                    if (mysqli_stmt_num_rows($chk_stmt) > 0) {
                        $_SESSION['error_msg'] = sprintf($l['users_err_email_exists'], $email);
                    } else {
                        $upd_stmt = mysqli_prepare($conn, "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, status = ? WHERE id = ?");
                        mysqli_stmt_bind_param($upd_stmt, "sssssi", $username, $full_name, $email, $user_role, $status, $id);
                        
                        if (mysqli_stmt_execute($upd_stmt)) {
                            // 若更新的是当前登录账户，同步更新 Session
                            if ($id === (int)$_SESSION['user_id']) {
                                $_SESSION['user_name'] = $full_name;
                            }
                            $_SESSION['success_msg'] = $l['users_success_update'];
                        } else {
                            $_SESSION['error_msg'] = $l['err_generic'];
                        }
                        mysqli_stmt_close($upd_stmt);
                    }
                    mysqli_stmt_close($chk_stmt);
                }
            }
        }
    }

    // 3. 一键重置用户密码
    elseif ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if (empty($new_password) || strlen($new_password) < 6) {
            $_SESSION['error_msg'] = $l['users_err_password_short'];
        } else {
            $hashed_pwd = password_hash($new_password, PASSWORD_DEFAULT);
            $rst_stmt = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?");
            mysqli_stmt_bind_param($rst_stmt, "si", $hashed_pwd, $id);
            
            if (mysqli_stmt_execute($rst_stmt)) {
                $_SESSION['success_msg'] = $l['users_success_reset'];
            } else {
                $_SESSION['error_msg'] = $l['err_generic'];
            }
            mysqli_stmt_close($rst_stmt);
        }
    }

    // 4. 快速切换状态
    elseif ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['error_msg'] = $l['users_err_deactivate_self'];
        } else {
            $sel_stmt = mysqli_prepare($conn, "SELECT status FROM users WHERE id = ?");
            mysqli_stmt_bind_param($sel_stmt, "i", $id);
            mysqli_stmt_execute($sel_stmt);
            mysqli_stmt_bind_result($sel_stmt, $current_status);
            mysqli_stmt_fetch($sel_stmt);
            mysqli_stmt_close($sel_stmt);

            $new_status = ($current_status === 'Active') ? 'Inactive' : 'Active';

            $upd_stmt = mysqli_prepare($conn, "UPDATE users SET status = ? WHERE id = ?");
            mysqli_stmt_bind_param($upd_stmt, "si", $new_status, $id);
            if (mysqli_stmt_execute($upd_stmt)) {
                $_SESSION['success_msg'] = $l['users_success_toggle'];
            } else {
                $_SESSION['error_msg'] = $l['err_generic'];
            }
            mysqli_stmt_close($upd_stmt);
        }
    }

    // 5. 物理删除系统账号 (需要确保没有外键冲突)
    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['error_msg'] = $l['users_err_delete_self'];
        } else {
            $can_delete = true;

            // 查询该用户在销售表（POS收银员）中是否已有业务关联
            $chk_sales = mysqli_prepare($conn, "SELECT id FROM sales WHERE created_by = ? LIMIT 1");
            mysqli_stmt_bind_param($chk_sales, "i", $id);
            mysqli_stmt_execute($chk_sales);
            mysqli_stmt_store_result($chk_sales);
            if (mysqli_stmt_num_rows($chk_sales) > 0) {
                $can_delete = false;
            }
            mysqli_stmt_close($chk_sales);

            // 查询该用户在采购单中是否已有业务关联
            if ($can_delete) {
                $chk_pur = mysqli_prepare($conn, "SELECT id FROM purchases WHERE created_by = ? LIMIT 1");
                mysqli_stmt_bind_param($chk_pur, "i", $id);
                mysqli_stmt_execute($chk_pur);
                mysqli_stmt_store_result($chk_pur);
                if (mysqli_stmt_num_rows($chk_pur) > 0) {
                    $can_delete = false;
                }
                mysqli_stmt_close($chk_pur);
            }

            if (!$can_delete) {
                $_SESSION['error_msg'] = $l['users_err_delete_ref'];
            } else {
                $del_stmt = mysqli_prepare($conn, "DELETE FROM users WHERE id = ?");
                mysqli_stmt_bind_param($del_stmt, "i", $id);
                if (mysqli_stmt_execute($del_stmt)) {
                    $_SESSION['success_msg'] = $l['users_success_delete'];
                } else {
                    $_SESSION['error_msg'] = $l['err_generic'];
                }
                mysqli_stmt_close($del_stmt);
            }
        }
    }

    // 重定向至本页刷新，防止在刷新页面时重复执行 POST
    header("Location: users.php");
    exit();
}

// ---------------------------------------------------------
// 筛选 & 搜索 & 分页查询
// ---------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// 构建带搜索筛选的 SQL Count
if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $count_query = "SELECT COUNT(*) FROM users WHERE full_name LIKE ? OR username LIKE ? OR email LIKE ?";
    $stmt_count = mysqli_prepare($conn, $count_query);
    mysqli_stmt_bind_param($stmt_count, "sss", $search_param, $search_param, $search_param);
    mysqli_stmt_execute($stmt_count);
    mysqli_stmt_bind_result($stmt_count, $total_records);
    mysqli_stmt_fetch($stmt_count);
    mysqli_stmt_close($stmt_count);
} else {
    $count_query = "SELECT COUNT(*) FROM users";
    $result_count = mysqli_query($conn, $count_query);
    $row_count = mysqli_fetch_row($result_count);
    $total_records = $row_count[0];
}

$total_pages = ceil($total_records / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

// 构建数据分页查询
if (!empty($search)) {
    $data_query = "SELECT id, username, full_name, email, role, status, created_at FROM users WHERE full_name LIKE ? OR username LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt_data = mysqli_prepare($conn, $data_query);
    mysqli_stmt_bind_param($stmt_data, "sssii", $search_param, $search_param, $search_param, $limit, $offset);
    mysqli_stmt_execute($stmt_data);
    $result_users = mysqli_stmt_get_result($stmt_data);
} else {
    $data_query = "SELECT id, username, full_name, email, role, status, created_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?";
    $stmt_data = mysqli_prepare($conn, $data_query);
    mysqli_stmt_bind_param($stmt_data, "ii", $limit, $offset);
    mysqli_stmt_execute($stmt_data);
    $result_users = mysqli_stmt_get_result($stmt_data);
}
?>

<!-- 页面次级导航和副标题 -->
<div class="row mb-4">
    <div class="col-md-8">
        <p class="text-muted mb-0"><?php echo htmlspecialchars($l['users_subtitle']); ?></p>
    </div>
    <div class="col-md-4 text-md-end mt-2 mt-md-0">
        <!-- 统一为 rounded-3 与操作栏保持一致 -->
        <button class="btn btn-primary rounded-3 px-4 py-2.5 fw-bold" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-user-plus me-2"></i><?php echo htmlspecialchars($l['users_add_btn']); ?>
        </button>
    </div>
</div>

<!-- 提示框模块 -->
<?php render_shared_alerts($l); ?>

<!-- 搜索栏 (高度对齐修复版本) -->
<div class="card border-0 shadow-sm mb-4 rounded-4">
    <div class="card-body p-3">
        <form method="GET" action="users.php" class="row g-2 align-items-stretch">
            <div class="col-sm-9 col-md-10 d-flex">
                <div class="input-group w-100">
                    <span class="input-group-text border-0 bg-light rounded-start-3 py-2.5"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-0 bg-light rounded-end-3 py-2.5" placeholder="<?php echo htmlspecialchars($l['users_search_placeholder']); ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-sm-3 col-md-2 d-flex">
                <button type="submit" class="btn btn-primary w-100 m-0 rounded-3 py-2.5 fw-bold d-flex align-items-center justify-content-center">
                    <i class="fas fa-filter me-2"></i><?php echo htmlspecialchars($l['prod_filter_btn_filter']); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 数据展示层：1. 桌面级高保真表格 (Desktop Table View) -->
<div class="d-none d-lg-block card border-0 shadow-sm rounded-4 overflow-hidden">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th class="ps-4">ID</th>
                <th><?php echo htmlspecialchars($l['users_tbl_username']); ?></th>
                <th><?php echo htmlspecialchars($l['users_tbl_name']); ?></th>
                <th><?php echo htmlspecialchars($l['users_tbl_email']); ?></th>
                <th><?php echo htmlspecialchars($l['users_tbl_role']); ?></th>
                <th><?php echo htmlspecialchars($l['users_tbl_status']); ?></th>
                <th class="pe-4 text-center"><?php echo htmlspecialchars($l['users_tbl_actions']); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (mysqli_num_rows($result_users) > 0): ?>
                <?php while ($user = mysqli_fetch_assoc($result_users)): ?>
                    <tr>
                        <td class="ps-4 text-muted">#<?php echo $user['id']; ?></td>
                        <td><span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle px-3 py-1.5 rounded-3"><?php echo htmlspecialchars($user['username']); ?></span></td>
                        <td>
                            <div class="fw-bold text-main"><?php echo htmlspecialchars($user['full_name']); ?></div>
                        </td>
                        <td><small><?php echo htmlspecialchars($user['email']); ?></small></td>
                        <td>
                            <span class="badge rounded-pill <?php echo $user['role'] === 'Administrator' ? 'bg-danger bg-opacity-10 text-danger' : 'bg-primary bg-opacity-10 text-primary'; ?>">
                                <?php 
                                    // 动态读取语言包里定义的系统角色别名
                                    $role_key = 'role_' . strtolower(str_replace(['/', ' '], '_', $user['role']));
                                    echo htmlspecialchars($l[$role_key] ?? $user['role']); 
                                ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" action="users.php" class="d-inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn border-0 p-0" <?php echo $user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                    <?php if ($user['status'] === 'Active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-1.5"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($l['prod_active']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-3 py-1.5"><i class="fas fa-ban me-1"></i><?php echo htmlspecialchars($l['prod_inactive']); ?></span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </td>
                        <td class="pe-4 text-center">
                            <div class="btn-group">
                                <!-- 统一为 rounded-3 -->
                                <button class="btn btn-sm btn-outline-primary rounded-start-3 px-3 py-1.5" onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo addslashes($user['full_name']); ?>', '<?php echo addslashes($user['email']); ?>', '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>')">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-warning px-3 py-1.5" onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')">
                                    <i class="fas fa-key"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger rounded-end-3 px-3 py-1.5" onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')" <?php echo $user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center py-5 text-muted">
                        <i class="fas fa-user-slash fa-3x mb-3 text-opacity-25 text-secondary"></i>
                        <p class="mb-0"><?php echo htmlspecialchars($l['sup_no_records']); ?></p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 数据展示层：2. 移动端自适应网格卡片 (Mobile Responsive Grid Cards) -->
<div class="d-block d-lg-none">
    <div class="row g-3">
        <?php 
        mysqli_data_seek($result_users, 0); // 重置指针以便二次循环渲染
        if (mysqli_num_rows($result_users) > 0): 
            while ($user = mysqli_fetch_assoc($result_users)):
        ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm p-3 rounded-4">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <span class="text-muted text-xs">#<?php echo $user['id']; ?></span>
                            <div class="mt-1"><span class="badge bg-secondary-subtle text-secondary small px-2 py-1.5 rounded-3"><?php echo htmlspecialchars($user['username']); ?></span></div>
                            <h6 class="fw-bold mb-0 mt-2"><?php echo htmlspecialchars($user['full_name']); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                        </div>
                        <span class="badge rounded-pill <?php echo $user['role'] === 'Administrator' ? 'bg-danger bg-opacity-10 text-danger' : 'bg-primary bg-opacity-10 text-primary'; ?>">
                            <?php 
                                $role_key = 'role_' . strtolower(str_replace(['/', ' '], '_', $role));
                                echo htmlspecialchars($l[$role_key] ?? $user['role']); 
                            ?>
                        </span>
                    </div>
                    <hr class="my-2 border-light opacity-50">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <form method="POST" action="users.php" class="d-inline">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                <button type="submit" class="btn border-0 p-0" <?php echo $user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                    <?php if ($user['status'] === 'Active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-2.5 py-1.5"><i class="fas fa-check-circle me-1"></i><?php echo htmlspecialchars($l['prod_active']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2.5 py-1.5"><i class="fas fa-ban me-1"></i><?php echo htmlspecialchars($l['prod_inactive']); ?></span>
                                    <?php endif; ?>
                                </button>
                            </form>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-primary rounded-start-3 px-3 py-1.5" onclick="openEditModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['username']); ?>', '<?php echo addslashes($user['full_name']); ?>', '<?php echo addslashes($user['email']); ?>', '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-warning px-3 py-1.5" onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')">
                                <i class="fas fa-key"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger rounded-end-3 px-3 py-1.5" onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo addslashes($user['full_name']); ?>')" <?php echo $user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php 
            endwhile;
        else:
        ?>
            <div class="col-12 text-center py-5 text-muted bg-white rounded-3 shadow-sm">
                <i class="fas fa-user-slash fa-2x mb-2 text-opacity-25"></i>
                <p class="mb-0"><?php echo htmlspecialchars($l['sup_no_records']); ?></p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 分页层 (Pagination Area) -->
<?php if ($total_pages > 1): ?>
    <nav class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>"><?php echo htmlspecialchars($l['prod_prev']); ?></a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $page == $i ? $page : $i; ?>&search=<?php echo urlencode($search); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>"><?php echo htmlspecialchars($l['prod_next']); ?></a>
            </li>
        </ul>
    </nav>
<?php endif; ?>


<!-- ---------------------------------------------------------
   模态框集群区域 (Modals Suite)
--------------------------------------------------------- -->

<!-- 1. 新增用户模态框 -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="users.php" class="modal-content rounded-4 border-0">
            <input type="hidden" name="action" value="add">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-plus text-primary me-2"></i><?php echo htmlspecialchars($l['users_modal_add']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_username']); ?></label>
                    <input type="text" name="username" class="form-control rounded-3 py-2" placeholder="<?php echo htmlspecialchars($l['username']); ?>" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_fullname']); ?></label>
                    <input type="text" name="full_name" class="form-control rounded-3 py-2" placeholder="<?php echo htmlspecialchars($l['name']); ?>" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_email']); ?></label>
                    <input type="email" name="email" class="form-control rounded-3 py-2" placeholder="<?php echo htmlspecialchars($l['email']); ?>" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_password']); ?></label>
                    <input type="password" name="password" class="form-control rounded-3 py-2" placeholder="<?php echo htmlspecialchars($l['users_placeholder_password'] ?? 'Min. 6 characters'); ?>" required autocomplete="new-password">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_role']); ?></label>
                    <select name="role" class="form-select rounded-3 py-2">
                        <option value="Cashier"><?php echo htmlspecialchars($l['role_cashier']); ?></option>
                        <option value="Administrator"><?php echo htmlspecialchars($l['role_administrator']); ?></option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_status']); ?></label>
                    <select name="status" class="form-select rounded-3 py-2">
                        <option value="Active"><?php echo htmlspecialchars($l['prod_active']); ?></option>
                        <option value="Inactive"><?php echo htmlspecialchars($l['prod_inactive']); ?></option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['users_btn_cancel']); ?></button>
                <button type="submit" class="btn btn-primary rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['users_btn_save']); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 2. 编辑用户模态框 -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="users.php" class="modal-content rounded-4 border-0">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_user_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-pen text-primary me-2"></i><?php echo htmlspecialchars($l['users_modal_edit']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_username']); ?></label>
                    <input type="text" name="username" id="edit_username" class="form-control rounded-3 py-2" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_fullname']); ?></label>
                    <input type="text" name="full_name" id="edit_full_name" class="form-control rounded-3 py-2" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_email']); ?></label>
                    <input type="email" name="email" id="edit_email" class="form-control rounded-3 py-2" required autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_role']); ?></label>
                    <select name="role" id="edit_role" class="form-select rounded-3 py-2">
                        <option value="Cashier"><?php echo htmlspecialchars($l['role_cashier']); ?></option>
                        <option value="Administrator"><?php echo htmlspecialchars($l['role_administrator']); ?></option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_status']); ?></label>
                    <select name="status" id="edit_status" class="form-select rounded-3 py-2">
                        <option value="Active"><?php echo htmlspecialchars($l['prod_active']); ?></option>
                        <option value="Inactive"><?php echo htmlspecialchars($l['prod_inactive']); ?></option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['users_btn_cancel']); ?></button>
                <button type="submit" class="btn btn-primary rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['users_btn_update']); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 3. 重置密码模态框 -->
<div class="modal fade" id="resetModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="users.php" class="modal-content rounded-4 border-0">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="reset_user_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-key text-warning me-2"></i><?php echo htmlspecialchars($l['users_modal_reset']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <p class="text-muted"><span id="reset_user_name" class="fw-bold text-dark"></span></p>
                <div class="mb-0">
                    <label class="form-label text-muted fw-bold small"><?php echo htmlspecialchars($l['users_lbl_new_password']); ?></label>
                    <input type="password" name="new_password" class="form-control rounded-3 py-2" placeholder="<?php echo htmlspecialchars($l['users_placeholder_password']); ?>" required autocomplete="new-password">
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['users_btn_cancel']); ?></button>
                <button type="submit" class="btn btn-warning rounded-3 px-4 py-2 text-white"><?php echo htmlspecialchars($l['users_btn_update']); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 4. 删除用户安全确认模态框 -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="users.php" class="modal-content rounded-4 border-0 border-start border-danger border-5">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_user_id">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-danger"><i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($l['prod_modal_delete_title']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-2 text-secondary"><?php echo htmlspecialchars($l['users_confirm_delete']); ?></p>
                <div class="bg-body-secondary p-3 rounded-3 mb-3 border">
                    <strong class="fs-5 text-dark" id="delete_user_name"></strong>
                </div>
                <small class="text-danger d-block bg-danger bg-opacity-10 p-3 rounded-3">
                    <i class="fas fa-triangle-exclamation me-1"></i> <?php echo htmlspecialchars($l['users_delete_warning']); ?>
                </small>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['users_btn_cancel']); ?></button>
                <button type="submit" class="btn btn-danger rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['users_btn_delete_confirm']); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 5. 动态表单数据绑定与实例化 JS 代码 -->
<script>
function openEditModal(id, username, fullName, email, role, status) {
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_username').value = username;
    document.getElementById('edit_full_name').value = fullName;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_status').value = status;
    
    var editModal = new bootstrap.Modal(document.getElementById('editModal'));
    editModal.show();
}

function openResetModal(id, fullName) {
    document.getElementById('reset_user_id').value = id;
    document.getElementById('reset_user_name').innerText = fullName;
    
    var resetModal = new bootstrap.Modal(document.getElementById('resetModal'));
    resetModal.show();
}

function openDeleteModal(id, fullName) {
    document.getElementById('delete_user_id').value = id;
    document.getElementById('delete_user_name').innerText = fullName;
    
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    deleteModal.show();
}
</script>

<?php 
require_once '../includes/footer.php'; 
?>