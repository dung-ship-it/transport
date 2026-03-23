<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Đăng ký tăng ca (OT)';
$errors = [];

// ── Xử lý form ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? 'create';

    // Huỷ đơn pending
    if ($action === 'cancel') {
        $ot_id = (int)$_POST['ot_id'];
        $pdo->prepare("
            UPDATE hr_overtime SET status = 'rejected', note = 'Nhân viên tự huỷ'
            WHERE id = ? AND user_id = ? AND status = 'pending'
        ")->execute([$ot_id, $user['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã huỷ đơn OT.'];
        header('Location: request.php');
        exit;
    }

    // Tạo mới
    $ot_date  = trim($_POST['ot_date']  ?? '');
    $ot_hours = (float)($_POST['ot_hours'] ?? 0);
    $ot_rate  = (float)($_POST['ot_rate']  ?? 1.5);
    $reason   = trim($_POST['reason']   ?? '');

    if (empty($ot_date))           $errors[] = 'Vui lòng chọn ngày OT.';
    if ($ot_hours <= 0)            $errors[] = 'Số giờ OT phải lớn hơn 0.';
    if ($ot_hours > 12)            $errors[] = 'OT không vượt quá 12 giờ/ngày.';
    if (empty($reason))            $errors[] = 'Vui lòng nhập lý do OT.';
    if ($ot_date < date('Y-m-d'))  $errors[] = 'Không thể đăng ký OT cho ngày đã qua.';

    // Kiểm tra đã có đơn OT ngày này chưa
    if (empty($errors)) {
        $chk = $pdo->prepare("
            SELECT COUNT(*) FROM hr_overtime
            WHERE user_id = ? AND ot_date = ? AND status != 'rejected'
        ");
        $chk->execute([$user['id'], $ot_date]);
        if ($chk->fetchColumn() > 0)
            $errors[] = 'Bạn đã có đơn OT cho ngày này rồi.';
    }

    if (empty($errors)) {
        try {
            $pdo->prepare("
                INSERT INTO hr_overtime (user_id, ot_date, ot_hours, ot_rate, reason, status)
                VALUES (?, ?, ?, ?, ?, 'pending')
            ")->execute([$user['id'], $ot_date, $ot_hours, $ot_rate, $reason]);

            // Thông báo cho người duyệt
            $approvers = $pdo->prepare("
                SELECT u.id FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.is_active = TRUE
                  AND r.name IN ('admin', 'director', 'accountant', 'manager')
            ");
            $approvers->execute();
            foreach ($approvers->fetchAll() as $ap) {
                try {
                    $pdo->prepare("
                        INSERT INTO notifications (user_id, title, message, type, link, created_at)
                        VALUES (?, ?, ?, 'ot_request', '/transport/hr/modules/overtime/manage.php', NOW())
                    ")->execute([
                        $ap['id'],
                        '📋 Đơn đăng ký OT mới',
                        htmlspecialchars($user['full_name']) . ' đăng ký OT ngày '
                            . date('d/m/Y', strtotime($ot_date))
                            . ' (' . $ot_hours . ' giờ)',
                    ]);
                } catch (Exception $e) { /* bảng notifications có thể khác schema */ }
            }

            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã gửi đơn đăng ký OT thành công!'];
            header('Location: request.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Lỗi lưu dữ liệu: ' . $e->getMessage();
        }
    }
}

// ── Lịch sử đơn OT của user ─────────────────────────────────
$myOTs = [];
try {
    $stmt = $pdo->prepare("
        SELECT o.*, u.full_name AS approver_name
        FROM hr_overtime o
        LEFT JOIN users u ON o.approved_by = u.id
        WHERE o.user_id = ?
        ORDER BY o.ot_date DESC
        LIMIT 30
    ");
    $stmt->execute([$user['id']]);
    $myOTs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Tổng OT tháng này ────────────────────────────────────────
$totalOTHours = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(ot_hours), 0)
        FROM hr_overtime
        WHERE user_id = ? AND status = 'approved'
          AND EXTRACT(MONTH FROM ot_date) = ?
          AND EXTRACT(YEAR  FROM ot_date) = ?
    ");
    $stmt->execute([$user['id'], date('m'), date('Y')]);
    $totalOTHours = (float)$stmt->fetchColumn();
} catch (Exception $e) {}

$pendingCount = count(array_filter($myOTs, fn($o) => $o['status'] === 'pending'));

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
        <h4 class="fw-bold mb-0">⏱️ Đăng ký tăng ca (OT)</h4>
        <small class="text-muted"><?= htmlspecialchars($user['full_name']) ?></small>
    </div>
    <a href="../attendance/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Quay lại
    </a>
</div>

<?php showFlash(); ?>

<div class="row g-4">

    <!-- ── Form đăng ký ── -->
    <div class="col-lg-5">

        <!-- Thống kê nhanh -->
        <div class="row g-2 mb-3">
            <div class="col-6">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-3 fw-bold text-warning"><?= number_format($totalOTHours, 1) ?></div>
                    <div class="small text-muted">Giờ OT tháng <?= date('m') ?></div>
                </div>
            </div>
            <div class="col-6">
                <div class="card border-0 shadow-sm text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?= $pendingCount ?></div>
                    <div class="small text-muted">Đơn chờ duyệt</div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-primary text-white fw-bold py-2">
                ➕ Tạo đơn đăng ký OT
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

                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="create">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">📅 Ngày tăng ca <span class="text-danger">*</span></label>
                        <input type="date" name="ot_date" class="form-control"
                               value="<?= htmlspecialchars($_POST['ot_date'] ?? date('Y-m-d')) ?>"
                               min="<?= date('Y-m-d') ?>" required>
                        <div id="dayTypeBadge" class="mt-1"></div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">⏰ Số giờ OT <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="ot_hours" class="form-control"
                                       value="<?= htmlspecialchars($_POST['ot_hours'] ?? '2') ?>"
                                       min="0.5" max="12" step="0.5" required id="otHours">
                                <span class="input-group-text">giờ</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">💰 Hệ số lương</label>
                            <select name="ot_rate" class="form-select" id="otRate">
                                <option value="1.5" <?= ($_POST['ot_rate'] ?? '1.5') == '1.5' ? 'selected' : '' ?>>x1.5 — Ngày thường</option>
                                <option value="2.0" <?= ($_POST['ot_rate'] ?? '') == '2.0' ? 'selected' : '' ?>>x2.0 — Cuối tuần</option>
                                <option value="3.0" <?= ($_POST['ot_rate'] ?? '') == '3.0' ? 'selected' : '' ?>>x3.0 — Ngày lễ</option>
                            </select>
                        </div>
                    </div>

                    <!-- Preview -->
                    <div class="alert alert-info py-2 mb-3 small" id="otPreview">
                        <div class="d-flex justify-content-between">
                            <span>⏱️ Số giờ OT:</span>
                            <strong id="previewHours">2 giờ</strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>💰 Hệ số:</span>
                            <strong id="previewRate" class="text-success">x1.5</strong>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">📝 Lý do tăng ca <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" required
                                  placeholder="Mô tả công việc cần làm thêm giờ..."><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-paper-plane me-2"></i>Gửi đơn OT
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Lịch sử đơn OT ── -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                <h6 class="fw-bold mb-0">📋 Lịch sử đơn OT của tôi</h6>
                <small class="text-muted"><?= count($myOTs) ?> đơn</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($myOTs)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-clock fa-3x mb-3 d-block opacity-25"></i>
                    Chưa có đơn OT nào
                </div>
                <?php else: ?>
                <?php foreach ($myOTs as $ot):
                    $st = $statusLabel[$ot['status']] ?? ['?', 'secondary'];
                ?>
                <div class="p-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div>
                            <span class="fw-bold">📅 <?= date('d/m/Y', strtotime($ot['ot_date'])) ?></span>
                            <span class="ms-1 text-muted small">(<?= date('l', strtotime($ot['ot_date'])) ?>)</span>
                        </div>
                        <span class="badge bg-<?= $st[1] ?> text-<?= $st[1] === 'warning' ? 'dark' : 'white' ?>">
                            <?= $st[0] ?>
                        </span>
                    </div>

                    <div class="d-flex flex-wrap gap-3 mb-1 small">
                        <span>
                            <i class="fas fa-hourglass-half text-primary me-1"></i>
                            <strong><?= number_format((float)$ot['ot_hours'], 1) ?> giờ</strong>
                        </span>
                        <span>
                            <i class="fas fa-coins text-warning me-1"></i>
                            Hệ số x<?= $ot['ot_rate'] ?>
                        </span>
                    </div>

                    <div class="small text-muted mb-1">
                        <i class="fas fa-comment me-1"></i><?= htmlspecialchars($ot['reason']) ?>
                    </div>

                    <?php if ($ot['status'] === 'approved' && !empty($ot['approver_name'])): ?>
                    <div class="small text-success">
                        <i class="fas fa-check-circle me-1"></i>
                        Duyệt bởi: <?= htmlspecialchars($ot['approver_name']) ?>
                    </div>
                    <?php elseif ($ot['status'] === 'rejected'): ?>
                    <div class="small text-danger">
                        <i class="fas fa-times-circle me-1"></i>
                        Lý do: <?= htmlspecialchars($ot['note'] ?? 'Không được duyệt') ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($ot['status'] === 'pending'): ?>
                    <form method="POST" class="mt-2 d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action"     value="cancel">
                        <input type="hidden" name="ot_id"      value="<?= $ot['id'] ?>">
                        <button class="btn btn-xs btn-outline-danger"
                                onclick="return confirm('Huỷ đơn OT ngày <?= date('d/m/Y', strtotime($ot['ot_date'])) ?>?')">
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
// Tự động gợi ý hệ số theo ngày
document.querySelector('[name="ot_date"]')?.addEventListener('change', function() {
    const d    = new Date(this.value);
    const dow  = d.getDay(); // 0=Sun, 6=Sat
    const sel  = document.getElementById('otRate');
    const badge = document.getElementById('dayTypeBadge');

    if (dow === 0 || dow === 6) {
        sel.value = '2.0';
        badge.innerHTML = '<span class="badge bg-warning text-dark">🌤️ Cuối tuần — hệ số x2.0</span>';
    } else {
        sel.value = '1.5';
        badge.innerHTML = '<span class="badge bg-secondary">📋 Ngày thường — hệ số x1.5</span>';
    }
    updatePreview();
});

function updatePreview() {
    const hours = document.getElementById('otHours').value;
    const rate  = document.getElementById('otRate').value;
    document.getElementById('previewHours').textContent = hours + ' giờ';
    document.getElementById('previewRate').textContent  = 'x' + rate;
}

document.getElementById('otHours')?.addEventListener('input',  updatePreview);
document.getElementById('otRate') ?.addEventListener('change', updatePreview);
updatePreview();
</script>

<?php include '../../../includes/footer.php'; ?>