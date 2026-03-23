<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('reports', 'view');

$pdo       = getDBConnection();
$user      = currentUser();
$pageTitle = 'Báo Cáo Tổng Hợp';

// ── Tham số lọc ─────────────────────────────────────────────
$tab            = $_GET['tab']         ?? 'overview';
$dateFrom       = $_GET['date_from']   ?? date('Y-m-01');
$dateTo         = $_GET['date_to']     ?? date('Y-m-d');
$filterCustomer = (int)($_GET['customer_id'] ?? 0);
$filterVehicle  = (int)($_GET['vehicle_id']  ?? 0);
$filterDriver   = (int)($_GET['driver_id']   ?? 0);

if (isset($_GET['from_lock']) && $_GET['from_lock'] === '1' && $tab === 'overview') {
    $tab = 'revenue';
}

// ── Helpers ──────────────────────────────────────────────────
function safeQuery(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('safeQuery: ' . $e->getMessage() . ' | SQL: ' . $sql);
        return [];
    }
}
function safeCol(PDO $pdo, string $sql, array $params = []): mixed {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        $v = $s->fetchColumn();
        return ($v === false) ? 0 : $v;
    } catch (PDOException $e) {
        error_log('safeCol: ' . $e->getMessage());
        return 0;
    }
}

// ── Danh sách lọc chung ──────────────────────────────────────
$allCustomers = safeQuery($pdo,
    "SELECT id, company_name, short_name FROM customers WHERE is_active = TRUE ORDER BY company_name");
$allVehicles  = safeQuery($pdo,
    "SELECT id, plate_number FROM vehicles WHERE is_active = TRUE ORDER BY plate_number");
