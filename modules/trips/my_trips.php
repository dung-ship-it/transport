<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();
$role = $user['role_name'] ?? $user['role'] ?? '';

$pageTitle = 'Lịch trình của tôi';

// ── Xác định loại user và load dữ liệu phù hợp ──────────────

// Driver: xem chuyến của chính mình
$driverRow = null;
$customerId = null;

if ($role === 'driver') {
    $driverStmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
    $driverStmt->execute([$user['id']]);
    $driverRow = $driverStmt->fetch();
}

if ($role === 'customer') {
    $cuStmt = $pdo->prepare("
        SELECT cu.*, c.company_name, c.short_name, c.customer_code
        FROM customer_users cu
        JOIN customers c ON cu.customer_id = c.id
        WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
    ");
    $cuStmt->execute([$user['id']]);
    $cu = $cuStmt->fetch();
    if ($cu) $customerId = $cu['customer_id'];
}

// ── Bộ lọc ──────────────────────────────────────────────────
$filterMonth   = $_GET['month']     ?? date('Y-m');
$filterStatus  = $_GET['status']    ?? '';
$filterVehicle = $_GET['vehicle']   ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';

[$year, $month] = explode('-', $filterMonth);

// ── Build WHERE ──────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

// Phạm vi theo role
if ($role === 'driver' && $driverRow) {
    $where[]  = 't.driver_id = ?';
    $params[] = $driverRow['id'];
} elseif ($role === 'customer' && $customerId) {
    $where[]  = 't.customer_id = ?';
    $params[] = $customerId;
} else {
    // Admin/superadmin: xem tất cả (có thể filter thêm)
}

// Filter ngày
if ($filterDateFrom && $filterDateTo) {
    $where[]  = 't.trip_date BETWEEN ? AND ?';
    $params[] = $filterDateFrom;
    $params[] = $filterDateTo;
} else {
    $where[]  = 'EXTRACT(MONTH FROM t.trip_date) = ?';
    $where[]  = 'EXTRACT(YEAR  FROM t.trip_date) = ?';
    $params[] = (int)$month;
    $params[] = (int)$year;
}

if ($filterStatus) {
    $where[]  = 't.status = ?';
    $params[] = $filterStatus;
}
if ($filterVehicle) {
    $where[]  = 't.vehicle_id = ?';
    $params[] = (int)$filterVehicle;
}

$whereStr = implode(' AND ', $where);

// ── Query trips ──────────────────────────────────────────────
$trips = $pdo->prepare("
    SELECT t.*,
           v.plate_number, v.capacity,
           u.full_name    AS driver_name,
           c.company_name AS customer_name,
           c.short_name   AS customer_short,
           cu_user.full_name AS confirmed_by_name
    FROM trips t
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    WHERE $whereStr
    ORDER BY t.trip_date DESC, t.id DESC
");
$trips->execute($params);
$trips = $trips->fetchAll();

// ── Xe để filter ────────────────────────────────────────────
if ($role === 'driver' && $driverRow) {
    // Xe mà driver này đã lái
    $myVehicles = $pdo->prepare("
        SELECT DISTINCT v.id, v.plate_number
        FROM trips t JOIN vehicles v ON t.vehicle_id = v.id
        WHERE t.driver_id = ?
        ORDER BY v.plate_number
    ");
    $myVehicles->execute([$driverRow['id']]);
} elseif ($role === 'customer' && $customerId) {
    $myVehicles = $pdo->prepare("
        SELECT DISTINCT v.id, v.plate_number
        FROM trips t JOIN vehicles v ON t.vehicle_id = v.id
        WHERE t.customer_id = ?
        ORDER BY v.plate_number
    ");
    $myVehicles->execute([$customerId]);
} else {
    $myVehicles = $pdo->query("
        SELECT id, plate_number FROM vehicles
        WHERE is_active = TRUE ORDER BY plate_number
    ");
}
$myVehicles = $myVehicles->fetchAll();

// ── Tổng kết ────────────────────────────────────────────────���
$totalKm    = array_sum(array_column($trips, 'total_km'));
$totalToll  = array_sum(array_column($trips, 'toll_fee'));
$totalTrips = count($trips);

$confirmed = count(array_filter($trips, fn($t) => $t['status'] === 'confirmed'));
$pending   = count(array_filter($trips, fn($t) => $t['status'] === 'completed'));
$rejected  = count(array_filter($trips, fn($t) => $t['status'] === 'rejected'));

$statusConfig = [
    'draft'     => ['secondary', '📝 Draft'],
    'submitted' => ['warning',   '📤 Đã gửi'],
    'completed' => ['primary',   '✅ Hoàn thành'],
    'confirmed' => ['success',   '👍 Đã duyệt'],
    'rejected'  => ['danger',    '❌ Từ chối'],
];

// Chart data
$kmByDay = $pdo->prepare("
    SELECT TO_CHAR(t.trip_date,'DD/MM') AS day_label,
           t.trip_date,
           COALESCE(SUM(t.total_km),0) AS km,
           COUNT(t.id) AS trips
    FROM trips t
    WHERE $whereStr
    GROUP BY t.trip_date
    ORDER BY t.trip_date
");
$kmByDay->execute($params);
$kmByDay     = $kmByDay->fetchAll();
$chartLabels = json_encode(array_column($kmByDay, 'day_label'));
$chartKm     = json_encode(array_column($kmByDay, 'km'));
$chartTrips  = json_encode(array_column($kmByDay, 'trips'));

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">
                🗓️ Lịch trình của tôi
            </h4>
            <small class="text-muted">
                <?php if ($role === 'driver' && $driverRow): ?>
                Lái xe: <strong><?= htmlspecialchars($user['full_name']) ?></strong>
                <?php elseif ($role === 'customer' && isset($cu)): ?>
                Khách hàng: <strong><?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></strong>
                — <span class="badge bg-secondary"><?= $cu['customer_code'] ?></span>
                <?php else: ?>
                Tất cả chuyến xe
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="my_statements.php?month=<?= $filterMonth ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-file-invoice-dollar me-1"></i> Bảng kê của tôi
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Tháng</label>
                    <input type="month" name="month" class="form-control form-control-sm"
                           value="<?= $filterMonth ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Từ ngày</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $filterDateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Đến ngày</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $filterDateTo ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Biển số xe</label>
                    <select name="vehicle" class="form-select form-select-sm">
                        <option value="">-- Tất cả xe --</option>
                        <?php foreach ($myVehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            <?= $filterVehicle == $v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['plate_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Trạng thái</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($statusConfig as $val => [$cls, $lbl]): ?>
                        <option value="<?= $val ?>"
                            <?= $filterStatus === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary me-1">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                    <a href="my_trips.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-primary"><?= $totalTrips ?></div>
                <div class="small text-muted">Tổng chuyến</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-success"><?= $confirmed ?></div>
                <div class="small text-muted">Đã duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-warning"><?= $pending ?></div>
                <div class="small text-muted">Chờ duyệt</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-danger"><?= $rejected ?></div>
                <div class="small text-muted">Từ chối</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-info">
                    <?= number_format($totalKm, 0) ?>
                </div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center p-3 h-100">
                <div class="fs-2 fw-bold text-secondary">
                    <?= number_format($totalToll, 0, '.', ',') ?>
                </div>
                <div class="small text-muted">Cầu đường (₫)</div>
            </div>
        </div>
    </div>

    <!-- Biểu đồ -->
    <?php if (!empty($kmByDay)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">
                📈 KM theo ngày —
                <?php if ($filterDateFrom && $filterDateTo): ?>
                <?= date('d/m/Y', strtotime($filterDateFrom)) ?>
                → <?= date('d/m/Y', strtotime($filterDateTo)) ?>
                <?php else: ?>
                Tháng <?= $month ?>/<?= $year ?>
                <?php endif; ?>
            </h6>
        </div>
        <div class="card-body">
            <canvas id="kmChart" height="80"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bảng chuyến xe -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">
                📋 Danh sách chuyến
                <span class="badge bg-secondary ms-1"><?= $totalTrips ?></span>
            </h6>
            <a href="../statements/my_statements.php?month=<?= $filterMonth ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="fas fa-print me-1"></i> In bảng kê
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:35px">STT</th>
                            <th>Ngày</th>
                            <th>Mã chuyến</th>
                            <th>Biển số xe</th>
                            <?php if ($role !== 'driver'): ?>
                            <th>Lái xe</th>
                            <?php endif; ?>
                            <?php if ($role !== 'customer'): ?>
                            <th>Khách hàng</th>
                            <?php endif; ?>
                            <th>Điểm đi</th>
                            <th>Điểm đến</th>
                            <th class="text-end">KM đi</th>
                            <th class="text-end">KM về</th>
                            <th class="text-end">Tổng KM</th>
                            <th class="text-end">Cầu đường</th>
                            <th>Ghi chú</th>
                            <th class="text-center">Trạng thái</th>
                            <th>Người duyệt</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="15" class="text-center py-5 text-muted">
                            <i class="fas fa-route fa-2x mb-2 d-block opacity-25"></i>
                            Không có dữ liệu trong kỳ này
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($trips as $i => $t):
                        [$sCls, $sLbl] = $statusConfig[$t['status']] ?? ['secondary', $t['status']];
                    ?>
                    <tr class="<?= $t['status']==='rejected'?'table-danger bg-opacity-10':($t['status']==='confirmed'?'table-success bg-opacity-10':'') ?>">
                        <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                        <td class="text-nowrap small">
                            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                            <?php if ($t['is_sunday']): ?>
                            <span class="badge bg-warning" style="font-size:0.6rem">CN</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= htmlspecialchars($t['trip_code']) ?></code></td>
                        <td class="fw-bold text-primary">
                            <?= htmlspecialchars($t['plate_number']) ?>
                            <?php if ($t['capacity']): ?>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= $t['capacity'] ?> tấn
                            </div>
                            <?php endif; ?>
                        </td>
                        <?php if ($role !== 'driver'): ?>
                        <td class="small"><?= htmlspecialchars($t['driver_name']) ?></td>
                        <?php endif; ?>
                        <?php if ($role !== 'customer'): ?>
                        <td class="small">
                            <?= htmlspecialchars($t['customer_short'] ?: $t['customer_name']) ?>
                        </td>
                        <?php endif; ?>
                        <td class="text-uppercase fw-semibold small">
                            <?= htmlspecialchars($t['pickup_location'] ?? '—') ?>
                        </td>
                        <td class="text-uppercase fw-semibold small">
                            <?= htmlspecialchars($t['dropoff_location'] ?? '—') ?>
                        </td>
                        <td class="text-end small">
                            <?= $t['odometer_start']
                                ? number_format($t['odometer_start'], 0)
                                : '—' ?>
                        </td>
                        <td class="text-end small">
                            <?= $t['odometer_end']
                                ? number_format($t['odometer_end'], 0)
                                : '—' ?>
                        </td>
                        <td class="text-end fw-bold text-primary">
                            <?= $t['total_km']
                                ? number_format($t['total_km'], 0) . ' km'
                                : '—' ?>
                        </td>
                        <td class="text-end">
                            <?= $t['toll_fee']
                                ? number_format($t['toll_fee'], 0, '.', ',') . ' ₫'
                                : '—' ?>
                        </td>
                        <td class="small text-muted" style="max-width:120px">
                            <?= htmlspecialchars($t['note'] ?? '') ?>
                            <?php if ($t['rejection_reason']): ?>
                            <div class="text-danger" style="font-size:0.72rem"
                                 title="<?= htmlspecialchars($t['rejection_reason']) ?>">
                                ❌ <?= mb_substr($t['rejection_reason'], 0, 30) ?>
                                <?= mb_strlen($t['rejection_reason']) > 30 ? '...' : '' ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $sCls ?>"><?= $sLbl ?></span>
                        </td>
                        <td class="small">
                            <?php if ($t['confirmed_by_name']): ?>
                            <div class="text-success fw-semibold">
                                ✅ <?= htmlspecialchars($t['confirmed_by_name']) ?>
                            </div>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= $t['confirmed_at']
                                    ? date('d/m/Y H:i', strtotime($t['confirmed_at']))
                                    : '' ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($trips)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="<?php
                                $cols = 10;
                                if ($role !== 'driver') $cols++;
                                if ($role !== 'customer') $cols++;
                            echo $cols; ?>"
                                class="text-end">
                                Tổng (<?= $totalTrips ?> chuyến):
                            </td>
                            <td class="text-end text-primary">
                                <?= number_format($totalKm, 0) ?> km
                            </td>
                            <td class="text-end">
                                <?= number_format($totalToll, 0, '.', ',') ?> ₫
                            </td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<?php if (!empty($kmByDay)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('kmChart'), {
    type: 'bar',
    data: {
        labels: <?= $chartLabels ?>,
        datasets: [
            {
                label: 'KM',
                data: <?= $chartKm ?>,
                backgroundColor: 'rgba(13,110,253,0.6)',
                borderColor: 'rgba(13,110,253,1)',
                borderWidth: 1,
                borderRadius: 4,
                yAxisID: 'y',
            },
            {
                label: 'Số chuyến',
                data: <?= $chartTrips ?>,
                type: 'line',
                borderColor: 'rgba(255,193,7,1)',
                backgroundColor: 'rgba(255,193,7,0.15)',
                borderWidth: 2,
                pointRadius: 4,
                fill: false,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: true, position: 'top' }
        },
        scales: {
            y:  { beginAtZero: true, position: 'left',
                  title: { display: true, text: 'KM' } },
            y1: { beginAtZero: true, position: 'right',
                  grid: { drawOnChartArea: false },
                  title: { display: true, text: 'Số chuyến' },
                  ticks: { stepSize: 1 } },
            x:  { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php include '../../includes/footer.php'; ?>