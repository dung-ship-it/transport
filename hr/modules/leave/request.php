<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo    = getDBConnection();
$user   = currentUser();
$pageTitle = 'Xin nghỉ phép';
$errors = [];

// ── Xử lý form ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'create';

    // Huỷ đơn
    if ($action === 'cancel') {
        $leave_id = (int)$_POST['leave_id'];
        $pdo->prepare("
            UPDATE hr_leaves SET status = 'rejected', note = 'Nhân viên tự huỷ'
            WHERE id = ? AND user_id = ? AND status = 'pending'
        ")->execute([$leave_id, $user['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã huỷ đơn nghỉ phép.'];
        header('Location: request.php');
        exit;
    }

    $leave_type = $_POST['leave_type'] ?? 'annual';
    $date_from  = trim($_POST['date_from'] ?? '');
    $date_to    = trim($_POST['date_to']   ?? '');
    $reason     = trim($_POST['reason']    ?? '');

    if (empty($date_from))           $errors[] = 'Vui lòng chọn ngày bắt đầu.';
    if (empty($date_to))             $errors[] = 'Vui lòng chọn ngày kết thúc.';
    if ($date_to < $date_from)       $errors[] = 'Ngày kết thúc phải >= ngày bắt đầu.';
    if (empty($reason))              $errors[] = 'Vui lòng nhập lý do nghỉ.';
    if ($date_from < date('Y-m-d'))  $errors[] = 'Không thể xin nghỉ cho ngày đã qua.';

    if (empty($errors)) {
        // Kiểm tra trùng với đơn đã có
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM hr_leaves
            WHERE user_id = ? AND status != 'rejected'
              AND date_from <= ? AND date_to >= ?
        ");
        $chk->execute([$user['id'], $date_to, $date_from]);
        if ($chk->fetchColumn() > 0)
            $errors[] = 'Bạn đã có đơn nghỉ phép trong khoảng thời gian này.';
    }

    if (empty($errors)) {
        // Tính số ngày (bỏ CN)
        $days = 0;
        $s = strtotime($date_from);
        $e = strtotime($date_to);
        for ($d = $s; $d <= $e; $d += 86400) {
            if (date('N', $d) != 7) $days++; // bỏ CN
        }

        try {
            $pdo->prepare("
                INSERT INTO hr_leaves
                    (user_id, leave_type, date_from, date_to, days_count, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')
            ")->execute([$user['id'], $leave_type, $date_from, $date_to, $days, $reason]);

            // Thông báo người duyệt
            $approvers = $pdo->prepare("
                SELECT u.id FROM users u JOIN roles r ON u.role_id = r.id
                WHERE u.is_active = TRUE AND r.name IN ('admin','director','accountant','manager')
            ");
            $approvers->execute();
            foreach ($approvers->fetchAll() as $ap) {
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, title, message, type, link, created_at)
                        VALUES (?, ?, ?, 'leave_request', '/hr/modules/leave/manage.php', NOW())
                    ")->execute([
                        $ap['id'],
                        '📋 Đơn xin nghỉ phép mới',
                        htmlspecialchars($user['full_name']) . ' xin nghỉ từ '
                            . date('d/m/Y', strtotime($date_from)) . ' đến '
                            . date('d/m/Y', strtotime($date_to))
                            . " ($days ngày)",
                    ]);
                } catch (Exception $e) {}
            }

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ Đã gửi đơn xin nghỉ $days ngày thành công!"];
            header('Location: request.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Lỗi lưu dữ liệu: ' . $e->getMessage();
        }
    }
}

