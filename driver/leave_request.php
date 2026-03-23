<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();

$user = currentUser();
if (($user['role'] ?? '') !== 'driver') {
    header('Location: /select_module.php');
    exit;
}

$pdo    = getDBConnection();
$errors = [];

function dQuery(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

// Xử lý form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Huỷ đơn
    if ($action === 'cancel') {
        $lv_id = (int)($_POST['lv_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE hr_leaves SET status='rejected', reject_reason='Nhân viên tự huỷ'
                WHERE id=? AND user_id=? AND status='pending'")
                ->execute([$lv_id, $user['id']]);
        } catch (Exception $e) {}
        header('Location: leave_request.php?cancelled=1');
        exit;
    }

    // Tạo đơn
    if ($action === 'create') {
        $leave_type = $_POST['leave_type'] ?? '';
        $date_from  = trim($_POST['date_from'] ?? '');
        $date_to    = trim($_POST['date_to']   ?? '');
        $reason     = trim($_POST['reason']    ?? '');

        if (empty($leave_type)) $errors[] = 'Vui lòng chọn loại nghỉ phép.';
        if (empty($date_from))  $errors[] = 'Vui lòng chọn ngày bắt đầu.';
        if (empty($date_to))    $errors[] = 'Vui lòng chọn ngày kết thúc.';
        if (empty($reason))     $errors[] = 'Vui lòng nhập lý do.';
        if (!empty($date_from) && !empty($date_to) && $date_to < $date_from)
            $errors[] = 'Ngày kết thúc phải sau ngày bắt đầu.';
        if (!empty($date_from) && $date_from < date('Y-m-d'))
            $errors[] = 'Không thể xin nghỉ cho ngày đã qua.';

        if (empty($errors)) {
            // Tính số ngày (không tính CN)
            $days = 0;
            $s = strtotime($date_from); $e = strtotime($date_to);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N',$d) != 7) $days++;
            }
            if ($days <= 0) $errors[] = 'Không có ngày làm việc hợp lệ trong khoảng đã chọn.';
        }

        if (empty($errors)) {
            // Kiểm tra trùng
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM hr_leaves
                    WHERE user_id=? AND status!='rejected'
                    AND date_from <= ? AND date_to >= ?");
                $chk->execute([$user['id'], $date_to, $date_from]);
                if ($chk->fetchColumn() > 0) $errors[] = 'Bạn đã có đơn nghỉ phép trong khoảng này.';
            } catch (Exception $e) {}
        }

        if (empty($errors)) {
            $days_count = 0;
            $s = strtotime($date_from); $e = strtotime($date_to);
            for ($d = $s; $d <= $e; $d += 86400) {
                if (date('N',$d) != 7) $days_count++;
            }
            try {
                $pdo->prepare("INSERT INTO hr_leaves (user_id,leave_type,date_from,date_to,days_count,reason,status)
                    VALUES (?,?,?,?,?,?,'pending')")
                    ->execute([$user['id'], $leave_type, $date_from, $date_to, $days_count, $reason]);

                // Thông báo người duyệt
                $approvers = dQuery($pdo,
                    "SELECT u.id FROM users u JOIN roles r ON u.role_id=r.id
                     WHERE r.name IN ('admin','director','manager') AND u.is_active=TRUE");
                foreach ($approvers as $ap) {
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
                            ->execute([$ap['id'],
                                '📋 Đơn nghỉ phép từ lái xe',
                                $user['full_name'].' xin nghỉ '.$days_count.' ngày ('
                                    .date('d/m',strtotime($date_from)).' – '.date('d/m/Y',strtotime($date_to)).')',
                                'leave_request']);
                    } catch (Exception $e) {}
                }
                header('Location: leave_request.php?sent=1');
                exit;
            } catch (Exception $e) {
                $errors[] = 'Lỗi lưu dữ liệu.';
            }
        }
    }
}

// Lịch sử đơn
$myLeaves = dQuery($pdo,
    "SELECT l.*, u.full_name AS approver_name
     FROM hr_leaves l
     LEFT JOIN users u ON l.approved_by = u.id
     WHERE l.user_id=?
     ORDER BY l.date_from DESC LIMIT 20",
    [$user['id']]);

