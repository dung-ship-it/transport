<?php
// ════════════════════════════════════════════════════════════
// TAB REVENUE — Link từ statement_periods (doanh thu đã chốt)
// + Trips realtime (chưa chốt)
// ════════════════════════════════════════════════════════════
$revenueData      = [];
$revenueFromLocked = [];
$revenueLiveTrips  = [];

if ($tab === 'revenue') {

    // ── 1. Doanh thu từ các kỳ đã CHỐT trong khoảng lọc ────
    $revenueFromLocked = safeQuery($pdo,"
        SELECT
            sp.period_from,
            sp.period_to,
            COALESCE(sp.period_label,
                'Kỳ ' || to_char(sp.period_from,'DD/MM/YYYY') || ' - ' || to_char(sp.period_to,'DD/MM/YYYY')
            ) AS period_label,
            sp.total_trips,
            sp.total_km,
            sp.total_amount,
            sp.customer_count,
            sp.locked_at,
            u.full_name AS locked_by_name
        FROM statement_periods sp
        LEFT JOIN users u ON sp.locked_by = u.id
        WHERE sp.status = 'locked'
          AND sp.period_from <= ?
          AND sp.period_to   >= ?
        ORDER BY sp.period_from DESC
    ",[$dateTo, $dateFrom]);

    $lockedRevenue   = array_sum(array_column($revenueFromLocked, 'total_amount'));
    $lockedTrips     = array_sum(array_column($revenueFromLocked, 'total_trips'));
    $lockedKm        = array_sum(array_column($revenueFromLocked, 'total_km'));

    // ── 2. Chi tiết trips từ statement_items (đã chốt) ──────
    if (!empty($revenueFromLocked)) {
        $periodIds = array_column($revenueFromLocked, 'period_from'); // dùng để filter
        $sql = "
            SELECT
                t.trip_code, t.trip_date, t.pickup_location AS route_from,
                t.dropoff_location AS route_to,
                t.total_km, t.toll_fee, t.total_amount, t.status,
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
        if ($filterDriver)   { $sql .= " AND d.id = ?";          $params[] = $filterDriver; }
        if ($filterVehicle)  { $sql .= " AND t.vehicle_id = ?";  $params[] = $filterVehicle; }
        $sql .= " ORDER BY t.trip_date DESC";

        $revenueData = safeQuery($pdo, $sql, $params);
    } else {
        // Không có kỳ chốt → hiện trips realtime
        $sql = "
            SELECT
                t.trip_code, t.trip_date, t.pickup_location AS route_from,
                t.dropoff_location AS route_to,
                t.total_km, t.toll_fee, t.total_amount, t.status,
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
        if ($filterDriver)   { $sql .= " AND d.id = ?";          $params[] = $filterDriver; }
        if ($filterVehicle)  { $sql .= " AND t.vehicle_id = ?";  $params[] = $filterVehicle; }
        $sql .= " ORDER BY t.trip_date DESC";
        $revenueData = safeQuery($pdo, $sql, $params);
        $lockedRevenue = array_sum(array_column($revenueData, 'total_amount'));
        $lockedTrips   = count($revenueData);
        $lockedKm      = array_sum(array_column($revenueData, 'total_km'));
    }
}