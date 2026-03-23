<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Duyệt tăng ca (OT)';

// ── Xử lý duyệt / từ chối ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action        = $_POST['action'] ?? '';
    $ot_id         = (int)$_POST['ot_id'];
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if ($action === 'approve') {
        $pdo->prepare("
            UPDATE hr_overtime
            SET status = 'approved', approved_by = ?, approved_at = NOW()
            WHERE id = ? AND status = 'pending'
        ")->execute([$user['id'], $ot_id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã duyệt đơn OT.'];

    } elseif ($action === 'reject') {
        if (empty($reject_reason)) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Vui lòng nhập lý do từ chối.'];
        } else {
            $pdo->prepare("
                UPDATE hr_overtime
                SET status = 'rejected', approved_by = ?, approved_at = NOW(), note = ?
                WHERE id = ? AND status = 'pending'
            ")->execute([$user['id'], $reject_reason, $ot_id]);
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => '⚠️ Đã từ chối đơn OT.'];
        }

    } elseif ($action === 'bulk_approve') {
        $ids   = array_map('intval', $_POST['selected_ids'] ?? []);
        $count = 0;
        foreach ($ids as $id) {
            $stmt = $pdo->prepare("
                UPDATE hr_overtime SET status='approved', approved_by=?, approved_at=NOW()
                WHERE id=? AND status='pending'
            ");
            $stmt->execute([$user['id'], $id]);
            $count += $stmt->rowCount();
        }
        $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ Đã duyệt <strong>$count</strong> đơn OT."];
    }

    header('Location: manage.php?' . http_build_query($_GET));
    exit;
}

// ── Bộ lọc ───────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'pending';
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));

// ── Danh sách đơn OT ─────────────────────────────────────────
$sql    = "
    SELECT o.*,
           u.full_name, u.employee_code,
           a.full_name AS approver_name
    FROM hr_overtime o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN users a ON o.approved_by = a.id
    WHERE EXTRACT(MONTH FROM o.ot_date) = ?
      AND EXTRACT(YEAR  FROM o.ot_date) = ?
";
$params = [$filterMonth, $filterYear];

