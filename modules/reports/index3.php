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

// ── Helper — safe query không throw lỗi ─────────────────────
function safeQuery(PDO $pdo, string $sql, array $params = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll();
    } catch (PDOException $e) { error_log('safeQuery: '.$e->getMessage()); return []; }
}
function safeCol(PDO $pdo, string $sql, array $params = []): float {
    try {
        $s = $pdo->prepare($sql); $s->execute($params);
        return (float)($s->fetchColumn() ?? 0);
    } catch (PDOException $e) { error_log('safeCol: '.$e->getMessage()); return 0.0; }
}

// ════════════════════════════════════════════════════════════
// HÀM TÍNH DOANH THU — ưu tiên statement_periods (đã chốt)
// fallback tính realtime từ trips × price_rules
// ════════════════════════════════════════════════════════════
function calcRevenue(PDO $pdo, string $dateFrom, string $dateTo,
                     int $filterCust = 0): array
{
    // 1. Lấy tất cả kỳ đã chốt nằm trong khoảng dateFrom–dateTo
    $lockedSql = "
        SELECT sp.id, sp.period_from, sp.period_to,
               si.customer_id,
               si.total_amount,
               si.total_km,
               si.total_toll,
               si.trip_count,
               si.confirmed_count,
               si.vehicle_count,
               si.has_price
        FROM statement_periods sp
        JOIN statement_items si ON si.period_id = sp.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
    ";
    $lockedParams = [$dateFrom, $dateTo];
    if ($filterCust) { $lockedSql .= " AND si.customer_id = ?"; $lockedParams[] = $filterCust; }

    $lockedRows = [];
    try {
        $s = $pdo->prepare($lockedSql);
        $s->execute($lockedParams);
        $lockedRows = $s->fetchAll();
    } catch (PDOException $e) {
        // statement_items chưa tồn tại — fallback realtime
    }

    // Nếu có dữ liệu đã chốt → trả về luôn
    if (!empty($lockedRows)) {
        $total = 0; $km = 0; $toll = 0; $trips = 0;
        foreach ($lockedRows as $r) {
            $total += (float)$r['total_amount'];
            $km    += (float)$r['total_km'];
            $toll  += (float)$r['total_toll'];
            $trips += (int)$r['trip_count'];
        }
        return [
            'source'  => 'locked',
            'revenue' => $total,
            'km'      => $km,
            'toll'    => $toll,
            'trips'   => $trips,
            'rows'    => $lockedRows,
        ];
    }

    // 2. Fallback: tính realtime từ trips × price_rules
    $sql = "
        SELECT
            t.id, t.trip_code, t.trip_date, t.status,
            t.total_km, t.toll_fee,
            t.pickup_location  AS route_from,
            t.dropoff_location AS route_to,
            t.is_sunday,
            c.id   AS customer_id,
            c.company_name AS customer,
            c.short_name,
            u.full_name AS driver,
            v.plate_number,
            pr.pricing_mode,
            COALESCE(pr.combo_monthly_price,   0) AS combo_monthly_price,
            COALESCE(pr.combo_km_limit,        0) AS combo_km_limit,
            COALESCE(pr.over_km_price,         0) AS over_km_price,
            COALESCE(pr.standard_price_per_km, 0) AS standard_price_per_km,
            COALESCE(pr.toll_included,     FALSE) AS toll_included,
            COALESCE(pr.sunday_surcharge,      0) AS sunday_surcharge
        FROM trips t
        JOIN customers c ON t.customer_id = c.id
        JOIN drivers d   ON t.driver_id   = d.id
        JOIN users u     ON d.user_id     = u.id
        JOIN vehicles v  ON t.vehicle_id  = v.id
        LEFT JOIN price_books pb
               ON pb.customer_id = c.id
              AND pb.is_active   = TRUE
              AND pb.valid_from  <= t.trip_date
              AND (pb.valid_to IS NULL OR pb.valid_to >= t.trip_date)
        LEFT JOIN price_rules pr
               ON pr.price_book_id = pb.id
              AND pr.vehicle_id    = t.vehicle_id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
    ";
    $params = [$dateFrom, $dateTo];
    if ($filterCust) { $sql .= " AND c.id = ?"; $params[] = $filterCust; }
    $sql .= " ORDER BY t.trip_date DESC";

    $rawRows = safeQuery($pdo, $sql, $params);

    // Tính tiền per-trip
    $revenueRows = [];
    $total = $km = $toll = $trips = 0;
    foreach ($rawRows as $r) {
        $amt  = 0;
        $mode = $r['pricing_mode'] ?? '';
        if ($mode === 'standard') {
            $amt = (float)$r['total_km'] * (float)$r['standard_price_per_km'];
        } elseif ($mode === 'combo') {
            // Combo: không tính per-trip chính xác được, dùng standard_price_per_km làm ước tính
            $amt = (float)$r['total_km'] * (float)$r['standard_price_per_km'];
        }
        $tollAmt = (!$r['toll_included'] && $r['toll_fee']) ? (float)$r['toll_fee'] : 0;
        $rowTotal = $amt + $tollAmt;

        $revenueRows[] = array_merge($r, [
            'calc_amount' => $rowTotal,
            'route_from'  => $r['route_from'] ?? '—',
            'route_to'    => $r['route_to']   ?? '—',
        ]);
        $total += $rowTotal;
        $km    += (float)$r['total_km'];
        $toll  += (float)($r['toll_fee'] ?? 0);
        $trips++;
    }

    return [
        'source'  => 'realtime',
        'revenue' => $total,
        'km'      => $km,
        'toll'    => $toll,
        'trips'   => $trips,
        'rows'    => $revenueRows,
    ];
}

