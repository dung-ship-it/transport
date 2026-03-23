<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

// Chỉ nhận POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ nhận POST request.']);
    exit;
}

$user = currentUser();
$pdo  = getDBConnection();

// Kiểm tra quyền
$roleStmt = $pdo->prepare("
    SELECT r.name FROM roles r
    JOIN users u ON u.role_id = r.id
    WHERE u.id = ?
");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');
$canEdit  = in_array($userRole, ['admin', 'superadmin', 'accountant', 'ketoan', 'ke_toan']) || can('statements', 'crud');

if (!$canEdit) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền chốt kỳ.']);
    exit;
}

// Đọc input — hỗ trợ cả JSON body lẫn POST form
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$dateFrom    = trim($input['date_from']    ?? '');
$dateTo      = trim($input['date_to']      ?? '');
$action      = trim($input['action']       ?? 'lock'); // lock | unlock
$totalAmount = (float)($input['total_amount'] ?? 0);
$totalTrips  = (int)($input['total_trips']    ?? 0);
$totalKm     = (float)($input['total_km']     ?? 0);
$periodLabel = trim($input['period_label']    ?? '');
$note        = trim($input['note']            ?? '');
$statementsData = $input['statements'] ?? [];

if (!$dateFrom || !$dateTo) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin kỳ (date_from, date_to).']);
    exit;
}

// Tự tạo period_label nếu không có
if (!$periodLabel) {
    $periodLabel = date('d/m/Y', strtotime($dateFrom)) . ' – ' . date('d/m/Y', strtotime($dateTo));
}

try {
    $pdo->beginTransaction();

    // Kiểm tra đã có record chưa
    $chk = $pdo->prepare("
        SELECT id, status FROM statement_periods
        WHERE period_from = ? AND period_to = ?
    ");
    $chk->execute([$dateFrom, $dateTo]);
    $existing = $chk->fetch();

    // ── UNLOCK ──────────────────────────────────────────────
    if ($action === 'unlock') {
        if (!$existing) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Kỳ này chưa được chốt.']);
            exit;
        }
        $pdo->prepare("
            UPDATE statement_periods
            SET status = 'draft', locked_at = NULL, locked_by = NULL
            WHERE id = ?
        ")->execute([$existing['id']]);
        $pdo->commit();
        echo json_encode(['ok' => true, 'msg' => '🔓 Đã mở lại kỳ bảng kê.', 'status' => 'draft']);
        exit;
    }

    // ── LOCK ─────────────────────────────────────────────────
    $customerCount = !empty($statementsData) ? count($statementsData) : (int)($input['customer_count'] ?? 0);

    if ($existing) {
        if ($existing['status'] === 'locked') {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => '⚠️ Kỳ này đã được chốt rồi. Mở lại trước nếu muốn chỉnh sửa.']);
            exit;
        }
        // Cập nhật draft → locked
        $pdo->prepare("
            UPDATE statement_periods SET
                status         = 'locked',
                locked_at      = NOW(),
                locked_by      = ?,
                total_amount   = ?,
                total_trips    = ?,
                total_km       = ?,
                customer_count = ?,
                period_label   = ?,
                note           = ?
            WHERE id = ?
        ")->execute([
            $user['id'], $totalAmount, $totalTrips, $totalKm,
            $customerCount, $periodLabel, $note, $existing['id']
        ]);
        $periodId = $existing['id'];
    } else {
        // Insert mới
        $ins = $pdo->prepare("
            INSERT INTO statement_periods
                (period_from, period_to, period_label, status,
                 total_amount, total_trips, total_km, customer_count,
                 locked_by, locked_at, created_by, note)
            VALUES (?, ?, ?, 'locked', ?, ?, ?, ?, ?, NOW(), ?, ?)
        ");
        $ins->execute([
            $dateFrom, $dateTo, $periodLabel,
            $totalAmount, $totalTrips, $totalKm, $customerCount,
            $user['id'], $user['id'], $note
        ]);
        $periodId = $pdo->lastInsertId();
    }

    // ── Lưu statement_items (nếu có dữ liệu chi tiết) ───────
    if (!empty($statementsData)) {
        // Xóa items cũ
        $pdo->prepare("DELETE FROM statement_items WHERE period_id = ?")->execute([$periodId]);

        $insItem = $pdo->prepare("
            INSERT INTO statement_items
                (period_id, customer_id, price_book_name, trip_count, confirmed_count,
                 total_km, total_toll, total_amount, vehicle_count, has_price, detail_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb)
        ");
        foreach ($statementsData as $s) {
            $insItem->execute([
                $periodId,
                $s['customer_id']    ?? 0,
                $s['pb_name']        ?? '',
                $s['trip_count']     ?? 0,
                $s['confirmed_count']?? 0,
                $s['total_km']       ?? 0,
                $s['total_toll']     ?? 0,
                $s['total_amount']   ?? 0,
                count($s['vehicles'] ?? []),
                !empty($s['has_price']) ? 'true' : 'false',
                json_encode($s, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // ── Confirm các chuyến 'completed' trong kỳ ─────────────
    $updTrips = $pdo->prepare("
        UPDATE trips SET status = 'confirmed'
        WHERE trip_date BETWEEN ? AND ?
          AND status = 'completed'
    ");
    $updTrips->execute([$dateFrom, $dateTo]);
    $confirmedCount = $updTrips->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'              => true,
        'msg'             => '✅ Đã chốt kỳ thành công!' . ($confirmedCount > 0 ? " ($confirmedCount chuyến được xác nhận)" : ''),
        'status'          => 'locked',
        'period_id'       => $periodId,
        'confirmed_count' => $confirmedCount,
        'redirect'        => '/modules/reports/index.php?tab=revenue&date_from=' . $dateFrom . '&date_to=' . $dateTo,
    ]);

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('[lock_period] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}