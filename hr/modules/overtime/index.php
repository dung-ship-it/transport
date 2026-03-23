<?php
// Quản lý và duyệt OT — dành cho manager+
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();

$userRole   = $user['role'] ?? '';
$isDirector = in_array($userRole, ['director','admin']);
$canApprove = $isDirector || in_array($userRole, ['accountant','manager']);

if (!$canApprove) {
    header('Location: request.php'); exit;
}

$pageTitle = 'Duyệt tăng ca HR';

// Xử lý duyệt / từ chối
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action        = $_POST['action'] ?? '';
    $ot_id         = (int)($_POST['ot_id'] ?? 0);
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if ($action==='approve' && $ot_id) {
        // Kiểm tra: nếu người tạo là accountant/manager → chỉ director được duyệt
        $otInfo = $pdo->prepare("SELECT o.*, r.name AS user_role FROM hr_overtime o JOIN users u ON o.user_id=u.id JOIN roles r ON u.role_id=r.id WHERE o.id=?");
        $otInfo->execute([$ot_id]);
        $otData = $otInfo->fetch(PDO::FETCH_ASSOC);
        if ($otData && in_array($otData['user_role'],['accountant','manager']) && !$isDirector) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Chỉ Giám đốc mới được duyệt OT của kế toán/quản lý.'];
        } else {
            $pdo->prepare("UPDATE hr_overtime SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'")
                ->execute([$user['id'], $ot_id]);
            if ($otData) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'ot_approved')")
                    ->execute([$otData['user_id'], '✅ Đơn OT được duyệt', 'Đơn OT ngày '.$otData['ot_date'].' ('.$otData['ot_hours'].'h) đã được duyệt.']);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã duyệt đơn OT.'];
        }
    } elseif ($action==='reject' && $ot_id) {
        if (empty($reject_reason)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Vui lòng nhập lý do từ chối.'];
        } else {
            $otInfo = $pdo->prepare("SELECT * FROM hr_overtime WHERE id=?");
            $otInfo->execute([$ot_id]);
            $otData = $otInfo->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE hr_overtime SET status='rejected', approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=? AND status='pending'")
                ->execute([$user['id'], $reject_reason, $ot_id]);
            if ($otData) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'ot_rejected')")
                    ->execute([$otData['user_id'], '❌ Đơn OT bị từ chối', 'Lý do: '.$reject_reason]);
            }
            $_SESSION['flash'] = ['type'=>'warning','msg'=>'⚠️ Đã từ chối đơn OT.'];
        }
    }
    header('Location: index.php?'.http_build_query($_GET)); exit;
}

$filterStatus = $_GET['status'] ?? 'pending';
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));

// Query — director thấy tất cả, accountant/manager chỉ thấy đơn của employee/driver
$sql = "SELECT o.*, u.full_name, u.employee_code, r.name AS user_role,
               a.full_name AS approver_name
        FROM hr_overtime o
        JOIN users u ON o.user_id=u.id
        JOIN roles r ON u.role_id=r.id
        LEFT JOIN users a ON o.approved_by=a.id
        WHERE EXTRACT(MONTH FROM o.ot_date)=? AND EXTRACT(YEAR FROM o.ot_date)=?";
$params = [$filterMonth, $filterYear];

if (!$isDirector) {
    // Accountant/manager chỉ duyệt được đơn của nhân viên thường (không phải accountant/manager khác)
    $sql .= " AND r.name NOT IN ('accountant','manager','director','admin')";
}
if ($filterStatus!=='all') { $sql .= " AND o.status=?"; $params[] = $filterStatus; }
$sql .= " ORDER BY o.ot_date DESC";

$requests = [];
try { $s=$pdo->prepare($sql);$s->execute($params);$requests=$s->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $e) { error_log($e->getMessage()); }

