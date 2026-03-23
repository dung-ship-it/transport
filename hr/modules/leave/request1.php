<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Xin nghỉ phép';

$errors = [];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? 'create';

    // Huỷ đơn
    if ($action==='cancel') {
        $pdo->prepare("UPDATE hr_leaves SET status='cancelled' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([(int)$_POST['leave_id'], $user['id']]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã huỷ đơn nghỉ phép.'];
        header('Location: request.php'); exit;
    }

    $leave_type = $_POST['leave_type'] ?? '';
    $date_from  = $_POST['date_from']  ?? '';
    $date_to    = $_POST['date_to']    ?? '';
    $reason     = trim($_POST['reason'] ?? '');

    if (empty($leave_type)) $errors[] = 'Vui lòng chọn loại nghỉ.';
    if (empty($date_from))  $errors[] = 'Vui lòng chọn ngày bắt đầu.';
    if (empty($date_to))    $errors[] = 'Vui lòng chọn ngày kết thúc.';
    if (!empty($date_from) && !empty($date_to) && $date_to < $date_from) $errors[] = 'Ngày kết thúc phải >= ngày bắt đầu.';
    if (empty($reason))     $errors[] = 'Vui lòng nhập lý do.';

    $daysCount = 0;
    if (empty($errors)) {
        $daysCount = (int)(( strtotime($date_to) - strtotime($date_from)) / 86400) + 1;
        // Kiểm tra trùng
        $overlap = $pdo->prepare("SELECT COUNT(*) FROM hr_leaves WHERE user_id=? AND status!='cancelled'
                                    AND date_from<=? AND date_to>=?");
        $overlap->execute([$user['id'], $date_to, $date_from]);
        if ($overlap->fetchColumn()>0) $errors[] = 'Bạn đã có đơn nghỉ phép trong khoảng thời gian này.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO hr_leaves (user_id, leave_type, date_from, date_to, days_count, reason, status)
                               VALUES (?,?,?,?,?,?,'pending')");
        $stmt->execute([$user['id'], $leave_type, $date_from, $date_to, $daysCount, $reason]);

        // Thông báo cho người duyệt
        $userRole     = $user['role'] ?? '';
        $seniorRoles  = ['accountant','manager'];
        $approverRole = in_array($userRole,$seniorRoles) ? ['director','admin'] : ['director','admin','accountant','manager'];
        $placeholders = implode(',', array_fill(0, count($approverRole), '?'));
        $approvers    = $pdo->prepare("SELECT u.id FROM users u JOIN roles r ON u.role_id=r.id WHERE r.name IN ($placeholders) AND u.is_active=TRUE");
        $approvers->execute($approverRole);
        foreach ($approvers->fetchAll() as $ap) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'leave_request')")
                ->execute([$ap['id'], '📋 Đơn nghỉ phép mới', $user['full_name'].' xin nghỉ '.$daysCount.' ngày ('.$date_from.' → '.$date_to.')']);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã gửi đơn xin nghỉ phép thành công!'];
        header('Location: request.php'); exit;
    }
}