// ════════════════════════════════════════════════════════════
// 1. TỔNG QUAN
// ════════════════════════════════════════════════════════════
$overview = [];
if ($tab === 'overview') {

    $rev = calcRevenue($pdo, $dateFrom, $dateTo, $filterCustomer);
    $overview['revenue']       = $rev['revenue'];
    $overview['trips']         = $rev['trips'];
    $overview['km']            = $rev['km'];
    $overview['revenue_source']= $rev['source'];

    // Chi phí nhiên liệu — fuel_logs.amount (không có total_cost)
    $overview['fuel_cost'] = safeCol($pdo,"
        SELECT COALESCE(SUM(amount),0) FROM fuel_logs
        WHERE log_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    // Chi phí bảo dưỡng — vehicle_maintenance.cost (kiểm tra tên cột)
    $overview['maint_cost'] = safeCol($pdo,"
        SELECT COALESCE(SUM(
            COALESCE(labor_cost,0) + COALESCE(parts_cost,0) + COALESCE(total_cost,0)
        ),0)
        FROM vehicle_maintenance
        WHERE maintenance_date BETWEEN ? AND ?
    ",[$dateFrom,$dateTo]);

    $overview['total_cost']   = $overview['fuel_cost'] + $overview['maint_cost'];
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
               COALESCE(SUM(total_km),0) AS km
        FROM trips WHERE trip_date BETWEEN ? AND ?
        GROUP BY status ORDER BY cnt DESC
    ",[$dateFrom,$dateTo]);

    // Doanh thu theo tháng (12 tháng gần nhất) — từ statement_periods đã chốt
    $overview['monthly'] = safeQuery($pdo,"
        SELECT TO_CHAR(sp.period_from,'YYYY-MM') AS ym,
               COALESCE(SUM(si.total_amount),0)  AS revenue,
               COALESCE(SUM(si.trip_count),0)    AS trips,
               COALESCE(SUM(si.total_km),0)      AS km
        FROM statement_periods sp
        JOIN statement_items si ON si.period_id = sp.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= CURRENT_DATE - INTERVAL '12 months'
        GROUP BY ym ORDER BY ym
    ");

    // Nếu chưa có statement_items, fallback trips
    if (empty($overview['monthly'])) {
        $overview['monthly'] = safeQuery($pdo,"
            SELECT TO_CHAR(trip_date,'YYYY-MM') AS ym,
                   COUNT(*) AS trips,
                   COALESCE(SUM(total_km),0) AS km,
                   0::numeric AS revenue
            FROM trips
            WHERE trip_date >= CURRENT_DATE - INTERVAL '12 months'
              AND status IN ('completed','confirmed')
            GROUP BY ym ORDER BY ym
        ");
    }

    // Top 5 khách hàng
    $overview['top_customers'] = safeQuery($pdo,"
        SELECT c.company_name, c.short_name,
               COUNT(t.id) AS trips,
               COALESCE(SUM(t.total_km),0) AS km
        FROM trips t JOIN customers c ON t.customer_id = c.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY c.id, c.company_name, c.short_name
        ORDER BY trips DESC LIMIT 5
    ",[$dateFrom,$dateTo]);

    // Top 5 lái xe
    $overview['top_drivers'] = safeQuery($pdo,"
        SELECT u.full_name,
               COUNT(t.id) AS trips,
               COALESCE(SUM(t.total_km),0) AS km
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        JOIN users u   ON d.user_id   = u.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
        GROUP BY d.id, u.full_name
        ORDER BY trips DESC LIMIT 5
    ",[$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 2. DOANH THU — từ statement_periods (đã chốt) hoặc realtime
// ═══════════════════���════════════════════════════════════════
$revenueData = [];
if ($tab === 'revenue') {
    $rev = calcRevenue($pdo, $dateFrom, $dateTo, $filterCustomer);
    $revenueData = $rev;

    // Nếu source = locked, lấy thêm detail từ statement_items
    if ($rev['source'] === 'locked') {
        // Lấy danh sách kỳ đã chốt
        $revenueData['periods'] = safeQuery($pdo,"
            SELECT sp.id, sp.period_from, sp.period_to,
                   sp.total_amount, sp.total_trips, sp.total_km,
                   sp.locked_at,
                   u.full_name AS locked_by_name,
                   COUNT(si.id) AS customer_count
            FROM statement_periods sp
            LEFT JOIN users u ON sp.locked_by = u.id
            LEFT JOIN statement_items si ON si.period_id = sp.id
            WHERE sp.status = 'locked'
              AND sp.period_from >= ? AND sp.period_to <= ?
            GROUP BY sp.id, u.full_name
            ORDER BY sp.period_from DESC
        ",[$dateFrom,$dateTo]);
    }
}

// ════════════════════════════════════════════════════════════
// 3. CHI PHÍ ĐẦU VÀO
// ══════════���═════════════════════════════════════════════════
$costData = [];
if ($tab === 'cost') {
    // Nhiên liệu — dùng đúng cột: amount (không phải total_cost)
    $costData['fuel'] = safeQuery($pdo,"
        SELECT fl.log_date,
               fl.liters_filled,
               fl.price_per_liter,
               fl.amount          AS total_cost,
               fl.fuel_efficiency AS lper100km,
               fl.station_name,
               fl.fuel_type,
               fl.km_before, fl.km_after, fl.km_driven,
               v.plate_number, v.brand, v.model,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v  ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u   ON d.user_id    = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC
    ",[$dateFrom,$dateTo]);

    // Bảo dưỡng
    $costData['maintenance'] = safeQuery($pdo,"
        SELECT vm.maintenance_date,
               vm.maintenance_type,
               vm.description,
               COALESCE(vm.total_cost,
                   COALESCE(vm.labor_cost,0) + COALESCE(vm.parts_cost,0)
               )                 AS total_cost,
               vm.garage_name,
               COALESCE(vm.odometer, vm.current_odometer) AS odometer,
               v.plate_number
        FROM vehicle_maintenance vm
        JOIN vehicles v ON vm.vehicle_id = v.id
        WHERE vm.maintenance_date BETWEEN ? AND ?
        ORDER BY vm.maintenance_date DESC
    ",[$dateFrom,$dateTo]);

    $costData['_fuel_total']  = array_sum(array_column($costData['fuel'],        'total_cost'));
    $costData['_maint_total'] = array_sum(array_column($costData['maintenance'],  'total_cost'));
    $costData['_grand_total'] = $costData['_fuel_total'] + $costData['_maint_total'];

    // Nhiên liệu theo xe
    $costData['fuel_by_vehicle'] = safeQuery($pdo,"
        SELECT v.plate_number,
               COUNT(fl.id)              AS fill_count,
               SUM(fl.liters_filled)     AS total_liters,
               SUM(fl.amount)            AS total_cost,
               SUM(fl.km_driven)         AS total_km_driven,
               AVG(fl.fuel_efficiency)   AS avg_lper100km
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number
        ORDER BY total_cost DESC
    ",[$dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 4. THEO KHÁCH HÀNG
// ════════════════════════════════════════════════════════════
$customerReport = [];
if ($tab === 'customer') {
    // Ưu tiên dữ liệu đã chốt
    $fromLocked = safeQuery($pdo,"
        SELECT
            c.id, c.company_name, c.short_name, c.customer_code,
            c.primary_contact_name AS contact_name,
            c.primary_contact_phone AS phone,
            COALESCE(SUM(si.total_amount),0)  AS total_revenue,
            COALESCE(SUM(si.total_km),0)      AS total_km,
            COALESCE(SUM(si.total_toll),0)    AS total_toll,
            COALESCE(SUM(si.trip_count),0)    AS total_trips,
            MIN(sp.period_from)               AS first_period,
            MAX(sp.period_to)                 AS last_period,
            'locked'                          AS data_source
        FROM statement_items si
        JOIN statement_periods sp ON si.period_id = sp.id
        JOIN customers c          ON si.customer_id = c.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
        GROUP BY c.id
        ORDER BY total_revenue DESC
    ",[$dateFrom,$dateTo]);

    if (!empty($fromLocked)) {
        $grandRev = array_sum(array_column($fromLocked, 'total_revenue'));
        foreach ($fromLocked as &$row) {
            $row['revenue_pct'] = $grandRev > 0
                ? round((float)$row['total_revenue'] / $grandRev * 100, 1) : 0;
        }
        unset($row);
        $customerReport = $fromLocked;
    } else {
        // Fallback: tính từ trips trực tiếp (không có tiền, chỉ có km)
        $customerReport = safeQuery($pdo,"
            SELECT
                c.id, c.company_name, c.short_name, c.customer_code,
                c.primary_contact_name AS contact_name,
                c.primary_contact_phone AS phone,
                COUNT(t.id)                    AS total_trips,
                COALESCE(SUM(t.total_km),0)    AS total_km,
                COALESCE(SUM(t.toll_fee),0)    AS total_toll,
                0::numeric                     AS total_revenue,
                MIN(t.trip_date)               AS first_period,
                MAX(t.trip_date)               AS last_period,
                'realtime'                     AS data_source
            FROM customers c
            LEFT JOIN trips t ON t.customer_id = c.id
                AND t.trip_date BETWEEN ? AND ?
                AND t.status IN ('completed','confirmed')
            WHERE c.is_active = TRUE
            GROUP BY c.id
            ORDER BY total_trips DESC
        ",[$dateFrom,$dateTo]);
    }
}

// ════════════════════════════════════════════════════════════
// 5. THEO XE
// ════════════════════════════════════════════════════════════
$vehicleReport = [];
if ($tab === 'vehicle') {
    $vehicleReport = safeQuery($pdo,"
        SELECT
            v.id, v.plate_number,
            COALESCE(v.brand,'') AS brand,
            COALESCE(v.model,'') AS model,
            v.status AS vehicle_status,
            COUNT(DISTINCT t.id)          AS total_trips,
            COALESCE(SUM(t.total_km),0)   AS total_km,
            COALESCE(SUM(t.toll_fee),0)   AS total_toll,
            COALESCE((
                SELECT SUM(fl.amount) FROM fuel_logs fl
                WHERE fl.vehicle_id = v.id AND fl.log_date BETWEEN ? AND ?
            ),0)                          AS fuel_cost,
            COALESCE((
                SELECT SUM(fl.liters_filled) FROM fuel_logs fl
                WHERE fl.vehicle_id = v.id AND fl.log_date BETWEEN ? AND ?
            ),0)                          AS fuel_liters,
            COALESCE((
                SELECT SUM(COALESCE(vm.total_cost,
                           COALESCE(vm.labor_cost,0)+COALESCE(vm.parts_cost,0)))
                FROM vehicle_maintenance vm
                WHERE vm.vehicle_id = v.id AND vm.maintenance_date BETWEEN ? AND ?
            ),0)                          AS maint_cost,
            COALESCE((
                SELECT COUNT(*) FROM vehicle_maintenance vm
                WHERE vm.vehicle_id = v.id AND vm.maintenance_date BETWEEN ? AND ?
            ),0)                          AS maint_count
        FROM vehicles v
        LEFT JOIN trips t ON t.vehicle_id = v.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        GROUP BY v.id
        ORDER BY total_km DESC
    ",[$dateFrom,$dateTo, $dateFrom,$dateTo,
       $dateFrom,$dateTo, $dateFrom,$dateTo,
       $dateFrom,$dateTo]);

    foreach ($vehicleReport as &$row) {
        $row['total_cost']   = (float)$row['fuel_cost'] + (float)$row['maint_cost'];
        $row['km_per_liter'] = (float)$row['fuel_liters'] > 0
            ? round((float)$row['total_km'] / (float)$row['fuel_liters'], 2) : 0;
        $row['cost_per_km']  = (float)$row['total_km'] > 0
            ? round((float)$row['total_cost'] / (float)$row['total_km'], 2) : 0;
    }
    unset($row);
}

// ════════════════════════════════════════════════════════════
// 6. THEO LÁI XE
// ════════════════════════════════════════════════════════════
$driverReport = [];
if ($tab === 'driver') {
    $driverReport = safeQuery($pdo,"
        SELECT
            d.id,
            u.full_name,
            COALESCE(u.phone,'') AS phone,
            COALESCE(d.license_number,'') AS license_number,
            COUNT(t.id)                    AS total_trips,
            COALESCE(SUM(t.total_km),0)    AS total_km,
            COALESCE(AVG(t.total_km),0)    AS avg_km_per_trip,
            COALESCE((
                SELECT SUM(fl.amount) FROM fuel_logs fl
                WHERE fl.driver_id = d.id AND fl.log_date BETWEEN ? AND ?
            ),0)                           AS fuel_cost,
            COALESCE((
                SELECT COUNT(*) FROM vehicle_maintenance vm
                JOIN trips tt ON tt.vehicle_id = vm.vehicle_id
                WHERE tt.driver_id = d.id
                  AND vm.is_driver_fault = TRUE
                  AND vm.maintenance_date BETWEEN ? AND ?
            ),0)                           AS faults,
            COALESCE((
                SELECT AVG(rating) FROM driver_ratings dr
                WHERE dr.driver_id = d.id
                  AND dr.rated_at::date BETWEEN ? AND ?
            ),0)                           AS avg_rating
        FROM drivers d
        JOIN users u ON d.user_id = u.id
        LEFT JOIN trips t ON t.driver_id = d.id
            AND t.trip_date BETWEEN ? AND ?
            AND t.status IN ('completed','confirmed')
        WHERE d.is_active = TRUE
        GROUP BY d.id, u.full_name, u.phone, d.license_number
        ORDER BY total_trips DESC
    ",[$dateFrom,$dateTo, $dateFrom,$dateTo,
       $dateFrom,$dateTo, $dateFrom,$dateTo]);
}

// ════════════════════════════════════════════════════════════
// 7. LÃI LỖ (P&L) — doanh thu từ statement_periods đã chốt
// ════════════════════════════════════════════════════════════
$plReport = [];
if ($tab === 'pl') {
    // Doanh thu theo tháng từ statement_periods
    $revenueMonthly = safeQuery($pdo,"
        SELECT TO_CHAR(sp.period_from,'YYYY-MM') AS ym,
               COALESCE(SUM(si.total_amount),0)  AS revenue,
               COALESCE(SUM(si.trip_count),0)    AS trips,
               COALESCE(SUM(si.total_km),0)      AS km
        FROM statement_periods sp
        JOIN statement_items si ON si.period_id = sp.id
        WHERE sp.status = 'locked'
          AND sp.period_from >= ? AND sp.period_to <= ?
        GROUP BY ym ORDER BY ym
    ",[$dateFrom,$dateTo]);

    // Chi phí nhiên liệu theo tháng
    $fuelMonthly = safeQuery($pdo,"
        SELECT TO_CHAR(log_date,'YYYY-MM') AS ym,
               SUM(amount) AS fuel
        FROM fuel_logs WHERE log_date BETWEEN ? AND ?
        GROUP BY ym ORDER BY ym
    ",[$dateFrom,$dateTo]);

    // Chi phí bảo dưỡng theo tháng
    $maintMonthly = safeQuery($pdo,"
        SELECT TO_CHAR(maintenance_date,'YYYY-MM') AS ym,
               SUM(COALESCE(total_cost,
                   COALESCE(labor_cost,0)+COALESCE(parts_cost,0))) AS maint
        FROM vehicle_maintenance
        WHERE maintenance_date BETWEEN ? AND ?
        GROUP BY ym ORDER BY ym
    ",[$dateFrom,$dateTo]);

    // Merge theo tháng
    $revMap   = array_column($revenueMonthly, null, 'ym');
    $fuelMap  = array_column($fuelMonthly,    null, 'ym');
    $maintMap = array_column($maintMonthly,   null, 'ym');

    // Tập hợp tất cả tháng có dữ liệu
    $allYm = array_unique(array_merge(
        array_keys($revMap),
        array_keys($fuelMap),
        array_keys($maintMap)
    ));
    sort($allYm);

    $plReport['monthly'] = [];
    foreach ($allYm as $ym) {
        $rev   = (float)($revMap[$ym]['revenue']  ?? 0);
        $fuel  = (float)($fuelMap[$ym]['fuel']    ?? 0);
        $maint = (float)($maintMap[$ym]['maint']  ?? 0);
        $cost  = $fuel + $maint;
        $plReport['monthly'][] = [
            'ym'         => $ym,
            'revenue'    => $rev,
            'fuel_cost'  => $fuel,
            'maint_cost' => $maint,
            'total_cost' => $cost,
            'profit'     => $rev - $cost,
            'trips'      => (int)($revMap[$ym]['trips'] ?? 0),
            'km'         => (float)($revMap[$ym]['km'] ?? 0),
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

// ════════════════════════════════════════════════════════════
// 8. NHIÊN LIỆU CHI TIẾT
// ═════════════════��══════════════════════════════════════════
$fuelReport = [];
if ($tab === 'fuel') {
    $fuelReport['detail'] = safeQuery($pdo,"
        SELECT fl.log_date,
               fl.liters_filled,
               fl.price_per_liter,
               fl.amount            AS total_cost,
               fl.km_before, fl.km_after, fl.km_driven,
               fl.fuel_efficiency   AS lper100km,
               fl.station_name,
               fl.fuel_type,
               fl.note,
               v.plate_number, v.brand, v.model,
               u.full_name AS driver
        FROM fuel_logs fl
        JOIN vehicles v  ON fl.vehicle_id = v.id
        LEFT JOIN drivers d ON fl.driver_id = d.id
        LEFT JOIN users u   ON d.user_id    = u.id
        WHERE fl.log_date BETWEEN ? AND ?
        ORDER BY fl.log_date DESC, v.plate_number
    ",[$dateFrom,$dateTo]);

    $fuelReport['by_vehicle'] = safeQuery($pdo,"
        SELECT v.plate_number,
               COUNT(fl.id)           AS fill_count,
               SUM(fl.liters_filled)  AS total_liters,
               SUM(fl.amount)         AS total_cost,
               SUM(fl.km_driven)      AS km_driven,
               AVG(fl.fuel_efficiency) AS avg_efficiency
        FROM fuel_logs fl
        JOIN vehicles v ON fl.vehicle_id = v.id
        WHERE fl.log_date BETWEEN ? AND ?
        GROUP BY v.id, v.plate_number ORDER BY total_cost DESC
    ",[$dateFrom,$dateTo]);

    $fuelReport['total_cost']   = array_sum(array_column($fuelReport['detail'], 'total_cost'));
    $fuelReport['total_liters'] = array_sum(array_column($fuelReport['detail'], 'liters_filled'));
    $fuelReport['total_km']     = array_sum(array_column($fuelReport['detail'], 'km_driven'));
}

// ── Load filter data ─────────────────────────────────────────
$customers = safeQuery($pdo,"
    SELECT id, company_name, short_name FROM customers
    WHERE is_active = TRUE ORDER BY company_name
");
$vehicles = safeQuery($pdo,"
    SELECT id, plate_number FROM vehicles
    WHERE status = 'active' ORDER BY plate_number
");
$driversList = safeQuery($pdo,"
    SELECT d.id, u.full_name FROM drivers d
    JOIN users u ON d.user_id = u.id
    WHERE d.is_active = TRUE ORDER BY u.full_name
");

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">📊 Báo Cáo Tổng Hợp</h4>
            <small class="text-muted">
                Doanh thu từ
                <?= $tab === 'revenue' && isset($revenueData['source'])
                    ? ($revenueData['source'] === 'locked'
                        ? '<span class="badge bg-success">🔒 Bảng kê đã chốt</span>'
                        : '<span class="badge bg-warning text-dark">⚡ Tính toán realtime</span>')
                    : 'bảng kê công nợ' ?>
                · Chi phí từ nhiên liệu & bảo dưỡng
            </small>
        </div>
        <a href="../statements/index.php" class="btn btn-sm btn-outline-success">
            <i class="fas fa-lock me-1"></i>Bảng kê công nợ
        </a>
    </div>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Từ ngày</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $dateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Đến ngày</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $dateTo ?>">
                </div>
                <?php if (in_array($tab, ['revenue','customer','vehicle'])): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $filterCustomer == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if (in_array($tab, ['vehicle','fuel','driver'])): ?>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Xe</label>
                    <select name="vehicle_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả xe --</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            <?= $filterVehicle == $v['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($v['plate_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-auto d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                    <a href="?tab=<?= $tab ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs mb-4">
        <?php
        $tabs = [
            'overview' => ['icon'=>'fas fa-tachometer-alt', 'label'=>'Tổng quan'],
            'revenue'  => ['icon'=>'fas fa-money-bill-wave','label'=>'Doanh thu'],
            'cost'     => ['icon'=>'fas fa-receipt',        'label'=>'Chi phí'],
            'pl'       => ['icon'=>'fas fa-chart-line',     'label'=>'Lãi/Lỗ P&L'],
            'customer' => ['icon'=>'fas fa-building',       'label'=>'Khách hàng'],
            'vehicle'  => ['icon'=>'fas fa-truck',          'label'=>'Xe'],
            'driver'   => ['icon'=>'fas fa-id-card',        'label'=>'Lái xe'],
            'fuel'     => ['icon'=>'fas fa-gas-pump',       'label'=>'Nhiên liệu'],
        ];
        foreach ($tabs as $key => $info):
        ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab === $key ? 'active fw-semibold' : '' ?>"
               href="?tab=<?= $key ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>">
                <i class="<?= $info['icon'] ?> me-1"></i><?= $info['label'] ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ════ TAB: TỔNG QUAN ════ -->
    <?php if ($tab === 'overview'): ?>
    <?php if ($overview['revenue_source'] === 'realtime'): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        Doanh thu đang tính <strong>realtime</strong> — chưa có kỳ nào được chốt trong khoảng này.
        <a href="../statements/index.php" class="alert-link ms-2">Chốt kỳ ngay →</a>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 h-100 border-start border-primary border-4">
                <div class="fs-2 fw-bold text-primary"><?= $overview['trips'] ?></div>
                <div class="small text-muted">Số chuyến</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 h-100 border-start border-info border-4">
                <div class="fs-3 fw-bold text-info"><?= number_format($overview['km'], 0) ?></div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 h-100 border-start border-success border-4">
                <div class="fs-4 fw-bold text-success"><?= number_format($overview['revenue'], 0, '.', ',') ?></div>
                <div class="small text-muted">Doanh thu (₫)</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 h-100 border-start border-danger border-4">
                <div class="fs-4 fw-bold text-danger"><?= number_format($overview['total_cost'], 0, '.', ',') ?></div>
                <div class="small text-muted">Tổng chi phí (₫)</div>
            </div>
        </div>
        <div class="col-md-2 col-6">
            <div class="card border-0 shadow-sm text-center p-3 h-100 border-start border-warning border-4">
                <div class="fs-4 fw-bold <?= $overview['profit'] >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= number_format($overview['profit'], 0, '.', ',') ?>
                </div>
                <div class="small text-muted">Lợi nhuận (₫) · <?= $overview['profit_rate'] ?>%</div>
            </div>
        </div>
    </div>

    <!-- Chi tiết chi phí -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0">🔥 Phân tích chi phí</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>⛽ Nhiên liệu</td>
                            <td class="text-end fw-semibold text-danger">
                                <?= number_format($overview['fuel_cost'], 0, '.', ',') ?> ₫
                            </td>
                        </tr>
                        <tr>
                            <td>🔧 Bảo dưỡng</td>
                            <td class="text-end fw-semibold text-warning">
                                <?= number_format($overview['maint_cost'], 0, '.', ',') ?> ₫
                            </td>
                        </tr>
                        <tr class="fw-bold table-light">
                            <td>Tổng chi phí</td>
                            <td class="text-end text-danger">
                                <?= number_format($overview['total_cost'], 0, '.', ',') ?> ₫
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted small">Chi phí/km</td>
                            <td class="text-end small">
                                <?= number_format($overview['cost_per_km'], 0) ?> ₫/km
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted small">TB/chuyến</td>
                            <td class="text-end small">
                                <?= number_format($overview['avg_per_trip'], 0, '.', ',') ?> ₫
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0">🏆 Top khách hàng (số chuyến)</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                    <?php foreach ($overview['top_customers'] as $i => $tc): ?>
                    <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <span class="badge bg-secondary me-1"><?= $i+1 ?></span>
                            <?= htmlspecialchars($tc['short_name'] ?: $tc['company_name']) ?>
                        </span>
                        <span class="small fw-semibold">
                            <?= $tc['trips'] ?> ch · <?= number_format($tc['km'],0) ?> km
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($overview['top_customers'])): ?>
                    <div class="list-group-item text-muted text-center py-3 small">Không có dữ liệu</div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0">🚛 Top lái xe</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                    <?php foreach ($overview['top_drivers'] as $i => $td): ?>
                    <div class="list-group-item py-2 d-flex justify-content-between align-items-center">
                        <span class="small">
                            <span class="badge bg-info me-1"><?= $i+1 ?></span>
                            <?= htmlspecialchars($td['full_name']) ?>
                        </span>
                        <span class="small fw-semibold">
                            <?= $td['trips'] ?> ch · <?= number_format($td['km'],0) ?> km
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($overview['top_drivers'])): ?>
                    <div class="list-group-item text-muted text-center py-3 small">Không có dữ liệu</div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trạng thái chuyến -->
    <?php if (!empty($overview['by_status'])): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">📋 Chuyến theo trạng thái</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Trạng thái</th>
                        <th class="text-center">Số chuyến</th>
                        <th class="text-end">Tổng KM</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $statusLabels = [
                    'scheduled'  =>'📅 Chờ xuất phát','in_progress'=>'🚛 Đang chạy',
                    'completed'  =>'✅ Hoàn thành',   'confirmed'  =>'👍 Đã duyệt',
                    'rejected'   =>'❌ Từ chối',       'cancelled'  =>'🚫 Hủy',
                ];
                foreach ($overview['by_status'] as $bs):
                ?>
                <tr>
                    <td class="ps-3"><?= $statusLabels[$bs['status']] ?? $bs['status'] ?></td>
                    <td class="text-center fw-semibold"><?= $bs['cnt'] ?></td>
                    <td class="text-end"><?= number_format($bs['km'], 0) ?> km</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════ TAB: DOANH THU ════ -->
    <?php elseif ($tab === 'revenue'): ?>

    <?php if ($revenueData['source'] === 'locked'): ?>
    <div class="alert alert-success py-2 small mb-3">
        <i class="fas fa-lock me-1"></i>
        Hiển thị dữ liệu từ <strong><?= count($revenueData['periods'] ?? []) ?> kỳ đã chốt</strong>
        trong khoảng <?= date('d/m/Y', strtotime($dateFrom)) ?> — <?= date('d/m/Y', strtotime($dateTo)) ?>
    </div>

    <!-- Bảng các kỳ đã chốt -->
    <?php if (!empty($revenueData['periods'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">🔒 Các kỳ đã chốt</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Kỳ</th>
                        <th>Từ ngày</th>
                        <th>Đến ngày</th>
                        <th class="text-center">KH</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">KM</th>
                        <th class="text-end fw-bold">Doanh thu</th>
                        <th>Người chốt</th>
                        <th>Ngày chốt</th>
                        <th>Xem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($revenueData['periods'] as $p): ?>
                <tr>
                    <td class="ps-3 fw-semibold">#<?= $p['id'] ?></td>
                    <td><?= date('d/m/Y', strtotime($p['period_from'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['period_to'])) ?></td>
                    <td class="text-center"><span class="badge bg-info"><?= $p['customer_count'] ?></span></td>
                    <td class="text-center"><?= number_format($p['total_trips'], 0) ?></td>
                    <td class="text-end"><?= number_format($p['total_km'], 0) ?> km</td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format($p['total_amount'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="small"><?= htmlspecialchars($p['locked_by_name'] ?? '—') ?></td>
                    <td class="small text-muted">
                        <?= $p['locked_at'] ? date('d/m/Y H:i', strtotime($p['locked_at'])) : '—' ?>
                    </td>
                    <td>
                        <a href="../statements/index.php?date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                           class="btn btn-xs btn-outline-primary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="6" class="ps-3 fw-bold">TỔNG CỘNG</td>
                        <td class="text-end fw-bold text-warning">
                            <?= number_format($revenueData['revenue'], 0, '.', ',') ?> ₫
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Chi tiết theo khách hàng trong kỳ đã chốt -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">📋 Chi tiết theo khách hàng</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Khách hàng</th>
                        <th class="text-center">Xe</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">KM</th>
                        <th class="text-end">Cầu đường</th>
                        <th class="text-end fw-bold">Doanh thu</th>
                        <th>Bảng giá</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($revenueData['rows'] as $r):
                    $c = safeQuery($pdo,"SELECT company_name, short_name, customer_code FROM customers WHERE id = ?",[(int)$r['customer_id']]);
                    $cInfo = $c[0] ?? [];
                ?>
                <tr>
                    <td class="ps-3 fw-semibold">
                        <?= htmlspecialchars($cInfo['short_name'] ?? $cInfo['company_name'] ?? 'KH #'.$r['customer_id']) ?>
                        <div class="small text-muted"><?= $cInfo['customer_code'] ?? '' ?></div>
                    </td>
                    <td class="text-center"><?= $r['vehicle_count'] ?? '—' ?></td>
                    <td class="text-center"><?= $r['trip_count'] ?></td>
                    <td class="text-end"><?= number_format($r['total_km'], 0) ?> km</td>
                    <td class="text-end"><?= number_format($r['total_toll'] ?? 0, 0, '.', ',') ?> ₫</td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format($r['total_amount'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="small text-muted"><?= htmlspecialchars($r['price_book_name'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php else: ?>
    <!-- Source = realtime -->
    <div class="alert alert-warning py-2 small mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        Dữ liệu <strong>chưa được chốt</strong> — đang tính realtime.
        Số liệu combo chỉ mang tính ước tính (tính theo km × đơn giá).
        <a href="../statements/index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
           class="alert-link ms-2">→ Vào bảng kê để chốt</a>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Ngày</th>
                        <th>Mã chuyến</th>
                        <th>Khách hàng</th>
                        <th>Xe</th>
                        <th>Lái xe</th>
                        <th>Tuyến đường</th>
                        <th class="text-end">KM</th>
                        <th class="text-end">Doanh thu (ước)</th>
                        <th class="text-center">TT</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($revenueData['rows'] as $r): ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= date('d/m/Y', strtotime($r['trip_date'])) ?></td>
                    <td><code class="small"><?= htmlspecialchars($r['trip_code'] ?? '') ?></code></td>
                    <td class="small"><?= htmlspecialchars($r['short_name'] ?: ($r['customer'] ?? '')) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($r['plate_number'] ?? '') ?></span></td>
                    <td class="small"><?= htmlspecialchars($r['driver'] ?? '') ?></td>
                    <td class="small text-muted">
                        <?= htmlspecialchars(mb_substr($r['route_from'] ?? '—', 0, 15)) ?>
                        → <?= htmlspecialchars(mb_substr($r['route_to'] ?? '—', 0, 15)) ?>
                    </td>
                    <td class="text-end"><?= number_format((float)($r['total_km'] ?? 0), 0) ?></td>
                    <td class="text-end fw-semibold text-success">
                        <?= number_format((float)($r['calc_amount'] ?? 0), 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $r['status']==='confirmed'?'success':'primary' ?>">
                            <?= $r['status'] ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td colspan="6" class="ps-3 fw-bold">
                            TỔNG (<?= $revenueData['trips'] ?> chuyến)
                        </td>
                        <td class="text-end fw-bold"><?= number_format($revenueData['km'], 0) ?> km</td>
                        <td class="text-end fw-bold text-warning">
                            <?= number_format($revenueData['revenue'], 0, '.', ',') ?> ₫
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════ TAB: CHI PHÍ ════ -->
    <?php elseif ($tab === 'cost'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
                <div class="fs-3 fw-bold text-danger">
                    <?= number_format($costData['_fuel_total'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">⛽ Chi phí nhiên liệu</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
                <div class="fs-3 fw-bold text-warning">
                    <?= number_format($costData['_maint_total'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">🔧 Chi phí bảo dưỡng</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-dark border-4">
                <div class="fs-3 fw-bold">
                    <?= number_format($costData['_grand_total'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Tổng chi phí</div>
            </div>
        </div>
    </div>

    <!-- Nhiên liệu theo xe -->
    <?php if (!empty($costData['fuel_by_vehicle'])): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">⛽ Nhiên liệu theo xe</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Biển số</th>
                        <th class="text-center">Số lần đổ</th>
                        <th class="text-end">Tổng lít</th>
                        <th class="text-end">KM đã chạy</th>
                        <th class="text-end">L/100km TB</th>
                        <th class="text-end fw-bold">Chi phí</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($costData['fuel_by_vehicle'] as $fv): ?>
                <tr>
                    <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($fv['plate_number']) ?></td>
                    <td class="text-center"><?= $fv['fill_count'] ?></td>
                    <td class="text-end"><?= number_format($fv['total_liters'], 1) ?> L</td>
                    <td class="text-end"><?= number_format($fv['total_km_driven'] ?? 0, 0) ?> km</td>
                    <td class="text-end">
                        <?= $fv['avg_efficiency'] ? number_format($fv['avg_efficiency'], 2).' L' : '—' ?>
                    </td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format($fv['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Chi tiết nhiên liệu -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between">
            <h6 class="fw-bold mb-0">⛽ Chi tiết đổ nhiên liệu</h6>
            <small class="text-muted"><?= count($costData['fuel']) ?> lần đổ</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.82rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ngày</th>
                        <th>Xe</th>
                        <th>Lái xe</th>
                        <th class="text-end">Số lít</th>
                        <th class="text-end">Đơn giá</th>
                        <th class="text-end">L/100km</th>
                        <th>Trạm xăng</th>
                        <th class="text-end fw-bold">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($costData['fuel'] as $fl): ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= date('d/m/Y', strtotime($fl['log_date'])) ?></td>
                    <td class="fw-bold text-primary"><?= htmlspecialchars($fl['plate_number']) ?></td>
                    <td class="small"><?= htmlspecialchars($fl['driver'] ?? '—') ?></td>
                    <td class="text-end"><?= number_format($fl['liters_filled'], 1) ?> L</td>
                    <td class="text-end small"><?= number_format($fl['price_per_liter'] ?? 0, 0) ?> ₫</td>
                    <td class="text-end small">
                        <?= $fl['lper100km'] ? number_format($fl['lper100km'], 2) : '—' ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($fl['station_name'] ?? '—') ?></td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format($fl['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($costData['fuel'])): ?>
                <tr><td colspan="8" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="7" class="ps-3 text-end">TỔNG:</td>
                        <td class="text-end text-danger">
                            <?= number_format($costData['_fuel_total'], 0, '.', ',') ?> ₫
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </div>

    <!-- Chi tiết bảo dưỡng -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between">
            <h6 class="fw-bold mb-0">🔧 Chi tiết bảo dưỡng</h6>
            <small class="text-muted"><?= count($costData['maintenance']) ?> lần</small>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.82rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ngày</th>
                        <th>Xe</th>
                        <th>Loại bảo dưỡng</th>
                        <th>Mô tả</th>
                        <th>Gara</th>
                        <th class="text-end">Odometer</th>
                        <th class="text-end fw-bold">Chi phí</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($costData['maintenance'] as $vm): ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= date('d/m/Y', strtotime($vm['maintenance_date'])) ?></td>
                    <td class="fw-bold text-primary"><?= htmlspecialchars($vm['plate_number']) ?></td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($vm['maintenance_type'] ?? '—') ?></span></td>
                    <td class="small"><?= htmlspecialchars(mb_substr($vm['description'] ?? '—', 0, 40)) ?></td>
                    <td class="small"><?= htmlspecialchars($vm['garage_name'] ?? '—') ?></td>
                    <td class="text-end small"><?= $vm['odometer'] ? number_format($vm['odometer'],0) : '—' ?></td>
                    <td class="text-end fw-bold text-warning">
                        <?= number_format($vm['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($costData['maintenance'])): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="6" class="ps-3 text-end">TỔNG:</td>
                        <td class="text-end text-warning">
                            <?= number_format($costData['_maint_total'], 0, '.', ',') ?> ₫
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ════ TAB: LÃI LỖ P&L ════ -->
    <?php elseif ($tab === 'pl'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-4 fw-bold text-success">
                    <?= number_format($plReport['total_revenue'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Tổng doanh thu</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
                <div class="fs-4 fw-bold text-danger">
                    <?= number_format($plReport['total_cost'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Tổng chi phí</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-<?= $plReport['total_profit']>=0?'primary':'warning' ?> border-4">
                <div class="fs-4 fw-bold text-<?= $plReport['total_profit']>=0?'primary':'warning' ?>">
                    <?= number_format($plReport['total_profit'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Lợi nhuận</div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
                <div class="fs-2 fw-bold text-info"><?= $plReport['margin'] ?>%</div>
                <div class="small text-muted">Biên lợi nhuận</div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">📈 Lãi/Lỗ theo tháng</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Tháng</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">KM</th>
                        <th class="text-end text-success">Doanh thu</th>
                        <th class="text-end">⛽ Nhiên liệu</th>
                        <th class="text-end">🔧 Bảo dưỡng</th>
                        <th class="text-end">Tổng CP</th>
                        <th class="text-end fw-bold">Lãi/Lỗ</th>
                        <th class="text-center">Biên LN</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($plReport['monthly'] as $pm): ?>
                <?php $margin = $pm['revenue'] > 0 ? round($pm['profit']/$pm['revenue']*100,1) : 0; ?>
                <tr class="<?= $pm['profit'] < 0 ? 'table-danger bg-opacity-25' : '' ?>">
                    <td class="ps-3 fw-semibold"><?= $pm['ym'] ?></td>
                    <td class="text-center"><?= $pm['trips'] ?></td>
                    <td class="text-end"><?= number_format($pm['km'], 0) ?> km</td>
                    <td class="text-end fw-semibold text-success">
                        <?= number_format($pm['revenue'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end text-danger">
                        <?= number_format($pm['fuel_cost'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end text-warning">
                        <?= number_format($pm['maint_cost'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end">
                        <?= number_format($pm['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end fw-bold <?= $pm['profit']>=0?'text-success':'text-danger' ?>">
                        <?= ($pm['profit'] >= 0 ? '+' : '') . number_format($pm['profit'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $margin>=20?'success':($margin>=0?'warning':'danger') ?>">
                            <?= $margin ?>%
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($plReport['monthly'])): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-dark fw-bold">
                    <tr>
                        <td class="ps-3">TỔNG CỘNG</td>
                        <td></td><td></td>
                        <td class="text-end text-success">
                            <?= number_format($plReport['total_revenue'], 0, '.', ',') ?> ₫
                        </td>
                        <td class="text-end text-danger">
                            <?= number_format($plReport['total_fuel'], 0, '.', ',') ?> ₫
                        </td>
                        <td class="text-end text-warning">
                            <?= number_format($plReport['total_maint'], 0, '.', ',') ?> ₫
                        </td>
                        <td class="text-end">
                            <?= number_format($plReport['total_cost'], 0, '.', ',') ?> ₫
                        </td>
                        <td class="text-end text-<?= $plReport['total_profit']>=0?'success':'danger' ?>">
                            <?= number_format($plReport['total_profit'], 0, '.', ',') ?> ₫
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $plReport['margin']>=20?'success':($plReport['margin']>=0?'warning':'danger') ?>">
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
    <?php if (!empty($customerReport) && ($customerReport[0]['data_source'] ?? '') === 'realtime'): ?>
    <div class="alert alert-warning py-2 small mb-3">
        <i class="fas fa-exclamation-triangle me-1"></i>
        Chưa có dữ liệu từ bảng kê đã chốt — hiển thị thống kê chuyến (không có doanh thu).
    </div>
    <?php endif; ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Khách hàng</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">KM</th>
                        <th class="text-end">Cầu đường</th>
                        <th class="text-end fw-bold">Doanh thu</th>
                        <th class="text-center">% DT</th>
                        <th>Kỳ đầu</th>
                        <th>Kỳ cuối</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customerReport as $cr): ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold">
                            <?= htmlspecialchars($cr['short_name'] ?: $cr['company_name']) ?>
                        </div>
                        <div class="small text-muted">
                            <?= $cr['customer_code'] ?>
                            <?php if ($cr['contact_name']): ?>
                            · <?= htmlspecialchars($cr['contact_name']) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center"><?= $cr['total_trips'] ?></td>
                    <td class="text-end"><?= number_format($cr['total_km'], 0) ?> km</td>
                    <td class="text-end"><?= number_format($cr['total_toll'] ?? 0, 0, '.', ',') ?> ₫</td>
                    <td class="text-end fw-bold text-success">
                        <?= (float)$cr['total_revenue'] > 0
                            ? number_format($cr['total_revenue'], 0, '.', ',').' ₫'
                            : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <?php if (isset($cr['revenue_pct'])): ?>
                        <span class="badge bg-info"><?= $cr['revenue_pct'] ?>%</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= $cr['first_period'] ? date('d/m/Y', strtotime($cr['first_period'])) : '—' ?>
                    </td>
                    <td class="small text-muted">
                        <?= $cr['last_period'] ? date('d/m/Y', strtotime($cr['last_period'])) : '—' ?>
                    </td>
                    <td>
                        <a href="../statements/index.php?customer_id=<?= $cr['id'] ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
                           class="btn btn-xs btn-outline-primary">
                            <i class="fas fa-file-invoice"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════ TAB: XE ════ -->
    <?php elseif ($tab === 'vehicle'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Biển số</th>
                        <th>Thương hiệu</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">KM</th>
                        <th class="text-end">⛽ Nhiên liệu</th>
                        <th class="text-end">🔧 Bảo dưỡng</th>
                        <th class="text-end">Tổng CP</th>
                        <th class="text-end">CP/km</th>
                        <th class="text-end">km/L</th>
                        <th class="text-center">TT Xe</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicleReport as $vr): ?>
                <tr>
                    <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($vr['plate_number']) ?></td>
                    <td class="small"><?= htmlspecialchars(trim($vr['brand'].' '.$vr['model'])) ?: '—' ?></td>
                    <td class="text-center"><?= $vr['total_trips'] ?></td>
                    <td class="text-end"><?= number_format($vr['total_km'], 0) ?> km</td>
                    <td class="text-end text-danger"><?= number_format($vr['fuel_cost'], 0, '.', ',') ?> ₫</td>
                    <td class="text-end text-warning"><?= number_format($vr['maint_cost'], 0, '.', ',') ?> ₫</td>
                    <td class="text-end fw-semibold"><?= number_format($vr['total_cost'], 0, '.', ',') ?> ₫</td>
                    <td class="text-end small">
                        <?= $vr['cost_per_km'] > 0 ? number_format($vr['cost_per_km'], 0).' ₫' : '—' ?>
                    </td>
                    <td class="text-end small">
                        <?= $vr['km_per_liter'] > 0 ? number_format($vr['km_per_liter'], 1) : '—' ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $vr['vehicle_status']==='active'?'success':'secondary' ?>">
                            <?= $vr['vehicle_status'] ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($vehicleReport)): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════ TAB: LÁI XE ════ -->
    <?php elseif ($tab === 'driver'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Lái xe</th>
                        <th>SĐT</th>
                        <th>Bằng lái</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng KM</th>
                        <th class="text-end">TB KM/chuyến</th>
                        <th class="text-end">⛽ Nhiên liệu</th>
                        <th class="text-center">Lỗi</th>
                        <th class="text-center">⭐ Đánh giá</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($driverReport as $dr): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= htmlspecialchars($dr['full_name']) ?></td>
                    <td class="small"><?= htmlspecialchars($dr['phone'] ?: '—') ?></td>
                    <td class="small"><code><?= htmlspecialchars($dr['license_number'] ?: '—') ?></code></td>
                    <td class="text-center fw-bold"><?= $dr['total_trips'] ?></td>
                    <td class="text-end"><?= number_format($dr['total_km'], 0) ?> km</td>
                    <td class="text-end"><?= number_format($dr['avg_km_per_trip'], 0) ?> km</td>
                    <td class="text-end text-danger"><?= number_format($dr['fuel_cost'], 0, '.', ',') ?> ₫</td>
                    <td class="text-center">
                        <?php if ($dr['faults'] > 0): ?>
                        <span class="badge bg-danger"><?= $dr['faults'] ?></span>
                        <?php else: ?>
                        <span class="badge bg-success">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ((float)$dr['avg_rating'] > 0): ?>
                        <span class="badge bg-warning text-dark">
                            ⭐ <?= number_format($dr['avg_rating'], 1) ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($driverReport)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ════ TAB: NHIÊN LIỆU ════ -->
    <?php elseif ($tab === 'fuel'): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
                <div class="fs-3 fw-bold text-danger">
                    <?= number_format($fuelReport['total_cost'], 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Tổng chi phí nhiên liệu</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
                <div class="fs-3 fw-bold text-info">
                    <?= number_format($fuelReport['total_liters'], 1) ?> L
                </div>
                <div class="small text-muted">Tổng lít</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-3 fw-bold text-success">
                    <?= number_format($fuelReport['total_km'], 0) ?> km
                </div>
                <div class="small text-muted">Tổng KM đã chạy</div>
            </div>
        </div>
    </div>

    <!-- Theo xe -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">🚛 Theo xe</h6>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Biển số</th>
                        <th class="text-center">Số lần đổ</th>
                        <th class="text-end">Tổng lít</th>
                        <th class="text-end">KM đã chạy</th>
                        <th class="text-end">L/100km TB</th>
                        <th class="text-end fw-bold">Chi phí</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fuelReport['by_vehicle'] as $fv): ?>
                <tr>
                    <td class="ps-3 fw-bold text-primary"><?= htmlspecialchars($fv['plate_number']) ?></td>
                    <td class="text-center"><?= $fv['fill_count'] ?></td>
                    <td class="text-end"><?= number_format($fv['total_liters'], 1) ?> L</td>
                    <td class="text-end"><?= number_format($fv['km_driven'] ?? 0, 0) ?> km</td>
                    <td class="text-end">
                        <?= $fv['avg_efficiency'] ? number_format($fv['avg_efficiency'], 2) : '—' ?>
                    </td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format($fv['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Chi tiết -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 pt-3">
            <h6 class="fw-bold mb-0">📋 Chi tiết từng lần đổ</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.82rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ngày</th>
                        <th>Xe</th>
                        <th>Lái xe</th>
                        <th class="text-end">KM trước</th>
                        <th class="text-end">KM sau</th>
                        <th class="text-end">Lít</th>
                        <th class="text-end">Đơn giá</th>
                        <th class="text-end">L/100km</th>
                        <th>Loại xăng</th>
                        <th>Trạm</th>
                        <th class="text-end fw-bold">Tiền</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fuelReport['detail'] as $fd): ?>
                <tr>
                    <td class="ps-3 text-nowrap"><?= date('d/m/Y', strtotime($fd['log_date'])) ?></td>
                    <td class="fw-bold text-primary"><?= htmlspecialchars($fd['plate_number']) ?></td>
                    <td class="small"><?= htmlspecialchars($fd['driver'] ?? '—') ?></td>
                    <td class="text-end"><?= $fd['km_before'] ? number_format($fd['km_before'],0) : '—' ?></td>
                    <td class="text-end"><?= $fd['km_after']  ? number_format($fd['km_after'], 0) : '—' ?></td>
                    <td class="text-end"><?= number_format($fd['liters_filled'], 1) ?> L</td>
                    <td class="text-end small"><?= number_format($fd['price_per_liter'] ?? 0, 0) ?> ₫</td>
                    <td class="text-end small">
                        <?= $fd['lper100km'] ? number_format($fd['lper100km'], 2) : '—' ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($fd['fuel_type'] ?? 'diesel') ?></td>
                    <td class="small"><?= htmlspecialchars($fd['station_name'] ?? '—') ?></td>
                    <td class="text-end fw-bold text-danger">
                        <?= number_format($fd['total_cost'], 0, '.', ',') ?> ₫
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fuelReport['detail'])): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Không có dữ liệu</td></tr>
                <?php endif; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="5" class="ps-3 text-end">TỔNG:</td>
                        <td class="text-end"><?= number_format($fuelReport['total_liters'], 1) ?> L</td>
                        <td colspan="4"></td>
                        <td class="text-end text-danger">
                            <?= number_format($fuelReport['total_cost'], 0, '.', ',') ?> ₫
                        </td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

</div>
</div>

<style>
.btn-xs { padding: 2px 8px; font-size: 12px; }
.nav-tabs .nav-link { font-size: 0.85rem; }
@media print {
    .sidebar, .topbar, form, .btn, .nav-tabs, .screen-only { display:none !important; }
    .main-content { margin:0 !important; }
}
</style>

<?php include '../../includes/footer.php'; ?>