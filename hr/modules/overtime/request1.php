<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Đăng ký tăng ca';

$errors = [];

// Xử lý form
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? 'create';

    // Huỷ đơn pending
    if ($action==='cancel') {
        $pdo->prepare("UPDATE hr_overtime SET status='cancelled' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([(int)$_POST['ot_id'], $user['id']]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã huỷ đơn OT.'];
        header('Location: request.php'); exit;
    }

    // Tạo mới
    $ot_date    = $_POST['ot_date']    ?? '';
    $ot_hours   = (float)($_POST['ot_hours'] ?? 0);
    $ot_rate    = (float)($_POST['ot_rate']  ?? 1.5);
    $reason     = trim($_POST['reason'] ?? '');

    if (empty($ot_date))   $errors[] = 'Vui lòng chọn ngày OT.';
    if ($ot_hours<=0)      $errors[] = 'Số giờ OT phải lớn hơn 0.';
    if ($ot_hours>12)      $errors[] = 'OT không vượt quá 12 giờ/ngày.';
    if (empty($reason))    $errors[] = 'Vui lòng nhập lý do.';
    if ($ot_date<date('Y-m-d')) $errors[] = 'Không thể đăng ký OT cho ngày đã qua.';

    if (empty($errors)) {
        // Kiểm tra trùng
        $exists = $pdo->prepare("SELECT COUNT(*) FROM hr_overtime WHERE user_id=? AND ot_date=? AND status!='cancelled'");
        $exists->execute([$user['id'], $ot_date]);
        if ($exists->fetchColumn()>0) $errors[] = 'Bạn đã có đơn OT cho ngày này.';
    }

    if (empty($errors)) {
        // Xác định người duyệt: nếu user là accountant/manager → phải director duyệt
        // Nếu là employee/driver → manager/accountant/director duyệt
        $userRole = $user['role'] ?? '';
        $seniorRoles = ['accountant','manager'];
        if (in_array($userRole, $seniorRoles)) {
            $approverRole = 'director';
        } else {
            $approverRole = null; // Sẽ duyệt bởi bất kỳ manager+
        }

        $pdo->prepare("INSERT INTO hr_overtime (user_id, ot_date, ot_hours, ot_rate, reason, status)
                        VALUES (?,?,?,?,'pending')"    // note: fixed
        );
        $stmt = $pdo->prepare("INSERT INTO hr_overtime (user_id, ot_date, ot_hours, ot_rate, reason, status)
                               VALUES (?,?,?,?,?,'pending')");
        $stmt->execute([$user['id'], $ot_date, $ot_hours, $ot_rate, $reason]);

        // Thông báo cho người duyệt
        if ($approverRole) {
            $approvers = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name='$approverRole' AND u.is_active=TRUE")->fetchAll();
        } else {
            $approvers = $pdo->query("SELECT u.id FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name IN ('director','admin','accountant','manager') AND u.is_active=TRUE")->fetchAll();
        }
        foreach ($approvers as $ap) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'ot_request')")
                ->execute([$ap['id'], '📋 Đơn OT mới', $user['full_name'].' đăng ký OT ngày '.$ot_date.' ('.$ot_hours.'h)']);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã gửi đơn đăng ký OT thành công!'];
        header('Location: request.php'); exit;
    }
}

