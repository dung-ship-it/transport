<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('trips', 'confirm');

$pdo    = getDBConnection();
$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

$trip = $pdo->prepare("SELECT * FROM trips WHERE id = ?");
$trip->execute([$id]);
$trip = $trip->fetch();

if (!$trip || !$id) { header('Location: index.php'); exit; }

if ($action === 'approve') {
    $pdo->prepare("
        UPDATE trips SET
            status       = 'completed',
            approved_by  = ?,
            approved_at  = NOW(),
            updated_at   = NOW()
        WHERE id = ?
    ")->execute([currentUser()['id'], $id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã duyệt chuyến '.$trip['trip_code'].'!'];
    header('Location: index.php'); exit;
}

if ($action === 'reject') {
    // Hiện form nhập lý do
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rejection_reason'])) {
        $reason = trim($_POST['rejection_reason']);
        if (!$reason) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Vui lòng nhập lý do từ chối!'];
            header('Location: confirm.php?id='.$id.'&action=reject'); exit;
        }
        $pdo->prepare("
            UPDATE trips SET
                status           = 'rejected',
                rejection_reason = ?,
                rejected_by      = ?,
                rejected_at      = NOW(),
                updated_at       = NOW()
            WHERE id = ?
        ")->execute([$reason, currentUser()['id'], $id]);
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'⚠️ Đã từ chối chuyến '.$trip['trip_code'].'. Lái xe sẽ được thông báo.'];
        header('Location: index.php'); exit;
    }

    // Hiện form nhập lý do
    include '../../includes/header.php';
    include '../../includes/sidebar.php';
    ?>
    <div class="main-content">
    <div class="container-fluid py-4" style="max-width:500px">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-danger text-white py-2">
                <h6 class="fw-bold mb-0">❌ Từ chối chuyến <?= $trip['trip_code'] ?></h6>
            </div>
            <div class="card-body">
                <?php showFlash(); ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control" rows="4"
                                  placeholder="Nhập lý do từ chối để thông báo cho lái xe..."
                                  required></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-1"></i> Xác nhận từ chối
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    </div>
    <?php
    include '../../includes/footer.php';
    exit;
}

header('Location: index.php');
exit;