<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('expenses', 'approve');

$pdo = getDBConnection();
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
    SELECT f.*, v.plate_number, v.fuel_quota,
           u.full_name AS driver_name,
           d.id AS driver_db_id
    FROM fuel_logs f
    JOIN vehicles v ON f.vehicle_id = v.id
    JOIN drivers d  ON f.driver_id  = d.id
    JOIN users u    ON d.user_id    = u.id
    WHERE f.id = ?
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
    $vehicleId    = (int)$_POST['vehicle_id'];
    $driverId     = (int)$_POST['driver_id'];
    $logDate      = $_POST['log_date'] ?? '';
    $kmBefore     = $_POST['km_before'] !== '' ? (int)$_POST['km_before'] : null;
    $kmAfter      = $_POST['km_after']  !== '' ? (int)$_POST['km_after']  : null;
    $litersFilled = (float)($_POST['liters_filled'] ?? 0);
    $amount       = (float)str_replace(',', '', $_POST['amount'] ?? '0');
    $receiptImg   = trim($_POST['receipt_img'] ?? $log['receipt_img'] ?? '');
    $note         = trim($_POST['note'] ?? '');

    // Validation
    if (!$vehicleId)        $errors[] = 'Vui lòng chọn xe.';
    if (!$driverId)         $errors[] = 'Vui lòng chọn lái xe.';
    if (empty($logDate))    $errors[] = 'Vui lòng chọn ngày.';
    if ($litersFilled <= 0) $errors[] = 'Số lít phải lớn hơn 0.';
    if ($amount <= 0)       $errors[] = 'Số tiền phải lớn hơn 0.';
    if ($kmBefore && $kmAfter && $kmAfter < $kmBefore)
        $errors[] = 'KM sau phải lớn hơn KM trước.';

    if (empty($errors)) {
        // KHÔNG truyền km_driven, fuel_efficiency, price_per_liter
        // vì chúng là generated columns — PostgreSQL tự tính
        $pdo->prepare("
            UPDATE fuel_logs SET
                vehicle_id    = ?,
                driver_id     = ?,
                log_date      = ?,
                km_before     = ?,
                km_after      = ?,
                liters_filled = ?,
                amount        = ?,
                receipt_img   = ?,
                note          = ?,
                updated_at    = NOW()
            WHERE id = ?
        ")->execute([
            $vehicleId,
            $driverId,
            $logDate,
            $kmBefore,
            $kmAfter,
            $litersFilled,
            $amount,
            $receiptImg,
            $note,
            $id,
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã cập nhật bản ghi xăng dầu!'];
        header('Location: index.php'); exit;
    }
}

