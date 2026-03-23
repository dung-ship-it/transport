<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /transport/dashboard.php'); exit; }

$pageTitle = 'Tạo chuyến mới';
$pdo  = getDBConnection();
$user = currentUser();
$errors = [];

// Lấy driver info
$driverStmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
$driverStmt->execute([$user['id']]);
$driver = $driverStmt->fetch();
if (!$driver) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy thông tin lái xe!'];
    header('Location: dashboard.php'); exit;
}
$driverId = $driver['id'];

// Danh sách xe active
$vehicles = $pdo->query("
    SELECT v.id, v.plate_number, v.capacity, vt.name AS type_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.is_active = TRUE ORDER BY v.plate_number
")->fetchAll();

// Danh sách khách hàng active
$customers = $pdo->query("
    SELECT id, customer_code, company_name, short_name
    FROM customers WHERE is_active = TRUE ORDER BY company_name
")->fetchAll();

// AJAX: lấy odometer_start
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
    $vehicleId     = (int)$_POST['vehicle_id'];
    $customerId    = (int)$_POST['customer_id'];
    $tripDate      = $_POST['trip_date']  ?: date('Y-m-d');
    $pickupLoc     = strtoupper(trim($_POST['pickup_location']  ?? ''));
    $dropoffLoc    = strtoupper(trim($_POST['dropoff_location'] ?? ''));
    $odometerStart = $_POST['odometer_start'] !== '' ? (float)$_POST['odometer_start'] : null;
    $odometerEnd   = $_POST['odometer_end']   !== '' ? (float)$_POST['odometer_end']   : null;
    $tollFee       = (float)($_POST['toll_fee'] ?? 0);
    $note          = trim($_POST['note'] ?? '');

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

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã gửi chuyến!'];
        header('Location: trips.php'); exit;
    }
}

include 'includes/header.php';
?>

<!-- Top Bar -->
<div class="driver-topbar">
    <div class="d-flex align-items-center gap-2">
        <a href="dashboard.php" class="text-white">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="fw-bold">🚗 TẠO CHUYẾN MỚI</div>
    </div>
</div>