$allDrivers   = safeQuery($pdo,
    "SELECT d.id, u.full_name FROM drivers d JOIN users u ON d.user_id = u.id
     WHERE d.is_active = TRUE ORDER BY u.full_name");

// ════════════════════════════════════════════════════════════
// 1. TỔNG QUAN
// ════════════════════════════════════════════════════════════
$overview = [];
if ($tab === 'overview') {

    $lockedRevenue = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(si.total_amount), 0)
        FROM statement_items si
        JOIN statement_periods sp ON si.period_id = sp.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
    ", [$dateFrom, $dateTo]);

    $tripsRevenue = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(total_amount), 0) FROM trips
        WHERE trip_date BETWEEN ? AND ?
          AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    $overview['revenue']    = $lockedRevenue > 0 ? $lockedRevenue : $tripsRevenue;
    $overview['has_locked'] = $lockedRevenue > 0;

    $overview['trips'] = (int)safeCol($pdo, "
        SELECT COUNT(*) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    $overview['km'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(total_km), 0) FROM trips
        WHERE trip_date BETWEEN ? AND ? AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    // ✅ SỬA: dùng maintenance_logs, cột total_cost, log_date
    $overview['fuel_cost'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(amount), 0) FROM fuel_logs
        WHERE log_date BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]);

    $overview['maint_cost'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(total_cost), 0) FROM maintenance_logs
        WHERE log_date BETWEEN ? AND ?
          AND status = 'completed'
    ", [$dateFrom, $dateTo]);

    $overview['total_cost']   = $overview['fuel_cost'] + $overview['maint_cost'];
    $overview['profit']       = $overview['revenue'] - $overview['total_cost'];
    $overview['profit_rate']  = $overview['revenue'] > 0
        ? round($overview['profit'] / $overview['revenue'] * 100, 1) : 0;
    $overview['avg_per_trip'] = $overview['trips'] > 0
        ? round($overview['revenue'] / $overview['trips']) : 0;
    $overview['cost_per_km']  = $overview['km'] > 0
        ? round($overview['total_cost'] / $overview['km'], 2) : 0;

    $overview['by_status'] = safeQuery($pdo, "
        SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS amt
        FROM trips WHERE trip_date BETWEEN ? AND ?
        GROUP BY status
    ", [$dateFrom, $dateTo]);

    $overview['monthly'] = safeQuery($pdo, "
        WITH months AS (
            SELECT DISTINCT TO_CHAR(trip_date,'YYYY-MM') AS ym
            FROM trips
            WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
        ),
        td AS (
            SELECT TO_CHAR(trip_date,'YYYY-MM') AS ym,
                   COUNT(*) AS trips,
                   COALESCE(SUM(total_amount),0) AS revenue,
                   COALESCE(SUM(total_km),0)     AS km
            FROM trips
            WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
              AND status IN ('completed','confirmed')
            GROUP BY TO_CHAR(trip_date,'YYYY-MM')
        ),
        ld AS (
            SELECT TO_CHAR(sp.period_from,'YYYY-MM') AS ym,
                   COALESCE(SUM(si.total_amount),0)   AS locked_revenue
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND sp.period_from >= CURRENT_DATE - INTERVAL '12 months'
            GROUP BY TO_CHAR(sp.period_from,'YYYY-MM')
        )
        SELECT m.ym,
               COALESCE(td.trips,   0) AS trips,
               COALESCE(td.km,      0) AS km,
               CASE WHEN ld.locked_revenue > 0
                    THEN ld.locked_revenue
                    ELSE COALESCE(td.revenue, 0)
               END AS revenue
        FROM months m
        LEFT JOIN td ON td.ym = m.ym
        LEFT JOIN ld ON ld.ym = m.ym
        ORDER BY m.ym
    ");

    $overview['top_customers'] = safeQuery($pdo, "
        SELECT c.company_name, COUNT(t.id) AS trips,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t JOIN customers c ON t.customer_id = c.id
        WHERE t.trip_date BETWEEN ? AND ? AND t.status IN ('completed','confirmed')
        GROUP BY c.id, c.company_name
        ORDER BY revenue DESC LIMIT 5
    ", [$dateFrom, $dateTo]);

    $overview['top_drivers'] = safeQuery($pdo, "
        SELECT u.full_name, COUNT(t.id) AS trips,
               COALESCE(SUM(t.total_km),0) AS km,
               COALESCE(SUM(t.total_amount),0) AS revenue
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        JOIN users   u ON d.user_id   = u.id
        WHERE t.trip_date BETWEEN ? AND ? AND t.status IN ('completed','confirmed')
        GROUP BY d.id, u.full_name
        ORDER BY trips DESC LIMIT 5
    ", [$dateFrom, $dateTo]);

    $overview['locked_periods'] = safeQuery($pdo, "
        SELECT sp.id, sp.period_from, sp.period_to, sp.locked_at,
               sp.total_amount, sp.total_trips, sp.total_km,
               u.full_name AS locked_by_name
        FROM statement_periods sp
        LEFT JOIN users u ON sp.locked_by = u.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
        ORDER BY sp.period_from DESC
    ", [$dateFrom, $dateTo]);
}

// ════════════════════════════════════════════════════════════
// 2. DOANH THU
// ════════════════════════════════════════════════════════════
$revenueData    = [];
$revenueTrips   = [];
$revenueTotal   = 0;
$revTotalKm     = 0;
$lockedPeriods  = [];
if ($tab === 'revenue') {

    $lockedPeriods = safeQuery($pdo, "
        SELECT sp.id, sp.period_from, sp.period_to, sp.locked_at,
               sp.total_amount, sp.total_trips, sp.total_km,
               u.full_name AS locked_by_name
        FROM statement_periods sp
        LEFT JOIN users u ON sp.locked_by = u.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
        ORDER BY sp.period_from DESC
    ", [$dateFrom, $dateTo]);

    $sqlItems = "
        SELECT si.*,
               sp.period_from, sp.period_to, sp.locked_at,
               u_lock.full_name AS locked_by_name,
               c.company_name, c.short_name, c.customer_code, c.tax_code
        FROM statement_items si
        JOIN statement_periods sp ON si.period_id = sp.id
        LEFT JOIN customers c ON si.customer_id = c.id
        LEFT JOIN users u_lock ON sp.locked_by = u_lock.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
    ";
    $itemParams = [$dateFrom, $dateTo];
    if ($filterCustomer) {
        $sqlItems   .= " AND si.customer_id = ?";
        $itemParams[] = $filterCustomer;
    }
    $sqlItems .= " ORDER BY sp.period_from DESC, c.company_name";
    $revenueData = safeQuery($pdo, $sqlItems, $itemParams);

    $sqlUT = "
        SELECT t.trip_date, t.trip_code,
               t.total_km, t.total_amount, t.toll_fee, t.status,
               c.company_name AS customer, c.customer_code,
               u.full_name AS driver, v.plate_number
        FROM trips t
        JOIN customers c ON t.customer_id = c.id
        JOIN drivers   d ON t.driver_id   = d.id
        JOIN users     u ON d.user_id     = u.id
        JOIN vehicles  v ON t.vehicle_id  = v.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
          AND NOT EXISTS (
              SELECT 1 FROM statement_periods sp
              WHERE sp.status = 'locked'
                AND t.trip_date BETWEEN sp.period_from AND sp.period_to
          )
    ";
    $utParams = [$dateFrom, $dateTo];
    if ($filterCustomer) { $sqlUT .= " AND t.customer_id = ?"; $utParams[] = $filterCustomer; }
    if ($filterDriver)   { $sqlUT .= " AND t.driver_id   = ?"; $utParams[] = $filterDriver;   }
    if ($filterVehicle)  { $sqlUT .= " AND t.vehicle_id  = ?"; $utParams[] = $filterVehicle;  }
    $sqlUT .= " ORDER BY t.trip_date DESC";
    $revenueTrips = safeQuery($pdo, $sqlUT, $utParams);

    $revenueTotal = array_sum(array_column($revenueData,  'total_amount'))
                  + array_sum(array_column($revenueTrips, 'total_amount'));
    $revTotalKm   = array_sum(array_column($revenueData,  'total_km'))
                  + array_sum(array_column($revenueTrips, 'total_km'));
}

// ════════════════════════════════════════════════════════════
// 3. CHI PHÍ ĐẦU VÀO
// ════════════════════════════════════════════════════════════
$costData = [];
if ($tab === 'cost') {

    $sqlFuel = "
        SELECT fl.log_date, fl.liters_filled, fl.price_per_liter,
               fl.amount, fl.km_before, fl.km_after,
               fl.km_driven, fl.fuel_efficiency,
               fl.station_name, fl.fuel_type, fl.note,
               v.plate_number,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users   u ON d.user_id    = u.id
        WHERE fl.log_date BETWEEN ? AND ?
    ";
    $fuelParams = [$dateFrom, $dateTo];
    if ($filterVehicle) { $sqlFuel .= " AND fl.vehicle_id = ?"; $fuelParams[] = $filterVehicle; }
    if ($filterDriver)  { $sqlFuel .= " AND fl.driver_id  = ?"; $fuelParams[] = $filterDriver;  }
    $sqlFuel .= " ORDER BY fl.log_date DESC";
    $costData['fuel'] = safeQuery($pdo, $sqlFuel, $fuelParams);

    // ✅ SỬA: maintenance_logs thay vì vehicle_maintenance
    $sqlMaint = "
        SELECT ml.log_date AS maintenance_date,
               ml.maintenance_type,
               ml.description,
               ml.total_cost AS cost,
               ml.parts_cost,
               ml.labor_cost,
               ml.odometer_km AS mileage,
               ml.garage_name,
               ml.next_maintenance_date,
               ml.note AS notes,
               ml.invoice_number,
               ml.status,
               v.plate_number
        FROM maintenance_logs ml
        JOIN vehicles v ON ml.vehicle_id = v.id
        WHERE ml.log_date BETWEEN ? AND ?
          AND ml.status = 'completed'
    ";
    $maintParams = [$dateFrom, $dateTo];
    if ($filterVehicle) { $sqlMaint .= " AND ml.vehicle_id = ?"; $maintParams[] = $filterVehicle; }
    $sqlMaint .= " ORDER BY ml.log_date DESC";
    $costData['maintenance'] = safeQuery($pdo, $sqlMaint, $maintParams);

    $costData['_fuel_total']  = (float)array_sum(array_column($costData['fuel'],        'amount'));
    $costData['_maint_total'] = (float)array_sum(array_column($costData['maintenance'], 'cost'));
    $costData['_grand_total'] = $costData['_fuel_total'] + $costData['_maint_total'];
    $costData['_fuel_liters'] = (float)array_sum(array_column($costData['fuel'], 'liters_filled'));
    $costData['_fuel_km']     = (float)array_sum(array_column($costData['fuel'], 'km_driven'));

    $costData['by_vehicle'] = safeQuery($pdo, "
        SELECT v.plate_number, v.brand,
               COALESCE(SUM(fl.liters_filled), 0) AS total_liters,
               COALESCE(SUM(fl.amount),        0) AS fuel_cost,
               COALESCE(SUM(fl.km_driven),     0) AS km_driven,
               COUNT(fl.id)                        AS fill_count
        FROM vehicles v
        LEFT JOIN fuel_logs fl ON fl.vehicle_id = v.id
            AND fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number, v.brand
        HAVING COUNT(fl.id) > 0
        ORDER BY fuel_cost DESC
    ", [$dateFrom, $dateTo]);

    // ✅ SỬA: maintenance_logs, cột total_cost, log_date
    $costData['maint_by_type'] = safeQuery($pdo, "
        SELECT maintenance_type,
               COUNT(*) AS cnt,
               COALESCE(SUM(total_cost), 0) AS total
        FROM maintenance_logs
        WHERE log_date BETWEEN ? AND ?
          AND status = 'completed'
        GROUP BY maintenance_type
        ORDER BY total DESC
    ", [$dateFrom, $dateTo]);
}

// ════════════════════════════════════════════════════════════
// 4. P&L
// ════════════════════════════════════════════════════════════
$plReport = [];
if ($tab === 'pl') {
    $months = safeQuery($pdo, "
        SELECT DISTINCT TO_CHAR(trip_date,'YYYY-MM') AS ym
        FROM trips WHERE trip_date BETWEEN ? AND ? ORDER BY ym
    ", [$dateFrom, $dateTo]);

    $plReport['monthly'] = [];
    foreach ($months as $m) {
        $ym      = $m['ym'];
        $ymStart = $ym . '-01';
        $ymEnd   = date('Y-m-t', strtotime($ymStart));

        $lockedRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(si.total_amount),0)
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND sp.period_from <= ? AND sp.period_to >= ?
        ", [$ymEnd, $ymStart]);

        $tripRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(total_amount),0) FROM trips
            WHERE trip_date BETWEEN ? AND ?
              AND status IN ('completed','confirmed')
        ", [$ymStart, $ymEnd]);

        $revenue = $lockedRev > 0 ? $lockedRev : $tripRev;

        $fuelCost = (float)safeCol($pdo,
            "SELECT COALESCE(SUM(amount),0) FROM fuel_logs WHERE log_date BETWEEN ? AND ?",
            [$ymStart, $ymEnd]);

        // ✅ SỬA: maintenance_logs, total_cost, log_date
        $maintCost = (float)safeCol($pdo,
            "SELECT COALESCE(SUM(total_cost),0) FROM maintenance_logs
             WHERE log_date BETWEEN ? AND ? AND status = 'completed'",
            [$ymStart, $ymEnd]);

        $totalCost = $fuelCost + $maintCost;

        $plReport['monthly'][] = [
            'ym'         => $ym,
            'revenue'    => $revenue,
            'fuel_cost'  => $fuelCost,
            'maint_cost' => $maintCost,
            'total_cost' => $totalCost,
            'profit'     => $revenue - $totalCost,
            'is_locked'  => $lockedRev > 0,
        ];
    }

    $plReport['total_revenue'] = (float)array_sum(array_column($plReport['monthly'], 'revenue'));
    $plReport['total_fuel']    = (float)array_sum(array_column($plReport['monthly'], 'fuel_cost'));
    $plReport['total_maint']   = (float)array_sum(array_column($plReport['monthly'], 'maint_cost'));
    $plReport['total_cost']    = $plReport['total_fuel'] + $plReport['total_maint'];
    $plReport['total_profit']  = $plReport['total_revenue'] - $plReport['total_cost'];
    $plReport['margin']        = $plReport['total_revenue'] > 0
        ? round($plReport['total_profit'] / $plReport['total_revenue'] * 100, 1) : 0;
}

// ════════════════════════════════════════════════════════════
// 5. KHÁCH HÀNG
// ════════════════════════════════════════════════════════════
$customerReport = [];
if ($tab === 'customer') {
    $customerReport = safeQuery($pdo, "
        SELECT c.id, c.company_name, c.short_name, c.customer_code,
               c.primary_contact_name AS contact_name,
               c.primary_contact_phone AS phone,
               COUNT(t.id)                        AS total_trips,
               COALESCE(SUM(t.total_km),    0)    AS total_km,
               COALESCE(SUM(t.total_amount),0)    AS total_revenue,
               COALESCE(AVG(t.total_amount),0)    AS avg_per_trip,
               MIN(t.trip_date)                   AS first_trip,
               MAX(t.trip_date)                   AS last_trip
        FROM customers c
        LEFT JOIN trips t ON t.customer_id = c.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE c.is_active = TRUE
        GROUP BY c.id, c.company_name, c.short_name, c.customer_code,
                 c.primary_contact_name, c.primary_contact_phone
        ORDER BY total_revenue DESC
    ", [$dateFrom, $dateTo]);

    $grandRev = array_sum(array_column($customerReport, 'total_revenue'));
    foreach ($customerReport as &$row) {
        $lockedRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(si.total_amount),0)
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND si.customer_id = ?
              AND sp.period_from >= ? AND sp.period_to <= ?
        ", [$row['id'], $dateFrom, $dateTo]);
        if ($lockedRev > 0) $row['total_revenue'] = $lockedRev;

        $row['revenue_pct'] = $grandRev > 0 ? round($row['total_revenue'] / $grandRev * 100, 1) : 0;
        $row['fuel_cost']   = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(fl.amount),0)
            FROM fuel_logs fl JOIN trips t ON fl.vehicle_id = t.vehicle_id
                AND fl.log_date = t.trip_date
            WHERE t.customer_id = ? AND t.trip_date BETWEEN ? AND ?
        ", [$row['id'], $dateFrom, $dateTo]);
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 6. XE
// ════════════════════════════════════════════════════════════
$vehicleReport = [];
if ($tab === 'vehicle') {
    // ✅ SỬA: maintenance_logs, total_cost, log_date
    $vehicleReport = safeQuery($pdo, "
        SELECT v.id, v.plate_number, v.brand, v.model, v.year,
               v.status AS vehicle_status,
               COUNT(t.id)                        AS total_trips,
               COALESCE(SUM(t.total_km),    0)    AS total_km,
               COALESCE(SUM(t.total_amount),0)    AS total_revenue,
               COALESCE((SELECT SUM(fl.amount) FROM fuel_logs fl
                         WHERE fl.vehicle_id = v.id AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost,
               COALESCE((SELECT SUM(fl.liters_filled) FROM fuel_logs fl
                         WHERE fl.vehicle_id = v.id AND fl.log_date BETWEEN ? AND ?),0) AS fuel_liters,
               COALESCE((SELECT SUM(ml.total_cost) FROM maintenance_logs ml
                         WHERE ml.vehicle_id = v.id AND ml.log_date BETWEEN ? AND ?
                           AND ml.status = 'completed'),0) AS maint_cost,
               COALESCE((SELECT COUNT(*) FROM maintenance_logs ml
                         WHERE ml.vehicle_id = v.id AND ml.log_date BETWEEN ? AND ?
                           AND ml.status = 'completed'),0) AS maint_count
        FROM vehicles v
        LEFT JOIN trips t ON t.vehicle_id = v.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        GROUP BY v.id, v.plate_number, v.brand, v.model, v.year, v.status
        ORDER BY total_km DESC
    ", [$dateFrom,$dateTo, $dateFrom,$dateTo, $dateFrom,$dateTo, $dateFrom,$dateTo, $dateFrom,$dateTo]);

    foreach ($vehicleReport as &$row) {
        $row['total_cost']   = $row['fuel_cost'] + $row['maint_cost'];
        $row['profit']       = $row['total_revenue'] - $row['total_cost'];
        $row['km_per_liter'] = $row['fuel_liters'] > 0
            ? round($row['total_km'] / $row['fuel_liters'], 2) : 0;
        $row['cost_per_km']  = $row['total_km'] > 0
            ? round($row['total_cost'] / $row['total_km'], 2) : 0;
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 7. LÁI XE
// ════════════════════════════════════════════════════════════
$driverReport = [];
if ($tab === 'driver') {
    // ✅ SỬA: maintenance_logs, is_driver_fault → dùng entered_by hoặc bỏ nếu không có
    $driverReport = safeQuery($pdo, "
        SELECT d.id, u.full_name, u.phone, d.license_number,
               COUNT(t.id)                        AS total_trips,
               COALESCE(SUM(t.total_km),    0)    AS total_km,
               COALESCE(SUM(t.total_amount),0)    AS total_revenue,
               COALESCE(AVG(t.total_km),    0)    AS avg_km_per_trip,
               COALESCE((SELECT SUM(fl.amount)
                         FROM fuel_logs fl WHERE fl.driver_id = d.id
                           AND fl.log_date BETWEEN ? AND ?),0) AS fuel_cost,
               0 AS faults
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN trips t ON t.driver_id = d.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE d.is_active = TRUE
        GROUP BY d.id, u.full_name, u.phone, d.license_number
        ORDER BY total_trips DESC
    ", [$dateFrom,$dateTo, $dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 8. NHIÊN LIỆU
// ════════════════════════════════════════════════════════════
$fuelReport = [];
if ($tab === 'fuel') {
    $fuelReport['detail'] = safeQuery($pdo, "
        SELECT fl.log_date, fl.liters_filled, fl.price_per_liter,
               fl.amount, fl.km_before, fl.km_after,
               fl.km_driven, fl.fuel_efficiency,
               fl.station_name, fl.fuel_type,
               v.plate_number, v.brand, v.model,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users   u ON d.user_id    = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ", [$dateFrom, $dateTo]);

    $fuelReport['by_vehicle'] = safeQuery($pdo, "
        SELECT v.plate_number, v.brand, v.model,
               COUNT(*)                                        AS fills,
               ROUND(SUM(fl.liters_filled)::numeric, 2)       AS total_liters,
               ROUND(SUM(fl.amount)::numeric,        0)       AS total_cost,
               ROUND(AVG(fl.price_per_liter)::numeric, 0)     AS avg_price,
               ROUND(AVG(fl.fuel_efficiency)::numeric, 2)     AS avg_l100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number, v.brand, v.model
        ORDER BY total_cost DESC
    ", [$dateFrom, $dateTo]);

    $fuelReport['_total_liters'] = (float)array_sum(array_column($fuelReport['detail'], 'liters_filled'));
    $fuelReport['_total_cost']   = (float)array_sum(array_column($fuelReport['detail'], 'amount'));
    $fuelReport['_total_km']     = (float)array_sum(array_column($fuelReport['detail'], 'km_driven'));
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📊 Báo Cáo Tổng Hợp</h4>
        <small class="text-muted">Doanh thu từ bảng kê đã chốt · Chi phí từ xe + nhiên liệu</small>
    </div>
    <div class="d-flex gap-2">
        <a href="../statements/index.php" class="btn btn-sm btn-outline-success">
            <i class="fas fa-file-invoice-dollar me-1"></i>Bảng kê công nợ
        </a>
        <a href="../statements/history.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-history me-1"></i>Lịch sử chốt
        </a>
    </div>
</div>

<?php if (isset($_GET['from_lock']) && $_GET['from_lock'] === '1'): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle me-2"></i>
    <strong>Đã chốt kỳ thành công!</strong> Dữ liệu doanh thu đã được cập nhật vào báo cáo.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3 flex-wrap" id="reportTabs">
    <?php
    $tabs = [
        'overview' => ['icon'=>'fas fa-tachometer-alt', 'label'=>'Tổng quan'],
        'revenue'  => ['icon'=>'fas fa-chart-line',     'label'=>'Doanh thu'],
        'cost'     => ['icon'=>'fas fa-receipt',        'label'=>'Chi phí'],
        'pl'       => ['icon'=>'fas fa-balance-scale',  'label'=>'Lãi / Lỗ'],
        'customer' => ['icon'=>'fas fa-building',       'label'=>'Khách hàng'],
        'vehicle'  => ['icon'=>'fas fa-truck',          'label'=>'Xe'],
        'driver'   => ['icon'=>'fas fa-id-card',        'label'=>'Lái xe'],
        'fuel'     => ['icon'=>'fas fa-gas-pump',       'label'=>'Nhiên liệu'],
    ];
    foreach ($tabs as $k => $t): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab===$k?'active':'' ?>"
           href="?tab=<?= $k ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>
                       <?= $filterCustomer ? '&customer_id='.$filterCustomer : '' ?>
                       <?= $filterVehicle  ? '&vehicle_id='.$filterVehicle   : '' ?>
                       <?= $filterDriver   ? '&driver_id='.$filterDriver     : '' ?>">
            <i class="<?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Bộ lọc -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Từ ngày</label>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= $dateFrom ?>">
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Đến ngày</label>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= $dateTo ?>">
    </div>
    <?php if (in_array($tab, ['revenue','customer','pl'])): ?>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Khách hàng</label>
        <select name="customer_id" class="form-select form-select-sm">
            <option value="">-- Tất cả --</option>
            <?php foreach ($allCustomers as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterCustomer==$c['id']?'selected':'' ?>>
                <?= htmlspecialchars($c['short_name']?:$c['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (in_array($tab, ['cost','vehicle','fuel'])): ?>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Xe</label>
        <select name="vehicle_id" class="form-select form-select-sm">
            <option value="">-- Tất cả xe --</option>
            <?php foreach ($allVehicles as $v): ?>
            <option value="<?= $v['id'] ?>" <?= $filterVehicle==$v['id']?'selected':'' ?>>
                <?= htmlspecialchars($v['plate_number']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (in_array($tab, ['driver','revenue'])): ?>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Lái xe</label>
        <select name="driver_id" class="form-select form-select-sm">
            <option value="">-- Tất cả --</option>
            <?php foreach ($allDrivers as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $filterDriver==$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="fas fa-search me-1"></i>Lọc
        </button>
        <a href="?tab=<?= $tab ?>" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
    <div class="col-auto ms-auto">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>In
        </button>
    </div>
</form>
</div>
</div>

<!-- ════ TAB: TỔNG QUAN ════ -->
<?php if ($tab === 'overview'): ?>

<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['val'=>formatMoney((float)$overview['revenue']),    'lbl'=>'Doanh thu'.($overview['has_locked']?' 🔒':''), 'icon'=>'fas fa-chart-line',     'color'=>'success'],
        ['val'=>formatMoney((float)$overview['total_cost']), 'lbl'=>'Tổng chi phí',   'icon'=>'fas fa-receipt',        'color'=>'danger'],
        ['val'=>formatMoney((float)$overview['profit']),     'lbl'=>'Lợi nhuận ('.$overview['profit_rate'].'%)', 'icon'=>'fas fa-balance-scale', 'color'=>$overview['profit']>=0?'primary':'danger'],
        ['val'=>number_format((float)$overview['trips']),    'lbl'=>'Số chuyến',       'icon'=>'fas fa-route',          'color'=>'info'],
        ['val'=>number_format((float)$overview['km'],0).' km','lbl'=>'Tổng KM',       'icon'=>'fas fa-tachometer-alt', 'color'=>'secondary'],
        ['val'=>formatMoney((float)$overview['fuel_cost']),  'lbl'=>'Chi phí nhiên liệu','icon'=>'fas fa-gas-pump',    'color'=>'warning'],
        ['val'=>formatMoney((float)$overview['maint_cost']), 'lbl'=>'Chi phí bảo dưỡng','icon'=>'fas fa-tools',       'color'=>'warning'],
        ['val'=>formatMoney((float)$overview['avg_per_trip']),'lbl'=>'Trung bình/chuyến','icon'=>'fas fa-coins',      'color'=>'success'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="<?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
                    <small class="text-muted"><?= $k['lbl'] ?></small>
                </div>
                <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($overview['locked_periods'])): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-success border-4">
    <div class="card-header bg-white py-2">
        <h6 class="fw-bold mb-0 text-success">🔒 Kỳ bảng kê đã chốt trong phạm vi lọc</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
            <thead class="table-light">
                <tr><th class="ps-3">Kỳ</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Doanh thu</th><th>Người chốt</th><th>Ngày chốt</th></tr>
            </thead>
            <tbody>
            <?php foreach ($overview['locked_periods'] as $lp): ?>
            <tr>
                <td class="ps-3 fw-semibold"><?= date('d/m/Y', strtotime($lp['period_from'])) ?> — <?= date('d/m/Y', strtotime($lp['period_to'])) ?></td>
                <td class="text-center"><?= number_format((int)$lp['total_trips']) ?></td>
                <td class="text-end"><?= number_format((float)$lp['total_km'], 0) ?> km</td>
                <td class="text-end fw-bold text-success"><?= formatMoney((float)$lp['total_amount']) ?></td>
                <td class="small"><?= htmlspecialchars($lp['locked_by_name'] ?? '—') ?></td>
                <td class="small text-muted"><?= $lp['locked_at'] ? date('d/m/Y H:i', strtotime($lp['locked_at'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($overview['monthly'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">📈 Xu hướng doanh thu 12 tháng gần nhất</h6></div>
    <div class="card-body"><canvas id="revenueChart" height="80"></canvas></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🏆 Top 5 Khách hàng</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
                    <thead class="table-light"><tr><th class="ps-3">Khách hàng</th><th class="text-center">Chuyến</th><th class="text-end">Doanh thu</th></tr></thead>
                    <tbody>
                    <?php foreach ($overview['top_customers'] as $r): ?>
                    <tr><td class="ps-3"><?= htmlspecialchars($r['company_name']) ?></td><td class="text-center"><?= $r['trips'] ?></td><td class="text-end fw-bold text-success"><?= formatMoney((float)$r['revenue']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($overview['top_customers'])): ?><tr><td colspan="3" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🥇 Top 5 Lái xe</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:.85rem">
                    <thead class="table-light"><tr><th class="ps-3">Lái xe</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Doanh thu</th></tr></thead>
                    <tbody>
                    <?php foreach ($overview['top_drivers'] as $r): ?>
                    <tr><td class="ps-3"><?= htmlspecialchars($r['full_name']) ?></td><td class="text-center"><?= $r['trips'] ?></td><td class="text-end"><?= number_format((float)$r['km'], 0) ?></td><td class="text-end fw-bold text-primary"><?= formatMoney((float)$r['revenue']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($overview['by_status'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">📋 Chuyến theo trạng thái</h6></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:.85rem">
            <thead class="table-light"><tr><th class="ps-3">Trạng thái</th><th class="text-center">Số chuyến</th><th class="text-end">Doanh thu</th></tr></thead>
            <tbody>
            <?php
            $statusMap = ['completed'=>['label'=>'Hoàn thành','class'=>'primary'],'confirmed'=>['label'=>'Đã duyệt','class'=>'success'],'rejected'=>['label'=>'Từ chối','class'=>'danger'],'in_progress'=>['label'=>'Đang chạy','class'=>'warning'],'scheduled'=>['label'=>'Chờ','class'=>'secondary']];
            foreach ($overview['by_status'] as $r):
                $sm = $statusMap[$r['status']] ?? ['label'=>$r['status'],'class'=>'secondary'];
            ?>
            <tr><td class="ps-3"><span class="badge bg-<?= $sm['class'] ?>"><?= $sm['label'] ?></span></td><td class="text-center"><?= number_format((int)$r['cnt']) ?></td><td class="text-end"><?= formatMoney((float)$r['amt']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($overview['monthly'])): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const chartData = <?= json_encode($overview['monthly'], JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: chartData.map(r => r.ym),
        datasets: [{
            label: 'Doanh thu',
            data: chartData.map(r => parseFloat(r.revenue)),
            backgroundColor: 'rgba(25,135,84,.7)',
            borderColor: 'rgb(25,135,84)',
            borderWidth: 1,
            yAxisID: 'y'
        },{
            label: 'Số chuyến',
            data: chartData.map(r => parseInt(r.trips)),
            type: 'line',
            borderColor: '#0d6efd',
            backgroundColor: 'transparent',
            tension: 0.3,
            yAxisID: 'y2'
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { position:'top' }, tooltip: {
            callbacks: { label: ctx => ctx.dataset.label + ': ' +
                (ctx.datasetIndex === 0
                    ? new Intl.NumberFormat('vi-VN').format(ctx.raw) + ' ₫'
                    : ctx.raw + ' chuyến') }
        }},
        scales: {
            y:  { position:'left',  ticks: { callback: v => (v/1e6).toFixed(0)+'M ₫' } },
            y2: { position:'right', grid: { drawOnChartArea: false } }
        }
    }
});
</script>
<?php endif; ?>

<!-- ════ TAB: DOANH THU ════ -->
<?php elseif ($tab === 'revenue'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
            <div class="fs-4 fw-bold text-success"><?= formatMoney($revenueTotal) ?></div>
            <div class="small text-muted">Tổng doanh thu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
            <div class="fs-4 fw-bold text-primary"><?= formatMoney(array_sum(array_column($revenueData, 'total_amount'))) ?></div>
            <div class="small text-muted">🔒 Từ kỳ đã chốt</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-4 fw-bold text-warning"><?= formatMoney(array_sum(array_column($revenueTrips, 'total_amount'))) ?></div>
            <div class="small text-muted">⏳ Chưa chốt kỳ</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-4 fw-bold text-info"><?= number_format($revTotalKm, 0) ?> km</div>
            <div class="small text-muted">Tổng KM</div>
        </div>
    </div>
</div>

<?php if (!empty($revenueData)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0 text-success">🔒 Doanh thu từ kỳ đã chốt</h6>
        <small class="text-muted"><?= count($lockedPeriods) ?> kỳ · <?= formatMoney(array_sum(array_column($revenueData,'total_amount'))) ?></small>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
            <thead class="table-dark">
                <tr><th class="ps-3">Khách hàng</th><th>Kỳ</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Cầu đường</th><th class="text-end fw-bold">Thành tiền</th><th>Bảng giá</th><th>Người chốt</th></tr>
            </thead>
            <tbody>
            <?php foreach ($revenueData as $r): ?>
            <tr>
                <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($r['short_name'] ?: $r['company_name']) ?></div><small class="text-muted"><?= htmlspecialchars($r['customer_code'] ?? '') ?></small></td>
                <td class="small"><?= date('d/m/Y', strtotime($r['period_from'])) ?><br><span class="text-muted">→ <?= date('d/m/Y', strtotime($r['period_to'])) ?></span></td>
                <td class="text-center"><?= number_format((int)$r['trip_count']) ?></td>
                <td class="text-end"><?= number_format((float)$r['total_km'], 0) ?> km</td>
                <td class="text-end"><?= formatMoney((float)$r['total_toll']) ?></td>
                <td class="text-end fw-bold text-success"><?= formatMoney((float)$r['total_amount']) ?></td>
                <td class="small text-muted"><?= htmlspecialchars($r['price_book_name'] ?? '—') ?></td>
                <td class="small"><?= htmlspecialchars($r['locked_by_name'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($revenueTrips)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0 text-warning">⏳ Chuyến chưa thuộc kỳ đã chốt</h6>
        <small class="text-muted"><?= count($revenueTrips) ?> chuyến · <?= formatMoney(array_sum(array_column($revenueTrips,'total_amount'))) ?></small>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr><th class="ps-3">Ngày</th><th>Mã chuyến</th><th>Khách hàng</th><th>Lái xe</th><th>Biển số</th><th class="text-end">KM</th><th class="text-end">Cầu đường</th><th class="text-end">Thành tiền</th><th class="text-center">Trạng thái</th></tr>
        </thead>
        <tbody>
        <?php foreach ($revenueTrips as $r):
            $sc = ['completed'=>'primary','confirmed'=>'success'][$r['status']] ?? 'secondary'; ?>
        <tr>
            <td class="ps-3 small"><?= date('d/m/Y', strtotime($r['trip_date'])) ?></td>
            <td><code style="font-size:.78rem"><?= htmlspecialchars($r['trip_code']) ?></code></td>
            <td><?= htmlspecialchars($r['customer']) ?></td>
            <td class="small"><?= htmlspecialchars($r['driver']) ?></td>
            <td class="fw-bold text-primary small"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total_km'], 0) ?> km</td>
            <td class="text-end"><?= formatMoney((float)$r['toll_fee']) ?></td>
            <td class="text-end fw-bold"><?= formatMoney((float)$r['total_amount']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $sc ?> small"><?= $r['status'] ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light fw-bold">
            <tr>
                <td colspan="5" class="ps-3 text-end">Tổng (<?= count($revenueTrips) ?> chuyến):</td>
                <td class="text-end"><?= number_format(array_sum(array_column($revenueTrips,'total_km')),0) ?> km</td>
                <td class="text-end"><?= formatMoney(array_sum(array_column($revenueTrips,'toll_fee'))) ?></td>
                <td class="text-end text-success"><?= formatMoney(array_sum(array_column($revenueTrips,'total_amount'))) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    </div>
</div>
<?php endif; ?>
<?php if (empty($revenueData) && empty($revenueTrips)): ?>
<div class="alert alert-info">
    <i class="fas fa-info-circle me-2"></i>Không có dữ liệu doanh thu trong kỳ này.
    <a href="../statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>" class="ms-2 btn btn-sm btn-outline-success">
        <i class="fas fa-file-invoice-dollar me-1"></i>Tới Bảng kê công nợ
    </a>
</div>
<?php endif; ?>

<!-- ════ TAB: CHI PHÍ ════ -->
<?php elseif ($tab === 'cost'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
            <div class="fs-4 fw-bold text-danger"><?= formatMoney($costData['_grand_total']) ?></div>
            <div class="small text-muted">Tổng chi phí</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-4 fw-bold text-warning"><?= formatMoney($costData['_fuel_total']) ?></div>
            <div class="small text-muted">Chi phí nhiên liệu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-4 fw-bold text-info"><?= formatMoney($costData['_maint_total']) ?></div>
            <div class="small text-muted">Chi phí bảo dưỡng</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-secondary border-4">
            <div class="fs-4 fw-bold text-secondary"><?= number_format($costData['_fuel_liters'], 1) ?> L</div>
            <div class="small text-muted">Tổng lít nhiên liệu</div>
        </div>
    </div>
</div>

<!-- Nhiên liệu -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">⛽ Chi tiết đổ dầu / nhiên liệu</h6>
        <small class="text-muted"><?= count($costData['fuel']) ?> lần · <?= formatMoney($costData['_fuel_total']) ?></small>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr><th class="ps-3">Ngày</th><th>Biển số</th><th>Lái xe</th><th class="text-end">Lít</th><th class="text-end">Đơn giá</th><th class="text-end">Tiền</th><th class="text-end">KM trước</th><th class="text-end">KM sau</th><th class="text-end">KM chạy</th><th class="text-end">L/100km</th><th>Trạm</th></tr>
        </thead>
        <tbody>
        <?php foreach ($costData['fuel'] as $r): ?>
        <tr>
            <td class="ps-3"><?= date('d/m/Y', strtotime($r['log_date'])) ?></td>
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="small"><?= htmlspecialchars($r['driver'] ?? '—') ?></td>
            <td class="text-end"><?= number_format((float)$r['liters_filled'], 1) ?></td>
            <td class="text-end"><?= number_format((float)$r['price_per_liter'], 0) ?></td>
            <td class="text-end fw-bold text-warning"><?= formatMoney((float)$r['amount']) ?></td>
            <td class="text-end"><?= $r['km_before'] ? number_format((float)$r['km_before'],0) : '—' ?></td>
            <td class="text-end"><?= $r['km_after']  ? number_format((float)$r['km_after'],0)  : '—' ?></td>
            <td class="text-end"><?= $r['km_driven'] ? number_format((float)$r['km_driven'],0) : '—' ?></td>
            <td class="text-end <?= ($r['fuel_efficiency'] ?? 0) > 35 ? 'text-danger' : '' ?>"><?= $r['fuel_efficiency'] ? number_format((float)$r['fuel_efficiency'],1) : '—' ?></td>
            <td class="small text-muted"><?= htmlspecialchars($r['station_name'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($costData['fuel'])): ?><tr><td colspan="11" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
        <?php if (!empty($costData['fuel'])): ?>
        <tfoot class="table-light fw-bold">
            <tr><td colspan="3" class="ps-3 text-end">Tổng:</td><td class="text-end"><?= number_format($costData['_fuel_liters'],1) ?> L</td><td></td><td class="text-end text-warning"><?= formatMoney($costData['_fuel_total']) ?></td><td colspan="3" class="text-end text-muted"><?= number_format($costData['_fuel_km'],0) ?> km</td><td colspan="2"></td></tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
    </div>
</div>

<!-- Bảo dưỡng -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">🔧 Chi tiết bảo dưỡng</h6>
        <small class="text-muted"><?= count($costData['maintenance']) ?> lần · <?= formatMoney($costData['_maint_total']) ?></small>
    </div>
    <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr><th class="ps-3">Ngày</th><th>Biển số</th><th>Loại BT</th><th>Mô tả</th><th class="text-end">Phụ tùng</th><th class="text-end">Nhân công</th><th class="text-end fw-bold">Tổng CP</th><th>Gara</th><th>BT kế tiếp</th></tr>
        </thead>
        <tbody>
        <?php foreach ($costData['maintenance'] as $r): ?>
        <tr>
            <td class="ps-3"><?= date('d/m/Y', strtotime($r['maintenance_date'])) ?></td>
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td><span class="badge bg-info"><?= htmlspecialchars($r['maintenance_type'] ?? '') ?></span></td>
            <td class="small"><?= htmlspecialchars($r['description'] ?? $r['notes'] ?? '') ?></td>
            <td class="text-end small"><?= formatMoney((float)($r['parts_cost'] ?? 0)) ?></td>
            <td class="text-end small"><?= formatMoney((float)($r['labor_cost'] ?? 0)) ?></td>
            <td class="text-end fw-bold text-danger"><?= formatMoney((float)$r['cost']) ?></td>
            <td class="small"><?= htmlspecialchars($r['garage_name'] ?? '—') ?></td>
            <td class="small text-muted"><?= $r['next_maintenance_date'] ? date('d/m/Y', strtotime($r['next_maintenance_date'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($costData['maintenance'])): ?><tr><td colspan="9" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
        <?php if (!empty($costData['maintenance'])): ?>
        <tfoot class="table-light fw-bold">
            <tr><td colspan="6" class="ps-3 text-end">Tổng (<?= count($costData['maintenance']) ?> lần):</td><td class="text-end text-danger"><?= formatMoney($costData['_maint_total']) ?></td><td colspan="2"></td></tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php if (!empty($costData['by_vehicle'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🚛 Chi phí nhiên liệu theo xe</h6></div>
    <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr><th class="ps-3">Biển số</th><th>Hãng</th><th class="text-center">Lần đổ</th><th class="text-end">Lít</th><th class="text-end">Tiền</th><th class="text-end">KM</th><th class="text-end">L/100km</th></tr>
        </thead>
        <tbody>
        <?php foreach ($costData['by_vehicle'] as $r): ?>
        <tr>
            <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="small"><?= htmlspecialchars($r['brand'] ?? '—') ?></td>
            <td class="text-center"><?= $r['fill_count'] ?></td>
            <td class="text-end"><?= number_format((float)$r['total_liters'],1) ?></td>
            <td class="text-end fw-bold text-warning"><?= formatMoney((float)$r['fuel_cost']) ?></td>
            <td class="text-end"><?= number_format((float)$r['km_driven'],0) ?> km</td>
            <td class="text-end"><?= $r['km_driven'] > 0 ? round($r['total_liters']/$r['km_driven']*100,1) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ════ TAB: P&L ════ -->
<?php elseif ($tab === 'pl'): ?>

<div class="row g-3 mb-4">
    <?php
    $plKpis = [
        ['val'=>formatMoney($plReport['total_revenue']), 'lbl'=>'Tổng doanh thu', 'color'=>'success'],
        ['val'=>formatMoney($plReport['total_cost']),    'lbl'=>'Tổng chi phí',   'color'=>'danger'],
        ['val'=>formatMoney($plReport['total_profit']),  'lbl'=>'Lợi nhuận ('.$plReport['margin'].'%)', 'color'=>$plReport['total_profit']>=0?'primary':'danger'],
        ['val'=>formatMoney($plReport['total_fuel']),    'lbl'=>'Chi phí nhiên liệu', 'color'=>'warning'],
        ['val'=>formatMoney($plReport['total_maint']),   'lbl'=>'Chi phí bảo dưỡng', 'color'=>'info'],
    ];
    foreach ($plKpis as $k): ?>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center p-3 border-top border-<?= $k['color'] ?> border-3">
            <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            <div class="small text-muted"><?= $k['lbl'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($plReport['monthly'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">📅 P&L theo tháng</h6></div>
    <div class="card-body p-0">
    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-dark">
            <tr><th class="ps-3">Tháng</th><th class="text-end">Doanh thu</th><th class="text-end">NL</th><th class="text-end">BT</th><th class="text-end">Tổng CP</th><th class="text-end fw-bold">Lợi nhuận</th><th class="text-center">Biên LN%</th><th class="text-center">Nguồn DT</th></tr>
        </thead>
        <tbody>
        <?php foreach ($plReport['monthly'] as $r):
            $margin = $r['revenue'] > 0 ? round($r['profit'] / $r['revenue'] * 100, 1) : 0;
            $profitClass = $r['profit'] >= 0 ? 'text-success' : 'text-danger';
        ?>
        <tr>
            <td class="ps-3 fw-semibold"><?= $r['ym'] ?></td>
            <td class="text-end fw-bold text-success"><?= formatMoney($r['revenue']) ?></td>
            <td class="text-end text-warning"><?= formatMoney($r['fuel_cost']) ?></td>
            <td class="text-end text-info"><?= formatMoney($r['maint_cost']) ?></td>
            <td class="text-end text-danger"><?= formatMoney($r['total_cost']) ?></td>
            <td class="text-end fw-bold <?= $profitClass ?>"><?= formatMoney($r['profit']) ?></td>
            <td class="text-center"><span class="badge bg-<?= $margin >= 0 ? 'success' : 'danger' ?>"><?= $margin ?>%</span></td>
            <td class="text-center"><?= $r['is_locked'] ? '<span class="badge bg-success">🔒 Đã chốt</span>' : '<span class="badge bg-warning text-dark">⏳ Tạm tính</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-dark fw-bold">
            <tr>
                <td class="ps-3">TỔNG CỘNG</td>
                <td class="text-end text-success"><?= formatMoney($plReport['total_revenue']) ?></td>
                <td class="text-end"><?= formatMoney($plReport['total_fuel']) ?></td>
                <td class="text-end"><?= formatMoney($plReport['total_maint']) ?></td>
                <td class="text-end text-danger"><?= formatMoney($plReport['total_cost']) ?></td>
                <td class="text-end <?= $plReport['total_profit']>=0?'text-success':'text-danger' ?>"><?= formatMoney($plReport['total_profit']) ?></td>
                <td class="text-center"><span class="badge bg-<?= $plReport['margin']>=0?'success':'danger' ?>"><?= $plReport['margin'] ?>%</span></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-info">Không có dữ liệu P&L trong kỳ này.</div>
<?php endif; ?>

<!-- ════ TAB: KHÁCH HÀNG ════ -->
<?php elseif ($tab === 'customer'): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🏢 Báo cáo theo khách hàng</h6></div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-dark">
            <tr><th class="ps-3">Khách hàng</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Doanh thu</th><th class="text-end">Avg/chuyến</th><th class="text-center">% DT</th><th>Chuyến đầu</th><th>Chuyến cuối</th></tr>
        </thead>
        <tbody>
        <?php foreach ($customerReport as $r): ?>
        <tr>
            <td class="ps-3"><div class="fw-semibold"><?= htmlspecialchars($r['short_name'] ?: $r['company_name']) ?></div><small class="text-muted"><?= $r['customer_code'] ?> | <?= htmlspecialchars($r['contact_name'] ?? '') ?></small></td>
            <td class="text-center"><?= number_format((int)$r['total_trips']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total_km'], 0) ?> km</td>
            <td class="text-end fw-bold text-success"><?= formatMoney((float)$r['total_revenue']) ?></td>
            <td class="text-end"><?= formatMoney((float)$r['avg_per_trip']) ?></td>
            <td class="text-center">
                <div class="progress" style="height:16px;min-width:60px">
                    <div class="progress-bar bg-success" style="width:<?= min(100,$r['revenue_pct']) ?>%"><?= $r['revenue_pct'] ?>%</div>
                </div>
            </td>
            <td class="small"><?= $r['first_trip'] ? date('d/m/Y',strtotime($r['first_trip'])) : '—' ?></td>
            <td class="small"><?= $r['last_trip']  ? date('d/m/Y',strtotime($r['last_trip']))  : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($customerReport)): ?><tr><td colspan="8" class="text-center text-muted py-4">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<!-- ════ TAB: XE ════ -->
<?php elseif ($tab === 'vehicle'): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🚛 Báo cáo theo xe</h6></div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-dark">
            <tr><th class="ps-3">Biển số</th><th>Hãng / Model</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Doanh thu</th><th class="text-end">NL (₫)</th><th class="text-end">BT (₫)</th><th class="text-end">Tổng CP</th><th class="text-end">Lợi nhuận</th><th class="text-end">₫/km</th><th class="text-end">km/L</th></tr>
        </thead>
        <tbody>
        <?php foreach ($vehicleReport as $r):
            $profitCls = $r['profit'] >= 0 ? 'text-success' : 'text-danger';
        ?>
        <tr>
            <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="small"><?= htmlspecialchars(trim(($r['brand']??'').' '.($r['model']??''))) ?: '—' ?></td>
            <td class="text-center"><?= number_format((int)$r['total_trips']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total_km'],0) ?> km</td>
            <td class="text-end fw-bold text-success"><?= formatMoney((float)$r['total_revenue']) ?></td>
            <td class="text-end text-warning"><?= formatMoney((float)$r['fuel_cost']) ?></td>
            <td class="text-end text-info"><?= formatMoney((float)$r['maint_cost']) ?></td>
            <td class="text-end text-danger"><?= formatMoney((float)$r['total_cost']) ?></td>
            <td class="text-end fw-bold <?= $profitCls ?>"><?= formatMoney((float)$r['profit']) ?></td>
            <td class="text-end small"><?= $r['cost_per_km'] > 0 ? number_format($r['cost_per_km'],0) : '—' ?></td>
            <td class="text-end small"><?= $r['km_per_liter'] > 0 ? $r['km_per_liter'] : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($vehicleReport)): ?><tr><td colspan="11" class="text-center text-muted py-4">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<!-- ════ TAB: LÁI XE ════ -->
<?php elseif ($tab === 'driver'): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">👤 Báo cáo lái xe</h6></div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-dark">
            <tr><th class="ps-3">Lái xe</th><th>SĐT</th><th>GPLX</th><th class="text-center">Chuyến</th><th class="text-end">KM</th><th class="text-end">Doanh thu</th><th class="text-end">Avg KM</th><th class="text-end">NL (₫)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($driverReport as $r): ?>
        <tr>
            <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
            <td class="small text-muted"><?= htmlspecialchars($r['phone'] ?? '') ?></td>
            <td class="small"><?= htmlspecialchars($r['license_number'] ?? '—') ?></td>
            <td class="text-center"><?= number_format((int)$r['total_trips']) ?></td>
            <td class="text-end"><?= number_format((float)$r['total_km'],0) ?> km</td>
            <td class="text-end fw-bold text-success"><?= formatMoney((float)$r['total_revenue']) ?></td>
            <td class="text-end"><?= number_format((float)$r['avg_km_per_trip'],0) ?> km</td>
            <td class="text-end text-warning"><?= formatMoney((float)$r['fuel_cost']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($driverReport)): ?><tr><td colspan="8" class="text-center text-muted py-4">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<!-- ════ TAB: NHIÊN LIỆU ════ -->
<?php elseif ($tab === 'fuel'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-4 fw-bold text-warning"><?= formatMoney($fuelReport['_total_cost']) ?></div>
            <div class="small text-muted">Tổng tiền nhiên liệu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-4 fw-bold text-info"><?= number_format($fuelReport['_total_liters'],1) ?> L</div>
            <div class="small text-muted">Tổng lít</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
            <div class="fs-4 fw-bold text-success"><?= number_format($fuelReport['_total_km'],0) ?> km</div>
            <div class="small text-muted">Tổng KM chạy (đổ dầu)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-secondary border-4">
            <div class="fs-4 fw-bold text-secondary">
                <?= $fuelReport['_total_km'] > 0 ? round($fuelReport['_total_liters']/$fuelReport['_total_km']*100,2) : '—' ?> L/100km
            </div>
            <div class="small text-muted">Mức tiêu thụ TB</div>
        </div>
    </div>
</div>

<?php if (!empty($fuelReport['by_vehicle'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2"><h6 class="fw-bold mb-0">🚛 Tiêu hao nhiên liệu theo xe</h6></div>
    <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr><th class="ps-3">Biển số</th><th>Hãng</th><th class="text-center">Lần đổ</th><th class="text-end">Lít</th><th class="text-end">Tiền</th><th class="text-end">Đơn giá TB</th><th class="text-end">L/100km TB</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fuelReport['by_vehicle'] as $r): ?>
        <tr>
            <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="small"><?= htmlspecialchars($r['brand']??'—') ?></td>
            <td class="text-center"><?= $r['fills'] ?></td>
            <td class="text-end"><?= number_format((float)$r['total_liters'],1) ?></td>
            <td class="text-end fw-bold text-warning"><?= formatMoney((float)$r['total_cost']) ?></td>
            <td class="text-end"><?= number_format((float)$r['avg_price'],0) ?> ₫</td>
            <td class="text-end <?= ($r['avg_l100km']??0) > 35 ? 'text-danger' : '' ?>"><?= $r['avg_l100km'] ? number_format((float)$r['avg_l100km'],1) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">⛽ Chi tiết từng lần đổ</h6>
        <small class="text-muted"><?= count($fuelReport['detail']) ?> lần</small>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
        <thead class="table-light">
            <tr><th class="ps-3">Ngày</th><th>Biển số</th><th>Lái xe</th><th class="text-end">Lít</th><th class="text-end">Đơn giá</th><th class="text-end">Tiền</th><th class="text-end">KM trước</th><th class="text-end">KM sau</th><th class="text-end">L/100km</th><th>Trạm</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fuelReport['detail'] as $r): ?>
        <tr>
            <td class="ps-3"><?= date('d/m/Y',strtotime($r['log_date'])) ?></td>
            <td class="fw-bold text-primary"><?= htmlspecialchars($r['plate_number']) ?></td>
            <td class="small"><?= htmlspecialchars($r['driver']??'—') ?></td>
            <td class="text-end"><?= number_format((float)$r['liters_filled'],1) ?></td>
            <td class="text-end"><?= number_format((float)$r['price_per_liter'],0) ?></td>
            <td class="text-end fw-bold"><?= formatMoney((float)$r['amount']) ?></td>
            <td class="text-end"><?= $r['km_before'] ? number_format((float)$r['km_before'],0) : '—' ?></td>
            <td class="text-end"><?= $r['km_after']  ? number_format((float)$r['km_after'],0)  : '—' ?></td>
            <td class="text-end"><?= $r['fuel_efficiency'] ? number_format((float)$r['fuel_efficiency'],1) : '—' ?></td>
            <td class="small text-muted"><?= htmlspecialchars($r['station_name']??'') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($fuelReport['detail'])): ?><tr><td colspan="10" class="text-center text-muted py-3">Không có dữ liệu</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php endif; ?>

</div>
</div>

<style>
@media print {
    .sidebar,.topbar,nav,.btn,form,.alert,.nav-tabs{display:none!important}
    .main-content{margin:0!important;padding:0!important}
    .container-fluid{padding:5mm!important}
    .card{border:1px solid #ccc!important;box-shadow:none!important;margin-bottom:8mm!important}
    table{font-size:8pt!important}
    .table-dark,.table-success,.table-warning{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>

<?php include '../../includes/footer.php'; ?>