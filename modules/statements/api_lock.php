<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

header('Content-Type: application/json');

$user = currentUser();
$pdo  = getDBConnection();

// Kiểm tra quyền
$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');

if (!in_array($userRole, ['admin','superadmin','accountant','ketoan','ke_toan'])) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền chốt kỳ.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Method không hợp lệ.']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true);
$dateFrom   = $input['date_from']   ?? '';
$dateTo     = $input['date_to']     ?? '';
$periodLabel = $input['period_label'] ?? '';
$statements = $input['statements']  ?? [];  // Dữ liệu tính toán từ frontend
$note       = $input['note']        ?? '';

if (!$dateFrom || !$dateTo || empty($statements)) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu dữ liệu.']);
    exit;
}

// Kiểm tra đã có kỳ này chưa
$chk = $pdo->prepare("SELECT id, status FROM statement_periods WHERE period_from = ? AND period_to = ?");
$chk->execute([$dateFrom, $dateTo]);
$existing = $chk->fetch();

if ($existing && $existing['status'] === 'locked') {
    echo json_encode(['ok' => false, 'msg' => 'Kỳ này đã được chốt rồi! Không thể chốt lại.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $totalAmount = array_sum(array_column($statements, 'total_amount'));
    $totalKm     = array_sum(array_column($statements, 'total_km'));
    $totalTrips  = array_sum(array_column($statements, 'trip_count'));

    if ($existing) {
        // Cập nhật draft
        $pdo->prepare("
            UPDATE statement_periods SET
                period_label   = ?,
                total_amount   = ?,
                total_km       = ?,
                total_trips    = ?,
                customer_count = ?,
                status         = 'locked',
                locked_by      = ?,
                locked_at      = NOW(),
                note           = ?
            WHERE id = ?
        ")->execute([
            $periodLabel, $totalAmount, $totalKm, $totalTrips,
            count($statements), $user['id'], $note, $existing['id']
        ]);
        $periodId = $existing['id'];

        // Xóa items cũ để insert lại
        $pdo->prepare("DELETE FROM statement_items WHERE period_id = ?")->execute([$periodId]);

    } else {
        // Tạo mới
        $ins = $pdo->prepare("
            INSERT INTO statement_periods
                (period_from, period_to, period_label, status,
                 total_amount, total_km, total_trips, customer_count,
                 locked_by, locked_at, created_by, note)
            VALUES (?,?,?,'locked',?,?,?,?,?,NOW(),?,?)
        ");
        $ins->execute([
            $dateFrom, $dateTo, $periodLabel,
            $totalAmount, $totalKm, $totalTrips, count($statements),
            $user['id'], $user['id'], $note
        ]);
        $periodId = $pdo->lastInsertId();
    }

    // Insert từng item
    $insItem = $pdo->prepare("
        INSERT INTO statement_items
            (period_id, customer_id, price_book_name, trip_count, confirmed_count,
             total_km, total_toll, total_amount, vehicle_count, has_price, detail_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb)
    ");

    foreach ($statements as $s) {
        $insItem->execute([
            $periodId,
            $s['customer_id'],
            $s['pb_name'],
            $s['trip_count'],
            $s['confirmed_count'],
            $s['total_km'],
            $s['total_toll'],
            $s['total_amount'],
            count($s['vehicles']),
            $s['has_price'] ? 1 : 0,
            json_encode($s, JSON_UNESCAPED_UNICODE)
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok'        => true,
        'msg'       => '✅ Đã chốt kỳ bảng kê thành công!',
        'period_id' => $periodId,
        'summary'   => [
            'total_amount'   => $totalAmount,
            'total_km'       => $totalKm,
            'total_trips'    => $totalTrips,
            'customer_count' => count($statements),
        ]
    ]);

} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('Lock statement error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}