<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('vehicles', 'crud');

$pageTitle = 'Thêm xe mới';
$pdo = getDBConnection();
$errors = [];
$data = [];

$vehicleTypes = $pdo->query("SELECT * FROM vehicle_types WHERE is_active=TRUE ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'plate_number'          => strtoupper(trim($_POST['plate_number'] ?? '')),
        'vehicle_type_id'       => (int)($_POST['vehicle_type_id'] ?? 0),
        'capacity'              => $_POST['capacity'] !== '' ? (float)$_POST['capacity'] : null,
        'fuel_quota'            => $_POST['fuel_quota'] !== '' ? (float)$_POST['fuel_quota'] : null,
        'registration_expiry'   => $_POST['registration_expiry'] ?: null,
        'insurance_expiry'      => $_POST['insurance_expiry'] ?: null,
        'road_tax_expiry'       => $_POST['road_tax_expiry'] ?: null,
        'fire_insurance_expiry' => $_POST['fire_insurance_expiry'] ?: null,
        'note'                  => trim($_POST['note'] ?? '') ?: null,
    ];

    if (!$data['plate_number'])    $errors[] = 'Biển số không được trống';
    if (!$data['vehicle_type_id']) $errors[] = 'Vui lòng chọn loại xe';

    // Kiểm tra biển số trùng
    $check = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
    $check->execute([$data['plate_number']]);
    if ($check->fetch()) $errors[] = 'Biển số ' . $data['plate_number'] . ' đã tồn tại!';

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO vehicles
                (plate_number, vehicle_type_id, capacity, fuel_quota,
                 registration_expiry, insurance_expiry, road_tax_expiry,
                 fire_insurance_expiry, note, is_active, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,TRUE,?,NOW())
        ")->execute([
            $data['plate_number'],
            $data['vehicle_type_id'],
            $data['capacity'],
            $data['fuel_quota'],
            $data['registration_expiry'],
            $data['insurance_expiry'],
            $data['road_tax_expiry'],
            $data['fire_insurance_expiry'],
            $data['note'],
            currentUser()['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success', 'msg'=>'✅ Đã thêm xe <strong>'.$data['plate_number'].'</strong>!'];
        header('Location: index.php'); exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:900px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">🚛 Thêm xe mới</h4>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST">

        <!-- 1. Thông tin cơ bản -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0"><i class="fas fa-truck me-1 text-primary"></i> 1. Thông tin xe</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Biển số xe <span class="text-danger">*</span></label>
                        <input type="text" name="plate_number" class="form-control text-uppercase fw-bold"
                               value="<?= htmlspecialchars($data['plate_number'] ?? '') ?>"
                               placeholder="VD: 51C-12345"
                               style="font-size:1.1rem;letter-spacing:1px" required
                               oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Loại xe <span class="text-danger">*</span></label>
                        <select name="vehicle_type_id" class="form-select" required>
                            <option value="">-- Chọn loại xe --</option>
                            <?php foreach ($vehicleTypes as $vt): ?>
                            <option value="<?= $vt['id'] ?>"
                                <?= ($data['vehicle_type_id'] ?? 0) == $vt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vt['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Tải trọng (tấn)</label>
                        <input type="number" name="capacity" class="form-control"
                               step="0.1" min="0" placeholder="5.0"
                               value="<?= $data['capacity'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Định mức xăng (L/100km)</label>
                        <input type="number" name="fuel_quota" class="form-control"
                               step="0.1" min="0" placeholder="12.0"
                               value="<?= $data['fuel_quota'] ?? '' ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm về xe..."><?= htmlspecialchars($data['note'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Thời hạn pháp lý -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0"><i class="fas fa-calendar-alt me-1 text-warning"></i> 2. Thời hạn pháp lý</h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            📋 Đăng kiểm
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="registration_expiry" class="form-control"
                               value="<?= $data['registration_expiry'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🛡️ Bảo hiểm xe
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="insurance_expiry" class="form-control"
                               value="<?= $data['insurance_expiry'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🛣️ Phí đường bộ
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="road_tax_expiry" class="form-control"
                               value="<?= $data['road_tax_expiry'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🔥 PCCC
                            <span class="text-muted fw-normal small">(nếu có)</span>
                        </label>
                        <input type="date" name="fire_insurance_expiry" class="form-control"
                               value="<?= $data['fire_insurance_expiry'] ?? '' ?>">
                    </div>
                </div>

                <!-- Cảnh báo hạn sắp hết -->
                <div class="mt-3 p-2 bg-warning bg-opacity-10 rounded-2 small text-muted">
                    <i class="fas fa-info-circle me-1 text-warning"></i>
                    Hệ thống sẽ cảnh báo tự động khi còn <strong>30 ngày</strong> trước ngày hết hạn.
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> Lưu xe
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
        </div>

    </form>
</div>
</div>

<?php include '../../includes/footer.php'; ?>