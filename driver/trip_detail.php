<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /dashboard.php'); exit; }

$pdo  = getDBConnection();
$user = currentUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: trips.php'); exit; }

// Lấy driver_id
$driverStmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ?");
$driverStmt->execute([$user['id']]);
$driverId = $driverStmt->fetchColumn();

// Load trip — chỉ lấy trip của chính lái xe này
$stmt = $pdo->prepare("
    SELECT t.*,
           c.company_name AS customer_name,
           c.short_name   AS customer_short,
           v.plate_number, v.capacity, v.vehicle_type_id,
           vt.name        AS vehicle_type_name
    FROM trips t
    JOIN customers c    ON t.customer_id   = c.id
    JOIN vehicles v     ON t.vehicle_id    = v.id
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE t.id = ? AND t.driver_id = ?
");
$stmt->execute([$id, $driverId]);
$trip = $stmt->fetch();

if (!$trip) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy chuyến xe!'];
    header('Location: trips.php'); exit;
}

// Xử lý action (cập nhật trạng thái)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Bắt đầu chuyến
    if ($action === 'start' && $trip['status'] === 'scheduled') {
        $odoStart = (int)$_POST['odometer_start'];
        $pdo->prepare("
            UPDATE trips SET
                status          = 'in_progress',
                odometer_start  = ?,
                departure_time  = NOW(),
                updated_at      = NOW()
            WHERE id = ? AND driver_id = ?
        ")->execute([$odoStart, $id, $driverId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'🚛 Đã bắt đầu chuyến!'];
        header("Location: trip_detail.php?id=$id"); exit;
    }

    // Hoàn thành chuyến
    if ($action === 'complete' && $trip['status'] === 'in_progress') {
        $odoEnd  = (int)$_POST['odometer_end'];
        $tollFee = (float)str_replace(',', '', $_POST['toll_fee'] ?? '0');
        $note    = trim($_POST['note'] ?? '');

        $odoStart = (float)$trip['odometer_start'];
        $totalKm  = $odoStart > 0 ? max(0, $odoEnd - $odoStart) : null;

        // Kiểm tra ngày chủ nhật
        $isSunday = (date('w', strtotime($trip['trip_date'])) == 0) ? 1 : 0;

        $pdo->prepare("
            UPDATE trips SET
                status         = 'completed',
                odometer_end   = ?,
                total_km       = ?,
                toll_fee       = ?,
                note           = ?,
                is_sunday      = ?,
                arrival_time   = NOW(),
                updated_at     = NOW()
            WHERE id = ? AND driver_id = ?
        ")->execute([$odoEnd, $totalKm, $tollFee, $note, $isSunday, $id, $driverId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã hoàn thành chuyến!'];
        header("Location: trip_detail.php?id=$id"); exit;
    }

    // Cập nhật ghi chú (khi đang in_progress)
    if ($action === 'update_note') {
        $note = trim($_POST['note'] ?? '');
        $pdo->prepare("
            UPDATE trips SET note = ?, updated_at = NOW()
            WHERE id = ? AND driver_id = ?
        ")->execute([$note, $id, $driverId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã lưu ghi chú!'];
        header("Location: trip_detail.php?id=$id"); exit;
    }
}

// Reload sau POST
$stmt->execute([$id, $driverId]);
$trip = $stmt->fetch();

$statusConfig = [
    'draft'       => ['secondary', '📝 Draft',          false],
    'scheduled'   => ['warning',   '📅 Chờ xuất phát',  true],
    'in_progress' => ['primary',   '🚛 Đang chạy',      true],
    'completed'   => ['success',   '✅ Hoàn thành',     false],
    'confirmed'   => ['info',      '👍 Đã xác nhận',    false],
    'rejected'    => ['danger',    '❌ Bị từ chối',     false],
];
[$sCls, $sLbl] = $statusConfig[$trip['status']] ?? ['secondary', $trip['status']];

$from = $trip['pickup_location']  ?? $trip['route_from']  ?? '—';
$to   = $trip['dropoff_location'] ?? $trip['route_to']    ?? '—';

include 'includes/header.php';
?>

<!-- Top Bar -->
<div class="driver-topbar d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center gap-2">
        <a href="trips.php" class="text-white">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div class="fw-bold">Chi tiết chuyến xe</div>
    </div>
    <span class="badge bg-<?= $sCls ?> fs-6"><?= $sLbl ?></span>
</div>

<div class="px-3 pt-3 pb-5">

    <?php showFlash(); ?>

    <!-- Mã chuyến + Khách hàng -->
    <div class="driver-card mb-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <code class="text-primary fw-bold fs-6"><?= htmlspecialchars($trip['trip_code']) ?></code>
                <?php if ($trip['is_sunday']): ?>
                <span class="badge bg-warning ms-1">☀️ Chủ nhật</span>
                <?php endif; ?>
            </div>
            <div class="text-muted small">
                <?= date('d/m/Y', strtotime($trip['trip_date'])) ?>
            </div>
        </div>
        <div class="fw-bold fs-6 mb-1">
            <?= htmlspecialchars($trip['customer_short'] ?: $trip['customer_name']) ?>
        </div>
        <div class="text-muted small">
            <i class="fas fa-car me-1"></i>
            <?= htmlspecialchars($trip['plate_number']) ?>
            <?= $trip['capacity'] ? '· ' . $trip['capacity'] . ' tấn' : '' ?>
            <?php if ($trip['vehicle_type_name']): ?>
            · <?= htmlspecialchars($trip['vehicle_type_name']) ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tuyến đường -->
    <div class="driver-card mb-3">
        <div class="small fw-semibold text-muted mb-2">
            <i class="fas fa-route me-1"></i>TUYẾN ĐƯỜNG
        </div>
        <div class="d-flex align-items-start gap-3">
            <div class="d-flex flex-column align-items-center">
                <div style="width:12px;height:12px;border-radius:50%;background:#dc3545;flex-shrink:0"></div>
                <div style="width:2px;height:30px;background:#dee2e6"></div>
                <div style="width:12px;height:12px;border-radius:50%;background:#198754;flex-shrink:0"></div>
            </div>
            <div>
                <div class="fw-semibold text-uppercase mb-2">
                    <?= htmlspecialchars($from) ?>
                </div>
                <div class="fw-semibold text-uppercase">
                    <?= htmlspecialchars($to) ?>
                </div>
            </div>
        </div>
        <?php if ($trip['total_km']): ?>
        <div class="mt-2 pt-2 border-top d-flex gap-3 small text-muted">
            <span><i class="fas fa-road me-1 text-primary"></i>
                <strong class="text-primary"><?= number_format($trip['total_km'], 0) ?> km</strong>
            </span>
            <?php if ($trip['toll_fee']): ?>
            <span><i class="fas fa-money-bill me-1 text-warning"></i>
                Cầu đường: <strong><?= number_format($trip['toll_fee'], 0, '.', ',') ?> ₫</strong>
            </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Đồng hồ & Thời gian -->
    <div class="driver-card mb-3">
        <div class="small fw-semibold text-muted mb-2">
            <i class="fas fa-tachometer-alt me-1"></i>ĐỒNG HỒ & THỜI GIAN
        </div>
        <div class="row g-2">
            <div class="col-6">
                <div class="p-2 rounded" style="background:#f8f9fa">
                    <div class="small text-muted">Km đi</div>
                    <div class="fw-bold">
                        <?= $trip['odometer_start']
                            ? number_format($trip['odometer_start'], 0) . ' km'
                            : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded" style="background:#f8f9fa">
                    <div class="small text-muted">Km về</div>
                    <div class="fw-bold">
                        <?= $trip['odometer_end']
                            ? number_format($trip['odometer_end'], 0) . ' km'
                            : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded" style="background:#f8f9fa">
                    <div class="small text-muted">Giờ xuất phát</div>
                    <div class="fw-bold">
                        <?= $trip['departure_time']
                            ? date('H:i d/m', strtotime($trip['departure_time']))
                            : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-2 rounded" style="background:#f8f9fa">
                    <div class="small text-muted">Giờ kết thúc</div>
                    <div class="fw-bold">
                        <?= $trip['arrival_time']
                            ? date('H:i d/m', strtotime($trip['arrival_time']))
                            : '<span class="text-muted">—</span>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trạng thái duyệt (nếu đã hoàn thành) -->
    <?php if (in_array($trip['status'], ['confirmed', 'rejected'])): ?>
    <div class="driver-card mb-3
        <?= $trip['status']==='confirmed' ? 'border-success' : 'border-danger' ?>
        border-start border-4">
        <div class="small fw-semibold text-muted mb-2">
            <i class="fas fa-check-circle me-1"></i>KẾT QUẢ DUYỆT
        </div>
        <?php if ($trip['status'] === 'confirmed'): ?>
        <div class="text-success fw-bold">✅ Khách hàng đã xác nhận chuyến này</div>
        <?php if ($trip['confirmed_at']): ?>
        <div class="text-muted small mt-1">
            <?= date('H:i d/m/Y', strtotime($trip['confirmed_at'])) ?>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="text-danger fw-bold">❌ Khách hàng từ chối chuyến này</div>
        <?php if ($trip['rejection_reason']): ?>
        <div class="mt-2 p-2 rounded" style="background:#fff3f3;font-size:0.85rem">
            <strong>Lý do:</strong> <?= htmlspecialchars($trip['rejection_reason']) ?>
        </div>
        <?php endif; ?>
        <?php if ($trip['rejected_at']): ?>
        <div class="text-muted small mt-1">
            <?= date('H:i d/m/Y', strtotime($trip['rejected_at'])) ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Ghi chú -->
    <?php if ($trip['note'] && !in_array($trip['status'], ['scheduled','in_progress'])): ?>
    <div class="driver-card mb-3">
        <div class="small fw-semibold text-muted mb-1">
            <i class="fas fa-sticky-note me-1"></i>GHI CHÚ
        </div>
        <div class="small"><?= nl2br(htmlspecialchars($trip['note'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- ══ ACTION: Bắt đầu chuyến ══════════════════════════ -->
    <?php if ($trip['status'] === 'scheduled'): ?>
    <div class="driver-card mb-3 border-warning border-start border-4">
        <div class="fw-semibold mb-3">
            <i class="fas fa-play-circle text-warning me-1"></i>
            Bắt đầu chuyến xe
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="start">
            <div class="mb-3">
                <label class="form-label small fw-semibold">
                    Số Km đồng hồ lúc xuất phát
                    <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <input type="number" name="odometer_start"
                           class="form-control form-control-lg"
                           placeholder="VD: 125000"
                           min="0" required
                           style="font-size:1.2rem;font-weight:bold">
                    <span class="input-group-text">km</span>
                </div>
                <small class="text-muted">Nhập số km hiện tại trên đồng hồ xe</small>
            </div>
            <button type="submit"
                    class="btn btn-warning w-100 py-3 fw-bold fs-5 rounded-pill"
                    onclick="return confirm('Xác nhận bắt đầu chuyến?')">
                <i class="fas fa-play me-2"></i>BẮT ĐẦU CHUYẾN
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══ ACTION: Hoàn thành chuyến ═══════════════════════ -->
    <?php if ($trip['status'] === 'in_progress'): ?>
    <div class="driver-card mb-3 border-primary border-start border-4">
        <div class="fw-semibold mb-3">
            <i class="fas fa-flag-checkered text-primary me-1"></i>
            Hoàn thành chuyến xe
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="complete">
            <div class="mb-3">
                <label class="form-label small fw-semibold">
                    Số Km đồng hồ khi về
                    <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <input type="number" name="odometer_end"
                           class="form-control form-control-lg"
                           placeholder="VD: 125350"
                           min="<?= (int)$trip['odometer_start'] ?>"
                           required
                           style="font-size:1.2rem;font-weight:bold"
                           oninput="calcKm(this.value)">
                    <span class="input-group-text">km</span>
                </div>
                <?php if ($trip['odometer_start']): ?>
                <div class="mt-1 small">
                    <span class="text-muted">Km đi: </span>
                    <strong><?= number_format($trip['odometer_start'], 0) ?> km</strong>
                    <span class="ms-2 text-primary fw-bold" id="kmPreview"></span>
                </div>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">
                    Vé cầu đường (nếu có)
                </label>
                <div class="input-group">
                    <input type="number" name="toll_fee"
                           class="form-control"
                           placeholder="0"
                           min="0" step="1000"
                           value="<?= (int)($trip['toll_fee'] ?? 0) ?>">
                    <span class="input-group-text">₫</span>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold">Ghi chú</label>
                <textarea name="note" class="form-control" rows="3"
                          placeholder="Ghi chú thêm (nếu có)..."><?= htmlspecialchars($trip['note'] ?? '') ?></textarea>
            </div>
            <button type="submit"
                    class="btn btn-primary w-100 py-3 fw-bold fs-5 rounded-pill"
                    onclick="return confirm('Xác nhận hoàn thành chuyến?')">
                <i class="fas fa-check-circle me-2"></i>HOÀN THÀNH CHUYẾN
            </button>
        </form>
    </div>

    <!-- Cập nhật ghi chú riêng -->
    <div class="driver-card mb-3">
        <div class="small fw-semibold text-muted mb-2">
            <i class="fas fa-sticky-note me-1"></i>CẬP NHẬT GHI CHÚ
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_note">
            <textarea name="note" class="form-control form-control-sm mb-2" rows="2"
                      placeholder="Ghi chú..."><?= htmlspecialchars($trip['note'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                <i class="fas fa-save me-1"></i>Lưu ghi chú
            </button>
        </form>
    </div>
    <?php endif; ?>

    <!-- ══ Thông tin thêm (completed/confirmed) ═════════════ -->
    <?php if (in_array($trip['status'], ['completed','confirmed','rejected'])): ?>
    <div class="driver-card mb-3">
        <div class="small fw-semibold text-muted mb-2">
            <i class="fas fa-info-circle me-1"></i>TỔNG KẾT CHUYẾN
        </div>
        <div class="row g-2 text-center">
            <div class="col-4">
                <div class="p-2 rounded" style="background:#e8f4fd">
                    <div class="fw-bold text-primary fs-5">
                        <?= $trip['total_km'] ? number_format($trip['total_km'], 0) : '—' ?>
                    </div>
                    <div class="small text-muted">km</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded" style="background:#fff3cd">
                    <div class="fw-bold text-warning fs-5">
                        <?= $trip['toll_fee'] ? number_format($trip['toll_fee'], 0, '.', ',') : '—' ?>
                    </div>
                    <div class="small text-muted">₫ cầu đường</div>
                </div>
            </div>
            <div class="col-4">
                <div class="p-2 rounded" style="background:#d1e7dd">
                    <div class="fw-bold text-success fs-5">
                        <?php
                        if ($trip['departure_time'] && $trip['arrival_time']) {
                            $diff = strtotime($trip['arrival_time']) - strtotime($trip['departure_time']);
                            $h = floor($diff / 3600);
                            $m = floor(($diff % 3600) / 60);
                            echo $h . 'h' . ($m > 0 ? $m . 'm' : '');
                        } else {
                            echo '—';
                        }
                        ?>
                    </div>
                    <div class="small text-muted">thời gian</div>
                </div>
            </div>
        </div>
        <?php if ($trip['note']): ?>
        <div class="mt-2 pt-2 border-top small text-muted">
            <i class="fas fa-sticky-note me-1"></i>
            <?= nl2br(htmlspecialchars($trip['note'])) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Nút quay lại -->
    <a href="trips.php" class="btn btn-outline-secondary w-100 rounded-pill">
        <i class="fas fa-arrow-left me-1"></i>Quay lại danh sách
    </a>

</div>

<script>
const odoStart = <?= (int)($trip['odometer_start'] ?? 0) ?>;

function calcKm(val) {
    const preview = document.getElementById('kmPreview');
    if (!preview) return;
    const end = parseInt(val);
    if (odoStart > 0 && end > odoStart) {
        const km = end - odoStart;
        preview.textContent = '→ Tổng: ' + km.toLocaleString() + ' km';
        preview.style.color = '#0d6efd';
    } else if (end <= odoStart && val !== '') {
        preview.textContent = '⚠️ Km về phải lớn hơn Km đi';
        preview.style.color = '#dc3545';
    } else {
        preview.textContent = '';
    }
}
</script>

<?php include 'includes/bottom_nav.php'; ?>