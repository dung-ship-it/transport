<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('trips', 'create');

$pageTitle = 'Tạo chuyến xe';
$pdo  = getDBConnection();
$user = currentUser();
$errors = [];

// Load data
$drivers = $pdo->query("
    SELECT d.id, u.full_name, u.phone
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
    SELECT id, customer_code, company_name, short_name
    FROM customers WHERE is_active = TRUE ORDER BY company_name
")->fetchAll();

// AJAX: lấy odometer_start tự động
if (isset($_GET['get_odometer'])) {
    $vid = (int)$_GET['vehicle_id'];
    $last = $pdo->prepare("
        SELECT odometer_end FROM trips
        WHERE vehicle_id = ? AND odometer_end IS NOT NULL
          AND status NOT IN ('rejected','draft')
        ORDER BY trip_date DESC, id DESC LIMIT 1
    ");
    $last->execute([$vid]);
    $km = $last->fetchColumn();
    header('Content-Type: application/json');
    echo json_encode(['odometer_start' => $km ?: null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driverId       = (int)$_POST['driver_id'];
    $vehicleId      = (int)$_POST['vehicle_id'];
    $customerId     = (int)$_POST['customer_id'];
    $tripDate       = $_POST['trip_date']  ?: date('Y-m-d');
    $pickupLoc      = strtoupper(trim($_POST['pickup_location']  ?? ''));
    $dropoffLoc     = strtoupper(trim($_POST['dropoff_location'] ?? ''));
    $odometerStart  = $_POST['odometer_start'] !== '' ? (float)$_POST['odometer_start'] : null;
    $odometerEnd    = $_POST['odometer_end']   !== '' ? (float)$_POST['odometer_end']   : null;
    $tollFee        = (float)($_POST['toll_fee'] ?? 0);
    $note           = trim($_POST['note'] ?? '');

    if (!$driverId)     $errors[] = 'Vui lòng chọn lái xe';
    if (!$vehicleId)    $errors[] = 'Vui lòng chọn xe';
    if (!$customerId)   $errors[] = 'Vui lòng chọn khách hàng';
    if (!$pickupLoc)    $errors[] = 'Điểm đi không được trống';
    if (!$dropoffLoc)   $errors[] = 'Điểm đến không được trống';
    if (!$odometerEnd)  $errors[] = 'KM kết thúc là bắt buộc';
    if ($odometerStart === null) $errors[] = 'KM điểm đi là bắt buộc';
    if ($odometerEnd && $odometerStart && $odometerEnd <= $odometerStart)
        $errors[] = 'KM kết thúc phải lớn hơn KM điểm đi';

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO trips
                (driver_id, vehicle_id, customer_id, trip_date,
                 pickup_location, dropoff_location,
                 odometer_start, odometer_end,
                 toll_fee, note, status, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,'submitted',?,NOW())
        ")->execute([
            $driverId, $vehicleId, $customerId, $tripDate,
            $pickupLoc, $dropoffLoc,
            $odometerStart, $odometerEnd,
            $tollFee ?: null, $note ?: null,
            $user['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã tạo chuyến xe!'];
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
        <h4 class="fw-bold mb-0">🚛 Tạo chuyến xe mới</h4>
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

        <!-- 1. Thông tin cơ bản -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-info-circle me-1 text-primary"></i> 1. Thông tin cơ bản
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Người lái <span class="text-danger">*</span>
                        </label>
                        <select name="driver_id" class="form-select" required>
                            <option value="">-- Chọn lái xe --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= ($_POST['driver_id']??'')==$d['id']?'selected':'' ?>>
                                <?= htmlspecialchars($d['full_name']) ?>
                                <?= $d['phone'] ? '('.$d['phone'].')' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Ngày</label>
                        <input type="date" name="trip_date" class="form-control"
                               value="<?= $_POST['trip_date'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Biển số xe <span class="text-danger">*</span>
                        </label>
                        <select name="vehicle_id" id="vehicleSelect" class="form-select"
                                onchange="loadVehicleInfo(this)" required>
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>"
                                    data-capacity="<?= $v['capacity'] ?>"
                                    <?= ($_POST['vehicle_id']??'')==$v['id']?'selected':'' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                                (<?= $v['type_name'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Tải trọng</label>
                        <div class="input-group">
                            <input type="text" id="capacityDisplay" class="form-control bg-light"
                                   placeholder="—" readonly>
                            <span class="input-group-text">
                                <i class="fas fa-lock text-muted"></i>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Khách hàng <span class="text-danger">*</span>
                        </label>
                        <select name="customer_id" class="form-select" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= ($_POST['customer_id']??'')==$c['id']?'selected':'' ?>>
                                [<?= $c['customer_code'] ?>]
                                <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Tuyến đường -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-route me-1 text-warning"></i> 2. Tuyến đường
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Điểm đi <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="pickup_location" class="form-control text-uppercase"
                               value="<?= htmlspecialchars($_POST['pickup_location'] ?? '') ?>"
                               placeholder="ĐIỂM ĐI (TỰ ĐỘNG HOA)"
                               oninput="this.value=this.value.toUpperCase()" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Điểm đến <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="dropoff_location" class="form-control text-uppercase"
                               value="<?= htmlspecialchars($_POST['dropoff_location'] ?? '') ?>"
                               placeholder="ĐIỂM ĐẾN (TỰ ĐỘNG HOA)"
                               oninput="this.value=this.value.toUpperCase()" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Số KM -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-road me-1 text-success"></i> 3. Số KM đồng hồ
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            KM điểm đi <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="odometer_start" id="odometerStart"
                               class="form-control" step="0.1" min="0"
                               value="<?= $_POST['odometer_start'] ?? '' ?>"
                               placeholder="Tự động lấy..."
                               oninput="calcKm()" required>
                        <small class="text-muted" id="kmStartNote">
                            Tự động lấy từ chuyến trước
                        </small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            KM kết thúc <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="odometer_end" id="odometerEnd"
                               class="form-control" step="0.1" min="0"
                               value="<?= $_POST['odometer_end'] ?? '' ?>"
                               placeholder="Nhập KM kết thúc"
                               oninput="calcKm()" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Tổng KM tuyến đường</label>
                        <div class="input-group">
                            <input type="text" id="totalKm" class="form-control bg-light fw-bold text-primary"
                                   placeholder="—" readonly>
                            <span class="input-group-text">
                                <i class="fas fa-lock text-muted"></i>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Vé cầu đường</label>
                        <div class="input-group">
                            <input type="number" name="toll_fee" class="form-control"
                                   step="1000" min="0"
                                   value="<?= $_POST['toll_fee'] ?? '' ?>"
                                   placeholder="0">
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm về chuyến xe..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-paper-plane me-1"></i> Gửi chuyến
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
        </div>

    </form>
</div>
</div>

<script>
function loadVehicleInfo(sel) {
    const opt      = sel.selectedOptions[0];
    const capacity = opt?.dataset.capacity;
    const vid      = sel.value;

    document.getElementById('capacityDisplay').value = capacity ? capacity + ' tấn' : '—';

    if (!vid) return;
    document.getElementById('kmStartNote').textContent = '⏳ Đang tải...';

    fetch(`?get_odometer=1&vehicle_id=${vid}`)
        .then(r => r.json())
        .then(data => {
            const inp = document.getElementById('odometerStart');
            if (data.odometer_start) {
                inp.value    = data.odometer_start;
                inp.readOnly = true;
                inp.classList.add('bg-light');
                document.getElementById('kmStartNote').innerHTML =
                    `✅ Tự động từ chuyến trước &nbsp;
                    <a href="#" onclick="unlockOdometer();return false">Sửa tay</a>`;
            } else {
                inp.value    = '';
                inp.readOnly = false;
                inp.classList.remove('bg-light');
                document.getElementById('kmStartNote').innerHTML =
                    '<span class="text-warning">⚠️ Chưa có dữ liệu — nhập KM ban đầu</span>';
            }
            calcKm();
        });
}

function unlockOdometer() {
    const inp = document.getElementById('odometerStart');
    inp.readOnly = false;
    inp.classList.remove('bg-light');
    inp.focus();
    document.getElementById('kmStartNote').textContent = 'Đang nhập tay';
}

function calcKm() {
    const start = parseFloat(document.getElementById('odometerStart').value) || 0;
    const end   = parseFloat(document.getElementById('odometerEnd').value)   || 0;
    const total = document.getElementById('totalKm');
    if (start > 0 && end > start) {
        total.value = new Intl.NumberFormat('vi-VN').format(end - start) + ' km';
        total.style.color = '#0d6efd';
    } else {
        total.value = '—';
        total.style.color = '';
    }
}
</script>

<?php include '../../includes/footer.php'; ?>