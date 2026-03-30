<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('fuel', 'create');

$pageTitle = 'Nhập nhiên liệu';
$pdo  = getDBConnection();
$user = currentUser();
$errors = [];

// Lấy driver_id từ user hiện tại
$driverStmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
$driverStmt->execute([$user['id']]);
$driver = $driverStmt->fetch();

// Nếu không phải driver thì dispatcher/admin nhập thay
$isDriver = hasRole('driver');
$driverId = $driver['id'] ?? null;

// Danh sách xe
$vehicles = $pdo->query("
    SELECT v.id, v.plate_number, vt.name AS type_name, v.fuel_quota
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.is_active = TRUE
    ORDER BY v.plate_number
")->fetchAll();

// Danh sách drivers (cho dispatcher/admin chọn)
$drivers = [];
if (!$isDriver) {
    $drivers = $pdo->query("
        SELECT d.id, u.full_name
        FROM drivers d JOIN users u ON d.user_id = u.id
        WHERE u.is_active = TRUE
        ORDER BY u.full_name
    ")->fetchAll();
}

// AJAX: lấy km_before
if (isset($_GET['get_km'])) {
    $vid = (int)$_GET['vehicle_id'];
    $did = $isDriver ? $driverId : (int)$_GET['driver_id'];
    $stmt = $pdo->prepare("
        SELECT km_after FROM fuel_logs
        WHERE vehicle_id = ? AND driver_id = ?
          AND km_after IS NOT NULL
        ORDER BY log_date DESC, id DESC LIMIT 1
    ");
    $stmt->execute([$vid, $did]);
    $km = $stmt->fetchColumn();
    header('Content-Type: application/json');
    echo json_encode(['km_before' => $km ?: null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId   = (int)($_POST['vehicle_id']  ?? 0);
    $selectedDid = $isDriver ? $driverId : (int)($_POST['driver_id'] ?? 0);
    $logDate     = $_POST['log_date']      ?? date('Y-m-d');
    $kmBefore    = $_POST['km_before'] !== '' ? (float)$_POST['km_before'] : null;
    $kmAfter     = $_POST['km_after']  !== '' ? (float)$_POST['km_after']  : null;
    $liters      = (float)($_POST['liters_filled'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $stationName = trim($_POST['station_name'] ?? '');
    $fuelType    = $_POST['fuel_type'] ?? 'diesel';
    $note        = trim($_POST['note'] ?? '');
    $receiptImg  = null;

    if (!$vehicleId)        $errors[] = 'Vui lòng chọn xe';
    if (!$selectedDid)      $errors[] = 'Vui lòng chọn lái xe';
    if ($liters <= 0)       $errors[] = 'Số lít phải lớn hơn 0';
    if ($amount <= 0)       $errors[] = 'Số tiền phải lớn hơn 0';
    if ($kmAfter !== null && $kmBefore !== null && $kmAfter <= $kmBefore)
        $errors[] = 'Km hiện tại phải lớn hơn Km lần trước';

    // Upload ảnh
    if (isset($_FILES['receipt_img']) && $_FILES['receipt_img']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['receipt_img'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','pdf'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'File không hợp lệ (JPG, PNG, PDF)';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'File quá lớn (tối đa 5MB)';
        } else {
            $uploadDir = dirname(__DIR__, 3) . '/uploads/fuel_receipts/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $fileName = 'fuel_' . $selectedDid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $fileName)) {
                $receiptImg = '/uploads/fuel_receipts/' . $fileName;
            } else {
                $errors[] = 'Lỗi upload, thử lại!';
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
            $selectedDid, $vehicleId, $logDate,
            $kmBefore, $kmAfter, $liters, $amount,
            $stationName ?: null, $fuelType,
            $receiptImg, $note ?: null, $user['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã lưu thông tin nhiên liệu!'];
        header('Location: index.php'); exit;
    }
}

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:800px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">⛽ Nhập nhiên liệu</h4>
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

    <form method="POST" enctype="multipart/form-data">

        <!-- Thông tin xe & lái xe -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-truck me-1 text-primary"></i> Thông tin xe
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-<?= $isDriver ? '6' : '4' ?>">
                        <label class="form-label fw-semibold">
                            Xe <span class="text-danger">*</span>
                        </label>
                        <select name="vehicle_id" id="vehicleSelect"
                                class="form-select" required
                                onchange="loadKmBefore()">
                            <option value="">-- Chọn xe --</option>
                            <?php foreach ($vehicles as $v): ?>
                            <option value="<?= $v['id'] ?>"
                                    data-quota="<?= $v['fuel_quota'] ?>"
                                    <?= ($_POST['vehicle_id']??'')==$v['id']?'selected':'' ?>>
                                <?= htmlspecialchars($v['plate_number']) ?>
                                (<?= htmlspecialchars($v['type_name']) ?>)
                                <?= $v['fuel_quota'] ? '- ĐM:'.$v['fuel_quota'].'L' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if (!$isDriver): ?>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Lái xe <span class="text-danger">*</span>
                        </label>
                        <select name="driver_id" id="driverSelect"
                                class="form-select" required
                                onchange="loadKmBefore()">
                            <option value="">-- Chọn lái xe --</option>
                            <?php foreach ($drivers as $d): ?>
                            <option value="<?= $d['id'] ?>"
                                <?= ($_POST['driver_id']??'')==$d['id']?'selected':'' ?>>
                                <?= htmlspecialchars($d['full_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-<?= $isDriver ? '3' : '2' ?>">
                        <label class="form-label fw-semibold">Ngày đổ</label>
                        <input type="date" name="log_date" class="form-control"
                               value="<?= $_POST['log_date'] ?? date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-<?= $isDriver ? '3' : '2' ?>">
                        <label class="form-label fw-semibold">Loại nhiên liệu</label>
                        <select name="fuel_type" class="form-select">
                            <option value="diesel" <?= ($_POST['fuel_type']??'diesel')==='diesel'?'selected':'' ?>>🛢️ Diesel</option>
                            <option value="ron95"  <?= ($_POST['fuel_type']??'')==='ron95' ?'selected':'' ?>>⛽ RON95</option>
                            <option value="ron92"  <?= ($_POST['fuel_type']??'')==='ron92' ?'selected':'' ?>>⛽ RON92</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Số Km -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-road me-1 text-warning"></i> Số Km đồng hồ
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Km lần trước
                        </label>
                        <input type="number" name="km_before" id="kmBefore"
                               class="form-control" step="0.1" min="0"
                               placeholder="Tự động lấy..."
                               value="<?= $_POST['km_before'] ?? '' ?>"
                               oninput="calcStats()">
                        <small class="text-muted" id="kmBeforeNote">
                            Tự động lấy từ lần đổ trước
                        </small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Km hiện tại
                        </label>
                        <input type="number" name="km_after" id="kmAfter"
                               class="form-control" step="0.1" min="0"
                               placeholder="Nhập Km đồng hồ"
                               value="<?= $_POST['km_after'] ?? '' ?>"
                               oninput="calcStats()">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Km đã đi</label>
                        <div class="form-control bg-light fw-bold text-primary"
                             id="kmDrivenDisplay">—</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nhiên liệu & Chi phí -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-gas-pump me-1 text-success"></i> Lượng xăng & Chi phí
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Số lít <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" name="liters_filled" id="liters"
                                   class="form-control" step="0.1" min="0.1"
                                   placeholder="0.0"
                                   value="<?= $_POST['liters_filled'] ?? '' ?>"
                                   oninput="calcStats()" required>
                            <span class="input-group-text">lít</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Số tiền <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <input type="number" name="amount" id="amount"
                                   class="form-control" step="1"
                                   placeholder="0"
                                   value="<?= $_POST['amount'] ?? '' ?>"
                                   oninput="calcStats()" required>
                            <span class="input-group-text">₫</span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Giá/lít</label>
                        <div class="form-control bg-light fw-bold text-success"
                             id="priceDisplay">—</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">
                            Hiệu suất thực tế
                        </label>
                        <div class="form-control bg-light fw-bold"
                             id="effDisplay">—</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Tên trạm xăng</label>
                        <input type="text" name="station_name" class="form-control"
                               placeholder="VD: Petrolimex Nguyễn Văn Linh..."
                               value="<?= htmlspecialchars($_POST['station_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <input type="text" name="note" class="form-control"
                               placeholder="Ghi chú thêm..."
                               value="<?= htmlspecialchars($_POST['note'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Ảnh hóa đơn -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-receipt me-1 text-info"></i> Ảnh hóa đơn
                    <small class="text-muted fw-normal">(JPG, PNG, PDF — tối đa 5MB)</small>
                </h6>
            </div>
            <div class="card-body">
                <!-- Preview -->
                <div id="previewBox" class="mb-3" style="display:none">
                    <img id="previewImg" src="" alt="Preview"
                         class="img-fluid rounded-3 border"
                         style="max-height:250px;object-fit:contain">
                </div>

                <label for="receiptInput" id="uploadArea"
                       class="d-block text-center p-4 rounded-3"
                       style="border:2px dashed #dee2e6;cursor:pointer;background:#fafafa">
                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2 d-block"></i>
                    <div class="text-muted">Click để chọn ảnh hóa đơn</div>
                    <div class="small text-muted">hoặc kéo thả vào đây</div>
                </label>
                <input type="file" name="receipt_img" id="receiptInput"
                       accept="image/*,application/pdf"
                       class="d-none"
                       onchange="previewReceipt(this)">

                <button type="button" class="btn btn-sm btn-outline-danger mt-2 d-none"
                        id="removeImg" onclick="removeReceipt()">
                    <i class="fas fa-times me-1"></i> Xóa ảnh
                </button>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> Lưu nhiên liệu
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
        </div>

    </form>
</div>
</div>

<script>
// ── Load km_before tự động ──
function loadKmBefore() {
    const vid = document.getElementById('vehicleSelect').value;
    const did = <?= $isDriver ? "'{$driverId}'" : "document.getElementById('driverSelect')?.value" ?>;
    if (!vid || !did) return;

    const note = document.getElementById('kmBeforeNote');
    note.textContent = '⏳ Đang tải...';

    fetch(`?get_km=1&vehicle_id=${vid}&driver_id=${did}`)
        .then(r => r.json())
        .then(data => {
            const inp = document.getElementById('kmBefore');
            if (data.km_before) {
                inp.value    = data.km_before;
                inp.readOnly = true;
                inp.classList.add('bg-light');
                note.innerHTML = `✅ Tự động từ lần đổ trước &nbsp;
                    <a href="#" onclick="unlockKm();return false">Sửa tay</a>`;
            } else {
                inp.value    = '';
                inp.readOnly = false;
                inp.classList.remove('bg-light');
                note.innerHTML = '<span class="text-warning">⚠️ Chưa có dữ liệu — nhập Km ban đầu</span>';
            }
            calcStats();
        });
}

function unlockKm() {
    const inp = document.getElementById('kmBefore');
    inp.readOnly = false;
    inp.classList.remove('bg-light');
    inp.focus();
    document.getElementById('kmBeforeNote').textContent = 'Đang nhập tay';
}

// ── Tính toán ──
function calcStats() {
    const kmB    = parseFloat(document.getElementById('kmBefore').value) || 0;
    const kmA    = parseFloat(document.getElementById('kmAfter').value)  || 0;
    const liters = parseFloat(document.getElementById('liters').value)   || 0;
    const amount = parseFloat(document.getElementById('amount').value)   || 0;
    const quota  = parseFloat(document.getElementById('vehicleSelect')
                    ?.selectedOptions[0]?.dataset.quota) || 0;

    // Km đã đi
    if (kmB > 0 && kmA > kmB) {
        const km = kmA - kmB;
        document.getElementById('kmDrivenDisplay').textContent =
            new Intl.NumberFormat('vi-VN').format(km) + ' km';

        // Hiệu suất
        if (liters > 0) {
            const eff = (liters / km * 100).toFixed(2);
            const el  = document.getElementById('effDisplay');
            el.textContent = eff + ' L/100km';
            if (quota > 0) {
                el.className = 'form-control fw-bold ' + (
                    eff > quota * 1.1 ? 'bg-danger text-white' :
                    eff > quota       ? 'bg-warning'           : 'bg-success text-white'
                );
            }
        }
    } else {
        document.getElementById('kmDrivenDisplay').textContent = '—';
    }

    // Giá/lít
    if (liters > 0 && amount > 0) {
        document.getElementById('priceDisplay').textContent =
            new Intl.NumberFormat('vi-VN').format(Math.round(amount / liters)) + ' ₫/lít';
    } else {
        document.getElementById('priceDisplay').textContent = '—';
    }
}

// ── Preview ảnh ──
function previewReceipt(input) {
    if (!input.files?.[0]) return;
    const file = input.files[0];
    if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('previewImg').src = e.target.result;
            document.getElementById('previewBox').style.display  = 'block';
            document.getElementById('uploadArea').style.display  = 'none';
        };
        reader.readAsDataURL(file);
    } else {
        document.getElementById('uploadArea').innerHTML =
            `<i class="fas fa-file-pdf fa-2x text-danger mb-1 d-block"></i>
             <div class="small fw-semibold">${file.name}</div>`;
    }
    document.getElementById('removeImg').classList.remove('d-none');
}

function removeReceipt() {
    document.getElementById('receiptInput').value = '';
    document.getElementById('previewBox').style.display = 'none';
    document.getElementById('uploadArea').style.display = 'block';
    document.getElementById('uploadArea').innerHTML = `
        <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2 d-block"></i>
        <div class="text-muted">Click để chọn ảnh hóa đơn</div>
        <div class="small text-muted">hoặc kéo thả vào đây</div>`;
    document.getElementById('removeImg').classList.add('d-none');
}
</script>

<?php include '../../../includes/footer.php'; ?>