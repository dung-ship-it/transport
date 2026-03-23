<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

// ── Kiểm tra quyền truy cập ──────────────────────────────────
// Cho phép: admin, superadmin, kế toán, manager
// hoặc bất kỳ ai có permission statements.view
$user     = currentUser();
$pdo      = getDBConnection();

// Lấy tên role từ DB để so sánh chính xác
$roleStmt = $pdo->prepare("
    SELECT r.name FROM roles r
    JOIN users u ON u.role_id = r.id
    WHERE u.id = ?
");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');

$allowedRoles = ['admin', 'superadmin', 'accountant', 'ketoan', 'ke_toan', 'manager', 'dispatcher'];
$hasAccess    = in_array($userRole, $allowedRoles) || can('statements', 'view');

if (!$hasAccess) {
    http_response_code(403);
    include '../../includes/header.php';
    include '../../includes/sidebar.php';
    ?>
    <div class="main-content">
        <div class="container-fluid py-5 text-center">
            <i class="fas fa-ban fa-3x text-danger mb-3 d-block"></i>
            <h4>Không có quyền truy cập</h4>
            <p class="text-muted">Trang này dành cho Admin và Kế toán.</p>
            <a href="/transport/dashboard.php" class="btn btn-primary">
                <i class="fas fa-home me-1"></i>Về Dashboard
            </a>
        </div>
    </div>
    <?php
    include '../../includes/footer.php';
    exit;
}

$canEdit   = in_array($userRole, ['admin', 'superadmin']) || can('statements', 'crud');
$pageTitle = 'Bảng kê công nợ';

// ── Mặc định kỳ: 26 tháng trước → 25 tháng này ─────────────
$today = new DateTime();
$day   = (int)$today->format('d');

if ($day >= 26) {
    $periodFrom = (clone $today)
        ->setDate((int)$today->format('Y'), (int)$today->format('m'), 26)
        ->modify('-1 month');
    $periodTo = (clone $today)
        ->setDate((int)$today->format('Y'), (int)$today->format('m'), 25);
} else {
    $periodFrom = (clone $today)
        ->setDate((int)$today->format('Y'), (int)$today->format('m'), 26)
        ->modify('-2 months');
    $periodTo = (clone $today)
        ->setDate((int)$today->format('Y'), (int)$today->format('m'), 25)
        ->modify('-1 month');
}

$dateFrom   = $_GET['date_from']  ?? $periodFrom->format('Y-m-d');
$dateTo     = $_GET['date_to']    ?? $periodTo->format('Y-m-d');
$filterCust = (int)($_GET['customer_id'] ?? 0);

// ── Load danh sách khách hàng (filter) ──────────────────────
$customers = $pdo->query("
    SELECT id, company_name, short_name, customer_code
    FROM customers WHERE is_active = TRUE
    ORDER BY company_name
")->fetchAll();

// ── Lấy tất cả trips trong kỳ, kèm price rule ───────────────
$tripQuery = "
    SELECT
        t.*,
        v.plate_number, v.capacity,
        c.id           AS cust_id,
        c.company_name, c.short_name, c.customer_code, c.tax_code,
        c.primary_contact_name, c.primary_contact_phone,
        c.bank_name, c.bank_account_number,
        u.full_name    AS driver_name,
        pr.id          AS rule_id,
        pr.pricing_mode,
        pr.combo_monthly_price,
        pr.combo_km_limit,
        pr.over_km_price,
        pr.standard_price_per_km,
        pr.toll_included,
        pr.holiday_surcharge,
        pr.sunday_surcharge,
        pr.waiting_fee_per_hour,
        pb.id          AS pb_id,
        pb.name        AS pb_name
    FROM trips t
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN customers c ON t.customer_id = c.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    LEFT JOIN price_books pb ON pb.customer_id = c.id
        AND pb.is_active = TRUE
        AND pb.valid_from <= t.trip_date
        AND (pb.valid_to IS NULL OR pb.valid_to >= t.trip_date)
    LEFT JOIN price_rules pr ON pr.price_book_id = pb.id
        AND pr.vehicle_id = t.vehicle_id
    WHERE t.trip_date BETWEEN ? AND ?
      AND t.status IN ('completed','confirmed')
";
$tripParams = [$dateFrom, $dateTo];

if ($filterCust) {
    $tripQuery   .= " AND c.id = ?";
    $tripParams[] = $filterCust;
}
$tripQuery .= " ORDER BY c.company_name, v.plate_number, t.trip_date";

$allTrips = $pdo->prepare($tripQuery);
$allTrips->execute($tripParams);
$allTrips = $allTrips->fetchAll();

// ── Nhóm trips theo khách hàng → xe ─────────────────────────
$statements = [];

foreach ($allTrips as $t) {
    $cid   = $t['cust_id'];
    $plate = $t['plate_number'];

    if (!isset($statements[$cid])) {
        $statements[$cid] = [
            'customer_id'     => $cid,
            'company_name'    => $t['company_name'],
            'short_name'      => $t['short_name'],
            'customer_code'   => $t['customer_code'],
            'tax_code'        => $t['tax_code'],
            'contact_name'    => $t['primary_contact_name'],
            'contact_phone'   => $t['primary_contact_phone'],
            'bank_name'       => $t['bank_name'],
            'bank_account'    => $t['bank_account_number'],
            'pb_name'         => $t['pb_name'] ?? 'Chưa có bảng giá',
            'vehicles'        => [],
            'total_km'        => 0,
            'total_toll'      => 0,
            'total_amount'    => 0,
            'trip_count'      => 0,
            'confirmed_count' => 0,
            'has_price'       => false,
        ];
    }

    if (!isset($statements[$cid]['vehicles'][$plate])) {
        $statements[$cid]['vehicles'][$plate] = [
            'plate_number'          => $plate,
            'capacity'              => $t['capacity'],
            'pricing_mode'          => $t['pricing_mode'],
            'combo_monthly_price'   => $t['combo_monthly_price'],
            'combo_km_limit'        => $t['combo_km_limit'],
            'over_km_price'         => $t['over_km_price'],
            'standard_price_per_km' => $t['standard_price_per_km'],
            'toll_included'         => $t['toll_included'],
            'holiday_surcharge'     => $t['holiday_surcharge'] ?? 0,
            'sunday_surcharge'      => $t['sunday_surcharge']  ?? 0,
            'has_rule'              => $t['rule_id'] ? true : false,
            'trip_count'            => 0,
            'total_km'              => 0,
            'total_toll'            => 0,
            'sunday_km'             => 0,
            'sunday_trips'          => 0,
            'amount_base'           => 0,
            'amount_toll'           => 0,
            'amount_surcharge'      => 0,
            'amount_total'          => 0,
            'over_km'               => 0,
            'over_amount'           => 0,
            'trips'                 => [],
        ];
    }

    $veh = &$statements[$cid]['vehicles'][$plate];
    $veh['trip_count']++;
    $veh['total_km']   += (float)$t['total_km'];
    $veh['total_toll'] += (float)$t['toll_fee'];
    if ($t['is_sunday']) {
        $veh['sunday_km']    += (float)$t['total_km'];
        $veh['sunday_trips'] += 1;
    }
    $veh['trips'][] = $t;

    if ($t['status'] === 'confirmed') $statements[$cid]['confirmed_count']++;
    $statements[$cid]['trip_count']++;
    $statements[$cid]['total_km']   += (float)$t['total_km'];
    $statements[$cid]['total_toll'] += (float)$t['toll_fee'];
}

// ── Tính tiền theo pricing mode ──────────────────────────────
foreach ($statements as $cid => &$stmt) {
    foreach ($stmt['vehicles'] as $plate => &$veh) {
        if (!$veh['has_rule']) {
            $veh['amount_base'] = $veh['amount_toll'] =
            $veh['amount_surcharge'] = $veh['amount_total'] = 0;
            continue;
        }

        $mode = $veh['pricing_mode'];

        if ($mode === 'combo') {
            $base     = (float)($veh['combo_monthly_price'] ?? 0);
            $kmLimit  = (float)($veh['combo_km_limit']  ?? 0);
            $overRate = (float)($veh['over_km_price']   ?? 0);
            $overKm   = max(0, $veh['total_km'] - $kmLimit);
            $overAmt  = $overKm * $overRate;
            $veh['over_km']     = $overKm;
            $veh['over_amount'] = $overAmt;
            $veh['amount_base'] = $base + $overAmt;
        } else {
            $rate = (float)($veh['standard_price_per_km'] ?? 0);
            $veh['amount_base'] = $veh['total_km'] * $rate;
            $veh['over_km']     = 0;
            $veh['over_amount'] = 0;
        }

        $veh['amount_toll'] = $veh['toll_included'] ? 0 : (float)$veh['total_toll'];

        $sunRate = (float)($veh['sunday_surcharge'] ?? 0);
        if ($sunRate > 0 && $veh['sunday_km'] > 0) {
            $avgRate = $veh['total_km'] > 0
                ? $veh['amount_base'] / $veh['total_km']
                : 0;
            $veh['amount_surcharge'] = $veh['sunday_km'] * $avgRate * ($sunRate / 100);
        } else {
            $veh['amount_surcharge'] = 0;
        }

        $veh['amount_total'] = $veh['amount_base']
                             + $veh['amount_toll']
                             + $veh['amount_surcharge'];

        $stmt['total_amount'] += $veh['amount_total'];
        if ($veh['has_rule']) $stmt['has_price'] = true;
    }
    unset($veh);
}
unset($stmt);

$grandTotal = $grandKm = $grandToll = $grandTrips = 0;
foreach ($statements as $s) {
    $grandTotal  += $s['total_amount'];
    $grandKm     += $s['total_km'];
    $grandToll   += $s['total_toll'];
    $grandTrips  += $s['trip_count'];
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">💰 Bảng kê công nợ</h4>
            <small class="text-muted">Tính tiền dựa trên bảng giá × KM thực tế</small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if (in_array($userRole, ['admin','superadmin'])): ?>
            <span class="badge bg-danger fs-6">👑 Admin</span>
            <?php elseif (in_array($userRole, ['accountant','ketoan','ke_toan'])): ?>
            <span class="badge bg-info fs-6">💼 Kế toán</span>
            <?php elseif ($userRole === 'manager'): ?>
            <span class="badge bg-primary fs-6">📊 Manager</span>
            <?php endif; ?>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">
                        Từ ngày
                        <span class="text-muted fw-normal">(mặc định 26 tháng trước)</span>
                    </label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $dateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">
                        Đến ngày
                        <span class="text-muted fw-normal">(mặc định 25 tháng này)</span>
                    </label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $dateTo ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Khách hàng</label>
                    <select name="customer_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả khách hàng --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $filterCust == $c['id'] ? 'selected' : '' ?>>
                            [<?= $c['customer_code'] ?>]
                            <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-search me-1"></i>Tính toán
                    </button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <?php if (!empty($statements)): ?>
                    <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="window.print()">
                        <i class="fas fa-print me-1"></i>In tất cả
                    </button>
                    <?php endif; ?>
                </div>
                <div class="col-md-2">
                    <div class="alert alert-info py-1 px-2 mb-0 small">
                        <i class="fas fa-info-circle me-1"></i>
                        Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?></strong>
                        → <strong><?= date('d/m/Y', strtotime($dateTo)) ?></strong>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Tổng quan -->
    <?php if (!empty($statements)): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
                <div class="fs-2 fw-bold text-primary"><?= count($statements) ?></div>
                <div class="small text-muted">Khách hàng</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
                <div class="fs-2 fw-bold text-info"><?= $grandTrips ?></div>
                <div class="small text-muted">Tổng chuyến</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-2 fw-bold text-success">
                    <?= number_format($grandKm, 0) ?> km
                </div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
                <div class="fs-2 fw-bold text-warning">
                    <?= number_format($grandTotal, 0, '.', ',') ?> ₫
                </div>
                <div class="small text-muted">Tổng tiền (ước tính)</div>
            </div>
        </div>
    </div>

    <!-- Bảng tổng hợp tất cả KH -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between">
            <h6 class="fw-bold mb-0">📊 Tổng hợp theo khách hàng</h6>
            <small class="text-muted">
                Kỳ: <?= date('d/m/Y', strtotime($dateFrom)) ?>
                — <?= date('d/m/Y', strtotime($dateTo)) ?>
            </small>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">Khách hàng</th>
                        <th class="text-center">Số xe</th>
                        <th class="text-center">Số chuyến</th>
                        <th class="text-center">Đã duyệt</th>
                        <th class="text-end">Tổng KM</th>
                        <th class="text-end">Cầu đường</th>
                        <th class="text-end">Bảng giá</th>
                        <th class="text-end fw-bold">Thành tiền</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($statements as $cid => $stmt): ?>
                <tr class="<?= !$stmt['has_price'] ? 'table-warning bg-opacity-25' : '' ?>">
                    <td class="ps-3">
                        <div class="fw-semibold">
                            <?= htmlspecialchars($stmt['short_name'] ?: $stmt['company_name']) ?>
                        </div>
                        <div class="text-muted small">
                            <span class="badge bg-secondary"><?= $stmt['customer_code'] ?></span>
                            <?= htmlspecialchars($stmt['pb_name']) ?>
                        </div>
                        <?php if (!$stmt['has_price']): ?>
                        <span class="badge bg-warning text-dark">⚠️ Chưa có bảng giá</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-info"><?= count($stmt['vehicles']) ?></span>
                    </td>
                    <td class="text-center"><?= $stmt['trip_count'] ?></td>
                    <td class="text-center">
                        <?php
                        $pct = $stmt['trip_count'] > 0
                            ? round($stmt['confirmed_count'] / $stmt['trip_count'] * 100)
                            : 0;
                        ?>
                        <span class="badge bg-<?= $pct==100?'success':($pct>0?'warning':'secondary') ?>">
                            <?= $stmt['confirmed_count'] ?>/<?= $stmt['trip_count'] ?>
                        </span>
                    </td>
                    <td class="text-end fw-semibold">
                        <?= number_format($stmt['total_km'], 0) ?> km
                    </td>
                    <td class="text-end">
                        <?= number_format($stmt['total_toll'], 0, '.', ',') ?> ₫
                    </td>
                    <td class="text-end small text-muted">
                        <?= htmlspecialchars($stmt['pb_name']) ?>
                    </td>
                    <td class="text-end fw-bold fs-6
                        <?= $stmt['has_price'] ? 'text-success' : 'text-muted' ?>">
                        <?= $stmt['has_price']
                            ? number_format($stmt['total_amount'], 0, '.', ',') . ' ₫'
                            : '—' ?>
                    </td>
                    <td class="text-center">
                        <a href="?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&customer_id=<?= $cid ?>#stmt-<?= $cid ?>"
                           class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-eye me-1"></i>Chi tiết
                        </a>
                        <a href="print_statement_billing.php?customer_id=<?= $cid ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
                           class="btn btn-sm btn-outline-secondary" target="_blank">
                            <i class="fas fa-print"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-dark">
                    <tr>
                        <td class="ps-3 fw-bold">
                            TỔNG CỘNG (<?= count($statements) ?> khách hàng)
                        </td>
                        <td></td>
                        <td class="text-center fw-bold"><?= $grandTrips ?></td>
                        <td></td>
                        <td class="text-end fw-bold">
                            <?= number_format($grandKm, 0) ?> km
                        </td>
                        <td class="text-end fw-bold">
                            <?= number_format($grandToll, 0, '.', ',') ?> ₫
                        </td>
                        <td></td>
                        <td class="text-end fw-bold text-warning fs-6">
                            <?= number_format($grandTotal, 0, '.', ',') ?> ₫
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Chi tiết từng khách hàng -->
    <?php foreach ($statements as $cid => $stmt): ?>
    <div class="card border-0 shadow-sm mb-4 stmt-card" id="stmt-<?= $cid ?>">

        <!-- Header KH -->
        <div class="card-header py-2 d-flex justify-content-between align-items-center"
             style="background:#0f3460;color:#fff">
            <div>
                <span class="fw-bold fs-6">
                    🏢 <?= htmlspecialchars($stmt['short_name'] ?: $stmt['company_name']) ?>
                </span>
                <span class="badge bg-light text-dark ms-2"><?= $stmt['customer_code'] ?></span>
                <?php if ($stmt['tax_code']): ?>
                <span class="small opacity-75 ms-2">MST: <?= $stmt['tax_code'] ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($stmt['has_price']): ?>
                <span class="fw-bold fs-6">
                    💰 <?= number_format($stmt['total_amount'], 0, '.', ',') ?> ₫
                </span>
                <?php else: ?>
                <span class="badge bg-warning text-dark">⚠️ Chưa có bảng giá</span>
                <?php endif; ?>
                <a href="print_statement_billing.php?customer_id=<?= $cid ?>&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
                   class="btn btn-sm btn-light" target="_blank">
                    <i class="fas fa-print me-1"></i>In
                </a>
            </div>
        </div>

        <div class="card-body p-0">

            <!-- Thông tin bảng giá áp dụng -->
            <?php if ($stmt['pb_name'] && $stmt['pb_name'] !== 'Chưa có bảng giá'): ?>
            <div class="px-3 py-2 bg-light border-bottom small text-muted">
                <i class="fas fa-tags me-1 text-primary"></i>
                Bảng giá áp dụng:
                <strong class="text-primary"><?= htmlspecialchars($stmt['pb_name']) ?></strong>
                | Kỳ: <?= date('d/m/Y', strtotime($dateFrom)) ?>
                — <?= date('d/m/Y', strtotime($dateTo)) ?>
                | <?= $stmt['trip_count'] ?> chuyến
                | <?= number_format($stmt['total_km'], 0) ?> km
            </div>
            <?php endif; ?>

            <!-- Bảng tổng hợp theo xe -->
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size:0.82rem">
                    <thead style="background:#e8f4fd">
                        <tr class="text-center">
                            <th class="ps-3 text-start" rowspan="2">Biển số xe</th>
                            <th rowspan="2">Tải trọng</th>
                            <th rowspan="2">Loại giá</th>
                            <th rowspan="2">Đơn giá</th>
                            <th rowspan="2">KM COMBO<br>/tháng</th>
                            <th rowspan="2">Số chuyến</th>
                            <th rowspan="2">Tổng KM<br>thực tế</th>
                            <th rowspan="2">Quá KM</th>
                            <th colspan="3" class="table-warning">THÀNH TIỀN</th>
                            <th rowspan="2">Cầu đường</th>
                            <th rowspan="2">Phụ phí CN</th>
                            <th rowspan="2" class="table-success fw-bold">TỔNG</th>
                        </tr>
                        <tr class="text-center">
                            <th class="table-warning small">Tiền COMBO/<br>KM cơ bản</th>
                            <th class="table-warning small">Tiền quá KM</th>
                            <th class="table-warning small">Tổng cơ bản</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stmt['vehicles'] as $plate => $veh): ?>
                    <tr class="<?= !$veh['has_rule'] ? 'table-warning bg-opacity-25' : '' ?>">
                        <td class="ps-3 fw-bold text-primary">
                            <?= htmlspecialchars($plate) ?>
                            <?php if (!$veh['has_rule']): ?>
                            <span class="badge bg-warning text-dark ms-1"
                                  style="font-size:0.65rem">Chưa có giá</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-muted small">
                            <?= $veh['capacity'] ? $veh['capacity'].' tấn' : '—' ?>
                        </td>
                        <td class="text-center">
                            <?php if ($veh['pricing_mode'] === 'combo'): ?>
                            <span class="badge bg-primary">COMBO</span>
                            <?php elseif ($veh['pricing_mode'] === 'standard'): ?>
                            <span class="badge bg-secondary">THƯỜNG</span>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end small">
                            <?php if ($veh['pricing_mode'] === 'combo'): ?>
                            <span class="fw-bold text-success">
                                <?= number_format($veh['combo_monthly_price'] ?? 0, 0, '.', ',') ?> ₫
                            </span>
                            <div class="text-muted" style="font-size:0.7rem">/tháng</div>
                            <?php elseif ($veh['pricing_mode'] === 'standard'): ?>
                            <span class="fw-bold text-info">
                                <?= number_format($veh['standard_price_per_km'] ?? 0, 0, '.', ',') ?>
                            </span>
                            <div class="text-muted" style="font-size:0.7rem">₫/km</div>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-center small">
                            <?= $veh['combo_km_limit']
                                ? number_format($veh['combo_km_limit'], 0) . ' km'
                                : '—' ?>
                        </td>
                        <td class="text-center"><?= $veh['trip_count'] ?></td>
                        <td class="text-end fw-semibold text-primary">
                            <?= number_format($veh['total_km'], 0) ?> km
                        </td>
                        <td class="text-end
                            <?= ($veh['over_km'] ?? 0) > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                            <?= ($veh['over_km'] ?? 0) > 0
                                ? number_format($veh['over_km'], 0) . ' km'
                                : '—' ?>
                        </td>
                        <!-- COMBO/KM cơ bản -->
                        <td class="text-end table-warning">
                            <?php if ($veh['has_rule']): ?>
                                <?php if ($veh['pricing_mode'] === 'combo'): ?>
                                <?= number_format($veh['combo_monthly_price'] ?? 0, 0, '.', ',') ?> ₫
                                <?php else: ?>
                                <?= number_format(
                                    $veh['total_km'] * ($veh['standard_price_per_km'] ?? 0),
                                    0, '.', ','
                                ) ?> ₫
                                <?php endif; ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <!-- Quá KM -->
                        <td class="text-end table-warning">
                            <?= ($veh['over_amount'] ?? 0) > 0
                                ? number_format($veh['over_amount'], 0, '.', ',') . ' ₫'
                                : '—' ?>
                        </td>
                        <!-- Tổng cơ bản -->
                        <td class="text-end table-warning fw-semibold">
                            <?= $veh['has_rule']
                                ? number_format($veh['amount_base'], 0, '.', ',') . ' ₫'
                                : '—' ?>
                        </td>
                        <!-- Cầu đường -->
                        <td class="text-end">
                            <?php if ($veh['toll_included']): ?>
                            <span class="text-muted small">Đã bao gồm</span>
                            <?php elseif ($veh['amount_toll'] > 0): ?>
                            <span class="text-warning fw-semibold">
                                <?= number_format($veh['amount_toll'], 0, '.', ',') ?> ₫
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <!-- Phụ phí CN -->
                        <td class="text-end">
                            <?= $veh['amount_surcharge'] > 0
                                ? '<span class="text-info fw-semibold">'
                                  . number_format($veh['amount_surcharge'], 0, '.', ',')
                                  . ' ₫</span>'
                                  . ' <small class="text-muted">(' . $veh['sunday_trips'] . ' CN)</small>'
                                : '—' ?>
                        </td>
                        <!-- TỔNG -->
                        <td class="text-end fw-bold table-success fs-6">
                            <?= $veh['has_rule']
                                ? number_format($veh['amount_total'], 0, '.', ',') . ' ₫'
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" style="background:#e8f4fd">
                            <td class="ps-3" colspan="5">
                                TỔNG CỘNG (<?= count($stmt['vehicles']) ?> xe,
                                <?= $stmt['trip_count'] ?> chuyến)
                            </td>
                            <td class="text-center"><?= $stmt['trip_count'] ?></td>
                            <td class="text-end text-primary">
                                <?= number_format($stmt['total_km'], 0) ?> km
                            </td>
                            <td></td>
                            <td colspan="3"></td>
                            <td class="text-end">
                                <?= number_format($stmt['total_toll'], 0, '.', ',') ?> ₫
                            </td>
                            <td></td>
                            <td class="text-end text-success fs-6">
                                <?= $stmt['has_price']
                                    ? number_format($stmt['total_amount'], 0, '.', ',') . ' ₫'
                                    : '—' ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Chi tiết chuy��n (collapsible) -->
            <div class="px-3 py-2">
                <button class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#trips-<?= $cid ?>"
                        aria-expanded="false">
                    <i class="fas fa-list me-1"></i>
                    Xem chi tiết <?= $stmt['trip_count'] ?> chuyến
                    <i class="fas fa-chevron-down ms-1"></i>
                </button>
            </div>

            <div class="collapse" id="trips-<?= $cid ?>">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0"
                           style="font-size:0.78rem">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">STT</th>
                                <th>Ngày</th>
                                <th>Mã chuyến</th>
                                <th>Biển số</th>
                                <th>Lái xe</th>
                                <th>Điểm đi → Điểm đến</th>
                                <th class="text-end">KM đi</th>
                                <th class="text-end">KM về</th>
                                <th class="text-end">Tổng KM</th>
                                <th class="text-end">Cầu đường</th>
                                <th>Ghi chú</th>
                                <th class="text-center">Trạng thái</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $i = 0;
                        foreach ($stmt['vehicles'] as $plate => $veh):
                            foreach ($veh['trips'] as $t):
                                $i++;
                                $statusCls = [
                                    'completed' => 'primary',
                                    'confirmed' => 'success',
                                    'rejected'  => 'danger',
                                ][$t['status']] ?? 'secondary';
                                $statusLbl = [
                                    'completed' => '✅ Hoàn thành',
                                    'confirmed' => '👍 Đã duyệt',
                                    'rejected'  => '❌ Từ chối',
                                ][$t['status']] ?? $t['status'];
                                $from = $t['pickup_location']  ?? $t['route_from'] ?? '—';
                                $to   = $t['dropoff_location'] ?? $t['route_to']   ?? '—';
                        ?>
                        <tr class="<?= $t['status']==='confirmed'
                            ? 'table-success bg-opacity-10'
                            : ($t['status']==='rejected' ? 'table-danger bg-opacity-10' : '') ?>">
                            <td class="ps-3 text-muted"><?= $i ?></td>
                            <td class="text-nowrap">
                                <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                                <?php if ($t['is_sunday']): ?>
                                <span class="badge bg-warning" style="font-size:0.6rem">CN</span>
                                <?php endif; ?>
                            </td>
                            <td><code><?= htmlspecialchars($t['trip_code']) ?></code></td>
                            <td class="fw-bold text-primary">
                                <?= htmlspecialchars($t['plate_number']) ?>
                            </td>
                            <td><?= htmlspecialchars($t['driver_name']) ?></td>
                            <td class="text-uppercase">
                                <?= htmlspecialchars($from) ?>
                                <i class="fas fa-arrow-right text-muted mx-1"></i>
                                <?= htmlspecialchars($to) ?>
                            </td>
                            <td class="text-end">
                                <?= $t['odometer_start']
                                    ? number_format($t['odometer_start'], 0)
                                    : '—' ?>
                            </td>
                            <td class="text-end">
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
                            <td class="text-muted">
                                <?= htmlspecialchars($t['note'] ?? '') ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $statusCls ?>"><?= $statusLbl ?></span>
                            </td>
                        </tr>
                        <?php endforeach; endforeach; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="8" class="ps-3 text-end">
                                    Tổng (<?= $stmt['trip_count'] ?> chuyến):
                                </td>
                                <td class="text-end text-primary">
                                    <?= number_format($stmt['total_km'], 0) ?> km
                                </td>
                                <td class="text-end">
                                    <?= number_format($stmt['total_toll'], 0, '.', ',') ?> ₫
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div><!-- end card-body -->
    </div><!-- end stmt-card -->
    <?php endforeach; ?>

    <?php else: ?>
    <!-- Empty state -->
    <div class="card border-0 shadow-sm p-5 text-center">
        <i class="fas fa-file-invoice-dollar fa-3x mb-3 text-muted opacity-25"></i>
        <h5 class="text-muted">Chưa có dữ liệu</h5>
        <p class="text-muted">
            Chọn kỳ thời gian và nhấn <strong>Tính toán</strong> để xem bảng kê công nợ
        </p>
        <p class="text-muted small">
            Kỳ mặc định:
            <strong><?= date('d/m/Y', strtotime($dateFrom)) ?></strong>
            → <strong><?= date('d/m/Y', strtotime($dateTo)) ?></strong>
        </p>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- Print styles -->
<style>
@media print {
    .sidebar, .topbar, nav.navbar,
    form, .btn, .alert, .screen-only { display:none !important; }
    .main-content { margin:0 !important; padding:0 !important; }
    .container-fluid { padding:5mm !important; }
    .collapse { display:block !important; }
    .card {
        border:1px solid #ccc !important;
        box-shadow:none !important;
        margin-bottom:10mm !important;
    }
    .card-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table { font-size:8pt !important; }
    .table-success, .table-warning, .table-dark {
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>