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
$flash  = '';

function dQuery(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

// Xử lý tạo đơn OT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Huỷ đơn
    if ($action === 'cancel') {
        $ot_id = (int)($_POST['ot_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE hr_overtime SET status='rejected', reject_reason='Nhân viên tự huỷ'
                WHERE id=? AND user_id=? AND status='pending'")
                ->execute([$ot_id, $user['id']]);
            $flash = '✅ Đã huỷ đơn OT.';
        } catch (Exception $e) {}
        header('Location: ot_request.php');
        exit;
    }

    // Tạo đơn
    if ($action === 'create') {
        $ot_date    = trim($_POST['ot_date']    ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time   = trim($_POST['end_time']   ?? '');
        $reason     = trim($_POST['reason']     ?? '');

        if (empty($ot_date))    $errors[] = 'Vui lòng chọn ngày OT.';
        if (empty($start_time)) $errors[] = 'Vui lòng nh��p giờ bắt đầu.';
        if (empty($end_time))   $errors[] = 'Vui lòng nhập giờ kết thúc.';
        if (empty($reason))     $errors[] = 'Vui lòng nhập lý do OT.';
        if ($ot_date < date('Y-m-d')) $errors[] = 'Không thể đăng ký OT cho ngày đã qua.';

        if (empty($errors)) {
            $startTs = strtotime("$ot_date $start_time");
            $endTs   = strtotime("$ot_date $end_time");
            if ($endTs <= $startTs) $endTs += 86400;
            $hours = round(($endTs - $startTs) / 3600, 2);
            if ($hours <= 0)  $errors[] = 'Giờ kết thúc phải sau giờ bắt đầu.';
            if ($hours > 12)  $errors[] = 'OT không được vượt quá 12 giờ/ngày.';
        }

        if (empty($errors)) {
            // Kiểm tra trùng
            try {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM hr_overtime WHERE user_id=? AND ot_date=? AND status!='rejected'");
                $chk->execute([$user['id'], $ot_date]);
                if ($chk->fetchColumn() > 0) $errors[] = 'Bạn đã có đơn OT cho ngày này.';
            } catch (Exception $e) {}
        }

        if (empty($errors)) {
            $dow     = (int)date('N', strtotime($ot_date));
            $ot_type = $dow >= 6 ? 'weekend' : 'weekday';
            // Kiểm tra ngày lễ
            try {
                $hChk = $pdo->prepare("SELECT COUNT(*) FROM hr_holidays WHERE holiday_date=?");
                $hChk->execute([$ot_date]);
                if ($hChk->fetchColumn() > 0) $ot_type = 'holiday';
            } catch (Exception $e) {}

            try {
                $pdo->prepare("INSERT INTO hr_overtime (user_id,ot_date,start_time,end_time,ot_hours,reason,ot_type,status)
                    VALUES (?,?,?,?,?,?,?,'pending')")
                    ->execute([$user['id'], $ot_date, $start_time, $end_time, $hours, $reason, $ot_type]);

                // Thông báo cho người duyệt (admin/director/manager)
                $approvers = dQuery($pdo,
                    "SELECT u.id FROM users u JOIN roles r ON u.role_id=r.id
                     WHERE r.name IN ('admin','director','manager') AND u.is_active=TRUE");
                foreach ($approvers as $ap) {
                    try {
                        $pdo->prepare("INSERT INTO notifications (user_id,title,message,type) VALUES (?,?,?,?)")
                            ->execute([$ap['id'],
                                '📋 Đơn OT mới từ lái xe',
                                $user['full_name'].' đăng ký OT ngày '.date('d/m/Y',strtotime($ot_date))
                                    .' ('.$start_time.'–'.$end_time.', '.$hours.' giờ)',
                                'ot_request']);
                    } catch (Exception $e) {}
                }
                header('Location: ot_request.php?sent=1');
                exit;
            } catch (Exception $e) {
                $errors[] = 'Lỗi lưu dữ liệu: ' . $e->getMessage();
            }
        }
    }
}

// Lịch sử đơn OT
$myOTs = dQuery($pdo,
    "SELECT o.*, u.full_name AS approver_name
     FROM hr_overtime o
     LEFT JOIN users u ON o.approved_by = u.id
     WHERE o.user_id=?
     ORDER BY o.ot_date DESC LIMIT 30",
    [$user['id']]);

// Thống kê tháng
$thisMonth = date('m'); $thisYear = date('Y');
$totalOT = 0; $pendingCount = 0;
foreach ($myOTs as $o) {
    if ($o['status']==='approved' && date('m',strtotime($o['ot_date']))==$thisMonth
        && date('Y',strtotime($o['ot_date']))==$thisYear) {
        $totalOT += $o['ot_hours'];
    }
    if ($o['status']==='pending') $pendingCount++;
}

$typeColors = ['weekday'=>['Ngày thường','#6b7280','#f3f4f6'],
               'weekend'=>['Cuối tuần','#b45309','#fef3c7'],
               'holiday'=>['Ngày lễ','#dc2626','#fee2e2']];
$statusInfo = ['pending'=>['⌛ Chờ duyệt','#92400e','#fef3c7'],
               'approved'=>['✅ Đã duyệt','#065f46','#d1fae5'],
               'rejected'=>['❌ Từ chối','#991b1b','#fee2e2']];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Đăng ký OT</title>
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
.btn-submit { width:100%;padding:14px;background:linear-gradient(135deg,#1a56db,#0e3a8c);color:#fff;border:none;border-radius:12px;font-size:.95rem;font-weight:700;cursor:pointer;margin-top:8px; }
.btn-submit:active { opacity:.9; }
.stat-row { display:flex;gap:0;border-bottom:1px solid #f3f4f6; }
.stat-item { flex:1;padding:14px 8px;text-align:center;border-right:1px solid #f3f4f6; }
.stat-item:last-child { border-right:none; }
.stat-value { font-size:1.4rem;font-weight:800; }
.stat-label { font-size:.68rem;color:#6b7280;margin-top:2px; }
.ot-item { padding:14px 16px;border-bottom:1px solid #f3f4f6; }
.ot-item:last-child { border-bottom:none; }
.ot-header { display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px; }
.badge-type { font-size:.7rem;padding:2px 8px;border-radius:20px;font-weight:600; }
.badge-status { font-size:.72rem;padding:3px 10px;border-radius:20px;font-weight:600; }
.ot-detail { font-size:.78rem;color:#6b7280;display:flex;flex-wrap:wrap;gap:8px;margin-bottom:6px; }
.btn-cancel { background:#fee2e2;color:#dc2626;border:none;border-radius:8px;padding:4px 12px;font-size:.75rem;cursor:pointer; }
.error-box { background:#fee2e2;border-radius:12px;padding:12px 16px;margin-bottom:12px;color:#991b1b;font-size:.85rem; }
.success-box { background:#d1fae5;border-radius:12px;padding:12px 16px;margin-bottom:12px;color:#065f46;font-size:.85rem; }
.preview-box { background:#eff6ff;border-radius:10px;padding:10px 12px;margin-top:8px;font-size:.8rem;color:#1e40af; }
.section-title { font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;padding:0 16px 8px; }
</style>
</head>
<body>

<div class="page-header d-flex align-items-center gap-3">
    <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
    <h5>Đăng ký tăng ca (OT)</h5>
</div>

<div style="padding:16px 0 0;">

<!-- Thống kê nhanh -->
<div class="card-mobile" style="margin-top:0;">
    <div class="stat-row">
        <div class="stat-item">
            <div class="stat-value text-warning"><?= number_format($totalOT,1) ?></div>
            <div class="stat-label">Giờ OT tháng <?= $thisMonth ?></div>
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
            ✅ Đã gửi đơn đăng ký OT thành công! Chờ quản lý duyệt.
        </div>
        <a href="ot_request.php" style="display:block;text-align:center;color:#1a56db;font-size:.85rem;">+ Tạo đơn mới</a>
    </div>
</div>
<?php else: ?>
<p class="section-title">Tạo đơn OT mới</p>
<div class="card-mobile" style="margin-top:0;">
    <div class="form-card">
        <?php if (!empty($errors)): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="otForm">
            <input type="hidden" name="action" value="create">

            <div class="mb-3">
                <label class="form-label-mobile">📅 Ngày tăng ca</label>
                <input type="date" name="ot_date" id="otDate" class="form-control-mobile"
                       value="<?= htmlspecialchars($_POST['ot_date'] ?? date('Y-m-d')) ?>"
                       min="<?= date('Y-m-d') ?>" required>
                <div id="dayTypeBadge" style="margin-top:6px;font-size:.78rem;"></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;" class="mb-3">
                <div>
                    <label class="form-label-mobile">⏰ Giờ bắt đầu</label>
                    <input type="time" name="start_time" id="otStart" class="form-control-mobile"
                           value="<?= htmlspecialchars($_POST['start_time'] ?? '17:00') ?>" required>
                </div>
                <div>
                    <label class="form-label-mobile">⏰ Giờ kết thúc</label>
                    <input type="time" name="end_time" id="otEnd" class="form-control-mobile"
                           value="<?= htmlspecialchars($_POST['end_time'] ?? '20:00') ?>" required>
                </div>
            </div>

            <!-- Preview -->
            <div id="otPreview" style="display:none;" class="preview-box mb-3">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                    <span>⏱️ Số giờ OT:</span>
                    <strong id="previewHours"></strong>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span>📊 Loại ngày:</span>
                    <span id="previewType"></span>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label-mobile">📝 Lý do tăng ca</label>
                <textarea name="reason" class="form-control-mobile" rows="3"
                          placeholder="Mô tả công việc cần làm thêm giờ..."
                          required><?= htmlspecialchars($_POST['reason'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane" style="margin-right:6px;"></i>Gửi đơn OT
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Lịch sử đơn OT -->
<p class="section-title" style="margin-top:8px;">Lịch sử đơn OT</p>
<div class="card-mobile" style="margin-top:0;">
    <?php if (empty($myOTs)): ?>
    <div style="text-align:center;padding:32px;color:#9ca3af;">
        <i class="fas fa-clock" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
        Chưa có đơn OT nào
    </div>
    <?php else:
    foreach ($myOTs as $ot):
        $st  = $statusInfo[$ot['status']] ?? $statusInfo['pending'];
        $typ = $typeColors[$ot['ot_type']] ?? $typeColors['weekday'];
    ?>
    <div class="ot-item">
        <div class="ot-header">
            <div>
                <div style="font-weight:700;font-size:.9rem;">
                    📅 <?= date('d/m/Y', strtotime($ot['ot_date'])) ?>
                </div>
                <span class="badge-type" style="color:<?= $typ[1] ?>;background:<?= $typ[2] ?>;">
                    <?= $typ[0] ?>
                </span>
            </div>
            <span class="badge-status" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>;">
                <?= $st[0] ?>
            </span>
        </div>
        <div class="ot-detail">
            <span><i class="fas fa-clock" style="color:#1a56db;"></i>
                <?= substr($ot['start_time'],0,5) ?> – <?= substr($ot['end_time'],0,5) ?>
            </span>
            <span><strong><?= $ot['ot_hours'] ?>h</strong></span>
            <?php if ($ot['approver_name']): ?>
            <span>Duyệt: <?= htmlspecialchars($ot['approver_name']) ?></span>
            <?php endif; ?>
        </div>
        <div style="font-size:.78rem;color:#6b7280;margin-bottom:6px;">
            <?= htmlspecialchars($ot['reason']) ?>
        </div>
        <?php if ($ot['status']==='rejected' && $ot['reject_reason']): ?>
        <div style="font-size:.75rem;color:#dc2626;background:#fee2e2;padding:4px 8px;border-radius:6px;margin-bottom:6px;">
            Lý do: <?= htmlspecialchars($ot['reject_reason']) ?>
        </div>
        <?php endif; ?>
        <?php if ($ot['status']==='pending'): ?>
        <form method="POST">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="ot_id" value="<?= $ot['id'] ?>">
            <button type="submit" class="btn-cancel"
                    onclick="return confirm('Huỷ đơn OT ngày <?= date('d/m',strtotime($ot['ot_date'])) ?>?')">
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
    <a href="ot_request.php" class="active"><i class="fas fa-business-time"></i><span style="font-size:.68rem;">OT</span></a>
    <a href="leave_request.php"><i class="fas fa-calendar-minus"></i><span style="font-size:.68rem;">Nghỉ phép</span></a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function getDayType(ds) {
    const dow = new Date(ds).getDay(); // 0=Sun,6=Sat
    if (dow === 0 || dow === 6) return 'weekend';
    return 'weekday';
}
const typeInfo = {
    weekday: { label: 'Ngày thường', color: '#6b7280', bg: '#f3f4f6' },
    weekend: { label: 'Cuối tuần 🌤️', color: '#b45309', bg: '#fef3c7' },
    holiday: { label: 'Ngày lễ 🎉', color: '#dc2626', bg: '#fee2e2' },
};
function timeToMin(t) {
    const [h,m] = t.split(':').map(Number); return h*60+m;
}
function updatePreview() {
    const ds    = document.getElementById('otDate').value;
    const start = document.getElementById('otStart').value;
    const end   = document.getElementById('otEnd').value;
    const badge = document.getElementById('dayTypeBadge');
    const prev  = document.getElementById('otPreview');
    if (!ds) return;
    const type = getDayType(ds);
    const info = typeInfo[type];
    badge.innerHTML = `<span style="background:${info.bg};color:${info.color};padding:2px 10px;border-radius:20px;font-weight:600;">${info.label}</span>`;
    if (start && end) {
        let sm = timeToMin(start), em = timeToMin(end);
        if (em <= sm) em += 24*60;
        const h = ((em-sm)/60).toFixed(1);
        prev.style.display = 'block';
        document.getElementById('previewHours').textContent = h + ' giờ';
        document.getElementById('previewType').innerHTML = `<span style="background:${info.bg};color:${info.color};padding:1px 8px;border-radius:20px;font-size:.75rem;">${info.label}</span>`;
    }
}
['otDate','otStart','otEnd'].forEach(id => {
    document.getElementById(id)?.addEventListener('change', updatePreview);
    document.getElementById(id)?.addEventListener('input',  updatePreview);
});
updatePreview();
</script>
</body>
</html>