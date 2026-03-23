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

// ── Redirect khi chốt kỳ xong ───────────────────────────────
// Nếu vừa chốt từ statement/index.php → tự động chuyển sang tab revenue
if (isset($_GET['from_lock']) && $_GET['from_lock'] === '1') {
    $tab = 'revenue';
}

// ── Helper ──────────────────────────────────────────────────
function safeQuery(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('safeQuery error: ' . $e->getMessage() . ' | SQL: ' . $sql);
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
        error_log('safeCol error: ' . $e->getMessage());
        return 0;
    }
}

// ═════════════════════════════════════���══════════════════════
// 1. TỔNG QUAN (overview)
// ════════════════════════════════════════════════════════════
$overview = [];
if ($tab === 'overview') {

    // ── Doanh thu từ statement_periods đã chốt (ưu tiên) ────
    // Nếu có kỳ đã chốt trong phạm vi → lấy từ statement_items
    // Ngược lại → tính trực tiếp từ trips
    $lockedRevenue = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(si.total_amount), 0)
        FROM statement_items si
        JOIN statement_periods sp ON si.period_id = sp.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ?
          AND sp.period_to   <= ?
    ", [$dateFrom, $dateTo]);

    $tripsRevenue = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(total_amount), 0) FROM trips
        WHERE trip_date BETWEEN ? AND ?
          AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    // Ưu tiên doanh thu đã chốt nếu có
    $overview['revenue']     = $lockedRevenue > 0 ? $lockedRevenue : $tripsRevenue;
    $overview['has_locked']  = $lockedRevenue > 0;

    // Số chuyến
    $overview['trips'] = (int)safeCol($pdo, "
        SELECT COUNT(*) FROM trips
        WHERE trip_date BETWEEN ? AND ?
          AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    // Tổng km
    $overview['km'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(total_km), 0) FROM trips
        WHERE trip_date BETWEEN ? AND ?
          AND status IN ('completed','confirmed')
    ", [$dateFrom, $dateTo]);

    // Chi phí nhiên liệu — từ fuel_logs (cột amount = tiền đổ xăng)
    $overview['fuel_cost'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(amount), 0) FROM fuel_logs
        WHERE log_date BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]);

    // Chi phí bảo dưỡng
    $overview['maint_cost'] = (float)safeCol($pdo, "
        SELECT COALESCE(SUM(cost), 0) FROM vehicle_maintenance
        WHERE maintenance_date BETWEEN ? AND ?
    ", [$dateFrom, $dateTo]);

    $overview['total_cost']   = $overview['fuel_cost'] + $overview['maint_cost'];
    $overview['profit']       = $overview['revenue'] - $overview['total_cost'];
    $overview['profit_rate']  = $overview['revenue'] > 0
        ? round($overview['profit'] / $overview['revenue'] * 100, 1) : 0;
    $overview['avg_per_trip'] = $overview['trips'] > 0
        ? round($overview['revenue'] / $overview['trips']) : 0;
    $overview['cost_per_km']  = $overview['km'] > 0
        ? round($overview['total_cost'] / $overview['km'], 2) : 0;

    // Chuyến theo trạng thái
    $overview['by_status'] = safeQuery($pdo, "
        SELECT status,
               COUNT(*)                       AS cnt,
               COALESCE(SUM(total_amount), 0) AS amt
        FROM trips
        WHERE trip_date BETWEEN ? AND ?
        GROUP BY status
    ", [$dateFrom, $dateTo]);

    // Doanh thu theo tháng (12 tháng gần nhất) — ghép locked + trips
    $overview['monthly'] = safeQuery($pdo, "
        WITH months AS (
            SELECT DISTINCT TO_CHAR(trip_date,'YYYY-MM') AS ym
            FROM trips
            WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
        ),
        trip_data AS (
            SELECT TO_CHAR(trip_date,'YYYY-MM') AS ym,
                   COUNT(*)                       AS trips,
                   COALESCE(SUM(total_amount), 0) AS revenue,
                   COALESCE(SUM(total_km),     0) AS km
            FROM trips
            WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
              AND status IN ('completed','confirmed')
            GROUP BY TO_CHAR(trip_date,'YYYY-MM')
        ),
        locked_data AS (
            SELECT TO_CHAR(sp.period_from,'YYYY-MM') AS ym,
                   COALESCE(SUM(si.total_amount), 0) AS locked_revenue
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND sp.period_from >= CURRENT_DATE - INTERVAL '12 months'
            GROUP BY TO_CHAR(sp.period_from,'YYYY-MM')
        )
        SELECT m.ym,
               COALESCE(td.trips,   0)  AS trips,
               COALESCE(td.km,      0)  AS km,
               CASE WHEN ld.locked_revenue > 0
                    THEN ld.locked_revenue
                    ELSE COALESCE(td.revenue, 0)
               END AS revenue
        FROM months m
        LEFT JOIN trip_data   td ON td.ym = m.ym
        LEFT JOIN locked_data ld ON ld.ym = m.ym
        ORDER BY m.ym
    ");

    // Top 5 khách hàng
    $overview['top_customers'] = safeQuery($pdo, "
        SELECT c.company_name,
               COUNT(t.id)                     AS trips,
               COALESCE(SUM(t.total_amount), 0) AS revenue
        FROM trips t
        JOIN customers c ON t.customer_id = c.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY c.id, c.company_name
        ORDER BY revenue DESC
        LIMIT 5
    ", [$dateFrom, $dateTo]);

    // Top 5 lái xe
    $overview['top_drivers'] = safeQuery($pdo, "
        SELECT u.full_name,
               COUNT(t.id)                     AS trips,
               COALESCE(SUM(t.total_km),     0) AS km,
               COALESCE(SUM(t.total_amount), 0) AS revenue
        FROM trips t
        JOIN drivers d ON t.driver_id   = d.id
        JOIN users   u ON d.user_id     = u.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY d.id, u.full_name
        ORDER BY trips DESC
        LIMIT 5
    ", [$dateFrom, $dateTo]);

    // Kỳ đã chốt trong phạm vi lọc
    $overview['locked_periods'] = safeQuery($pdo, "
        SELECT sp.*,
               u.full_name AS locked_by_name,
               COUNT(si.id) AS item_count
        FROM statement_periods sp
        LEFT JOIN users u ON sp.locked_by = u.id
        LEFT JOIN statement_items si ON si.period_id = sp.id
        WHERE sp.period_from >= ? AND sp.period_to <= ?
          AND sp.status = 'locked'
        GROUP BY sp.id, u.full_name
        ORDER BY sp.period_from DESC
    ", [$dateFrom, $dateTo]);
}

// ════════════════════════════════════════════════════════════
// 2. DOANH THU — lấy từ statement_items (đã chốt) + trips chưa chốt
// ════════════════════════════════════════════════════════════
$revenueData     = [];
$revenueTrips    = [];
$revenueTotal    = 0;
$revenueTotalKm  = 0;
if ($tab === 'revenue') {

    // Doanh thu từ các kỳ ĐÃ CHỐT trong phạm vi lọc
    $lockedItems = safeQuery($pdo, "
        SELECT
            si.*,
            sp.period_from, sp.period_to,
            sp.locked_at,
            u_lock.full_name AS locked_by_name,
            c.company_name,
            c.short_name,
            c.customer_code,
            c.tax_code
        FROM statement_items si
        JOIN statement_periods sp ON si.period_id = sp.id
        LEFT JOIN customers c ON si.customer_id = c.id
        LEFT JOIN users u_lock ON sp.locked_by = u_lock.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ?
          AND sp.period_to   <= ?
        ORDER BY sp.period_from DESC, c.company_name
    ", [$dateFrom, $dateTo]);

    // Trips chưa thuộc kỳ nào đã chốt
    $unlockedTrips = safeQuery($pdo, "
        SELECT t.trip_date,
               t.trip_code,
               t.total_km,
               t.total_amount,
               t.toll_fee,
               t.status,
               c.company_name AS customer,
               c.customer_code,
               u.full_name    AS driver,
               v.plate_number
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
        ORDER BY t.trip_date DESC
    ", [$dateFrom, $dateTo]);

    if ($filterCustomer) {
        $lockedItems   = array_filter($lockedItems,   fn($r) => $r['customer_id'] == $filterCustomer);
        $unlockedTrips = array_filter($unlockedTrips, fn($r) => false); // re-query below
        $unlockedTrips = safeQuery($pdo, "
            SELECT t.trip_date, t.trip_code, t.total_km, t.total_amount, t.toll_fee, t.status,
                   c.company_name AS customer, c.customer_code,
                   u.full_name AS driver, v.plate_number
            FROM trips t
            JOIN customers c ON t.customer_id = c.id
            JOIN drivers   d ON t.driver_id   = d.id
            JOIN users     u ON d.user_id     = u.id
            JOIN vehicles  v ON t.vehicle_id  = v.id
            WHERE t.trip_date BETWEEN ? AND ?
              AND t.status IN ('completed','confirmed')
              AND t.customer_id = ?
              AND NOT EXISTS (
                  SELECT 1 FROM statement_periods sp
                  WHERE sp.status = 'locked'
                    AND t.trip_date BETWEEN sp.period_from AND sp.period_to
              )
            ORDER BY t.trip_date DESC
        ", [$dateFrom, $dateTo, $filterCustomer]);
    }

    $revenueData   = $lockedItems;
    $revenueTrips  = array_values($unlockedTrips);

    $lockedTotal   = array_sum(array_column($revenueData,  'total_amount'));
    $unlockedTotal = array_sum(array_column($revenueTrips, 'total_amount'));
    $revenueTotal  = $lockedTotal + $unlockedTotal;
    $revenueTotalKm = array_sum(array_column($revenueTrips, 'total_km'));
}

// ════════════════════════════════════════════════════════════
// 3. CHI PHÍ ĐẦU VÀO — fuel_logs + vehicle_maintenance
// ════════════════════════════════════════════════════════════
$costData = [];
if ($tab === 'cost') {

    // Nhiên liệu — dùng cột đúng theo schema PostgreSQL:
    // fuel_logs: amount (tiền), liters_filled, km_before, km_after
    // km_driven và price_per_liter là generated columns
    $sqlFuel = "
        SELECT fl.log_date,
               fl.liters_filled,
               fl.price_per_liter,
               fl.amount,
               fl.km_before,
               fl.km_after,
               fl.km_driven,
               fl.fuel_efficiency,
               fl.station_name,
               fl.fuel_type,
               fl.note,
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
    $sqlFuel .= " ORDER BY fl.log_date DESC";
    $costData['fuel'] = safeQuery($pdo, $sqlFuel, $fuelParams);

    // Bảo dưỡng — schema từ data_transport.sql: vehicle_maintenance
    $sqlMaint = "
        SELECT vm.maintenance_date,
               vm.maintenance_type,
               vm.description,
               vm.cost,
               vm.mileage,
               vm.garage_name,
               vm.next_maintenance_date,
               vm.notes,
               v.plate_number
        FROM vehicle_maintenance vm
        JOIN vehicles v ON vm.vehicle_id = v.id
        WHERE vm.maintenance_date BETWEEN ? AND ?
    ";
    $maintParams = [$dateFrom, $dateTo];
    if ($filterVehicle) { $sqlMaint .= " AND vm.vehicle_id = ?"; $maintParams[] = $filterVehicle; }
    $sqlMaint .= " ORDER BY vm.maintenance_date DESC";
    $costData['maintenance'] = safeQuery($pdo, $sqlMaint, $maintParams);

    // Tổng hợp
    $costData['_fuel_total']  = (float)array_sum(array_column($costData['fuel'],        'amount'));
    $costData['_maint_total'] = (float)array_sum(array_column($costData['maintenance'], 'cost'));
    $costData['_grand_total'] = $costData['_fuel_total'] + $costData['_maint_total'];

    $costData['_fuel_liters'] = (float)array_sum(array_column($costData['fuel'], 'liters_filled'));
    $costData['_fuel_km']     = (float)array_sum(array_column($costData['fuel'], 'km_driven'));

    // Phân tích theo xe
    $costData['by_vehicle'] = safeQuery($pdo, "
        SELECT v.plate_number,
               v.brand,
               COALESCE(SUM(fl.liters_filled), 0)  AS total_liters,
               COALESCE(SUM(fl.amount),        0)  AS fuel_cost,
               COALESCE(SUM(fl.km_driven),     0)  AS km_driven,
               COUNT(fl.id)                         AS fill_count
        FROM vehicles v
        LEFT JOIN fuel_logs fl ON fl.vehicle_id = v.id
            AND fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number, v.brand
        HAVING COUNT(fl.id) > 0 OR (0 = 0)
        ORDER BY fuel_cost DESC
    ", [$dateFrom, $dateTo]);

    $costData['maint_by_type'] = safeQuery($pdo, "
        SELECT maintenance_type,
               COUNT(*)       AS cnt,
               SUM(cost)      AS total
        FROM vehicle_maintenance
        WHERE maintenance_date BETWEEN ? AND ?
        GROUP BY maintenance_type
        ORDER BY total DESC
    ", [$dateFrom, $dateTo]);
}

// ════════════════════════════════════════════════════════════
// 4. LÃI LỖ TỔNG HỢP (P&L) — Doanh thu từ locked statements
// ════════════════════════════════════════════════════════════
$plReport = [];
if ($tab === 'pl') {
    // Tháng nào có dữ liệu
    $months = safeQuery($pdo, "
        SELECT DISTINCT TO_CHAR(trip_date,'YYYY-MM') AS ym
        FROM trips
        WHERE trip_date BETWEEN ? AND ?
        ORDER BY ym
    ", [$dateFrom, $dateTo]);

    $plReport['monthly'] = [];
    foreach ($months as $m) {
        $ym       = $m['ym'];
        $ymStart  = $ym . '-01';
        $ymEnd    = date('Y-m-t', strtotime($ymStart));

        // Doanh thu: ưu tiên locked
        $lockedRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(si.total_amount), 0)
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND sp.period_from <= ? AND sp.period_to >= ?
        ", [$ymEnd, $ymStart]);

        $tripRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(total_amount), 0) FROM trips
            WHERE trip_date BETWEEN ? AND ?
              AND status IN ('completed','confirmed')
        ", [$ymStart, $ymEnd]);

        $revenue = $lockedRev > 0 ? $lockedRev : $tripRev;

        $fuelCost  = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(amount), 0) FROM fuel_logs
            WHERE log_date BETWEEN ? AND ?
        ", [$ymStart, $ymEnd]);

        $maintCost = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(cost), 0) FROM vehicle_maintenance
            WHERE maintenance_date BETWEEN ? AND ?
        ", [$ymStart, $ymEnd]);

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

    $plReport['total_revenue'] = array_sum(array_column($plReport['monthly'], 'revenue'));
    $plReport['total_fuel']    = array_sum(array_column($plReport['monthly'], 'fuel_cost'));
    $plReport['total_maint']   = array_sum(array_column($plReport['monthly'], 'maint_cost'));
    $plReport['total_cost']    = $plReport['total_fuel'] + $plReport['total_maint'];
    $plReport['total_profit']  = $plReport['total_revenue'] - $plReport['total_cost'];
    $plReport['margin']        = $plReport['total_revenue'] > 0
        ? round($plReport['total_profit'] / $plReport['total_revenue'] * 100, 1) : 0;
}

// ════��═══════════════════════════════════════════════════════
// 5. BÁO CÁO KHÁCH HÀNG
// ════════════════════════════════════════════════════════════
$customerReport = [];
if ($tab === 'customer') {
    $customerReport = safeQuery($pdo, "
        SELECT
            c.id,
            c.company_name,
            c.short_name,
            c.primary_contact_name  AS contact_name,
            c.primary_contact_phone AS phone,
            c.customer_code,
            COUNT(t.id)                              AS total_trips,
            COALESCE(SUM(t.total_km),    0)          AS total_km,
            COALESCE(SUM(t.total_amount),0)          AS total_revenue,
            COALESCE(AVG(t.total_amount),0)          AS avg_per_trip,
            MIN(t.trip_date)                         AS first_trip,
            MAX(t.trip_date)                         AS last_trip
        FROM customers c
        LEFT JOIN trips t ON t.customer_id = c.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE c.is_active = TRUE
        GROUP BY c.id, c.company_name, c.short_name,
                 c.primary_contact_name, c.primary_contact_phone, c.customer_code
        ORDER BY total_revenue DESC
    ", [$dateFrom, $dateTo]);

    // Ghép doanh thu đã chốt
    foreach ($customerReport as &$row) {
        $lockedRev = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(si.total_amount), 0)
            FROM statement_items si
            JOIN statement_periods sp ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND si.customer_id = ?
              AND sp.period_from >= ?
              AND sp.period_to   <= ?
        ", [$row['id'], $dateFrom, $dateTo]);

        if ($lockedRev > 0) {
            $row['total_revenue'] = $lockedRev;
            $row['is_locked']     = true;
        } else {
            $row['is_locked'] = false;
        }
    }
    unset($row);

    $grandRev = array_sum(array_column($customerReport, 'total_revenue'));
    foreach ($customerReport as &$row) {
        $row['revenue_pct'] = $grandRev > 0
            ? round($row['total_revenue'] / $grandRev * 100, 1) : 0;
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 6. BÁO CÁO XE
// ════════════════════════════════════════════════════════════
$vehicleReport = [];
if ($tab === 'vehicle') {
    $vehicleReport = safeQuery($pdo, "
        SELECT
            v.id,
            v.plate_number,
            v.brand,
            v.model,
            v.year_of_manufacture   AS year,
            v.status                AS vehicle_status,
            COUNT(t.id)                              AS total_trips,
            COALESCE(SUM(t.total_km),    0)          AS total_km,
            COALESCE(SUM(t.total_amount),0)          AS total_revenue
        FROM vehicles v
        LEFT JOIN trips t ON t.vehicle_id = v.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        GROUP BY v.id, v.plate_number, v.brand, v.model,
                 v.year_of_manufacture, v.status
        ORDER BY total_km DESC
    ", [$dateFrom, $dateTo]);

    foreach ($vehicleReport as &$row) {
        $vid = $row['id'];

        $row['fuel_cost']   = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(amount), 0) FROM fuel_logs
            WHERE vehicle_id = ? AND log_date BETWEEN ? AND ?
        ", [$vid, $dateFrom, $dateTo]);

        $row['fuel_liters'] = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(liters_filled), 0) FROM fuel_logs
            WHERE vehicle_id = ? AND log_date BETWEEN ? AND ?
        ", [$vid, $dateFrom, $dateTo]);

        $row['maint_cost']  = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(cost), 0) FROM vehicle_maintenance
            WHERE vehicle_id = ? AND maintenance_date BETWEEN ? AND ?
        ", [$vid, $dateFrom, $dateTo]);

        $row['maint_count'] = (int)safeCol($pdo, "
            SELECT COUNT(*) FROM vehicle_maintenance
            WHERE vehicle_id = ? AND maintenance_date BETWEEN ? AND ?
        ", [$vid, $dateFrom, $dateTo]);

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
// 7. BÁO CÁO LÁI XE
// ════════════════════════════════════════════════════════════
$driverReport = [];
if ($tab === 'driver') {
    $driverReport = safeQuery($pdo, "
        SELECT
            d.id,
            u.full_name,
            u.phone,
            d.license_number,
            COUNT(t.id)                     AS total_trips,
            COALESCE(SUM(t.total_km),    0) AS total_km,
            COALESCE(SUM(t.total_amount),0) AS total_revenue,
            COALESCE(AVG(t.total_km),    0) AS avg_km_per_trip
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN trips t ON t.driver_id = d.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE d.is_active = TRUE
        GROUP BY d.id, u.full_name, u.phone, d.license_number
        ORDER BY total_trips DESC
    ", [$dateFrom, $dateTo]);

    foreach ($driverReport as &$row) {
        $did = $row['id'];

        $row['fuel_cost'] = (float)safeCol($pdo, "
            SELECT COALESCE(SUM(amount), 0) FROM fuel_logs
            WHERE driver_id = ? AND log_date BETWEEN ? AND ?
        ", [$did, $dateFrom, $dateTo]);

        $row['avg_kpi'] = (float)safeCol($pdo, "
            SELECT COALESCE(AVG(score_total), 0) FROM kpi_scores
            WHERE driver_id = ? AND period_from >= ? AND period_to <= ?
        ", [$did, $dateFrom, $dateTo]);

        $row['avg_rating'] = (float)safeCol($pdo, "
            SELECT COALESCE(AVG(rating), 0) FROM driver_ratings
            WHERE driver_id = ? AND rated_at::date BETWEEN ? AND ?
        ", [$did, $dateFrom, $dateTo]);
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 8. BÁO CÁO NHIÊN LIỆU
// ════════════════��═══════════════════════════════════════════
$fuelReport = [];
if ($tab === 'fuel') {
    $fuelReport['detail'] = safeQuery($pdo, "
        SELECT fl.log_date,
               fl.liters_filled,
               fl.price_per_liter,
               fl.amount,
               fl.km_before,
               fl.km_after,
               fl.km_driven,
               fl.fuel_efficiency,
               fl.station_name,
               fl.fuel_type,
               fl.note,
               v.plate_number,
               v.brand,
               v.model,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users   u ON d.user_id    = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ", [$dateFrom, $dateTo]);

    $fuelReport['by_vehicle'] = safeQuery($pdo, "
        SELECT v.plate_number,
               v.brand,
               v.model,
               COUNT(fl.id)                  AS fills,
               ROUND(SUM(fl.liters_filled),2) AS total_liters,
               ROUND(SUM(fl.amount),       0) AS total_cost,
               ROUND(AVG(fl.price_per_liter),0) AS avg_price,
               ROUND(AVG(fl.fuel_efficiency),2)  AS avg_efficiency
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number, v.brand, v.model
        ORDER BY total_cost DESC
    ", [$dateFrom, $dateTo]);

    $fuelReport['_total_liters'] = (float)array_sum(array_column($fuelReport['detail'], 'liters_filled'));
    $fuelReport['_total_cost']   = (float)array_sum(array_column($fuelReport['detail'], 'amount'));
}

// ── Danh sách filter ────────────────────────────────────────
$customers = safeQuery($pdo, "SELECT id, company_name, short_name FROM customers WHERE is_active=TRUE ORDER BY company_name");
$vehicles  = safeQuery($pdo, "SELECT id, plate_number FROM vehicles WHERE status = 'active' ORDER BY plate_number");
$drivers   = safeQuery($pdo, "SELECT d.id, u.full_name FROM drivers d JOIN users u ON d.user_id=u.id WHERE d.is_active=TRUE ORDER BY u.full_name");

include '../../includes/header.php';
include '../../includes/sidebar.php';

$tabs = [
    'overview' => ['icon' => 'fa-chart-pie',            'label' => 'Tổng quan'],
    'revenue'  => ['icon' => 'fa-file-invoice-dollar',  'label' => 'Doanh thu'],
    'cost'     => ['icon' => 'fa-money-bill-wave',       'label' => 'Chi phí đầu vào'],
    'pl'       => ['icon' => 'fa-balance-scale',         'label' => 'Lãi / Lỗ'],
    'customer' => ['icon' => 'fa-building',              'label' => 'Theo KH'],
    'vehicle'  => ['icon' => 'fa-truck',                 'label' => 'Theo xe'],
    'driver'   => ['icon' => 'fa-id-card',               'label' => 'Theo lái xe'],
    'fuel'     => ['icon' => 'fa-gas-pump',              'label' => 'Nhiên liệu'],
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
            <?php if (!empty($overview['has_locked'])): ?>
            <span class="badge bg-success ms-2">🔒 Sử dụng dữ liệu đã chốt</span>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="/transport/modules/statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
           class="btn btn-sm btn-outline-success">
            <i class="fas fa-lock me-1"></i>Tới Bảng kê & Chốt kỳ
        </a>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>In trang này
        </button>
    </div>
</div>

<?php showFlash(); ?>

<!-- ── Bộ lọc ── -->
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
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $filterCustomer==$c['id']?'selected':'' ?>>
                        <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
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
                    <?php foreach ($vehicles as $v): ?>
                    <option value="<?= $v['id'] ?>" <?= $filterVehicle==$v['id']?'selected':'' ?>>
                        <?= htmlspecialchars($v['plate_number']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto d-flex gap-2 align-items-end">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-search me-1"></i>Lọc
                </button>
                <a href="?tab=<?= $tab ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- ── Tabs ── -->
<ul class="nav nav-tabs mb-4 flex-nowrap overflow-auto" style="white-space:nowrap">
    <?php foreach ($tabs as $key => $t): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab===$key?'active fw-semibold':'' ?>"
           href="?tab=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
            <i class="fas <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- ════════════ TAB: TỔNG QUAN ════════════ -->
<?php if ($tab === 'overview'): ?>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['val' => number_format($overview['revenue'],    0, '.', ',').' ₫', 'lbl' => 'Doanh thu' . ($overview['has_locked'] ? ' (đã chốt)' : ' (ước tính)'), 'color' => 'success', 'icon' => 'fa-money-bill-wave'],
        ['val' => number_format($overview['total_cost'], 0, '.', ',').' ₫', 'lbl' => 'Tổng chi phí',   'color' => 'danger',  'icon' => 'fa-shopping-cart'],
        ['val' => number_format($overview['profit'],     0, '.', ',').' ₫', 'lbl' => 'Lợi nhuận',      'color' => ($overview['profit']>=0?'primary':'danger'), 'icon' => 'fa-chart-line'],
        ['val' => $overview['profit_rate'].'%',                              'lbl' => 'Tỷ suất lợi nhuận','color' => 'info',   'icon' => 'fa-percent'],
        ['val' => number_format($overview['trips']),                         'lbl' => 'Số chuyến',       'color' => 'primary', 'icon' => 'fa-route'],
        ['val' => number_format($overview['km'],0).' km',                    'lbl' => 'Tổng KM',         'color' => 'secondary','icon' => 'fa-road'],
        ['val' => number_format($overview['fuel_cost'], 0,'.', ',').' ₫',   'lbl' => 'Chi phí nhiên liệu','color' => 'warning','icon' => 'fa-gas-pump'],
        ['val' => number_format($overview['maint_cost'],0,'.', ',').' ₫',   'lbl' => 'Chi phí bảo dưỡng','color' => 'dark',   'icon' => 'fa-tools'],
    ];
    foreach ($cards as $card): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100 border-start border-<?= $card['color'] ?> border-4">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="fas <?= $card['icon'] ?> text-<?= $card['color'] ?>"></i>
                    <small class="text-muted"><?= $card['lbl'] ?></small>
                </div>
                <div class="fw-bold fs-5 text-<?= $card['color'] ?>"><?= $card['val'] ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Kỳ đã chốt -->
<?php if (!empty($overview['locked_periods'])): ?>
<div class="card border-0 shadow-sm mb-4 border-start border-success border-4">
    <div class="card-header bg-white py-2">
        <h6 class="fw-bold mb-0">🔒 Kỳ bảng kê đã chốt trong phạm vi lọc</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:0.85rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Kỳ</th>
                    <th class="text-center">KH</th>
                    <th class="text-center">Chuyến</th>
                    <th class="text-end">Tổng KM</th>
                    <th class="text-end fw-bold">Doanh thu</th>
                    <th>Người chốt</th>
                    <th>Ngày chốt</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($overview['locked_periods'] as $lp): ?>
            <tr>
                <td class="ps-3 fw-semibold">
                    <?= date('d/m/Y', strtotime($lp['period_from'])) ?>
                    — <?= date('d/m/Y', strtotime($lp['period_to'])) ?>
                </td>
                <td class="text-center"><?= (int)($lp['customer_count'] ?? $lp['item_count'] ?? 0) ?></td>
                <td class="text-center"><?= (int)($lp['total_trips'] ?? 0) ?></td>
                <td class="text-end"><?= number_format((float)($lp['total_km'] ?? 0), 0) ?> km</td>
                <td class="text-end fw-bold text-success">
                    <?= number_format((float)($lp['total_amount'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td><?= htmlspecialchars($lp['locked_by_name'] ?? '—') ?></td>
                <td class="small text-muted">
                    <?= $lp['locked_at'] ? date('d/m/Y H:i', strtotime($lp['locked_at'])) : '—' ?>
                </td>
                <td>
                    <a href="?tab=revenue&date_from=<?= $lp['period_from'] ?>&date_to=<?= $lp['period_to'] ?>"
                       class="btn btn-xs btn-outline-primary">
                        <i class="fas fa-eye"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Top khách hàng -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">🏆 Top 5 Khách hàng (doanh thu)</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Khách hàng</th>
                            <th class="text-center">Chuyến</th>
                            <th class="text-end">Doanh thu</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overview['top_customers'] as $i => $tc): ?>
                    <tr>
                        <td class="ps-3">
                            <span class="badge bg-<?= ['warning','secondary','secondary','secondary','secondary'][$i] ?? 'secondary' ?> me-1">
                                #<?= $i+1 ?>
                            </span>
                            <?= htmlspecialchars($tc['company_name']) ?>
                        </td>
                        <td class="text-center"><?= (int)$tc['trips'] ?></td>
                        <td class="text-end fw-semibold text-success">
                            <?= number_format((float)$tc['revenue'], 0, '.', ',') ?> ₫
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($overview['top_customers'])): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Top lái xe -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">🚛 Top 5 Lái xe (số chuyến)</h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Lái xe</th>
                            <th class="text-center">Chuyến</th>
                            <th class="text-end">KM</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($overview['top_drivers'] as $i => $td): ?>
                    <tr>
                        <td class="ps-3">
                            <span class="badge bg-<?= ['warning','secondary','secondary','secondary','secondary'][$i] ?? 'secondary' ?> me-1">#<?= $i+1 ?></span>
                            <?= htmlspecialchars($td['full_name']) ?>
                        </td>
                        <td class="text-center"><?= (int)$td['trips'] ?></td>
                        <td class="text-end"><?= number_format((float)$td['km'], 0) ?> km</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($overview['top_drivers'])): ?>
                    <tr><td colspan="3" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart doanh thu theo tháng -->
<?php if (!empty($overview['monthly'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">📈 Doanh thu — Chi phí 12 tháng gần nhất</h6>
    </div>
    <div class="card-body">
        <canvas id="overviewChart" height="80"></canvas>
    </div>
</div>
<script>
(function(){
    const labels   = <?= json_encode(array_column($overview['monthly'], 'ym')) ?>;
    const revenue  = <?= json_encode(array_map(fn($r) => (float)$r['revenue'], $overview['monthly'])) ?>;
    const trips    = <?= json_encode(array_map(fn($r) => (int)$r['trips'],   $overview['monthly'])) ?>;
    new Chart(document.getElementById('overviewChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Doanh thu (₫)',
                    data: revenue,
                    backgroundColor: 'rgba(13,110,253,0.65)',
                    borderColor:     'rgba(13,110,253,1)',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'y',
                },
                {
                    label: 'Số chuyến',
                    data: trips,
                    type: 'line',
                    borderColor:     'rgba(25,135,84,1)',
                    backgroundColor: 'rgba(25,135,84,0.1)',
                    tension: 0.4,
                    yAxisID: 'y1',
                    fill: true,
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top' } },
            scales: {
                y:  { beginAtZero: true, position: 'left',
                      ticks: { callback: v => (v/1e6).toFixed(1)+'M ₫' } },
                y1: { beginAtZero: true, position: 'right',
                      grid: { drawOnChartArea: false } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<!-- ════════════ TAB: DOANH THU ════════════ -->
<?php elseif ($tab === 'revenue'): ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">💰 Doanh thu theo bảng kê đã chốt</h5>
    <a href="/transport/modules/statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
       class="btn btn-sm btn-success">
        <i class="fas fa-lock me-1"></i>Vào Bảng kê để chốt kỳ mới
    </a>
</div>

<!-- Tổng -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
            <div class="fs-3 fw-bold text-success">
                <?= number_format($revenueTotal, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Tổng doanh thu</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
            <div class="fs-3 fw-bold text-primary">
                <?= number_format(array_sum(array_column($revenueData, 'total_amount')), 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Từ kỳ đã chốt 🔒</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-3 fw-bold text-warning">
                <?= number_format(array_sum(array_column($revenueTrips, 'total_amount')), 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Chưa chốt (ước tính)</div>
        </div>
    </div>
</div>

<!-- Doanh thu từ kỳ đã chốt -->
<?php if (!empty($revenueData)): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">🔒 Chi tiết doanh thu từ kỳ đã chốt</h6>
        <span class="badge bg-success"><?= count($revenueData) ?> khách hàng / kỳ</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">Khách hàng</th>
                    <th>Kỳ</th>
                    <th class="text-center">Chuyến</th>
                    <th class="text-end">KM</th>
                    <th class="text-end">Cầu đường</th>
                    <th class="text-end fw-bold">Thành tiền</th>
                    <th>Người chốt</th>
                    <th>Ngày chốt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($revenueData as $item): ?>
            <tr>
                <td class="ps-3">
                    <div class="fw-semibold">
                        <?= htmlspecialchars($item['short_name'] ?? $item['company_name'] ?? '—') ?>
                    </div>
                    <?php if (!empty($item['customer_code'])): ?>
                    <small class="badge bg-secondary"><?= htmlspecialchars($item['customer_code']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="small text-muted">
                    <?= date('d/m/Y', strtotime($item['period_from'])) ?>
                    — <?= date('d/m/Y', strtotime($item['period_to'])) ?>
                </td>
                <td class="text-center"><?= (int)($item['trip_count'] ?? 0) ?></td>
                <td class="text-end"><?= number_format((float)($item['total_km'] ?? 0), 0) ?> km</td>
                <td class="text-end"><?= number_format((float)($item['total_toll'] ?? 0), 0, '.', ',') ?> ₫</td>
                <td class="text-end fw-bold text-success fs-6">
                    <?= number_format((float)($item['total_amount'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="small"><?= htmlspecialchars($item['locked_by_name'] ?? '—') ?></td>
                <td class="small text-muted">
                    <?= !empty($item['locked_at'])
                        ? date('d/m/Y H:i', strtotime($item['locked_at']))
                        : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <td class="ps-3 fw-bold" colspan="5">TỔNG (đã chốt)</td>
                    <td class="text-end fw-bold text-warning fs-6">
                        <?= number_format(array_sum(array_column($revenueData, 'total_amount')), 0, '.', ',') ?> ₫
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Trips chưa chốt -->
<?php if (!empty($revenueTrips)): ?>
<div class="card border-0 shadow-sm border-warning border-2 mb-4">
    <div class="card-header py-2 d-flex justify-content-between align-items-center"
         style="background:#fff8e1">
        <h6 class="fw-bold mb-0 text-warning">
            ⚠️ Chuyến chưa thuộc kỳ chốt nào
            <span class="badge bg-warning text-dark ms-1"><?= count($revenueTrips) ?></span>
        </h6>
        <a href="/transport/modules/statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
           class="btn btn-sm btn-warning fw-semibold">
            🔒 Chốt kỳ ngay →
        </a>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.83rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ngày</th>
                    <th>Mã chuyến</th>
                    <th>Khách hàng</th>
                    <th>Lái xe</th>
                    <th>Biển số</th>
                    <th class="text-end">KM</th>
                    <th class="text-end">Tiền (ước tính)</th>
                    <th class="text-center">Trạng thái</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($revenueTrips, 0, 50) as $trip): ?>
            <tr>
                <td class="ps-3"><?= date('d/m/Y', strtotime($trip['trip_date'])) ?></td>
                <td><code><?= htmlspecialchars($trip['trip_code'] ?? '—') ?></code></td>
                <td><?= htmlspecialchars($trip['customer'] ?? '—') ?></td>
                <td><?= htmlspecialchars($trip['driver']   ?? '—') ?></td>
                <td><?= htmlspecialchars($trip['plate_number'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)($trip['total_km'] ?? 0), 0) ?> km</td>
                <td class="text-end text-warning fw-semibold">
                    <?= number_format((float)($trip['total_amount'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="text-center">
                    <span class="badge bg-<?= $trip['status']==='confirmed'?'success':'primary' ?>">
                        <?= $trip['status'] === 'confirmed' ? '👍 Đã duyệt' : '✅ Hoàn thành' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="5" class="ps-3 text-end">Tổng (<?= count($revenueTrips) ?> chuyến):</td>
                    <td class="text-end"><?= number_format($revenueTotalKm, 0) ?> km</td>
                    <td class="text-end text-warning">
                        <?= number_format(array_sum(array_column($revenueTrips,'total_amount')), 0,'.', ',') ?> ₫
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (empty($revenueData) && empty($revenueTrips)): ?>
<div class="card border-0 shadow-sm p-5 text-center">
    <i class="fas fa-file-invoice-dollar fa-3x mb-3 text-muted opacity-25"></i>
    <h5 class="text-muted">Không có dữ liệu doanh thu trong kỳ này</h5>
    <a href="/transport/modules/statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
       class="btn btn-success mt-2">
        <i class="fas fa-lock me-1"></i>Vào Bảng kê & Chốt kỳ
    </a>
</div>
<?php endif; ?>

<!-- ════════════ TAB: CHI PHÍ ════════════ -->
<?php elseif ($tab === 'cost'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-3 fw-bold text-warning">
                <?= number_format($costData['_grand_total'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Tổng chi phí</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
            <div class="fs-3 fw-bold text-danger">
                <?= number_format($costData['_fuel_total'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Chi phí nhiên liệu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-dark border-4">
            <div class="fs-3 fw-bold">
                <?= number_format($costData['_maint_total'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Chi phí bảo dưỡng</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-3 fw-bold text-info">
                <?= number_format($costData['_fuel_liters'] ?? 0, 1) ?> L
            </div>
            <div class="small text-muted">Tổng lít xăng dầu</div>
        </div>
    </div>
</div>

<!-- Chi phí nhiên liệu -->
<?php if (!empty($costData['fuel'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">⛽ Chi tiết nhiên liệu</h6>
        <span class="badge bg-warning text-dark"><?= count($costData['fuel']) ?> lần đổ</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.83rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ngày</th>
                    <th>Xe</th>
                    <th>Lái xe</th>
                    <th class="text-end">Số lít</th>
                    <th class="text-end">Đơn giá</th>
                    <th class="text-end">Thành tiền</th>
                    <th class="text-end">KM đồng hồ</th>
                    <th class="text-end">Hao hụt (L/100km)</th>
                    <th>Trạm</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($costData['fuel'] as $f): ?>
            <tr>
                <td class="ps-3"><?= date('d/m/Y', strtotime($f['log_date'])) ?></td>
                <td class="fw-bold text-primary"><?= htmlspecialchars($f['plate_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($f['driver'] ?? '—') ?></td>
                <td class="text-end"><?= number_format((float)($f['liters_filled'] ?? 0), 1) ?> L</td>
                <td class="text-end"><?= number_format((float)($f['price_per_liter'] ?? 0), 0, '.', ',') ?> ₫</td>
                <td class="text-end fw-semibold text-danger">
                    <?= number_format((float)($f['amount'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-muted small">
                    <?= ($f['km_after'] ?? null) ? number_format((float)$f['km_after'], 0) : '—' ?>
                </td>
                <td class="text-end">
                    <?= ($f['fuel_efficiency'] ?? null)
                        ? number_format((float)$f['fuel_efficiency'], 2).' L/100km'
                        : '—' ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($f['station_name'] ?? '') ?></td>
                <td class="small text-muted"><?= htmlspecialchars($f['note'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3" class="ps-3 text-end">TỔNG:</td>
                    <td class="text-end"><?= number_format($costData['_fuel_liters'], 1) ?> L</td>
                    <td></td>
                    <td class="text-end text-danger"><?= number_format($costData['_fuel_total'], 0, '.', ',') ?> ₫</td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chi phí bảo dưỡng -->
<?php if (!empty($costData['maintenance'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between">
        <h6 class="fw-bold mb-0">🔧 Chi tiết bảo dưỡng</h6>
        <span class="badge bg-dark"><?= count($costData['maintenance']) ?> lần</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.83rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ngày</th>
                    <th>Xe</th>
                    <th>Loại bảo dưỡng</th>
                    <th>Mô tả</th>
                    <th class="text-end">Đồng hồ</th>
                    <th class="text-end fw-bold">Chi phí</th>
                    <th>Gara</th>
                    <th>BD tiếp theo</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($costData['maintenance'] as $m): ?>
            <tr>
                <td class="ps-3"><?= date('d/m/Y', strtotime($m['maintenance_date'])) ?></td>
                <td class="fw-bold text-primary"><?= htmlspecialchars($m['plate_number'] ?? '—') ?></td>
                <td>
                    <span class="badge bg-secondary"><?= htmlspecialchars($m['maintenance_type'] ?? '—') ?></span>
                </td>
                <td class="small"><?= htmlspecialchars($m['description'] ?? '') ?></td>
                <td class="text-end text-muted small">
                    <?= ($m['mileage'] ?? null) ? number_format((float)$m['mileage'], 0).' km' : '—' ?>
                </td>
                <td class="text-end fw-bold text-dark">
                    <?= number_format((float)($m['cost'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="small"><?= htmlspecialchars($m['garage_name'] ?? '') ?></td>
                <td class="small text-muted">
                    <?= ($m['next_maintenance_date'] ?? null)
                        ? date('d/m/Y', strtotime($m['next_maintenance_date']))
                        : '—' ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($m['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="5" class="ps-3 text-end">TỔNG:</td>
                    <td class="text-end"><?= number_format($costData['_maint_total'], 0, '.', ',') ?> ₫</td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Chi phí theo xe -->
<?php if (!empty($costData['by_vehicle'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">🚛 Chi phí tổng hợp theo xe</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:0.85rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Biển số</th>
                    <th>Hãng</th>
                    <th class="text-center">Lần đổ xăng</th>
                    <th class="text-end">Tổng lít</th>
                    <th class="text-end">Chi phí xăng</th>
                    <th class="text-end">KM đã chạy</th>
                    <th class="text-end">L/100km</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($costData['by_vehicle'] as $bv): ?>
            <tr>
                <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($bv['plate_number'] ?? '—') ?></td>
                <td class="text-muted small"><?= htmlspecialchars($bv['brand'] ?? '—') ?></td>
                <td class="text-center"><?= (int)($bv['fill_count'] ?? 0) ?></td>
                <td class="text-end"><?= number_format((float)($bv['total_liters'] ?? 0), 1) ?> L</td>
                <td class="text-end text-danger fw-semibold">
                    <?= number_format((float)($bv['fuel_cost'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="text-end"><?= number_format((float)($bv['km_driven'] ?? 0), 0) ?> km</td>
                <td class="text-end">
                    <?php
                    $km = (float)($bv['km_driven'] ?? 0);
                    $lt = (float)($bv['total_liters'] ?? 0);
                    echo ($km > 0 && $lt > 0) ? round($lt/$km*100, 2).' L/100km' : '—';
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ════════════ TAB: LÃI LỖ ════════════ -->
<?php elseif ($tab === 'pl'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
            <div class="fs-3 fw-bold text-success">
                <?= number_format($plReport['total_revenue'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Tổng doanh thu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
            <div class="fs-3 fw-bold text-danger">
                <?= number_format($plReport['total_cost'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Tổng chi phí</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-<?= ($plReport['total_profit'] ?? 0) >= 0 ? 'primary' : 'danger' ?> border-4">
            <div class="fs-3 fw-bold text-<?= ($plReport['total_profit'] ?? 0) >= 0 ? 'primary' : 'danger' ?>">
                <?= number_format($plReport['total_profit'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Lợi nhuận</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-3 fw-bold text-info"><?= $plReport['margin'] ?? 0 ?>%</div>
            <div class="small text-muted">Biên lợi nhuận</div>
        </div>
    </div>
</div>

<?php if (!empty($plReport['monthly'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">📊 Lãi / Lỗ theo tháng</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">Tháng</th>
                    <th class="text-end">Doanh thu</th>
                    <th class="text-end">Nhiên liệu</th>
                    <th class="text-end">Bảo dưỡng</th>
                    <th class="text-end">Tổng chi phí</th>
                    <th class="text-end fw-bold">Lợi nhuận</th>
                    <th class="text-center">Biên LN</th>
                    <th class="text-center">Nguồn DT</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plReport['monthly'] as $row): ?>
            <?php $profit = (float)$row['profit']; ?>
            <tr class="<?= $profit < 0 ? 'table-danger bg-opacity-10' : '' ?>">
                <td class="ps-3 fw-semibold"><?= $row['ym'] ?></td>
                <td class="text-end text-success fw-semibold">
                    <?= number_format((float)$row['revenue'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-warning">
                    <?= number_format((float)$row['fuel_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end">
                    <?= number_format((float)$row['maint_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-danger">
                    <?= number_format((float)$row['total_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end fw-bold <?= $profit >= 0 ? 'text-primary' : 'text-danger' ?> fs-6">
                    <?= number_format($profit, 0, '.', ',') ?> ₫
                </td>
                <td class="text-center">
                    <?php
                    $rev = (float)$row['revenue'];
                    $margin = $rev > 0 ? round($profit / $rev * 100, 1) : 0;
                    $cls = $margin >= 20 ? 'success' : ($margin >= 10 ? 'info' : ($margin >= 0 ? 'warning' : 'danger'));
                    ?>
                    <span class="badge bg-<?= $cls ?>"><?= $margin ?>%</span>
                </td>
                <td class="text-center">
                    <?php if ($row['is_locked']): ?>
                    <span class="badge bg-success" title="Doanh thu từ kỳ đã chốt">🔒 Chốt</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark" title="Ước tính từ trips">📝 Ước tính</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <td class="ps-3 fw-bold">TỔNG CỘNG</td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format($plReport['total_revenue'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format($plReport['total_fuel'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold">
                        <?= number_format($plReport['total_maint'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format($plReport['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-warning fs-6">
                        <?= number_format($plReport['total_profit'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-center fw-bold"><?= $plReport['margin'] ?>%</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">📈 Biểu đồ Lãi/Lỗ theo tháng</h6>
    </div>
    <div class="card-body">
        <canvas id="plChart" height="80"></canvas>
    </div>
</div>
<script>
(function(){
    const labels  = <?= json_encode(array_column($plReport['monthly'], 'ym')) ?>;
    const revenue = <?= json_encode(array_map(fn($r)=>(float)$r['revenue'],   $plReport['monthly'])) ?>;
    const cost    = <?= json_encode(array_map(fn($r)=>(float)$r['total_cost'],$plReport['monthly'])) ?>;
    const profit  = <?= json_encode(array_map(fn($r)=>(float)$r['profit'],    $plReport['monthly'])) ?>;

    new Chart(document.getElementById('plChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label:'Doanh thu', data:revenue, backgroundColor:'rgba(25,135,84,.7)',  borderRadius:4 },
                { label:'Chi phí',   data:cost,    backgroundColor:'rgba(220,53,69,.65)', borderRadius:4 },
                { label:'Lợi nhuận', data:profit,  type:'line',
                  borderColor:'rgba(13,110,253,1)', backgroundColor:'rgba(13,110,253,.1)',
                  tension:0.4, fill:true }
            ]
        },
        options:{
            responsive:true,
            interaction:{mode:'index',intersect:false},
            plugins:{legend:{position:'top'}},
            scales:{
                y:{beginAtZero:true,
                   ticks:{callback:v=>(v/1e6).toFixed(1)+'M ₫'}}
            }
        }
    });
})();
</script>
<?php else: ?>
<div class="alert alert-info">Không có dữ liệu trong kỳ đã chọn.</div>
<?php endif; ?>

<!-- ════════════ TAB: KHÁCH HÀNG ════════════ -->
<?php elseif ($tab === 'customer'): ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">🏢 Doanh thu & hoạt động theo khách hàng</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Khách hàng</th>
                    <th class="text-center">Chuyến</th>
                    <th class="text-end">Tổng KM</th>
                    <th class="text-end">TB / chuyến</th>
                    <th class="text-end fw-bold">Doanh thu</th>
                    <th class="text-center">% DT</th>
                    <th>Chuyến đầu</th>
                    <th>Chuyến cuối</th>
                    <th>Nguồn</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($customerReport as $i => $cr): ?>
            <tr>
                <td class="ps-3 text-muted"><?= $i+1 ?></td>
                <td>
                    <div class="fw-semibold">
                        <?= htmlspecialchars($cr['short_name'] ?: $cr['company_name']) ?>
                    </div>
                    <small class="badge bg-secondary"><?= htmlspecialchars($cr['customer_code'] ?? '') ?></small>
                </td>
                <td class="text-center"><?= (int)$cr['total_trips'] ?></td>
                <td class="text-end"><?= number_format((float)$cr['total_km'], 0) ?> km</td>
                <td class="text-end">
                    <?= (int)$cr['total_trips'] > 0
                        ? number_format((float)$cr['avg_per_trip'], 0, '.', ',').' ₫'
                        : '—' ?>
                </td>
                <td class="text-end fw-bold text-success">
                    <?= number_format((float)$cr['total_revenue'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-center">
                    <div class="progress" style="height:6px;min-width:60px">
                        <div class="progress-bar bg-success"
                             style="width:<?= min(100,(float)$cr['revenue_pct']) ?>%"></div>
                    </div>
                    <small><?= $cr['revenue_pct'] ?>%</small>
                </td>
                <td class="small text-muted">
                    <?= $cr['first_trip'] ? date('d/m/Y', strtotime($cr['first_trip'])) : '—' ?>
                </td>
                <td class="small text-muted">
                    <?= $cr['last_trip']  ? date('d/m/Y', strtotime($cr['last_trip']))  : '—' ?>
                </td>
                <td class="text-center">
                    <?php if ($cr['is_locked']): ?>
                    <span class="badge bg-success">🔒 Chốt</span>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark">📝 Ước tính</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($customerReport)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot class="table-dark">
                <tr>
                    <td colspan="2" class="ps-3 fw-bold">TỔNG (<?= count($customerReport) ?> KH)</td>
                    <td class="text-center fw-bold">
                        <?= array_sum(array_column($customerReport, 'total_trips')) ?>
                    </td>
                    <td class="text-end fw-bold">
                        <?= number_format(array_sum(array_column($customerReport, 'total_km')), 0) ?> km
                    </td>
                    <td></td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format(array_sum(array_column($customerReport, 'total_revenue')), 0, '.', ',') ?> ₫
                    </td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>

<!-- ════════════ TAB: XE ════════════ -->
<?php elseif ($tab === 'vehicle'): ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">🚛 Phân tích hiệu quả theo xe</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.83rem">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">Biển số</th>
                    <th>Hãng / Model</th>
                    <th class="text-center">Chuyến</th>
                    <th class="text-end">KM</th>
                    <th class="text-end">Doanh thu</th>
                    <th class="text-end">Chi phí xăng</th>
                    <th class="text-end">Chi phí BD</th>
                    <th class="text-end">Tổng CP</th>
                    <th class="text-end fw-bold">Lợi nhuận</th>
                    <th class="text-end">₫/km</th>
                    <th class="text-end">km/lít</th>
                    <th class="text-center">BD</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($vehicleReport as $vr): ?>
            <?php $profit = (float)$vr['profit']; ?>
            <tr class="<?= $profit < 0 ? 'table-danger bg-opacity-10' : '' ?>">
                <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($vr['plate_number'] ?? '—') ?></td>
                <td class="small text-muted">
                    <?= htmlspecialchars(($vr['brand'] ?? '') . ' ' . ($vr['model'] ?? '')) ?>
                    <?= $vr['year'] ? '('.$vr['year'].')' : '' ?>
                </td>
                <td class="text-center"><?= (int)$vr['total_trips'] ?></td>
                <td class="text-end"><?= number_format((float)$vr['total_km'], 0) ?> km</td>
                <td class="text-end text-success fw-semibold">
                    <?= number_format((float)$vr['total_revenue'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-warning">
                    <?= number_format((float)$vr['fuel_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end">
                    <?= number_format((float)$vr['maint_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-danger">
                    <?= number_format((float)$vr['total_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end fw-bold <?= $profit >= 0 ? 'text-primary' : 'text-danger' ?>">
                    <?= number_format($profit, 0, '.', ',') ?> ₫
                </td>
                <td class="text-end small">
                    <?= $vr['cost_per_km'] > 0 ? number_format($vr['cost_per_km'], 0).' ₫' : '—' ?>
                </td>
                <td class="text-end small">
                    <?= $vr['km_per_liter'] > 0 ? $vr['km_per_liter'] : '—' ?>
                </td>
                <td class="text-center">
                    <?php if ($vr['maint_count'] > 0): ?>
                    <span class="badge bg-dark"><?= $vr['maint_count'] ?>x</span>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($vehicleReport)): ?>
            <tr><td colspan="12" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($vehicleReport)): ?>
            <tfoot class="table-dark">
                <tr>
                    <td colspan="2" class="ps-3 fw-bold">TỔNG (<?= count($vehicleReport) ?> xe)</td>
                    <td class="text-center fw-bold"><?= array_sum(array_column($vehicleReport,'total_trips')) ?></td>
                    <td class="text-end fw-bold"><?= number_format(array_sum(array_column($vehicleReport,'total_km')),0) ?> km</td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format(array_sum(array_column($vehicleReport,'total_revenue')),0,'.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format(array_sum(array_column($vehicleReport,'fuel_cost')),0,'.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold">
                        <?= number_format(array_sum(array_column($vehicleReport,'maint_cost')),0,'.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format(array_sum(array_column($vehicleReport,'total_cost')),0,'.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format(array_sum(array_column($vehicleReport,'profit')),0,'.', ',') ?> ₫
                    </td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<!-- ════════════ TAB: LÁI XE ════════════ -->
<?php elseif ($tab === 'driver'): ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">🧑‍✈️ Hiệu quả theo lái xe</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Lái xe</th>
                    <th>GPLX</th>
                    <th class="text-center">Chuyến</th>
                    <th class="text-end">KM</th>
                    <th class="text-end">TB km/chuyến</th>
                    <th class="text-end">Doanh thu</th>
                    <th class="text-end">CP xăng</th>
                    <th class="text-center">KPI</th>
                    <th class="text-center">Đánh giá KH</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($driverReport as $i => $dr): ?>
            <tr>
                <td class="ps-3 text-muted"><?= $i+1 ?></td>
                <td class="fw-semibold"><?= htmlspecialchars($dr['full_name'] ?? '—') ?></td>
                <td class="small text-muted"><?= htmlspecialchars($dr['license_number'] ?? '—') ?></td>
                <td class="text-center"><?= (int)$dr['total_trips'] ?></td>
                <td class="text-end"><?= number_format((float)$dr['total_km'], 0) ?> km</td>
                <td class="text-end small text-muted">
                    <?= (int)$dr['total_trips'] > 0
                        ? number_format((float)$dr['avg_km_per_trip'], 0).' km'
                        : '—' ?>
                </td>
                <td class="text-end text-success fw-semibold">
                    <?= number_format((float)$dr['total_revenue'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-warning">
                    <?= number_format((float)$dr['fuel_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-center">
                    <?php if ((float)$dr['avg_kpi'] > 0):
                        $kpiCls = $dr['avg_kpi'] >= 80 ? 'success' : ($dr['avg_kpi'] >= 60 ? 'warning' : 'danger');
                    ?>
                    <span class="badge bg-<?= $kpiCls ?>"><?= round((float)$dr['avg_kpi'], 1) ?></span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ((float)$dr['avg_rating'] > 0): ?>
                    <span class="text-warning">
                        <?= str_repeat('★', min(5, round((float)$dr['avg_rating']))) ?>
                        <small class="text-muted">(<?= round((float)$dr['avg_rating'], 1) ?>)</small>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($driverReport)): ?>
            <tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($driverReport)): ?>
            <tfoot class="table-dark">
                <tr>
                    <td colspan="3" class="ps-3 fw-bold">TỔNG (<?= count($driverReport) ?> lái xe)</td>
                    <td class="text-center fw-bold"><?= array_sum(array_column($driverReport,'total_trips')) ?></td>
                    <td class="text-end fw-bold"><?= number_format(array_sum(array_column($driverReport,'total_km')),0) ?> km</td>
                    <td></td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format(array_sum(array_column($driverReport,'total_revenue')),0,'.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format(array_sum(array_column($driverReport,'fuel_cost')),0,'.', ',') ?> ₫
                    </td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
        </div>
    </div>
</div>

<!-- ════════════ TAB: NHIÊN LIỆU ════════════ -->
<?php elseif ($tab === 'fuel'): ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
            <div class="fs-3 fw-bold text-warning">
                <?= number_format($fuelReport['_total_cost'] ?? 0, 0, '.', ',') ?> ₫
            </div>
            <div class="small text-muted">Tổng tiền nhiên liệu</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
            <div class="fs-3 fw-bold text-info">
                <?= number_format($fuelReport['_total_liters'] ?? 0, 1) ?> L
            </div>
            <div class="small text-muted">Tổng số lít</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-secondary border-4">
            <div class="fs-3 fw-bold">
                <?= count($fuelReport['detail'] ?? []) ?>
            </div>
            <div class="small text-muted">Số lần đổ xăng</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
            <div class="fs-3 fw-bold text-success">
                <?php
                $totalLiters = $fuelReport['_total_liters'] ?? 0;
                $totalCost   = $fuelReport['_total_cost']   ?? 0;
                echo $totalLiters > 0
                    ? number_format($totalCost / $totalLiters, 0, '.', ',').' ₫/L'
                    : '—';
                ?>
            </div>
            <div class="small text-muted">Giá bình quân</div>
        </div>
    </div>
</div>

<!-- Tổng hợp theo xe -->
<?php if (!empty($fuelReport['by_vehicle'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">🚛 Nhiên liệu theo xe</h6>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" style="font-size:0.85rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Biển số</th>
                    <th>Hãng</th>
                    <th class="text-center">Lần đổ</th>
                    <th class="text-end">Tổng lít</th>
                    <th class="text-end">Tổng tiền</th>
                    <th class="text-end">Giá BQ</th>
                    <th class="text-end">Hao hụt (L/100km)</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fuelReport['by_vehicle'] as $fv): ?>
            <tr>
                <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($fv['plate_number'] ?? '—') ?></td>
                <td class="small text-muted">
                    <?= htmlspecialchars(($fv['brand'] ?? '') . ' ' . ($fv['model'] ?? '')) ?>
                </td>
                <td class="text-center"><?= (int)$fv['fills'] ?></td>
                <td class="text-end"><?= number_format((float)$fv['total_liters'], 1) ?> L</td>
                <td class="text-end text-danger fw-semibold">
                    <?= number_format((float)$fv['total_cost'], 0, '.', ',') ?> ₫
                </td>
                <td class="text-end">
                    <?= ($fv['avg_price'] ?? null)
                        ? number_format((float)$fv['avg_price'], 0, '.', ',').' ₫'
                        : '—' ?>
                </td>
                <td class="text-end">
                    <?= ($fv['avg_efficiency'] ?? null)
                        ? number_format((float)$fv['avg_efficiency'], 2).' L/100km'
                        : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Chi tiết -->
<?php if (!empty($fuelReport['detail'])): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom py-2">
        <h6 class="fw-bold mb-0">📋 Chi tiết từng lần đổ xăng</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0" style="font-size:0.82rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Ngày</th>
                    <th>Xe</th>
                    <th>Lái xe</th>
                    <th>Loại NL</th>
                    <th class="text-end">Số lít</th>
                    <th class="text-end">Đơn giá</th>
                    <th class="text-end">Thành tiền</th>
                    <th class="text-end">KM sau đổ</th>
                    <th class="text-end">L/100km</th>
                    <th>Trạm</th>
                    <th>Ghi chú</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($fuelReport['detail'] as $fd): ?>
            <tr>
                <td class="ps-3"><?= date('d/m/Y', strtotime($fd['log_date'])) ?></td>
                <td class="fw-bold text-primary"><?= htmlspecialchars($fd['plate_number'] ?? '—') ?></td>
                <td><?= htmlspecialchars($fd['driver'] ?? '—') ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($fd['fuel_type'] ?? '—') ?></span></td>
                <td class="text-end"><?= number_format((float)($fd['liters_filled'] ?? 0), 1) ?> L</td>
                <td class="text-end">
                    <?= ($fd['price_per_liter'] ?? null)
                        ? number_format((float)$fd['price_per_liter'], 0, '.', ',').' ₫'
                        : '—' ?>
                </td>
                <td class="text-end text-danger fw-semibold">
                    <?= number_format((float)($fd['amount'] ?? 0), 0, '.', ',') ?> ₫
                </td>
                <td class="text-end text-muted small">
                    <?= ($fd['km_after'] ?? null) ? number_format((float)$fd['km_after'], 0) : '—' ?>
                </td>
                <td class="text-end">
                    <?= ($fd['fuel_efficiency'] ?? null)
                        ? number_format((float)$fd['fuel_efficiency'], 2)
                        : '—' ?>
                </td>
                <td class="small text-muted"><?= htmlspecialchars($fd['station_name'] ?? '') ?></td>
                <td class="small text-muted"><?= htmlspecialchars($fd['note'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="4" class="ps-3 text-end">TỔNG:</td>
                    <td class="text-end"><?= number_format($fuelReport['_total_liters'], 1) ?> L</td>
                    <td></td>
                    <td class="text-end text-danger"><?= number_format($fuelReport['_total_cost'], 0, '.', ',') ?> ₫</td>
                    <td colspan="4"></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($fuelReport['detail'])): ?>
<div class="alert alert-info">Không có dữ liệu nhiên liệu trong kỳ đã chọn.</div>
<?php endif; ?>

<?php endif; // end tabs ?>

</div><!-- /container-fluid -->
</div><!-- /main-content -->

<style>
.nav-tabs .nav-link { white-space:nowrap; }
.nav-tabs .nav-link.active { font-weight:600; border-bottom:3px solid #0d6efd; }
.btn-xs { padding:2px 8px; font-size:12px; }
@media print {
    .sidebar, .topbar, nav.navbar, form, .btn, .alert, .screen-only { display:none !important; }
    .main-content { margin:0 !important; padding:0 !important; }
    .container-fluid { padding:5mm !important; }
    .nav-tabs { display:none !important; }
    .card { border:1px solid #ccc !important; box-shadow:none !important; margin-bottom:8mm !important; }
    .table-dark, .table-success, .table-warning {
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>

<?php include '../../includes/footer.php'; ?>