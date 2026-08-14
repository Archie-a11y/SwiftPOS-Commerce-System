<?php
require_once '../includes/header.php';
require_once '../includes/alerts.php';

if ($_SESSION['role'] !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

$upload_dir = '../uploads/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. 添加商品
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $product_code = trim($_POST['product_code']);
        $name = trim($_POST['name']);
        $brand = trim($_POST['brand'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
        $cost_price = floatval($_POST['cost_price']);
        $selling_price = floatval($_POST['selling_price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $min_stock_level = intval($_POST['min_stock_level']);
        $barcode = trim($_POST['barcode']);
        $status = $_POST['status'] === 'Inactive' ? 'Inactive' : 'Active';
        
        if (empty($product_code) || empty($name) || $cost_price < 0 || $selling_price < 0) {
            $error_msg = $l['prod_err_invalid_fields'] ?? 'Please fill all required fields correctly.';
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM products WHERE product_code = ?");
            $check_stmt->bind_param("s", $product_code);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error_msg = sprintf($l['prod_err_duplicate_code'] ?? "Product Code '%s' already exists.", $product_code);
            } else {
                // 上传主图
                $image_name = null;
                if (!empty($_FILES['image']['name'])) {
                    $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($file_ext, $allowed_exts)) {
                        $image_name = time() . '_' . uniqid() . '.' . $file_ext;
                        move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name);
                    } else {
                        $error_msg = $l['prod_err_invalid_image'] ?? 'Invalid image format.';
                    }
                }

                if (empty($error_msg)) {
                    $insert_stmt = $conn->prepare("INSERT INTO products (product_code, name, brand, category_id, supplier_id, cost_price, selling_price, stock_quantity, min_stock_level, barcode, image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_stmt->bind_param("sssiidddisss", $product_code, $name, $brand, $category_id, $supplier_id, $cost_price, $selling_price, $stock_quantity, $min_stock_level, $barcode, $image_name, $status);
                    
                    if ($insert_stmt->execute()) {
                        $new_product_id = $insert_stmt->insert_id;

                        // 审计日志
                        log_activity($conn, $_SESSION['user_id'], 'Add Product', "Registered new product: {$name} [SKU: {$product_code}]");

                        // 写入附加多图相册表 (product_images)
                        if (!empty($_FILES['additional_images']['name'][0])) {
                            $files_count = count($_FILES['additional_images']['name']);
                            for ($i = 0; $i < $files_count; $i++) {
                                if ($_FILES['additional_images']['error'][$i] === 0) {
                                    $sub_ext = strtolower(pathinfo($_FILES['additional_images']['name'][$i], PATHINFO_EXTENSION));
                                    if (in_array($sub_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                        $sub_image_name = time() . '_sub_' . uniqid() . '.' . $sub_ext;
                                        if (move_uploaded_file($_FILES['additional_images']['tmp_name'][$i], $upload_dir . $sub_image_name)) {
                                            $stmt_sub = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
                                            $stmt_sub->bind_param("is", $new_product_id, $sub_image_name);
                                            $stmt_sub->execute();
                                            $stmt_sub->close();
                                        }
                                    }
                                }
                            }
                        }

                        $success_msg = $l['prod_success_add'] ?? 'Product added successfully!';
                    } else {
                        $error_msg = ($l['err_generic'] ?? 'Error occurred') . ": " . $conn->error;
                    }
                }
            }
        }
    }

    // B. 编辑商品
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $id = intval($_POST['id']);
        $product_code = trim($_POST['product_code']);
        $name = trim($_POST['name']);
        $brand = trim($_POST['brand'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $supplier_id = !empty($_POST['supplier_id']) ? intval($_POST['supplier_id']) : null;
        $cost_price = floatval($_POST['cost_price']);
        $selling_price = floatval($_POST['selling_price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $min_stock_level = intval($_POST['min_stock_level']);
        $barcode = trim($_POST['barcode']);
        $status = $_POST['status'] === 'Inactive' ? 'Inactive' : 'Active';

        if (empty($product_code) || empty($name) || $cost_price < 0 || $selling_price < 0) {
            $error_msg = $l['prod_err_invalid_fields'] ?? 'Please fill all required fields correctly.';
        } else {
            $check_stmt = $conn->prepare("SELECT id FROM products WHERE product_code = ? AND id != ?");
            $check_stmt->bind_param("si", $product_code, $id);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error_msg = sprintf($l['prod_err_duplicate_code'] ?? "Product Code '%s' already exists.", $product_code);
            } else {
                $img_query = $conn->prepare("SELECT image FROM products WHERE id = ?");
                $img_query->bind_param("i", $id);
                $img_query->execute();
                $old_image = $img_query->get_result()->fetch_assoc()['image'] ?? null;

                $image_name = $old_image;
                if (!empty($_FILES['image']['name'])) {
                    $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($file_ext, $allowed_exts)) {
                        $image_name = time() . '_' . uniqid() . '.' . $file_ext;
                        if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $image_name)) {
                            if ($old_image && file_exists($upload_dir . $old_image)) {
                                unlink($upload_dir . $old_image);
                            }
                        }
                    } else {
                        $error_msg = $l['prod_err_invalid_image'] ?? 'Invalid image format.';
                    }
                }

                if (empty($error_msg)) {
                    $update_stmt = $conn->prepare("UPDATE products SET product_code = ?, name = ?, brand = ?, category_id = ?, supplier_id = ?, cost_price = ?, selling_price = ?, stock_quantity = ?, min_stock_level = ?, barcode = ?, image = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param("sssiidddissssi", $product_code, $name, $brand, $category_id, $supplier_id, $cost_price, $selling_price, $stock_quantity, $min_stock_level, $barcode, $image_name, $status, $id);
                    
                    if ($update_stmt->execute()) {

                        // 审计日志
                        log_activity($conn, $_SESSION['user_id'], 'Edit Product', "Updated product: {$name} [SKU: {$product_code}]");

                        // 写入附加多图相册表 (product_images)
                        if (!empty($_FILES['additional_images']['name'][0])) {
                            $files_count = count($_FILES['additional_images']['name']);
                            for ($i = 0; $i < $files_count; $i++) {
                                if ($_FILES['additional_images']['error'][$i] === 0) {
                                    $sub_ext = strtolower(pathinfo($_FILES['additional_images']['name'][$i], PATHINFO_EXTENSION));
                                    if (in_array($sub_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                        $sub_image_name = time() . '_sub_' . uniqid() . '.' . $sub_ext;
                                        if (move_uploaded_file($_FILES['additional_images']['tmp_name'][$i], $upload_dir . $sub_image_name)) {
                                            $stmt_sub = $conn->prepare("INSERT INTO product_images (product_id, image_path) VALUES (?, ?)");
                                            $stmt_sub->bind_param("is", $id, $sub_image_name);
                                            $stmt_sub->execute();
                                            $stmt_sub->close();
                                        }
                                    }
                                }
                            }
                        }

                        $success_msg = $l['prod_success_update'] ?? 'Product updated successfully!';
                    } else {
                        $error_msg = ($l['err_generic'] ?? 'Error occurred') . ": " . $conn->error;
                    }
                }
            }
        }
    }

    // C. 删除商品
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = intval($_POST['id']);
        
        // 抓取主图名与子相册的所有图名
        $img_query = $conn->prepare("SELECT image, name FROM products WHERE id = ?");
        $img_query->bind_param("i", $id);
        $img_query->execute();
        $prod_data = $img_query->get_result()->fetch_assoc();
        $image = $prod_data['image'] ?? null;
        $p_name = $prod_data['name'] ?? '';

        $sub_images_res = $conn->query("SELECT image_path FROM product_images WHERE product_id = $id");
        $sub_images = [];
        while($sub_row = $sub_images_res->fetch_assoc()) {
            $sub_images[] = $sub_row['image_path'];
        }

        $delete_stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $delete_stmt->bind_param("i", $id);
        if ($delete_stmt->execute()) {
            // 擦除主图磁盘物理文件
            if ($image && file_exists($upload_dir . $image)) {
                unlink($upload_dir . $image);
            }
            // 擦除子相册磁盘物理文件
            foreach ($sub_images as $s_img) {
                if (file_exists($upload_dir . $s_img)) {
                    unlink($upload_dir . $s_img);
                }
            }

            // 审计日志
            log_activity($conn, $_SESSION['user_id'], 'Delete Product', "Deleted product: {$p_name} [ID: {$id}]");

            $success_msg = $l['prod_success_delete'] ?? 'Product deleted successfully!';
        } else {
            $error_msg = $l['prod_err_delete_ref'] ?? 'Cannot delete. Product in use.';
        }
    }
}

// 供货和分类
$categories_res = $conn->query("SELECT id, name FROM categories WHERE status = 'Active' ORDER BY name ASC");
$categories = [];
while ($row = $categories_res->fetch_assoc()) {
    $categories[] = $row;
}

$suppliers_res = $conn->query("SELECT id, company_name FROM suppliers ORDER BY company_name ASC");
$suppliers = [];
while ($row = $suppliers_res->fetch_assoc()) {
    $suppliers[] = $row;
}

// 分页搜索
$search = $_GET['search'] ?? '';
$filter_category = $_GET['category_id'] ?? '';
$filter_supplier = $_GET['supplier_id'] ?? '';
$filter_stock = $_GET['stock_status'] ?? '';

$where_clauses = ["1=1"];
$params = [];
$types = "";

if (!empty($search)) {
    $where_clauses[] = "(products.name LIKE ? OR products.brand LIKE ? OR products.product_code LIKE ? OR products.barcode LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

if (!empty($filter_category)) {
    $where_clauses[] = "products.category_id = ?";
    $params[] = intval($filter_category);
    $types .= "i";
}

if (!empty($filter_supplier)) {
    $where_clauses[] = "products.supplier_id = ?";
    $params[] = intval($filter_supplier);
    $types .= "i";
}

if ($filter_stock === 'low') {
    $where_clauses[] = "products.stock_quantity <= products.min_stock_level";
} elseif ($filter_stock === 'out') {
    $where_clauses[] = "products.stock_quantity = 0";
}

$where_sql = implode(" AND ", $where_clauses);

$limit = 10;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$count_sql = "SELECT COUNT(*) as total FROM products WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_records = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
$total_pages = ceil($total_records / $limit);

$query_sql = "SELECT products.*, categories.name AS category_name, suppliers.company_name AS supplier_name 
              FROM products 
              LEFT JOIN categories ON products.category_id = categories.id 
              LEFT JOIN suppliers ON products.supplier_id = suppliers.id 
              WHERE $where_sql 
              ORDER BY products.id DESC 
              LIMIT ?, ?";
$query_stmt = $conn->prepare($query_sql);

$bind_params = array_merge($params, [$offset, $limit]);
$bind_types = $types . "ii";

$query_stmt->bind_param($bind_types, ...$bind_params);
$query_stmt->execute();
$products_res = $query_stmt->get_result();

$products_list = [];
while ($row = $products_res->fetch_assoc()) {
    // 聚合每样商品专属附加多图
    $id_val = $row['id'];
    $sub_img_array = [];
    $sub_img_query = $conn->query("SELECT image_path FROM product_images WHERE product_id = $id_val");
    while($sub_img_row = $sub_img_query->fetch_assoc()) {
        $sub_img_array[] = $sub_img_row['image_path'];
    }
    $row['additional_images'] = $sub_img_array;
    $products_list[] = $row;
}
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.29/jspdf.plugin.autotable.min.js"></script>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-4 d-print-none">
        <div>
            <h2 class="fw-bold mb-1"><?php echo htmlspecialchars($l['nav_products'] ?? 'Products'); ?></h2>
            <p class="text-muted small mb-0 d-none d-sm-block"><?php echo htmlspecialchars($l['prod_subtitle']); ?></p>
        </div>
        <button class="btn btn-primary rounded-pill px-4 py-2" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="fas fa-plus-circle me-2"></i> <?php echo htmlspecialchars($l['prod_add_btn']); ?>
        </button>
    </div>

    <?php render_shared_alerts($l, $success_msg, $error_msg, ['print_hidden' => true]); ?>

    <!-- 过滤器面板 -->
    <div class="card border-0 shadow-sm p-4 mb-4 d-print-none rounded-4">
        <form method="GET" class="row g-3 align-items-stretch">
            <div class="col-12 col-md-6 col-lg-3 d-flex">
                <div class="input-group w-100">
                    <span class="input-group-text border-end-0 bg-transparent text-muted rounded-start-3"><i class="fas fa-search"></i></span>
                    <input type="text" name="search" class="form-control border-start-0 ps-0 rounded-end-3" placeholder="<?php echo htmlspecialchars($l['prod_search_placeholder_detailed'] ?? 'Code, Name, Brand, Barcode...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-2 d-flex">
                <select name="category_id" class="form-select rounded-3 w-100">
                    <option value=""><?php echo htmlspecialchars($l['prod_filter_all_categories'] ?? 'All Categories'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $filter_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2 d-flex">
                <select name="supplier_id" class="form-select rounded-3 w-100">
                    <option value=""><?php echo htmlspecialchars($l['prod_filter_all_suppliers'] ?? 'All Suppliers'); ?></option>
                    <?php foreach ($suppliers as $sup): ?>
                        <option value="<?php echo $sup['id']; ?>" <?php echo $filter_supplier == $sup['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sup['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-6 col-lg-2 d-flex">
                <select name="stock_status" class="form-select rounded-3 w-100">
                    <option value=""><?php echo htmlspecialchars($l['prod_filter_all_stock'] ?? 'All Stock Status'); ?></option>
                    <option value="low" <?php echo $filter_stock == 'low' ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['prod_low_stock'] ?? 'Low Stock'); ?></option>
                    <option value="out" <?php echo $filter_stock == 'out' ? 'selected' : ''; ?>><?php echo htmlspecialchars($l['prod_out_of_stock'] ?? 'Out of Stock'); ?></option>
                </select>
            </div>
            <div class="col-12 col-lg-3 d-flex gap-2">
                <button type="submit" class="btn btn-secondary flex-grow-1 d-inline-flex align-items-center justify-content-center rounded-3">
                    <i class="fas fa-filter me-2"></i> <?php echo htmlspecialchars($l['prod_filter_btn_filter'] ?? 'Filter'); ?>
                </button>
                <a href="products.php" class="btn btn-outline-secondary d-inline-flex align-items-center justify-content-center rounded-3 px-3">
                    <i class="fas fa-undo"></i>
                </a>
            </div>
        </form>
    </div>

    <div class="card border-0 shadow-sm print-area rounded-4">
        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center p-4 d-print-none">
            <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($l['prod_catalog_title']); ?> (<?php echo $total_records; ?>)</h5>
            <div class="d-flex gap-2">
                <button onclick="printCurrentPage()" class="btn btn-outline-dark d-inline-flex align-items-center justify-content-center px-3 py-2 rounded-3">
                    <i class="fas fa-print me-2"></i> <?php echo htmlspecialchars($l['prod_print'] ?? 'Print'); ?>
                </button>
                <button onclick="exportTableToExcel('productsTable', '<?php echo htmlspecialchars(addslashes($l['prod_excel_filename'] ?? 'Malaysian_Products_Catalog.xlsx')); ?>', '<?php echo htmlspecialchars(addslashes($l['prod_excel_sheetname'] ?? 'Catalog')); ?>')" class="btn btn-outline-success d-inline-flex align-items-center justify-content-center px-3 py-2 rounded-3">
                    <i class="fas fa-file-excel me-2"></i> <?php echo htmlspecialchars($l['prod_excel'] ?? 'Excel'); ?>
                </button>
                <button onclick="exportTableToPDF('productsTable', '<?php echo htmlspecialchars(addslashes($l['prod_catalog_title'])); ?>', '<?php echo htmlspecialchars(addslashes($l['prod_pdf_filename'] ?? 'Malaysian_Products_Catalog.pdf')); ?>')" class="btn btn-outline-danger d-inline-flex align-items-center justify-content-center px-3 py-2 rounded-3">
                    <i class="fas fa-file-pdf me-2"></i> <?php echo htmlspecialchars($l['prod_pdf'] ?? 'PDF'); ?>
                </button>
            </div>
        </div>

        <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle mb-0" id="productsTable">
                <thead class="table-light">
                    <tr>
                        <th class="d-print-none"><?php echo htmlspecialchars($l['prod_th_image']); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_th_code']); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_th_name']); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_lbl_brand'] ?? 'Brand'); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_th_category']); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_th_supplier']); ?></th>
                        <th class="text-end"><?php echo htmlspecialchars($l['prod_th_cost'] ?? 'Cost'); ?></th>
                        <th class="text-end"><?php echo htmlspecialchars($l['prod_th_selling'] ?? 'Selling'); ?></th>
                        <th class="text-center"><?php echo htmlspecialchars($l['prod_th_stock'] ?? 'Stock'); ?></th>
                        <th><?php echo htmlspecialchars($l['prod_th_status'] ?? 'Status'); ?></th>
                        <th class="text-end d-print-none"><?php echo htmlspecialchars($l['prod_th_actions'] ?? 'Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($products_list) === 0): ?>
                        <tr>
                            <td colspan="11" class="text-center py-5 text-muted"><?php echo htmlspecialchars($l['prod_no_found']); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products_list as $p): ?>
                            <?php 
                            $is_low = $p['stock_quantity'] <= $p['min_stock_level'];
                            $is_out = $p['stock_quantity'] == 0;
                            $display_status = ($p['status'] === 'Active') ? ($l['prod_active'] ?? 'Active') : ($l['prod_inactive'] ?? 'Inactive');
                            ?>
                            <tr>
                                <td class="d-print-none">
                                    <?php if ($p['image'] && file_exists($upload_dir . $p['image'])): ?>
                                        <img src="<?php echo $upload_dir . $p['image']; ?>" class="rounded object-fit-cover" width="45" height="45">
                                    <?php else: ?>
                                        <div class="bg-secondary bg-opacity-10 text-muted rounded d-flex align-items-center justify-content-center" style="width:45px; height:45px;">
                                            <i class="fas fa-image"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold"><?php echo htmlspecialchars($p['product_code']); ?></td>
                                <td>
                                    <span class="fw-semibold d-block"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <?php if ($p['barcode']): ?>
                                        <small class="text-muted text-uppercase">
                                            <i class="fas fa-barcode me-1"></i><?php echo htmlspecialchars($p['barcode']); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?php echo htmlspecialchars($p['brand'] ?: ($l['prod_brand_generic'] ?? 'Generic')); ?></td>
                                <td><?php echo htmlspecialchars($p['category_name'] ?? ($l['prod_category_uncategorized'] ?? 'Uncategorized')); ?></td>
                                <td><?php echo htmlspecialchars($p['supplier_name'] ?? ($l['prod_na'] ?? 'N/A')); ?></td>
                                <td class="text-end">RM<?php echo number_format($p['cost_price'], 2); ?></td>
                                <td class="text-end fw-bold text-primary">RM<?php echo number_format($p['selling_price'], 2); ?></td>
                                <td class="text-center">
                                    <?php if ($is_out): ?>
                                        <span class="badge bg-danger rounded-pill"><?php echo htmlspecialchars($l['prod_out_of_stock']); ?></span>
                                    <?php elseif ($is_low): ?>
                                        <span class="badge bg-warning text-dark rounded-pill">
                                            <?php echo sprintf($l['prod_low_stock_format'], $p['stock_quantity']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success rounded-pill"><?php echo $p['stock_quantity']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $p['status'] === 'Active' ? 'success' : 'secondary'; ?> bg-opacity-10 text-<?php echo $p['status'] === 'Active' ? 'success' : 'secondary'; ?> rounded-pill px-3">
                                        <?php echo htmlspecialchars($display_status); ?>
                                    </span>
                                </td>
                                <td class="text-end d-print-none">
                                    <div class="dropdown">
                                        <!-- 已修复：配置 strategy: fixed 防止下拉菜单被 table-responsive 容器截断或在表格内挤压产生垂直滚动条 -->
                                        <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'><i class="fas fa-ellipsis-v"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <button class="dropdown-item" onclick="viewProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                                    <i class="fas fa-eye me-2 text-info"></i> <?php echo htmlspecialchars($l['prod_view_details'] ?? 'View Details'); ?>
                                                </button>
                                            </li>
                                            <li>
                                                <button class="dropdown-item" onclick="editProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                                    <i class="fas fa-edit me-2 text-primary"></i> <?php echo htmlspecialchars($l['prod_edit_product'] ?? 'Edit Product'); ?>
                                                </button>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <button class="dropdown-item text-danger" onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')">
                                                    <i class="fas fa-trash-alt me-2"></i> <?php echo htmlspecialchars($l['prod_delete'] ?? 'Delete'); ?>
                                                </button>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="d-block d-lg-none d-print-none p-3">
            <?php if (count($products_list) === 0): ?>
                <div class="text-center py-5 text-muted"><?php echo htmlspecialchars($l['prod_no_found']); ?></div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($products_list as $p): ?>
                        <?php 
                        $is_low = $p['stock_quantity'] <= $p['min_stock_level'];
                        $is_out = $p['stock_quantity'] == 0;
                        $display_status = ($p['status'] === 'Active') ? ($l['prod_active'] ?? 'Active') : ($l['prod_inactive'] ?? 'Inactive');
                        ?>
                        <div class="col-12 col-md-6">
                            <div class="card border-0 shadow-sm h-100 rounded-3 border border-secondary-subtle">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="me-3">
                                            <?php if ($p['image'] && file_exists($upload_dir . $p['image'])): ?>
                                                <img src="<?php echo $upload_dir . $p['image']; ?>" class="rounded shadow-sm object-fit-cover" width="55" height="55">
                                            <?php else: ?>
                                                <div class="bg-secondary bg-opacity-10 text-muted rounded d-flex align-items-center justify-content-center" style="width:55px; height:55px;">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex-grow-1 min-width-0">
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary mb-1 small"><?php echo htmlspecialchars($p['product_code']); ?></span>
                                            <h6 class="fw-bold text-truncate mb-0 w-100"><?php echo htmlspecialchars($p['name']); ?></h6>
                                            <small class="text-muted d-block text-truncate"><?php echo htmlspecialchars($l['prod_lbl_brand'] ?? 'Brand'); ?>: <?php echo htmlspecialchars($p['brand'] ?: ($l['prod_brand_generic'] ?? 'Generic')); ?></small>
                                        </div>
                                        <div class="dropdown align-self-start">
                                            <!-- 已修复：移动卡片端同样配置 strategy: fixed -->
                                            <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}'><i class="fas fa-ellipsis-v"></i></button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <button class="dropdown-item" onclick="viewProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                                        <i class="fas fa-eye me-2 text-info"></i> <?php echo htmlspecialchars($l['prod_view_details'] ?? 'View Details'); ?>
                                                    </button>
                                                </li>
                                                <li>
                                                    <button class="dropdown-item" onclick="editProduct(<?php echo htmlspecialchars(json_encode($p)); ?>)">
                                                        <i class="fas fa-edit me-2 text-primary"></i> <?php echo htmlspecialchars($l['prod_edit_product'] ?? 'Edit Product'); ?>
                                                    </button>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button class="dropdown-item text-danger" onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars(addslashes($p['name'])); ?>')">
                                                        <i class="fas fa-trash-alt me-2"></i> <?php echo htmlspecialchars($l['prod_delete'] ?? 'Delete'); ?>
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                    <div class="row g-2 border-top pt-2 small">
                                        <div class="col-6">
                                            <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_category'] ?? 'Category'); ?></span>
                                            <span class="fw-semibold text-truncate d-block"><?php echo htmlspecialchars($p['category_name'] ?? ($l['prod_category_uncategorized'] ?? 'Uncategorized')); ?></span>
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_brand'] ?? 'Brand'); ?></span>
                                            <span class="fw-semibold text-truncate d-block"><?php echo htmlspecialchars($p['brand'] ?: ($l['prod_brand_generic'] ?? 'Generic')); ?></span>
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_th_cost'] ?? 'Cost'); ?></span>
                                            <span class="fw-semibold text-secondary">RM<?php echo number_format($p['cost_price'], 2); ?></span>
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_th_selling'] ?? 'Selling'); ?></span>
                                            <span class="fw-bold text-primary">RM<?php echo number_format($p['selling_price'], 2); ?></span>
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted d-block small mb-1"><?php echo htmlspecialchars($l['prod_th_stock'] ?? 'Stock'); ?></span>
                                            <?php if ($is_out): ?>
                                                <span class="badge bg-danger rounded-pill"><?php echo htmlspecialchars($l['prod_out_of_stock']); ?></span>
                                            <?php elseif ($is_low): ?>
                                                <span class="badge bg-warning text-dark rounded-pill">
                                                    <?php echo sprintf($l['prod_low_stock_format'], $p['stock_quantity']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success rounded-pill"><?php echo $p['stock_quantity']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-6">
                                            <span class="text-muted d-block small mb-1"><?php echo htmlspecialchars($l['prod_th_status'] ?? 'Status'); ?></span>
                                            <span class="badge bg-<?php echo $p['status'] === 'Active' ? 'success' : 'secondary'; ?> bg-opacity-10 text-<?php echo $p['status'] === 'Active' ? 'success' : 'secondary'; ?> rounded-pill px-2">
                                                <?php echo htmlspecialchars($display_status); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-transparent border-0 d-flex justify-content-between align-items-center p-4 d-print-none rounded-bottom-4">
                <span class="small text-muted">
                    <?php echo htmlspecialchars($l['prod_showing']); ?> 
                    <?php echo $offset + 1; ?> 
                    <?php echo htmlspecialchars($l['prod_to']); ?> 
                    <?php echo min($offset + $limit, $total_records); ?> 
                    <?php echo htmlspecialchars($l['prod_of']); ?> 
                    <?php echo $total_records; ?> 
                    <?php echo htmlspecialchars($l['prod_records']); ?>
                </span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&category_id=<?php echo $filter_category; ?>&supplier_id=<?php echo $filter_supplier; ?>&stock_status=<?php echo $filter_stock; ?>"><?php echo htmlspecialchars($l['prod_prev']); ?></a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&category_id=<?php echo $filter_category; ?>&supplier_id=<?php echo $filter_supplier; ?>&stock_status=<?php echo $filter_stock; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&category_id=<?php echo $filter_category; ?>&supplier_id=<?php echo $filter_supplier; ?>&stock_status=<?php echo $filter_stock; ?>"><?php echo htmlspecialchars($l['prod_next']); ?></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL: 添加商品 -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2 text-primary"></i><?php echo htmlspecialchars($l['prod_modal_add_title']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_code']); ?></label>
                            <input type="text" name="product_code" class="form-control" placeholder="e.g. PROD1001" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_name']); ?></label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Milo Activ-Go 1kg" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_brand_placeholder'] ?? 'Brand / Maker'); ?></label>
                            <input type="text" name="brand" class="form-control" placeholder="e.g. Nestle">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_category']); ?></label>
                            <select name="category_id" class="form-select">
                                <option value=""><?php echo htmlspecialchars($l['prod_select_category_placeholder']); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_supplier']); ?></label>
                            <select name="supplier_id" class="form-select">
                                <option value=""><?php echo htmlspecialchars($l['prod_select_supplier_placeholder']); ?></option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_cost']); ?></label>
                            <input type="number" step="0.01" name="cost_price" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_selling']); ?></label>
                            <input type="number" step="0.01" name="selling_price" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_stock']); ?></label>
                            <input type="number" name="stock_quantity" class="form-control" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_min_stock']); ?></label>
                            <input type="number" name="min_stock_level" class="form-control" value="5">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_barcode']); ?></label>
                            <input type="text" name="barcode" class="form-control" placeholder="EAN-13, UPC...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_status']); ?></label>
                            <select name="status" class="form-select">
                                <option value="Active"><?php echo htmlspecialchars($l['prod_active']); ?></option>
                                <option value="Inactive"><?php echo htmlspecialchars($l['prod_inactive']); ?></option>
                            </select>
                        </div>
                        <!-- 主图及高保真预览容器 -->
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_primary_image'] ?? 'Product Primary Image *'); ?></label>
                            <input type="file" name="image" id="add_image_input" class="form-control" accept="image/*">
                            <div id="add_image_preview" class="mt-2 d-flex gap-2 flex-wrap"></div>
                        </div>
                        <!-- 子相册及高保真多图预览容器 -->
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_additional_images'] ?? 'Additional Album Photos (Multiple Images)'); ?></label>
                            <input type="file" name="additional_images[]" id="add_album_input" class="form-control" accept="image/*" multiple>
                            <div id="add_album_preview" class="mt-2 d-flex gap-2 flex-wrap"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?php echo htmlspecialchars($l['prod_btn_save']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 编辑商品 -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-header border-0">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2 text-primary"></i><?php echo htmlspecialchars($l['prod_modal_edit_title']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_code']); ?></label>
                            <input type="text" name="product_code" id="edit_code" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_name']); ?></label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_brand_placeholder'] ?? 'Brand / Maker'); ?></label>
                            <input type="text" name="brand" id="edit_brand" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_category']); ?></label>
                            <select name="category_id" id="edit_category_id" class="form-select">
                                <option value=""><?php echo htmlspecialchars($l['prod_select_category_placeholder']); ?></option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_supplier']); ?></label>
                            <select name="supplier_id" id="edit_supplier_id" class="form-select">
                                <option value=""><?php echo htmlspecialchars($l['prod_select_supplier_placeholder']); ?></option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?php echo $sup['id']; ?>"><?php echo htmlspecialchars($sup['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_cost']); ?></label>
                            <input type="number" step="0.01" name="cost_price" id="edit_cost_price" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_selling']); ?></label>
                            <input type="number" step="0.01" name="selling_price" id="edit_selling_price" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_stock']); ?></label>
                            <input type="number" name="stock_quantity" id="edit_stock" class="form-control bg-body-tertiary" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_min_stock']); ?></label>
                            <input type="number" name="min_stock_level" id="edit_min_stock" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_barcode']); ?></label>
                            <input type="text" name="barcode" id="edit_barcode" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_status']); ?></label>
                            <select name="status" id="edit_status" class="form-select">
                                <option value="Active"><?php echo htmlspecialchars($l['prod_active']); ?></option>
                                <option value="Inactive"><?php echo htmlspecialchars($l['prod_inactive']); ?></option>
                            </select>
                        </div>
                        <!-- 主图替换及高保真预览容器 -->
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_replace_primary_image'] ?? 'Replace Primary Image'); ?></label>
                            <input type="file" name="image" id="edit_image_input" class="form-control" accept="image/*">
                            <div id="edit_image_preview" class="mt-2 d-flex gap-2 flex-wrap"></div>
                        </div>
                        <!-- 子相册追加及高保真多图预览容器 -->
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold"><?php echo htmlspecialchars($l['prod_lbl_append_additional_images'] ?? 'Append Additional Album Photos'); ?></label>
                            <input type="file" name="additional_images[]" id="edit_album_input" class="form-control" accept="image/*" multiple>
                            <div id="edit_album_preview" class="mt-2 d-flex gap-2 flex-wrap"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4"><?php echo htmlspecialchars($l['prod_btn_update']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL: 查看商品细节 -->
