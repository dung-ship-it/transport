<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('customer')) { header('Location: /dashboard.php'); exit; }

$pageTitle = 'Dashboard Khách hàng';
$pdo  = getDBConnection();
$user = currentUser();

// Lấy customer_id từ customer_users
$cuStmt = $pdo->prepare("
    SELECT cu.*, c.*
    FROM customer_users cu
    JOIN customers c ON cu.customer_id = c.id
    WHERE cu.user_id = ? AND cu.is_active = TRUE
    LIMIT 1
");
$cuStmt->execute([$user['id']]);
$cu = $cuStmt->fetch();
if (!$cu) {
    echo '<div class="alert alert-danger m-4">Tài khoản chưa được liên kết với khách hàng nào. Liên hệ quản trị viên!</div>';
    exit;
}
$customerId = $cu['customer_id'];
$cuRole     = $cu['role'];

// Tháng lọc
$filterMonth = $_GET['month'] ?? date('Y-m');
[$year, $month] = explode('-', $filterMonth);

// Thống kê tháng
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                                                  AS total_trips,
        COUNT(*) FILTER (WHERE status = 'submitted')             AS pending,
        COUNT(*) FILTER (WHERE status = 'completed')             AS completed,
        COUNT(*) FILTER (WHERE status = 'confirmed')             AS confirmed,
        COUNT(*) FILTER (WHERE status = 'rejected')              AS rejected,
        COALESCE(SUM(total_km),0)                                AS total_km,
        COALESCE(SUM(toll_fee),0)                                AS total_toll,
        COUNT(DISTINCT vehicle_id)                               AS vehicles_used
    FROM trips
    WHERE customer_id = ?
      AND EXTRACT(MONTH FROM trip_date) = ?
      AND EXTRACT(YEAR  FROM trip_date) = ?
");
$stats->execute([$customerId, $month, $year]);
$stats = $stats->fetch();

// Chuyến chờ duyệt
$pendingTrips = $pdo->prepare("
    SELECT t.*, v.plate_number, v.capacity,
           u.full_name AS driver_name
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d  ON t.driver_id  = d.id
    JOIN users u    ON d.user_id    = u.id
    WHERE t.customer_id = ?
      AND t.status = 'completed'
    ORDER BY t.trip_date DESC
    LIMIT 10
");
$pendingTrips->execute([$customerId]);
$pendingTrips = $pendingTrips->fetchAll();

// KM theo xe
$byVehicle = $pdo->prepare("
    SELECT v.plate_number, v.capacity,
           COUNT(t.id)                    AS trip_count,
           COALESCE(SUM(t.total_km), 0)  AS total_km
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.customer_id = ?
      AND EXTRACT(MONTH FROM t.trip_date) = ?
      AND EXTRACT(YEAR  FROM t.trip_date) = ?
    GROUP BY v.id, v.plate_number, v.capacity
    ORDER BY total_km DESC
");
$byVehicle->execute([$customerId, $month, $year]);
$byVehicle = $byVehicle->fetchAll();

// KM theo ngày cho chart
$kmByDay = $pdo->prepare("
    SELECT TO_CHAR(trip_date,'DD') AS day,
           COALESCE(SUM(total_km),0) AS km
    FROM trips
    WHERE customer_id = ?
      AND EXTRACT(MONTH FROM trip_date) = ?
      AND EXTRACT(YEAR  FROM trip_date) = ?
    GROUP BY trip_date
    ORDER BY trip_date
");
$kmByDay->execute([$customerId, $month, $year]);
$kmByDay     = $kmByDay->fetchAll();
$chartLabels = json_encode(array_column($kmByDay, 'day'));
$chartData   = json_encode(array_column($kmByDay, 'km'));

// ✅ ĐÚNG VỊ TRÍ — include TRƯỚC khi xuất HTML
include '../includes/customer_header.php';
?>

<!-- Topbar -->
<div class="customer-topbar d-flex justify-content-between align-items-center px-3 py-2"
     style="background:#0f3460;color:#fff">
    <div class="d-flex align-items-center gap-2">
        <div class="fw-bold fs-5">🏢 <?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></div>
        <span class="badge bg-info"><?= $cu['customer_code'] ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="small opacity-75"><?= htmlspecialchars($user['full_name']) ?></span>
        <a href="/logout.php" class="btn btn-sm btn-outline-light">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</div>

<!-- Nav -->
<nav class="bg-white border-bottom px-3 py-1 d-flex gap-3 overflow-auto"
     style="font-size:0.88rem">
    <a href="dashboard.php" class="nav-link fw-semibold text-primary border-bottom border-primary border-2 pb-1">
        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
    </a>
    <a href="trips.php"    class="nav-link text-muted"><i class="fas fa-route me-1"></i>Chuyến xe</a>
    <a href="reports.php"  class="nav-link text-muted"><i class="fas fa-chart-bar me-1"></i>Báo cáo</a>
    <a href="print_statement.php" class="nav-link text-muted"><i class="fas fa-print me-1"></i>In bảng kê</a>
</nav>

<div class="container-fluid py-3 px-3">

    <?php showFlash(); ?>

    <!-- Lọc tháng -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0">📊 Tổng quan tháng</h5>
        <form method="GET" class="d-flex gap-2">
            <input type="month" name="month" class="form-control form-control-sm"
                   value="<?= $filterMonth ?>" onchange="this.form.submit()">
        </form>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-primary"><?= $stats['total_trips'] ?></div>
                <div class="small text-muted">Tổng chuyến</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-warning"><?= $stats['pending'] ?></div>
                <div class="small text-muted">Chờ duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-success"><?= $stats['confirmed'] ?></div>
                <div class="small text-muted">Đã duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-danger"><?= $stats['rejected'] ?></div>
                <div class="small text-muted">Từ chối</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-info"><?= number_format($stats['total_km'], 0) ?></div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-secondary"><?= $stats['vehicles_used'] ?></div>
                <div class="small text-muted">Xe đã dùng</div>
            </div>
        </div>
    </div>

    <!-- Chuyến cần duyệt -->
    <?php if (!empty($pendingTrips) && in_array($cuRole, ['approver','admin_customer'])): ?>
    <div class="card border-0 shadow-sm border-start border-warning border-4 mb-4">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between">
            <h6 class="fw-bold mb-0 text-warning">
                ⚠️ Chuyến chờ xác nhận (<?= count($pendingTrips) ?>)
            </h6>
            <a href="trips.php?status=completed" class="btn btn-sm btn-outline-warning">Xem tất cả</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Ngày</th>
                            <th>Mã chuyến</th>
                            <th>Xe</th>
                            <th>Lái xe</th>
                            <th>Tuyến</th>
                            <th class="text-end">KM</th>
                            <th class="text-end">Cầu đường</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingTrips as $t): ?>
                    <tr>
                        <td class="ps-3 small">
                            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                            <?php if ($t['is_sunday']): ?>
                            <span class="badge bg-warning">CN</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= $t['trip_code'] ?></code></td>
                        <td class="fw-bold text-primary"><?= $t['plate_number'] ?></td>
                        <td class="small"><?= htmlspecialchars($t['driver_name']) ?></td>
                        <td class="small text-uppercase">
                            <?= htmlspecialchars($t['pickup_location']) ?>
                            <i class="fas fa-arrow-right text-muted mx-1"></i>
                            <?= htmlspecialchars($t['dropoff_location']) ?>
                        </td>
                        <td class="text-end fw-semibold">
                            <?= $t['total_km'] ? number_format($t['total_km'],0).' km' : '—' ?>
                        </td>
                        <td class="text-end">
                            <?= $t['toll_fee'] ? number_format($t['toll_fee'],0,'.', ',').' ₫' : '—' ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="trip_confirm.php?id=<?= $t['id'] ?>&action=confirm"
                                   class="btn btn-success btn-sm"
                                   onclick="return confirm('Xác nhận chuyến này?')">
                                    <i class="fas fa-check me-1"></i>Duyệt
                                </a>
                                <a href="trip_confirm.php?id=<?= $t['id'] ?>&action=reject"
                                   class="btn btn-outline-danger btn-sm">
                                    <i class="fas fa-times me-1"></i>Từ chối
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Biểu đồ + Thống kê xe -->
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📈 KM theo ngày — Tháng <?= $month ?>/<?= $year ?></h6>
                </div>
                <div class="card-body">
                    <canvas id="kmChart" height="120"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🚛 KM theo xe</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                    <?php foreach ($byVehicle as $bv): ?>
                    <div class="list-group-item py-2">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-primary small"><?= $bv['plate_number'] ?></span>
                            <span class="fw-bold small"><?= number_format($bv['total_km'],0) ?> km</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted" style="font-size:0.75rem">
                                <?= $bv['capacity'] ? $bv['capacity'].' tấn' : '' ?>
                            </span>
                            <span class="text-muted" style="font-size:0.75rem">
                                <?= $bv['trip_count'] ?> chuyến
                            </span>
                        </div>
                        <?php if ($stats['total_km'] > 0): ?>
                        <div class="progress mt-1" style="height:4px">
                            <div class="progress-bar bg-primary"
                                 style="width:<?= min(100, $bv['total_km']/$stats['total_km']*100) ?>%">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($byVehicle)): ?>
                    <div class="list-group-item text-muted text-center py-3 small">Chưa có dữ liệu</div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Nút nhanh -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <a href="trips.php" class="card border-0 shadow-sm text-decoration-none text-center p-3 d-block hover-card">
                <i class="fas fa-list fa-2x text-primary mb-2 d-block"></i>
                <div class="fw-semibold">Danh sách chuyến</div>
                <div class="small text-muted">Xem & duyệt</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="reports.php" class="card border-0 shadow-sm text-decoration-none text-center p-3 d-block hover-card">
                <i class="fas fa-chart-bar fa-2x text-success mb-2 d-block"></i>
                <div class="fw-semibold">Báo cáo thống kê</div>
                <div class="small text-muted">Theo xe / ngày / tuần</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="print_statement.php" class="card border-0 shadow-sm text-decoration-none text-center p-3 d-block hover-card">
                <i class="fas fa-print fa-2x text-warning mb-2 d-block"></i>
                <div class="fw-semibold">In bảng kê</div>
                <div class="small text-muted">Ngày / tuần / tháng</div>
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="trips.php?status=completed" class="card border-0 shadow-sm text-decoration-none text-center p-3 d-block hover-card">
                <i class="fas fa-clock fa-2x text-danger mb-2 d-block"></i>
                <div class="fw-semibold">Chờ xác nhận</div>
                <div class="small text-muted"><?= $stats['pending'] ?> chuyến</div>
            </a>
        </div>
    </div>

</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('kmChart'), {
    type: 'bar',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [{
            label: 'KM',
            data: <?= $chartData ?>,
            backgroundColor: 'rgba(13,110,253,0.6)',
            borderColor: 'rgba(13,110,253,1)',
            borderWidth: 1,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 50 } },
            x: { grid: { display: false } }
        }
    }
});
</script>

<?php include '../includes/customer_footer.php'; // ✅ ĐÚNG VỊ TRÍ — cuối file ?>