// Danh sách xe & lái xe
$vehicles = $pdo->query("
    SELECT id, plate_number, fuel_quota FROM vehicles WHERE is_active=TRUE ORDER BY plate_number
")->fetchAll();

$drivers = $pdo->query("
    SELECT d.id, u.full_name, u.employee_code
    FROM drivers d JOIN users u ON d.user_id = u.id
    WHERE u.is_active = TRUE ORDER BY u.full_name
")->fetchAll();

$pageTitle = 'Chỉnh sửa xăng dầu';
include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:760px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h4 class="fw-bold mb-0">✏️ Chỉnh sửa xăng dầu</h4>
            <div class="text-muted small">
                Xe <?= htmlspecialchars($log['plate_number']) ?>
                — <?= date('d/m/Y', strtotime($log['log_date'])) ?>
                <?php if ($log['is_approved']): ?>
                <span class="badge bg-success ms-1">✅ Đã duyệt</span>
                <?php else: ?>
                <span class="badge bg-warning text-dark ms-1">⏳ Chờ duyệt</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($log['is_approved']): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
        <i class="fas fa-exclamation-triangle mt-1"></i>
        <div>
            <strong>Lưu ý:</strong> Bản ghi này đã được duyệt.
            Việc chỉnh sửa sẽ ảnh hưởng đến báo cáo. Hãy chắc chắn trước khi lưu.
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
                ⛽ Thông tin xăng dầu
            </div>
            <div class="card-body">
                <div class="row g-3">

                    <!-- Ngày -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Ngày <span class="text-danger">*</span></label>
                        <input type="date" name="log_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['log_date'] ?? $log['log_date']) ?>"
                               required>
                    </div>

                    <!-- Xe -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Xe <span class="text-danger">*</span></label>
                        <select name="vehicle_id" class="form-select" required id="vehicleSelect">
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>"
                                    data-quota="<?= $v['fuel_quota'] ?? '' ?>"
                                <?= ($v['id'] == ($_POST['vehicle_id'] ?? $log['vehicle_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                                <?= $v['fuel_quota'] ? '(ĐM: '.$v['fuel_quota'].'L)' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Lái xe -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">Lái xe <span class="text-danger">*</span></label>
                        <select name="driver_id" class="form-select" required>
                            <option value="">-- Chọn lái xe --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= ($d['id'] == ($_POST['driver_id'] ?? $log['driver_db_id'])) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($d['full_name']) ?>
                                <?= $d['employee_code'] ? '('.$d['employee_code'].')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- KM trước -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">KM đồng hồ trước</label>
                        <div class="input-group">
                            <input type="number" name="km_before" class="form-control"
                                   value="<?= htmlspecialchars($_POST['km_before'] ?? $log['km_before'] ?? '') ?>"
                                   min="0" step="1" placeholder="0"
                                   id="kmBefore" oninput="calcKm()">
                            <span class="input-group-text">km</span>
                        </div>
                    </div>

                    <!-- KM sau -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">KM đồng hồ sau</label>
                        <div class="input-group">
                            <input type="number" name="km_after" class="form-control"
                                   value="<?= htmlspecialchars($_POST['km_after'] ?? $log['km_after'] ?? '') ?>"
                                   min="0" step="1" placeholder="0"
                                   id="kmAfter" oninput="calcKm()">
                            <span class="input-group-text">km</span>
                        </div>
                        <div id="kmPreview" class="form-text fw-semibold text-primary"></div>
                    </div>

                    <!-- Số lít — step="0.01" cho phép 12.5, 231.75 ... -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Số lít đổ <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="liters_filled" class="form-control"
                                   value="<?= htmlspecialchars($_POST['liters_filled'] ?? $log['liters_filled']) ?>"
                                   min="0.01" step="0.01" required
                                   id="liters" oninput="calcEfficiency()">
                            <span class="input-group-text">L</span>
                        </div>
                    </div>

                    <!-- Số tiền — step="1" cho phép nhập từng đồng -->
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Số tiền <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="number" name="amount" class="form-control"
                                   value="<?= htmlspecialchars($_POST['amount'] ?? $log['amount']) ?>"
                                   min="1" step="1" required
                                   id="amount" oninput="calcEfficiency()">
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>

                    <!-- Preview tính toán -->
                    <div class="col-12">
                        <div id="calcPreview" class="p-2 rounded d-none"
                             style="background:#f0f9ff;border-left:3px solid #0ea5e9;font-size:12px;">
                        </div>
                    </div>

                    <!-- Link hóa đơn -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Link hóa đơn / ảnh</label>
                        <input type="text" name="receipt_img" class="form-control"
                               value="<?= htmlspecialchars($_POST['receipt_img'] ?? $log['receipt_img'] ?? '') ?>"
                               placeholder="https://... hoặc để trống">
                    </div>

                    <!-- Ghi chú -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Ghi chú</label>
                        <input type="text" name="note" class="form-control"
                               value="<?= htmlspecialchars($_POST['note'] ?? $log['note'] ?? '') ?>"
                               placeholder="Ghi chú thêm...">
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
function calcKm() {
    const b = parseInt(document.getElementById('kmBefore').value) || 0;
    const a = parseInt(document.getElementById('kmAfter').value)  || 0;
    const p = document.getElementById('kmPreview');
    if (b > 0 && a > b) {
        p.textContent = '→ Đã chạy: ' + (a - b).toLocaleString() + ' km';
        p.style.color = '#0d6efd';
    } else if (a > 0 && a <= b) {
        p.textContent = '⚠️ KM sau phải lớn hơn KM trước';
        p.style.color = '#dc3545';
    } else {
        p.textContent = '';
    }
    calcEfficiency();
}

function calcEfficiency() {
    const b      = parseInt(document.getElementById('kmBefore').value)  || 0;
    const a      = parseInt(document.getElementById('kmAfter').value)   || 0;
    const liters = parseFloat(document.getElementById('liters').value)  || 0;
    const amount = parseFloat(document.getElementById('amount').value)  || 0;
    const preview = document.getElementById('calcPreview');

    const km = a > b ? (a - b) : 0;
    let html = '';

    if (liters > 0 && amount > 0) {
        const ppl = (amount / liters).toFixed(0);
        html += `<span class="me-3">💰 Đơn giá: <strong>${Number(ppl).toLocaleString('vi-VN')} ₫/L</strong></span>`;
    }
    if (km > 0 && liters > 0) {
        const eff = ((liters / km) * 100).toFixed(2);
        html += `<span class="me-3">📊 Tiêu hao: <strong>${eff} L/100km</strong></span>`;

        const sel   = document.getElementById('vehicleSelect');
        const quota = sel ? parseFloat(sel.options[sel.selectedIndex]?.dataset.quota || 0) : 0;
        if (quota > 0) {
            const effNum = parseFloat(eff);
            if (effNum > quota * 1.1) {
                html += `<span class="text-danger fw-bold">⚠️ Vượt định mức ${quota} L/100km!</span>`;
            } else if (effNum > quota) {
                html += `<span class="text-warning fw-bold">🔶 Hơi vượt định mức ${quota} L/100km</span>`;
            } else {
                html += `<span class="text-success">✅ Trong định mức ${quota} L/100km</span>`;
            }
        }
    }

    if (html) {
        preview.innerHTML = html;
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
}

calcKm();
calcEfficiency();
</script>

<?php include '../../../includes/footer.php'; ?>