// ── Lịch sử đơn nghỉ ─────────────────────────────────────────
$myLeaves = [];
try {
    $stmt = $pdo->prepare("
        SELECT l.*, u.full_name AS approver_name
        FROM hr_leaves l
        LEFT JOIN users u ON l.approved_by = u.id
        WHERE l.user_id = ?
        ORDER BY l.date_from DESC
        LIMIT 30
    ");
    $stmt->execute([$user['id']]);
    $myLeaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Số ngày phép còn lại (tham khảo) ─────────────────────────
$usedDays = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(days_count), 0)
        FROM hr_leaves
        WHERE user_id = ? AND status = 'approved'
          AND leave_type = 'annual'
          AND EXTRACT(YEAR FROM date_from) = ?
    ");
    $stmt->execute([$user['id'], date('Y')]);
    $usedDays = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$leaveTypeLabel = [
    'annual'   => '🏖️ Phép năm',
    'sick'     => '🤒 Nghỉ ốm',
    'unpaid'   => '💼 Không lương',
    'maternity'=> '🍼 Thai sản',
    'other'    => '📋 Lý do khác',
];
$statusLabel = [
    'pending'  => ['⌛ Chờ duyệt', 'warning'],
    'approved' => ['✅ Đã duyệt',  'success'],
    'rejected' => ['❌ Từ chối',   'danger'],
];

$csrf = generateCSRF();
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📝 Xin nghỉ phép</h4>
        <small class="text-muted"><?= htmlspecialchars($user['full_name']) ?></small>
    </div>
    <a href="../attendance/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Quay lại
    </a>
</div>

<?php showFlash(); ?>

<div class="row g-4">

    <!-- Form tạo đơn -->
    <div class="col-lg-5">

        <!-- Tóm tắt phép năm -->
        <div class="card border-0 shadow-sm mb-3 border-start border-4 border-info">
            <div class="card-body py-3">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="fw-bold fs-4 text-primary">12</div>
                        <div class="small text-muted">Ngày phép/năm</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold fs-4 text-danger"><?= $usedDays ?></div>
                        <div class="small text-muted">Đã dùng</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold fs-4 text-success"><?= max(0, 12 - $usedDays) ?></div>
                        <div class="small text-muted">Còn lại</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-warning text-dark fw-bold py-2">
                ➕ Tạo đơn xin nghỉ phép
            </div>
            <div class="card-body">
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger py-2">
                    <ul class="mb-0 small">
                        <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <form method="POST" id="leaveForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="create">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Loại nghỉ phép <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select" required>
                            <?php foreach ($leaveTypeLabel as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= ($_POST['leave_type'] ?? 'annual') === $val ? 'selected' : '' ?>>
                                <?= $lbl ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Từ ngày <span class="text-danger">*</span></label>
                            <input type="date" name="date_from" class="form-control" id="dateFrom"
                                   value="<?= htmlspecialchars($_POST['date_from'] ?? date('Y-m-d')) ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Đến ngày <span class="text-danger">*</span></label>
                            <input type="date" name="date_to" class="form-control" id="dateTo"
                                   value="<?= htmlspecialchars($_POST['date_to'] ?? date('Y-m-d')) ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <!-- Preview số ngày -->
                    <div class="alert alert-light py-2 mb-3 small" id="daysPreview">
                        <i class="fas fa-calendar me-1 text-primary"></i>
                        Số ngày nghỉ (trừ CN): <strong id="previewDays">0</strong> ngày
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Lý do nghỉ <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Mô tả lý do xin nghỉ..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-warning w-100 fw-bold text-dark">
                        <i class="fas fa-paper-plane me-2"></i>Gửi đơn nghỉ phép
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Lịch sử đơn nghỉ -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                <h6 class="fw-bold mb-0">📋 Lịch sử đơn nghỉ phép</h6>
                <small class="text-muted"><?= count($myLeaves) ?> đơn</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($myLeaves)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
                    Chưa có đơn nghỉ phép nào
                </div>
                <?php else: ?>
                <?php foreach ($myLeaves as $lv):
                    $st = $statusLabel[$lv['status']] ?? ['?', 'secondary'];
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <span class="fw-bold">
                                <?= $leaveTypeLabel[$lv['leave_type']] ?? htmlspecialchars($lv['leave_type']) ?>
                            </span>
                            <span class="badge bg-light text-dark border ms-2">
                                <?= $lv['days_count'] ?> ngày
                            </span>
                        </div>
                        <span class="badge bg-<?= $st[1] ?> text-<?= $st[1] === 'warning' ? 'dark' : 'white' ?>">
                            <?= $st[0] ?>
                        </span>
                    </div>

                    <div class="small text-muted mb-1">
                        <i class="fas fa-calendar me-1"></i>
                        <?= date('d/m/Y', strtotime($lv['date_from'])) ?>
                        <?= $lv['date_from'] !== $lv['date_to']
                            ? ' → ' . date('d/m/Y', strtotime($lv['date_to']))
                            : '' ?>
                    </div>

                    <div class="small text-muted mb-1">
                        <i class="fas fa-comment me-1"></i>
                        <?= htmlspecialchars($lv['reason']) ?>
                    </div>

                    <?php if ($lv['status'] === 'approved' && !empty($lv['approver_name'])): ?>
                    <div class="small text-success">
                        <i class="fas fa-check-circle me-1"></i>
                        Duyệt bởi: <?= htmlspecialchars($lv['approver_name']) ?>
                    </div>
                    <?php elseif ($lv['status'] === 'rejected'): ?>
                    <div class="small text-danger">
                        <i class="fas fa-times-circle me-1"></i>
                        Lý do: <?= htmlspecialchars($lv['note'] ?? 'Không được duyệt') ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($lv['status'] === 'pending'): ?>
                    <form method="POST" class="mt-2 d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action"     value="cancel">
                        <input type="hidden" name="leave_id"   value="<?= $lv['id'] ?>">
                        <button class="btn btn-xs btn-outline-danger"
                                onclick="return confirm('Huỷ đơn nghỉ phép này?')">
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

<style>
.btn-xs { padding: 2px 10px; font-size: 12px; }
</style>

<script>
// Tính số ngày (trừ CN)
function calcDays() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    if (!from || !to || to < from) {
        document.getElementById('previewDays').textContent = '0';
        return;
    }
    let days = 0;
    let d = new Date(from);
    const end = new Date(to);
    while (d <= end) {
        if (d.getDay() !== 0) days++; // bỏ CN
        d.setDate(d.getDate() + 1);
    }
    document.getElementById('previewDays').textContent = days;
}

document.getElementById('dateFrom')?.addEventListener('change', function() {
    // Đảm bảo dateTo >= dateFrom
    const dt = document.getElementById('dateTo');
    if (dt.value < this.value) dt.value = this.value;
    calcDays();
});
document.getElementById('dateTo')?.addEventListener('change', calcDays);
calcDays();
</script>

<?php include '../../../includes/footer.php'; ?>