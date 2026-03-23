<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
if (!can('vehicles','crud') && !can('expenses','create')) {
    requirePermission('expenses','create');
}

$pageTitle = 'Thêm bảo dưỡng / sửa chữa';
$pdo = getDBConnection();
$errors = [];

$vehicleId = (int)($_GET['vehicle_id'] ?? $_POST['vehicle_id'] ?? 0);
$vehicles = $pdo->query("SELECT id, plate_number FROM vehicles WHERE is_active=TRUE ORDER BY plate_number")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vid         = (int)$_POST['vehicle_id'];
    $mDate       = $_POST['maintenance_date'] ?: date('Y-m-d');
    $mType       = $_POST['maintenance_type'] ?? 'repair';
    $description = trim($_POST['description'] ?? '');
    $garageName  = trim($_POST['garage_name'] ?? '');
    $odometerKm  = $_POST['odometer_km'] !== '' ? (float)$_POST['odometer_km'] : null;
    $partsCost   = (float)($_POST['parts_cost'] ?? 0);
    $laborCost   = (float)($_POST['labor_cost'] ?? 0);
    $totalCost   = $partsCost + $laborCost;
    $nextKm      = $_POST['next_maintenance_km'] !== '' ? (float)$_POST['next_maintenance_km'] : null;
    $nextDate    = $_POST['next_maintenance_date'] ?: null;
    $invoiceNo   = trim($_POST['invoice_number'] ?? '');
    $note        = trim($_POST['note'] ?? '');
    $status      = can('expenses','approve') ? ($_POST['status'] ?? 'completed') : 'pending';

    if (!$vid)         $errors[] = 'Vui lòng chọn xe';
    if (!$description) $errors[] = 'Nội dung không được trống';

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO maintenance_logs
                (vehicle_id, maintenance_date, maintenance_type, description,
                 garage_name, odometer_km, parts_cost, labor_cost, total_cost,
                 next_maintenance_km, next_maintenance_date, invoice_number,
                 note, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $vid, $mDate, $mType, $description,
            $garageName ?: null, $odometerKm,
            $partsCost, $laborCost, $totalCost,
            $nextKm, $nextDate, $invoiceNo ?: null,
            $note ?: null, $status, currentUser()['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã thêm bảo dưỡng!'];
        header("Location: ../detail.php?id=$vid&tab=maintenance"); exit;
    }
}

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:800px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="../detail.php?id=<?= $vehicleId ?>&tab=maintenance"
           class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">🔧 Thêm bảo dưỡng / sửa chữa</h4>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0"><i class="fas fa-tools me-1 text-warning"></i> Thông tin bảo dưỡng</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Xe <span class="text-danger">*</span></label>
                        <select name="vehicle_id" class="form-select" required>
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $vehicleId==$v['id']?'selected':'' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Ngày</label>
                        <input type="date" name="maintenance_date" class="form-control"
                               value="<?= $_POST['maintenance_date'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Loại</label>
                        <select name="maintenance_type" class="form-select">
                            <option value="repair">🔧 Sửa chữa</option>
                            <option value="scheduled">📅 Bảo dưỡng định kỳ</option>
                            <option value="tire">🔄 Lốp xe</option>
                            <option value="oil">🛢️ Thay dầu</option>
                            <option value="other">📌 Khác</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Nội dung <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="VD: Thay lốp trước 2 bánh, vá săm..." required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Garage / Xưởng sửa chữa</label>
                        <input type="text" name="garage_name" class="form-control"
                               value="<?= htmlspecialchars($_POST['garage_name'] ?? '') ?>"
                               placeholder="VD: Garage Minh Phát">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Km hiện tại</label>
                        <input type="number" name="odometer_km" class="form-control"
                               step="0.1" placeholder="125000"
                               value="<?= $_POST['odometer_km'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Số hóa đơn</label>
                        <input type="text" name="invoice_number" class="form-control"
                               value="<?= htmlspecialchars($_POST['invoice_number'] ?? '') ?>"
                               placeholder="HD-001">
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0"><i class="fas fa-dollar-sign me-1 text-success"></i> Chi phí</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Chi phí phụ tùng (₫)</label>
                        <input type="number" name="parts_cost" class="form-control"
                               step="1000" min="0" placeholder="0"
                               value="<?= $_POST['parts_cost'] ?? 0 ?>"
                               oninput="calcTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Chi phí nhân công (₫)</label>
                        <input type="number" name="labor_cost" class="form-control"
                               step="1000" min="0" placeholder="0"
                               value="<?= $_POST['labor_cost'] ?? 0 ?>"
                               oninput="calcTotal()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Tổng chi phí</label>
                        <div class="form-control bg-light fw-bold text-danger" id="totalCost">
                            0 ₫
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0"><i class="fas fa-forward me-1 text-info"></i> Bảo dưỡng tiếp theo</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Km tiếp theo</label>
                        <input type="number" name="next_maintenance_km" class="form-control"
                               placeholder="135000"
                               value="<?= $_POST['next_maintenance_km'] ?? '' ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Ngày tiếp theo</label>
                        <input type="date" name="next_maintenance_date" class="form-control"
                               value="<?= $_POST['next_maintenance_date'] ?? '' ?>">
                    </div>
                    <?php if (can('expenses', 'approve')): ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Trạng thái</label>
                        <select name="status" class="form-select">
                            <option value="completed">✅ Hoàn thành</option>
                            <option value="in_progress">🔧 Đang sửa</option>
                            <option value="pending">⏳ Chờ duyệt</option>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-warning px-4">
                <i class="fas fa-save me-1"></i> Lưu bảo dưỡng
            </button>
            <a href="../detail.php?id=<?= $vehicleId ?>&tab=maintenance"
               class="btn btn-outline-secondary">Hủy</a>
        </div>
    </form>
</div>
</div>

<script>
function calcTotal() {
    const parts = parseFloat(document.querySelector('[name=parts_cost]').value) || 0;
    const labor = parseFloat(document.querySelector('[name=labor_cost]').value) || 0;
    document.getElementById('totalCost').textContent =
        new Intl.NumberFormat('vi-VN').format(parts + labor) + ' ₫';
}
calcTotal();
</script>

<?php include '../../../includes/footer.php'; ?>