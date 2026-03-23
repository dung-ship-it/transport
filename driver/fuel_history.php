<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /transport/dashboard.php'); exit; }

$pageTitle = 'Lịch sử xăng dầu';
$pdo  = getDBConnection();
$user = currentUser();

$driverStmt = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ?");
$driverStmt->execute([$user['id']]);
$driverId = $driverStmt->fetchColumn();

$filterMonth = $_GET['month'] ?? date('Y-m');
[$year, $month] = explode('-', $filterMonth);

$logs = $pdo->prepare("
    SELECT f.*, v.plate_number
    FROM fuel_logs f
    JOIN vehicles v ON f.vehicle_id = v.id
    WHERE f.driver_id = ?
      AND EXTRACT(MONTH FROM f.log_date) = ?
      AND EXTRACT(YEAR  FROM f.log_date) = ?
    ORDER BY f.log_date DESC, f.id DESC
");
$logs->execute([$driverId, (int)$month, (int)$year]);
$logs = $logs->fetchAll();

// Tổng tháng
$totalLiters = array_sum(array_column($logs, 'liters_filled'));
$totalAmount = array_sum(array_column($logs, 'amount'));
$totalKm     = array_sum(array_column($logs, 'km_driven'));

include 'includes/header.php';
?>

<div class="driver-topbar">
    <div class="d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="text-white"><i class="fas fa-arrow-left"></i></a>
            <div class="fw-bold">⛽ Lịch sử xăng dầu</div>
        </div>
        <a href="fuel_add.php" class="btn btn-sm btn-warning rounded-pill">
            + Nhập mới
        </a>
    </div>
</div>

<div class="px-3 pt-3">

    <!-- Lọc tháng -->
    <form method="GET" class="mb-3">
        <input type="month" name="month" class="form-control"
               value="<?= $filterMonth ?>"
               onchange="this.form.submit()">
    </form>

    <!-- Thống kê tháng -->
    <div class="row g-2 mb-3">
        <div class="col-4">
            <div class="driver-card text-center py-2">
                <div class="fw-bold text-primary fs-5">
                    <?= number_format($totalLiters, 1) ?>
                </div>
                <div class="text-muted" style="font-size:0.7rem">Tổng lít</div>
            </div>
        </div>
        <div class="col-4">
            <div class="driver-card text-center py-2">
                <div class="fw-bold text-danger fs-5">
                    <?= $totalAmount >= 1000000
                        ? number_format($totalAmount/1000000, 1) . 'M'
                        : number_format($totalAmount/1000, 0) . 'K' ?>
                </div>
                <div class="text-muted" style="font-size:0.7rem">Tổng tiền</div>
            </div>
        </div>
        <div class="col-4">
            <div class="driver-card text-center py-2">
                <div class="fw-bold text-success fs-5">
                    <?= number_format($totalKm, 0) ?>
                </div>
                <div class="text-muted" style="font-size:0.7rem">Tổng Km</div>
            </div>
        </div>
    </div>

    <!-- Danh sách -->
    <?php if (empty($logs)): ?>
    <div class="driver-card text-center py-4">
        <div style="font-size:2.5rem">⛽</div>
        <div class="text-muted mt-1">Chưa có dữ liệu tháng này</div>
        <a href="fuel_add.php" class="btn btn-warning btn-sm mt-2 rounded-pill">
            + Nhập xăng dầu
        </a>
    </div>
    <?php else: ?>
    <?php foreach ($logs as $log): ?>
    <div class="driver-card mb-2">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <div class="fw-semibold"><?= htmlspecialchars($log['plate_number']) ?></div>
                <div class="text-muted small">
                    <?= date('d/m/Y', strtotime($log['log_date'])) ?>
                    • <?= $log['fuel_type'] === 'diesel' ? '🛢️ Diesel' : '⛽ Xăng' ?>
                </div>
            </div>
            <div class="text-end">
                <?php if ($log['is_approved']): ?>
                <span class="badge bg-success">✅ Đã duyệt</span>
                <?php else: ?>
                <span class="badge bg-warning">⏳ Chờ duyệt</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-0 text-center">
            <div class="col-3 border-end">
                <div class="fw-bold text-primary"><?= $log['liters_filled'] ?></div>
                <div style="font-size:0.65rem;color:#6c757d">Lít</div>
            </div>
            <div class="col-3 border-end">
                <div class="fw-bold text-danger">
                    <?= number_format($log['amount']/1000, 0) ?>K
                </div>
                <div style="font-size:0.65rem;color:#6c757d">VNĐ</div>
            </div>
            <div class="col-3 border-end">
                <div class="fw-bold">
                    <?= $log['km_driven'] ? number_format($log['km_driven'], 0) : '—' ?>
                </div>
                <div style="font-size:0.65rem;color:#6c757d">Km</div>
            </div>
            <div class="col-3">
                <div class="fw-bold <?= $log['fuel_efficiency'] && $log['fuel_efficiency'] > 15 ? 'text-danger' : 'text-success' ?>">
                    <?= $log['fuel_efficiency'] ? $log['fuel_efficiency'] . 'L' : '—' ?>
                </div>
                <div style="font-size:0.65rem;color:#6c757d">/100km</div>
            </div>
        </div>

        <?php if ($log['receipt_img']): ?>
        <div class="mt-2 text-center">
            <a href="<?= htmlspecialchars($log['receipt_img']) ?>"
               target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">
                <i class="fas fa-receipt me-1"></i> Xem hóa đơn
            </a>
        </div>
        <?php endif; ?>

        <?php if ($log['station_name']): ?>
        <div class="mt-1 small text-muted">
            <i class="fas fa-map-marker-alt me-1"></i>
            <?= htmlspecialchars($log['station_name']) ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include 'includes/bottom_nav.php'; ?>