<div class="px-3 pt-3 pb-5">

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-3">
        <?php foreach ($errors as $e): ?>
        <div><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" id="tripForm">

        <!-- Người lái (read-only) -->
        <div class="driver-card mb-3">
            <div class="row g-2">
                <div class="col-8">
                    <label class="form-label small fw-semibold text-muted">NGƯỜI LÁI</label>
                    <div class="input-group">
                        <input type="text" class="form-control bg-light fw-semibold"
                               value="<?= htmlspecialchars($user['full_name']) ?>" readonly>
                        <span class="input-group-text bg-light">
                            <i class="fas fa-lock text-muted"></i>
                        </span>
                    </div>
                </div>
                <div class="col-4">
                    <label class="form-label small fw-semibold text-muted">NGÀY</label>
                    <input type="date" name="trip_date" class="form-control"
                           value="<?= $_POST['trip_date'] ?? date('Y-m-d') ?>">
                </div>
            </div>
        </div>

        <!-- Xe -->
        <div class="driver-card mb-3">
            <div class="row g-2">
                <div class="col-7">
                    <label class="form-label small fw-semibold text-muted">
                        BIỂN SỐ XE <span class="text-danger">*</span>
                    </label>
                    <select name="vehicle_id" id="vehicleSelect" class="form-select"
                            onchange="onVehicleChange(this)" required>
                        <option value="">Chọn xe...</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                                data-capacity="<?= $v['capacity'] ?>"
                                <?= ($_POST['vehicle_id']??'')==$v['id']?'selected':'' ?>>
                            <?= htmlspecialchars($v['plate_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-5">
                    <label class="form-label small fw-semibold text-muted">TẢI TRỌNG</label>
                    <div class="input-group">
                        <input type="text" id="capacityDisplay"
                               class="form-control bg-light fw-semibold"
                               placeholder="—" readonly>
                        <span class="input-group-text bg-light">
                            <i class="fas fa-lock text-muted small"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Khách hàng -->
        <div class="driver-card mb-3">
            <label class="form-label small fw-semibold text-muted">
                KHÁCH HÀNG <span class="text-danger">*</span>
            </label>
            <select name="customer_id" class="form-select" required>
                <option value="">Chọn KH...</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>"
                    <?= ($_POST['customer_id']??'')==$c['id']?'selected':'' ?>>
                    [<?= $c['customer_code'] ?>]
                    <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Tuyến đường -->
        <div class="driver-card mb-3">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">
                    ĐIỂM ĐI <span class="text-danger">*</span>
                </label>
                <input type="text" name="pickup_location"
                       class="form-control text-uppercase fw-semibold"
                       value="<?= htmlspecialchars($_POST['pickup_location'] ?? '') ?>"
                       placeholder="ĐIỂM ĐI"
                       oninput="this.value=this.value.toUpperCase()" required>
            </div>
            <div>
                <label class="form-label small fw-semibold text-muted">
                    ĐIỂM ĐẾN <span class="text-danger">*</span>
                </label>
                <input type="text" name="dropoff_location"
                       class="form-control text-uppercase fw-semibold"
                       value="<?= htmlspecialchars($_POST['dropoff_location'] ?? '') ?>"
                       placeholder="ĐIỂM ĐẾN"
                       oninput="this.value=this.value.toUpperCase()" required>
            </div>
        </div>

        <!-- KM -->
        <div class="driver-card mb-3">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">
                    KM ĐIỂM ĐI <span class="text-danger">*</span>
                </label>
                <input type="number" name="odometer_start" id="odometerStart"
                       class="form-control" step="0.1" min="0"
                       value="<?= $_POST['odometer_start'] ?? '' ?>"
                       placeholder="Tự động lấy..."
                       oninput="calcKm()" required>
                <small class="text-muted" id="kmNote">Chọn xe để tự động lấy KM</small>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">
                    KM KẾT THÚC <span class="text-danger">*</span>
                </label>
                <input type="number" name="odometer_end" id="odometerEnd"
                       class="form-control" step="0.1" min="0"
                       value="<?= $_POST['odometer_end'] ?? '' ?>"
                       placeholder="Nhập KM kết thúc"
                       oninput="calcKm()" required>
            </div>

            <!-- Tổng KM -->
            <div class="p-2 rounded-3" style="background:#f0f4ff">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small fw-semibold">TỔNG KM TUYẾN ĐƯỜNG</span>
                    <div class="input-group" style="width:140px">
                        <input type="text" id="totalKm"
                               class="form-control form-control-sm bg-light fw-bold text-primary text-end"
                               value="--" readonly>
                        <span class="input-group-text bg-light ps-1 pe-2">
                            <i class="fas fa-lock text-muted small"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cầu đường & Ghi chú -->
        <div class="driver-card mb-3">
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">VÉ CẦU ĐƯỜNG (₫)</label>
                <input type="number" name="toll_fee" class="form-control"
                       step="1000" min="0" placeholder="0"
                       value="<?= $_POST['toll_fee'] ?? '' ?>">
            </div>
            <div>
                <label class="form-label small fw-semibold text-muted">GHI CHÚ</label>
                <textarea name="note" class="form-control" rows="2"
                          placeholder="Ghi chú..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn btn-primary btn-driver">
            <i class="fas fa-paper-plane me-2"></i>GỬI CHUYẾN
        </button>

    </form>
</div>

<script>
function onVehicleChange(sel) {
    const opt = sel.selectedOptions[0];
    const cap = opt?.dataset.capacity;
    document.getElementById('capacityDisplay').value = cap ? cap + ' tấn' : '—';

    if (!sel.value) return;
    document.getElementById('kmNote').textContent = '⏳ Đang tải...';

    fetch(`?get_odometer=1&vehicle_id=${sel.value}`)
        .then(r => r.json())
        .then(data => {
            const inp = document.getElementById('odometerStart');
            if (data.odometer_start) {
                inp.value    = data.odometer_start;
                inp.readOnly = true;
                inp.classList.add('bg-light');
                document.getElementById('kmNote').innerHTML =
                    `✅ Tự động từ chuyến trước &nbsp;
                    <a href="#" onclick="unlockOdometer();return false">Sửa</a>`;
            } else {
                inp.value    = '';
                inp.readOnly = false;
                inp.classList.remove('bg-light');
                document.getElementById('kmNote').innerHTML =
                    '<span class="text-warning">⚠️ Nhập KM ban đầu</span>';
            }
            calcKm();
        });
}

function unlockOdometer() {
    const inp = document.getElementById('odometerStart');
    inp.readOnly = false;
    inp.classList.remove('bg-light');
    inp.focus();
}

function calcKm() {
    const s = parseFloat(document.getElementById('odometerStart').value) || 0;
    const e = parseFloat(document.getElementById('odometerEnd').value)   || 0;
    document.getElementById('totalKm').value =
        (s > 0 && e > s)
            ? new Intl.NumberFormat('vi-VN').format(e - s) + ' km'
            : '--';
}
</script>

<?php include 'includes/bottom_nav.php'; ?>