<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('customer')) { header('Location: /transport/dashboard.php'); exit; }

$pdo  = getDBConnection();
$user = currentUser();

$cuStmt = $pdo->prepare("
    SELECT cu.*, c.company_name, c.short_name, c.customer_code
    FROM customer_users cu JOIN customers c ON cu.customer_id = c.id
    WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
");
$cuStmt->execute([$user['id']]);
$cu = $cuStmt->fetch();
if (!$cu) exit('Chưa liên kết khách hàng!');
$customerId = $cu['customer_id'];
$pageTitle  = 'Báo cáo thống kê';

$filterMonth = $_GET['month'] ?? date('Y-m');
[$year, $month] = explode('-', $filterMonth);

$byVehicle = $pdo->prepare("
    SELECT v.plate_number, v.capacity,
           COUNT(t.id)                       AS trip_count,
           COALESCE(SUM(t.total_km),0)       AS total_km,
           COALESCE(SUM(t.toll_fee),0)       AS total_toll,
           COUNT(*) FILTER (WHERE t.status='confirmed') AS confirmed_count,
           MIN(t.trip_date)                  AS first_trip,
           MAX(t.trip_date)                  AS last_trip
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

$byDay = $pdo->prepare("
    SELECT t.trip_date,
           COUNT(t.id)                       AS trip_count,
           COALESCE(SUM(t.total_km),0)       AS total_km,
           COALESCE(SUM(t.toll_fee),0)       AS total_toll,
           COUNT(DISTINCT t.vehicle_id)      AS vehicles_used,
           EXTRACT(DOW FROM t.trip_date)     AS dow
    FROM trips t
    WHERE t.customer_id = ?
      AND EXTRACT(MONTH FROM t.trip_date) = ?
      AND EXTRACT(YEAR  FROM t.trip_date) = ?
    GROUP BY t.trip_date
    ORDER BY t.trip_date
");
$byDay->execute([$customerId, $month, $year]);
$byDay = $byDay->fetchAll();

$byWeek = $pdo->prepare("
    SELECT EXTRACT(WEEK FROM trip_date) AS week_num,
           MIN(trip_date)               AS week_start,
           MAX(trip_date)               AS week_end,
           COUNT(id)                    AS trip_count,
           COALESCE(SUM(total_km),0)    AS total_km,
           COALESCE(SUM(toll_fee),0)    AS total_toll
    FROM trips
    WHERE customer_id = ?
      AND EXTRACT(MONTH FROM trip_date) = ?
      AND EXTRACT(YEAR  FROM trip_date) = ?
    GROUP BY EXTRACT(WEEK FROM trip_date)
    ORDER BY week_num
");
$byWeek->execute([$customerId, $month, $year]);
$byWeek = $byWeek->fetchAll();

$totalKm    = array_sum(array_column($byVehicle, 'total_km'));
$totalToll  = array_sum(array_column($byVehicle, 'total_toll'));
$totalTrips = array_sum(array_column($byVehicle, 'trip_count'));

$dayLabels = json_encode(array_map(fn($d) => date('d/m', strtotime($d['trip_date'])), $byDay));
$dayKm     = json_encode(array_column($byDay, 'total_km'));

// ✅ include TRƯỚC khi xuất HTML
include '../includes/customer_header.php';
?>

<!-- Topbar -->
<div class="d-flex justify-content-between align-items-center px-3 py-2"
     style="background:#0f3460;color:#fff">
    <div class="fw-bold">🏢 <?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></div>
    <a href="dashboard.php" class="btn btn-sm btn-outline-light">
        <i class="fas fa-home"></i>
    </a>
</div>

<!-- Nav -->
<nav class="bg-white border-bottom px-3 py-1 d-flex gap-3 overflow-auto"
     style="font-size:0.88rem">
    <a href="dashboard.php" class="nav-link text-muted">
        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
    </a>
    <a href="trips.php" class="nav-link text-muted">
        <i class="fas fa-route me-1"></i>Chuyến xe
    </a>
    <a href="reports.php" class="nav-link fw-semibold text-primary border-bottom border-primary border-2 pb-1">
        <i class="fas fa-chart-bar me-1"></i>Báo cáo
    </a>
    <a href="print_statement.php" class="nav-link text-muted">
        <i class="fas fa-print me-1"></i>In bảng kê
    </a>
</nav>

<div class="container-fluid py-3 px-3">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0">📊 Báo cáo thống kê</h5>
        <form method="GET" class="d-flex gap-2">
            <input type="month" name="month" class="form-control form-control-sm"
                   value="<?= $filterMonth ?>" onchange="this.form.submit()">
            <a href="print_statement.php?month=<?= $filterMonth ?>"
               class="btn btn-sm btn-outline-secondary" target="_blank">
                <i class="fas fa-print me-1"></i>In
            </a>
        </form>
    </div>

    <!-- Tổng quan -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-primary"><?= $totalTrips ?></div>
                <div class="small text-muted">Tổng chuyến tháng <?= $month ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-success"><?= number_format($totalKm,0) ?></div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-warning"><?= number_format($totalToll,0,'.', ',') ?></div>
                <div class="small text-muted">Cầu đường (₫)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-info"><?= count($byVehicle) ?></div>
                <div class="small text-muted">Xe hoạt động</div>
            </div>
        </div>
    </div>

    <!-- Biểu đồ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📈 KM theo ngày — Tháng <?= $month ?>/<?= $year ?></h6>
        </div>
        <div class="card-body">
            <canvas id="kmByDayChart" height="80"></canvas>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <!-- Theo xe -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🚛 Thống kê theo xe</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Biển số</th>
                                <th>Tải trọng</th>
                                <th class="text-center">Số chuyến</th>
                                <th class="text-end">Tổng KM</th>
                                <th class="text-end">Cầu đường</th>
                                <th class="text-center">Đã duyệt</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($byVehicle as $bv): ?>
                        <tr>
                            <td class="ps-3 fw-bold text-primary"><?= $bv['plate_number'] ?></td>
                            <td class="text-muted small"><?= $bv['capacity'] ? $bv['capacity'].' tấn' : '—' ?></td>
                            <td class="text-center"><?= $bv['trip_count'] ?></td>
                            <td class="text-end fw-semibold"><?= number_format($bv['total_km'],0) ?> km</td>
                            <td class="text-end"><?= $bv['total_toll'] ? number_format($bv['total_toll'],0,'.', ',').' ₫' : '—' ?></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?= $bv['confirmed_count'] ?></span>
                                / <?= $bv['trip_count'] ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byVehicle)): ?>
                        <tr><td colspan="6" class="text-center py-3 text-muted">Chưa có dữ liệu</td></tr>
                        <?php endif; ?>
                        </tbody>
                        <?php if (!empty($byVehicle)): ?>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="ps-3">Tổng</td>
                                <td class="text-center"><?= $totalTrips ?></td>
                                <td class="text-end text-primary"><?= number_format($totalKm,0) ?> km</td>
                                <td class="text-end"><?= number_format($totalToll,0,'.', ',') ?> ₫</td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <!-- Theo tuần -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📅 Thống kê theo tuần</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Tuần</th>
                                <th>Kỳ</th>
                                <th class="text-center">Chuyến</th>
                                <th class="text-end">KM</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($byWeek as $bw): ?>
                        <tr>
                            <td class="ps-3 fw-semibold">Tuần <?= $bw['week_num'] ?></td>
                            <td class="small text-muted">
                                <?= date('d/m', strtotime($bw['week_start'])) ?>
                                — <?= date('d/m', strtotime($bw['week_end'])) ?>
                            </td>
                            <td class="text-center"><?= $bw['trip_count'] ?></td>
                            <td class="text-end fw-semibold"><?= number_format($bw['total_km'],0) ?> km</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byWeek)): ?>
                        <tr><td colspan="4" class="text-center py-3 text-muted">Chưa có dữ liệu</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Theo ngày -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📆 Chi tiết theo ngày</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Ngày</th>
                            <th>Thứ</th>
                            <th class="text-center">Số chuyến</th>
                            <th class="text-center">Số xe</th>
                            <th class="text-end">Tổng KM</th>
                            <th class="text-end">Cầu đường</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $dowNames = ['CN','T2','T3','T4','T5','T6','T7'];
                    foreach ($byDay as $bd):
                        $isSunday = (int)$bd['dow'] === 0;
                    ?>
                    <tr class="<?= $isSunday ? 'table-warning bg-opacity-25' : '' ?>">
                        <td class="ps-3 fw-semibold"><?= date('d/m/Y', strtotime($bd['trip_date'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $isSunday ? 'warning' : 'secondary' ?>">
                                <?= $dowNames[(int)$bd['dow']] ?>
                            </span>
                        </td>
                        <td class="text-center"><?= $bd['trip_count'] ?></td>
                        <td class="text-center"><?= $bd['vehicles_used'] ?></td>
                        <td class="text-end fw-semibold"><?= number_format($bd['total_km'],0) ?> km</td>
                        <td class="text-end"><?= $bd['total_toll'] ? number_format($bd['total_toll'],0,'.', ',').' ₫' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('kmByDayChart'), {
    type: 'bar',
    data: {
        labels: <?= $dayLabels ?>,
        datasets: [{
            label: 'KM',
            data:  <?= $dayKm ?>,
            backgroundColor: 'rgba(13,110,253,0.6)',
            borderColor:     'rgba(13,110,253,1)',
            borderWidth: 1,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true },
            x: { grid: { display: false } }
        }
    }
});
</script>

<?php include '../includes/customer_footer.php'; // ✅ ?>