<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) {
    header('Location: /dashboard.php'); exit;
}

$pageTitle = 'Trang chủ';
$pdo  = getDBConnection();
$user = currentUser();
$today        = date('Y-m-d');
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');

// Lấy driver_id
$driver = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
$driver->execute([$user['id']]);
$driver = $driver->fetch();
$driverId = $driver['id'] ?? 0;

// Chuyến hôm nay
$tripsToday = $pdo->prepare("
    SELECT t.*, c.company_name AS customer_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN vehicles  v ON t.vehicle_id  = v.id
    WHERE t.driver_id = ? AND t.trip_date = ?
    ORDER BY t.departure_time
");
$tripsToday->execute([$driverId, $today]);
$tripsToday = $tripsToday->fetchAll();

// Thống kê tháng này
// ✅ MỚI — tính theo kỳ 26 tháng trước → 25 tháng này
$today = date('Y-m-d');
$day   = (int)date('d');

if ($day >= 26) {
    // Đã qua ngày 26: kỳ mới bắt đầu (26 tháng này → 25 tháng sau)
    $kyCurrent = date('Y-m-26');
    $kyEnd     = date('Y-m-25', strtotime('+1 month'));
} else {
    // Chưa tới ngày 26: kỳ hiện tại (26 tháng trước → 25 tháng này)
    $kyCurrent = date('Y-m-26', strtotime('-1 month'));
    $kyEnd     = date('Y-m-25');
}

$statsMonth = $pdo->prepare("
    SELECT
        COUNT(*)                                          AS total_trips,
        COUNT(*) FILTER (WHERE status = 'completed')     AS completed,
        COUNT(*) FILTER (WHERE status = 'in_progress')   AS in_progress,
        COALESCE(SUM(total_km),0)                        AS total_km
    FROM trips
    WHERE driver_id = ?
      AND trip_date BETWEEN ? AND ?
");
$statsMonth->execute([$driverId, $kyCurrent, $kyEnd]);
$stats = $statsMonth->fetch();

// Chuyến sắp tới (3 ngày tới)
$upcoming = $pdo->prepare("
    SELECT t.*, c.company_name AS customer_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN vehicles  v ON t.vehicle_id  = v.id
    WHERE t.driver_id = ?
      AND t.trip_date > ?
      AND t.trip_date <= (CURRENT_DATE + INTERVAL '3 days')
      AND t.status = 'scheduled'
    ORDER BY t.trip_date, t.departure_time
");
$upcoming->execute([$driverId, $today]);
$upcoming = $upcoming->fetchAll();

include 'includes/header.php';
?>

<!-- Top Bar -->
<div class="driver-topbar">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <?php
            $nameParts = explode(' ', trim($user['full_name']));
            $firstName = end($nameParts);
            ?>
            <div class="fw-bold fs-6">Xin chào, <?= htmlspecialchars($firstName) ?> 👋</div>
            <div style="font-size:0.75rem;opacity:0.75"><?= date('l, d/m/Y') ?></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-success">🚗 Lái Xe</span>
            <a href="/logout.php" class="text-white opacity-75">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</div>

<div class="px-3 pt-3">

    <?php showFlash(); ?>

    <!-- Stat Cards -->
    <div class="row g-2 mb-3">
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#0f3460,#1a4a8a)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['total_trips'] ?></div>
                <div style="font-size:0.75rem;opacity:0.85">
    Chuyến 26/<?= date('m', strtotime('-1 month')) ?>
    — 25/<?= date('m') ?>
</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#198754,#20a060)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['completed'] ?></div>
                <div style="font-size:0.75rem;opacity:0.85">Đã hoàn thành</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#fd7e14,#e8650a)">
                <div style="font-size:1.8rem;font-weight:700">
                    <?= number_format((float)$stats['total_km'], 0) ?>
                </div>
                <div style="font-size:0.75rem;opacity:0.85">Km tháng này</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#0dcaf0,#0aa8cc)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['in_progress'] ?></div>
                <div style="font-size:0.75rem;opacity:0.85">Đang chạy</div>
            </div>
        </div>
    </div>

    <!-- Chuyến hôm nay -->
    <div class="section-title">📅 Chuyến xe hôm nay</div>
    <?php if (empty($tripsToday)): ?>
    <div class="driver-card text-center py-3">
        <div style="font-size:2.5rem">😴</div>
        <div class="text-muted mt-1">Không có chuyến nào hôm nay</div>
        <a href="/driver/trip_create.php" class="btn btn-primary btn-sm mt-2 rounded-pill">
            + Tạo chuyến mới
        </a>
    </div>
    <?php else: ?>
    <?php
    $statusColors = [
        'scheduled'   => 'warning',
        'in_progress' => 'primary',
        'completed'   => 'success',
        'confirmed'   => 'info',
        'rejected'    => 'danger',
    ];
    $statusLabels = [
        'scheduled'   => '📅 Chờ xuất phát',
        'in_progress' => '🚛 Đang chạy',
        'completed'   => '✅ Hoàn thành',
        'confirmed'   => '👍 Đã confirm',
        'rejected'    => '❌ Từ chối',
    ];
    foreach ($tripsToday as $trip):
        $sc   = $statusColors[$trip['status']] ?? 'secondary';
        $sl   = $statusLabels[$trip['status']] ?? $trip['status'];
        // Dùng pickup_location/dropoff_location, fallback sang route_from/route_to nếu có
        $from = $trip['pickup_location'] ?? $trip['route_from'] ?? '';
        $to   = $trip['dropoff_location'] ?? $trip['route_to']  ?? '';
    ?>
    <a href="/driver/trip_detail.php?id=<?= $trip['id'] ?>"
       class="text-decoration-none">
        <div class="trip-card status-<?= $trip['status'] ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <code class="text-primary fw-bold">
                    <?= htmlspecialchars($trip['trip_code']) ?>
                </code>
                <span class="badge bg-<?= $sc ?> status-badge"><?= $sl ?></span>
            </div>
            <div class="fw-semibold mb-1">
                <?= htmlspecialchars($trip['customer_name']) ?>
            </div>
            <?php if ($from || $to): ?>
            <div class="d-flex align-items-center gap-1 text-muted small mb-2">
                <i class="fas fa-map-marker-alt text-danger"></i>
                <?= htmlspecialchars($from) ?>
                <i class="fas fa-arrow-right mx-1"></i>
                <i class="fas fa-map-marker-alt text-success"></i>
                <?= htmlspecialchars($to) ?>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-3 small text-muted">
                <span>
                    <i class="fas fa-clock me-1"></i>
                    <?= !empty($trip['departure_time'])
                        ? substr($trip['departure_time'], 0, 5)
                        : '--:--' ?>
                </span>
                <span>
                    <i class="fas fa-car me-1"></i>
                    <?= htmlspecialchars($trip['plate_number']) ?>
                </span>
                <?php if (!empty($trip['total_km'])): ?>
                <span>
                    <i class="fas fa-road me-1"></i>
                    <?= number_format($trip['total_km'], 0) ?> km
                </span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Chuyến sắp tới -->
    <?php if (!empty($upcoming)): ?>
    <div class="section-title">🔜 Sắp tới (3 ngày)</div>
    <?php foreach ($upcoming as $trip):
        $from = $trip['pickup_location'] ?? $trip['route_from'] ?? '';
        $to   = $trip['dropoff_location'] ?? $trip['route_to']  ?? '';
    ?>
    <a href="/driver/trip_detail.php?id=<?= $trip['id'] ?>"
       class="text-decoration-none">
        <div class="trip-card" style="border-left-color:#6c757d">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <code class="text-secondary">
                    <?= htmlspecialchars($trip['trip_code']) ?>
                </code>
                <small class="text-muted">
                    <?= date('d/m', strtotime($trip['trip_date'])) ?>
                </small>
            </div>
            <div class="fw-semibold small">
                <?= htmlspecialchars($trip['customer_name']) ?>
            </div>
            <?php if ($from || $to): ?>
            <div class="text-muted small">
                <?= htmlspecialchars($from) ?>
                <?php if ($from && $to): ?> → <?php endif; ?>
                <?= htmlspecialchars($to) ?>
            </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="section-title">⚡ Thao tác nhanh</div>
    <div class="row g-2 mb-3">
        <div class="col-6">
            <a href="/driver/trip_create.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">🚛</div>
                    <div class="small fw-semibold mt-1">Tạo chuyến xe</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/fuel_add.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">⛽</div>
                    <div class="small fw-semibold mt-1">Nhập xăng dầu</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/trips.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">📋</div>
                    <div class="small fw-semibold mt-1">Lịch sử chuyến</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/profile.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">👤</div>
                    <div class="small fw-semibold mt-1">Hồ sơ của tôi</div>
                </div>
            </a>
        </div>
    </div>

</div>

<?php include 'includes/bottom_nav.php'; ?>