<div class="modal fade" id="viewProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content rounded-4">
            <div class="modal-header border-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-cube me-2 text-primary"></i><?php echo htmlspecialchars($l['prod_modal_view_title']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <!-- 多图相册 Gallery -->
                <div class="d-flex justify-content-center flex-wrap gap-2 mb-3" id="view_gallery_container"></div>

                <h4 id="view_name" class="fw-bold mb-1"></h4>
                <p id="view_code" class="text-muted small mb-3"></p>
                <hr class="border-light">
                <div class="row text-start g-2">
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_brand'] ?? 'Brand'); ?></span>
                        <strong id="view_brand"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_category']); ?></span>
                        <strong id="view_category"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_supplier']); ?></span>
                        <strong id="view_supplier"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_cost']); ?></span>
                        <strong id="view_cost" class="text-secondary"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_selling']); ?></span>
                        <strong id="view_selling" class="text-primary"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_stock']); ?></span>
                        <strong id="view_stock"></strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_status']); ?></span>
                        <strong id="view_status"></strong>
                    </div>
                    <div class="col-12">
                        <span class="text-muted d-block small"><?php echo htmlspecialchars($l['prod_lbl_barcode_qrcode_details'] ?? 'Barcode & QR Code'); ?></span>
                        <strong id="view_barcode" class="text-uppercase"></strong>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-secondary w-100 rounded-pill py-3" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_close']); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: 删除安全确认 -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content rounded-4 border">
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="modal-body p-4 text-center">
                    <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                    <h5 class="fw-bold"><?php echo htmlspecialchars($l['prod_modal_delete_title']); ?></h5>
                    <p class="text-muted small mb-0"><?php echo htmlspecialchars($l['prod_delete_warning']); ?> <strong id="delete_prod_name"></strong>.</p>
                </div>
                <div class="modal-footer border-top-0 pt-0 d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-secondary px-3 rounded-pill" data-bs-dismiss="modal"><?php echo htmlspecialchars($l['prod_btn_cancel']); ?></button>
                    <button type="submit" class="btn btn-danger px-3 rounded-pill"><?php echo htmlspecialchars($l['prod_btn_delete_confirm']); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // 注册高保真图片预览组件
    setupImagePreview('add_image_input', 'add_image_preview');
    setupMultipleImagePreview('add_album_input', 'add_album_preview');
    setupImagePreview('edit_image_input', 'edit_image_preview');
    setupMultipleImagePreview('edit_album_input', 'edit_album_preview');
});

