<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();

// Lấy role từ DB
$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$role = strtolower($roleStmt->fetchColumn() ?? '');

$pageTitle = 'Bảng kê của tôi';

// ── Xác định phạm vi dữ liệu theo role ──────────────────────
$driverRow  = null;
$customerId = null;
$cu         = null;

if ($role === 'driver') {
    $dStmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
    $dStmt->execute([$user['id']]);
    $driverRow = $dStmt->fetch();
}

if ($role === 'customer') {
    $cuStmt = $pdo->prepare("
        SELECT cu.*, c.*
        FROM customer_users cu
        JOIN customers c ON cu.customer_id = c.id
        WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
    ");
    $cuStmt->execute([$user['id']]);
    $cu = $cuStmt->fetch();
    if ($cu) $customerId = $cu['customer_id'];
}

// ── Bộ lọc ──────────────────────────────────────────────────
$period      = $_GET['period']    ?? 'monthly';
$filterMonth = $_GET['month']     ?? date('Y-m');
$dateFrom    = $_GET['date_from'] ?? date('Y-m-01');
$dateTo      = $_GET['date_to']   ?? date('Y-m-d');
$filterCustId  = (int)($_GET['filter_customer'] ?? 0);
$filterPlate   = trim($_GET['filter_plate'] ?? '');

[$year, $month] = explode('-', $filterMonth);

if ($period === 'monthly') {
    $dateFrom = "$year-$month-01";
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
}

// ── Build WHERE ──────────────────────────────────────────────
$where  = ['t.trip_date BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];

if ($role === 'driver' && $driverRow) {
    $where[]  = 't.driver_id = ?';
    $params[] = $driverRow['id'];
} elseif ($role === 'customer' && $customerId) {
    $where[]  = 't.customer_id = ?';
    $params[] = $customerId;
}

// Lọc khách hàng cụ thể (admin/dispatcher)
if ($filterCustId && !in_array($role, ['driver','customer'])) {
    $where[]  = 't.customer_id = ?';
    $params[] = $filterCustId;
}

// Lọc biển số xe cụ thể
if ($filterPlate) {
    $where[]  = 'v.plate_number = ?';
    $params[] = $filterPlate;
}

$whereStr = implode(' AND ', $where);

// ── Query trips — sắp xếp theo KH → biển số → ngày ─────────
$trips = $pdo->prepare("
    SELECT t.*,
           v.plate_number, v.capacity,
           u.full_name       AS driver_name,
           c.id              AS customer_id,
           c.company_name    AS customer_name,
           c.short_name      AS customer_short,
           c.tax_code        AS customer_tax,
           cu_user.full_name AS confirmed_by_name
    FROM trips t
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    WHERE $whereStr
    ORDER BY c.company_name ASC, v.plate_number ASC, t.trip_date ASC
");
$trips->execute($params);
$trips = $trips->fetchAll();

$totalKm    = array_sum(array_column($trips, 'total_km'));
$totalToll  = array_sum(array_column($trips, 'toll_fee'));
$totalTrips = count($trips);

// ── Nhóm theo khách hàng → biển số ──────────────────────────
$grouped = []; // [customer_id => ['info'=>..., 'vehicles'=>[plate=>[trips]]]]

foreach ($trips as $t) {
    $cid   = $t['customer_id'];
    $plate = $t['plate_number'];

    if (!isset($grouped[$cid])) {
        $grouped[$cid] = [
            'customer_id'   => $cid,
            'customer_name' => $t['customer_name'],
            'customer_short'=> $t['customer_short'],
            'customer_tax'  => $t['customer_tax'],
            'vehicles'      => [],
            'total_km'      => 0,
            'total_toll'    => 0,
            'trip_count'    => 0,
            'confirmed'     => 0,
        ];
    }

    if (!isset($grouped[$cid]['vehicles'][$plate])) {
        $grouped[$cid]['vehicles'][$plate] = [
            'plate_number' => $plate,
            'capacity'     => $t['capacity'],
            'trip_count'   => 0,
            'total_km'     => 0,
            'total_toll'   => 0,
            'confirmed'    => 0,
            'trips'        => [],
        ];
    }

    $grouped[$cid]['vehicles'][$plate]['trips'][]   = $t;
    $grouped[$cid]['vehicles'][$plate]['trip_count']++;
    $grouped[$cid]['vehicles'][$plate]['total_km']   += (float)$t['total_km'];
    $grouped[$cid]['vehicles'][$plate]['total_toll'] += (float)$t['toll_fee'];
    if ($t['status'] === 'confirmed') {
        $grouped[$cid]['vehicles'][$plate]['confirmed']++;
        $grouped[$cid]['confirmed']++;
    }

    $grouped[$cid]['trip_count']++;
    $grouped[$cid]['total_km']   += (float)$t['total_km'];
    $grouped[$cid]['total_toll'] += (float)$t['toll_fee'];
}

// ── Load danh sách KH và biển số cho filter (admin) ─────────
$allCustomers = [];
$allPlates    = [];
if (!in_array($role, ['driver','customer'])) {
    $allCustomers = $pdo->query("
        SELECT id, company_name, short_name FROM customers
        WHERE is_active = TRUE ORDER BY company_name
    ")->fetchAll();

    $plateStmt = $pdo->prepare("
        SELECT DISTINCT v.plate_number
        FROM trips t JOIN vehicles v ON t.vehicle_id = v.id
        WHERE t.trip_date BETWEEN ? AND ?
        ORDER BY v.plate_number
    ");
    $plateStmt->execute([$dateFrom, $dateTo]);
    $allPlates = $plateStmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($role === 'driver') {
    // Lái xe: danh sách biển số của họ
    $plateStmt = $pdo->prepare("
        SELECT DISTINCT v.plate_number
        FROM trips t JOIN vehicles v ON t.vehicle_id = v.id
        WHERE t.driver_id = ? ORDER BY v.plate_number
    ");
    $plateStmt->execute([$driverRow['id'] ?? 0]);
    $allPlates = $plateStmt->fetchAll(PDO::FETCH_COLUMN);
}

$statusPrint = [
    'draft'     => 'Draft',
    'submitted' => 'Đã gửi',
    'completed' => 'Hoàn thành',
    'confirmed' => 'Đã duyệt',
    'rejected'  => 'Từ chối',
    'in_progress' => 'Đang chạy',
    'scheduled'   => 'Chờ',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bảng kê — <?= htmlspecialchars($user['full_name']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Times New Roman', serif; font-size:11pt; color:#222; }

.screen-toolbar {
    background:#fff;
    border-bottom:1px solid #dee2e6;
    padding:10px 20px;
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:wrap;
    position:sticky;
    top:0;
    z-index:100;
    box-shadow:0 2px 4px rgba(0,0,0,.08);
}
.screen-toolbar button,
.screen-toolbar a.tbtn {
    padding:7px 16px;
    border-radius:5px;
    border:1px solid #0d6efd;
    background:#0d6efd;
    color:#fff;
    text-decoration:none;
    font-size:13px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-family:sans-serif;
}
.screen-toolbar a.tbtn-outline {
    padding:7px 16px;
    border-radius:5px;
    border:1px solid #0d6efd;
    background:#fff;
    color:#0d6efd;
    text-decoration:none;
    font-size:13px;
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-family:sans-serif;
}
.screen-toolbar select,
.screen-toolbar input[type=month],
.screen-toolbar input[type=date] {
    padding:6px 10px;
    border-radius:5px;
    border:1px solid #ced4da;
    font-size:13px;
    font-family:sans-serif;
}
.screen-toolbar label { font-size:12px; color:#666; font-family:sans-serif; }
.tb-sep { color:#ccc; font-size:18px; }

.print-body { padding:15mm 15mm 10mm; }

/* Header */
.company-header { text-align:center; margin-bottom:12px; }
.company-name   { font-weight:bold; font-size:13pt; text-transform:uppercase; }
.company-sub    { font-size:9.5pt; color:#444; margin-top:2px; }
.report-title   {
    text-align:center; font-weight:bold; font-size:14pt;
    margin:15px 0 4px; text-transform:uppercase; letter-spacing:0.5px;
}
.report-meta    { text-align:center; font-size:9.5pt; color:#555; margin-bottom:15px; }

/* Summary */
.summary-grid {
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-bottom:16px;
    font-family:sans-serif;
}
.summary-card {
    border:1px solid #dee2e6;
    border-radius:6px;
    padding:10px;
    text-align:center;
    background:#f8f9fa;
}
.summary-card .num { font-size:20pt; font-weight:bold; color:#0d6efd; }
.summary-card .lbl { font-size:8.5pt; color:#666; margin-top:2px; }

/* Customer block */
.customer-block {
    margin-bottom:24px;
    page-break-inside:avoid;
}
.customer-header {
    background:#0f3460;
    color:#fff;
    padding:7px 12px;
    font-weight:bold;
    font-size:11pt;
    display:flex;
    justify-content:space-between;
    align-items:center;
    border-radius:4px 4px 0 0;
}
.customer-header .cname { font-size:11pt; }
.customer-header .cstat { font-size:9pt; opacity:0.9; }

/* Vehicle block */
.vehicle-block { margin-bottom:12px; }
.vehicle-title {
    background:#e8f4fd;
    padding:5px 10px;
    font-weight:bold;
    font-size:10pt;
    border-left:4px solid #0d6efd;
    margin-bottom:4px;
    display:flex;
    justify-content:space-between;
}

/* Tables */
table           { width:100%; border-collapse:collapse; font-size:8.5pt; }
th, td          { border:1px solid #aaa; padding:3px 5px; }
thead th        { background:#f0f0f0; text-align:center; font-weight:bold; }
.text-center    { text-align:center; }
.text-right     { text-align:right; }
.text-upper     { text-transform:uppercase; font-weight:bold; }
tfoot td        { font-weight:bold; background:#f5f5f5; }
tr.confirmed    { background:#f0fff4; }
tr.rejected     { background:#fff5f5; }
tr.in-progress  { background:#fff8e1; }

/* Summary table */
.summary-table  { margin-bottom:16px; }

/* Section title */
.section-title {
    font-weight:bold; font-size:11pt;
    margin:18px 0 6px;
    padding-bottom:4px;
    border-bottom:2px solid #333;
}

/* Signature */
.signature-row {
    display:flex;
    justify-content:space-between;
    margin-top:35px;
    text-align:center;
}
.sig-box .sig-title { font-weight:bold; margin-bottom:4px; }
.sig-box .sig-sub   { font-size:8.5pt; color:#666; margin-bottom:45px; }
.sig-box .sig-name  { font-weight:bold; }
.sig-box            { width:30%; }

/* Print */
@media print {
    .screen-toolbar { display:none !important; }
    .print-body     { padding:10mm; }
    body            { font-size:9.5pt; }
    .customer-block { page-break-before:auto; }
    .customer-block + .customer-block { page-break-before:always; }
    .customer-header {
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
    .vehicle-title, thead th, tr.confirmed, tr.rejected, tr.in-progress,
    .summary-card {
        -webkit-print-color-adjust:exact;
        print-color-adjust:exact;
    }
}
</style>
</head>
<body>

<!-- ── Toolbar ────────────────────────────────────────────── -->
<div class="screen-toolbar">
    <button onclick="window.print()">🖨️ In bảng kê</button>

    <span class="tb-sep">|</span>

    <div style="display:flex;align-items:center;gap:6px;font-family:sans-serif">
        <label>Kỳ:</label>
        <select onchange="changePeriod(this.value)" id="periodSelect">
            <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Hàng tháng</option>
            <option value="custom"  <?= $period==='custom' ?'selected':'' ?>>Tùy chỉnh</option>
        </select>
    </div>

    <div id="monthPicker" style="display:<?= $period!=='monthly'?'none':'flex' ?>;align-items:center;gap:6px">
        <label>Tháng:</label>
        <input type="month" id="monthInput" value="<?= $filterMonth ?>"
               onchange="reloadMonth()">
    </div>

    <div id="datePicker" style="display:<?= $period==='custom'?'flex':'none' ?>;align-items:center;gap:6px">
        <label>Từ:</label>
        <input type="date" id="dateFrom" value="<?= $dateFrom ?>">
        <label>Đến:</label>
        <input type="date" id="dateTo" value="<?= $dateTo ?>">
        <button onclick="applyCustom()"
                style="background:#198754;border-color:#198754;border:1px solid #198754;
                       padding:6px 12px;border-radius:5px;color:#fff;cursor:pointer;
                       font-family:sans-serif;font-size:13px">
            ✅ Áp dụng
        </button>
    </div>

    <?php if (!empty($allCustomers)): ?>
    <span class="tb-sep">|</span>
    <div style="display:flex;align-items:center;gap:6px;font-family:sans-serif">
        <label>Khách hàng:</label>
        <select id="filterCustomer" onchange="applyFilters()">
            <option value="">-- Tất cả --</option>
            <?php foreach ($allCustomers as $fc): ?>
            <option value="<?= $fc['id'] ?>"
                <?= $filterCustId == $fc['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($fc['short_name'] ?: $fc['company_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if (!empty($allPlates)): ?>
    <div style="display:flex;align-items:center;gap:6px;font-family:sans-serif">
        <label>Biển số:</label>
        <select id="filterPlate" onchange="applyFilters()">
            <option value="">-- Tất cả xe --</option>
            <?php foreach ($allPlates as $fp): ?>
            <option value="<?= htmlspecialchars($fp) ?>"
                <?= $filterPlate === $fp ? 'selected' : '' ?>>
                <?= htmlspecialchars($fp) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <span class="tb-sep">|</span>
    <a href="javascript:history.back()" class="tbtn-outline">← Quay lại</a>
</div>

<!-- ── Nội dung in ────────────────────────────────────────── -->
<div class="print-body">

    <!-- Header công ty -->
    <div class="company-header">
        <div class="company-name">CÔNG TY TNHH DNA EXPRESS VIỆT NAM</div>
        <div class="company-sub">
            Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam
        </div>
        <div class="company-sub">MST: 0107514537</div>
    </div>

    <div class="report-title">Bảng kê tình hình sử dụng xe</div>
    <div class="report-meta">
        <?php if ($role === 'customer' && $cu): ?>
        Khách hàng: <strong><?= htmlspecialchars($cu['company_name']) ?></strong>
        <?php if ($cu['tax_code']): ?> — MST: <?= htmlspecialchars($cu['tax_code']) ?><?php endif; ?>
        <br>
        <?php elseif ($role === 'driver' && $driverRow): ?>
        Lái xe: <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        <?php if ($filterPlate): ?> — Xe: <strong><?= htmlspecialchars($filterPlate) ?></strong><?php endif; ?>
        <br>
        <?php else: ?>
        <?php if ($filterCustId && !empty($grouped)): ?>
        Khách hàng: <strong><?= htmlspecialchars(array_values($grouped)[0]['customer_name'] ?? '') ?></strong>
        <?php else: ?>
        Người lập: <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        <?php endif; ?>
        <?php if ($filterPlate): ?> — Xe: <strong><?= htmlspecialchars($filterPlate) ?></strong><?php endif; ?>
        <br>
        <?php endif; ?>
        Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?> — <?= date('d/m/Y', strtotime($dateTo)) ?></strong>
        &nbsp;|&nbsp; Ngày in: <?= date('d/m/Y H:i') ?>
        &nbsp;|&nbsp; Người in: <?= htmlspecialchars($user['full_name']) ?>
    </div>

    <!-- Tóm tắt tổng -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="num"><?= $totalTrips ?></div>
            <div class="lbl">Tổng chuyến</div>
        </div>
        <div class="summary-card">
            <div class="num" style="color:#198754"><?= number_format($totalKm, 0) ?></div>
            <div class="lbl">Tổng KM</div>
        </div>
        <div class="summary-card">
            <div class="num" style="color:#6f42c1"><?= count($grouped) ?></div>
            <div class="lbl"><?= count($grouped) > 1 ? 'Khách hàng' : 'Khách hàng' ?></div>
        </div>
    </div>

    <?php if (empty($grouped)): ?>
    <div style="text-align:center;padding:40px;color:#999;font-family:sans-serif">
        <div style="font-size:2rem">📭</div>
        <div>Không có dữ liệu trong kỳ này</div>
    </div>
    <?php else: ?>

    <!-- ══ Tóm tắt theo từng khách hàng ════════════════════ -->
   <?php if (!empty($grouped)): ?>
    <div class="section-title">📊 Tóm tắt theo khách hàng & xe</div>
    <table class="summary-table" style="margin-bottom:20px">
        <thead>
            <tr>
                <th>STT</th>
                <th>Khách hàng</th>
                <th>Biển số xe</th>
                <th>Tải trọng</th>
                <th>Số chuyến</th>
                <th>Tổng KM</th>
                <th>Đã duyệt</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $stt = 0;
        foreach ($grouped as $cid => $cGroup):
            $vCount = count($cGroup['vehicles']);
            $first  = true;
            foreach ($cGroup['vehicles'] as $plate => $veh):
                $stt++;
        ?>
        <tr>
            <td class="text-center"><?= $stt ?></td>
            <?php if ($first): ?>
            <td rowspan="<?= $vCount ?>" style="font-weight:bold;vertical-align:middle">
                <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>
                <?php if ($cGroup['customer_tax']): ?>
                <div style="font-weight:normal;font-size:8pt;color:#666">
                    MST: <?= $cGroup['customer_tax'] ?>
                </div>
                <?php endif; ?>
            </td>
            <?php $first = false; endif; ?>
            <td class="text-center text-upper"><?= htmlspecialchars($plate) ?></td>
            <td class="text-center">
                <?= $veh['capacity'] ? $veh['capacity'] . ' tấn' : '—' ?>
            </td>
            <td class="text-center"><?= $veh['trip_count'] ?></td>
            <td class="text-right"><?= number_format($veh['total_km'], 0) ?> km</td>
            <td class="text-center"><?= $veh['confirmed'] ?>/<?= $veh['trip_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        <!-- Subtotal KH -->
        <tr style="background:#e8f4fd;font-weight:bold">
            <td colspan="5" class="text-right" style="font-style:italic">
                Tổng <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>
                (<?= count($cGroup['vehicles']) ?> xe):
            </td>
            <td class="text-center"><?= $cGroup['trip_count'] ?></td>
            <td class="text-right"><?= number_format($cGroup['total_km'], 0) ?> km</td>
            <td class="text-center"><?= $cGroup['confirmed'] ?>/<?= $cGroup['trip_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="text-right">TỔNG CỘNG:</td>
                <td class="text-center"><?= $totalTrips ?></td>
                <td class="text-right"><?= number_format($totalKm, 0) ?> km</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- ══ Chi tiết theo từng khách hàng ════════════════════ -->
    <div class="section-title">📋 Chi tiết chuyến xe theo khách hàng</div>

    <?php foreach ($grouped as $cid => $cGroup): ?>
    <div class="customer-block">

        <!-- Header khách hàng -->
        <div class="customer-header">
            <div class="cname">
                🏢 <?= htmlspecialchars($cGroup['customer_name']) ?>
                <?php if ($cGroup['customer_tax']): ?>
                <span style="font-weight:normal;font-size:9pt;opacity:0.8">
                    — MST: <?= $cGroup['customer_tax'] ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="cstat">
                <?= count($cGroup['vehicles']) ?> xe
                · <?= $cGroup['trip_count'] ?> chuyến
                · <?= number_format($cGroup['total_km'], 0) ?> km
            </div>
        </div>

        <!-- Từng xe trong khách hàng -->
        <?php foreach ($cGroup['vehicles'] as $plate => $veh): ?>
        <div class="vehicle-block">

            <!-- Title biển số -->
            <div class="vehicle-title">
                <span>
                    🚛 Xe: <strong><?= htmlspecialchars($plate) ?></strong>
                    <?= $veh['capacity'] ? '(' . $veh['capacity'] . ' tấn)' : '' ?>
                </span>
                <span style="font-weight:normal;font-size:9pt">
                    <?= $veh['trip_count'] ?> chuyến
                    · <?= number_format($veh['total_km'], 0) ?> km
                    · Đã duyệt: <?= $veh['confirmed'] ?>/<?= $veh['trip_count'] ?>
                </span>
            </div>

            <!-- Bảng chi tiết chuyến -->
            <table>
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Người lái</th>
                        <th>Ngày</th>
                        <?php if ($role !== 'customer'): ?>
                        <th>Khách hàng</th>
                        <?php endif; ?>
                        <th>Điểm đi</th>
                        <th>Điểm đến</th>
                        <th>KM đi</th>
                        <th>KM về</th>
                        <th>Tổng KM</th>
                        <th>Ghi chú</th>
                        <th>Trạng thái</th>
                        <th>KH duyệt</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($veh['trips'] as $ti => $t):
                    $rowClass = $t['status'] === 'confirmed'   ? 'confirmed'
                              : ($t['status'] === 'rejected'   ? 'rejected'
                              : ($t['status'] === 'in_progress'? 'in-progress' : ''));
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="text-center"><?= $ti + 1 ?></td>
                    <td><?= htmlspecialchars($t['driver_name']) ?></td>
                    <td class="text-center" style="white-space:nowrap">
                        <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                        <?= $t['is_sunday'] ? '<br><span style="color:#d97706;font-size:7.5pt">(CN)</span>' : '' ?>
                    </td>
                    <?php if ($role !== 'customer'): ?>
                    <td><?= htmlspecialchars($t['customer_short'] ?: $t['customer_name']) ?></td>
                    <?php endif; ?>
                    <td class="text-upper" style="font-size:8pt">
                        <?= htmlspecialchars($t['pickup_location'] ?? '—') ?>
                    </td>
                    <td class="text-upper" style="font-size:8pt">
                        <?= htmlspecialchars($t['dropoff_location'] ?? '—') ?>
                    </td>
                    <td class="text-right">
                        <?= $t['odometer_start'] ? number_format($t['odometer_start'], 0) : '—' ?>
                    </td>
                    <td class="text-right">
                        <?= $t['odometer_end'] ? number_format($t['odometer_end'], 0) : '—' ?>
                    </td>
                    <td class="text-right" style="font-weight:bold">
                        <?= $t['total_km'] ? number_format($t['total_km'], 0) . ' km' : '—' ?>
                    </td>
                    <td style="font-size:8pt">
                        <?= htmlspecialchars($t['note'] ?? '') ?>
                        <?php if ($t['rejection_reason']): ?>
                        <br><span style="color:#dc3545;font-size:7.5pt">
                            ❌ <?= htmlspecialchars($t['rejection_reason']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $statusPrint[$t['status']] ?? $t['status'] ?>
                    </td>
                    <td style="font-size:8pt">
                        <?php if ($t['confirmed_by_name']): ?>
                        <?= htmlspecialchars($t['confirmed_by_name']) ?>
                        <?php if ($t['confirmed_at']): ?>
                        <br><span style="color:#666;font-size:7.5pt">
                            <?= date('d/m/Y H:i', strtotime($t['confirmed_at'])) ?>
                        </span>
                        <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="<?= ($role !== 'customer') ? 7 : 6 ?>"
                            class="text-right">
                            Tổng xe <?= htmlspecialchars($plate) ?>
                            (<?= $veh['trip_count'] ?> chuyến):
                        </td>
                        <td class="text-right">
                            <?= number_format($veh['total_km'], 0) ?> km
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div><!-- end vehicle-block -->
        <?php endforeach; ?>

        <!-- Subtotal khách hàng -->
        <div style="text-align:right;padding:5px 8px;background:#e8f4fd;
                    font-weight:bold;font-size:9.5pt;border:1px solid #aaa;border-top:none">
            Tổng <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>:
            <?= $cGroup['trip_count'] ?> chuyến
            · <span style="color:#0d6efd"><?= number_format($cGroup['total_km'], 0) ?> km</span>
        </div>

    </div><!-- end customer-block -->
    <?php endforeach; ?>

    <!-- Tổng cộng toàn bộ -->
    <div style="text-align:right;padding:8px 12px;background:#0f3460;color:#fff;
                font-weight:bold;font-size:10.5pt;border-radius:4px;margin-top:8px">
        TỔNG CỘNG TẤT CẢ:
        <?= $totalTrips ?> chuyến
        · <?= number_format($totalKm, 0) ?> km
    </div>

    <?php endif; ?>

    <!-- Ký tên -->
    <div class="signature-row" style="margin-top:35px">
        <div class="sig-box">
            <div class="sig-title">Người lập bảng</div>
            <div class="sig-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sig-name"><?= htmlspecialchars($user['full_name']) ?></div>
        </div>
        <?php if ($role === 'customer' && $cu): ?>
        <div class="sig-box">
            <div class="sig-title">Đại diện khách hàng</div>
            <div class="sig-sub">(Ký, đóng dấu)</div>
            <div class="sig-name"><?= htmlspecialchars($cu['company_name']) ?></div>
        </div>
        <?php else: ?>
        <div class="sig-box">
            <div class="sig-title">Phụ trách điều hành</div>
            <div class="sig-sub">(Ký, ghi rõ họ tên)</div>
            <div class="sig-name">&nbsp;</div>
        </div>
        <?php endif; ?>
        <div class="sig-box">
            <div class="sig-title">DNA EXPRESS VIỆT NAM</div>
            <div class="sig-sub">(Ký, đóng dấu)</div>
            <div class="sig-name">Giám đốc</div>
        </div>
    </div>

</div><!-- end .print-body -->

<script>
function buildParams() {
    const period = document.getElementById('periodSelect').value;
    const cust   = document.getElementById('filterCustomer')?.value ?? '';
    const plate  = document.getElementById('filterPlate')?.value    ?? '';
    let url = '?period=' + period;

    if (period === 'monthly') {
        url += '&month=' + (document.getElementById('monthInput')?.value ?? '');
    } else {
        url += '&date_from=' + (document.getElementById('dateFrom')?.value ?? '');
        url += '&date_to='   + (document.getElementById('dateTo')?.value   ?? '');
    }
    if (cust)  url += '&filter_customer=' + cust;
    if (plate) url += '&filter_plate='    + encodeURIComponent(plate);
    return url;
}

function changePeriod(val) {
    document.getElementById('monthPicker').style.display = val === 'monthly' ? 'flex' : 'none';
    document.getElementById('datePicker').style.display  = val === 'custom'  ? 'flex' : 'none';
    if (val === 'monthly') reloadMonth();
}

function reloadMonth() {
    location.href = buildParams();
}

function applyCustom() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    if (!from || !to)  { alert('Vui lòng chọn ngày!'); return; }
    if (from > to)     { alert('Ngày bắt đầu phải trước ngày kết thúc!'); return; }
    location.href = buildParams();
}

function applyFilters() {
    location.href = buildParams();
}
</script>

</body>
</html>