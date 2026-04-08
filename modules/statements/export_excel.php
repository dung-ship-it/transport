<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();

$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$role = strtolower($roleStmt->fetchColumn() ?? '');

$driverRow  = null;
$customerId = null;
$cu         = null;

if ($role === 'driver') {
    $dStmt = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
    $dStmt->execute([$user['id']]);
    $driverRow = $dStmt->fetch();
}
if ($role === 'customer') {
    $cuStmt = $pdo->prepare("SELECT cu.*, c.* FROM customer_users cu JOIN customers c ON cu.customer_id = c.id WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1");
    $cuStmt->execute([$user['id']]);
    $cu = $cuStmt->fetch();
    if ($cu) $customerId = $cu['customer_id'];
}

$period       = $_GET['period']    ?? 'monthly';
$filterMonth  = $_GET['month']     ?? date('Y-m');
$dateFrom     = $_GET['date_from'] ?? date('Y-m-01');
$dateTo       = $_GET['date_to']   ?? date('Y-m-d');
$filterCustId = (int)($_GET['filter_customer'] ?? 0);
$filterPlate  = trim($_GET['filter_plate'] ?? '');

[$year, $month] = explode('-', $filterMonth);
if ($period === 'monthly') {
    $dateFrom = "$year-$month-01";
    $dateTo   = date('Y-m-t', strtotime($dateFrom));
}

