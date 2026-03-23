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

$pdo = getDBConnection();

$viewMonth = (int)($_GET['month'] ?? date('m'));
$viewYear  = (int)($_GET['year']  ?? date('Y'));
if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

function dQuery(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

// Chấm công tháng
$monthLogs = dQuery($pdo,
    "SELECT * FROM hr_attendance
     WHERE user_id=? AND EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?
     ORDER BY work_date",
    [$user['id'], $viewMonth, $viewYear]);
$logsMap = [];
foreach ($monthLogs as $l) $logsMap[$l['work_date']] = $l;

// Nghỉ phép được duyệt
$leaves = dQuery($pdo,
    "SELECT * FROM hr_leaves WHERE user_id=? AND status='approved'
     AND ((EXTRACT(MONTH FROM date_from)=? AND EXTRACT(YEAR FROM date_from)=?)
       OR (EXTRACT(MONTH FROM date_to)=?   AND EXTRACT(YEAR FROM date_to)=?))",
    [$user['id'], $viewMonth, $viewYear, $viewMonth, $viewYear]);
$leaveDays = [];
foreach ($leaves as $lv) {
    $s = strtotime($lv['date_from']); $e = strtotime($lv['date_to']);
    for ($d = $s; $d <= $e; $d += 86400) $leaveDays[date('Y-m-d', $d)] = $lv['leave_type'];
}

// Thống kê
$totalWork   = count(array_filter($monthLogs, fn($l) => $l['check_in']));
$totalHours  = (float)array_sum(array_column($monthLogs, 'work_hours'));
$lateDays    = count(array_filter($monthLogs, fn($l) => ($l['is_late'] ?? 0)));
$absentDays  = 0;
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
for ($d = 1; $d <= $daysInMonth; $d++) {
    $ds  = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $d);
    $dow = (int)date('N', strtotime($ds));
    if ($dow === 7 || $ds > date('Y-m-d')) continue;
    if (!isset($logsMap[$ds]) && !isset($leaveDays[$ds])) $absentDays++;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Chấm công</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body { background:#f0f4f8; font-family:'Segoe UI',system-ui,sans-serif; padding-bottom:80px; }
.page-header {
    background:linear-gradient(135deg,#1a56db,#0e3a8c);
    color:#fff; padding:16px 20px;
    position:sticky; top:0; z-index:100;
}
.page-header .back-btn { color:#fff; text-decoration:none; font-size:1.1rem; }
.page-header h5 { margin:0; font-weight:700; font-size:1rem; }
.bottom-nav { position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid #e5e7eb;display:flex;z-index:200;box-shadow:0 -4px 16px rgba(0,0,0,.08); }
.bottom-nav a { flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:8px 4px;text-decoration:none;color:#9ca3af;font-size:.68rem;gap:3px; }
.bottom-nav a.active,.bottom-nav a:hover { color:#1a56db; }
.bottom-nav a i { font-size:1.3rem; }
.card-mobile { background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,.07);margin:0 16px 16px;overflow:hidden; }
.stat-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:0; }
.stat-item { padding:14px 8px;text-align:center;border-right:1px solid #f3f4f6; }
.stat-item:last-child { border-right:none; }
.stat-value { font-size:1.3rem;font-weight:800; }
.stat-label { font-size:.65rem;color:#6b7280;margin-top:2px; }
/* Calendar */
.cal-nav { display:flex;align-items:center;justify-content:space-between;padding:16px; }
.cal-nav-btn { background:#f3f4f6;border:none;border-radius:10px;padding:8px 14px;font-size:.9rem;color:#374151;cursor:pointer; }
.cal-month { font-weight:700;font-size:1rem; }
.calendar { width:100%;border-collapse:collapse; }
.calendar th { text-align:center;padding:8px 4px;font-size:.7rem;font-weight:700;color:#6b7280;text-transform:uppercase; }
.calendar th.sun { color:#ef4444; }
.calendar td { height:52px;vertical-align:top;padding:4px;border:1px solid #f3f4f6;position:relative;cursor:default; }
.day-num { font-size:.75rem;font-weight:600;margin-bottom:2px; }
.day-today .day-num { color:#1a56db;font-weight:800; }
.day-today { background:#eff6ff !important; }
.day-present { background:#f0fdf4; }
.day-late    { background:#fffbf0; }
.day-absent  { background:#fff5f5; }
.day-leave   { background:#e8f4fd; }
.day-future  { background:#fafafa;color:#d1d5db; }
.day-sun     { background:#f9fafb;color:#9ca3af; }
.status-dot { display:block;font-size:.65rem;text-align:center;margin-top:1px; }
/* Log list */
.log-item { display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #f3f4f6; }
.log-item:last-child { border-bottom:none; }
.log-date { min-width:56px;text-align:center;background:#f8fafc;border-radius:10px;padding:6px; }
.log-date .d { font-size:1.1rem;font-weight:800;line-height:1; }
.log-date .m { font-size:.65rem;color:#6b7280; }
.log-times { flex:1; }
.log-time-row { display:flex;gap:8px;align-items:center;font-size:.8rem; }
.badge-status { padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600; }
.badge-ok    { background:#d1fae5;color:#065f46; }
.badge-late  { background:#fef3c7;color:#92400e; }
.badge-absent{ background:#fee2e2;color:#991b1b; }
.badge-leave { background:#dbeafe;color:#1e40af; }
</style>
</head>
<body>

<!-- Header -->
<div class="page-header d-flex align-items-center gap-3">
    <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i></a>
    <h5>Chấm công của tôi</h5>
</div>

<div style="padding:16px 0 0;">

<!-- Thống kê -->
<div class="card-mobile" style="margin-top:0;">
    <div class="stat-grid">
        <div class="stat-item">
            <div class="stat-value text-success"><?= $totalWork ?></div>
            <div class="stat-label">Ngày công</div>
        </div>
        <div class="stat-item">
            <div class="stat-value text-primary"><?= number_format($totalHours,1) ?></div>
            <div class="stat-label">Tổng giờ</div>
        </div>
        <div class="stat-item">
            <div class="stat-value text-warning"><?= $lateDays ?></div>
            <div class="stat-label">Đi trễ</div>
        </div>
        <div class="stat-item">
            <div class="stat-value text-danger"><?= $absentDays ?></div>
            <div class="stat-label">Vắng</div>
        </div>
    </div>
</div>

<!-- Calendar navigation -->
<div class="card-mobile" style="margin-top:0;">
    <div class="cal-nav">
        <a href="?month=<?= $viewMonth-1 ?>&year=<?= $viewYear ?>" class="cal-nav-btn">
            <i class="fas fa-chevron-left"></i>
        </a>
        <span class="cal-month">Tháng <?= $viewMonth ?>/<?= $viewYear ?></span>
        <a href="?month=<?= $viewMonth+1 ?>&year=<?= $viewYear ?>" class="cal-nav-btn">
            <i class="fas fa-chevron-right"></i>
        </a>
    </div>

    <!-- Calendar grid -->
    <div style="padding:0 12px 16px;">
        <table class="calendar">
            <thead>
                <tr>
                    <th>T2</th><th>T3</th><th>T4</th><th>T5</th><th>T6</th><th>T7</th>
                    <th class="sun">CN</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $firstDay    = mktime(0, 0, 0, $viewMonth, 1, $viewYear);
            $startDow    = (int)date('N', $firstDay);
            $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
            echo '<tr>';
            for ($i = 1; $i < $startDow; $i++) echo '<td></td>';
            $col = $startDow;
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $ds      = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                $dow     = (int)date('N', mktime(0,0,0,$viewMonth,$day,$viewYear));
                $isToday = $ds === date('Y-m-d');
                $isSun   = $dow === 7;
                $future  = $ds > date('Y-m-d');
                $log     = $logsMap[$ds] ?? null;
                $isLeave = isset($leaveDays[$ds]);
                $cls = 'day-future';
                $icon = '';
                if ($isSun) { $cls='day-sun'; $icon=''; }
                elseif ($future) { $cls='day-future'; }
                elseif ($isLeave && !$log) { $cls='day-leave'; $icon='🏖️'; }
                elseif ($log && $log['check_in']) {
                    $cls  = ($log['is_late'] ?? 0) ? 'day-late' : 'day-present';
                    $icon = ($log['is_late'] ?? 0) ? '⚡' : '✓';
                } else { $cls='day-absent'; $icon='✗'; }
                $todayClass = $isToday ? 'day-today' : '';
                echo "<td class='$cls $todayClass'>
                    <div class='day-num'>$day</div>
                    <span class='status-dot'>$icon</span>
                </td>";
                if ($col % 7 === 0 && $day < $daysInMonth) echo '</tr><tr>';
                $col++;
            }
            while ($col % 7 !== 1) { echo '<td></td>'; $col++; }
            echo '</tr>';
            ?>
            </tbody>
        </table>
        <!-- Legend -->
        <div class="d-flex flex-wrap gap-2 mt-3" style="font-size:.7rem;">
            <span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:20px;">✓ Đúng giờ</span>
            <span style="color:#92400e;background:#fef3c7;padding:2px 8px;border-radius:20px;">⚡ Đi trễ</span>
            <span style="color:#1e40af;background:#dbeafe;padding:2px 8px;border-radius:20px;">🏖️ Nghỉ phép</span>
            <span style="color:#991b1b;background:#fee2e2;padding:2px 8px;border-radius:20px;">✗ Vắng</span>
        </div>
    </div>
</div>

<!-- Danh sách chi tiết -->
<div class="card-mobile" style="margin-top:0;">
    <div style="padding:12px 16px 8px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#6b7280;">
        Chi tiết chấm công
    </div>
    <?php
    $logsDesc = array_reverse($monthLogs);
    if (empty($logsDesc)): ?>
    <div style="text-align:center;padding:32px;color:#9ca3af;">
        <i class="fas fa-calendar-times" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.4;"></i>
        Chưa có dữ liệu chấm công
    </div>
    <?php else:
    foreach ($logsDesc as $log): ?>
    <div class="log-item">
        <div class="log-date">
            <div class="d"><?= date('d', strtotime($log['work_date'])) ?></div>
            <div class="m">T<?= date('m', strtotime($log['work_date'])) ?></div>
        </div>
        <div class="log-times">
            <div class="log-time-row">
                <span style="color:#059669;"><i class="fas fa-sign-in-alt"></i>
                    <?= $log['check_in'] ? date('H:i', strtotime($log['check_in'])) : '--:--' ?>
                </span>
                <span style="color:#9ca3af;">→</span>
                <span style="color:#dc2626;"><i class="fas fa-sign-out-alt"></i>
                    <?= $log['check_out'] ? date('H:i', strtotime($log['check_out'])) : '--:--' ?>
                </span>
                <span style="color:#6b7280;font-size:.75rem;"><?= $log['work_hours'] ? $log['work_hours'].'h' : '' ?></span>
            </div>
        </div>
        <div>
            <?php if ($log['is_late'] ?? 0): ?>
            <span class="badge-status badge-late">Trễ <?= $log['late_minutes'] ?? '' ?>p</span>
            <?php else: ?>
            <span class="badge-status badge-ok">Đúng giờ</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

</div>

<!-- Bottom Nav -->
<nav class="bottom-nav">
    <a href="index.php"><i class="fas fa-home"></i><span style="font-size:.68rem;">Trang chủ</span></a>
    <a href="attendance.php" class="active"><i class="fas fa-clock"></i><span style="font-size:.68rem;">Chấm công</span></a>
    <a href="ot_request.php"><i class="fas fa-business-time"></i><span style="font-size:.68rem;">OT</span></a>
    <a href="leave_request.php"><i class="fas fa-calendar-minus"></i><span style="font-size:.68rem;">Nghỉ phép</span></a>
</nav>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>