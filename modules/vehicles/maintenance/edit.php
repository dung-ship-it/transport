<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();

$pdo         = getDBConnection();
$currentUser = currentUser();

// Chỉ admin/kế toán mới được sửa
$canManage = in_array($currentUser['role'] ?? '', ['admin', 'accountant']);
if (!$canManage) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Bạn không có quyền chỉnh sửa.'];
    header('Location: index.php'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Load bản ghi
$stmt = $pdo->prepare("
    SELECT m.*, v.plate_number, vt.name AS vehicle_type
    FROM maintenance_logs m
    JOIN vehicles v       ON m.vehicle_id      = v.id
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE m.id = ?
");
$stmt->execute([$id]);
$log = $stmt->fetch();

if (!$log) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Không tìm thấy bản ghi!'];
    header('Location: index.php'); exit;
}

$errors = [];

// ── Xử lý lưu ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';

    // ── XÓA ──────────────────────────────────────────────────
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM maintenance_logs WHERE id = ?")
            ->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xóa bản ghi bảo dưỡng!'];
        header('Location: index.php'); exit;
    }

    // ── CẬP NHẬT ─────────────────────────────────────────────
    $vehicleId       = (int)$_POST['vehicle_id'];
    $maintenanceDate = $_POST['maintenance_date'] ?? '';
    $maintenanceType = $_POST['maintenance_type'] ?? 'repair';
    $description     = trim($_POST['description'] ?? '');
    $garageName      = trim($_POST['garage_name'] ?? '');
    $odometerKm      = $_POST['odometer_km'] !== '' ? (int)$_POST['odometer_km'] : null;
    $partsCost       = (float)str_replace(',', '', $_POST['parts_cost'] ?? '0');
    $laborCost       = (float)str_replace(',', '', $_POST['labor_cost'] ?? '0');
    $totalCost       = $partsCost + $laborCost;
    $note            = trim($_POST['note'] ?? '');
    $status          = $_POST['status'] ?? $log['status'];

    // Validation
    if (!$vehicleId)            $errors[] = 'Vui lòng chọn xe.';
    if (empty($maintenanceDate)) $errors[] = 'Vui lòng chọn ngày.';
    if (empty($description))    $errors[] = 'Vui lòng nhập nội dung.';
    if ($totalCost <= 0)        $errors[] = 'Tổng chi phí phải lớn hơn 0.';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE maintenance_logs SET
                vehicle_id       = ?,
                maintenance_date = ?,
                maintenance_type = ?,
                description      = ?,
                garage_name      = ?,
                odometer_km      = ?,
                parts_cost       = ?,
                labor_cost       = ?,
                total_cost       = ?,
                note             = ?,
                status           = ?,
                updated_at       = NOW()
            WHERE id = ?
        ")->execute([
            $vehicleId,
            $maintenanceDate,
            $maintenanceType,
            $description,
            $garageName,
            $odometerKm,
            $partsCost,
            $laborCost,
            $totalCost,
            $note,
            $status,
            $id,
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã cập nhật bản ghi bảo dưỡng!'];
        header('Location: index.php'); exit;
    }
}