function setupImagePreview(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (!input || !preview) return;
    input.addEventListener('change', function() {
        preview.innerHTML = '';
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" class="rounded border p-1" style="width: 80px; height: 80px; object-fit: cover;">`;
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
}

function setupMultipleImagePreview(inputId, previewId) {
    const input = document.getElementById(inputId);
    const preview = document.getElementById(previewId);
    if (!input || !preview) return;
    input.addEventListener('change', function() {
        preview.innerHTML = '';
        if (this.files) {
            Array.from(this.files).forEach(file => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML += `<img src="${e.target.result}" class="rounded border p-1" style="width: 80px; height: 80px; object-fit: cover;">`;
                };
                reader.readAsDataURL(file);
            });
        }
    });
}

function editProduct(product) {
    document.getElementById('edit_id').value = product.id;
    document.getElementById('edit_code').value = product.product_code;
    document.getElementById('edit_name').value = product.name;
    document.getElementById('edit_brand').value = product.brand || '';
    document.getElementById('edit_category_id').value = product.category_id || '';
    document.getElementById('edit_supplier_id').value = product.supplier_id || '';
    document.getElementById('edit_cost_price').value = product.cost_price;
    document.getElementById('edit_selling_price').value = product.selling_price;
    document.getElementById('edit_stock').value = product.stock_quantity;
    document.getElementById('edit_min_stock').value = product.min_stock_level;
    document.getElementById('edit_barcode').value = product.barcode || '';
    document.getElementById('edit_status').value = product.status;

    // 清空上次编辑预览
    document.getElementById('edit_image_preview').innerHTML = '';
    document.getElementById('edit_album_preview').innerHTML = '';

    new bootstrap.Modal(document.getElementById('editProductModal')).show();
}