$where  = ['t.trip_date BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];

if ($role === 'driver' && $driverRow) {
    $where[]  = 't.driver_id = ?';
    $params[] = $driverRow['id'];
} elseif ($role === 'customer' && $customerId) {
    $where[]  = 't.customer_id = ?';
    $params[] = $customerId;
}
if ($filterCustId && !in_array($role, ['driver','customer'])) {
    $where[]  = 't.customer_id = ?';
    $params[] = $filterCustId;
}
if ($filterPlate) {
    $where[]  = 'v.plate_number = ?';
    $params[] = $filterPlate;
}

$whereStr = implode(' AND ', $where);

$tripsStmt = $pdo->prepare("
    SELECT t.*,
           v.plate_number, v.capacity,
           u.full_name       AS driver_name,
           c.id              AS customer_id,
           c.company_name    AS customer_name,
           c.short_name      AS customer_short,
           c.tax_code        AS customer_tax,
           cu_user.full_name AS confirmed_by_name
    FROM trips t
    JOIN vehicles v       ON t.vehicle_id  = v.id
    JOIN drivers d        ON t.driver_id   = d.id
    JOIN users u          ON d.user_id     = u.id
    JOIN customers c      ON t.customer_id = c.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    WHERE $whereStr
    ORDER BY c.company_name ASC, v.plate_number ASC, t.trip_date ASC
");
$tripsStmt->execute($params);
$trips = $tripsStmt->fetchAll();

$totalKm    = array_sum(array_column($trips, 'total_km'));
$totalTrips = count($trips);

$grouped = [];
foreach ($trips as $t) {
    $cid   = $t['customer_id'];
    $plate = $t['plate_number'];
    if (!isset($grouped[$cid])) {
        $grouped[$cid] = [
            'customer_name'  => $t['customer_name'],
            'customer_short' => $t['customer_short'],
            'customer_tax'   => $t['customer_tax'],
            'vehicles'       => [],
            'total_km'       => 0,
            'trip_count'     => 0,
            'confirmed'      => 0,
        ];
    }
    if (!isset($grouped[$cid]['vehicles'][$plate])) {
        $grouped[$cid]['vehicles'][$plate] = [
            'plate_number' => $plate,
            'capacity'     => $t['capacity'],
            'trip_count'   => 0,
            'total_km'     => 0,
            'confirmed'    => 0,
            'trips'        => [],
        ];
    }
    $grouped[$cid]['vehicles'][$plate]['trips'][]    = $t;
    $grouped[$cid]['vehicles'][$plate]['trip_count']++;
    $grouped[$cid]['vehicles'][$plate]['total_km']  += (float)$t['total_km'];
    if ($t['status'] === 'confirmed') {
        $grouped[$cid]['vehicles'][$plate]['confirmed']++;
        $grouped[$cid]['confirmed']++;
    }
    $grouped[$cid]['trip_count']++;
    $grouped[$cid]['total_km'] += (float)$t['total_km'];
}

$statusPrint = [
    'draft'       => 'Draft',
    'submitted'   => 'Đã gửi',
    'completed'   => 'Hoàn thành',
    'confirmed'   => 'Đã duyệt',
    'rejected'    => 'Từ chối',
    'in_progress' => 'Đang chạy',
    'scheduled'   => 'Chờ',
];

$showKH    = ($role !== 'customer');
$colDetail = $showKH ? 11 : 10;

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="bang_ke_' . date('Y-m-d') . '.xls"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="UTF-8">
<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets>
<x:ExcelWorksheet><x:Name>Bang_ke</x:Name><x:WorksheetOptions><x:Selected/></x:WorksheetOptions></x:ExcelWorksheet>
</x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
</head>
<body>
<table border="1" cellspacing="0" cellpadding="3"
       style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt">

  <!-- ── Tiêu đề ── -->
  <tr>
    <td colspan="7" style="font-weight:bold;font-size:13pt;text-align:center;background:#0f3460;color:white">
      CÔNG TY TNHH DNA EXPRESS VIỆT NAM
    </td>
  </tr>
  <tr>
    <td colspan="7" style="text-align:center;font-size:11pt;font-weight:bold">
      BẢNG KÊ TÌNH HÌNH SỬ DỤNG XE
    </td>
  </tr>
  <tr>
    <td colspan="7" style="text-align:center;font-size:9pt;color:#555">
      Kỳ: <?= date('d/m/Y', strtotime($dateFrom)) ?> — <?= date('d/m/Y', strtotime($dateTo)) ?>
      &nbsp;|&nbsp; Ngày xuất: <?= date('d/m/Y H:i') ?>
      &nbsp;|&nbsp; Người xuất: <?= htmlspecialchars($user['full_name']) ?>
    </td>
  </tr>
  <tr><td colspan="7" style="border:none">&nbsp;</td></tr>

  <!-- ── Tóm tắt header ── -->
  <tr style="background-color:#f0f0f0;font-weight:bold;text-align:center">
    <td>STT</td><td>Khách hàng</td><td>Biển số xe</td>
    <td>Tải trọng</td><td>Số chuyến</td><td>Tổng KM</td><td>Đã duyệt</td>
  </tr>

<?php
$stt = 0;
foreach ($grouped as $cid => $cGroup):
    foreach ($cGroup['vehicles'] as $plate => $veh):
        $stt++;
?>
  <tr>
    <td style="text-align:center"><?= $stt ?></td>
    <td style="font-weight:bold">
      <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>
      <?= $cGroup['customer_tax'] ? ' (MST: '.htmlspecialchars($cGroup['customer_tax']).')' : '' ?>
    </td>
    <td style="text-align:center;font-weight:bold"><?= htmlspecialchars($plate) ?></td>
    <td style="text-align:center"><?= $veh['capacity'] ? $veh['capacity'].' tấn' : '—' ?></td>
    <td style="text-align:center"><?= $veh['trip_count'] ?></td>
    <td style="text-align:right"><?= number_format($veh['total_km'], 0) ?> km</td>
    <td style="text-align:center"><?= $veh['confirmed'] ?>/<?= $veh['trip_count'] ?></td>
  </tr>
<?php endforeach; ?>
  <tr style="background-color:#e8f4fd;font-weight:bold">
    <td colspan="4" style="text-align:right;font-style:italic">
      Tổng <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>
      (<?= count($cGroup['vehicles']) ?> xe):
    </td>
    <td style="text-align:center"><?= $cGroup['trip_count'] ?></td>
    <td style="text-align:right"><?= number_format($cGroup['total_km'], 0) ?> km</td>
    <td style="text-align:center"><?= $cGroup['confirmed'] ?>/<?= $cGroup['trip_count'] ?></td>
  </tr>
<?php endforeach; ?>
  <tr style="background-color:#0f3460;color:white;font-weight:bold">
    <td colspan="4" style="text-align:right">TỔNG CỘNG:</td>
    <td style="text-align:center"><?= $totalTrips ?></td>
    <td style="text-align:right"><?= number_format($totalKm, 0) ?> km</td>
    <td></td>
  </tr>

  <!-- Spacer -->
  <tr><td colspan="7" style="border:none">&nbsp;</td></tr>
  <tr><td colspan="7" style="border:none">&nbsp;</td></tr>

  <!-- ── Chi tiết ── -->
  <tr>
    <td colspan="7" style="font-weight:bold;font-size:12pt;background:#f8f9fa">
      CHI TIẾT CHUYẾN XE THEO KHÁCH HÀNG
    </td>
  </tr>
  <tr><td colspan="7" style="border:none">&nbsp;</td></tr>

</table>

<?php foreach ($grouped as $cid => $cGroup): ?>
<table border="1" cellspacing="0" cellpadding="3"
       style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt;width:100%;margin-bottom:4pt">

  <!-- Customer header -->
  <tr style="background-color:#0f3460;color:white;font-weight:bold">
    <td colspan="<?= $colDetail ?>" style="font-size:11pt;padding:5px 8px">
      <?= htmlspecialchars($cGroup['customer_name']) ?>
      <?= $cGroup['customer_tax'] ? ' — MST: '.htmlspecialchars($cGroup['customer_tax']) : '' ?>
      &nbsp;&nbsp;
      <span style="font-weight:normal;font-size:9pt">
        | <?= count($cGroup['vehicles']) ?> xe
        · <?= $cGroup['trip_count'] ?> chuyến
        · <?= number_format($cGroup['total_km'], 0) ?> km
      </span>
    </td>
  </tr>

  <?php foreach ($cGroup['vehicles'] as $plate => $veh): ?>
  <!-- Vehicle title -->
  <tr style="background-color:#e8f4fd;font-weight:bold">
    <td colspan="<?= $colDetail ?>" style="padding:4px 8px;border-left:4px solid #0d6efd">
      Xe: <?= htmlspecialchars($plate) ?>
      <?= $veh['capacity'] ? ' ('.$veh['capacity'].' tấn)' : '' ?>
      <span style="font-weight:normal;font-size:9pt">
        &nbsp;|&nbsp; <?= $veh['trip_count'] ?> chuyến
        · <?= number_format($veh['total_km'], 0) ?> km
        · Đã duyệt: <?= $veh['confirmed'] ?>/<?= $veh['trip_count'] ?>
      </span>
    </td>
  </tr>

  <!-- Column headers -->
  <tr style="background-color:#f0f0f0;font-weight:bold;text-align:center">
    <td>STT</td>
    <td>Người lái</td>
    <td>Ngày</td>
    <?php if ($showKH): ?><td>Khách hàng</td><?php endif; ?>
    <td>Điểm đi</td>
    <td>Điểm đến</td>
    <td>KM đi</td>
    <td>KM về</td>
    <td>Tổng KM</td>
    <td>Ghi chú</td>
    <td>Trạng thái</td>
  </tr>

  <?php foreach ($veh['trips'] as $ti => $t): ?>
  <tr>
    <td style="text-align:center"><?= $ti + 1 ?></td>
    <td><?= htmlspecialchars($t['driver_name']) ?></td>
    <td style="text-align:center"><?= date('d/m/Y', strtotime($t['trip_date'])) ?></td>
    <?php if ($showKH): ?>
    <td><?= htmlspecialchars($t['customer_short'] ?: $t['customer_name']) ?></td>
    <?php endif; ?>
    <td style="text-transform:uppercase"><?= htmlspecialchars($t['pickup_location'] ?? '—') ?></td>
    <td style="text-transform:uppercase"><?= htmlspecialchars($t['dropoff_location'] ?? '—') ?></td>
    <td style="text-align:right"><?= $t['odometer_start'] ? number_format($t['odometer_start'], 0) : '—' ?></td>
    <td style="text-align:right"><?= $t['odometer_end']   ? number_format($t['odometer_end'],   0) : '—' ?></td>
    <td style="text-align:right;font-weight:bold"><?= $t['total_km'] ? number_format($t['total_km'], 0).' km' : '—' ?></td>
    <td style="font-size:9pt"><?= htmlspecialchars($t['note'] ?? '') ?></td>
    <td style="text-align:center"><?= $statusPrint[$t['status']] ?? $t['status'] ?></td>
  </tr>
  <?php endforeach; ?>

  <!-- Vehicle subtotal -->
  <tr style="background-color:#f5f5f5;font-weight:bold">
    <td colspan="<?= $showKH ? 8 : 7 ?>" style="text-align:right">
      Tổng xe <?= htmlspecialchars($plate) ?> (<?= $veh['trip_count'] ?> chuyến):
    </td>
    <td style="text-align:right"><?= number_format($veh['total_km'], 0) ?> km</td>
    <td colspan="2"></td>
  </tr>

  <!-- Blank row between vehicles -->
  <tr><td colspan="<?= $colDetail ?>" style="background:white;border-left:none;border-right:none">&nbsp;</td></tr>
  <?php endforeach; ?>

  <!-- Customer subtotal -->
  <tr style="background-color:#e8f4fd;font-weight:bold">
    <td colspan="<?= $colDetail - 2 ?>" style="text-align:right">
      Tổng <?= htmlspecialchars($cGroup['customer_short'] ?: $cGroup['customer_name']) ?>:
    </td>
    <td colspan="2" style="text-align:right">
      <?= $cGroup['trip_count'] ?> chuyến · <?= number_format($cGroup['total_km'], 0) ?> km
    </td>
  </tr>
</table>
<br>
<?php endforeach; ?>

<!-- Grand total -->
<table border="1" cellspacing="0" cellpadding="3"
       style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt;width:100%">
  <tr style="background-color:#0f3460;color:white;font-weight:bold">
    <td colspan="<?= $colDetail - 2 ?>" style="text-align:right;padding:6px">TỔNG CỘNG TẤT CẢ:</td>
    <td colspan="2" style="text-align:right;padding:6px">
      <?= $totalTrips ?> chuyến · <?= number_format($totalKm, 0) ?> km
    </td>
  </tr>
</table>

</body>
</html>
