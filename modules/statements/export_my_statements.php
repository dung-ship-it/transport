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

if ($filterCustId && !in_array($role, ['driver','customer'])) {
    $where[]  = 't.customer_id = ?';
    $params[] = $filterCustId;
}

if ($filterPlate) {
    $where[]  = 'v.plate_number = ?';
    $params[] = $filterPlate;
}

$whereStr = implode(' AND ', $where);

// ── Query trips ──────────────────────────────────────────────
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
$totalTrips = count($trips);

// ── Nhóm theo khách hàng → biển số ──────────────────────────
$grouped = [];

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
            'confirmed'    => 0,
            'trips'        => [],
        ];
    }

    $grouped[$cid]['vehicles'][$plate]['trips'][]   = $t;
    $grouped[$cid]['vehicles'][$plate]['trip_count']++;
    $grouped[$cid]['vehicles'][$plate]['total_km']   += (float)$t['total_km'];
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

// ── PhpSpreadsheet ───────────────────────────────────────────
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;

$spreadsheet = new Spreadsheet();

// ════════════════════════════════════════════════════════════
// SHEET 1: Tóm tắt
// ════════════════════════════════════════════════════════════
$sheet1 = $spreadsheet->getActiveSheet();
$sheet1->setTitle('Tóm tắt');

$totalCols1 = 7; // A–G
$lastCol1   = 'G';

