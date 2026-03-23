<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo        = getDBConnection();
$user       = currentUser();
$customerId = (int)($_GET['customer_id'] ?? 0);
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01');
$dateTo     = $_GET['date_to']   ?? date('Y-m-d');

if (!$customerId) exit('Thiếu customer_id');

// Load customer
$customer = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$customer->execute([$customerId]);
$customer = $customer->fetch();
if (!$customer) exit('Không tìm thấy khách hàng');

// Load trips + price rules
$trips = $pdo->prepare("
    SELECT
        t.*,
        v.plate_number, v.capacity,
        u.full_name    AS driver_name,
        cu_user.full_name AS confirmed_by_name,
        pr.pricing_mode,
        pr.combo_monthly_price, pr.combo_km_limit,
        pr.over_km_price, pr.standard_price_per_km,
        pr.toll_included, pr.sunday_surcharge,
        pr.holiday_surcharge,
        pb.name AS pb_name
    FROM trips t
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    LEFT JOIN price_books pb ON pb.customer_id = t.customer_id
        AND pb.is_active = TRUE
        AND pb.valid_from <= t.trip_date
        AND (pb.valid_to IS NULL OR pb.valid_to >= t.trip_date)
    LEFT JOIN price_rules pr ON pr.price_book_id = pb.id
        AND pr.vehicle_id = t.vehicle_id
    WHERE t.customer_id = ?
      AND t.trip_date BETWEEN ? AND ?
      AND t.status IN ('completed','confirmed')
    ORDER BY t.trip_date ASC, v.plate_number
");
$trips->execute([$customerId, $dateFrom, $dateTo]);
$trips = $trips->fetchAll();

// Nhóm theo xe + tính tiền
$byVehicle = [];
foreach ($trips as $t) {
    $plate = $t['plate_number'];
    if (!isset($byVehicle[$plate])) {
        $byVehicle[$plate] = [
            'plate_number'        => $plate,
            'capacity'            => $t['capacity'],
            'pricing_mode'        => $t['pricing_mode'],
            'combo_monthly_price' => $t['combo_monthly_price'],
            'combo_km_limit'      => $t['combo_km_limit'],
            'over_km_price'       => $t['over_km_price'],
            'standard_price_per_km' => $t['standard_price_per_km'],
            'toll_included'       => $t['toll_included'],
            'sunday_surcharge'    => $t['sunday_surcharge'] ?? 0,
            'has_rule'            => $t['pricing_mode'] ? true : false,
            'pb_name'             => $t['pb_name'],
            'trip_count'          => 0,
            'total_km'            => 0,
            'total_toll'          => 0,
            'sunday_km'           => 0,
            'sunday_trips'        => 0,
            'trips'               => [],
        ];
    }
    $byVehicle[$plate]['trip_count']++;
    $byVehicle[$plate]['total_km']   += (float)$t['total_km'];
    $byVehicle[$plate]['total_toll'] += (float)$t['toll_fee'];
    if ($t['is_sunday']) {
        $byVehicle[$plate]['sunday_km']    += (float)$t['total_km'];
        $byVehicle[$plate]['sunday_trips'] += 1;
    }
    $byVehicle[$plate]['trips'][] = $t;
}

// Tính tiền từng xe
$grandKm = $grandToll = $grandAmount = $grandTrips = 0;
foreach ($byVehicle as $plate => &$veh) {
    if (!$veh['has_rule']) {
        $veh['amount_base'] = $veh['amount_toll'] = $veh['amount_surcharge'] = $veh['amount_total'] = 0;
        $veh['over_km'] = $veh['over_amount'] = 0;
        continue;
    }
    if ($veh['pricing_mode'] === 'combo') {
        $base    = (float)($veh['combo_monthly_price'] ?? 0);
        $limit   = (float)($veh['combo_km_limit'] ?? 0);
        $overKm  = max(0, $veh['total_km'] - $limit);
        $overAmt = $overKm * (float)($veh['over_km_price'] ?? 0);
        $veh['over_km']     = $overKm;
        $veh['over_amount'] = $overAmt;
        $veh['amount_base'] = $base + $overAmt;
    } else {
        $veh['amount_base'] = $veh['total_km'] * (float)($veh['standard_price_per_km'] ?? 0);
        $veh['over_km']     = 0;
        $veh['over_amount'] = 0;
    }
    $veh['amount_toll'] = $veh['toll_included'] ? 0 : $veh['total_toll'];
    $sunRate = (float)($veh['sunday_surcharge'] ?? 0);
    if ($sunRate > 0 && $veh['sunday_km'] > 0) {
        $avgRate = $veh['total_km'] > 0 ? $veh['amount_base'] / $veh['total_km'] : 0;
        $veh['amount_surcharge'] = $veh['sunday_km'] * $avgRate * ($sunRate / 100);
    } else {
        $veh['amount_surcharge'] = 0;
    }
    $veh['amount_total'] = $veh['amount_base'] + $veh['amount_toll'] + $veh['amount_surcharge'];
    $grandKm     += $veh['total_km'];
    $grandToll   += $veh['total_toll'];
    $grandAmount += $veh['amount_total'];
    $grandTrips  += $veh['trip_count'];
}
unset($veh);

$pbName = reset($byVehicle)['pb_name'] ?? 'Chưa có bảng giá';
$statusPrint = [
    'completed' => 'Hoàn thành',
    'confirmed' => 'Đã duyệt',
    'rejected'  => 'Từ chối',
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Bảng kê công nợ — <?= htmlspecialchars($customer['company_name']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Times New Roman', serif; font-size:11pt; padding:12mm; }
.no-print { margin-bottom:12px; display:flex; gap:8px; align-items:center; }
.no-print button, .no-print a {
    padding:7px 16px; border-radius:5px; border:none; cursor:pointer;
    font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:5px;
}
.btn-print { background:#0f3460; color:#fff; }
.btn-back  { background:#6c757d; color:#fff; }
.company-header { text-align:center; margin-bottom:8px; }
.company-name   { font-weight:bold; font-size:13pt; text-transform:uppercase; }
.report-title   { text-align:center; font-weight:bold; font-size:14pt;
                  margin:12px 0 4px; text-transform:uppercase; }
.report-meta    { text-align:center; font-size:9.5pt; color:#555; margin-bottom:14px; }
.summary-box {
    display:flex; gap:15px; margin-bottom:14px;
    padding:8px 12px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px;
}
.summary-item { text-align:center; flex:1; }
.summary-item .val { font-weight:bold; font-size:13pt; color:#0d6efd; }
.summary-item .lbl { font-size:8.5pt; color:#666; }
.section-title {
    font-weight:bold; font-size:11pt; margin:14px 0 5px;
    padding-bottom:3px; border-bottom:2px solid #333;
}
table { width:100%; border-collapse:collapse; font-size:9pt; margin-bottom:12px; }
th, td { border:1px solid #999; padding:3px 5px; }
thead th { background:#e8f4fd; text-align:center; font-weight:bold; }
.text-center { text-align:center; }
.text-right  { text-align:right; }
.text-upper  { text-transform:uppercase; font-weight:bold; }
tfoot td     { font-weight:bold; background:#f0f0f0; }
tr.confirmed { background:#f0fff4; }
tr.completed { background:#fff; }
.total-row td { font-weight:bold; background:#e8f4fd; font-size:10pt; }
.grand-total  { font-size:12pt; font-weight:bold; color:#0d6efd; }
.signature-row {
    display:flex; justify-content:space-between;
    margin-top:30px; text-align:center;
}
.sig-box { width:30%; }
.sig-title { font-weight:bold; }
.sig-sub   { font-size:8.5pt; color:#666; margin-bottom:45px; }
.sig-name  { font-weight:bold; }
@media print {
    .no-print { display:none !important; }
    body { padding:8mm; }
    tr.confirmed { background:#f0fff4 !important; -webkit-print-color-adjust:exact; }
    thead th     { background:#e8f4fd !important; -webkit-print-color-adjust:exact; }
    tfoot td, .total-row td { background:#f0f0f0 !important; -webkit-print-color-adjust:exact; }
    .summary-box { background:#f8f9fa !important; -webkit-print-color-adjust:exact; }
}
</style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨️ In bảng kê</button>
    <a href="index.php?date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>&customer_id=<?= $customerId ?>"
       class="btn-back">← Quay lại</a>
</div>

<!-- Header -->
<div class="company-header">
    <div class="company-name">CÔNG TY TNHH DNA EXPRESS VIỆT NAM</div>
    <div style="font-size:9.5pt;color:#444;margin-top:2px">
        Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam
    </div>
    <div style="font-size:9.5pt;color:#444">MST: 0107514537</div>
</div>

<div class="report-title">Bảng kê dịch vụ vận chuyển</div>
<div class="report-meta">
    Khách hàng: <strong><?= htmlspecialchars($customer['company_name']) ?></strong>
    <?php if ($customer['tax_code']): ?>
    — MST: <strong><?= htmlspecialchars($customer['tax_code']) ?></strong>
    <?php endif; ?>
    <br>
    Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?></strong>
    — <strong><?= date('d/m/Y', strtotime($dateTo)) ?></strong>
    &nbsp;|&nbsp;
    Bảng giá: <strong><?= htmlspecialchars($pbName) ?></strong>
    <br>
    Ngày lập: <?= date('d/m/Y H:i') ?>
    &nbsp;|&nbsp;
    Người lập: <?= htmlspecialchars($user['full_name']) ?>
</div>

<!-- Tóm tắt -->
<div class="summary-box">
    <div class="summary-item">
        <div class="val"><?= $grandTrips ?></div>
        <div class="lbl">Tổng chuyến</div>
    </div>
    <div class="summary-item">
        <div class="val"><?= count($byVehicle) ?></div>
        <div class="lbl">Số xe</div>
    </div>
    <div class="summary-item">
        <div class="val"><?= number_format($grandKm, 0) ?> km</div>
        <div class="lbl">Tổng KM</div>
    </div>
    <div class="summary-item">
        <div class="val" style="color:#fd7e14">
            <?= number_format($grandToll, 0, '.', ',') ?> đ
        </div>
        <div class="lbl">Cầu đường</div>
    </div>
    <div class="summary-item">
        <div class="val" style="color:#198754;font-size:14pt">
            <?= number_format($grandAmount, 0, '.', ',') ?> đ
        </div>
        <div class="lbl"><strong>TỔNG THANH TOÁN</strong></div>
    </div>
</div>

<!-- Bảng tổng hợp theo xe -->
<div class="section-title">I. Tổng hợp theo xe</div>
<table>
    <thead>
        <tr>
            <th>STT</th>
            <th>Biển số xe</th>
            <th>Tải trọng</th>
            <th>Loại giá</th>
            <th>Đơn giá</th>
            <th>KM COMBO<br>/tháng</th>
            <th>Số chuyến</th>
            <th>Tổng KM</th>
            <th>Quá KM</th>
            <th>Tiền cơ bản</th>
            <th>Tiền quá KM</th>
            <th>Cầu đường</th>
            <th>Phụ phí CN</th>
            <th>THÀNH TIỀN</th>
        </tr>
    </thead>
    <tbody>
    <?php $i=0; foreach ($byVehicle as $plate => $veh): $i++; ?>
    <tr>
        <td class="text-center"><?= $i ?></td>
        <td class="text-center text-upper"><?= htmlspecialchars($plate) ?></td>
        <td class="text-center">
            <?= $veh['capacity'] ? $veh['capacity'].' tấn' : '—' ?>
        </td>
        <td class="text-center">
            <?= $veh['pricing_mode'] === 'combo' ? 'COMBO' : ($veh['pricing_mode'] === 'standard' ? 'THƯỜNG' : '—') ?>
        </td>
        <td class="text-right">
            <?php if ($veh['pricing_mode'] === 'combo'): ?>
            <?= number_format($veh['combo_monthly_price'] ?? 0, 0, '.', ',') ?> đ/tháng
            <?php elseif ($veh['pricing_mode'] === 'standard'): ?>
            <?= number_format($veh['standard_price_per_km'] ?? 0, 0, '.', ',') ?> đ/km
            <?php else: ?>—<?php endif; ?>
        </td>
        <td class="text-center">
            <?= $veh['combo_km_limit'] ? number_format($veh['combo_km_limit'],0).' km' : '—' ?>
        </td>
        <td class="text-center"><?= $veh['trip_count'] ?></td>
        <td class="text-right"><?= number_format($veh['total_km'], 0) ?> km</td>
        <td class="text-right">
            <?= ($veh['over_km'] ?? 0) > 0 ? number_format($veh['over_km'],0).' km' : '—' ?>
        </td>
        <td class="text-right">
            <?= $veh['has_rule']
                ? number_format($veh['pricing_mode']==='combo'
                    ? ($veh['combo_monthly_price'] ?? 0)
                    : $veh['total_km'] * ($veh['standard_price_per_km'] ?? 0)
                  , 0, '.', ',').' đ'
                : '—' ?>
        </td>
        <td class="text-right">
            <?= ($veh['over_amount'] ?? 0) > 0
                ? number_format($veh['over_amount'], 0, '.', ',').' đ'
                : '—' ?>
        </td>
        <td class="text-right">
            <?= $veh['toll_included']
                ? 'Đã gộp'
                : ($veh['amount_toll'] > 0
                    ? number_format($veh['amount_toll'], 0, '.', ',').' đ'
                    : '—') ?>
        </td>
        <td class="text-right">
            <?= $veh['amount_surcharge'] > 0
                ? number_format($veh['amount_surcharge'], 0, '.', ',').' đ'
                : '—' ?>
        </td>
        <td class="text-right" style="font-weight:bold">
            <?= $veh['has_rule']
                ? number_format($veh['amount_total'], 0, '.', ',').' đ'
                : '—' ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="6" class="text-right">TỔNG CỘNG (<?= count($byVehicle) ?> xe):</td>
            <td class="text-center"><?= $grandTrips ?></td>
            <td class="text-right"><?= number_format($grandKm, 0) ?> km</td>
            <td></td>
            <td colspan="3" class="text-right">
                Cầu đường: <?= number_format($grandToll, 0, '.', ',') ?> đ
            </td>
            <td></td>
            <td class="text-right grand-total">
                <?= number_format($grandAmount, 0, '.', ',') ?> đ
            </td>
        </tr>
    </tfoot>
</table>

<!-- Bảng chi tiết chuyến -->
<div class="section-title">II. Chi tiết chuyến xe</div>
<table>
    <thead>
        <tr>
            <th>STT</th>
            <th>Ngày</th>
            <th>Mã chuyến</th>
            <th>Biển số xe</th>
            <th>Tải trọng</th>
            <th>Người lái</th>
            <th>Điểm đi (Bắt buộc)</th>
            <th>Điểm đến 1 (Bắt buộc)</th>
            <th>KM đi (Bắt buộc)</th>
            <th>KM kết thúc (Bắt buộc)</th>
            <th>Tổng KM</th>
            <th>Vé cầu đường</th>
            <th>Ghi chú</th>
            <th>Trạng thái</th>
            <th>Khách hàng duyệt</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $i = 0;
    foreach ($byVehicle as $plate => $veh):
        foreach ($veh['trips'] as $t):
            $i++;
            $rowCls = $t['status'] === 'confirmed' ? 'confirmed' : 'completed';
    ?>
    <tr class="<?= $rowCls ?>">
        <td class="text-center"><?= $i ?></td>
        <td class="text-center" style="white-space:nowrap">
            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
            <?= $t['is_sunday'] ? ' (CN)' : '' ?>
        </td>
        <td class="text-center"><?= htmlspecialchars($t['trip_code']) ?></td>
        <td class="text-center text-upper"><?= htmlspecialchars($t['plate_number']) ?></td>
        <td class="text-center">
            <?= $t['capacity'] ? $t['capacity'].' tấn' : '—' ?>
        </td>
        <td><?= htmlspecialchars($t['driver_name']) ?></td>
        <td class="text-upper"><?= htmlspecialchars($t['pickup_location'] ?? '—') ?></td>
        <td class="text-upper"><?= htmlspecialchars($t['dropoff_location'] ?? '—') ?></td>
        <td class="text-right">
            <?= $t['odometer_start'] ? number_format($t['odometer_start'], 0) : '—' ?>
        </td>
        <td class="text-right">
            <?= $t['odometer_end'] ? number_format($t['odometer_end'], 0) : '—' ?>
        </td>
        <td class="text-right" style="font-weight:bold">
            <?= $t['total_km'] ? number_format($t['total_km'], 0).' km' : '—' ?>
        </td>
        <td class="text-right">
            <?= $t['toll_fee'] ? number_format($t['toll_fee'], 0, '.', ',').' đ' : '—' ?>
        </td>
        <td style="font-size:8pt"><?= htmlspecialchars($t['note'] ?? '') ?></td>
        <td class="text-center"><?= $statusPrint[$t['status']] ?? $t['status'] ?></td>
        <td style="font-size:8pt">
            <?php if ($t['confirmed_by_name']): ?>
            <?= htmlspecialchars($t['confirmed_by_name']) ?>
            <?= $t['confirmed_at'] ? '<br><span style="font-size:7pt;color:#666">'.date('d/m/Y H:i',strtotime($t['confirmed_at'])).'</span>' : '' ?>
            <?php else: ?>—<?php endif; ?>
        </td>
    </tr>
    <?php endforeach; endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="10" class="text-right">TỔNG CỘNG (<?= $grandTrips ?> chuyến):</td>
            <td class="text-right"><?= number_format($grandKm, 0) ?> km</td>
            <td class="text-right"><?= number_format($grandToll, 0, '.', ',') ?> đ</td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>

<!-- Tổng thanh toán -->
<table style="margin-top:10px;width:40%;margin-left:auto">
    <tbody>
        <tr>
            <td style="padding:5px 10px;font-weight:bold">Tiền vận chuyển (chưa VAT):</td>
            <td class="text-right" style="padding:5px 10px;font-weight:bold">
                <?= number_format($grandAmount, 0, '.', ',') ?> đ
            </td>
        </tr>
        <tr>
            <td style="padding:5px 10px;color:#666">VAT 8%:</td>
            <td class="text-right" style="padding:5px 10px;color:#666">
                <?= number_format($grandAmount * 0.08, 0, '.', ',') ?> đ
            </td>
        </tr>
        <tr style="background:#e8f4fd">
            <td style="padding:6px 10px;font-weight:bold;font-size:12pt">TỔNG THANH TOÁN:</td>
            <td class="text-right" style="padding:6px 10px;font-weight:bold;font-size:12pt;color:#0d6efd">
                <?= number_format($grandAmount * 1.08, 0, '.', ',') ?> đ
            </td>
        </tr>
    </tbody>
</table>

<!-- Ký tên -->
<div class="signature-row">
    <div class="sig-box">
        <div class="sig-title">Người lập bảng kê</div>
        <div class="sig-sub">(Ký, ghi rõ họ tên)</div>
        <div class="sig-name"><?= htmlspecialchars($user['full_name']) ?></div>
    </div>
    <div class="sig-box">
        <div class="sig-title">Đại diện khách hàng</div>
        <div class="sig-sub">(Ký, đóng dấu xác nhận)</div>
        <div class="sig-name"><?= htmlspecialchars($customer['company_name']) ?></div>
    </div>
    <div class="sig-box">
        <div class="sig-title">DNA EXPRESS VIỆT NAM</div>
        <div class="sig-sub">(Ký, đóng dấu)</div>
        <div class="sig-name">Giám đốc</div>
    </div>
</div>

</body>
</html>