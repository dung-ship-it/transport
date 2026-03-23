<?php
// Quản lý và duyệt nghỉ phép — manager+
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

if (!$canApprove) { header('Location: request.php'); exit; }

$pageTitle = 'Duyệt nghỉ phép HR';

// Xử lý duyệt / từ chối
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action        = $_POST['action'] ?? '';
    $leave_id      = (int)($_POST['leave_id'] ?? 0);
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    if ($action==='approve' && $leave_id) {
        $lvInfo = $pdo->prepare("SELECT l.*, r.name AS user_role FROM hr_leaves l JOIN users u ON l.user_id=u.id JOIN roles r ON u.role_id=r.id WHERE l.id=?");
        $lvInfo->execute([$leave_id]);
        $lvData = $lvInfo->fetch(PDO::FETCH_ASSOC);

        if ($lvData && in_array($lvData['user_role'],['accountant','manager']) && !$isDirector) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Chỉ Giám đốc mới được duyệt phép của kế toán/quản lý.'];
        } else {
            $pdo->prepare("UPDATE hr_leaves SET status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND status='pending'")
                ->execute([$user['id'], $leave_id]);
            if ($lvData) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'leave_approved')")
                    ->execute([$lvData['user_id'], '✅ Đơn nghỉ phép được duyệt', 'Đơn nghỉ phép '.$lvData['date_from'].' → '.$lvData['date_to'].' đã được duyệt.']);
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã duyệt đơn nghỉ phép.'];
        }
    } elseif ($action==='reject' && $leave_id) {
        if (empty($reject_reason)) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Vui lòng nhập lý do từ chối.'];
        } else {
            $lvInfo = $pdo->prepare("SELECT * FROM hr_leaves WHERE id=?");
            $lvInfo->execute([$leave_id]);
            $lvData = $lvInfo->fetch(PDO::FETCH_ASSOC);
            $pdo->prepare("UPDATE hr_leaves SET status='rejected', approved_by=?, approved_at=NOW(), reject_reason=? WHERE id=? AND status='pending'")
                ->execute([$user['id'], $reject_reason, $leave_id]);
            if ($lvData) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,'leave_rejected')")
                    ->execute([$lvData['user_id'], '❌ Đơn nghỉ phép bị từ chối', 'Lý do: '.$reject_reason]);
            }
            $_SESSION['flash'] = ['type'=>'warning','msg'=>'⚠️ Đã từ chối đơn nghỉ phép.'];
        }
    }
    header('Location: index.php?'.http_build_query($_GET)); exit;
}

$filterStatus = $_GET['status'] ?? 'pending';
$filterMonth  = (int)($_GET['month'] ?? date('m'));
$filterYear   = (int)($_GET['year']  ?? date('Y'));

$sql = "SELECT l.*, u.full_name, u.employee_code, r.name AS user_role,
               a.full_name AS approver_name
        FROM hr_leaves l
        JOIN users u ON l.user_id=u.id
        JOIN roles r ON u.role_id=r.id
        LEFT JOIN users a ON l.approved_by=a.id
        WHERE (EXTRACT(MONTH FROM l.date_from)=? OR EXTRACT(MONTH FROM l.date_to)=?)
          AND (EXTRACT(YEAR FROM l.date_from)=?  OR EXTRACT(YEAR FROM l.date_to)=?)";
$params = [$filterMonth, $filterMonth, $filterYear, $filterYear];

if (!$isDirector) {
    $sql .= " AND r.name NOT IN ('accountant','manager','director','admin')";
}
if ($filterStatus!=='all') { $sql .= " AND l.status=?"; $params[] = $filterStatus; }
$sql .= " ORDER BY l.created_at DESC";

$requests = [];
try { $s=$pdo->prepare($sql);$s->execute($params);$requests=$s->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $e) { error_log($e->getMessage()); }

$csrf = generateCSRF();
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">📋 Duyệt nghỉ phép</h4>
        <small class="text-muted">Tháng <?=$filterMonth?>/<?=$filterYear?></small>
    </div>
    <a href="request.php" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-plus me-1"></i>Đơn phép của tôi
    </a>
</div>

<?php showFlash(); ?>

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
            <option value="rejected" <?=$filterStatus==='rejected'?'selected':''?>>❌ Từ