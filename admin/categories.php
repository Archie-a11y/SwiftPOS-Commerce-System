<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 安全防护：仅允许系统管理员(Administrator)角色访问
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once '../config/db.php';

// 处理表单提交动作 (采用 PRG 模式防止刷新重复提交)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 1. 新增分类
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if (empty($name)) {
            $_SESSION['error_msg_key'] = 'cat_err_empty_name';
        } else {
            // 查重检查
            $check_stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
            $check_stmt->bind_param("s", $name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $_SESSION['error_msg_key'] = 'cat_err_exists';
            } else {
                $stmt = $conn->prepare("INSERT INTO categories (name, status) VALUES (?, ?)");
                $stmt->bind_param("ss", $name, $status);
                if ($stmt->execute()) {
                    $_SESSION['success_msg_key'] = 'cat_success_add';
                } else {
                    $_SESSION['error_msg_key'] = 'cat_err_add_failed';
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
        header("Location: categories.php");
        exit();
    }

    // 2. 编辑分类
    if ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($id <= 0 || empty($name)) {
            $_SESSION['error_msg_key'] = 'cat_err_invalid_fields';
        } else {
            // 查重检查 (排除自身)
            $check_stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $check_stmt->bind_param("si", $name, $id);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $_SESSION['error_msg_key'] = 'cat_err_exists';
            } else {
                $stmt = $conn->prepare("UPDATE categories SET name = ?, status = ? WHERE id = ?");
                $stmt->bind_param("ssi", $name, $status, $id);
                if ($stmt->execute()) {
                    $_SESSION['success_msg_key'] = 'cat_success_update';
                } else {
                    $_SESSION['error_msg_key'] = 'cat_err_update_failed';
                }
                $stmt->close();
            }
            $check_stmt->close();
        }
        header("Location: categories.php");
        exit();
    }

    // 3. 删除分类 (安全物理删除：必须先检验有无关联商品)
    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);

        if ($id <= 0) {
            $_SESSION['error_msg_key'] = 'cat_err_invalid_id';
        } else {
            // 校验是否正被商品表引用
            $ref_stmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $ref_stmt->bind_param("i", $id);
            $ref_stmt->execute();
            $ref_stmt->bind_result($product_count);
            $ref_stmt->fetch();
            $ref_stmt->close();

            if ($product_count > 0) {
                $_SESSION['error_msg_key'] = 'cat_err_delete_referenced';
                $_SESSION['error_msg_arg'] = $product_count;
            } else {
                $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                $stmt->bind_param("i", $id);
                if ($stmt->execute()) {
                    $_SESSION['success_msg_key'] = 'cat_success_delete';
                } else {
                    $_SESSION['error_msg_key'] = 'cat_err_delete_failed';
                }
                $stmt->close();
            }
        }
        header("Location: categories.php");
        exit();
    }
}

// 包含通用头部组件 (此操作会自动载入 $lang_code 与多语言包 $l)
include_once '../includes/header.php';
include_once '../includes/alerts.php';

// 检索筛选与分页设置
$search = trim($_GET['search'] ?? '');
$page = intval($_GET['page'] ?? 1);
if ($page < 1) $page = 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// 1. 构建查询条件
$where = "";
$params = [];
$types = "";
if (!empty($search)) {
    $where = " WHERE name LIKE ? ";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $types .= "s";
}

