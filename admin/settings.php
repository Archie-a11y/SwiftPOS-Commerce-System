<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 安全防护：仅限 Administrator
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Administrator') {
    header("Location: ../auth.php?view=login");
    exit();
}

require_once '../config/db.php';
require_once '../includes/languages.php'; // 引入语言配置文件

$l = $languages[$lang_code] ?? $languages['en'];

$success_msg = '';
$error_msg = '';

// 处理配置保存动作 (采用预处理语句防范 SQL 注入)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_name = trim($_POST['store_name'] ?? '');
    $store_address = trim($_POST['store_address'] ?? '');
    $store_phone = trim($_POST['store_phone'] ?? '');
    $sst_rate = floatval($_POST['sst_rate'] ?? 0.00);
    $sst_reg_no = trim($_POST['sst_reg_no'] ?? '');
    $receipt_qr_link = trim($_POST['receipt_qr_link'] ?? '');

    if (empty($store_name)) {
        $error_msg = $l['set_err_empty_name'] ?? "Store Name cannot be empty.";
    } else {
        $conn->begin_transaction();
        try {
            $updates = [
                'store_name' => $store_name,
                'store_address' => $store_address,
                'store_phone' => $store_phone,
                'sst_rate' => number_format($sst_rate, 2, '.', ''),
                'sst_reg_no' => $sst_reg_no,
                'receipt_qr_link' => $receipt_qr_link
            ];

            foreach ($updates as $key => $val) {
                $stmt = $conn->prepare("INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = ?");
                $stmt->bind_param("sss", $key, $val, $val);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            $success_msg = $l['set_success_update'] ?? "Settings updated successfully!";
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = ($l['set_err_save_failed'] ?? "Failed to save settings: ") . $e->getMessage();
        }
    }
}

// 提取当前配置字典
$settings = [];
$res = $conn->query("SELECT key_name, key_value FROM settings");
while ($row = $res->fetch_assoc()) {
    $settings[$row['key_name']] = $row['key_value'];
}

include_once '../includes/header.php';
include_once '../includes/alerts.php';
?>

<div class="row">
    <div class="col-12 col-xl-8 mx-auto">
        <!-- 页面页头 -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle">
                    <i class="fas fa-sliders fa-2x"></i>
                </div>
                <div>
                    <h2 class="fw-bold mb-1"><?php echo htmlspecialchars($l['set_title'] ?? 'Store Settings'); ?></h2>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($l['set_subtitle'] ?? 'Configure your business metadata, Malaysia SST registration, and hardware variables.'); ?></p>
                </div>
            </div>
        </div>

        <?php render_shared_alerts($l, $success_msg, $error_msg); ?>

        <!-- 主表单 -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <form method="POST" action="settings.php">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_store_name'] ?? 'Store Name *'); ?></label>
                            <input type="text" name="store_name" class="form-control py-2.5 rounded-3" value="<?php echo htmlspecialchars($settings['store_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_store_address'] ?? 'Store Address'); ?></label>
                            <textarea name="store_address" class="form-control py-2.5 rounded-3" rows="3"><?php echo htmlspecialchars($settings['store_address'] ?? ''); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_store_phone'] ?? 'Store Phone / Support Line'); ?></label>
                            <input type="text" name="store_phone" class="form-control py-2.5 rounded-3" value="<?php echo htmlspecialchars($settings['store_phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_sst_reg_no'] ?? 'SST Reg Number (W-10-xxxx-xxxxx)'); ?></label>
                            <input type="text" name="sst_reg_no" class="form-control py-2.5 rounded-3" value="<?php echo htmlspecialchars($settings['sst_reg_no'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_sst_rate'] ?? 'SST Rate (%)'); ?></label>
                            <input type="number" step="0.01" min="0" max="100" name="sst_rate" class="form-control py-2.5 rounded-3" value="<?php echo htmlspecialchars($settings['sst_rate'] ?? '0.00'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold text-muted small"><?php echo htmlspecialchars($l['set_receipt_qr_link'] ?? 'Receipt Verification QR Code URL'); ?></label>
                            <input type="url" name="receipt_qr_link" class="form-control py-2.5 rounded-3" value="<?php echo htmlspecialchars($settings['receipt_qr_link'] ?? ''); ?>" placeholder="https://">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 border-top pt-4 mt-4">
                        <button type="submit" class="btn btn-primary py-2.5 px-4 rounded-3">
                            <i class="fas fa-save me-2"></i><?php echo htmlspecialchars($l['set_btn_save'] ?? 'Save Configuration'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>