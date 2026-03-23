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
$canEdit  = in_array($userRole, ['admin', 'superadmin']) || can('statements', 'crud');

if (!$canEdit) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền chốt kỳ.']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$dateFrom   = $input['date_from'] ?? '';
$dateTo     = $input['date_to']   ?? '';
$action     = $input['action']    ?? 'lock'; // lock | unlock
$totalAmount = (float)($input['total_amount'] ?? 0);
$totalTrips  = (int)($input['total_trips']    ?? 0);
$totalKm     = (float)($input['total_km']     ?? 0);

if (!$dateFrom || !$dateTo) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin kỳ.']);
    exit;
}

try {
    // Kiểm tra đã có record chưa
    $chk = $pdo->prepare("SELECT id, status FROM statement_periods WHERE period_from = ? AND period_to = ?");
    $chk->execute([$dateFrom, $dateTo]);
    $existing = $chk->fetch();

    if ($action === 'unlock') {
        if (!$existing) {
            echo json_encode(['ok' => false, 'msg' => 'Kỳ này chưa được chốt.']);
            exit;
        }
        $pdo->prepare("UPDATE statement_periods SET status = 'draft', locked_at = NULL, locked_by = NULL WHERE id = ?")
            ->execute([$existing['id']]);
        echo json_encode(['ok' => true, 'msg' => '🔓 Đã mở lại kỳ bảng kê.', 'status' => 'draft']);
        exit;
    }

    // Hành động lock
    if ($existing) {
        if ($existing['status'] === 'locked') {
            echo json_encode(['ok' => false, 'msg' => '⚠️ Kỳ này đã được chốt rồi. Mở lại trước nếu muốn chỉnh sửa.']);
            exit;
        }
        // Cập nhật draft → locked
        $pdo->prepare("
            UPDATE statement_periods
            SET status = 'locked',
                locked_at = NOW(),
                locked_by = ?,
                total_amount = ?,
                total_trips  = ?,
                total_km     = ?
            WHERE id = ?
        ")->execute([$user['id'], $totalAmount, $totalTrips, $totalKm, $existing['id']]);
    } else {
        // Insert mới
        $pdo->prepare("
            INSERT INTO statement_periods
                (period_from, period_to, status, locked_at, locked_by, total_amount, total_trips, total_km, created_by)
            VALUES (?, ?, 'locked', NOW(), ?, ?, ?, ?, ?)
        ")->execute([$dateFrom, $dateTo, $user['id'], $totalAmount, $totalTrips, $totalKm, $user['id']]);
    }

    // Cập nhật trạng thái các trips trong kỳ thành 'confirmed' (nếu chưa)
    $pdo->prepare("
        UPDATE trips SET status = 'confirmed'
        WHERE trip_date BETWEEN ? AND ?
          AND status = 'completed'
    ")->execute([$dateFrom, $dateTo]);

    $confirmedCount = $pdo->rowCount(); // số trip vừa được confirm

    echo json_encode([
        'ok'             => true,
        'msg'            => '✅ Đã chốt kỳ thành công! ' . ($confirmedCount > 0 ? "$confirmedCount chuyến được xác nhận." : ''),
        'status'         => 'locked',
        'confirmed_count'=> $confirmedCount,
    ]);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'msg' => 'Lỗi: ' . $e->getMessage()]);
}