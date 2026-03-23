<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('trips', 'create');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Load chuyến
$stmt = $pdo->prepare("
    SELECT t.*, d.id AS driver_db_id
    FROM trips t
    JOIN drivers d ON t.driver_id = d.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$trip = $stmt->fetch();

if (!$trip) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Không tìm thấy chuyến xe!'];
    header('Location: index.php'); exit;
}

// Chỉ cho sửa khi chưa duyệt hoặc bị từ chối
$editableStatuses = ['draft', 'submitted', 'rejected'];
if (!in_array($trip['status'], $editableStatuses)) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => '⚠️ Chuyến đã được duyệt, không thể chỉnh sửa.'];
    header('Location: index.php'); exit;
}

$errors = [];

// ── Xử lý lưu ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driverId        = (int)$_POST['driver_id'];
    $vehicleId       = (int)$_POST['vehicle_id'];
    $customerId      = (int)$_POST['customer_id'];
    $tripDate        = $_POST['trip_date'] ?? '';
    $pickupLocation  = trim($_POST['pickup_location'] ?? '');
    $dropoffLocation = trim($_POST['dropoff_location'] ?? '');
    $odoStart        = $_POST['odometer_start'] !== '' ? (int)$_POST['odometer_start'] : null;
    $odoEnd          = $_POST['odometer_end']   !== '' ? (int)$_POST['odometer_end']   : null;
    $tollFee         = $_POST['toll_fee'] !== '' ? (float)str_replace(',', '', $_POST['toll_fee']) : 0;
    $note            = trim($_POST['note'] ?? '');
    $newStatus       = $_POST['new_status'] ?? $trip['status'];

    // Validation
    if (!$driverId)          $errors[] = 'Vui lòng chọn lái xe.';
    if (!$vehicleId)         $errors[] = 'Vui lòng chọn xe.';
    if (!$customerId)        $errors[] = 'Vui lòng chọn khách hàng.';
    if (empty($tripDate))    $errors[] = 'Vui lòng chọn ngày.';
    if (empty($pickupLocation))  $errors[] = 'Vui lòng nhập điểm đi.';
    if (empty($dropoffLocation)) $errors[] = 'Vui lòng nhập điểm đến.';
    if ($odoStart && $odoEnd && $odoEnd < $odoStart) $errors[] = 'KM kết thúc phải lớn hơn KM đi.';

    if (empty($errors)) {
        // ⚠️ KHÔNG set total_km và is_sunday — đây là generated columns trong PostgreSQL
        // PostgreSQL tự tính: total_km = odometer_end - odometer_start
        //                     is_sunday = EXTRACT(DOW FROM trip_date) = 0
        $pdo->prepare("
            UPDATE trips SET
                driver_id        = ?,
                vehicle_id       = ?,
                customer_id      = ?,
                trip_date        = ?,
                pickup_location  = ?,
                dropoff_location = ?,
                odometer_start   = ?,
                odometer_end     = ?,
                toll_fee         = ?,
                note             = ?,
                status           = ?,
                updated_at       = NOW()
            WHERE id = ?
        ")->execute([
            $driverId, $vehicleId, $customerId,
            $tripDate,
            strtoupper($pickupLocation),
            strtoupper($dropoffLocation),
            $odoStart, $odoEnd,
            $tollFee, $note,
            $newStatus,
            $id
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã cập nhật chuyến xe thành công!'];
        header('Location: index.php');
        exit;
    }
}

