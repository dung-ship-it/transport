<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /dashboard.php'); exit; }

$pageTitle = 'Nhập xăng dầu';
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

// Lấy danh sách xe đã từng chạy
$vehicles = $pdo->prepare("
    SELECT DISTINCT v.id, v.plate_number, vt.name AS type_name,
           v.fuel_quota
    FROM trips t
    JOIN vehicles v   ON t.vehicle_id = v.id
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE t.driver_id = ? AND v.is_active = TRUE
    ORDER BY v.plate_number
");
$vehicles->execute([$driverId]);
$vehicles = $vehicles->fetchAll();

// Lấy km_before tự động khi chọn xe (AJAX)
if (isset($_GET['get_km']) && $_GET['vehicle_id']) {
    $vid = (int)$_GET['vehicle_id'];
    $lastKm = $pdo->prepare("
        SELECT km_after FROM fuel_logs
        WHERE driver_id = ? AND vehicle_id = ?
          AND km_after IS NOT NULL
        ORDER BY log_date DESC, id DESC
        LIMIT 1
    ");
    $lastKm->execute([$driverId, $vid]);
    $km = $lastKm->fetchColumn();
    header('Content-Type: application/json');
    echo json_encode(['km_before' => $km ?: null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId   = (int)($_POST['vehicle_id'] ?? 0);
    $logDate     = $_POST['log_date']      ?? date('Y-m-d');
    $kmBefore    = $_POST['km_before'] !== '' ? (float)$_POST['km_before'] : null;
    $kmAfter     = $_POST['km_after']  !== '' ? (float)$_POST['km_after']  : null;
    $liters      = (float)($_POST['liters_filled'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $stationName = trim($_POST['station_name'] ?? '');
    $fuelType    = $_POST['fuel_type'] ?? 'diesel';
    $note        = trim($_POST['note'] ?? '');
    $receiptImg  = null;

    // Validation
    if (!$vehicleId)      $errors[] = 'Vui lòng chọn xe';
    if ($liters <= 0)     $errors[] = 'Số lít phải lớn hơn 0';
    if ($amount <= 0)     $errors[] = 'Số tiền phải lớn hơn 0';
    if (!$kmAfter)        $errors[] = 'Vui lòng nhập Km hiện tại';
    if ($kmBefore === null) $errors[] = 'Vui lòng nhập Km lần trước';
    if ($kmAfter && $kmBefore && $kmAfter <= $kmBefore)
        $errors[] = 'Km hiện tại phải lớn hơn Km lần trước';

    // Upload ảnh hóa đơn
    if (isset($_FILES['receipt_img']) && $_FILES['receipt_img']['error'] === UPLOAD_ERR_OK) {
        $file     = $_FILES['receipt_img'];
        $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','gif','webp','pdf'];
        $maxSize  = 5 * 1024 * 1024; // 5MB

        if (!in_array($ext, $allowed)) {
            $errors[] = 'File ảnh không hợp lệ (chỉ JPG, PNG, PDF)';
        } elseif ($file['size'] > $maxSize) {
            $errors[] = 'File quá lớn (tối đa 5MB)';
        } else {
            $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/fuel_receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileName  = 'fuel_' . $driverId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                $receiptImg = '/uploads/fuel_receipts/' . $fileName;
            } else {
                $errors[] = 'Lỗi upload file, thử lại!';
            }
        }
    }

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO fuel_logs
                (driver_id, vehicle_id, log_date, km_before, km_after,
                 liters_filled, amount, station_name, fuel_type,
                 receipt_img, note, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            $driverId, $vehicleId, $logDate,
            $kmBefore, $kmAfter,
            $liters, $amount,
            $stationName ?: null, $fuelType,
            $receiptImg, $note ?: null,
            $user['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã lưu thông tin xăng dầu!'];
        header('Location: fuel_history.php'); exit;
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
        <div class="fw-bold">⛽ Nhập xăng dầu</div>
    </div>
</div>

<div class="px-3 pt-3">

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger rounded-3">
        <?php foreach ($errors as $e): ?>
        <div><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($e) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="fuelForm">

        <!-- Thông tin cơ bản -->
        <div class="driver-card mb-3">
            <div class="section-title mb-3">🚛 Thông tin xe & ngày</div>

            <div class="mb-3">
                <label class="form-label fw-semibold">
                    Xe <span class="text-danger">*</span>
                </label>
                <select name="vehicle_id" id="vehicleSelect" class="form-select"
                        onchange="loadKmBefore(this.value)" required>
                    <option value="">-- Chọn xe --</option>
                    <?php foreach ($vehicles as $v): ?>
                    <option value="<?= $v['id'] ?>"
                            data-quota="<?= $v['fuel_quota'] ?>"
                            <?= ($_POST['vehicle_id'] ?? '') == $v['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($v['plate_number']) ?>
                        — <?= htmlspecialchars($v['type_name']) ?>
                        <?= $v['fuel_quota'] ? '(ĐM: '.$v['fuel_quota'].' L/100km)' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Ngày đổ</label>
                    <input type="date" name="log_date" class="form-control"
                           value="<?= $_POST['log_date'] ?? date('Y-m-d') ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Loại nhiên liệu</label>
                    <select name="fuel_type" class="form-select">
                        <option value="diesel"  <?= ($_POST['fuel_type']??'diesel')==='diesel' ?'selected':'' ?>>🛢️ Dầu Diesel</option>
                        <option value="ron95"   <?= ($_POST['fuel_type']??'')==='ron95'  ?'selected':'' ?>>⛽ Xăng RON95</option>
                        <option value="ron92"   <?= ($_POST['fuel_type']??'')==='ron92'  ?'selected':'' ?>>⛽ Xăng RON92</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Số Km -->
        <div class="driver-card mb-3">
            <div class="section-title mb-3">📍 Số Km đồng hồ</div>

            <div class="row g-2 mb-2">
                <div class="col-6">
                    <label class="form-label fw-semibold">
                        Km lần trước
                        <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="km_before" id="kmBefore"
                           class="form-control" step="0.1" min="0"
                           placeholder="Tự động lấy..."
                           value="<?= $_POST['km_before'] ?? '' ?>"
                           oninput="calcStats()" required>
                    <small class="text-muted" id="kmBeforeNote">
                        Tự động lấy từ lần đổ trước
                    </small>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">
                        Km hiện tại
                        <span class="text-danger">*</span>
                    </label>
                    <input type="number" name="km_after" id="kmAfter"
                           class="form-control" step="0.1" min="0"
                           placeholder="Nhập Km đồng hồ"
                           value="<?= $_POST['km_after'] ?? '' ?>"
                           oninput="calcStats()" required>
                </div>
            </div>

            <!-- Km đã đi -->
            <div class="p-2 rounded-3 text-center" id="kmDrivenBox"
                 style="background:#f0f4ff;display:none">
                <div class="row g-0">
                    <div class="col-6 border-end">
                        <div class="text-muted" style="font-size:0.7rem">Km đã đi</div>
                        <div class="fw-bold text-primary" id="kmDrivenDisplay">—</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted" style="font-size:0.7rem">Hiệu suất thực tế</div>
                        <div class="fw-bold" id="efficiencyDisplay">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nhiên liệu & Tiền -->
        <div class="driver-card mb-3">
            <div class="section-title mb-3">💰 Lượng xăng & Chi phí</div>

            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">
                        Số lít đổ <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                       
<input type="number" name="liters_filled" id="liters"
       class="form-control" step="any" min="0.01"
                               placeholder="0.0"
                               value="<?= $_POST['liters_filled'] ?? '' ?>"
                               oninput="calcStats()" required>
                        <span class="input-group-text">lít</span>
                    </div>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">
                        Số tiền <span class="text-danger">*</span>
                    </label>
                    <div class="input-group">
                       
<input type="number" name="amount" id="amount"
       class="form-control" step="any" min="1"
                               placeholder="0"
                               value="<?= $_POST['amount'] ?? '' ?>"
                               oninput="calcStats()" required>
                        <span class="input-group-text">₫</span>
                    </div>
                </div>
            </div>

            <!-- Giá/lít tính toán -->
            <div class="p-2 rounded-3 text-center mb-3" id="priceBox"
                 style="background:#f0fff4;display:none">
                <div class="text-muted small">Giá trung bình / lít</div>
                <div class="fw-bold fs-5 text-success" id="priceDisplay">—</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Tên trạm xăng</label>
                <input type="text" name="station_name" class="form-control"
                       placeholder="VD: Petrolimex Nguyễn Văn Linh..."
                       value="<?= htmlspecialchars($_POST['station_name'] ?? '') ?>">
            </div>

            <div class="mb-2">
                <label class="form-label fw-semibold">Ghi chú</label>
                <textarea name="note" class="form-control" rows="2"
                          placeholder="Ghi chú thêm..."><?= htmlspecialchars($_POST['note'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Ảnh hóa đơn -->
        <div class="driver-card mb-3">
            <div class="section-title mb-2">📸 Ảnh hóa đơn</div>

            <div class="mb-2">
                <label class="form-label fw-semibold">
                    Chụp / Tải ảnh hóa đơn
                    <span class="text-muted fw-normal small">(tối đa 5MB)</span>
                </label>

                <!-- Preview box -->
                <div id="previewBox" class="mb-2" style="display:none">
                    <img id="previewImg" src="" alt="Preview"
                         class="img-fluid rounded-3"
                         style="max-height:200px;object-fit:contain;width:100%;border:2px dashed #0f3460">
                </div>

                <!-- Upload area -->
                <label for="receiptInput" id="uploadArea"
                       class="d-block text-center p-4 rounded-3 cursor-pointer"
                       style="border:2px dashed #dee2e6;cursor:pointer;background:#fafafa">
                    <i class="fas fa-camera fa-2x text-muted mb-2 d-block"></i>
                    <div class="small text-muted">Chụp ảnh hoặc chọn từ thư viện</div>
                    <div class="small text-muted">JPG, PNG, PDF • Tối đa 5MB</div>
                </label>
                <input type="file" name="receipt_img" id="receiptInput"
                       accept="image/*,application/pdf"
                       capture="environment"
                       class="d-none"
                       onchange="previewReceipt(this)">
            </div>

            <!-- Nút xóa preview -->
            <button type="button" class="btn btn-sm btn-outline-danger d-none"
                    id="removeImg" onclick="removeReceipt()">
                <i class="fas fa-times me-1"></i> Xóa ảnh
            </button>
        </div>

        <!-- Submit -->
        <button type="submit" class="btn btn-primary btn-driver mb-2">
            <i class="fas fa-save me-2"></i>Lưu xăng dầu
        </button>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-driver mb-4">
            Hủy
        </a>

    </form>
</div>

<script>
// ── Load km_before tự động ──────────────────────────────────
function loadKmBefore(vehicleId) {
    if (!vehicleId) return;
    const note = document.getElementById('kmBeforeNote');
    note.textContent = '⏳ Đang tải...';

    fetch(`?get_km=1&vehicle_id=${vehicleId}`)
        .then(r => r.json())
        .then(data => {
            const inp = document.getElementById('kmBefore');
            if (data.km_before) {
                inp.value = data.km_before;
                inp.readOnly = true;
                inp.classList.add('bg-light');
                note.innerHTML = `✅ Lấy tự động từ lần đổ trước
                    <a href="#" onclick="unlockKm();return false">(Sửa tay)</a>`;
            } else {
                inp.value = '';
                inp.readOnly = false;
                inp.classList.remove('bg-light');
                note.innerHTML = '⚠️ Chưa có dữ liệu — <strong>nhập tay Km ban đầu</strong>';
            }
            calcStats();
        })
        .catch(() => {
            note.textContent = 'Không thể tải — nhập tay';
        });
}

function unlockKm() {
    const inp = document.getElementById('kmBefore');
    inp.readOnly = false;
    inp.classList.remove('bg-light');
    inp.focus();
    document.getElementById('kmBeforeNote').textContent = 'Đang nhập tay';
}

// ── Tính toán stats ─────────────────────────────────────────
function calcStats() {
    const kmBefore = parseFloat(document.getElementById('kmBefore').value) || 0;
    const kmAfter  = parseFloat(document.getElementById('kmAfter').value)  || 0;
    const liters   = parseFloat(document.getElementById('liters').value)   || 0;
    const amount   = parseFloat(document.getElementById('amount').value)   || 0;

    const kmDrivenBox  = document.getElementById('kmDrivenBox');
    const priceBox     = document.getElementById('priceBox');

    // Km đã đi
    if (kmBefore > 0 && kmAfter > kmBefore) {
        const kmDriven = kmAfter - kmBefore;
        document.getElementById('kmDrivenDisplay').textContent =
            new Intl.NumberFormat('vi-VN').format(kmDriven) + ' km';

        // Hiệu suất nhiên liệu
        if (liters > 0) {
            const eff = (liters / kmDriven * 100).toFixed(2);

            // So với định mức
            const quota = parseFloat(
                document.getElementById('vehicleSelect')
                    ?.selectedOptions[0]?.dataset.quota
            ) || 0;

            let effColor = 'text-success';
            let effIcon  = '✅';
            if (quota > 0) {
                if (eff > quota * 1.1) { effColor = 'text-danger'; effIcon = '⚠️'; }
                else if (eff > quota)  { effColor = 'text-warning'; effIcon = '🔶'; }
            }

            document.getElementById('efficiencyDisplay').innerHTML =
                `<span class="${effColor}">${effIcon} ${eff} L/100km</span>`;
        } else {
            document.getElementById('efficiencyDisplay').textContent = '—';
        }
        kmDrivenBox.style.display = 'block';
    } else {
        kmDrivenBox.style.display = 'none';
    }

    // Giá/lít
    if (liters > 0 && amount > 0) {
        const price = Math.round(amount / liters);
        document.getElementById('priceDisplay').textContent =
            new Intl.NumberFormat('vi-VN').format(price) + ' ₫/lít';
        priceBox.style.display = 'block';
    } else {
        priceBox.style.display = 'none';
    }
}

// ── Preview ảnh hóa đơn ─────────────────────────────────────
function previewReceipt(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('previewBox').style.display = 'block';
            document.getElementById('uploadArea').style.display = 'none';
            document.getElementById('removeImg').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    } else {
        // PDF
        document.getElementById('previewBox').style.display = 'none';
        document.getElementById('uploadArea').innerHTML =
            `<i class="fas fa-file-pdf fa-2x text-danger mb-1 d-block"></i>
             <div class="small fw-semibold">${file.name}</div>`;
        document.getElementById('removeImg').classList.remove('d-none');
    }
}

function removeReceipt() {
    document.getElementById('receiptInput').value = '';
    document.getElementById('previewBox').style.display = 'none';
    document.getElementById('uploadArea').style.display = 'block';
    document.getElementById('uploadArea').innerHTML = `
        <i class="fas fa-camera fa-2x text-muted mb-2 d-block"></i>
        <div class="small text-muted">Chụp ảnh hoặc chọn từ thư viện</div>
        <div class="small text-muted">JPG, PNG, PDF • Tối đa 5MB</div>`;
    document.getElementById('removeImg').classList.add('d-none');
}

// Chạy khi load nếu có xe được chọn sẵn
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('vehicleSelect');
    if (sel.value) loadKmBefore(sel.value);
});
</script>

<?php include 'includes/bottom_nav.php'; ?>