$pendingCount = count(array_filter($myLeaves, fn($l)=>$l['status']==='pending'));
$approvedThisYear = array_sum(array_map(
    fn($l) => ($l['status']==='approved' && date('Y',strtotime($l['date_from']))==date('Y')) ? (int)$l['days_count'] : 0,
    $myLeaves));

$leaveTypeLabels = [
    'annual'  => ['Phép năm',       '📅', '#1e40af', '#dbeafe'],
    'sick'    => ['Nghỉ ốm',        '🏥', '#065f46', '#d1fae5'],
    'unpaid'  => ['Không lương',    '⚠️',  '#92400e', '#fef3c7'],
    'other'   => ['Lý do khác',     '📝', '#374151', '#f3f4f6'],
];
$statusInfo = [
    'pending'  => ['⌛ Chờ duyệt', '#92400e', '#fef3c7'],
    'approved' => ['✅ Đã duyệt',  '#065f46', '#d1fae5'],
    'rejected' => ['❌ Từ chối',   '#991b1b', '#fee2e2'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Xin nghỉ phép</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8;font-family:'Segoe UI',system-ui,sans-serif;padding-bottom:80px; }
.page-header { background:linear-gradient(135deg,#1a56db,#0e3a8c);color:#fff;padding:16px 20px;position:sticky;top:0;z-index:100; }
.page-header .back-btn { color:#fff;text-decoration:none;font-size:1.1rem; }
.page-header h5 { margin:0;font-weight:700;font-size:1rem; }
.bottom-nav { position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e7eb;display:flex;z-index:200;box-shadow:0 -4px 16px rgba(0,0,0,.08); }
.bottom-nav a { flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 4px;text-decoration:none;color:#9ca3af;font-size:.68rem;gap:3px; }
.bottom-nav a.active,.bottom-nav a:hover { color:#1a56db; }
.bottom-nav a i { font-size:1.3rem; }
.card-mobile { background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin:0 16px 16px;overflow:hidden; }
.form-card { padding:16px; }
.form-label-mobile { font-size:.82rem;font-weight:600;color:#374151;margin-bottom:6px;display:block; }
.form-control-mobile { width:100%;padding:12px 14px;border:1.5px solid #e5e7eb;border-radius:12px;font-size:.9rem;background:#fff;outline:none;transition:border-color .2s; }
.form-control-mobile:focus { border-color:#1a56db; }
.btn-submit { width:100%;padding:14px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:12px;font-size:.95rem;font-weight:700;cursor:pointer;margin-top:8px; }
.stat-row { display:flex;gap:0; }
.stat-item { flex:1;padding:14px 8px;text-align:center;border-right:1px solid #f3f4f6; }
.stat-item:last-child { border-right:none; }
.stat-value { font-size:1.4rem;font-weight:800; }
.stat-label { font-size:.68rem;color:#6b7280;margin-top:2px; }
.leave-type-grid { display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px; }
.leave-type-btn { border:2px solid #e5e7eb;border-radius:12px;padding:12px;text-align:center;cursor:pointer;transition:all .2s;background:#fff; }
.leave-type-btn.selected { border-color:#1a56db;background:#eff6ff; }
.leave-type-btn .lt-icon { font-size:1.4rem;display:block;margin-bottom:4px; }
.leave-type-btn .lt-label { font-size:.78rem;font-weight:600;color:#374151; }
.leave-item { padding:14px 16px;border-bottom:1px solid #f3f4f6; }
.leave-item:last-child { border-bottom:none; }
.leave-header { display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px; }
.badge-status { font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600; }
.badge-type  { font-size:.72rem;padding:2px 8px;border-radius:20px;font-weight:600; }
.leave-detail { font-size:.78rem;color:#6b7280;display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px; }
.btn-cancel { background:#fee2e2;color:#dc2626;border:none;border-radius:8px;padding:4px 12px;font-size:.75rem;cursor:pointer; }
.error-box { background:#fee2e2;border-radius:12px;padding:12px 16px;margin-bottom:12px;color:#991b1b;font-size:.85rem; }
.success-box { background:#d1fae5;border-radius:12px;padding:12px 16px;margin-bottom:12px;color:#065f46;font-size:.85rem; }
.preview-days { background:#eff6ff;border-radius:10px;padding:8px 12px;font-size:.8rem;color:#1e40af;margin-top:8px;display:none; }
.section-title { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;padding:0 16px 8px; }
</style>
</head>
<body>

<div class="page-header d-flex align-items-center gap-3">
    <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
    <h5>Xin nghỉ phép</h5>
</div>

<div style="padding:16px 0 0;">

<!-- Thống kê nhanh -->
<div class="card-mobile" style="margin-top:0;">
    <div class="stat-row">
        <div class="stat-item">
            <div class="stat-value text-primary"><?= $approvedThisYear ?></div>
            <div class="stat-label">Ngày phép đã dùng</div>
        </div>
        <div class="stat-item">
            <div class="stat-value <?= $pendingCount>0?'text-danger':'text-muted' ?>"><?= $pendingCount ?></div>
            <div class="stat-label">Đang chờ duyệt</div>
        </div>
    </div>
</div>

<!-- Form tạo đơn -->
<?php if (isset($_GET['sent'])): ?>
<div class="card-mobile" style="margin-top:0;">
    <div class="form-card">
        <div class="success-box">
            ✅ Đã gửi đơn xin nghỉ phép thành công! Chờ quản lý duyệt.
        </div>
        <a href="leave_request.php" style="display:block;text-align:center;color:#1a56db;font-size:.85rem;">+ Tạo đơn mới</a>
    </div>
</div>
<?php elseif (isset($_GET['cancelled'])): ?>
<div class="card-mobile" style="margin-top:0;">
    <div class="form-card">
        <div class="success-box">✅ Đã huỷ đơn nghỉ phép.</div>
        <a href="leave_request.php" style="display:block;text-align:center;color:#1a56db;font-size:.85rem;">+ Tạo đơn mới</a>
    </div>
</div>
<?php else: ?>
<p class="section-title">Tạo đơn xin nghỉ phép</p>
<div class="card-mobile" style="margin-top:0;">
    <div class="form-card">
        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="leaveForm">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="leave_type" id="leaveTypeInput" value="<?= htmlspecialchars($_POST['leave_type'] ?? 'annual') ?>">

            <!-- Loại nghỉ -->
            <div class="mb-3">
                <label class="form-label-mobile">Loại nghỉ phép</label>
                <div class="leave-type-grid">
                    <?php foreach ($leaveTypeLabels as $k => $lt): ?>
                    <div class="leave-type-btn <?= ($_POST['leave_type'] ?? 'annual')===$k?'selected':'' ?>"
                         onclick="selectLeaveType('<?= $k ?>')">
                        <span class="lt-icon"><?= $lt[1] ?></span>
                        <span class="lt-label"><?= $lt[0] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Ngày -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" class="mb-3">
                <div>
                    <label class="form-label-mobile">📅 Từ ngày</label>
                    <input type="date" name="date_from" id="dateFrom" class="form-control-mobile"
                           value="<?= htmlspecialchars($_POST['date_from'] ?? date('Y-m-d')) ?>"
                           min="<?= date('Y-m-d') ?>" required
                           onchange="calcDays()">
                </div>
                <div>
                    <label class="form-label-mobile">📅 Đến ngày</label>
                    <input type="date" name="date_to" id="dateTo" class="form-control-mobile"
                           value="<?= htmlspecialchars($_POST['date_to'] ?? date('Y-m-d')) ?>"
                           min="<?= date('Y-m-d') ?>" required
                           onchange="calcDays()">
                </div>
            </div>
            <div id="previewDays" class="preview-days mb-3"></div>

            <div class="mb-3">
                <label class="form-label-mobile">📝 Lý do nghỉ phép</label>
                <textarea name="reason" class="form-control-mobile" rows="3"
                          placeholder="Mô tả lý do xin nghỉ..."
                          required><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane" style="margin-right:6px;"></i>Gửi đơn nghỉ phép
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Lịch sử đơn -->
<p class="section-title" style="margin-top:8px;">Lịch sử đơn nghỉ phép</p>
<div class="card-mobile" style="margin-top:0;">
    <?php if (empty($myLeaves)): ?>
    <div style="text-align:center;padding:32px;color:#9ca3af;">
        <i class="fas fa-calendar-times" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
        Chưa có đơn nghỉ phép nào
    </div>
    <?php else:
    foreach ($myLeaves as $lv):
        $st  = $statusInfo[$lv['status']] ?? $statusInfo['pending'];
        $typ = $leaveTypeLabels[$lv['leave_type']] ?? $leaveTypeLabels['other'];
    ?>
    <div class="leave-item">
        <div class="leave-header">
            <div>
                <div style="font-weight:700;font-size:.9rem;margin-bottom:3px;">
                    <?= $typ[1] ?> <?= $typ[0] ?>
                </div>
                <div style="font-size:.78rem;color:#6b7280;">
                    <?= date('d/m/Y',strtotime($lv['date_from'])) ?>
                    <?= $lv['date_from']!==$lv['date_to'] ? ' → '.date('d/m/Y',strtotime($lv['date_to'])) : '' ?>
                </div>
            </div>
            <div style="text-align:right;">
                <span class="badge-status" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>;">
                    <?= $st[0] ?>
                </span>
                <div style="font-size:.72rem;color:#6b7280;margin-top:3px;">
                    <?= $lv['days_count'] ?> ngày
                </div>
            </div>
        </div>
        <div class="leave-detail">
            <?php if ($lv['approver_name']): ?>
            <span>Duyệt: <?= htmlspecialchars($lv['approver_name']) ?></span>
            <?php endif; ?>
            <span style="color:#9ca3af;"><?= htmlspecialchars($lv['reason']) ?></span>
        </div>
        <?php if ($lv['status']==='rejected' && $lv['reject_reason']): ?>
        <div style="font-size:.75rem;color:#dc2626;background:#fee2e2;padding:4px 8px;border-radius:6px;margin-bottom:6px;">
            Lý do từ chối: <?= htmlspecialchars($lv['reject_reason']) ?>
        </div>
        <?php endif; ?>
        <?php if ($lv['status']==='pending'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="lv_id" value="<?= $lv['id'] ?>">
            <button type="submit" class="btn-cancel"
                    onclick="return confirm('Huỷ đơn nghỉ phép này?')">
                <i class="fas fa-times" style="margin-right:4px;"></i>Huỷ đơn
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>

</div>

<!-- Bottom Nav -->
<nav class="bottom-nav">
    <a href="index.php"><i class="fas fa-home"></i><span style="font-size:.68rem;">Trang chủ</span></a>
    <a href="attendance.php"><i class="fas fa-clock"></i><span style="font-size:.68rem;">Chấm công</span></a>
    <a href="ot_request.php"><i class="fas fa-business-time"></i><span style="font-size:.68rem;">OT</span></a>
    <a href="leave_request.php" class="active"><i class="fas fa-calendar-minus"></i><span style="font-size:.68rem;">Nghỉ phép</span></a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function selectLeaveType(type) {
    document.getElementById('leaveTypeInput').value = type;
    document.querySelectorAll('.leave-type-btn').forEach(b => b.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

function calcDays() {
    const from = document.getElementById('dateFrom').value;
    const to   = document.getElementById('dateTo').value;
    const prev = document.getElementById('previewDays');
    if (!from || !to || to < from) { prev.style.display='none'; return; }
    let days = 0;
    let d = new Date(from);
    const end = new Date(to);
    while (d <= end) {
        if (d.getDay() !== 0) days++; // không tính CN
        d.setDate(d.getDate() + 1);
    }
    prev.style.display = 'block';
    prev.innerHTML = `📋 Tổng <strong>${days} ngày làm việc</strong> (không tính Chủ nhật)`;
}
// Chạy khi load nếu đã có giá trị
calcDays();
</script>
</body>
</html>