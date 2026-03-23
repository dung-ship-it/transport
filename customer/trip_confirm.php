<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('customer')) { header('Location: /transport/dashboard.php'); exit; }

$pdo  = getDBConnection();
$user = currentUser();

$cuStmt = $pdo->prepare("
    SELECT cu.*, c.company_name FROM customer_users cu
    JOIN customers c ON cu.customer_id = c.id
    WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
");
$cuStmt->execute([$user['id']]);
$cu = $cuStmt->fetch();
if (!$cu || !in_array($cu['role'], ['approver','admin_customer'])) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Bạn không có quyền duyệt chuyến!'];
    header('Location: trips.php'); exit;
}

$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$tripStmt = $pdo->prepare("
    SELECT t.*, v.plate_number, u.full_name AS driver_name
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d  ON t.driver_id  = d.id
    JOIN users u    ON d.user_id    = u.id
    WHERE t.id = ? AND t.customer_id = ?
");
$tripStmt->execute([$id, $cu['customer_id']]);
$trip = $tripStmt->fetch();

if (!$trip) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy chuyến xe!'];
    header('Location: trips.php'); exit;
}

// Confirm
if ($action === 'confirm') {
    $pdo->prepare("
        UPDATE trips SET
            status       = 'confirmed',
            confirmed_by = ?,
            confirmed_at = NOW(),
            updated_at   = NOW()
        WHERE id = ? AND customer_id = ?
    ")->execute([$user['id'], $id, $cu['customer_id']]);

    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => '✅ Đã xác nhận chuyến <strong>'.$trip['trip_code'].'</strong>!'
    ];
    header('Location: trips.php'); exit;
}

// Reject
if ($action === 'reject') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rejection_reason'])) {
        $reason = trim($_POST['rejection_reason']);
        if (!$reason) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Vui lòng nhập lý do từ chối!'];
            header('Location: trip_confirm.php?id='.$id.'&action=reject'); exit;
        }
        $pdo->prepare("
            UPDATE trips SET
                status           = 'rejected',
                rejection_reason = ?,
                rejected_by      = ?,
                rejected_at      = NOW(),
                updated_at       = NOW()
            WHERE id = ? AND customer_id = ?
        ")->execute([$reason, $user['id'], $id, $cu['customer_id']]);

        $_SESSION['flash'] = [
            'type' => 'warning',
            'msg'  => '⚠️ Đã từ chối chuyến <strong>'.$trip['trip_code'].'</strong>.'
        ];
        header('Location: trips.php'); exit;
    }

    $pageTitle = 'Từ chối chuyến xe';
    // ✅ include TRƯỚC khi xuất HTML
    include '../includes/customer_header.php';
    ?>

    <!-- Topbar -->
    <div class="d-flex justify-content-between align-items-center px-3 py-2"
         style="background:#0f3460;color:#fff">
        <div class="fw-bold">🏢 <?= htmlspecialchars($cu['company_name']) ?></div>
        <a href="trips.php" class="btn btn-sm btn-outline-light">
            <i class="fas fa-arrow-left me-1"></i>Quay lại
        </a>
    </div>

    <div class="container py-4" style="max-width:500px">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-danger text-white py-2">
                <h6 class="fw-bold mb-0">❌ Từ chối chuyến <?= $trip['trip_code'] ?></h6>
            </div>
            <div class="card-body">
                <div class="p-2 rounded-3 bg-light mb-3 small">
                    <div><strong>Xe:</strong> <?= $trip['plate_number'] ?></div>
                    <div><strong>Lái xe:</strong> <?= htmlspecialchars($trip['driver_name']) ?></div>
                    <div><strong>Ngày:</strong> <?= date('d/m/Y', strtotime($trip['trip_date'])) ?></div>
                    <div class="text-uppercase">
                        <strong>Tuyến:</strong>
                        <?= htmlspecialchars($trip['pickup_location']) ?>
                        → <?= htmlspecialchars($trip['dropoff_location']) ?>
                    </div>
                    <div><strong>KM:</strong>
                        <?= $trip['total_km'] ? number_format($trip['total_km'],0).' km' : '—' ?>
                    </div>
                </div>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Lý do từ chối <span class="text-danger">*</span>
                        </label>
                        <textarea name="rejection_reason" class="form-control" rows="4"
                                  placeholder="Nhập lý do từ chối..." required></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i> Xác nhận từ chối
                        </button>
                        <a href="trips.php" class="btn btn-outline-secondary">Hủy</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php
    include '../includes/customer_footer.php'; // ✅
    exit;
}

header('Location: trips.php');
exit;