// 2. 统计行数算总页数
$count_sql = "SELECT COUNT(*) FROM categories" . $where;
$count_stmt = $conn->prepare($count_sql);
if (!empty($search)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$count_stmt->bind_result($total_rows);
$count_stmt->fetch();
$count_stmt->close();

$total_pages = ceil($total_rows / $limit);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $limit;

// 3. 执行物理分页检索
$data_sql = "SELECT id, name, status FROM categories" . $where . " ORDER BY id DESC LIMIT ? OFFSET ?";
$data_stmt = $conn->prepare($data_sql);

if (!empty($search)) {
    $bind_types = $types . "ii";
    $bind_params = array_merge($params, [$limit, $offset]);
    $data_stmt->bind_param($bind_types, ...$bind_params);
} else {
    $data_stmt->bind_param("ii", $limit, $offset);
}
$data_stmt->execute();
$result = $data_stmt->get_result();
?>

<!-- 交互状态反馈提示框 -->
<?php render_shared_alerts($l); ?>

<!-- 顶层过滤检索工具栏 -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="categories.php" class="row g-3 align-items-center">
            <div class="col-12 col-md-8 d-flex gap-2">
                <div class="input-group rounded-3 overflow-hidden">
                    <span class="input-group-text bg-transparent border-end-0 text-muted rounded-start-3"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="<?php echo htmlspecialchars($l['cat_search_placeholder']); ?>" value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-primary px-4 rounded-end-3" type="submit">
                        <i class="fas fa-filter me-1"></i><?php echo htmlspecialchars($l['prod_filter_btn_filter'] ?? 'Filter'); ?>
                    </button>
                </div>
                <?php if (!empty($search)): ?>
                    <a href="categories.php" class="btn btn-outline-secondary rounded-3 d-flex align-items-center justify-content-center px-3" title="Reset">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                <?php endif; ?>
            </div>
            <div class="col-12 col-md-4 text-md-end text-start">
                <button type="button" class="btn btn-primary rounded-3 px-4 py-2.5" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="fas fa-plus-circle me-2"></i><?php echo htmlspecialchars($l['cat_add_title']); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 数据呈现区：1. PC端高保真精细数据表 (大型屏幕显示) -->
<div class="d-none d-lg-block card border-0 shadow-sm mb-4 rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <!-- 调整列宽分配：给 ID 和状态分配合理空间，并给操作列设定最小宽度以确保不换行 -->
                    <th class="ps-4" style="width: 10%; min-width: 80px;">ID</th>
                    <th style="width: 50%;"><?php echo htmlspecialchars($l['cat_name_label']); ?></th>
                    <th style="width: 15%; min-width: 120px;"><?php echo htmlspecialchars($l['cat_status_label']); ?></th>
                    <th class="text-end pe-4 text-nowrap" style="width: 25%; min-width: 240px;"><?php echo htmlspecialchars($l['cat_actions']); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 text-muted fw-mono">#<?php echo $row['id']; ?></td>
                            <td><span class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></span></td>
                            <td>
                                <?php if ($row['status'] === 'Active'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-3 py-2">
                                        <i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($l['cat_active']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-3 py-2">
                                        <i class="fas fa-circle-minus me-1"></i><?php echo htmlspecialchars($l['cat_inactive']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <!-- 加上 text-nowrap 防止操作列换行 -->
                            <td class="text-end pe-4 text-nowrap">
                                <!-- 将原本的 btn-group 改为 d-inline-flex，既保留了独立的圆角，又兼容 gap 间隔，避免布局错乱 -->
                                <div class="d-inline-flex gap-2 justify-content-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary rounded-3 px-3 py-1.5 text-nowrap" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', '<?php echo $row['status']; ?>')">
                                        <i class="fas fa-edit me-1"></i><?php echo htmlspecialchars($l['cat_update']); ?>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger rounded-3 px-3 py-1.5 text-nowrap" onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')">
                                        <i class="fas fa-trash-can me-1"></i><?php echo htmlspecialchars($l['cat_delete']); ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="fas fa-folder-open d-block fs-1 mb-3 text-secondary"></i>
                            <?php echo htmlspecialchars($l['cat_no_records']); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 数据呈现区：2. 移动端轻量级信息卡片 (中小屏幕显示) -->
<div class="d-block d-lg-none mt-2">
    <?php if ($result->num_rows > 0): ?>
        <?php $result->data_seek(0); // 游标复位重新迭代 ?>
        <?php while ($row = $result->fetch_assoc()): ?>
            <div class="card border-0 shadow-sm mb-3 rounded-3">
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">#<?php echo $row['id']; ?></span>
                        <div>
                            <?php if ($row['status'] === 'Active'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 rounded-pill px-2.5 py-1.5 small">
                                    <i class="fas fa-circle-check me-1"></i><?php echo htmlspecialchars($l['cat_active']); ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 rounded-pill px-2.5 py-1.5 small">
                                    <i class="fas fa-circle-minus me-1"></i><?php echo htmlspecialchars($l['cat_inactive']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-3"><?php echo htmlspecialchars($row['name']); ?></h5>
                    <div class="d-flex justify-content-end gap-2 border-top pt-2 mt-2">
                        <button class="btn btn-sm btn-primary rounded-3 px-3 py-2" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', '<?php echo $row['status']; ?>')">
                            <i class="fas fa-edit me-1"></i><?php echo htmlspecialchars($l['cat_update']); ?>
                        </button>
                        <button class="btn btn-sm btn-outline-danger rounded-3 px-3 py-2" onclick="openDeleteModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')">
                            <i class="fas fa-trash-can me-1"></i><?php echo htmlspecialchars($l['cat_delete']); ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="card border-0 shadow-sm py-5 text-center text-muted rounded-3">
            <i class="fas fa-folder-open d-block fs-1 mb-3 text-secondary"></i>
            <?php echo htmlspecialchars($l['cat_no_records']); ?>
        </div>
    <?php endif; ?>
</div>

<!-- 分页控制栏 -->
<?php if ($total_pages > 1): ?>
    <nav class="d-flex justify-content-between align-items-center mt-4">
        <div class="text-muted small d-none d-sm-block">
            <?php echo htmlspecialchars($l['prod_showing'] ?? 'Showing'); ?> 
            <span class="fw-bold"><?php echo $offset + 1; ?></span> 
            <?php echo htmlspecialchars($l['prod_to'] ?? 'to'); ?> 
            <span class="fw-bold"><?php echo min($offset + $limit, $total_rows); ?></span> 
            <?php echo htmlspecialchars($l['prod_of'] ?? 'of'); ?> 
            <span class="fw-bold"><?php echo $total_rows; ?></span> 
            <?php echo htmlspecialchars($l['prod_records'] ?? 'records'); ?>
        </div>
        <ul class="pagination pagination-sm mb-0 rounded-3 overflow-hidden">
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page - 1; ?>">
                    <?php echo htmlspecialchars($l['prod_prev'] ?? 'Previous'); ?>
                </a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                    <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?search=<?php echo urlencode($search); ?>&page=<?php echo $page + 1; ?>">
                    <?php echo htmlspecialchars($l['prod_next'] ?? 'Next'); ?>
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>

<!-- MODAL 1: 录入新分类弹窗 -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <form method="POST" action="categories.php">
                <input type="hidden" name="action" value="add">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-folder-plus text-primary me-2"></i><?php echo htmlspecialchars($l['cat_add_title']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-2">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['cat_name_label']); ?> *</label>
                        <input type="text" name="name" class="form-control rounded-3 py-2.5" placeholder="<?php echo htmlspecialchars($l['cat_add_placeholder'] ?? 'e.g. Stationary / Groceries'); ?>" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['cat_status_label']); ?></label>
                        <select name="status" class="form-select rounded-3 py-2.5">
                            <option value="Active" selected><?php echo htmlspecialchars($l['cat_active']); ?></option>
                            <option value="Inactive"><?php echo htmlspecialchars($l['cat_inactive']); ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['cat_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['cat_save']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: 修改分类档案弹窗 -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 rounded-4">
            <form method="POST" action="categories.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header border-bottom-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-folder-open text-primary me-2"></i><?php echo htmlspecialchars($l['cat_edit_title']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-2">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['cat_name_label']); ?> *</label>
                        <input type="text" name="name" id="edit_name" class="form-control rounded-3 py-2.5" required>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-semibold text-muted small"><?php echo htmlspecialchars($l['cat_status_label']); ?></label>
                        <select name="status" id="edit_status" class="form-select rounded-3 py-2.5">
                            <option value="Active"><?php echo htmlspecialchars($l['cat_active']); ?></option>
                            <option value="Inactive"><?php echo htmlspecialchars($l['cat_inactive']); ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['cat_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['cat_update']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 3: 物理删除安全警示弹窗 -->
<div class="modal fade" id="deleteCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow border-0 border-start border-danger border-5 rounded-4">
            <form method="POST" action="categories.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold text-danger">
                        <i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($l['cat_delete_title']); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 pt-0">
                    <p class="mb-2 text-secondary"><?php echo htmlspecialchars($l['cat_confirm_delete_msg']); ?></p>
                    <div class="bg-body-secondary p-3 rounded-3 mb-3 border">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['cat_name_label']); ?></span>
                        <strong class="fs-5" id="delete_name_span">---</strong>
                    </div>
                    <div class="alert alert-warning border-0 small mb-0 py-2.5 rounded-3">
                        <i class="fas fa-circle-info me-2 text-warning"></i><?php echo htmlspecialchars($l['cat_linked_warning']); ?>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-3 px-4 py-2" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['cat_cancel']); ?></button>
                    <button type="submit" class="btn btn-danger rounded-3 px-4 py-2"><?php echo htmlspecialchars($l['cat_delete']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 前端参数流转脚本 -->
<script>
function openEditModal(id, name, status) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_status').value = status;
    
    var editModal = new bootstrap.Modal(document.getElementById('editCategoryModal'));
    editModal.show();
}

function openDeleteModal(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name_span').innerText = name;
    
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteCategoryModal'));
    deleteModal.show();
}
</script>

<?php
// 关闭数据读取语句并引入底部
$data_stmt->close();
include_once '../includes/footer.php';
?>