// Danh sách lái xe, xe, khách hàng
$drivers = $pdo->query("
    SELECT d.id, u.full_name, u.employee_code
    FROM drivers d JOIN users u ON d.user_id = u.id
    WHERE u.is_active = TRUE ORDER BY u.full_name
")->fetchAll();

$vehicles = $pdo->query("
    SELECT v.id, v.plate_number, v.capacity, vt.name AS type_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.is_active = TRUE ORDER BY v.plate_number
")->fetchAll();

$customers = $pdo->query("
    SELECT id, customer_code, short_name, company_name
    FROM customers WHERE is_active = TRUE ORDER BY company_name
")->fetchAll();

$pageTitle = 'Chỉnh sửa chuyến ' . $trip['trip_code'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:860px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h4 class="fw-bold mb-0">✏️ Chỉnh sửa chuyến xe</h4>
            <div class="text-muted small">
                <?= htmlspecialchars($trip['trip_code']) ?>
                <?php if ($trip['status'] === 'rejected'): ?>
                <span class="badge bg-danger ms-1">❌ Bị từ chối — cần điều chỉnh lại</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($trip['status'] === 'rejected' && $trip['rejection_reason']): ?>
    <div class="alert alert-danger d-flex gap-2 align-items-start mb-4">
        <i class="fas fa-exclamation-triangle mt-1"></i>
        <div>
            <strong>Lý do từ chối:</strong>
            <?= htmlspecialchars($trip['rejection_reason']) ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2 fw-bold">
                🚛 Thông tin chuyến xe
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Ngày -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Ngày <span class="text-danger">*</span></label>
                        <input type="date" name="trip_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['trip_date'] ?? $trip['trip_date']) ?>"
                               required>
                    </div>

                    <!-- Lái xe -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Lái xe <span class="text-danger">*</span></label>
                        <select name="driver_id" class="form-select" required>
                            <option value="">-- Chọn lái xe --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= ($d['id'] == ($_POST['driver_id'] ?? $trip['driver_db_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['full_name']) ?>
                                <?= $d['employee_code'] ? '(' . $d['employee_code'] . ')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Xe -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Xe <span class="text-danger">*</span></label>
                        <select name="vehicle_id" class="form-select" required>
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>"
                                <?= ($v['id'] == ($_POST['vehicle_id'] ?? $trip['vehicle_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                                (<?= $v['type_name'] ?><?= $v['capacity'] ? ' · ' . $v['capacity'] . ' tấn' : '' ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Khách hàng -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Khách hàng <span class="text-danger">*</span></label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($c['id'] == ($_POST['customer_id'] ?? $trip['customer_id'])) ? 'selected' : '' ?>>
                                [<?= $c['customer_code'] ?>] <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Điểm đi -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Điểm đi <span class="text-danger">*</span></label>
                        <input type="text" name="pickup_location" class="form-control text-uppercase"
                               value="<?= htmlspecialchars($_POST['pickup_location'] ?? $trip['pickup_location'] ?? '') ?>"
                               placeholder="VD: BẮC NINH" required>
                    </div>

                    <!-- Điểm đến -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Điểm đến <span class="text-danger">*</span></label>
                        <input type="text" name="dropoff_location" class="form-control text-uppercase"
                               value="<?= htmlspecialchars($_POST['dropoff_location'] ?? $trip['dropoff_location'] ?? '') ?>"
                               placeholder="VD: HÀ NỘI" required>
                    </div>

                    <!-- KM đi -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Số KM điểm đi</label>
                        <div class="input-group">
                            <input type="number" name="odometer_start" class="form-control"
                                   value="<?= htmlspecialchars($_POST['odometer_start'] ?? $trip['odometer_start'] ?? '') ?>"
                                   min="0" placeholder="0" id="odoStart"
                                   oninput="calcKm()">
                            <span class="input-group-text">km</span>
                        </div>
                    </div>

                    <!-- KM kết thúc -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Số KM kết thúc</label>
                        <div class="input-group">
                            <input type="number" name="odometer_end" class="form-control"
                                   value="<?= htmlspecialchars($_POST['odometer_end'] ?? $trip['odometer_end'] ?? '') ?>"
                                   min="0" placeholder="0" id="odoEnd"
                                   oninput="calcKm()">
                            <span class="input-group-text">km</span>
                        </div>
                        <div id="kmPreview" class="form-text fw-semibold text-primary"></div>
                    </div>

                    <!-- Vé cầu đường -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Vé cầu đường</label>
                        <div class="input-group">
                            <input type="number" name="toll_fee" class="form-control"
                                   value="<?= htmlspecialchars($_POST['toll_fee'] ?? $trip['toll_fee'] ?? 0) ?>"
                                   min="0" step="1000" placeholder="0">
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>

                    <!-- Ghi chú -->
                    <div class="col-md-8">
                        <label class="form-label fw-semibold small">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm..."><?= htmlspecialchars($_POST['note'] ?? $trip['note'] ?? '') ?></textarea>
                    </div>

                    <!-- Trạng thái mới (chỉ khi bị từ chối) -->
                    <?php if ($trip['status'] === 'rejected'): ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Gửi lại duyệt?</label>
                        <select name="new_status" class="form-select">
                            <option value="rejected">Lưu nháp (chưa gửi lại)</option>
                            <option value="submitted">Gửi lại để duyệt</option>
                        </select>
                        <div class="form-text">Chọn "Gửi lại để duyệt" sau khi đã sửa xong.</div>
                    </div>
                    <?php endif; ?>

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
function calcKm() {
    const s = parseInt(document.getElementById('odoStart').value) || 0;
    const e = parseInt(document.getElementById('odoEnd').value)   || 0;
    const p = document.getElementById('kmPreview');
    if (s > 0 && e > s) {
        p.textContent = '→ Tổng: ' + (e - s).toLocaleString() + ' km';
        p.style.color = '#0d6efd';
    } else if (e > 0 && e <= s) {
        p.textContent = '⚠️ KM kết thúc phải lớn hơn KM đi';
        p.style.color = '#dc3545';
    } else {
        p.textContent = '';
    }
}
calcKm();
</script>

<?php include '../../includes/footer.php'; ?>