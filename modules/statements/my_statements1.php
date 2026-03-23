<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();
$role = $user['role_name'] ?? $user['role'] ?? '';

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
$period      = $_GET['period']     ?? 'monthly';
$filterMonth = $_GET['month']      ?? date('Y-m');
$dateFrom    = $_GET['date_from']  ?? date('Y-m-01');
$dateTo      = $_GET['date_to']    ?? date('Y-m-d');

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

$whereStr = implode(' AND ', $where);

// ── Query trips ──────────────────────────────────────────────
$trips = $pdo->prepare("
    SELECT t.*,
           v.plate_number, v.capacity,
           u.full_name    AS driver_name,
           c.company_name AS customer_name,
           c.short_name   AS customer_short,
           c.tax_code     AS customer_tax,
           cu_user.full_name AS confirmed_by_name
    FROM trips t
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    WHERE $whereStr
    ORDER BY t.trip_date ASC, v.plate_number
");
$trips->execute($params);
$trips = $trips->fetchAll();

$totalKm    = array_sum(array_column($trips, 'total_km'));
$totalToll  = array_sum(array_column($trips, 'toll_fee'));
$totalTrips = count($trips);

// ── Thống kê theo xe (cho phần tóm tắt) ─────────────────────
$byVehicle = [];
foreach ($trips as $t) {
    $plate = $t['plate_number'];
    if (!isset($byVehicle[$plate])) {
        $byVehicle[$plate] = [
            'plate_number' => $plate,
            'capacity'     => $t['capacity'],
            'trip_count'   => 0,
            'total_km'     => 0,
            'total_toll'   => 0,
            'confirmed'    => 0,
        ];
    }
    $byVehicle[$plate]['trip_count']++;
    $byVehicle[$plate]['total_km']   += $t['total_km'];
    $byVehicle[$plate]['total_toll'] += $t['toll_fee'];
    if ($t['status'] === 'confirmed') $byVehicle[$plate]['confirmed']++;
}
usort($byVehicle, fn($a,$b) => $b['total_km'] <=> $a['total_km']);

$statusPrint = [
    'draft'     => 'Draft',
    'submitted' => 'Đã gửi',
    'completed' => 'Hoàn thành',
    'confirmed' => 'Đã duyệt',
    'rejected'  => 'Từ chối',
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

/* Screen only */
.screen-only { display:block; }
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
.screen-toolbar a {
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
}
.screen-toolbar a.btn-outline {
    background:#fff;
    color:#0d6efd;
}
.screen-toolbar select,
.screen-toolbar input {
    padding:6px 10px;
    border-radius:5px;
    border:1px solid #ced4da;
    font-size:13px;
}
.screen-toolbar label { font-size:12px; color:#666; font-family:sans-serif; }

.print-body { padding:15mm 15mm 10mm; }

/* Company header */
.company-header { text-align:center; margin-bottom:12px; }
.company-name   { font-weight:bold; font-size:13pt; text-transform:uppercase; }
.company-sub    { font-size:9.5pt; color:#444; margin-top:2px; }
.report-title   {
    text-align:center; font-weight:bold; font-size:14pt;
    margin:15px 0 4px; text-transform:uppercase;
    letter-spacing:0.5px;
}
.report-meta    { text-align:center; font-size:9.5pt; color:#555; margin-bottom:15px; }

/* Summary box */
.summary-grid {
    display:grid;
    grid-template-columns:repeat(4,1fr);
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

/* Tables */
table           { width:100%; border-collapse:collapse; font-size:9pt; }
th, td          { border:1px solid #aaa; padding:4px 6px; }
thead th        { background:#f0f0f0; text-align:center; font-weight:bold; }
.text-center    { text-align:center; }
.text-right     { text-align:right; }
.text-upper     { text-transform:uppercase; font-weight:bold; }
tfoot td        { font-weight:bold; background:#f5f5f5; }
tr.confirmed    { background:#f0fff4; }
tr.rejected     { background:#fff5f5; }

/* Section titles */
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
.sig-box { width:30%; }
.sig-box .sig-title { font-weight:bold; margin-bottom:4px; }
.sig-box .sig-sub   { font-size:8.5pt; color:#666; margin-bottom:45px; }
.sig-box .sig-name  { font-weight:bold; }

/* Print */
@media print {
    .screen-only,
    .screen-toolbar { display:none !important; }
    .print-body { padding:10mm; }
    body { font-size:10pt; }
    .summary-card .num { font-size:16pt; }
    tr.confirmed { background:#f0fff4 !important; -webkit-print-color-adjust:exact; }
    tr.rejected  { background:#fff5f5 !important; -webkit-print-color-adjust:exact; }
    thead th     { background:#f0f0f0 !important; -webkit-print-color-adjust:exact; }
}
</style>
</head>
<body>

<!-- Toolbar (ẩn khi in) -->
<div class="screen-toolbar screen-only">
    <button onclick="window.print()">🖨️ In bảng kê</button>

    <div>
        <label>Kỳ:</label>
        <select onchange="changePeriod(this.value)" id="periodSelect">
            <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Hàng tháng</option>
            <option value="custom"  <?= $period==='custom' ?'selected':'' ?>>Tùy chỉnh</option>
        </select>
    </div>

    <div id="monthPicker" <?= $period!=='monthly'?'style="display:none"':'' ?>>
        <label>Tháng:</label>
        <input type="month" id="monthInput" value="<?= $filterMonth ?>"
               onchange="reloadPage()">
    </div>

    <div id="datePicker" <?= $period!=='custom'?'style="display:none"':'' ?>
         style="display:flex;gap:6px;align-items:center">
        <label>Từ:</label>
        <input type="date" id="dateFrom" value="<?= $dateFrom ?>">
        <label>Đến:</label>
        <input type="date" id="dateTo" value="<?= $dateTo ?>">
        <button onclick="applyCustom()" style="background:#198754;border-color:#198754">
            ✅ Áp dụng
        </button>
    </div>

    <a href="../trips/my_trips.php?month=<?= $filterMonth ?>" class="btn-outline">
        ← Về lịch trình
    </a>
</div>

<!-- Nội dung in -->
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
        <br>
        <?php else: ?>
        Người lập: <strong><?= htmlspecialchars($user['full_name']) ?></strong>
        <br>
        <?php endif; ?>
        Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?> — <?= date('d/m/Y', strtotime($dateTo)) ?></strong>
        &nbsp;|&nbsp;
        Ngày in: <?= date('d/m/Y H:i') ?>
        &nbsp;|&nbsp;
        Người in: <?= htmlspecialchars($user['full_name']) ?>
    </div>

    <!-- Tóm tắt -->
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
            <div class="num" style="color:#fd7e14"><?= number_format($totalToll, 0, '.', ',') ?></div>
            <div class="lbl">Cầu đường (₫)</div>
        </div>
        <div class="summary-card">
            <div class="num" style="color:#6f42c1"><?= count($byVehicle) ?></div>
            <div class="lbl">Xe hoạt động</div>
        </div>
    </div>

    <!-- Bảng tóm tắt theo xe -->
    <?php if (count($byVehicle) > 1): ?>
    <div class="section-title">📊 Tóm tắt theo xe</div>
    <table style="margin-bottom:16px">
        <thead>
            <tr>
                <th>STT</th>
                <th>Biển số xe</th>
                <th>Tải trọng</th>
                <th>Số chuyến</th>
                <th>Tổng KM</th>
                <th>Cầu đường</th>
                <th>Đã duyệt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($byVehicle as $i => $bv): ?>
        <tr>
            <td class="text-center"><?= $i + 1 ?></td>
            <td class="text-center text-upper"><?= htmlspecialchars($bv['plate_number']) ?></td>
            <td class="text-center">
                <?= $bv['capacity'] ? $bv['capacity'] . ' tấn' : '—' ?>
            </td>
            <td class="text-center"><?= $bv['trip_count'] ?></td>
            <td class="text-right"><?= number_format($bv['total_km'], 0) ?> km</td>
            <td class="text-right">
                <?= $bv['total_toll'] ? number_format($bv['total_toll'], 0, '.', ',') . ' đ' : '—' ?>
            </td>
            <td class="text-center">
                <?= $bv['confirmed'] ?>/<?= $bv['trip_count'] ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="text-right">Tổng cộng:</td>
                <td class="text-center"><?= $totalTrips ?></td>
                <td class="text-right"><?= number_format($totalKm, 0) ?> km</td>
                <td class="text-right"><?= number_format($totalToll, 0, '.', ',') ?> đ</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- Bảng chi tiết chuyến -->
    <div class="section-title">📋 Chi tiết chuyến xe</div>
    <table>
        <thead>
            <tr>
                <th rowspan="2">STT</th>
                <th rowspan="2">Người lái</th>
                <th rowspan="2">Ngày</th>
                <th rowspan="2">Biển số xe<br>(Bắt buộc)</th>
                <th rowspan="2">Tải trọng</th>
                <?php if ($role !== 'customer'): ?>
                <th rowspan="2">Khách hàng</th>
                <?php endif; ?>
                <th rowspan="2">Điểm đi<br>(Bắt buộc)</th>
                <th rowspan="2">Điểm đến<br>(Bắt buộc)</th>
                <th rowspan="2">KM đi<br>(Bắt buộc)</th>
                <th rowspan="2">KM kết thúc<br>(Bắt buộc)</th>
                <th rowspan="2">Tổng KM<br>tuyến đường</th>
                <th rowspan="2">Vé Cầu Đường</th>
                <th rowspan="2">Ghi chú</th>
                <th rowspan="2">Trạng thái</th>
                <th rowspan="2">Khách hàng duyệt</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($trips)): ?>
        <tr>
            <td colspan="15" class="text-center" style="padding:20px;color:#999">
                Không có dữ liệu trong kỳ này
            </td>
        </tr>
        <?php endif; ?>
        <?php foreach ($trips as $i => $t):
            $rowClass = $t['status'] === 'confirmed' ? 'confirmed'
                      : ($t['status'] === 'rejected' ? 'rejected' : '');
        ?>
        <tr class="<?= $rowClass ?>">
            <td class="text-center"><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($t['driver_name']) ?></td>
            <td class="text-center" style="white-space:nowrap">
                <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                <?= $t['is_sunday'] ? ' (CN)' : '' ?>
            </td>
            <td class="text-center text-upper"><?= htmlspecialchars($t['plate_number']) ?></td>
            <td class="text-center">
                <?= $t['capacity'] ? $t['capacity'] . ' tấn' : '—' ?>
            </td>
            <?php if ($role !== 'customer'): ?>
            <td><?= htmlspecialchars($t['customer_short'] ?: $t['customer_name']) ?></td>
            <?php endif; ?>
            <td class="text-upper"><?= htmlspecialchars($t['pickup_location'] ?? '—') ?></td>
            <td class="text-upper"><?= htmlspecialchars($t['dropoff_location'] ?? '—') ?></td>
            <td class="text-right">
                <?= $t['odometer_start'] ? number_format($t['odometer_start'], 0) : '—' ?>
            </td>
            <td class="text-right">
                <?= $t['odometer_end'] ? number_format($t['odometer_end'], 0) : '—' ?>
            </td>
            <td class="text-right" style="font-weight:bold">
                <?= $t['total_km'] ? number_format($t['total_km'], 0) . ' km' : '—' ?>
            </td>
            <td class="text-right">
                <?= $t['toll_fee'] ? number_format($t['toll_fee'], 0, '.', ',') . ' đ' : '—' ?>
            </td>
            <td style="font-size:8.5pt">
                <?= htmlspecialchars($t['note'] ?? '') ?>
                <?php if ($t['rejection_reason']): ?>
                <br><span style="color:#dc3545">❌ <?= htmlspecialchars($t['rejection_reason']) ?></span>
                <?php endif; ?>
            </td>
            <td class="text-center">
                <?= $statusPrint[$t['status']] ?? $t['status'] ?>
            </td>
            <td style="font-size:8.5pt">
                <?php if ($t['confirmed_by_name']): ?>
                <?= htmlspecialchars($t['confirmed_by_name']) ?><br>
                <span style="color:#666;font-size:7.5pt">
                    <?= $t['confirmed_at']
                        ? date('d/m/Y H:i', strtotime($t['confirmed_at']))
                        : '' ?>
                </span>
                <?php else: ?>
                —
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="<?= ($role !== 'customer') ? 10 : 9 ?>"
                    class="text-right">
                    TỔNG CỘNG (<?= $totalTrips ?> chuyến):
                </td>
                <td class="text-right"><?= number_format($totalKm, 0) ?> km</td>
                <td class="text-right"><?= number_format($totalToll, 0, '.', ',') ?> đ</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>

    <!-- Ký tên -->
    <div class="signature-row">
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
function changePeriod(val) {
    document.getElementById('monthPicker').style.display = val === 'monthly' ? 'block' : 'none';
    document.getElementById('datePicker').style.display  = val === 'custom'  ? 'flex'  : 'none';
}

function reloadPage() {
    const month  = document.getElementById('monthInput').value;
    location.href = '?period=monthly&month=' + month;
}

function applyCustom() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    if (!from || !to) { alert('Vui lòng chọn ngày bắt đầu và kết thúc!'); return; }
    if (from > to)    { alert('Ngày bắt đầu phải trước ngày kết thúc!'); return; }
    location.href = '?period=custom&date_from=' + from + '&date_to=' + to;
}
</script>

</body>
</html>