// Danh sách xe
$vehicles = $pdo->query("
    SELECT v.id, v.plate_number, vt.name AS type_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.is_active = TRUE ORDER BY v.plate_number
")->fetchAll();

$pageTitle = 'Chỉnh sửa bảo dưỡng';
include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:800px">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h4 class="fw-bold mb-0">✏️ Chỉnh sửa bảo dưỡng</h4>
                <div class="text-muted small">
                    Xe <?= htmlspecialchars($log['plate_number']) ?>
                    — <?= $log['maintenance_date'] ? date('d/m/Y', strtotime($log['maintenance_date'])) : '—' ?>
                </div>
            </div>
        </div>
        <!-- Nút xóa -->
        <form method="POST" id="deleteForm">
            <input type="hidden" name="action" value="delete">
            <button type="button" class="btn btn-danger btn-sm"
                    onclick="confirmDelete()">
                <i class="fas fa-trash me-1"></i> Xóa bản ghi
            </button>
        </form>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="editForm">
        <input type="hidden" name="action" value="update">

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 fw-bold">
                🔧 Thông tin bảo dưỡng / sửa chữa
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Ngày -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Ngày <span class="text-danger">*</span></label>
                        <input type="date" name="maintenance_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['maintenance_date'] ?? $log['maintenance_date'] ?? '') ?>"
                               required>
                    </div>

                    <!-- Xe -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Xe <span class="text-danger">*</span></label>
                        <select name="vehicle_id" class="form-select" required>
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>"
                                <?= ($v['id'] == ($_POST['vehicle_id'] ?? $log['vehicle_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                                (<?= htmlspecialchars($v['type_name']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Loại bảo dưỡng -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Loại <span class="text-danger">*</span></label>
                        <select name="maintenance_type" class="form-select" required>
                            <?php
                            $types = [
                                'repair'    => '🔧 Sửa chữa',
                                'scheduled' => '📅 Định kỳ',
                                'tire'      => '🔄 Lốp xe',
                                'oil'       => '🛢️ Thay dầu',
                                'other'     => '📌 Khác',
                            ];
                            $selType = $_POST['maintenance_type'] ?? $log['maintenance_type'] ?? 'repair';
                            foreach ($types as $val => $lbl):
                            ?>
                            <option value="<?= $val ?>" <?= $selType === $val ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Nội dung -->
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Nội dung <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="2" required
                                  placeholder="Mô tả công việc bảo dưỡng/sửa chữa..."><?=
                            htmlspecialchars($_POST['description'] ?? $log['description'] ?? '')
                        ?></textarea>
                    </div>

                    <!-- Garage -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Garage / Đơn vị sửa</label>
                        <input type="text" name="garage_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['garage_name'] ?? $log['garage_name'] ?? '') ?>"
                               placeholder="Tên garage hoặc đơn vị sửa chữa">
                    </div>

                    <!-- Km đồng hồ -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Km đồng hồ</label>
                        <div class="input-group">
                            <input type="number" name="odometer_km" class="form-control"
                                   value="<?= htmlspecialchars($_POST['odometer_km'] ?? $log['odometer_km'] ?? '') ?>"
                                   min="0" step="1" placeholder="0">
                            <span class="input-group-text">km</span>
                        </div>
                    </div>

                    <!-- Trạng thái -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Trạng thái</label>
                        <select name="status" class="form-select">
                            <?php
                            $statuses = [
                                'pending'     => '⏳ Chờ duyệt',
                                'in_progress' => '🔧 Đang sửa',
                                'completed'   => '✅ Hoàn thành',
                            ];
                            $selStatus = $_POST['status'] ?? $log['status'] ?? 'pending';
                            foreach ($statuses as $val => $lbl):
                            ?>
                            <option value="<?= $val ?>" <?= $selStatus === $val ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <!-- Chi phí -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 fw-bold">
                💰 Chi phí
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Phụ tùng -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Chi phí phụ tùng</label>
                        <div class="input-group">
                            <input type="number" name="parts_cost" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parts_cost'] ?? $log['parts_cost'] ?? 0) ?>"
                                   min="0" step="1000" placeholder="0"
                                   id="partsCost" oninput="calcTotal()">
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>

                    <!-- Nhân công -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Chi phí nhân công</label>
                        <div class="input-group">
                            <input type="number" name="labor_cost" class="form-control"
                                   value="<?= htmlspecialchars($_POST['labor_cost'] ?? $log['labor_cost'] ?? 0) ?>"
                                   min="0" step="1000" placeholder="0"
                                   id="laborCost" oninput="calcTotal()">
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>

                    <!-- Tổng tiền (readonly, tự tính) -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Tổng tiền</label>
                        <div class="input-group">
                            <input type="text" id="totalDisplay" class="form-control fw-bold text-danger"
                                   readonly placeholder="0">
                            <span class="input-group-text">₫</span>
                        </div>
                        <div class="form-text" style="font-size:10px;">Tự tính = Phụ tùng + Nhân công</div>
                    </div>

                    <!-- Ghi chú -->
                    <div class="col-12">
                        <label class="form-label fw-semibold small">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm..."><?=
                            htmlspecialchars($_POST['note'] ?? $log['note'] ?? '')
                        ?></textarea>
                    </div>

                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> Lưu thay đổi
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Huỷ</a>
        </div>
    </form>

</div>
</div>

<script>
function calcTotal() {
    const p = parseFloat(document.getElementById('partsCost').value) || 0;
    const l = parseFloat(document.getElementById('laborCost').value) || 0;
    document.getElementById('totalDisplay').value = (p + l).toLocaleString('vi-VN');
}

function confirmDelete() {
    if (confirm('⚠️ Bạn có chắc muốn XÓA bản ghi bảo dưỡng này?\nHành động này không thể hoàn tác!')) {
        document.getElementById('deleteForm').submit();
    }
}

// Chạy lần đầu
calcTotal();
</script>

<?php include '../../../includes/footer.php'; ?>