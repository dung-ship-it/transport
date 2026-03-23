<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/functions.php';
requireLogin();

$user = currentUser();
$role = $user['role'] ?? '';

// ── Redirect theo role ──────────────────────────────────────
if ($role === 'customer') {
    header('Location: /transport/customer/dashboard.php');
    exit;
}
if ($role === 'driver') {
    header('Location: /transport/driver/dashboard.php');
    exit;
}

// ── Từ đây chỉ còn admin / accountant / dispatcher / manager ─
$pageTitle = 'Dashboard';
$pdo       = getDBConnection();
$today        = date('Y-m-d');
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');

$stats = [];

$stats['trips_today'] = (int)$pdo->query("
    SELECT COUNT(*) FROM trips WHERE trip_date = '$today'
")->fetchColumn();

$stats['vehicles_running'] = (int)$pdo->query("
    SELECT COUNT(*) FROM trips WHERE status = 'in_progress'
")->fetchColumn();

$stats['pending_confirm'] = (int)$pdo->query("
    SELECT COUNT(*) FROM trips WHERE status = 'completed'
")->fetchColumn();

$stats['total_vehicles'] = (int)$pdo->query("
    SELECT COUNT(*) FROM vehicles WHERE status = 'active'
")->fetchColumn();

$stats['total_drivers'] = (int)$pdo->query("
    SELECT COUNT(*) FROM drivers WHERE is_active = TRUE
")->fetchColumn();

$stats['total_customers'] = (int)$pdo->query("
    SELECT COUNT(*) FROM customers WHERE is_active = TRUE
")->fetchColumn();

// Chuyến xe gần đây
$recentTrips = $pdo->query("
    SELECT t.*, c.company_name AS customer_name,
           u.full_name AS driver_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN vehicles v  ON t.vehicle_id  = v.id
    ORDER BY t.created_at DESC
    LIMIT 8
")->fetchAll();

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <button class="btn btn-dark d-md-none mb-3" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">
                Xin chào, <strong><?= htmlspecialchars($user['full_name']) ?></strong> 👋
            </h4>
            <p class="text-muted mb-0">
                <?php $badge = getRoleBadge($user['role']); ?>
                <span class="badge bg-<?= $badge['class'] ?>">
                    <?= $badge['icon'] ?> <?= $badge['label'] ?>
                </span>
                &nbsp; <?= date('l, d/m/Y') ?>
            </p>
        </div>
        <div class="text-end d-none d-md-block">
            <small class="text-muted">
                <i class="fas fa-clock me-1"></i>
                <span id="liveClock"></span>
            </small>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100 bg-primary text-white">
                <div class="fs-2 fw-bold"><?= $stats['trips_today'] ?></div>
                <div class="small opacity-75">🚛 Chuyến hôm nay</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100 bg-success text-white">
                <div class="fs-2 fw-bold"><?= $stats['vehicles_running'] ?></div>
                <div class="small opacity-75">🟢 Đang chạy</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100 bg-warning">
                <div class="fs-2 fw-bold"><?= $stats['pending_confirm'] ?></div>
                <div class="small">⏳ Chờ xác nhận</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100">
                <div class="fs-2 fw-bold text-info"><?= $stats['total_vehicles'] ?></div>
                <div class="small text-muted">🚚 Tổng xe</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100">
                <div class="fs-2 fw-bold text-secondary"><?= $stats['total_drivers'] ?></div>
                <div class="small text-muted">👨‍✈️ Lái xe</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3 h-100">
                <div class="fs-2 fw-bold text-danger"><?= $stats['total_customers'] ?></div>
                <div class="small text-muted">🏢 Khách hàng</div>
            </div>
        </div>
    </div>

    <!-- Chuyến gần đây -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-bold border-0 pt-3">
            <i class="fas fa-history me-2 text-primary"></i>Chuyến xe gần đây
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Mã chuyến</th>
                            <th>Ngày</th>
                            <th>Khách hàng</th>
                            <th>Xe</th>
                            <th>Lái xe</th>
                            <th>Tuyến</th>
                            <th class="text-end">KM</th>
                            <th class="text-center">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $statusColors = [
                        'scheduled'   => 'warning',
                        'in_progress' => 'primary',
                        'completed'   => 'success',
                        'confirmed'   => 'info',
                        'rejected'    => 'danger',
                    ];
                    $statusLabels = [
                        'scheduled'   => 'Chờ xuất phát',
                        'in_progress' => 'Đang chạy',
                        'completed'   => 'Hoàn thành',
                        'confirmed'   => 'Đã xác nhận',
                        'rejected'    => 'Từ chối',
                    ];
                    foreach ($recentTrips as $t):
                        $sc = $statusColors[$t['status']] ?? 'secondary';
                        $sl = $statusLabels[$t['status']] ?? $t['status'];
                    ?>
                    <tr>
                        <td class="ps-3">
                            <code class="text-primary">
                                <?= htmlspecialchars($t['trip_code'] ?? '#'.$t['id']) ?>
                            </code>
                        </td>
                        <td><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
                        <td class="small"><?= htmlspecialchars($t['customer_name']) ?></td>
                        <td class="fw-bold text-primary small"><?= htmlspecialchars($t['plate_number']) ?></td>
                        <td class="small"><?= htmlspecialchars($t['driver_name']) ?></td>
                        <td class="small text-muted">
                            <?= htmlspecialchars(mb_substr($t['pickup_location'] ?? '', 0, 15)) ?>
                            <?php if (!empty($t['dropoff_location'])): ?>
                            → <?= htmlspecialchars(mb_substr($t['dropoff_location'] ?? '', 0, 15)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small">
                            <?= $t['total_km'] ? number_format($t['total_km'], 0).' km' : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<script>
// Live clock
setInterval(() => {
    const now = new Date();
    const el  = document.getElementById('liveClock');
    if (el) el.textContent = now.toLocaleTimeString('vi-VN');
}, 1000);
</script>

<?php include 'includes/footer.php'; ?>