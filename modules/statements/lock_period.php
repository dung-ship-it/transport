<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$pdo  = getDBConnection();

// ── Kiểm tra quyền ──────────────────────────────────────────
$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');
$canEdit  = in_array($userRole, ['admin', 'superadmin', 'accountant', 'ketoan', 'ke_toan'])
            || can('statements', 'crud');

if (!$canEdit) {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền chốt kỳ.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Chỉ chấp nhận POST request.']);
    exit;
}

// ── Đọc input (hỗ trợ cả JSON và form POST) ─────────────────
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_POST;
}

$dateFrom    = trim($input['date_from']    ?? '');
$dateTo      = trim($input['date_to']      ?? '');
$action      = trim($input['action']       ?? 'lock');
$totalAmount = (float)($input['total_amount'] ?? 0);
$totalTrips  = (int)($input['total_trips']    ?? 0);
$totalKm     = (float)($input['total_km']     ?? 0);
$customerCount = (int)($input['customer_count'] ?? 0);
$periodLabel = trim($input['period_label']  ?? '');
$statements  = $input['statements'] ?? []; // array chi tiết KH

if (!$dateFrom || !$dateTo) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin kỳ (date_from, date_to).']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    echo json_encode(['ok' => false, 'msg' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).']);
    exit;
}

// Auto tạo period_label nếu chưa có
if (!$periodLabel) {
    $periodLabel = 'Kỳ ' . date('d/m/Y', strtotime($dateFrom)) . ' - ' . date('d/m/Y', strtotime($dateTo));
}

try {
    $pdo->beginTransaction();

    // Kiểm tra đã có record chưa
    $chk = $pdo->prepare("SELECT id, status FROM statement_periods WHERE period_from = ? AND period_to = ?");
    $chk->execute([$dateFrom, $dateTo]);
    $existing = $chk->fetch();

    // ── Hành động UNLOCK ────────────────���───────────────────
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

    // ── Hành động LOCK ───────────────────────────────────────
    if ($existing && $existing['status'] === 'locked') {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => '⚠️ Kỳ này đã được chốt rồi. Mở lại trước nếu muốn chỉnh sửa.']);
        exit;
    }

    // Tính lại tổng từ statements nếu có
    if (!empty($statements) && is_array($statements)) {
        $totalAmount   = array_sum(array_column($statements, 'total_amount'));
        $totalKm       = array_sum(array_column($statements, 'total_km'));
        $totalTrips    = array_sum(array_column($statements, 'trip_count'));
        $customerCount = count($statements);
    }

    if ($existing) {
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
                period_label   = ?
            WHERE id = ?
        ")->execute([
            $user['id'], $totalAmount, $totalTrips, $totalKm,
            $customerCount, $periodLabel, $existing['id']
        ]);
        $periodId = $existing['id'];

        // Xóa items cũ
        $pdo->prepare("DELETE FROM statement_items WHERE period_id = ?")->execute([$periodId]);

    } else {
        // Insert mới
        $ins = $pdo->prepare("
            INSERT INTO statement_periods
                (period_from, period_to, period_label, status,
                 total_amount, total_trips, total_km, customer_count,
                 locked_by, locked_at, created_by, created_at)
            VALUES (?,?,?,'locked',?,?,?,?,?,NOW(),?,NOW())
        ");
        $ins->execute([
            $dateFrom, $dateTo, $periodLabel,
            $totalAmount, $totalTrips, $totalKm, $customerCount,
            $user['id'], $user['id']
        ]);
        $periodId = $pdo->lastInsertId();
    }

    // ── Lưu statement_items nếu có data ─────────────────────
    if (!empty($statements) && is_array($statements)) {
        $insItem = $pdo->prepare("
            INSERT INTO statement_items
                (period_id, customer_id, price_book_name, trip_count, confirmed_count,
                 total_km, total_toll, total_amount, vehicle_count, has_price, detail_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?::jsonb)
        ");
        foreach ($statements as $s) {
            $insItem->execute([
                $periodId,
                (int)($s['customer_id'] ?? 0),
                $s['pb_name'] ?? '',
                (int)($s['trip_count'] ?? 0),
                (int)($s['confirmed_count'] ?? 0),
                (float)($s['total_km'] ?? 0),
                (float)($s['total_toll'] ?? 0),
                (float)($s['total_amount'] ?? 0),
                count($s['vehicles'] ?? []),
                !empty($s['has_price']),
                json_encode($s, JSON_UNESCAPED_UNICODE)
            ]);
        }
    }

    // ── Confirm các trips completed → confirmed ──────────────
    $updTrips = $pdo->prepare("
        UPDATE trips SET status = 'confirmed'
        WHERE trip_date BETWEEN ? AND ? AND status = 'completed'
    ");
    $updTrips->execute([$dateFrom, $dateTo]);
    $confirmedCount = $updTrips->rowCount();

    $pdo->commit();

    echo json_encode([
        'ok'              => true,
        'msg'             => '✅ Đã chốt kỳ thành công!' . ($confirmedCount > 0 ? " $confirmedCount chuyến được xác nhận." : ''),
        'status'          => 'locked',
        'period_id'       => $periodId,
        'confirmed_count' => $confirmedCount,
        'redirect'        => '/modules/reports/index.php?tab=revenue&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo),
        'summary' => [
            'total_amount'   => $totalAmount,
            'total_km'       => $totalKm,
            'total_trips'    => $totalTrips,
            'customer_count' => $customerCount,
        ]
    ]);

} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('Lock statement error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Lỗi hệ thống: ' . $e->getMessage()]);
}