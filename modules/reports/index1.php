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
        return $s->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

// ════════════════════════════════════════════════════════════
// 1. TỔNG QUAN (overview)
// ════════════════════════════════════════════════════════════
$overview = [];
if ($tab === 'overview') {
    // Doanh thu
    $overview['revenue'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_amount),0) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    // Số chuyến
    $overview['trips'] = (int)safeCol($pdo,"
        SELECT COUNT(*) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    // Tổng km
    $overview['km'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_km),0) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ",[$dateFrom,$dateTo]);

    // Chi phí nhiên liệu
    $overview['fuel_cost'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_cost),0) FROM fuel_logs
        WHERE log_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    // Chi phí bảo dưỡng
    $overview['maint_cost'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(total_cost),0) FROM vehicle_maintenance
        WHERE maintenance_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    // Chi phí khác (nếu có bảng expenses)
    $overview['other_cost'] = (float)safeCol($pdo,"
        SELECT COALESCE(SUM(amount),0) FROM expenses
        WHERE expense_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

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
        SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt
        FROM trips WHERE trip_date BETWEEN ? AND ?
        GROUP BY status
    ",[$dateFrom,$dateTo]);

    // Doanh thu theo tháng (12 tháng gần nhất)
    $overview['monthly'] = safeQuery($pdo,"
        SELECT DATE_FORMAT(trip_date,'%Y-%m') AS ym,
               COUNT(*) AS trips,
               COALESCE(SUM(total_amount),0) AS revenue,
               COALESCE(SUM(total_km),0) AS km
        FROM trips
        WHERE trip_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
          AND status IN ('completed','confirmed')
        GROUP BY ym ORDER BY ym
    ");

    // Top 5 khách hàng
    $overview['top_customers'] = safeQuery($pdo,"
        SELECT c.company_name, COUNT(*) AS trips,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t JOIN customers c ON t.customer_id = c.id
        WHERE t.trip_date BETWEEN ? AND ? AND t.status IN ('completed','confirmed')
        GROUP BY c.id ORDER BY revenue DESC LIMIT 5
    ",[$dateFrom,$dateTo]);

    // Top 5 lái xe
    $overview['top_drivers'] = safeQuery($pdo,"
        SELECT u.full_name, COUNT(*) AS trips,
               COALESCE(SUM(t.total_km),0) AS km,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        JOIN users u ON d.user_id = u.id
        WHERE t.trip_date BETWEEN ? AND ? AND t.status IN ('completed','confirmed')
        GROUP BY d.id ORDER BY trips DESC LIMIT 5
    ",[$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 2. BÁO CÁO DOANH THU / HOÁ ĐƠN ĐẦU RA
// ════════════════════════════════════════════════════════════
$revenueData = [];
if ($tab === 'revenue') {
    $sql = "
        SELECT t.trip_code, t.trip_date, t.route_from, t.route_to,
               t.total_km, t.total_amount, t.status,
               c.company_name AS customer,
               u.full_name AS driver,
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
    $revenueData = safeQuery($pdo, $sql, $params);

    // Tổng
    $revenueData['_total_amount'] = array_sum(array_column($revenueData, 'total_amount'));
    $revenueData['_total_km']     = array_sum(array_column($revenueData, 'total_km'));
    $revenueData['_total_trips']  = count($revenueData) - 2; // trừ 2 key tổng
}

// ════════════════════════════════════════════════════════════
// 3. BÁO CÁO CHI PHÍ ĐẦU VÀO
// ════════════════════════════════════════════════════════════
$costData = [];
if ($tab === 'cost') {
    // Nhiên liệu
    $costData['fuel'] = safeQuery($pdo,"
        SELECT fl.log_date, fl.liters_filled, fl.price_per_liter,
               fl.total_cost, fl.odometer_reading,
               v.plate_number, u.full_name AS driver,
               fl.station_name
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ",[$dateFrom,$dateTo]);

    // Bảo dưỡng
    $costData['maintenance'] = safeQuery($pdo,"
        SELECT vm.maintenance_date, vm.maintenance_type, vm.description,
               vm.total_cost, vm.garage_name, vm.odometer,
               v.plate_number
        FROM vehicle_maintenance vm
        JOIN vehicles v ON vm.vehicle_id = v.id
        WHERE vm.maintenance_date BETWEEN ? AND ?
        ORDER BY vm.maintenance_date DESC
    ",[$dateFrom,$dateTo]);

    // Chi phí khác
    $costData['others'] = safeQuery($pdo,"
        SELECT expense_date, category, description, amount, note
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
        ORDER BY expense_date DESC
    ",[$dateFrom,$dateTo]);

    // Tổng hợp
    $costData['_fuel_total']  = array_sum(array_column($costData['fuel'], 'total_cost'));
    $costData['_maint_total'] = array_sum(array_column($costData['maintenance'], 'total_cost'));
    $costData['_other_total'] = array_sum(array_column($costData['others'], 'amount'));
    $costData['_grand_total'] = $costData['_fuel_total'] + $costData['_maint_total'] + $costData['_other_total'];

    // Phân tích theo loại chi phí
    $costData['by_type'] = safeQuery($pdo,"
        SELECT 'Nhiên liệu' AS type,
               COUNT(*) AS count,
               COALESCE(SUM(total_cost),0) AS total
        FROM fuel_logs WHERE log_date BETWEEN ? AND ?
        UNION ALL
        SELECT 'Bảo dưỡng', COUNT(*), COALESCE(SUM(total_cost),0)
        FROM vehicle_maintenance WHERE maintenance_date BETWEEN ? AND ?
        UNION ALL
        SELECT COALESCE(category,'Khác'), COUNT(*), COALESCE(SUM(amount),0)
        FROM expenses WHERE expense_date BETWEEN ? AND ?
        GROUP BY category
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 4. BÁO CÁO LÃI LỖ TỪNG KHÁCH HÀNG
// ════════════════════════════════════════════════════════════
$customerReport = [];
if ($tab === 'customer') {
    $customerReport = safeQuery($pdo,"
        SELECT
            c.id, c.company_name, c.contact_name, c.phone,
            COUNT(t.id)                              AS total_trips,
            COALESCE(SUM(t.total_km),0)              AS total_km,
            COALESCE(SUM(t.total_amount),0)          AS total_revenue,
            COALESCE(AVG(t.total_amount),0)          AS avg_per_trip,
            MIN(t.trip_date)                         AS first_trip,
            MAX(t.trip_date)                         AS last_trip,
            -- Chi phí nhiên liệu tương ứng (ước tính theo km tỷ lệ)
            COALESCE(SUM(t.total_km),0) * 0.085      AS est_fuel_cost
        FROM customers c
        LEFT JOIN trips t ON t.customer_id = c.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE c.is_active = TRUE
        GROUP BY c.id
        ORDER BY total_revenue DESC
    ",[$dateFrom,$dateTo]);

    // Gắn thêm % doanh thu
    $grandRev = array_sum(array_column($customerReport, 'total_revenue'));
    foreach ($customerReport as &$row) {
        $row['revenue_pct'] = $grandRev > 0
            ? round($row['total_revenue'] / $grandRev * 100, 1) : 0;
        $row['est_profit']  = $row['total_revenue'] - $row['est_fuel_cost'];
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 5. BÁO CÁO XE
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
            -- Nhiên liệu
            COALESCE((SELECT SUM(fl.total_cost)
                      FROM fuel_logs fl
                      WHERE fl.vehicle_id = v.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost,
            COALESCE((SELECT SUM(fl.liters_filled)
                      FROM fuel_logs fl
                      WHERE fl.vehicle_id = v.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_liters,
            -- Bảo dưỡng
            COALESCE((SELECT SUM(vm.total_cost)
                      FROM vehicle_maintenance vm
                      WHERE vm.vehicle_id = v.id
                        AND vm.maintenance_date BETWEEN ? AND ?),0) AS maint_cost,
            COALESCE((SELECT COUNT(*)
                      FROM vehicle_maintenance vm
                      WHERE vm.vehicle_id = v.id
                        AND vm.maintenance_date BETWEEN ? AND ?),0) AS maint_count
        FROM vehicles v
        LEFT JOIN trips t ON t.vehicle_id = v.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        GROUP BY v.id
        ORDER BY total_km DESC
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);

    foreach ($vehicleReport as &$row) {
        $row['total_cost']  = $row['fuel_cost'] + $row['maint_cost'];
        $row['profit']      = $row['total_revenue'] - $row['total_cost'];
        $row['km_per_liter']= $row['fuel_liters'] > 0
            ? round($row['total_km'] / $row['fuel_liters'], 2) : 0;
        $row['cost_per_km'] = $row['total_km'] > 0
            ? round($row['total_cost'] / $row['total_km'], 2) : 0;
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 6. BÁO CÁO LÁI XE
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
            -- Nhiên liệu
            COALESCE((SELECT SUM(fl.total_cost)
                      FROM fuel_logs fl WHERE fl.driver_id = d.id
                        AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost,
            -- Lỗi chủ quan
            COALESCE((SELECT COUNT(*) FROM vehicle_maintenance vm
                      JOIN trips tt ON tt.vehicle_id = vm.vehicle_id
                      WHERE tt.driver_id = d.id
                        AND vm.is_driver_fault = TRUE
                        AND vm.maintenance_date BETWEEN ? AND ?),0) AS faults,
            -- KPI
            COALESCE((SELECT AVG(ks.score_total)
                      FROM kpi_scores ks WHERE ks.driver_id = d.id
                        AND ks.period_from >= ? AND ks.period_to <= ?),0) AS avg_kpi
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN trips t ON t.driver_id = d.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE d.is_active = TRUE
        GROUP BY d.id
        ORDER BY total_trips DESC
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 7. BÁO CÁO LÃI LỖ TỔNG HỢP (P&L)
// ════════════════════════════════════════════════════════════
$plReport = [];
if ($tab === 'pl') {
    // Theo tháng
    $plReport['monthly'] = safeQuery($pdo,"
        SELECT
            DATE_FORMAT(m.ym, '%Y-%m') AS ym,
            COALESCE(r.revenue,0)   AS revenue,
            COALESCE(f.fuel,0)      AS fuel_cost,
            COALESCE(mn.maint,0)    AS maint_cost,
            COALESCE(ex.other,0)    AS other_cost,
            COALESCE(r.revenue,0)
              - COALESCE(f.fuel,0)
              - COALESCE(mn.maint,0)
              - COALESCE(ex.other,0) AS profit
        FROM (
            SELECT DISTINCT DATE_FORMAT(trip_date,'%Y-%m-01') AS ym
            FROM trips WHERE trip_date BETWEEN ? AND ?
        ) m
        LEFT JOIN (
            SELECT DATE_FORMAT(trip_date,'%Y-%m-01') AS ym,
                   SUM(total_amount) AS revenue
            FROM trips WHERE trip_date BETWEEN ? AND ?
              AND status IN ('completed','confirmed')
            GROUP BY ym
        ) r ON r.ym = m.ym
        LEFT JOIN (
            SELECT DATE_FORMAT(log_date,'%Y-%m-01') AS ym,
                   SUM(total_cost) AS fuel
            FROM fuel_logs WHERE log_date BETWEEN ? AND ?
            GROUP BY ym
        ) f ON f.ym = m.ym
        LEFT JOIN (
            SELECT DATE_FORMAT(maintenance_date,'%Y-%m-01') AS ym,
                   SUM(total_cost) AS maint
            FROM vehicle_maintenance WHERE maintenance_date BETWEEN ? AND ?
            GROUP BY ym
        ) mn ON mn.ym = m.ym
        LEFT JOIN (
            SELECT DATE_FORMAT(expense_date,'%Y-%m-01') AS ym,
                   SUM(amount) AS other
            FROM expenses WHERE expense_date BETWEEN ? AND ?
            GROUP BY ym
        ) ex ON ex.ym = m.ym
        ORDER BY m.ym
    ",[$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo,$dateFrom,$dateTo]);

    $plReport['total_revenue'] = array_sum(array_column($plReport['monthly'], 'revenue'));
    $plReport['total_fuel']    = array_sum(array_column($plReport['monthly'], 'fuel_cost'));
    $plReport['total_maint']   = array_sum(array_column($plReport['monthly'], 'maint_cost'));
    $plReport['total_other']   = array_sum(array_column($plReport['monthly'], 'other_cost'));
    $plReport['total_cost']    = $plReport['total_fuel'] + $plReport['total_maint'] + $plReport['total_other'];
    $plReport['total_profit']  = $plReport['total_revenue'] - $plReport['total_cost'];
    $plReport['margin']        = $plReport['total_revenue'] > 0
        ? round($plReport['total_profit'] / $plReport['total_revenue'] * 100, 1) : 0;
}

// ════════════════════════════════════════════════════════════
// 8. BÁO CÁO NHIÊN LIỆU
// ═════════════════════════════════════���══════════════════════
$fuelReport = [];
if ($tab === 'fuel') {
    $fuelReport['detail'] = safeQuery($pdo,"
        SELECT fl.log_date, fl.liters_filled, fl.price_per_liter,
               fl.total_cost, fl.odometer_reading, fl.station_name,
               v.plate_number, v.brand, v.model,
               u.full_name AS driver,
               -- L/100km
               CASE WHEN (fl.km_after - fl.km_before) > 0
                    THEN ROUND(fl.liters_filled / (fl.km_after - fl.km_before) * 100, 2)
                    ELSE NULL END AS lper100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u ON d.user_id = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ",[$dateFrom,$dateTo]);

    // Theo xe
    $fuelReport['by_vehicle'] = safeQuery($pdo,"
        SELECT v.plate_number, v.brand, v.model,
               COUNT(*) AS fills,
               ROUND(SUM(fl.liters_filled),2) AS total_liters,
               ROUND(SUM(fl.total_cost),0)    AS total_cost,
               ROUND(AVG(fl.price_per_liter),0) AS avg_price,
               ROUND(AVG(
                   CASE WHEN (fl.km_after - fl.km_before) > 0
                        THEN fl.liters_filled / (fl.km_after - fl.km_before) * 100
                   END),2) AS avg_lper100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id ORDER BY total_cost DESC
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

// ── Tab config ──────────────────────────────────────────────
$tabs = [
    'overview' => ['icon'=>'fa-chart-pie',       'label'=>'Tổng quan'],
    'revenue'  => ['icon'=>'fa-file-invoice-dollar','label'=>'Doanh thu'],
    'cost'     => ['icon'=>'fa-money-bill-wave',  'label'=>'Chi phí đầu vào'],
    'pl'       => ['icon'=>'fa-balance-scale',    'label'=>'Lãi / Lỗ'],
    'customer' => ['icon'=>'fa-building',         'label'=>'Theo KH'],
    'vehicle'  => ['icon'=>'fa-truck',            'label'=>'Theo xe'],
    'driver'   => ['icon'=>'fa-id-card',          'label'=>'Theo lái xe'],
    'fuel'     => ['icon'=>'fa-gas-pump',         'label'=>'Nhiên liệu'],
];
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- ── Header ── -->
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

    <!-- ── Bộ lọc ── -->
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
                            <?= htmlspecialchars($c['company_name']) ?>
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
                            <?= htmlspecialchars($v['plate_number']) ?>
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
                            <?= htmlspecialchars($d['full_name']) ?>
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
                            'Hôm nay'    => [date('Y-m-d'), date('Y-m-d')],
                            'Tháng này'  => [date('Y-m-01'), date('Y-m-d')],
                            'Tháng trước'=> [date('Y-m-01', strtotime('first day of last month')),
                                             date('Y-m-t',  strtotime('last day of last month'))],
                            'Năm nay'    => [date('Y-01-01'), date('Y-m-d')],
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

    <!-- ── Tabs ── -->
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

    <!-- ════════════════════════════════════════
         TAB: TỔNG QUAN
    ════════════════════════════════════════ -->
    <?php if ($tab === 'overview'): ?>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['Doanh thu',      formatMoney($overview['revenue']),   'primary', 'fa-chart-line'],
            ['Tổng chi phí',   formatMoney($overview['total_cost']),'danger',  'fa-money-bill-wave'],
            ['Lợi nhuận',      formatMoney($overview['profit']),    $overview['profit']>=0?'success':'danger', 'fa-coins'],
            ['Biên lợi nhuận', $overview['profit_rate'].'%',        $overview['profit_rate']>=20?'success':'warning', 'fa-percentage'],
            ['Số chuyến',      number_format($overview['trips']).' chuyến', 'info',    'fa-route'],
            ['Tổng km',        number_format($overview['km']).' km','secondary','fa-road'],
            ['TB/chuyến',      formatMoney($overview['avg_per_trip']),'warning','fa-calculator'],
            ['CP/km',          number_format($overview['cost_per_km']).'đ/km','dark',  'fa-gas-pump'],
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

    <!-- Chi phí breakdown -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">💰 Cơ cấu chi phí</h6>
                </div>
                <div class="card-body">
                    <?php
                    $costItems = [
                        ['Nhiên liệu', $overview['fuel_cost'],  $overview['total_cost'], 'warning'],
                        ['Bảo dưỡng',  $overview['maint_cost'], $overview['total_cost'], 'danger'],
                        ['Chi phí khác',$overview['other_cost'],$overview['total_cost'], 'secondary'],
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

        <!-- Top khách hàng -->
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
                                <small><?= htmlspecialchars($c['company_name']) ?></small>
                            </td>
                            <td class="text-center small"><?= $c['trips'] ?></td>
                            <td class="text-end small fw-semibold text-success">
                                <?= formatMoney($c['revenue']) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overview['top_customers'])): ?>
                        <tr><td colspan="3" class="text-center text-muted small py-3">Không có dữ li��u</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Top lái xe -->
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
                                <small><?= htmlspecialchars($d['full_name']) ?></small>
                            </td>
                            <td class="text-center small"><?= $d['trips'] ?></td>
                            <td class="text-end small"><?= number_format($d['km']) ?></td>
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

    <!-- Biểu đồ doanh thu theo tháng -->
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
                    'completed' =>['✅ Hoàn thành','success'],
                    'confirmed' =>['✔️ Xác nhận','info'],
                    'in_progress'=>['🚛 Đang chạy','primary'],
                    'pending'   =>['⏳ Chờ','warning'],
                    'cancelled' =>['❌ Huỷ','danger'],
                    'rejected'  =>['🚫 Từ chối','danger'],
                ];
                foreach ($overview['by_status'] as $s):
                    [$slabel, $scolor] = $statusMap[$s['status']] ?? [$s['status'],'secondary'];
                ?>
                <tr>
                    <td><span class="badge bg-<?= $scolor ?>"><?= $slabel ?></span></td>
                    <td class="text-center"><?= number_format($s['cnt']) ?></td>
                    <td class="text-end"><?= formatMoney($s['amt']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: DOANH THU / HOÁ ĐƠN ĐẦU RA
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'revenue'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">📄 Chi tiết doanh thu / Hoá đơn đầu ra</h6>
            <div class="text-end small text-muted">
                <?php $rows = array_filter($revenueData, 'is_array'); ?>
                <strong><?= count($rows) ?></strong> chuyến ·
                Tổng: <strong class="text-success"><?= formatMoney($revenueData['_total_amount'] ?? 0) ?></strong> ·
                <?= number_format($revenueData['_total_km'] ?? 0) ?> km
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
                    <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><code><?= $r['trip_code'] ?></code></td>
                        <td><?= date('d/m/Y', strtotime($r['trip_date'])) ?></td>
                        <td><?= htmlspecialchars($r['customer']) ?></td>
                        <td><?= htmlspecialchars($r['driver']) ?></td>
                        <td><span class="badge bg-secondary"><?= $r['plate_number'] ?></span></td>
                        <td>
                            <small><?= htmlspecialchars($r['route_from']) ?>
                            → <?= htmlspecialchars($r['route_to']) ?></small>
                        </td>
                        <td class="text-end"><?= number_format($r['total_km'], 1) ?></td>
                        <td class="text-end fw-semibold text-success"><?= formatMoney($r['total_amount']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $r['status']==='completed'?'success':'info' ?>">
                                <?= $r['status'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rows)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="6">TỔNG CỘNG</td>
                            <td class="text-end"><?= number_format($revenueData['_total_km'] ?? 0, 1) ?></td>
                            <td class="text-end text-success"><?= formatMoney($revenueData['_total_amount'] ?? 0) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: CHI PHÍ ĐẦU VÀO
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'cost'): ?>

    <!-- Tổng chi phí -->
    <div class="row g-3 mb-4">
        <?php
        $costCards = [
            ['⛽ Nhiên liệu', $costData['_fuel_total'],  'warning'],
            ['🔧 Bảo dưỡng',  $costData['_maint_total'], 'danger'],
            ['📦 Chi phí khác',$costData['_other_total'],'secondary'],
            ['💰 Tổng chi phí',$costData['_grand_total'],'dark'],
        ];
        foreach ($costCards as [$label, $val, $color]):
        ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center border-start border-<?= $color ?> border-4">
                <div class="card-body py-3">
                    <div class="small text-muted"><?= $label ?></div>
                    <div class="fw-bold fs-5 text-<?= $color ?>"><?= formatMoney($val) ?></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Chi phí nhiên liệu -->
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
                        <th class="text-end">Đơn giá</th>
                        <th class="text-end">Thành tiền</th>
                        <th>Trạm</th>
                        <th class="text-end">L/100km</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($costData['fuel'] as $f): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($f['log_date'])) ?></td>
                        <td><?= $f['plate_number'] ?></td>
                        <td><?= htmlspecialchars($f['driver'] ?? '—') ?></td>
                        <td class="text-end"><?= number_format($f['liters_filled'], 2) ?></td>
                        <td class="text-end"><?= number_format($f['price_per_liter']) ?>đ</td>
                        <td class="text-end fw-semibold"><?= formatMoney($f['total_cost']) ?></td>
                        <td><small><?= htmlspecialchars($f['station_name'] ?? '—') ?></small></td>
                        <td class="text-end">
                            <?php if ($f['lper100km']): ?>
                            <span class="badge bg-<?= $f['lper100km'] <= 9 ? 'success' : 'warning' ?>">
                                <?= $f['lper100km'] ?>
                            </span>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($costData['fuel'])): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="fw-bold table-warning">
                        <tr>
                            <td colspan="3">TỔNG</td>
                            <td class="text-end"><?= number_format(array_sum(array_column($costData['fuel'],'liters_filled')),2) ?> L</td>
                            <td></td>
                            <td class="text-end"><?= formatMoney($costData['_fuel_total']) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Bảo dưỡng -->
    <div class="card border-0 shadow-sm mb-3">
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
                        <td><?= $m['plate_number'] ?></td>
                        <td><span class="badge bg-secondary"><?= $m['maintenance_type'] ?></span></td>
                        <td><small><?= htmlspecialchars($m['description'] ?? '—') ?></small></td>
                        <td><small><?= htmlspecialchars($m['garage_name'] ?? '—') ?></small></td>
                        <td class="text-end fw-semibold text-danger"><?= formatMoney($m['total_cost']) ?></td>
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

    <!-- Chi phí khác -->
    <?php if (!empty($costData['others'])): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-secondary bg-opacity-10 border-bottom py-2">
            <h6 class="fw-bold mb-0">📦 Chi phí khác</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-secondary"><tr>
                    <th>Ngày</th><th>Danh mục</th><th>Mô tả</th>
                    <th class="text-end">Số tiền</th><th>Ghi chú</th>
                </tr></thead>
                <tbody>
                <?php foreach ($costData['others'] as $e): ?>
                <tr>
                    <td><?= date('d/m/Y', strtotime($e['expense_date'])) ?></td>
                    <td><?= htmlspecialchars($e['category'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($e['description'] ?? '—') ?></td>
                    <td class="text-end"><?= formatMoney($e['amount']) ?></td>
                    <td><small class="text-muted"><?= htmlspecialchars($e['note'] ?? '') ?></small></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="fw-bold table-secondary">
                    <tr>
                        <td colspan="3">TỔNG</td>
                        <td class="text-end"><?= formatMoney($costData['_other_total']) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════
         TAB: LÃI / LỖ (P&L)
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'pl'): ?>

    <!-- Tổng P&L -->
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
                        <?= is_numeric($val) ? formatMoney($val) : $val ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Bảng P&L theo tháng -->
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
                    <th class="text-end">Chi phí khác</th>
                    <th class="text-end">Tổng CP</th>
                    <th class="text-end">Lợi nhuận</th>
                    <th class="text-center">Biên LN</th>
                </tr></thead>
                <tbody>
                <?php foreach ($plReport['monthly'] as $m):
                    $totalCp = $m['fuel_cost'] + $m['maint_cost'] + $m['other_cost'];
                    $margin  = $m['revenue'] > 0 ? round($m['profit']/$m['revenue']*100,1) : 0;
                ?>
                <tr>
                    <td class="fw-semibold"><?= $m['ym'] ?></td>
                    <td class="text-end text-success"><?= formatMoney($m['revenue']) ?></td>
                    <td class="text-end text-warning"><?= formatMoney($m['fuel_cost']) ?></td>
                    <td class="text-end text-danger"><?= formatMoney($m['maint_cost']) ?></td>
                    <td class="text-end"><?= formatMoney($m['other_cost']) ?></td>
                    <td class="text-end text-danger"><?= formatMoney($totalCp) ?></td>
                    <td class="text-end fw-bold text-<?= $m['profit']>=0?'success':'danger' ?>">
                        <?= formatMoney($m['profit']) ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $margin>=15?'success':($margin>=0?'warning':'danger') ?>">
                            <?= $margin ?>%
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
                        <td>TỔNG</td>
                        <td class="text-end text-success"><?= formatMoney($plReport['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney($plReport['total_fuel']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney($plReport['total_maint']) ?></td>
                        <td class="text-end"><?= formatMoney($plReport['total_other']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney($plReport['total_cost']) ?></td>
                        <td class="text-end text-<?= $plReport['total_profit']>=0?'success':'danger' ?>">
                            <?= formatMoney($plReport['total_profit']) ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $plReport['margin']>=15?'success':($plReport['margin']>=0?'warning':'danger') ?>">
                                <?= $plReport['margin'] ?>%
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: THEO KHÁCH HÀNG
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'customer'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🏢 Lãi lỗ theo từng khách hàng</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="exportTable">
                    <thead class="table-dark"><tr>
                        <th>#</th><th>Khách hàng</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Km</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">TB/chuyến</th>
                        <th class="text-center">% DT</th>
                        <th class="text-end">CP NL (ước)</th>
                        <th class="text-end">LN ước tính</th>
                        <th>Chuyến gần nhất</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($customerReport as $i => $c): ?>
                    <tr class="<?= $c['total_trips']==0?'opacity-50':'' ?>">
                        <td class="text-muted small"><?= $i+1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($c['company_name']) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($c['contact_name'] ?? '') ?></div>
                        </td>
                        <td class="text-center fw-bold"><?= $c['total_trips'] ?></td>
                        <td class="text-end small"><?= number_format($c['total_km']) ?></td>
                        <td class="text-end fw-semibold text-success"><?= formatMoney($c['total_revenue']) ?></td>
                        <td class="text-end small"><?= formatMoney($c['avg_per_trip']) ?></td>
                        <td class="text-center">
                            <div class="progress" style="height:6px;border-radius:3px">
                                <div class="progress-bar bg-primary" style="width:<?= $c['revenue_pct'] ?>%"></div>
                            </div>
                            <small><?= $c['revenue_pct'] ?>%</small>
                        </td>
                        <td class="text-end small text-warning"><?= formatMoney($c['est_fuel_cost']) ?></td>
                        <td class="text-end fw-semibold text-<?= $c['est_profit']>=0?'success':'danger' ?>">
                            <?= formatMoney($c['est_profit']) ?>
                        </td>
                        <td class="small text-muted">
                            <?= $c['last_trip'] ? date('d/m/Y', strtotime($c['last_trip'])) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($customerReport)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: THEO XE
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'vehicle'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🚛 Hiệu quả từng xe</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="exportTable" style="font-size:.84rem">
                    <thead class="table-dark"><tr>
                        <th>Biển số</th><th>Xe</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">Chi NL</th>
                        <th class="text-end">Chi BDưỡng</th>
                        <th class="text-end">Tổng CP</th>
                        <th class="text-end">LN ước</th>
                        <th class="text-end">L/km</th>
                        <th class="text-end">CP/km</th>
                        <th class="text-center">Tình trạng</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($vehicleReport as $v): ?>
                    <tr>
                        <td><span class="badge bg-dark"><?= $v['plate_number'] ?></span></td>
                        <td>
                            <small><?= htmlspecialchars($v['brand'].' '.$v['model']) ?></small>
                            <div class="text-muted" style="font-size:10px"><?= $v['year'] ?></div>
                        </td>
                        <td class="text-center"><?= $v['total_trips'] ?></td>
                        <td class="text-end"><?= number_format($v['total_km']) ?></td>
                        <td class="text-end text-success"><?= formatMoney($v['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney($v['fuel_cost']) ?></td>
                        <td class="text-end text-danger"><?= formatMoney($v['maint_cost']) ?></td>
                        <td class="text-end"><?= formatMoney($v['total_cost']) ?></td>
                        <td class="text-end fw-semibold text-<?= $v['profit']>=0?'success':'danger' ?>">
                            <?= formatMoney($v['profit']) ?>
                        </td>
                        <td class="text-end small">
                            <?= $v['km_per_liter'] > 0 ? $v['km_per_liter'].' km/L' : '—' ?>
                        </td>
                        <td class="text-end small">
                            <?= $v['cost_per_km'] > 0 ? number_format($v['cost_per_km']).'đ' : '—' ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $v['vehicle_status']==='active'?'success':'secondary' ?>">
                                <?= $v['vehicle_status'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($vehicleReport)): ?>
                    <tr><td colspan="12" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="2">TỔNG</td>
                            <td class="text-center"><?= array_sum(array_column($vehicleReport,'total_trips')) ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($vehicleReport,'total_km'))) ?></td>
                            <td class="text-end text-success"><?= formatMoney(array_sum(array_column($vehicleReport,'total_revenue'))) ?></td>
                            <td class="text-end text-warning"><?= formatMoney(array_sum(array_column($vehicleReport,'fuel_cost'))) ?></td>
                            <td class="text-end text-danger"><?= formatMoney(array_sum(array_column($vehicleReport,'maint_cost'))) ?></td>
                            <td class="text-end"><?= formatMoney(array_sum(array_column($vehicleReport,'total_cost'))) ?></td>
                            <td class="text-end"><?= formatMoney(array_sum(array_column($vehicleReport,'profit'))) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: THEO LÁI XE
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'driver'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">👤 Hiệu quả từng lái xe</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="exportTable" style="font-size:.84rem">
                    <thead class="table-dark"><tr>
                        <th>#</th><th>Lái xe</th><th>Bằng lái</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng km</th>
                        <th class="text-end">TB km/chuyến</th>
                        <th class="text-end">Doanh thu</th>
                        <th class="text-end">Chi NL</th>
                        <th class="text-center">Lỗi</th>
                        <th class="text-center">KPI TB</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($driverReport as $i => $d): ?>
                    <tr>
                        <td class="text-muted small"><?= $i+1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($d['full_name']) ?></div>
                            <div class="text-muted small"><?= $d['phone'] ?></div>
                        </td>
                        <td><small><?= $d['license_number'] ?></small></td>
                        <td class="text-center fw-bold"><?= $d['total_trips'] ?></td>
                        <td class="text-end"><?= number_format($d['total_km']) ?></td>
                        <td class="text-end small"><?= number_format($d['avg_km_per_trip'], 1) ?></td>
                        <td class="text-end text-success"><?= formatMoney($d['total_revenue']) ?></td>
                        <td class="text-end text-warning"><?= formatMoney($d['fuel_cost']) ?></td>
                        <td class="text-center">
                            <?php if ($d['faults'] > 0): ?>
                            <span class="badge bg-danger"><?= $d['faults'] ?> lỗi</span>
                            <?php else: ?>
                            <span class="text-success small">✓</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($d['avg_kpi'] > 0): ?>
                            <span class="badge bg-<?= $d['avg_kpi']>=85?'success':($d['avg_kpi']>=70?'warning':'danger') ?>">
                                <?= number_format($d['avg_kpi'], 1) ?>đ
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($driverReport)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3">TỔNG</td>
                            <td class="text-center"><?= array_sum(array_column($driverReport,'total_trips')) ?></td>
                            <td class="text-end"><?= number_format(array_sum(array_column($driverReport,'total_km'))) ?></td>
                            <td></td>
                            <td class="text-end text-success"><?= formatMoney(array_sum(array_column($driverReport,'total_revenue'))) ?></td>
                            <td class="text-end text-warning"><?= formatMoney(array_sum(array_column($driverReport,'fuel_cost'))) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════
         TAB: NHIÊN LIỆU
    ════════════════════════════════════════ -->
    <?php elseif ($tab === 'fuel'): ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center border-start border-warning border-4">
                <div class="card-body py-3">
                    <div class="small text-muted">Tổng lít đổ</div>
                    <div class="fw-bold fs-4 text-warning"><?= number_format($fuelReport['_total_liters'], 2) ?> L</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center border-start border-danger border-4">
                <div class="card-body py-3">
                    <div class="small text-muted">Tổng chi phí nhiên liệu</div>
                    <div class="fw-bold fs-4 text-danger"><?= formatMoney($fuelReport['_total_cost']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center border-start border-info border-4">
                <div class="card-body py-3">
                    <div class="small text-muted">Đơn giá TB</div>
                    <div class="fw-bold fs-4 text-info">
                        <?= $fuelReport['_total_liters'] > 0
                            ? number_format($fuelReport['_total_cost'] / $fuelReport['_total_liters']).'đ/L'
                            : '—' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Theo xe -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">⛽ Tiêu hao nhiên liệu theo xe</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" id="exportTable">
                <thead class="table-warning"><tr>
                    <th>Xe</th><th class="text-center">Lần đổ</th>
                    <th class="text-end">Tổng lít</th>
                    <th class="text-end">Tổng chi</th>
                    <th class="text-end">Giá TB</th>
                    <th class="text-end">TB L/100km</th>
                </tr></thead>
                <tbody>
                <?php foreach ($fuelReport['by_vehicle'] as $fv): ?>
                <tr>
                    <td><span class="badge bg-dark"><?= $fv['plate_number'] ?></span>
                        <small class="ms-1 text-muted"><?= htmlspecialchars($fv['brand'].' '.$fv['model']) ?></small>
                    </td>
                    <td class="text-center"><?= $fv['fills'] ?></td>
                    <td class="text-end"><?= number_format($fv['total_liters'], 2) ?> L</td>
                    <td class="text-end text-danger"><?= formatMoney($fv['total_cost']) ?></td>
                    <td class="text-end"><?= number_format($fv['avg_price']) ?>đ</td>
                    <td class="text-end">
                        <?php if ($fv['avg_lper100km']): ?>
                        <span class="badge bg-<?= $fv['avg_lper100km'] <= 9 ? 'success' : 'warning' ?>">
                            <?= $fv['avg_lper100km'] ?> L/100km
                        </span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fuelReport['by_vehicle'])): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

<!-- ── Chart.js ── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
<?php if ($tab === 'overview' && !empty($overview['monthly'])): ?>
// ── Biểu đồ doanh thu ──
const labels  = <?= json_encode(array_column($overview['monthly'], 'ym')) ?>;
const revenues= <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $overview['monthly'])) ?>;
const trips   = <?= json_encode(array_map(fn($r) => (int)$r['trips'],   $overview['monthly'])) ?>;

new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels,
        datasets: [
            {
                label: 'Doanh thu (đ)',
                data: revenues,
                backgroundColor: 'rgba(13,110,253,.7)',
                borderRadius: 4,
                yAxisID: 'y',
            },
            {
                label: 'Số chuyến',
                data: trips,
                type: 'line',
                borderColor: '#fd7e14',
                backgroundColor: 'rgba(253,126,20,.1)',
                pointRadius: 4,
                tension: .3,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.datasetIndex === 0
                        ? ' ' + new Intl.NumberFormat('vi-VN').format(ctx.raw) + 'đ'
                        : ' ' + ctx.raw + ' chuyến'
                }
            }
        },
        scales: {
            y:  { type:'linear', position:'left',  ticks:{ callback: v => (v/1e6).toFixed(0)+'M' } },
            y1: { type:'linear', position:'right', grid:{ drawOnChartArea:false } }
        }
    }
});
<?php endif; ?>

// ── Xuất Excel ──
function exportCurrentTab() {
    const table = document.getElementById('exportTable');
    if (!table) { alert('Tab này không hỗ trợ xuất Excel. Vui lòng chọn tab khác.'); return; }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.table_to_sheet(table);
    XLSX.utils.book_append_sheet(wb, ws, 'BaoCao');
    XLSX.writeFile(wb, 'BaoCao_<?= $tab ?>_<?= $dateFrom ?>_<?= $dateTo ?>.xlsx');
}
</script>

<style>
.nav-tabs { border-bottom: 2px solid #dee2e6; }
.nav-tabs .nav-link { font-size: .83rem; padding: .4rem .8rem; white-space: nowrap; }
@media print {
    .sidebar, .card-body form, .btn, nav { display: none !important; }
    .main-content { margin: 0 !important; }
}
</style>

<?php include '../../includes/footer.php'; ?>