if ($filterStatus !== 'all') {
    $sql    .= " AND o.status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY o.ot_date DESC, o.created_at DESC";

$requests = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Thống kê tháng ───────────────────────────────────────────
$stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'total_hours' => 0];
try {
    $st = $pdo->prepare("
        SELECT status, COUNT(*) AS cnt,
               COALESCE(SUM(ot_hours), 0) AS hours
        FROM hr_overtime
        WHERE EXTRACT(MONTH FROM ot_date) = ?
          AND EXTRACT(YEAR  FROM ot_date) = ?
        GROUP BY status
    ");
    $st->execute([$filterMonth, $filterYear]);
    foreach ($st->fetchAll() as $row) {
        $stats[$row['status']] = (int)$row['cnt'];
        if ($row['status'] === 'approved')
            $stats['total_hours'] = (float)$row['hours'];
    }
} catch (Exception $e) {}

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
        <h4 class="fw-bold mb-0">✅ Duyệt tăng ca (OT)</h4>
        <small class="text-muted">Tháng <?= $filterMonth ?>/<?= $filterYear ?></small>
    </div>
    <a href="request.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-plus me-1"></i>Đăng ký OT của tôi
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
            <div class="fs-2 fw-bold text-primary"><?= number_format($stats['total_hours'], 1) ?></div>
            <div class="small text-muted">⏱️ Tổng giờ OT</div>
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

<!-- Danh sách đơn -->
<form method="POST" id="bulkForm">
<input type="hidden" name="csrf_token" value="<?= $csrf ?>">
<input type="hidden" name="action"     value="bulk_approve">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
        <span class="fw-bold">
            📋 Danh sách đơn OT
            <span class="badge bg-secondary ms-1"><?= count($requests) ?></span>
        </span>
        <?php if ($filterStatus === 'pending' && !empty($requests)): ?>
        <div class="d-flex gap-2 align-items-center">
            <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="selectAll"
                       onchange="document.querySelectorAll('.ot-check').forEach(cb=>cb.checked=this.checked);updateBulkBtn()">
                <label class="form-check-label small" for="selectAll">Chọn tất cả</label>
            </div>
            <button type="submit" class="btn btn-success btn-sm" id="bulkBtn" disabled
                    onclick="return confirm('Duyệt tất cả đơn đã chọn?')">
                <i class="fas fa-check-double me-1"></i>Duyệt hàng loạt
                <span id="bulkCount" class="badge bg-white text-success ms-1">0</span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <div class="card-body p-0">
        <?php if (empty($requests)): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-clipboard-check fa-3x mb-3 d-block opacity-25"></i>
            Không có đơn OT nào
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                <thead class="table-light">
                    <tr>
                        <?php if ($filterStatus === 'pending'): ?><th width="40"></th><?php endif; ?>
                        <th>Nhân viên</th>
                        <th>Ngày OT</th>
                        <th class="text-center">Số giờ</th>
                        <th class="text-center">Hệ số</th>
                        <th>Lý do</th>
                        <th>Trạng thái</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $ot):
                    $st = $statusLabel[$ot['status']] ?? ['?', 'secondary'];
                ?>
                <tr class="<?= $ot['status'] === 'rejected' ? 'opacity-50' : '' ?>">
                    <?php if ($filterStatus === 'pending'): ?>
                    <td>
                        <?php if ($ot['status'] === 'pending'): ?>
                        <input type="checkbox" name="selected_ids[]" value="<?= $ot['id'] ?>"
                               class="form-check-input ot-check" onchange="updateBulkBtn()">
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($ot['full_name']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($ot['employee_code'] ?? '') ?></div>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= date('d/m/Y', strtotime($ot['ot_date'])) ?></div>
                        <div class="text-muted small"><?= date('l', strtotime($ot['ot_date'])) ?></div>
                    </td>
                    <td class="text-center fw-bold text-primary"><?= number_format((float)$ot['ot_hours'], 1) ?>h</td>
                    <td class="text-center">
                        <span class="badge bg-<?= $ot['ot_rate'] >= 3 ? 'danger' : ($ot['ot_rate'] >= 2 ? 'warning text-dark' : 'secondary') ?>">
                            x<?= $ot['ot_rate'] ?>
                        </span>
                    </td>
                    <td>
                        <small class="text-muted" title="<?= htmlspecialchars($ot['reason']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($ot['reason'], 0, 40, '...')) ?>
                        </small>
                    </td>
                    <td>
                        <span class="badge bg-<?= $st[1] ?> text-<?= $st[1] === 'warning' ? 'dark' : 'white' ?>">
                            <?= $st[0] ?>
                        </span>
                        <?php if ($ot['status'] !== 'pending' && !empty($ot['approver_name'])): ?>
                        <div class="small text-muted" style="font-size:10px;"><?= htmlspecialchars($ot['approver_name']) ?></div>
                        <?php endif; ?>
                        <?php if ($ot['status'] === 'rejected' && !empty($ot['note'])): ?>
                        <div class="small text-danger" style="font-size:10px;"
                             title="<?= htmlspecialchars($ot['note']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($ot['note'], 0, 25, '...')) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($ot['status'] === 'pending'): ?>
                        <div class="d-flex gap-1 justify-content-center">
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <input type="hidden" name="action"     value="approve">
                                <input type="hidden" name="ot_id"      value="<?= $ot['id'] ?>">
                                <button class="btn btn-xs btn-success"
                                        onclick="return confirm('Duyệt đơn OT của <?= htmlspecialchars($ot['full_name']) ?>?')"
                                        title="Duyệt">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                            <button class="btn btn-xs btn-danger"
                                    onclick="showRejectModal(<?= $ot['id'] ?>, '<?= htmlspecialchars(addslashes($ot['full_name'])) ?>')"
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
</form>

</div>
</div>

<!-- Modal từ chối -->
<div class="modal fade" id="rejectModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <form method="POST" id="rejectForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="reject">
        <input type="hidden" name="ot_id"      id="rejectOtId">
        <div class="modal-header border-0">
            <h6 class="modal-title">❌ Từ chối đơn OT của <strong id="rejectEmpName"></strong></h6>
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
    document.getElementById('rejectOtId').value       = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.querySelector('#rejectForm textarea').value  = '';
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

function updateBulkBtn() {
    const count = document.querySelectorAll('.ot-check:checked').length;
    const btn   = document.getElementById('bulkBtn');
    const cnt   = document.getElementById('bulkCount');
    if (btn) { btn.disabled = count === 0; }
    if (cnt) { cnt.textContent = count; }
    const total = document.querySelectorAll('.ot-check').length;
    const sa    = document.getElementById('selectAll');
    if (sa) {
        sa.indeterminate = count > 0 && count < total;
        sa.checked = count === total && total > 0;
    }
}
</script>

<?php include '../../../includes/footer.php'; ?>