// Helper: merge & style header
$sheet1->mergeCells("A1:{$lastCol1}1");
$sheet1->setCellValue('A1', 'CÔNG TY TNHH DNA EXPRESS VIỆT NAM');
$sheet1->getStyle('A1')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 13],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet1->mergeCells("A2:{$lastCol1}2");
$sheet1->setCellValue('A2', 'Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam');
$sheet1->getStyle('A2')->applyFromArray([
    'font'      => ['size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

$sheet1->mergeCells("A3:{$lastCol1}3");
$sheet1->setCellValue('A3', 'MST: 0107514537');
$sheet1->getStyle('A3')->applyFromArray([
    'font'      => ['size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Row 4 trống
$sheet1->mergeCells("A4:{$lastCol1}4");

$sheet1->mergeCells("A5:{$lastCol1}5");
$sheet1->setCellValue('A5', 'BẢNG KÊ TÌNH HÌNH SỬ DỤNG XE');
$sheet1->getStyle('A5')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 14],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Row 6: thông tin người lập
$sheet1->mergeCells("A6:{$lastCol1}6");
if ($role === 'customer' && $cu) {
    $meta6 = 'Khách hàng: ' . ($cu['company_name'] ?? '');
    if (!empty($cu['tax_code'])) $meta6 .= ' — MST: ' . $cu['tax_code'];
} elseif ($role === 'driver' && $driverRow) {
    $meta6 = 'Lái xe: ' . ($user['full_name'] ?? '');
    if ($filterPlate) $meta6 .= ' — Xe: ' . $filterPlate;
} else {
    if ($filterCustId && !empty($grouped)) {
        $meta6 = 'Khách hàng: ' . (array_values($grouped)[0]['customer_name'] ?? '');
    } else {
        $meta6 = 'Người lập: ' . ($user['full_name'] ?? '');
    }
    if ($filterPlate) $meta6 .= ' — Xe: ' . $filterPlate;
}
$sheet1->setCellValue('A6', $meta6);
$sheet1->getStyle('A6')->applyFromArray([
    'font'      => ['size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Row 7: kỳ / ngày in / người in
$sheet1->mergeCells("A7:{$lastCol1}7");
$periodStr = date('d/m/Y', strtotime($dateFrom)) . ' — ' . date('d/m/Y', strtotime($dateTo));
$sheet1->setCellValue('A7',
    'Kỳ: ' . $periodStr .
    '   |   Ngày in: ' . date('d/m/Y H:i') .
    '   |   Người in: ' . ($user['full_name'] ?? '')
);
$sheet1->getStyle('A7')->applyFromArray([
    'font'      => ['size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Row 8 trống
$sheet1->mergeCells("A8:{$lastCol1}8");

// Row 9: thống kê nhanh
$sheet1->mergeCells("A9:{$lastCol1}9");
$sheet1->setCellValue('A9',
    'Tổng chuyến: ' . $totalTrips .
    '   |   Tổng KM: ' . number_format($totalKm, 0) .
    '   |   Khách hàng: ' . count($grouped)
);
$sheet1->getStyle('A9')->applyFromArray([
    'font'      => ['bold' => true, 'size' => 10],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Row 10 trống
$sheet1->mergeCells("A10:{$lastCol1}10");

// Row 11: header bảng tóm tắt
$sheet1->fromArray(['STT', 'Khách hàng', 'Biển số xe', 'Tải trọng', 'Số chuyến', 'Tổng KM', 'Đã duyệt'], null, 'A11');
$sheet1->getStyle('A11:G11')->applyFromArray([
    'font'      => ['bold' => true],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F0F0']],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);

// Freeze pane
$sheet1->freezePane('A12');

$row1 = 12;
$stt  = 0;

foreach ($grouped as $cid => $cGroup) {
    $vCount     = count($cGroup['vehicles']);
    $custMergeStart = $row1;
    $first      = true;

    foreach ($cGroup['vehicles'] as $plate => $veh) {
        $stt++;
        $sheet1->setCellValue("A{$row1}", $stt);
        if ($first) {
            $custName = $cGroup['customer_short'] ?: $cGroup['customer_name'];
            if ($cGroup['customer_tax']) {
                $custName .= "\nMST: " . $cGroup['customer_tax'];
            }
            $sheet1->setCellValue("B{$row1}", $custName);
            $sheet1->getStyle("B{$row1}")->getFont()->setBold(true);
            $first = false;
        }
        $sheet1->setCellValue("C{$row1}", strtoupper($plate));
        $sheet1->setCellValue("D{$row1}", $veh['capacity'] ? $veh['capacity'] . ' tấn' : '—');
        $sheet1->setCellValue("E{$row1}", (int)$veh['trip_count']);
        $sheet1->setCellValue("F{$row1}", (float)$veh['total_km']);
        $sheet1->setCellValue("G{$row1}", $veh['confirmed'] . '/' . $veh['trip_count']);

        $sheet1->getStyle("A{$row1}:G{$row1}")->applyFromArray([
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet1->getStyle("A{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("C{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("D{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("E{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("F{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet1->getStyle("G{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row1++;
    }

    // Merge customer name column
    if ($vCount > 1) {
        $sheet1->mergeCells("B{$custMergeStart}:B" . ($row1 - 1));
        $sheet1->getStyle("B{$custMergeStart}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
    }

    // Subtotal row
    $subtotalLabel = 'Tổng ' . ($cGroup['customer_short'] ?: $cGroup['customer_name']) .
                     ' (' . count($cGroup['vehicles']) . ' xe):';
    $sheet1->mergeCells("A{$row1}:D{$row1}");
    $sheet1->setCellValue("A{$row1}", $subtotalLabel);
    $sheet1->setCellValue("E{$row1}", (int)$cGroup['trip_count']);
    $sheet1->setCellValue("F{$row1}", (float)$cGroup['total_km']);
    $sheet1->setCellValue("G{$row1}", $cGroup['confirmed'] . '/' . $cGroup['trip_count']);
    $sheet1->getStyle("A{$row1}:G{$row1}")->applyFromArray([
        'font'    => ['bold' => true, 'italic' => true],
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $sheet1->getStyle("A{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet1->getStyle("E{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle("F{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sheet1->getStyle("G{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row1++;
}

// Grand total row
$sheet1->mergeCells("A{$row1}:D{$row1}");
$sheet1->setCellValue("A{$row1}", 'TỔNG CỘNG:');
$sheet1->setCellValue("E{$row1}", (int)$totalTrips);
$sheet1->setCellValue("F{$row1}", (float)$totalKm);
$sheet1->getStyle("A{$row1}:G{$row1}")->applyFromArray([
    'font'    => ['bold' => true],
    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F5F5F5']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
]);
$sheet1->getStyle("A{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
$sheet1->getStyle("E{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet1->getStyle("F{$row1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

// Auto-size columns sheet1
foreach (range('A', $lastCol1) as $col) {
    $sheet1->getColumnDimension($col)->setAutoSize(true);
}

// ════════════════════════════════════════════════════════════
// SHEET 2: Chi tiết
// ════════════════════════════════════════════════════════════
$sheet2 = $spreadsheet->createSheet();
$sheet2->setTitle('Chi tiết');

$isCustomer = ($role === 'customer');
$totalCols2 = $isCustomer ? 11 : 12;
$lastCol2   = $isCustomer ? 'K' : 'L';

$detailHeaders = ['STT', 'Người lái', 'Ngày'];
if (!$isCustomer) $detailHeaders[] = 'Khách hàng';
$detailHeaders = array_merge($detailHeaders, [
    'Điểm đi', 'Điểm đến', 'KM đi', 'KM về', 'Tổng KM', 'Ghi chú', 'Trạng thái', 'KH duyệt',
]);

$row2 = 1;

foreach ($grouped as $cid => $cGroup) {
    // Header khách hàng
    $sheet2->mergeCells("A{$row2}:{$lastCol2}{$row2}");
    $custDisplay = $cGroup['customer_name'];
    if ($cGroup['customer_tax']) $custDisplay .= ' — MST: ' . $cGroup['customer_tax'];
    $sheet2->setCellValue("A{$row2}", $custDisplay);
    $sheet2->getStyle("A{$row2}:{$lastCol2}{$row2}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F3460']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    $row2++;

    foreach ($cGroup['vehicles'] as $plate => $veh) {
        // Header xe
        $sheet2->mergeCells("A{$row2}:{$lastCol2}{$row2}");
        $vehTitle = 'Xe: ' . strtoupper($plate);
        if ($veh['capacity']) $vehTitle .= ' (' . $veh['capacity'] . ' tấn)';
        $vehTitle .= '   —   ' . $veh['trip_count'] . ' chuyến · ' .
                     number_format($veh['total_km'], 0) . ' km · Đã duyệt: ' .
                     $veh['confirmed'] . '/' . $veh['trip_count'];
        $sheet2->setCellValue("A{$row2}", $vehTitle);
        $sheet2->getStyle("A{$row2}:{$lastCol2}{$row2}")->applyFromArray([
            'font'      => ['bold' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $row2++;

        // Header bảng chi tiết
        $sheet2->fromArray($detailHeaders, null, "A{$row2}");
        $sheet2->getStyle("A{$row2}:{$lastCol2}{$row2}")->applyFromArray([
            'font'      => ['bold' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F0F0']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $row2++;

        // Trips
        foreach ($veh['trips'] as $ti => $t) {
            $col = 'A';
            $sheet2->setCellValue("{$col}{$row2}", $ti + 1); $col++;
            $sheet2->setCellValue("{$col}{$row2}", $t['driver_name']); $col++;
            $sheet2->setCellValue("{$col}{$row2}", date('d/m/Y', strtotime($t['trip_date']))); $col++;
            if (!$isCustomer) {
                $sheet2->setCellValue("{$col}{$row2}", $t['customer_short'] ?: $t['customer_name']);
                $col++;
            }
            $sheet2->setCellValue("{$col}{$row2}", $t['pickup_location'] ?? ''); $col++;
            $sheet2->setCellValue("{$col}{$row2}", $t['dropoff_location'] ?? ''); $col++;
            $sheet2->setCellValue("{$col}{$row2}", $t['odometer_start'] ? (float)$t['odometer_start'] : ''); $col++;
            $sheet2->setCellValue("{$col}{$row2}", $t['odometer_end']   ? (float)$t['odometer_end']   : ''); $col++;
            $sheet2->setCellValue("{$col}{$row2}", $t['total_km'] ? (float)$t['total_km'] : ''); $col++;

            $noteVal = $t['note'] ?? '';
            if ($t['rejection_reason']) $noteVal .= ($noteVal ? "\n" : '') . '❌ ' . $t['rejection_reason'];
            $sheet2->setCellValue("{$col}{$row2}", $noteVal); $col++;

            $sheet2->setCellValue("{$col}{$row2}", $statusPrint[$t['status']] ?? $t['status']); $col++;

            $confirmedBy = '';
            if ($t['confirmed_by_name']) {
                $confirmedBy = $t['confirmed_by_name'];
                if ($t['confirmed_at']) {
                    $confirmedBy .= ' ' . date('d/m/Y H:i', strtotime($t['confirmed_at']));
                }
            }
            $sheet2->setCellValue("{$col}{$row2}", $confirmedBy);

            // Row background by status
            $bgColor = null;
            if ($t['status'] === 'confirmed')   $bgColor = 'F0FFF4';
            elseif ($t['status'] === 'rejected') $bgColor = 'FFF5F5';
            elseif ($t['status'] === 'in_progress') $bgColor = 'FFF8E1';

            $rowStyle = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];
            if ($bgColor) {
                $rowStyle['fill'] = ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]];
            }
            $sheet2->getStyle("A{$row2}:{$lastCol2}{$row2}")->applyFromArray($rowStyle);
            $row2++;
        }

        // Vehicle subtotal
        $mergeEnd = $isCustomer ? 7 : 8; // number of cols to merge for label
        $mergeColLetter = chr(ord('A') + $mergeEnd - 1); // 0-indexed col letter
        $sheet2->mergeCells("A{$row2}:{$mergeColLetter}{$row2}");
        $sheet2->setCellValue("A{$row2}", 'Tổng xe ' . strtoupper($plate) . ' (' . $veh['trip_count'] . ' chuyến):');
        // KM total in next column
        $kmCol = chr(ord('A') + $mergeEnd);
        $sheet2->setCellValue("{$kmCol}{$row2}", (float)$veh['total_km']);
        $sheet2->getStyle("A{$row2}:{$lastCol2}{$row2}")->applyFromArray([
            'font'    => ['bold' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ]);
        $sheet2->getStyle("A{$row2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet2->getStyle("{$kmCol}{$row2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $row2++;
    }

    // Separator row between customers
    $row2++;
}

// Auto-size columns sheet2
foreach (range('A', $lastCol2) as $col) {
    $sheet2->getColumnDimension($col)->setAutoSize(true);
}

// ── Output ───────────────────────────────────────────────────
$safeFrom = preg_replace('/[^0-9\-]/', '', $dateFrom);
$safeTo   = preg_replace('/[^0-9\-]/', '', $dateTo);
$fileName = 'bang-ke-' . $safeFrom . '-' . $safeTo . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
