<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('reports', 'view');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Báo Cáo Tổng Hợp';

// ── Tham số lọc ─────────────────────────────────────────────
$tab       = $_GET['tab']       ?? 'overview';
$dateFrom  = $_GET['date_from'] ?? date('Y-m-01');
$dateTo    = $_GET['date_to']   ?? date('Y-m-d');
$filterCustomer = (int)($_GET['customer_id'] ?? 0);
$filterVehicle  = (int)($_GET['vehicle_id']  ?? 0);
$filterDriver   = (int)($_GET['driver_id']   ?? 0);

// ── Helper ──────────────────────────────────────────────────
function safeQuery(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}
function safeCol(PDO $pdo, string $sql, array $params = []): mixed {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        return $v === false ? 0 : $v;
    } catch (PDOException $e) {
        return 0;
    }
}

// ═══════════════════════════════��════════════════════════════
// 1. TỔNG QUAN (overview)
// ════════════════════════════════════════════════════════════
$overview = [];
if ($tab === 'overview') {
    $overview['revenue'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_amount),0) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    $overview['trips'] = (int)safeCol($pdo,"
        SELECT COUNT(*) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    $overview['km'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_km),0) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    // Chi phí nhiên liệu — thử cả hai tên bảng phổ biến
    $overview['fuel_cost'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(amount),0) FROM fuel_logs
        WHERE log_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    // Chi phí bảo dưỡng
    $overview['maint_cost'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(cost),0) FROM maintenance_logs
        WHERE log_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    $overview['other_cost']   = 0; // chưa có bảng expenses riêng
    $overview['total_cost']   = $overview['fuel_cost'] + $overview['maint_cost'] + $overview['other_cost'];
    $overview['profit']       = $overview['revenue'] - $overview['total_cost'];
    $overview['profit_rate']  = $overview['revenue'] > 0
        ? round($overview['profit'] / $overview['revenue'] * 100, 1) : 0;
    $overview['avg_per_trip'] = $overview['trips'] > 0
        ? round($overview['revenue'] / $overview['trips']) : 0;
    $overview['cost_per_km']  = $overview['km'] > 0
        ? round($overview['total_cost'] / $overview['km'], 2) : 0;

    // Chuyến theo trạng thái
    $overview['by_status'] = safeQuery($pdo,"
        SELECT status, COUNT(*) AS cnt,
               COALESCE(SUM(total_amount),0) AS amt
        FROM trips WHERE trip_date BETWEEN ? AND ?
        GROUP BY status
    ",[$dateFrom,$dateTo]);

    // Doanh thu theo tháng (12 tháng gần nhất) — PostgreSQL syntax
    $overview['monthly'] = safeQuery($pdo,"
        SELECT TO_CHAR(trip_date,'YYYY-MM') AS ym,
               COUNT(*) AS trips,
               COALESCE(SUM(total_amount),0) AS revenue,
               COALESCE(SUM(total_km),0) AS km
        FROM trips
        WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
          AND status IN ('completed','confirmed')
        GROUP BY ym ORDER BY ym
    ");

    // Top 5 khách hàng
    $overview['top_customers'] = safeQuery($pdo,"
        SELECT c.company_name, COUNT(*) AS trips,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t JOIN customers c ON t.customer_id = c.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY c.id, c.company_name
        ORDER BY revenue DESC LIMIT 5
    ",[$dateFrom,$dateTo]);

    // Top 5 lái xe
    $overview['top_drivers'] = safeQuery($pdo,"
        SELECT u.full_name, COUNT(*) AS trips,
               COALESCE(SUM(t.total_km),0) AS km,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY d.id, u.full_name
        ORDER BY trips DESC LIMIT 5
    ",[$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 2. BÁO CÁO DOANH THU
// ════════════════════════════════════════════════════════════
$revenueData    = [];
$revenueRows    = [];
$revTotalAmount = 0;
$revTotalKm     = 0;

if ($tab === 'revenue') {
    $sql = "
        SELECT t.trip_code,
               t.trip_date,
               COALESCE(t.pickup_location, t.route_from, '')  AS route_from,
               COALESCE(t.dropoff_location, t.route_to, '')   AS route_to,
               COALESCE(t.total_km, 0)                        AS total_km,
               COALESCE(t.total_amount, 0)                    AS total_amount,
               t.status,
               c.company_name AS customer,
               u.full_name    AS driver,
               v.plate_number
        FROM trips t
        JOIN customers c ON t.customer_id = c.id
        JOIN drivers d   ON t.driver_id   = d.id
        JOIN users u     ON d.user_id     = u.id
        JOIN vehicles v  ON t.vehicle_id  = v.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
    ";
    $params = [$dateFrom, $dateTo];
    if ($filterCustomer) { $sql .= " AND t.customer_id = ?"; $params[] = $filterCustomer; }
    if ($filterDriver)   { $sql .= " AND t.driver_id = ?";   $params[] = $filterDriver; }
    if ($filterVehicle)  { $sql .= " AND t.vehicle_id = ?";  $params[] = $filterVehicle; }
    $sql .= " ORDER BY t.trip_date DESC";

    $revenueRows    = safeQuery($pdo, $sql, $params);
    $revTotalAmount = array_sum(array_column($revenueRows, 'total_amount'));
    $revTotalKm     = array_sum(array_column($revenueRows, 'total_km'));
}

// ════════════════════════════════════════════════════════════
// 3. CHI PHÍ ĐẦU VÀO
// ════════════════════════════════════════════════════════════
$costData = [];
if ($tab === 'cost') {
    // Nhiên liệu — dùng bảng fuel_logs của transport DB
    $costData['fuel'] = safeQuery($pdo,"
        SELECT fl.log_date,
               COALESCE(fl.liters_filled, 0)   AS liters_filled,
               COALESCE(fl.amount, 0)           AS total_cost,
               fl.station_name,
               v.plate_number,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ",[$dateFrom,$dateTo]);

    // Bảo dưỡng
    $costData['maintenance'] = safeQuery($pdo,"
        SELECT ml.log_date AS maintenance_date,
               ml.maintenance_type,
               ml.description,
               COALESCE(ml.cost, ml.total_cost, 0) AS total_cost,
               ml.garage_name,
               v.plate_number
        FROM maintenance_logs ml
        JOIN vehicles v ON ml.vehicle_id = v.id
        WHERE ml.log_date BETWEEN ? AND ?
        ORDER BY ml.log_date DESC
    ",[$dateFrom,$dateTo]);

    $costData['others']       = [];
    $costData['_fuel_total']  = array_sum(array_column($costData['fuel'],  'total_cost'));
    $costData['_maint_total'] = array_sum(array_column($costData['maintenance'], 'total_cost'));
    $costData['_other_total'] = 0;
    $costData['_grand_total'] = $costData['_fuel_total'] + $costData['_maint_total'];
}

// ════════════════════════════════════════════════════════════
// 4. KHÁCH HÀNG
// ════════════════════════════════════════════════════════════
$customerReport = [];
if ($tab === 'customer') {
    $customerReport = safeQuery($pdo,"
        SELECT
            c.id,
            c.company_name,
            c.primary_contact_name AS contact_name,
            c.primary_contact_phone AS phone,
            COUNT(t.id)                               AS total_trips,
            COALESCE(SUM(t.total_km),0)               AS total_km,
            COALESCE(SUM(t.total_amount),0)           AS total_revenue,
            COALESCE(AVG(t.total_amount),0)           AS avg_per_trip,
            MIN(t.trip_date)                          AS first_trip,
            MAX(t.trip_date)                          AS last_trip
        FROM customers c
        LEFT JOIN trips t ON t.customer_id = c.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE c.is_active = TRUE
        GROUP BY c.id, c.company_name, c.primary_contact_name, c.primary_contact_phone
        ORDER BY total_revenue DESC
    ",[$dateFrom,$dateTo]);

    $grandRev = array_sum(array_column($customerReport, 'total_revenue'));
    foreach ($customerReport as &$row) {
        $row['revenue_pct'] = $grandRev > 0
            ? round($row['total_revenue'] / $grandRev * 100, 1) : 0;
        $row['est_fuel_cost'] = (float)$row['total_km'] * 0.085;
        $row['est_profit']    = (float)$row['total_revenue'] - $row['est_fuel_cost'];
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 5. XE
// ════════════════════════════════════════════════════════════
$vehicleReport = [];
if ($tab === 'vehicle') {
    $vehicleReport = safeQuery($pdo,"
        SELECT
            v.id, v.plate_number, v.brand, v.model, v.year,
            v.status AS vehicle_status,
            COUNT(t.id)                              AS total_trips,
            COALESCE(SUM(t.total_km),0)              AS total_km,
            COALESCE(SUM(t.total_amount),0)          AS total_revenue,
            COALESCE((SELECT SUM(fl.amount)
                      FROM fuel_logs fl
                      WHERE fl.vehicle_id = v.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost,
            COALESCE((SELECT SUM(fl.liters_filled)
                      FROM fuel_logs fl
                      WHERE fl.vehicle_id = v.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_liters,
            COALESCE((SELECT SUM(COALESCE(ml.cost, ml.total_cost, 0))
                      FROM maintenance_logs ml
                      WHERE ml.vehicle_id = v.id
                        AND ml.log_date BETWEEN ? AND ?),0) AS maint_cost,
            COALESCE((SELECT COUNT(*)
                      FROM maintenance_logs ml
                      WHERE ml.vehicle_id = v.id
                        AND ml.log_date BETWEEN ? AND ?),0) AS maint_count
        FROM vehicles v
        LEFT JOIN trips t ON t.vehicle_id = v.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        GROUP BY v.id, v.plate_number, v.brand, v.model, v.year, v.status
        ORDER BY total_km DESC
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);

    foreach ($vehicleReport as &$row) {
        $row['total_cost']   = (float)$row['fuel_cost'] + (float)$row['maint_cost'];
        $row['profit']       = (float)$row['total_revenue'] - $row['total_cost'];
        $row['km_per_liter'] = (float)$row['fuel_liters'] > 0
            ? round((float)$row['total_km'] / (float)$row['fuel_liters'], 2) : 0;
        $row['cost_per_km']  = (float)$row['total_km'] > 0
            ? round($row['total_cost'] / (float)$row['total_km'], 2) : 0;
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 6. LÁI XE
// ════════════════════════════════════════════════════════════
$driverReport = [];
if ($tab === 'driver') {
    $driverReport = safeQuery($pdo,"
        SELECT
            d.id, u.full_name, u.phone, d.license_number,
            COUNT(t.id)                     AS total_trips,
            COALESCE(SUM(t.total_km),0)     AS total_km,
            COALESCE(SUM(t.total_amount),0) AS total_revenue,
            COALESCE(AVG(t.total_km),0)     AS avg_km_per_trip,
            COALESCE((SELECT SUM(fl.amount)
                      FROM fuel_logs fl WHERE fl.driver_id = d.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN trips t ON t.driver_id = d.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE d.is_active = TRUE
        GROUP BY d.id, u.full_name, u.phone, d.license_number
        ORDER BY total_trips DESC
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 7. LÃI LỖ (P&L) — PostgreSQL syntax
// ════════════════════════════════════════════════════════════
$plReport = [];
if ($tab === 'pl') {
    $plReport['monthly'] = safeQuery($pdo,"
        SELECT
            TO_CHAR(m.ym, 'YYYY-MM') AS ym,
            COALESCE(r.revenue,  0) AS revenue,
            COALESCE(f.fuel,     0) AS fuel_cost,
            COALESCE(mn.maint,   0) AS maint_cost,
            0                       AS other_cost,
            COALESCE(r.revenue,  0)
              - COALESCE(f.fuel, 0)
              - COALESCE(mn.maint,0) AS profit
        FROM (
            SELECT DISTINCT DATE_TRUNC('month', trip_date) AS ym
            FROM trips WHERE trip_date BETWEEN ? AND ?
        ) m
        LEFT JOIN (
            SELECT DATE_TRUNC('month', trip_date) AS ym,
                   SUM(total_amount) AS revenue
            FROM trips WHERE trip_date BETWEEN ? AND ?
              AND status IN ('completed','confirmed')
            GROUP BY 1
        ) r ON r.ym = m.ym
        LEFT JOIN (
            SELECT DATE_TRUNC('month', log_date) AS ym,
                   SUM(amount) AS fuel
            FROM fuel_logs WHERE log_date BETWEEN ? AND ?
            GROUP BY 1
        ) f ON f.ym = m.ym
        LEFT JOIN (
            SELECT DATE_TRUNC('month', log_date) AS ym,
                   SUM(COALESCE(cost, total_cost, 0)) AS maint
            FROM maintenance_logs WHERE log_date BETWEEN ? AND ?
            GROUP BY 1
        ) mn ON mn.ym = m.ym
        ORDER BY m.ym
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);

    $plReport['total_revenue'] = array_sum(array_column($plReport['monthly'], 'revenue'));
    $plReport['total_fuel']    = array_sum(array_column($plReport['monthly'], 'fuel_cost'));
    $plReport['total_maint']   = array_sum(array_column($plReport['monthly'], 'maint_cost'));
    $plReport['total_other']   = 0;
    $plReport['total_cost']    = $plReport['total_fuel'] + $plReport['total_maint'];
    $plReport['total_profit']  = $plReport['total_revenue'] - $plReport['total_cost'];
    $plReport['margin']        = $plReport['total_revenue'] > 0
        ? round($plReport['total_profit'] / $plReport['total_revenue'] * 100, 1) : 0;
}

// ════════════════════════════════════════════════════════════
// 8. NHIÊN LIỆU
// ════════════════════════════════════════════���═══════════════
$fuelReport = [];
if ($tab === 'fuel') {
    $fuelReport['detail'] = safeQuery($pdo,"
        SELECT fl.log_date,
               COALESCE(fl.liters_filled, 0) AS liters_filled,
               COALESCE(fl.amount, 0)        AS total_cost,
               fl.station_name,
               v.plate_number, v.brand, v.model,
               u.full_name AS driver,
               CASE
                   WHEN (fl.km_after - fl.km_before) > 0
                   THEN ROUND(fl.liters_filled / (fl.km_after - fl.km_before) * 100, 2)
                   ELSE NULL
               END AS lper100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ",[$dateFrom,$dateTo]);

    $fuelReport['by_vehicle'] = safeQuery($pdo,"
        SELECT v.plate_number, v.brand, v.model,
               COUNT(*)                          AS fills,
               ROUND(SUM(fl.liters_filled)::numeric,2) AS total_liters,
               ROUND(SUM(fl.amount)::numeric,0)  AS total_cost,
               ROUND(AVG(
                   CASE WHEN (fl.km_after - fl.km_before) > 0
                        THEN fl.liters_filled / (fl.km_after - fl.km_before) * 100
                   END
               )::numeric,2)                     AS avg_lper100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number, v.brand, v.model
        ORDER BY total_cost DESC
    ",[$dateFrom,$dateTo]);

    $fuelReport['_total_liters'] = array_sum(array_column($fuelReport['detail'], 'liters_filled'));
    $fuelReport['_total_cost']   = array_sum(array_column($fuelReport['detail'], 'total_cost'));
}

// ── Danh sách filter ────────────────────────────────────────
$customers = safeQuery($pdo,"SELECT id, company_name FROM customers WHERE is_active=TRUE ORDER BY company_name");
$vehicles  = safeQuery($pdo,"SELECT id, plate_number FROM vehicles ORDER BY plate_number");
$drivers   = safeQuery($pdo,"SELECT d.id, u.full_name FROM drivers d JOIN users u ON d.user_id=u.id WHERE d.is_active=TRUE ORDER BY u.full_name");

include '../../includes/header.php';
include '../../includes/sidebar.php';

$tabs = [
    'overview' => ['icon'=>'fa-chart-pie',          'label'=>'Tổng quan'],
    'revenue'  => ['icon'=>'fa-file-invoice-dollar', 'label'=>'Doanh thu'],
    'cost'     => ['icon'=>'fa-money-bill-wave',     'label'=>'Chi phí đầu vào'],
    'pl'       => ['icon'=>'fa-balance-scale',       'label'=>'Lãi / Lỗ'],
    'customer' => ['icon'=>'fa-building',            'label'=>'Theo KH'],
    'vehicle'  => ['icon'=>'fa-truck',               'label'=>'Theo xe'],
    'driver'   => ['icon'=>'fa-id-card',             'label'=>'Theo lái xe'],
    'fuel'     => ['icon'=>'fa-gas-pump',            'label'=>'Nhiên liệu'],
];
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-chart-bar me-2 text-primary"></i>Báo Cáo Tổng Hợp
            </h4>
            <small class="text-muted">
                Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?></strong>
                → <strong><?= date('d/m/Y', strtotime($dateTo)) ?></strong>
            </small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success" onclick="exportCurrentTab()">
                <i class="fas fa-file-excel me-1"></i>Xuất Excel
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                <i class="fas fa-print me-1"></i>In
            </button>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Từ ngày</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $dateFrom ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-semibold mb-1">Đến ngày</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $dateTo ?>">
                </div>
                <?php if (in_array($tab, ['revenue','customer'])): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterCustomer==$c['id']?'selected':'' ?>>
                            <?= htmlspecialchars($c['company_name'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (in_array($tab, ['revenue','vehicle','fuel'])): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Xe</label>
                    <select name="vehicle_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $filterVehicle==$v['id']?'selected':'' ?>>
                            <?= htmlspecialchars($v['plate_number'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (in_array($tab, ['revenue','driver'])): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Lái xe</label>
                    <select name="driver_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $filterDriver==$d['id']?'selected':'' ?>>
                            <?= htmlspecialchars($d['full_name'] ?? '') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Shortcut kỳ -->
                <div class="col-md-auto">
                    <label class="form-label small fw-semibold mb-1 d-block">Kỳ nhanh</label>
                    <div class="btn-group btn-group-sm">
                        <?php
                        $periods = [
                            'Hôm nay'     => [date('Y-m-d'), date('Y-m-d')],
                            'Tháng này'   => [date('Y-m-01'), date('Y-m-d')],
                            'Tháng trước' => [
                                date('Y-m-01', strtotime('first day of last month')),
                                date('Y-m-t',  strtotime('last day of last month'))
                            ],
                            'Năm nay'     => [date('Y-01-01'), date('Y-m-d')],
                        ];
                        foreach ($periods as $label => [$f, $t]):
                        ?>
                        <a href="?tab=<?= $tab ?>&date_from=<?= $f ?>&date_to=<?= $t ?>"
                           class="btn btn-outline-secondary <?= ($dateFrom==$f && $dateTo==$t)?'active':'' ?>">
                            <?= $label ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto">
        <?php foreach ($tabs as $key => $t): ?>
        <li class="nav-item">
            <a href="?tab=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
               class="nav-link text-nowrap <?= $tab===$key?'active':'' ?>">
                <i class="fas <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ════ TAB: TỔNG QUAN ════ -->
    <?php if ($tab === 'overview'): ?>

    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['Doanh thu',       formatMoney($overview['revenue']),    'primary',   'fa-chart-line'],
            ['Tổng chi phí',    formatMoney($overview['total_cost']), 'danger',    'fa-money-bill-wave'],
            ['Lợi nhuận',       formatMoney($overview['profit']),     $overview['profit']>=0?'success':'danger', 'fa-coins'],
            ['Biên lợi nhuận',  $overview['profit_rate'].'%',         $overview['profit_rate']>=20?'success':'warning', 'fa-percentage'],
            ['Số chuyến',       number_format($overview['trips']).' chuyến', 'info',  'fa-route'],
            ['Tổng km',         number_format($overview['km']).' km',       'secondary','fa-road'],
            ['TB/chuyến',       formatMoney($overview['avg_per_trip']),     'warning',  'fa-calculator'],
            ['CP/km',           number_format($overview['cost_per_km']).'đ/km','dark', 'fa-gas-pump'],
        ];
        foreach ($cards as [$label, $value, $color, $icon]):
        ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm border-start border-<?= $color ?> border-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="small text-muted"><?= $label ?></div>
                            <div class="fw-bold fs-5 text-<?= $color ?>"><?= $value ?></div>
                        </div>
                        <div class="text-<?= $color ?> opacity-25 fs-2">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Chi phí + Top KH + Top LX -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">💰 Cơ cấu chi phí</h6>
                </div>
                <div class="card-body">
                    <?php
                    $costItems = [
                        ['Nhiên liệu',   $overview['fuel_cost'],  $overview['total_cost'], 'warning'],
                        ['Bảo dưỡng',    $overview['maint_cost'], $overview['total_cost'], 'danger'],
                        ['Chi phí khác', $overview['other_cost'], $overview['total_cost'], 'secondary'],
                    ];
                    foreach ($costItems as [$label, $val, $total, $color]):
                        $pct = $total > 0 ? round($val/$total*100,1) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= $label ?></span>
                            <span class="fw-semibold"><?= formatMoney($val) ?> (<?= $pct ?>%)</span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <hr>
                    <div class="d-flex justify-content-between fw-bold">
                        <span>Tổng chi phí</span>
                        <span class="text-danger"><?= formatMoney($overview['total_cost']) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🏆 Top 5 Khách hàng</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr>
                            <th>Khách hàng</th>
                            <th class="text-center">Chuyến</th>
                            <th class="text-end">Doanh thu</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($overview['top_customers'] as $i => $c): ?>
                        <tr>
                            <td>
                                <span class="me-1"><?= ['🥇','🥈','🥉','4️⃣','5️⃣'][$i] ?? ($i+1) ?></span>
                                <small><?= htmlspecialchars($c['company_name'] ?? '') ?></small>
                            </td>
                            <td class="text-center small"><?= $c['trips'] ?></td>
                            <td class="text-end small fw-semibold text-success">
                                <?= formatMoney((float)$c['revenue']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overview['top_customers'])): ?>
                        <tr><td colspan="3" class="text-center text-muted small py-3">Không có dữ liệu</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🚛 Top 5 Lái xe</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr>
                            <th>Lái xe</th>
                            <th class="text-center">Chuyến</th>
                            <th class="text-end">Km</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($overview['top_drivers'] as $i => $d): ?>
                        <tr>
                            <td>
                                <span class="me-1"><?= ['🥇','🥈','🥉','4️⃣','5️⃣'][$i] ?? ($i+1) ?></span>
                                <small><?= htmlspecialchars($d['full_name'] ?? '') ?></small>
                            </td>
                            <td class="text-center small"><?= $d['trips'] ?></td>
                            <td class="text-end small"><?= number_format((float)$d['km']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overview['top_drivers'])): ?>
                        <tr><td colspan="3" class="text-center text-muted small py-3">Không có dữ liệu</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart doanh thu 12 tháng -->
    <?php if (!empty($overview['monthly'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📈 Doanh thu 12 tháng gần nhất</h6>
        </div>
        <div class="card-body">
            <canvas id="revenueChart" height="80"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Trạng thái chuyến -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📊 Trạng thái chuyến xe</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-dark"><tr>
                    <th>Trạng thái</th>
                    <th class="text-center">Số chuyến</th>
                    <th class="text-end">Doanh thu</th>
                </tr></thead>
                <tbody>
                <?php
                $statusMap = [
                    'completed'   => ['✅ Hoàn thành','success'],
                    'confirmed'   => ['✔️ Xác nhận',  'info'],
                    'in_progress' => ['🚛 Đang chạy', 'primary'],
                    'scheduled'   => ['⏳ Chờ xuất phát','warning'],
                    'cancelled'   => ['❌ Huỷ',        'danger'],
                    'rejected'    => ['🚫 Từ chối',    'danger'],
                ];
                foreach ($overview['by_status'] as $s):
                    [$slabel, $scolor] = $statusMap[$s['status']] ?? [$s['status'],'secondary'];
                ?>
                <tr>
                    <td><span class="badge bg-<?= $scolor ?>"><?= $slabel ?></span></td>
                    <td class="text-center"><?= number_format($s['cnt']) ?></td>
                    <td class="text-end"><?= formatMoney((float)$s['amt']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($overview['by_status'])): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Không có dữ li���u</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════ TAB: DOANH THU ════ -->
    <?php elseif ($tab === 'revenue'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">📄 Chi tiết doanh thu</h6>
            <div class="text-end small text-muted">
                <strong><?= count($revenueRows) ?></strong> chuyến ·
                Tổng: <strong class="text-success"><?= formatMoney($revTotalAmount) ?></strong> ·
                <?= number_format($revTotalKm) ?> km
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem" id="exportTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Mã chuyến</th>
                            <th>Ngày</th>
                            <th>Khách hàng</th>
                            <th>Lái xe</th>
                            <th>Biển số</th>
                            <th>Lộ trình</th>
                            <th class="text-end">Km</th>
                            <th class="text-end">Thành tiền</th>
                            <th class="text-center">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($revenueRows as $r): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($r['trip_code'] ?? '') ?></code></td>
                        <td><?= date('d/m/Y', strtotime($r['trip_date'])) ?></td>
                        <td><?= htmlspecialchars($r['customer'] ?? '') ?></td>
                        <td><?= htmlspecialchars($r['driver'] ?? '') ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($r['plate_number'] ?? '') ?></span></td>
                        <td>
                            <small>
                                <?= htmlspecialchars($r['route_from'] ?? '') ?>
                                <?php if ($r['route_from'] && $r['route_to']): ?> → <?php endif; ?>
                                <?= htmlspecialchars($r['route_to'] ?? '') ?>
                            </small>
                        </td>
                        <td class="text-end"><?= number_format((float)$r['total_km'], 1) ?></td>
                        <td class="text-end fw-semibold text-success"><?= formatMoney((float)$r['total_amount']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $r['status']==='confirmed'?'success':'info' ?>">
                                <?= htmlspecialchars($r['status'] ?? '') ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($revenueRows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="6">TỔNG CỘNG</td>
                            <td class="text-end"><?= number_format($revTotalKm, 1) ?></td>
                            <td class="text-end text-warning"><?= formatMoney($revTotalAmount) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ TAB: CHI PHÍ ════ -->
    <?php elseif ($tab === 'cost'): ?>

    <div class="row g-3 mb-4">
        <?php
        $costCards = [
            ['⛽ Nhiên liệu',   $costData['_fuel_total'],  'warning'],
            ['🔧 Bảo dưỡng',   $costData['_maint_total'], 'danger'],
            ['📦 Chi phí khác', $costData['_other_total'], 'secondary'],
            ['💰 Tổng',         $costData['_grand_total'], 'dark'],
        ];
        foreach ($costCards as [$label, $val, $color]):
        ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-<?= $color ?> border-4">
                <div class="card-body py-3">
                    <div class="small text-muted"><?= $label ?></div>
                    <div class="fw-bold fs-5 text-<?= $color ?>"><?= formatMoney((float)$val) ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Nhiên liệu -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-warning bg-opacity-10 border-bottom py-2">
            <h6 class="fw-bold mb-0">⛽ Chi phí nhiên liệu (<?= count($costData['fuel']) ?> lần đổ)</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.83rem" id="exportTable">
                    <thead class="table-warning"><tr>
                        <th>Ngày</th><th>Xe</th><th>Lái xe</th>
                        <th class="text-end">Lít</th>
                        <th class="text-end">Thành tiền</th>
                        <th>Trạm</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($costData['fuel'] as $f): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($f['log_date'])) ?></td>
                        <td><?= htmlspecialchars($f['plate_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($f['driver'] ?? '—') ?></td>
                        <td class="text-end"><?= number_format((float)$f['liters_filled'], 2) ?></td>
                        <td class="text-end fw-semibold"><?= formatMoney((float)$f['total_cost']) ?></td>
                        <td><small><?= htmlspecialchars($f['station_name'] ?? '—') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($costData['fuel'])): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="fw-bold table-warning">
                        <tr>
                            <td colspan="3">TỔNG</td>
                            <td class="text-end"><?= number_format(array_sum(array_column($costData['fuel'],'liters_filled')),2) ?> L</td>
                            <td class="text-end"><?= formatMoney($costData['_fuel_total']) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Bảo dưỡng -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-danger bg-opacity-10 border-bottom py-2">
            <h6 class="fw-bold mb-0">🔧 Chi phí bảo dưỡng (<?= count($costData['maintenance']) ?> lần)</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                    <thead class="table-danger"><tr>
                        <th>Ngày</th><th>Xe</th><th>Loại</th>
                        <th>Mô tả</th><th>Gara</th>
                        <th class="text-end">Chi phí</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($costData['maintenance'] as $m): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($m['maintenance_date'])) ?></td>
                        <td><?= htmlspecialchars($m['plate_number'] ?? '') ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($m['maintenance_type'] ?? '') ?></span></td>
                        <td><small><?= htmlspecialchars($m['description'] ?? '—') ?></small></td>
                        <td><small><?= htmlspecialchars($m['garage_name'] ?? '—') ?></small></td>
                        <td class="text-end fw-semibold text-danger"><?= formatMoney((float)$m['total_cost']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($costData['maintenance'])): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="fw-bold table-danger">
                        <tr>
                            <td colspan="5">TỔNG</td>
                            <td class="text-end"><?= formatMoney($costData['_maint_total']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ TAB: P&L ════ -->
    <?php elseif ($tab === 'pl'): ?>

    <div class="row g-3 mb-4">
        <?php
        $plCards = [
            ['Tổng doanh thu', $plReport['total_revenue'], 'success'],
            ['Tổng chi phí',   $plReport['total_cost'],    'danger'],
            ['Lợi nhuận gộp',  $plReport['total_profit'],  $plReport['total_profit']>=0?'success':'danger'],
            ['Biên lợi nhuận', $plReport['margin'].'%',    $plReport['margin']>=15?'success':'warning'],
        ];
        foreach ($plCards as [$label, $val, $color]):
        ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-<?= $color ?> border-4">
                <div class="card-body py-4">
                    <div class="small text-muted mb-1"><?= $label ?></div>
                    <div class="fw-bold fs-4 text-<?= $color ?>">
                        <?= is_numeric($val) ? formatMoney((float)$val) : $val ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📊 Lãi / Lỗ theo tháng</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" id="exportTable">
                <thead class="table-dark"><tr>
                    <th>Tháng</th>
                    <th class="text-end">Doanh thu</th>
                    <th class="text-end">Nhiên liệu</th>
                    <th class="text-end">Bảo dưỡng</th>
                    <th class="text-end">CP khác</th>
                    <th class="text-end">Tổng CP</th>
                    <th class="text-end">Lợi nhuận</th>
                    <th class="text-center">Biên LN</th>
                </tr></thead>
                <tbody>
                <?php foreach ($plReport['monthly'] as $m):
                    $totalCp    = (float)$m['fuel_cost'] + (float)$m['maint_cost'] + (float)$m['other_cost'];
                    $profitVal  = (float)$m['profit'];
                    $marginVal  = (float)$m['revenue'] > 0
                        ? round($profitVal / (float)$m['revenue'] * 100, 1) : 0;
                ?>
                <tr>
                    <td class="fw-semibold"><?= htmlspecialchars($m['ym'] ?? '') ?></td>
                    <td class="text-end text-success"><?= formatMoney((float)$m['revenue']) ?></td>
                    <td class="text-end text-warning"><?= formatMoney((float)$m['fuel_cost']) ?></td>
                    <td class="text-end text-danger"><?= formatMoney((float)$m['maint_cost']) ?></td>
                    <td class="text-end"><?= formatMoney((float)$m['other_cost']) ?></td>
                    <td class="text-end text-danger"><?= formatMoney($totalCp) ?></td>
                    <td class="text-end fw-bold text-<?= $profitVal>=0?'success':'danger' ?>">
                        <?= formatMoney($profitVal) ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $marginVal>=15?'success':($marginVal>=0?'warning':'danger') ?>">
                            <?= $marginVal ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($plReport['monthly'])): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td>TỔNG CỘNG</td>
                        <td class="text-end text-success"><?= formatMoney($plReport['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney($plReport['total_fuel']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney($plReport['total_maint']) ?></td>
                        <td class="text-end"><?= formatMoney($plReport['total_other']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney($plReport['total_cost']) ?></td>
                        <td class="text-end text-<?= $plReport['total_profit']>=0?'success':'danger' ?> fw-bold">
                            <?= formatMoney($plReport['total_profit']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $plReport['margin']>=15?'success':'warning' ?>">
                                <?= $plReport['margin'] ?>%
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ════ TAB: KHÁCH HÀNG ════ -->
    <?php elseif ($tab === 'customer'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🏢 Báo cáo theo khách hàng</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem" id="exportTable">
                    <thead class="table-dark"><tr>
                        <th>Khách hàng</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">TB/chuyến</th>
                        <th class="text-end">% DT</th>
                        <th>Chuyến đầu</th>
                        <th>Chuyến cuối</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($customerReport as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($c['company_name'] ?? '') ?></td>
                        <td class="text-center"><?= $c['total_trips'] ?></td>
                        <td class="text-end"><?= number_format((float)$c['total_km']) ?></td>
                        <td class="text-end text-success fw-semibold"><?= formatMoney((float)$c['total_revenue']) ?></td>
                        <td class="text-end"><?= formatMoney((float)$c['avg_per_trip']) ?></td>
                        <td class="text-end">
                            <div class="progress" style="height:6px;min-width:60px">
                                <div class="progress-bar bg-primary"
                                     style="width:<?= $c['revenue_pct'] ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $c['revenue_pct'] ?>%</small>
                        </td>
                        <td class="small text-muted">
                            <?= $c['first_trip'] ? date('d/m/Y', strtotime($c['first_trip'])) : '—' ?>
                        </td>
                        <td class="small text-muted">
                            <?= $c['last_trip'] ? date('d/m/Y', strtotime($c['last_trip'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($customerReport)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ TAB: XE ════ -->
    <?php elseif ($tab === 'vehicle'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🚛 Báo cáo theo xe</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem" id="exportTable">
                    <thead class="table-dark"><tr>
                        <th>Biển số</th>
                        <th>Xe</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">CP nhiên liệu</th>
                        <th class="text-end">CP bảo dưỡng</th>
                        <th class="text-end">Lợi nhuận</th>
                        <th class="text-end">CP/km</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($vehicleReport as $v): ?>
                    <tr>
                        <td class="fw-bold text-primary"><?= htmlspecialchars($v['plate_number'] ?? '') ?></td>
                        <td class="small"><?= htmlspecialchars(trim(($v['brand']??'').' '.($v['model']??''))) ?></td>
                        <td class="text-center"><?= $v['total_trips'] ?></td>
                        <td class="text-end"><?= number_format((float)$v['total_km']) ?></td>
                        <td class="text-end text-success"><?= formatMoney((float)$v['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney((float)$v['fuel_cost']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney((float)$v['maint_cost']) ?></td>
                        <td class="text-end fw-bold text-<?= $v['profit']>=0?'success':'danger' ?>">
                            <?= formatMoney((float)$v['profit']) ?>
                        </td>
                        <td class="text-end small"><?= number_format($v['cost_per_km'],2) ?>đ</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($vehicleReport)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ TAB: LÁI XE ════ -->
    <?php elseif ($tab === 'driver'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">👤 Báo cáo theo lái xe</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.85rem" id="exportTable">
                    <thead class="table-dark"><tr>
                        <th>Lái xe</th>
                        <th>SĐT</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">TB km/chuyến</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">CP nhiên liệu</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($driverReport as $d): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($d['full_name'] ?? '') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($d['phone'] ?? '—') ?></td>
                        <td class="text-center"><?= $d['total_trips'] ?></td>
                        <td class="text-end"><?= number_format((float)$d['total_km']) ?></td>
                        <td class="text-end"><?= number_format((float)$d['avg_km_per_trip'], 1) ?></td>
                        <td class="text-end text-success"><?= formatMoney((float)$d['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney((float)$d['fuel_cost']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($driverReport)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════ TAB: NHIÊN LIỆU ════ -->
    <?php elseif ($tab === 'fuel'): ?>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-warning border-4">
                <div class="card-body py-3">
                    <div class="small text-muted">Tổng lít</div>
                    <div class="fw-bold fs-5 text-warning">
                        <?= number_format($fuelReport['_total_liters'], 2) ?> L
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-danger border-4">
                <div class="card-body py-3">
                    <div class="small text-muted">Tổng tiền nhiên liệu</div>
                    <div class="fw-bold fs-5 text-danger">
                        <?= formatMoney($fuelReport['_total_cost']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chi tiết -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">⛽ Chi tiết đổ nhiên liệu</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.83rem" id="exportTable">
                    <thead class="table-warning"><tr>
                        <th>Ngày</th><th>Xe</th><th>Lái xe</th>
                        <th class="text-end">Lít</th>
                        <th class="text-end">Thành tiền</th>
                        <th>Trạm</th>
                        <th class="text-end">L/100km</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($fuelReport['detail'] as $f): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($f['log_date'])) ?></td>
                        <td><?= htmlspecialchars($f['plate_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($f['driver'] ?? '—') ?></td>
                        <td class="text-end"><?= number_format((float)$f['liters_filled'], 2) ?></td>
                        <td class="text-end fw-semibold"><?= formatMoney((float)$f['total_cost']) ?></td>
                        <td><small><?= htmlspecialchars($f['station_name'] ?? '—') ?></small></td>
                        <td class="text-end">
                            <?php if (!empty($f['lper100km'])): ?>
                            <span class="badge bg-<?= $f['lper100km'] <= 9 ? 'success' : 'warning' ?>">
                                <?= $f['lper100km'] ?>
                            </span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fuelReport['detail'])): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">Không có d�� liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Theo xe -->
    <?php if (!empty($fuelReport['by_vehicle'])): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🚛 Tổng hợp nhiên liệu theo xe</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light"><tr>
                    <th>Xe</th>
                    <th class="text-center">Lần đổ</th>
                    <th class="text-end">Tổng lít</th>
                    <th class="text-end">Tổng tiền</th>
                    <th class="text-end">TB L/100km</th>
                </tr></thead>
                <tbody>
                <?php foreach ($fuelReport['by_vehicle'] as $bv): ?>
                <tr>
                    <td class="fw-bold text-primary"><?= htmlspecialchars($bv['plate_number'] ?? '') ?></td>
                    <td class="text-center"><?= $bv['fills'] ?></td>
                    <td class="text-end"><?= number_format((float)$bv['total_liters'], 2) ?> L</td>
                    <td class="text-end text-danger"><?= formatMoney((float)$bv['total_cost']) ?></td>
                    <td class="text-end">
                        <?php if ($bv['avg_lper100km']): ?>
                        <span class="badge bg-<?= $bv['avg_lper100km'] <= 9 ? 'success' : 'warning' ?>">
                            <?= $bv['avg_lper100km'] ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // end tab switch ?>

</div><!-- /container -->
</div><!-- /main-content -->

<!-- Chart.js cho overview -->
<?php if ($tab === 'overview' && !empty($overview['monthly'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const labels  = <?= json_encode(array_column($overview['monthly'], 'ym')) ?>;
const revenue = <?= json_encode(array_map('floatval', array_column($overview['monthly'], 'revenue'))) ?>;
const trips   = <?= json_encode(array_map('intval',   array_column($overview['monthly'], 'trips'))) ?>;

new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                label: 'Doanh thu (đ)',
                data: revenue,
                backgroundColor: 'rgba(13,110,253,0.6)',
                borderColor: 'rgba(13,110,253,1)',
                borderWidth: 1,
                borderRadius: 4,
                yAxisID: 'y',
            },
            {
                label: 'Số chuyến',
                data: trips,
                type: 'line',
                borderColor: '#198754',
                backgroundColor: 'rgba(25,135,84,.15)',
                fill: true,
                tension: 0.3,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        plugins: { legend: { position: 'top' } },
        scales: {
            y:  { beginAtZero: true, position: 'left',
                  ticks: { callback: v => new Intl.NumberFormat('vi-VN').format(v) } },
            y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } },
            x:  { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<!-- Export Excel helper -->
<script>
function exportCurrentTab() {
    const tbl = document.getElementById('exportTable');
    if (!tbl) { alert('Không có bảng dữ liệu để xuất'); return; }
    let csv = '';
    for (const row of tbl.rows) {
        const cols = [];
        for (const cell of row.cells) {
            let txt = cell.innerText.replace(/\n/g,' ').replace(/,/g,'').trim();
            cols.push('"' + txt + '"');
        }
        csv += cols.join(',') + '\n';
    }
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'bao_cao_<?= $tab ?>_<?= date('Ymd') ?>.csv';
    a.click();
}
</script>

<!-- Print styles -->
<style>
@media print {
    .sidebar, nav, form, .btn, .alert { display: none !important; }
    .main-content { margin: 0 !important; }
    .collapse { display: block !important; }
}
</style>

<?php include '../../includes/footer.php'; ?>