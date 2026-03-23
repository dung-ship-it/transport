<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('vehicles', 'crud');

$pageTitle = 'Sửa thông tin xe';
$pdo = getDBConnection();
$errors = [];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Load xe hiện tại
$stmt = $pdo->prepare("
    SELECT v.*, vt.name AS type_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.id = ?
");
$stmt->execute([$id]);
$vehicle = $stmt->fetch();
if (!$vehicle) { header('Location: index.php'); exit; }

$vehicleTypes = $pdo->query("SELECT * FROM vehicle_types ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'plate_number'          => strtoupper(trim($_POST['plate_number'] ?? '')),
        'vehicle_type_id'       => (int)($_POST['vehicle_type_id'] ?? 0),
        'capacity'              => $_POST['capacity'] !== '' ? (float)$_POST['capacity'] : null,
        'fuel_quota'            => $_POST['fuel_quota'] !== '' ? (float)$_POST['fuel_quota'] : null,
        'registration_expiry'   => $_POST['registration_expiry']    ?: null,
        'insurance_expiry'      => $_POST['insurance_expiry']       ?: null,
        'road_tax_expiry'       => $_POST['road_tax_expiry']        ?: null,
        'fire_insurance_expiry' => $_POST['fire_insurance_expiry']  ?: null,
        'note'                  => trim($_POST['note'] ?? '') ?: null,
        'is_active'             => isset($_POST['is_active']) ? 'true' : 'false',
    ];

    if (!$data['plate_number'])    $errors[] = 'Biển số không được trống';
    if (!$data['vehicle_type_id']) $errors[] = 'Vui lòng chọn loại xe';

    // Kiểm tra biển số trùng (trừ xe hiện tại)
    $check = $pdo->prepare("SELECT id FROM vehicles WHERE plate_number = ? AND id != ?");
    $check->execute([$data['plate_number'], $id]);
    if ($check->fetch()) $errors[] = 'Biển số ' . $data['plate_number'] . ' đã tồn tại!';

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE vehicles SET
                plate_number          = ?,
                vehicle_type_id       = ?,
                capacity              = ?,
                fuel_quota            = ?,
                registration_expiry   = ?,
                insurance_expiry      = ?,
                road_tax_expiry       = ?,
                fire_insurance_expiry = ?,
                note                  = ?,
                is_active             = ?::boolean,
                updated_by            = ?,
                updated_at            = NOW()
            WHERE id = ?
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
            $data['is_active'],
            currentUser()['id'],
            $id,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã cập nhật xe <strong>' . $data['plate_number'] . '</strong>!'];
        header('Location: detail.php?id=' . $id);
        exit;
    }

    // Nếu lỗi → giữ lại data vừa nhập
    $vehicle = array_merge($vehicle, $data);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:900px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="detail.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h4 class="fw-bold mb-0">✏️ Sửa thông tin xe</h4>
            <p class="text-muted mb-0 small">Biển số: <strong><?= htmlspecialchars($vehicle['plate_number']) ?></strong></p>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST">

        <!-- 1. Thông tin xe -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-truck me-1 text-primary"></i> 1. Thông tin xe
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Biển số xe <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="plate_number" class="form-control text-uppercase fw-bold"
                               value="<?= htmlspecialchars($vehicle['plate_number']) ?>"
                               style="font-size:1.1rem;letter-spacing:1px" required
                               oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Loại xe <span class="text-danger">*</span>
                        </label>
                        <select name="vehicle_type_id" class="form-select" required>
                            <option value="">-- Chọn loại xe --</option>
                            <?php foreach ($vehicleTypes as $vt): ?>
                            <option value="<?= $vt['id'] ?>"
                                <?= $vehicle['vehicle_type_id'] == $vt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vt['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Tải trọng (tấn)</label>
                        <input type="number" name="capacity" class="form-control"
                               step="0.1" min="0" placeholder="5.0"
                               value="<?= $vehicle['capacity'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Định mức xăng (L/100km)</label>
                        <input type="number" name="fuel_quota" class="form-control"
                               step="0.1" min="0" placeholder="12.0"
                               value="<?= $vehicle['fuel_quota'] ?? '' ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm về xe..."><?= htmlspecialchars($vehicle['note'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox"
                                   name="is_active" id="isActive"
                                   <?= $vehicle['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="isActive">
                                Đang hoạt động
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Thời hạn pháp lý -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-calendar-alt me-1 text-warning"></i> 2. Thời hạn pháp lý
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            📋 Đăng kiểm
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="registration_expiry" class="form-control"
                               value="<?= $vehicle['registration_expiry'] ?? '' ?>">
                        <?php if (!empty($vehicle['registration_expiry'])): ?>
                        <?php
                            $regExp = $vehicle['registration_expiry'];
                            $regClass = $regExp < date('Y-m-d') ? 'danger' :
                                ($regExp < date('Y-m-d', strtotime('+30 days')) ? 'warning' : 'success');
                            $regDiff = (new DateTime())->diff(new DateTime($regExp));
                        ?>
                        <small class="text-<?= $regClass ?>">
                            <?= $regExp < date('Y-m-d')
                                ? '❌ Đã quá hạn ' . $regDiff->days . ' ngày'
                                : '✅ Còn ' . $regDiff->days . ' ngày' ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🛡️ Bảo hiểm xe
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="insurance_expiry" class="form-control"
                               value="<?= $vehicle['insurance_expiry'] ?? '' ?>">
                        <?php if (!empty($vehicle['insurance_expiry'])): ?>
                        <?php
                            $insExp = $vehicle['insurance_expiry'];
                            $insClass = $insExp < date('Y-m-d') ? 'danger' :
                                ($insExp < date('Y-m-d', strtotime('+30 days')) ? 'warning' : 'success');
                            $insDiff = (new DateTime())->diff(new DateTime($insExp));
                        ?>
                        <small class="text-<?= $insClass ?>">
                            <?= $insExp < date('Y-m-d')
                                ? '❌ Đã quá hạn ' . $insDiff->days . ' ngày'
                                : '✅ Còn ' . $insDiff->days . ' ngày' ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🛣️ Phí đường bộ
                            <span class="text-muted fw-normal small">(hạn cuối)</span>
                        </label>
                        <input type="date" name="road_tax_expiry" class="form-control"
                               value="<?= $vehicle['road_tax_expiry'] ?? '' ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            🔥 PCCC
                            <span class="text-muted fw-normal small">(nếu có)</span>
                        </label>
                        <input type="date" name="fire_insurance_expiry" class="form-control"
                               value="<?= $vehicle['fire_insurance_expiry'] ?? '' ?>">
                    </div>
                </div>

                <div class="mt-3 p-2 bg-warning bg-opacity-10 rounded-2 small text-muted">
                    <i class="fas fa-info-circle me-1 text-warning"></i>
                    Hệ thống cảnh báo tự động khi còn <strong>30 ngày</strong> trước ngày hết hạn.
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> Lưu thay đổi
            </button>
            <a href="detail.php?id=<?= $id ?>" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Hủy
            </a>
        </div>

    </form>
</div>
</div>

<?php include '../../includes/footer.php'; ?>