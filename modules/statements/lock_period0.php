<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$pdo  = getDBConnection();

// Quyền
$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');
$canEdit  = in_array($userRole, ['admin','superadmin','accountant','ketoan','ke_toan'])
            || can('statements', 'crud');

if (!$canEdit) {
    echo json_encode(['ok'=>false,'msg'=>'Không có quyền chốt kỳ.']);
    exit;
}

// Nhận JSON body
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

// Fallback sang $_POST nếu không có JSON body
if (empty($input)) {
    $input = $_POST;
}

$dateFrom    = trim($input['date_from']    ?? '');
$dateTo      = trim($input['date_to']      ?? '');
$action      = trim($input['action']       ?? 'lock');
$totalAmount = (float)($input['total_amount']   ?? 0);
$totalTrips  = (int)  ($input['total_trips']    ?? 0);
$totalKm     = (float)($input['total_km']       ?? 0);
$custCount   = (int)  ($input['customer_count'] ?? 0);
$periodLabel = trim($input['period_label'] ?? '');
$statements  = $input['statements'] ?? null;

if (!$dateFrom || !$dateTo) {
    echo json_encode(['ok'=>false,'msg'=>'Thiếu thông tin kỳ (date_from / date_to).']);
    exit;
}

try {
    $chk = $pdo->prepare("SELECT id, status FROM statement_periods WHERE period_from = ? AND period_to = ?");
    $chk->execute([$dateFrom, $dateTo]);
    $existing = $chk->fetch(PDO::FETCH_ASSOC);

    // ── UNLOCK ──────────────────────────────────────────────
    if ($action === 'unlock') {
        if (!$existing) {
            echo json_encode(['ok'=>false,'msg'=>'Kỳ này chưa được chốt.']);
            exit;
        }
        $pdo->prepare("
            UPDATE statement_periods
            SET status='draft', locked_at=NULL, locked_by=NULL
            WHERE id=?
        ")->execute([$existing['id']]);
        echo json_encode(['ok'=>true,'msg'=>'🔓 Đã mở lại kỳ bảng kê.','status'=>'draft']);
        exit;
    }

    // ── LOCK ────────────────────────────────────────────────
    if ($existing && $existing['status'] === 'locked') {
        echo json_encode(['ok'=>false,'msg'=>'⚠️ Kỳ này đã được chốt rồi. Mở lại trước nếu muốn chỉnh sửa.']);
        exit;
    }

    $pdo->beginTransaction();

    if ($existing) {
        // Cập nhật draft → locked
        $pdo->prepare("
            UPDATE statement_periods
            SET status        = 'locked',
                locked_at     = NOW(),
                locked_by     = ?,
                total_amount  = ?,
                total_trips   = ?,
                total_km      = ?,
                customer_count= ?,
                period_label  = COALESCE(NULLIF(?,''),(
                    SELECT period_label FROM statement_periods WHERE id = ?
                ))
            WHERE id = ?
        ")->execute([
            $user['id'], $totalAmount, $totalTrips, $totalKm,
            $custCount, $periodLabel, $existing['id'],
            $existing['id']
        ]);
        $periodId = $existing['id'];
    } else {
        // Insert mới
        $pdo->prepare("
            INSERT INTO statement_periods
                (period_from, period_to, period_label, status,
                 locked_at, locked_by,
                 total_amount, total_trips, total_km, customer_count, created_by)
            VALUES (?,?,?,'locked', NOW(),?, ?,?,?,?,?)
        ")->execute([
            $dateFrom, $dateTo,
            $periodLabel ?: ('Kỳ '.date('d/m/Y',strtotime($dateFrom)).' - '.date('d/m/Y',strtotime($dateTo))),
            $user['id'],
            $totalAmount, $totalTrips, $totalKm, $custCount,
            $user['id']
        ]);
        $periodId = $pdo->lastInsertId();
    }

    // Lưu statement_items nếu có
    if (!empty($statements) && is_array($statements)) {
        // Xoá items cũ
        $pdo->prepare("DELETE FROM statement_items WHERE period_id = ?")->execute([$periodId]);

        $insItem = $pdo->prepare("
            INSERT INTO statement_items
                (period_id, customer_id, price_book_name, trip_count, confirmed_count,
                 total_km, total_toll, total_amount, vehicle_count, has_price, detail_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        foreach ($statements as $s) {
            $insItem->execute([
                $periodId,
                $s['customer_id']     ?? null,
                $s['pb_name']         ?? null,
                $s['trip_count']      ?? 0,
                $s['confirmed_count'] ?? 0,
                $s['total_km']        ?? 0,
                $s['total_toll']      ?? 0,
                $s['total_amount']    ?? 0,
                isset($s['vehicles']) ? count($s['vehicles']) : 0,
                !empty($s['has_price']) ? 1 : 0,
                json_encode($s, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // Confirm các trips completed → confirmed
    $pdo->prepare("
        UPDATE trips SET status='confirmed'
        WHERE trip_date BETWEEN ? AND ? AND status='completed'
    ")->execute([$dateFrom, $dateTo]);
    $confirmedCount = $pdo->rowCount();

    $pdo->commit();

    // URL redirect sang báo cáo
    $redirect = '/modules/reports/index.php?tab=revenue'
              . '&date_from=' . urlencode($dateFrom)
              . '&date_to='   . urlencode($dateTo)
              . '&from_lock=1';

    echo json_encode([
        'ok'              => true,
        'msg'             => '✅ Đã chốt kỳ thành công!'
                            . ($confirmedCount > 0 ? " $confirmedCount chuyến được xác nhận." : ''),
        'status'          => 'locked',
        'period_id'       => (int)$periodId,
        'confirmed_count' => $confirmedCount,
        'redirect'        => $redirect,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('lock_period error: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Lỗi hệ thống: '.$e->getMessage()]);
}