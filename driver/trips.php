<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /dashboard.php'); exit; }

$pageTitle = 'Chuyến xe của tôi';
$pdo  = getDBConnection();
$user = currentUser();

$driver = $pdo->prepare("SELECT id FROM drivers WHERE user_id = ?");
$driver->execute([$user['id']]);
$driverId = $driver->fetchColumn();

$filterStatus = $_GET['status'] ?? '';
$filterMonth  = $_GET['month']  ?? date('Y-m');

[$year, $month] = explode('-', $filterMonth);

$where  = ['t.driver_id = ?', "EXTRACT(MONTH FROM t.trip_date) = ?", "EXTRACT(YEAR FROM t.trip_date) = ?"];
$params = [$driverId, (int)$month, (int)$year];

if ($filterStatus) {
    $where[]  = 't.status = ?';
    $params[] = $filterStatus;
}

$whereStr = implode(' AND ', $where);

$trips = $pdo->prepare("
    SELECT t.*, c.company_name AS customer_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN vehicles  v ON t.vehicle_id  = v.id
    WHERE $whereStr
    ORDER BY t.trip_date DESC, t.departure_time DESC
");
$trips->execute($params);
$trips = $trips->fetchAll();

include 'includes/header.php';
?>

<div class="driver-topbar">
    <div class="d-flex justify-content-between align-items-center">
        <div class="fw-bold">📋 Chuyến xe của tôi</div>
        <a href="/driver/trip_create.php" class="btn btn-sm btn-success rounded-pill">
            + Tạo chuyến
        </a>
    </div>
</div>

<div class="px-3 pt-3">

    <!-- Bộ lọc -->
    <div class="driver-card p-2 mb-3">
        <form method="GET" class="d-flex gap-2">
            <input type="month" name="month" class="form-control form-control-sm"
                   value="<?= $filterMonth ?>" style="flex:1">
            <select name="status" class="form-select form-select-sm" style="flex:1">
                <option value="">Tất cả</option>
                <option value="scheduled"   <?= $filterStatus==='scheduled'   ?'selected':'' ?>>Chờ xuất phát</option>
                <option value="in_progress" <?= $filterStatus==='in_progress' ?'selected':'' ?>>Đang chạy</option>
                <option value="completed"   <?= $filterStatus==='completed'   ?'selected':'' ?>>Hoàn thành</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Lọc</button>
        </form>
    </div>

    <div class="text-muted small mb-2">
        Tháng <?= $month ?>/<?= $year ?> — <strong><?= count($trips) ?></strong> chuyến
    </div>

    <?php if (empty($trips)): ?>
    <div class="driver-card text-center py-4">
        <div style="font-size:3rem">🚫</div>
        <div class="text-muted mt-2">Không có chuyến nào</div>
    </div>
    <?php else: ?>
    <?php
    $statusColors = [
        'scheduled'   => 'warning',
        'in_progress' => 'primary',
        'completed'   => 'success',
        'confirmed'   => 'info',
        'cancelled'   => 'danger',
        'rejected'    => 'danger',
    ];
    $statusLabels = [
        'scheduled'   => '📅 Chờ xuất phát',
        'in_progress' => '🚛 Đang chạy',
        'completed'   => '✅ Hoàn thành',
        'confirmed'   => '👍 Đã confirm',
        'cancelled'   => '❌ Đã hủy',
        'rejected'    => '❌ Từ chối',
    ];
    foreach ($trips as $trip):
        $sc = $statusColors[$trip['status']] ?? 'secondary';
        $sl = $statusLabels[$trip['status']] ?? $trip['status'];
        // Dùng pickup_location/dropoff_location, fallback sang route_from/route_to nếu có
        $from = $trip['pickup_location'] ?? $trip['route_from'] ?? '';
        $to   = $trip['dropoff_location'] ?? $trip['route_to']  ?? '';
    ?>
    <a href="/driver/trip_detail.php?id=<?= $trip['id'] ?>"
       class="text-decoration-none">
        <div class="trip-card status-<?= $trip['status'] ?>">
            <div class="d-flex justify-content-between mb-1">
                <code class="text-primary"><?= htmlspecialchars($trip['trip_code']) ?></code>
                <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
            </div>
            <div class="fw-semibold"><?= htmlspecialchars($trip['customer_name']) ?></div>
            <div class="text-muted small my-1">
                <?= htmlspecialchars($from) ?>
                <?php if ($from || $to): ?>
                <i class="fas fa-arrow-right mx-1"></i>
                <?php endif; ?>
                <?= htmlspecialchars($to) ?>
            </div>
            <div class="d-flex justify-content-between small text-muted">
                <span>
                    <i class="fas fa-calendar me-1"></i>
                    <?= date('d/m/Y', strtotime($trip['trip_date'])) ?>
                </span>
                <span>
                    <i class="fas fa-car me-1"></i>
                    <?= htmlspecialchars($trip['plate_number']) ?>
                </span>
            </div>
            <?php if (!empty($trip['total_km'])): ?>
            <div class="text-muted small mt-1">
                <i class="fas fa-road me-1"></i><?= number_format($trip['total_km'], 0) ?> km
            </div>
            <?php endif; ?>
            <?php if ($trip['status'] === 'rejected' && !empty($trip['rejection_reason'])): ?>
            <div class="text-danger small mt-1">
                ❌ <?= htmlspecialchars(mb_substr($trip['rejection_reason'], 0, 60)) ?>
            </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include 'includes/bottom_nav.php'; ?>