function viewProduct(product) {
    document.getElementById('view_name').innerText = product.name;
    document.getElementById('view_code').innerText = "SKU: " + product.product_code;
    document.getElementById('view_brand').innerText = product.brand || '<?php echo htmlspecialchars(addslashes($l['prod_brand_generic'] ?? 'Generic')); ?>';
    document.getElementById('view_category').innerText = product.category_name || 'N/A';
    document.getElementById('view_supplier').innerText = product.supplier_name || 'N/A';
    document.getElementById('view_cost').innerText = "RM" + parseFloat(product.cost_price).toFixed(2);
    document.getElementById('view_selling').innerText = "RM" + parseFloat(product.selling_price).toFixed(2);
    document.getElementById('view_stock').innerText = product.stock_quantity;
    
    const activeLabel = "<?php echo htmlspecialchars($l['prod_active']); ?>";
    const inactiveLabel = "<?php echo htmlspecialchars($l['prod_inactive']); ?>";
    document.getElementById('view_status').innerText = (product.status === 'Active') ? activeLabel : inactiveLabel;
    
    let codeStr = 'Barcode: ' + (product.barcode || 'N/A');
    document.getElementById('view_barcode').innerText = codeStr;

    // 动态渲染多图相册预览列表 (Primary + Additional Album)
    const galleryContainer = document.getElementById('view_gallery_container');
    galleryContainer.innerHTML = '';

    // 1. 加载主图
    if (product.image) {
        galleryContainer.innerHTML += `
            <div class="border p-1 bg-body rounded position-relative">
                <img src="../uploads/${product.image}" class="rounded object-fit-cover" style="width: 110px; height: 110px;">
                <span class="badge bg-primary position-absolute bottom-0 start-50 translate-middle-x mb-1 small"><?php echo htmlspecialchars(addslashes($l['prod_lbl_primary_badge'] ?? 'Primary')); ?></span>
            </div>
        `;
    } else {
        galleryContainer.innerHTML += `
            <div class="border p-1 bg-body rounded d-flex align-items-center justify-content-center" style="width: 110px; height: 110px;">
                <i class="fas fa-image text-muted fa-2x"></i>
            </div>
        `;
    }

    // 2. 加载子相册附加多图
    if (product.additional_images && product.additional_images.length > 0) {
        product.additional_images.forEach(img => {
            galleryContainer.innerHTML += `
                <div class="border p-1 bg-body rounded">
                    <img src="../uploads/${img}" class="rounded object-fit-cover" style="width: 110px; height: 110px;">
                </div>
            `;
        });
    }

    new bootstrap.Modal(document.getElementById('viewProductModal')).show();
}

function confirmDelete(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_prod_name').innerText = name;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>