<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('customer')) { header('Location: /dashboard.php'); exit; }

$pdo  = getDBConnection();
$user = currentUser();

$cuStmt = $pdo->prepare("
    SELECT cu.*, c.* FROM customer_users cu
    JOIN customers c ON cu.customer_id = c.id
    WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
");
$cuStmt->execute([$user['id']]);
$cu = $cuStmt->fetch();
if (!$cu) exit('Chưa liên kết khách hàng!');
$customerId = $cu['customer_id'];

// Loại kỳ: daily | weekly | monthly
$period    = $_GET['period']    ?? 'monthly';
$filterMonth = $_GET['month']   ?? date('Y-m');
$dateFrom    = $_GET['date_from'] ?? date('Y-m-01');
$dateTo      = $_GET['date_to']   ?? date('Y-m-d');

[$year, $month] = explode('-', $filterMonth);

if ($period === 'monthly') {
    $dateFrom = "$year-$month-01";
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
}

$trips = $pdo->prepare("
    SELECT t.*, v.plate_number, v.capacity,
           u.full_name AS driver_name,
           cu2.full_name AS confirmed_by_name
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d  ON t.driver_id  = d.id
    JOIN users u    ON d.user_id    = u.id
    LEFT JOIN users cu2 ON t.confirmed_by = cu2.id
    WHERE t.customer_id = ?
      AND t.trip_date BETWEEN ? AND ?
    ORDER BY t.trip_date ASC, v.plate_number
");
$trips->execute([$customerId, $dateFrom, $dateTo]);
$trips = $trips->fetchAll();

$totalKm   = array_sum(array_column($trips, 'total_km'));
$totalToll = array_sum(array_column($trips, 'toll_fee'));
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Bảng kê — <?= $cu['company_name'] ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family: 'Times New Roman', serif; font-size: 11pt; padding: 15mm; }
    .company-header { text-align: center; margin-bottom: 10px; }
    .company-name   { font-weight: bold; font-size: 12pt; }
    .report-title   { text-align: center; font-weight: bold; font-size: 14pt;
                      margin: 15px 0 5px; text-transform: uppercase; }
    .report-sub     { text-align: center; font-size: 10pt; margin-bottom: 15px; }
    table           { width: 100%; border-collapse: collapse; font-size: 9pt; }
    th, td          { border: 1px solid #333; padding: 4px 6px; }
    thead th        { background: #f0f0f0; text-align: center; font-weight: bold; }
    .text-center    { text-align: center; }
    .text-right     { text-align: right; }
    .text-upper     { text-transform: uppercase; font-weight: bold; }
    tfoot td        { font-weight: bold; background: #f8f8f8; }
    .signature-row  { display: flex; justify-content: space-between;
                      margin-top: 30px; text-align: center; }
    .sig-box        { width: 30%; }
    .no-print       { display: block; }
    @media print    {
        .no-print   { display: none !important; }
        body        { padding: 10mm; }
    }
</style>
</head>
<body>

<!-- Nút in (ẩn khi in) -->
<div class="no-print" style="margin-bottom:15px;display:flex;gap:10px;align-items:center">
    <button onclick="window.print()" style="padding:8px 20px;background:#0f3460;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px">
        🖨️ In bảng kê
    </button>
    <select onchange="location.href='?period='+this.value+'&month=<?= $filterMonth ?>'"
            style="padding:6px;border-radius:5px;border:1px solid #ccc">
        <option value="monthly" <?= $period==='monthly'?'selected':'' ?>>Hàng tháng</option>
        <option value="weekly"  <?= $period==='weekly' ?'selected':'' ?>>Hàng tuần</option>
        <option value="custom"  <?= $period==='custom' ?'selected':'' ?>>Tùy chỉnh</option>
    </select>
    <?php if ($period === 'monthly'): ?>
    <input type="month" value="<?= $filterMonth ?>"
           onchange="location.href='?period=monthly&month='+this.value"
           style="padding:6px;border-radius:5px;border:1px solid #ccc">
    <?php else: ?>
    <input type="date" value="<?= $dateFrom ?>"
           onchange="location.href='?period=<?= $period ?>&date_from='+this.value+'&date_to=<?= $dateTo ?>'"
           style="padding:6px">
    <span>—</span>
    <input type="date" value="<?= $dateTo ?>"
           onchange="location.href='?period=<?= $period ?>&date_from=<?= $dateFrom ?>&date_to='+this.value"
           style="padding:6px">
    <?php endif; ?>
    <a href="trips.php" style="color:#666;text-decoration:none">← Quay lại</a>
</div>

<!-- Header -->
<div class="company-header">
    <div class="company-name">CÔNG TY TNHH DNA EXPRESS VIỆT NAM</div>
    <div style="font-size:10pt">
        Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam
    </div>
    <div style="font-size:10pt">MST: 0107514537</div>
</div>

<div class="report-title">Bảng theo dõi tình hình sử dụng xe</div>
<div class="report-sub">
    Khách hàng: <strong><?= htmlspecialchars($cu['company_name']) ?></strong>
    <?php if ($cu['tax_code']): ?>
    — MST: <?= $cu['tax_code'] ?>
    <?php endif; ?>
    <br>
    Kỳ: <?= date('d/m/Y', strtotime($dateFrom)) ?> — <?= date('d/m/Y', strtotime($dateTo)) ?>
    | Ngày in: <?= date('d/m/Y H:i') ?>
    | Người in: <?= htmlspecialchars($user['full_name']) ?>
</div>

<!-- Bảng -->
<table>
    <thead>
        <tr>
            <th rowspan="2">STT</th>
            <th rowspan="2">Người lái</th>
            <th rowspan="2">Ngày</th>
            <th rowspan="2">Biển số xe<br>(Bắt buộc)</th>
            <th rowspan="2">Tải trọng</th>
            <th rowspan="2">Khách hàng<br>(Bắt buộc)</th>
            <th rowspan="2">Điểm đi<br>(Bắt buộc)</th>
            <th rowspan="2">Điểm đến 1<br>(Bắt buộc)</th>
            <th rowspan="2">Số KM<br>điểm đi<br>(Bắt buộc)</th>
            <th rowspan="2">Số KM<br>kết thúc<br>(Bắt buộc)</th>
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
    <?php $statusPrint = [
        'draft'=>'Draft','submitted'=>'Đã gửi','completed'=>'Hoàn thành',
        'confirmed'=>'Đã duyệt','rejected'=>'Từ chối'
    ];
    foreach ($trips as $i => $t): ?>
    <tr>
        <td class="text-center"><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($t['driver_name']) ?></td>
        <td class="text-center text-nowrap">
            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
            <?= $t['is_sunday'] ? ' (CN)' : '' ?>
        </td>
        <td class="text-center text-upper"><?= $t['plate_number'] ?></td>
        <td class="text-center"><?= $t['capacity'] ? $t['capacity'].' tấn' : '—' ?></td>
        <td><?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></td>
        <td class="text-upper"><?= htmlspecialchars($t['pickup_location'] ?? '—') ?></td>
        <td class="text-upper"><?= htmlspecialchars($t['dropoff_location'] ?? '—') ?></td>
        <td class="text-right">
            <?= $t['odometer_start'] ? number_format($t['odometer_start'],0) : '—' ?>
        </td>
        <td class="text-right">
            <?= $t['odometer_end'] ? number_format($t['odometer_end'],0) : '—' ?>
        </td>
        <td class="text-right" style="font-weight:bold">
            <?= $t['total_km'] ? number_format($t['total_km'],0).' km' : '—' ?>
        </td>
        <td class="text-right">
            <?= $t['toll_fee'] ? number_format($t['toll_fee'],0,'.', ',').' đ' : '—' ?>
        </td>
        <td><?= htmlspecialchars($t['note'] ?? '') ?></td>
        <td class="text-center">
            <?= $statusPrint[$t['status']] ?? $t['status'] ?>
        </td>
        <td>
            <?php if ($t['confirmed_by_name']): ?>
            <?= htmlspecialchars($t['confirmed_by_name']) ?><br>
            <span style="font-size:8pt;color:#666">
                <?= $t['confirmed_at'] ? date('d/m/Y H:i', strtotime($t['confirmed_at'])) : '' ?>
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
            <td colspan="10" class="text-right">
                TỔNG CỘNG (<?= count($trips) ?> chuyến):
            </td>
            <td class="text-right"><?= number_format($totalKm,0) ?> km</td>
            <td class="text-right"><?= number_format($totalToll,0,'.', ',') ?> đ</td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>

<!-- Ký tên -->
<div class="signature-row no-print" style="display:none">
</div>
<div style="display:flex;justify-content:space-between;margin-top:30px;text-align:center">
    <div style="width:30%">
        <div style="font-weight:bold">Người lập bảng</div>
        <div style="font-size:9pt;color:#666;margin-bottom:50px">(Ký, ghi rõ họ tên)</div>
        <div><?= htmlspecialchars($user['full_name']) ?></div>
    </div>
    <div style="width:30%">
        <div style="font-weight:bold">Đại diện khách hàng</div>
        <div style="font-size:9pt;color:#666;margin-bottom:50px">(Ký, đóng dấu)</div>
        <div><?= htmlspecialchars($cu['company_name']) ?></div>
    </div>
    <div style="width:30%">
        <div style="font-weight:bold">DNA EXPRESS VIỆT NAM</div>
        <div style="font-size:9pt;color:#666;margin-bottom:50px">(Ký, đóng dấu)</div>
        <div>Giám đốc</div>
    </div>
</div>

</body>
</html>