// Lịch sử đơn OT
$myOTs = [];
try {
    $s = $pdo->prepare("SELECT o.*, u.full_name AS approver_name FROM hr_overtime o
                         LEFT JOIN users u ON o.approved_by=u.id
                         WHERE o.user_id=? ORDER BY o.created_at DESC LIMIT 30");
    $s->execute([$user['id']]);
    $myOTs = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Tổng OT tháng này
$totalOTMonth = 0;
try {
    $s = $pdo->prepare("SELECT COALESCE(SUM(ot_hours),0) FROM hr_overtime
                         WHERE user_id=? AND status='approved'
                           AND EXTRACT(MONTH FROM ot_date)=EXTRACT(MONTH FROM CURRENT_DATE)
                           AND EXTRACT(YEAR FROM ot_date)=EXTRACT(YEAR FROM CURRENT_DATE)");
    $s->execute([$user['id']]);
    $totalOTMonth = (float)$s->fetchColumn();
} catch(Exception $e) {}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">⏱️ Đăng ký Tăng ca (OT)</h4>
        <small class="text-muted"><?= htmlspecialchars($user['full_name']) ?></small>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-list me-1"></i>Danh sách OT
    </a>
</div>

<?php showFlash(); ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <!-- Thống kê nhanh -->
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-3 fw-bold text-warning"><?= number_format($totalOTMonth,1) ?></div>
                    <div class="small text-muted">Giờ OT tháng <?= date('m') ?></div>
                </div>
            </div>
            <div class="col-6">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?= count(array_filter($myOTs,fn($o)=>$o['status']==='pending')) ?></div>
                    <div class="small text-muted">Đơn chờ duyệt</div>
                </div>
            </div>
        </div>

        <!-- Form tạo đơn -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">➕ Tạo đơn đăng ký OT</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">📅 Ngày tăng ca <span class="text-danger">*</span></label>
                        <input type="date" name="ot_date" class="form-control"
                               value="<?= $_POST['ot_date'] ?? date('Y-m-d') ?>"
                               min="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">⏱️ Số giờ OT <span class="text-danger">*</span></label>
                            <input type="number" name="ot_hours" class="form-control"
                                   value="<?= $_POST['ot_hours'] ?? 2 ?>"
                                   min="0.5" max="12" step="0.5" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">💰 Hệ số</label>
                            <select name="ot_rate" class="form-select">
                                <option value="1.5" <?= ($_POST['ot_rate']??'1.5')==='1.5'?'selected':'' ?>>1.5x — Ngày thường</option>
                                <option value="2.0" <?= ($_POST['ot_rate']??'')==='2.0'?'selected':'' ?>>2.0x — Cuối tuần</option>
                                <option value="3.0" <?= ($_POST['ot_rate']??'')==='3.0'?'selected':'' ?>>3.0x — Ngày lễ</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">📝 Lý do <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Mô tả công việc cần làm thêm giờ..."><?= htmlspecialchars($_POST['reason']??'') ?></textarea>
                    </div>
                    <?php
                    $userRole = $user['role']??'';
                    $needDirector = in_array($userRole,['accountant','manager']);
                    if ($needDirector): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Với role <strong><?= htmlspecialchars($userRole) ?></strong>, đơn OT của bạn sẽ được <strong>Giám đốc</strong> duyệt.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-paper-plane me-2"></i>Gửi đơn OT
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span>📋 Lịch sử đơn OT</span>
                <small class="text-muted fw-normal"><?= count($myOTs) ?> đơn</small>
            </div>
            <div class="card-body p-0">
            <?php if (empty($myOTs)): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-clock fa-3x mb-3 d-block opacity-25"></i>Chưa có đơn OT
            </div>
            <?php else: ?>
            <?php foreach($myOTs as $ot):
                $stBadge = ['pending'=>['warning','⌛ Chờ duyệt'],'approved'=>['success','✅ Đã duyệt'],'rejected'=>['danger','❌ Từ chối'],'cancelled'=>['secondary','🚫 Đã huỷ']];
                [$stColor,$stLabel] = $stBadge[$ot['status']] ?? ['secondary',$ot['status']];
            ?>
            <div class="p-3 border-bottom">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="fw-bold">📅 <?= date('d/m/Y',strtotime($ot['ot_date'])) ?></span>
                        <span class="ms-2 text-muted small"><?= $ot['ot_hours'] ?>h · x<?= $ot['ot_rate'] ?></span>
                    </div>
                    <span class="badge bg-<?= $stColor ?> text-<?= $stColor==='warning'?'dark':'white' ?>"><?= $stLabel ?></span>
                </div>
                <div class="small text-muted mb-1"><i class="fas fa-comment me-1"></i><?= htmlspecialchars($ot['reason']) ?></div>
                <?php if ($ot['status']==='approved' && $ot['approver_name']): ?>
                <div class="small text-success"><i class="fas fa-check-circle me-1"></i>Duyệt bởi: <?= htmlspecialchars($ot['approver_name']) ?></div>
                <?php elseif ($ot['status']==='rejected'): ?>
                <div class="small text-danger"><i class="fas fa-times-circle me-1"></i><?= htmlspecialchars($ot['reject_reason']??'Bị từ chối') ?></div>
                <?php endif; ?>
                <?php if ($ot['status']==='pending'): ?>
                <form method="POST" class="mt-2 d-inline">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
                    <button class="btn btn-xs btn-outline-danger"
                            onclick="return confirm('Huỷ đơn OT ngày <?= date('d/m/Y',strtotime($ot['ot_date'])) ?>?')">
                        <i class="fas fa-times me-1"></i>Huỷ đơn
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<style>.btn-xs{padding:2px 10px;font-size:12px;}</style>
<?php include '../../includes/footer.php'; ?>