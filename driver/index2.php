<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();

$user = currentUser();
// Chỉ driver mới vào được
if (($user['role'] ?? '') !== 'driver') {
    header('Location: /transport/select_module.php');
    exit;
}

$pdo   = getDBConnection();
$today = date('Y-m-d');

function dCol(PDO $pdo, string $sql, array $p = []): mixed {
    try { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false ? 0 : $v; }
    catch (Exception $e) { return 0; }
}
function dQuery(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

// Chấm công hôm nay
$todayLog = dQuery($pdo,
    "SELECT * FROM hr_attendance WHERE user_id = ? AND work_date = ? LIMIT 1",
    [$user['id'], $today]);
$todayLog = $todayLog[0] ?? null;

// Thống kê tháng hiện tại
$thisMonth = date('m');
$thisYear  = date('Y');
$totalWork  = (int)dCol($pdo,
    "SELECT COUNT(*) FROM hr_attendance WHERE user_id=? AND check_in IS NOT NULL
     AND EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",
    [$user['id'], $thisMonth, $thisYear]);
$totalHours = (float)dCol($pdo,
    "SELECT COALESCE(SUM(work_hours),0) FROM hr_attendance WHERE user_id=?
     AND EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",
    [$user['id'], $thisMonth, $thisYear]);
$pendingOT = (int)dCol($pdo,
    "SELECT COUNT(*) FROM hr_overtime WHERE user_id=? AND status='pending'",
    [$user['id']]);
$pendingLeave = (int)dCol($pdo,
    "SELECT COUNT(*) FROM hr_leaves WHERE user_id=? AND status='pending'",
    [$user['id']]);
$approvedOTHours = (float)dCol($pdo,
    "SELECT COALESCE(SUM(ot_hours),0) FROM hr_overtime WHERE user_id=?
     AND status='approved'
     AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",
    [$user['id'], $thisMonth, $thisYear]);

// Thông báo gần đây
$notifications = dQuery($pdo,
    "SELECT * FROM notifications WHERE user_id=? AND is_read=FALSE ORDER BY created_at DESC LIMIT 5",
    [$user['id']]);

// Xử lý check-in/out
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $now = date('Y-m-d H:i:s');
    if ($_POST['action'] === 'check_in') {
        try {
            $pdo->prepare("INSERT INTO hr_attendance (user_id,work_date,check_in,status,source)
                VALUES (?,?,?,'present','manual')
                ON CONFLICT (user_id,work_date) DO NOTHING")
                ->execute([$user['id'], $today, $now]);
        } catch (Exception $e) {}
    } elseif ($action === 'check_out') {
    try {
        $pdo->prepare("
            UPDATE hr_attendance
            SET check_out  = ?,
                work_hours = LEAST(
                    ROUND(EXTRACT(EPOCH FROM (?::timestamp - check_in)) / 3600, 2),
                    24.00
                )
            WHERE user_id = ? AND work_date = ? AND check_out IS NULL
        ")->execute([$now, $now, $user['id'], $today]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Chấm công RA lúc ' . date('H:i')];
    } catch (Exception $e) { /* ignore */ }
}
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>HR Portal - Lái Xe</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
* { box-sizing: border-box; }
body { background: #f0f4f8; font-family: 'Segoe UI', system-ui, sans-serif; padding-bottom: 80px; }

/* Header */
.driver-header {
    background: linear-gradient(135deg, #1a56db 0%, #0e3a8c 100%);
    color: #fff; padding: 16px 20px 20px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 12px rgba(0,0,0,.2);
}
.driver-header .greeting { font-size: .8rem; opacity: .75; }
.driver-header .name { font-size: 1.15rem; font-weight: 700; }
.driver-header .date-badge {
    background: rgba(255,255,255,.15); border-radius: 20px;
    padding: 4px 12px; font-size: .78rem;
}
.back-btn {
    background: rgba(255,255,255,.2); border: none; color: #fff;
    border-radius: 10px; padding: 6px 12px; font-size: .8rem;
    cursor: pointer; text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px; transition: background .2s;
}
.back-btn:hover { background: rgba(255,255,255,.3); color: #fff; }

/* Bottom nav */
.bottom-nav {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #fff; border-top: 1px solid #e5e7eb;
    display: flex; z-index: 200;
    box-shadow: 0 -4px 16px rgba(0,0,0,.08);
}
.bottom-nav a {
    flex: 1; display: flex; flex-direction: column; align-items: center;
    justify-content: center; padding: 8px 4px; text-decoration: none;
    color: #9ca3af; font-size: .68rem; transition: color .2s;
    gap: 3px;
}
.bottom-nav a.active, .bottom-nav a:hover { color: #1a56db; }
.bottom-nav a i { font-size: 1.3rem; }
.bottom-nav a .nav-label { font-weight: 500; }

/* Cards */
.card-mobile {
    background: #fff; border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    margin: 0 16px 16px; overflow: hidden;
}
.section-title {
    font-size: .72rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .8px; color: #6b7280; padding: 0 16px 8px;
}

/* Check-in button */
.checkin-btn {
    width: 120px; height: 120px; border-radius: 50%;
    border: none; font-size: 1rem; font-weight: 700;
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 6px; cursor: pointer;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
    transition: transform .15s, box-shadow .15s;
}
.checkin-btn:active { transform: scale(.96); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
.checkin-btn.btn-in  { background: linear-gradient(135deg, #10b981, #059669); color: #fff; }
.checkin-btn.btn-out { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
.checkin-btn.btn-done { background: #f3f4f6; color: #9ca3af; cursor: default; }

/* KPI grid */
.kpi-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
.kpi-item { background: #f8fafc; border-radius: 12px; padding: 14px; text-align: center; }
.kpi-value { font-size: 1.5rem; font-weight: 800; }
.kpi-label { font-size: .72rem; color: #6b7280; margin-top: 2px; }

/* Quick action */
.quick-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 16px; }
.quick-btn {
    border-radius: 14px; padding: 16px 12px; text-decoration: none;
    display: flex; flex-direction: column; align-items: center;
    gap: 8px; font-size: .82rem; font-weight: 600; transition: transform .15s;
    border: none; text-align: center;
}
.quick-btn:active { transform: scale(.97); }
.quick-btn i { font-size: 1.6rem; }

/* Notification dot */
.notif-dot {
    display: inline-block; width: 8px; height: 8px;
    background: #ef4444; border-radius: 50%; vertical-align: super;
    margin-left: 3px;
}
</style>
</head>
<body>

<!-- Header -->
<div class="driver-header">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <a href="/transport/driver/dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
        <div class="date-badge">📅 <?= date('d/m/Y') ?></div>
    </div>
    <div class="d-flex justify-content-between align-items-end mt-1">
        <div>
            <div class="greeting">HR Portal 👷</div>
            <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
        </div>
        <div style="font-size:.72rem;opacity:.65;"><?= date('l') ?></div>
    </div>
</div>

<div style="padding: 16px 0 0;">

<!-- Check-in Card -->
<div class="card-mobile">
    <div class="d-flex flex-column align-items-center py-4">
        <?php
        $canIn   = !$todayLog || !$todayLog['check_in'];
        $canOut  = $todayLog && $todayLog['check_in'] && !$todayLog['check_out'];
        $isDone  = $todayLog && $todayLog['check_out'];
        ?>
        <div class="d-flex gap-4 mb-3" style="font-size:.8rem;">
            <div class="text-center">
                <div class="text-muted small">Vào ca</div>
                <div class="fw-bold <?= $todayLog&&$todayLog['check_in']?'text-success':'text-muted' ?>" style="font-size:1.1rem;">
                    <?= $todayLog&&$todayLog['check_in'] ? date('H:i',strtotime($todayLog['check_in'])) : '--:--' ?>
                </div>
            </div>
            <div class="text-center">
                <div class="text-muted small">Ra ca</div>
                <div class="fw-bold <?= $todayLog&&$todayLog['check_out']?'text-danger':'text-muted' ?>" style="font-size:1.1rem;">
                    <?= $todayLog&&$todayLog['check_out'] ? date('H:i',strtotime($todayLog['check_out'])) : '--:--' ?>
                </div>
            </div>
            <?php if ($isDone): ?>
            <div class="text-center">
                <div class="text-muted small">Số giờ</div>
                <div class="fw-bold text-primary" style="font-size:1.1rem;"><?= $todayLog['work_hours'] ?>h</div>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST">
            <?php if ($isDone): ?>
            <button class="checkin-btn btn-done" disabled>
                <i class="fas fa-check-circle" style="font-size:1.8rem;"></i>
                <span>Hoàn thành</span>
            </button>
            <?php elseif ($canIn): ?>
            <input type="hidden" name="action" value="check_in">
            <button type="submit" class="checkin-btn btn-in"
                    onclick="return confirm('Xác nhận chấm công VÀO?')">
                <i class="fas fa-sign-in-alt" style="font-size:1.8rem;"></i>
                <span>Chấm vào</span>
            </button>
            <?php elseif ($canOut): ?>
            <input type="hidden" name="action" value="check_out">
            <button type="submit" class="checkin-btn btn-out"
                    onclick="return confirm('Xác nhận chấm công RA?')">
                <i class="fas fa-sign-out-alt" style="font-size:1.8rem;"></i>
                <span>Chấm ra</span>
            </button>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- KPI tháng -->
<p class="section-title">Tháng <?= $thisMonth ?>/<?= $thisYear ?></p>
<div class="card-mobile" style="margin-top:0;">
    <div class="kpi-grid">
        <div class="kpi-item">
            <div class="kpi-value text-success"><?= $totalWork ?></div>
            <div class="kpi-label">Ngày công</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value text-primary"><?= number_format($totalHours,1) ?></div>
            <div class="kpi-label">Giờ làm</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value text-warning"><?= number_format($approvedOTHours,1) ?></div>
            <div class="kpi-label">Giờ OT</div>
        </div>
        <div class="kpi-item">
            <div class="kpi-value <?= $pendingOT+$pendingLeave>0?'text-danger':'text-muted' ?>">
                <?= $pendingOT + $pendingLeave ?>
            </div>
            <div class="kpi-label">Đang chờ duyệt</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<p class="section-title">Thao tác nhanh</p>
<div class="card-mobile" style="margin-top:0;">
    <div class="quick-actions">
        <a href="attendance.php" class="quick-btn" style="background:#eff6ff;color:#1d4ed8;">
            <i class="fas fa-calendar-check"></i>
            <span>Xem chấm công</span>
        </a>
        <a href="ot_request.php" class="quick-btn" style="background:#fefce8;color:#a16207;">
            <i class="fas fa-business-time"></i>
            <span>Đăng ký OT<?= $pendingOT>0?"<span class='notif-dot'></span>":'' ?></span>
        </a>
        <a href="leave_request.php" class="quick-btn" style="background:#f0fdf4;color:#15803d;">
            <i class="fas fa-calendar-minus"></i>
            <span>Xin nghỉ phép<?= $pendingLeave>0?"<span class='notif-dot'></span>":'' ?></span>
        </a>
        <a href="/transport/driver/dashboard.php" class="quick-btn" style="background:#f1f5f9;color:#475569;">
            <i class="fas fa-truck"></i>
            <span>Dashboard Xe</span>
        </a>
    </div>
</div>

<!-- Thông báo -->
<?php if (!empty($notifications)): ?>
<p class="section-title">Thông báo mới</p>
<div class="card-mobile" style="margin-top:0;">
    <?php foreach ($notifications as $i => $n): ?>
    <div class="px-4 py-3 <?= $i<count($notifications)-1?'border-bottom':'' ?>">
        <div class="fw-bold" style="font-size:.85rem;"><?= htmlspecialchars($n['title']) ?></div>
        <div class="text-muted" style="font-size:.78rem;"><?= htmlspecialchars($n['message']) ?></div>
        <div class="text-muted" style="font-size:.7rem;margin-top:2px;">
            <?= date('d/m H:i', strtotime($n['created_at'])) ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /padding -->

<!-- Bottom Nav -->
<nav class="bottom-nav">
    <a href="/transport/driver/dashboard.php">
        <i class="fas fa-truck"></i>
        <span class="nav-label">Chuyến xe</span>
    </a>
    <a href="attendance.php">
        <i class="fas fa-clock"></i>
        <span class="nav-label">Chấm công</span>
    </a>
    <a href="ot_request.php">
        <i class="fas fa-business-time"></i>
        <span class="nav-label">OT</span>
    </a>
    <a href="leave_request.php">
        <i class="fas fa-calendar-minus"></i>
        <span class="nav-label">Nghỉ phép</span>
    </a>
    <a href="index.php" class="active">
        <i class="fas fa-home"></i>
        <span class="nav-label">HR Home</span>
    </a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>