// Lịch sử
$myLeaves = [];
try {
    $s = $pdo->prepare("SELECT l.*, u.full_name AS approver_name FROM hr_leaves l
                         LEFT JOIN users u ON l.approved_by=u.id
                         WHERE l.user_id=? ORDER BY l.created_at DESC LIMIT 20");
    $s->execute([$user['id']]);
    $myLeaves = $s->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">📝 Xin nghỉ phép</h4>
        <small class="text-muted"><?= htmlspecialchars($user['full_name']) ?></small>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-list me-1"></i>Quản lý phép
    </a>
</div>

<?php showFlash(); ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white fw-bold">📝 Tạo đơn xin nghỉ phép</div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Loại nghỉ phép <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select" required>
                            <option value="">-- Chọn --</option>
                            <option value="annual"  <?=($_POST['leave_type']??'')==='annual'  ?'selected':''?>>Nghỉ phép năm</option>
                            <option value="sick"    <?=($_POST['leave_type']??'')==='sick'    ?'selected':''?>>Nghỉ ốm</option>
                            <option value="unpaid"  <?=($_POST['leave_type']??'')==='unpaid'  ?'selected':''?>>Nghỉ không lương</option>
                            <option value="other"   <?=($_POST['leave_type']??'')==='other'   ?'selected':''?>>Lý do khác</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Từ ngày <span class="text-danger">*</span></label>
                            <input type="date" name="date_from" class="form-control"
                                   value="<?= $_POST['date_from']??date('Y-m-d') ?>" required
                                   min="<?= date('Y-m-d') ?>" id="dateFrom" onchange="calcDays()">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Đến ngày <span class="text-danger">*</span></label>
                            <input type="date" name="date_to" class="form-control"
                                   value="<?= $_POST['date_to']??date('Y-m-d') ?>" required
                                   min="<?= date('Y-m-d') ?>" id="dateTo" onchange="calcDays()">
                        </div>
                    </div>
                    <div id="daysPreview" class="alert alert-info py-2 mb-3 d-none small">
                        <i class="fas fa-calendar me-1"></i>Số ngày nghỉ: <strong id="daysCount">0</strong> ngày
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lý do <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="4" required
                                  placeholder="Mô tả lý do xin nghỉ..."><?= htmlspecialchars($_POST['reason']??'') ?></textarea>
                    </div>
                    <?php
                    $userRole = $user['role']??'';
                    if (in_array($userRole,['accountant','manager'])): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>Đơn của bạn sẽ do <strong>Giám đốc</strong> duyệt.
                    </div>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-paper-plane me-2"></i>Gửi đơn
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-bold d-flex justify-content-between">
                <span>📋 Lịch sử đơn nghỉ phép</span>
                <small class="text-muted fw-normal"><?= count($myLeaves) ?> đơn</small>
            </div>
            <div class="card-body p-0">
            <?php if (empty($myLeaves)): ?>
            <div class="text-center text-muted py-5">Chưa có đơn nghỉ phép</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr><th>Loại</th><th>Từ ngày</th><th>Đến ngày</th><th>Số ngày</th><th>Trạng thái</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach($myLeaves as $lv):
                    $ltLabel = ['annual'=>'Phép năm','sick'=>'Nghỉ ốm','unpaid'=>'Không lương','other'=>'Khác'];
                    $stBadge = ['pending'=>['warning','⌛ Chờ'],'approved'=>['success','✅ Duyệt'],'rejected'=>['danger','❌ Từ chối'],'cancelled'=>['secondary','🚫 Huỷ']];
                    [$stColor,$stLabel] = $stBadge[$lv['status']] ?? ['secondary',$lv['status']];
                ?>
                <tr>
                    <td><?= $ltLabel[$lv['leave_type']] ?? $lv['leave_type'] ?></td>
                    <td><?= date('d/m/Y',strtotime($lv['date_from'])) ?></td>
                    <td><?= date('d/m/Y',strtotime($lv['date_to'])) ?></td>
                    <td><?= $lv['days_count'] ?></td>
                    <td>
                        <span class="badge bg-<?=$stColor?> text-<?=$stColor==='warning'?'dark':'white'?>"><?=$stLabel?></span>
                        <?php if($lv['status']==='rejected' && ($lv['reject_reason']??'')): ?>
                        <div class="text-danger" style="font-size:10px;"><?= htmlspecialchars($lv['reject_reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                    <?php if($lv['status']==='pending'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="leave_id" value="<?=$lv['id']?>">
                        <button class="btn btn-xs btn-outline-danger" onclick="return confirm('Huỷ đơn này?')">Huỷ</button>
                    </form>
                    <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>
</div>

<style>.btn-xs{padding:2px 10px;font-size:12px;}</style>
<script>
function calcDays() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    if (from && to && to >= from) {
        const days = Math.round((new Date(to) - new Date(from)) / 86400000) + 1;
        document.getElementById('daysCount').textContent = days;
        document.getElementById('daysPreview').classList.remove('d-none');
    }
}
calcDays();
</script>
<?php include '../../includes/footer.php'; ?>