// Thống kê
$statsData = [];
try {
    $ss = $pdo->prepare("SELECT COUNT(*) FILTER(WHERE status='pending') AS pending,
                                COUNT(*) FILTER(WHERE status='approved') AS approved,
                                COALESCE(SUM(ot_hours) FILTER(WHERE status='approved'),0) AS total_hours
                         FROM hr_overtime
                         WHERE EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?");
    $ss->execute([$filterMonth, $filterYear]);
    $statsData = $ss->fetch(PDO::FETCH_ASSOC) ?: [];
} catch(Exception $e) {}

$csrf = generateCSRF();
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">✅ Duyệt tăng ca (OT)</h4>
        <small class="text-muted">Tháng <?=$filterMonth?>/<?=$filterYear?></small>
    </div>
    <a href="request.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-plus me-1"></i>Đăng ký OT của tôi
    </a>
</div>

<?php showFlash(); ?>

<!-- Thống kê -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= $statsData['pending']??0 ?></div>
            <div class="small text-muted">⌛ Chờ duyệt</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= $statsData['approved']??0 ?></div>
            <div class="small text-muted">✅ Đã duyệt</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= number_format((float)($statsData['total_hours']??0),1) ?></div>
            <div class="small text-muted">⏱️ Tổng giờ</div>
        </div>
    </div>
</div>

<!-- Bộ lọc -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Tháng</label>
        <select name="month" class="form-select form-select-sm">
            <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$filterMonth?'selected':''?>>Tháng <?=$m?></option><?php endfor; ?>
        </select>
    </div>
    <div class="col-md-1"><label class="form-label small fw-semibold mb-1">Năm</label>
        <select name="year" class="form-select form-select-sm">
            <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$filterYear?'selected':''?>><?=$y?></option><?php endfor; ?>
        </select>
    </div>
    <div class="col-md-2"><label class="form-label small fw-semibold mb-1">Trạng thái</label>
        <select name="status" class="form-select form-select-sm">
            <option value="pending"  <?=$filterStatus==='pending' ?'selected':''?>>⌛ Chờ duyệt</option>
            <option value="approved" <?=$filterStatus==='approved'?'selected':''?>>✅ Đã duyệt</option>
            <option value="rejected" <?=$filterStatus==='rejected'?'selected':''?>>❌ Từ chối</option>
            <option value="all"      <?=$filterStatus==='all'     ?'selected':''?>>Tất cả</option>
        </select>
    </div>
    <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm">Lọc</button>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">↺</a>
    </div>
</form>
</div>
</div>

<!-- Danh sách -->
<div class="card border-0 shadow-sm">
<div class="card-header bg-white fw-bold d-flex justify-content-between">
    <span>📋 Danh sách đơn OT <span class="badge bg-secondary ms-1"><?= count($requests) ?></span></span>
</div>
<div class="card-body p-0">
<?php if (empty($requests)): ?>
<div class="text-center text-muted py-5"><i class="fas fa-clipboard-check fa-3x mb-3 d-block opacity-25"></i>Không có đơn OT</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-hover align-middle mb-0" style="font-size:.85rem">
    <thead class="table-light">
        <tr>
            <th>Nhân viên</th>
            <th class="text-center">Ngày OT</th>
            <th class="text-center">Số giờ</th>
            <th class="text-center">Hệ số</th>
            <th>Lý do</th>
            <th class="text-center">Trạng thái</th>
            <th class="text-center">Thao tác</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($requests as $ot):
        $stBadge = ['pending'=>['warning','⌛ Chờ'],'approved'=>['success','✅ Duyệt'],'rejected'=>['danger','❌ Từ chối'],'cancelled'=>['secondary','🚫 Huỷ']];
        [$stColor,$stLabel] = $stBadge[$ot['status']] ?? ['secondary',$ot['status']];
        // Director có thể duyệt tất cả; manager/accountant chỉ duyệt employee/driver
        $canApproveThis = $isDirector || !in_array($ot['user_role'],['accountant','manager','director','admin']);
    ?>
    <tr>
        <td>
            <div class="fw-semibold"><?= htmlspecialchars($ot['full_name']) ?></div>
            <div class="text-muted small"><?= $ot['employee_code'] ?></div>
        </td>
        <td class="text-center"><?= date('d/m/Y',strtotime($ot['ot_date'])) ?></td>
        <td class="text-center fw-bold text-primary"><?= $ot['ot_hours'] ?>h</td>
        <td class="text-center"><span class="badge bg-warning text-dark">x<?= $ot['ot_rate'] ?></span></td>
        <td><small class="text-muted"><?= htmlspecialchars(mb_strimwidth($ot['reason'],0,40,'...')) ?></small></td>
        <td class="text-center">
            <span class="badge bg-<?=$stColor?> text-<?=$stColor==='warning'?'dark':'white'?>"><?=$stLabel?></span>
            <?php if($ot['approver_name']): ?><div class="text-muted" style="font-size:10px;"><?= htmlspecialchars($ot['approver_name']) ?></div><?php endif; ?>
        </td>
        <td class="text-center">
        <?php if ($ot['status']==='pending' && $canApproveThis): ?>
        <div class="d-flex gap-1 justify-content-center">
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="ot_id" value="<?=$ot['id']?>">
                <button class="btn btn-xs btn-success" onclick="return confirm('Duyệt đơn OT này?')"><i class="fas fa-check"></i></button>
            </form>
            <button class="btn btn-xs btn-danger" onclick="showReject(<?=$ot['id']?>, '<?= htmlspecialchars($ot['full_name']) ?>')"><i class="fas fa-times"></i></button>
        </div>
        <?php elseif ($ot['status']==='pending' && !$canApproveThis): ?>
        <span class="badge bg-light text-muted border" style="font-size:10px;">Cần GĐ duyệt</span>
        <?php else: ?>
        <span class="text-muted small">—</span>
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

<!-- Modal Từ chối -->
<div class="modal fade" id="rejectModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST">
    <input type="hidden" name="action" value="reject">
    <input type="hidden" name="ot_id" id="rejectOtId">
    <div class="modal-header border-0">
        <h6 class="modal-title fw-bold">❌ Từ chối OT — <span id="rejectEmpName"></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <label class="form-label fw-semibold">Lý do từ chối <span class="text-danger">*</span></label>
        <textarea name="reject_reason" class="form-control" rows="3" required placeholder="Nhập lý do..."></textarea>
    </div>
    <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
        <button type="submit" class="btn btn-danger">Xác nhận từ chối</button>
    </div>
</form>
</div>
</div>
</div>

<style>.btn-xs{padding:3px 10px;font-size:12px;}</style>
<script>
function showReject(id, name) {
    document.getElementById('rejectOtId').value = id;
    document.getElementById('rejectEmpName').textContent = name;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}
</script>
<?php include '../../includes/footer.php'; ?>