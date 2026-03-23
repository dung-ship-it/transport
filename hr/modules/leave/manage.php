<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Duyệt nghỉ phép';

// ── Xử lý duyệt / từ chối ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action        = $_POST['action']        ?? '';
    $leave_id      = (int)$_POST['leave_id'];
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if ($action === 'approve') {
        $pdo->prepare("
            UPDATE hr_leaves
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$user['id'], $leave_id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã duyệt đơn nghỉ phép.'];

    } elseif ($action === 'reject') {
        if (empty($reject_reason)) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Vui lòng nhập lý do từ chối.'];
        } else {
            $pdo->prepare("
                UPDATE hr_leaves
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), note = ?
                WHERE id = ? AND status = 'pending'
            ")->execute([$user['id'], $reject_reason, $leave_id]);
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => '⚠️ Đã từ chối đơn nghỉ phép.'];
        }
    }

    header('Location: manage.php?' . http_build_query($_GET));
    exit;
}

// ── Bộ lọc ───────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'pending';
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));

// ── Danh sách đơn nghỉ ───────────────────────────────────────
$requests = [];
try {
    $sql    = "
        SELECT l.*,
               u.full_name, u.employee_code,
               a.full_name AS approver_name
        FROM hr_leaves l
        JOIN users u ON l.user_id = u.id
        LEFT JOIN users a ON l.approved_by = a.id
        WHERE (EXTRACT(MONTH FROM l.date_from) = ? AND EXTRACT(YEAR FROM l.date_from) = ?)
           OR (EXTRACT(MONTH FROM l.date_to)   = ? AND EXTRACT(YEAR FROM l.date_to)   = ?)
    ";
    $params = [$filterMonth, $filterYear, $filterMonth, $filterYear];

    if ($filterStatus !== 'all') {
        $sql    .= " AND l.status = ?";
        $params[] = $filterStatus;
    }
    $sql .= " ORDER BY l.date_from DESC, l.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Thống kê ─────────────────────────────────────────────────
$stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total_days' => 0];
try {
    $st = $pdo->prepare("
        SELECT status, COUNT(*) AS cnt, COALESCE(SUM(days_count), 0) AS days
        FROM hr_leaves
        WHERE (EXTRACT(MONTH FROM date_from) = ? AND EXTRACT(YEAR FROM date_from) = ?)
           OR (EXTRACT(MONTH FROM date_to)   = ? AND EXTRACT(YEAR FROM date_to)   = ?)
        GROUP BY status
    ");
    $st->execute([$filterMonth, $filterYear, $filterMonth, $filterYear]);
    foreach ($st->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['cnt'];
        if ($row['status'] === 'approved')
            $stats['total_days'] = (int)$row['days'];
    }
} catch (Exception $e) {}

$leaveTypeLabel = [
    'annual'    => '🏖️ Phép năm',
    'sick'      => '🤒 Nghỉ ốm',
    'unpaid'    => '💼 Không lương',
    'maternity' => '🍼 Thai sản',
    'other'     => '📋 Lý do khác',
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
        <h4 class="fw-bold mb-0">📋 Duyệt nghỉ phép</h4>
        <small class="text-muted">Tháng <?= $filterMonth ?>/<?= $filterYear ?></small>
    </div>
    <a href="request.php" class="btn btn-sm btn-outline-warning">
        <i class="fas fa-plus me-1"></i>Xin nghỉ của tôi
    </a>
</div>

<?php showFlash(); ?>

<!-- Thống kê -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= $stats['pending'] ?></div>
            <div class="small text-muted">⌛ Chờ duyệt</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= $stats['approved'] ?></div>
            <div class="small text-muted">✅ Đã duyệt</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-danger"><?= $stats['rejected'] ?></div>
            <div class="small text-muted">❌ Từ chối</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-info"><?= $stats['total_days'] ?></div>
            <div class="small text-muted">📅 Tổng ngày đã duyệt</div>
        </div>
    </div>
</div>

<!-- Bộ lọc -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Tháng</label>
        <select name="month" class="form-select form-select-sm">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $filterMonth ? 'selected' : '' ?>>Tháng <?= $m ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Năm</label>
        <select name="year" class="form-select form-select-sm">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
            <option value="<?= $y ?>" <?= $y == $filterYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Trạng thái</label>
        <select name="status" class="form-select form-select-sm">
            <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>⌛ Chờ duyệt</option>
            <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>✅ Đã duyệt</option>
            <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>❌ Từ chối</option>
            <option value="all"      <?= $filterStatus === 'all'      ? 'selected' : '' ?>>Tất cả</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Lọc</button>
        <a href="manage.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
</form>
</div>
</div>

<!-- Bảng đơn nghỉ -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-bold">
            📋 Danh sách đơn nghỉ phép
            <span class="badge bg-secondary ms-1"><?= count($requests) ?></span>
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-calendar-times fa-3x mb-3 d-block opacity-25"></i>
            Không có đơn nghỉ phép nào
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                <thead class="table-light">
                    <tr>
                        <th>Nhân viên</th>
                        <th>Loại phép</th>
                        <th>Từ ngày</th>
                        <th>Đến ngày</th>
                        <th class="text-center">Số ngày</th>
                        <th>Lý do</th>
                        <th>Trạng thái</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $lv):
                    $st = $statusLabel[$lv['status']] ?? ['?', 'secondary'];
                ?>
                <tr class="<?= $lv['status'] === 'rejected' ? 'opacity-50' : '' ?>">
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($lv['full_name']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($lv['employee_code'] ?? '') ?></div>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <?= $leaveTypeLabel[$lv['leave_type']] ?? htmlspecialchars($lv['leave_type']) ?>
                        </span>
                    </td>
                    <td><?= date('d/m/Y', strtotime($lv['date_from'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($lv['date_to'])) ?></td>
                    <td class="text-center fw-bold text-primary"><?= $lv['days_count'] ?></td>
                    <td>
                        <small class="text-muted" title="<?= htmlspecialchars($lv['reason']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($lv['reason'], 0, 40, '...')) ?>
                        </small>
                    </td>
                    <td>
                        <span class="badge bg-<?= $st[1] ?> text-<?= $st[1] === 'warning' ? 'dark' : 'white' ?>">
                            <?= $st[0] ?>
                        </span>
                        <?php if ($lv['status'] !== 'pending' && !empty($lv['approver_name'])): ?>
                        <div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($lv['approver_name']) ?></div>
                        <?php endif; ?>
                        <?php if ($lv['status'] === 'rejected' && !empty($lv['note'])): ?>
                        <div class="text-danger" style="font-size:10px;"
                             title="<?= htmlspecialchars($lv['note']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($lv['note'], 0, 25, '...')) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($lv['status'] === 'pending'): ?>
                        <div class="d-flex gap-1 justify-content-center">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action"    value="approve">
                                <input type="hidden" name="leave_id"  value="<?= $lv['id'] ?>">
                                <button class="btn btn-xs btn-success"
                                        onclick="return confirm('Duyệt đơn nghỉ của <?= htmlspecialchars($lv['full_name']) ?>?')"
                                        title="Duyệt">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                            <button class="btn btn-xs btn-danger"
                                    onclick="showRejectModal(<?= $lv['id'] ?>, '<?= htmlspecialchars(addslashes($lv['full_name'])) ?>')"
                                    title="Từ chối">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
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

<!-- Modal từ chối -->
<div class="modal fade" id="rejectModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <form method="POST" id="rejectForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="reject">
        <input type="hidden" name="leave_id"   id="rejectLeaveId">
        <div class="modal-header border-0">
            <h6 class="modal-title">❌ Từ chối đơn nghỉ của <strong id="rejectEmpName"></strong></h6>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
            <textarea name="reject_reason" class="form-control" rows="3" required
                      placeholder="Nhập lý do từ chối..."></textarea>
        </div>
        <div class="modal-footer border-0">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
            <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
        </div>
    </form>
</div>
</div>
</div>

<style>
.btn-xs { padding: 3px 10px; font-size: 12px; }
</style>

<script>
function showRejectModal(id, name) {
    document.getElementById('rejectLeaveId').value       = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.querySelector('#rejectForm textarea').value  = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>

<?php include '../../../includes/footer.php'; ?>