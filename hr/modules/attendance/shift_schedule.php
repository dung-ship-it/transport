<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();

$viewMonth  = (int)($_GET['month']  ?? date('m'));
$viewYear   = (int)($_GET['year']   ?? date('Y'));
$filterDept = (int)($_GET['dept']   ?? 0);
$filterUser = (int)($_GET['user_id']?? 0);

if ($viewMonth<1)  { $viewMonth=12; $viewYear--; }
if ($viewMonth>12) { $viewMonth=1;  $viewYear++; }

$daysInMon = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);

function ssq(PDO $pdo, string $sql, array $p=[]): array {
    try { $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e){ error_log($e->getMessage()); return []; }
}

// Kiểm tra departments
$hasDepts = false;
try { $pdo->query("SELECT 1 FROM departments LIMIT 1"); $hasDepts=true; } catch(Exception $e){}
$depts = $hasDepts ? ssq($pdo,"SELECT * FROM departments ORDER BY name") : [];

// Nhân viên + ca được phân công
$empSQL = "SELECT u.id, u.full_name, u.employee_code,
           " . ($hasDepts?"COALESCE(d.name,'Chưa phân phòng')":"'Tất cả'") . " AS dept_name,
           ws.id AS shift_id, ws.shift_name, ws.shift_code, ws.color AS shift_color,
           ws.start_time, ws.end_time, ws.late_threshold
    FROM users u
    JOIN roles r ON u.role_id=r.id
    " . ($hasDepts?"LEFT JOIN departments d ON u.department_id=d.id":"") . "
    LEFT JOIN employee_shifts es ON es.user_id=u.id
        AND es.effective_date <= CURRENT_DATE
        AND (es.end_date IS NULL OR es.end_date >= CURRENT_DATE)
    LEFT JOIN work_shifts ws ON es.shift_id=ws.id
    WHERE u.is_active=TRUE AND r.name NOT IN('customer')";

$empParams=[];
if($filterDept && $hasDepts){ $empSQL.=" AND u.department_id=?"; $empParams[]=$filterDept; }
if($filterUser){ $empSQL.=" AND u.id=?"; $empParams[]=$filterUser; }
$empSQL.=" ORDER BY dept_name, u.full_name";
$employees = ssq($pdo,$empSQL,$empParams);

// Map chấm công tháng
$attRows = ssq($pdo,
    "SELECT * FROM hr_attendance
     WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",
    [$viewMonth,$viewYear]);
$attMap=[];
foreach($attRows as $a) $attMap[$a['user_id']][$a['work_date']]=$a;

// Map nghỉ phép
$leaveRows = ssq($pdo,
    "SELECT * FROM hr_leaves WHERE status='approved'
     AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?)
     AND (EXTRACT(YEAR FROM date_from)=?  OR EXTRACT(YEAR FROM date_to)=?)",
    [$viewMonth,$viewMonth,$viewYear,$viewYear]);
$leaveMap=[];
foreach($leaveRows as $lv){
    $s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);
    for($d=$s;$d<=$e;$d+=86400) $leaveMap[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];
}

// Map OT
$otRows = ssq($pdo,
    "SELECT * FROM hr_overtime WHERE status='approved'
     AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",
    [$viewMonth,$viewYear]);
$otMap=[];
foreach($otRows as $ot) $otMap[$ot['user_id']][$ot['ot_date']]=$ot;

// KPI
$totalEmp = count($employees);
$hasShift = count(array_filter($employees,fn($e)=>$e['shift_id']));

// Nhóm theo phòng ban
$grouped=[];
foreach($employees as $emp) $grouped[$emp['dept_name']][]=$emp;

$empList = ssq($pdo,"SELECT u.id,u.full_name,u.employee_code FROM users u JOIN roles r ON u.role_id=r.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY u.full_name");

$pageTitle='Lịch ca tháng';
$canManage = can('hr','manage');
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📅 Lịch ca tháng <?=$viewMonth?>/<?=$viewYear?></h4>
        <small class="text-muted"><?=$totalEmp?> nhân viên · <?=$hasShift?> đã có ca</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if($canManage): ?>
        <a href="shift_assign.php" class="btn btn-success btn-sm">
            <i class="fas fa-user-clock me-1"></i>Phân công ca
        </a>
        <a href="shift_setup.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i>Setup ca
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-print me-1"></i>In
        </button>
    </div>
</div>

<?php showFlash(); ?>

<!-- Bộ lọc + điều hướng -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<div class="d-flex align-items-center gap-3 flex-wrap">
    <div class="d-flex align-items-center gap-2">
        <a href="?month=<?=$viewMonth-1?>&year=<?=$viewYear?>&dept=<?=$filterDept?>&user_id=<?=$filterUser?>"
           class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i></a>
        <strong>T<?=$viewMonth?>/<?=$viewYear?></strong>
        <a href="?month=<?=$viewMonth+1?>&year=<?=$viewYear?>&dept=<?=$filterDept?>&user_id=<?=$filterUser?>"
           class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-right"></i></a>
        <a href="?month=<?=date('m')?>&year=<?=date('Y')?>&dept=<?=$filterDept?>"
           class="btn btn-sm btn-outline-info">Tháng này</a>
    </div>

    <form method="GET" class="d-flex gap-2 align-items-center ms-auto flex-wrap">
        <?php if($hasDepts && !empty($depts)): ?>
        <select name="dept" class="form-select form-select-sm" style="width:160px;" onchange="this.form.submit()">
            <option value="">-- Phòng ban --</option>
            <?php foreach($depts as $d): ?>
            <option value="<?=$d['id']?>" <?=$filterDept==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="user_id" class="form-select form-select-sm" style="width:180px;" onchange="this.form.submit()">
            <option value="">-- Tất cả NV --</option>
            <?php foreach($empList as $e): ?>
            <option value="<?=$e['id']?>" <?=$filterUser==$e['id']?'selected':''?>><?=htmlspecialchars($e['employee_code'].' - '.$e['full_name'])?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" name="month" value="<?=$viewMonth?>">
        <input type="hidden" name="year"  value="<?=$viewYear?>">
        <a href="shift_schedule.php?month=<?=$viewMonth?>&year=<?=$viewYear?>" class="btn btn-sm btn-outline-secondary">↺</a>
    </form>
</div>
</div>
</div>

<!-- Chú thích -->
<div class="d-flex flex-wrap gap-2 mb-2" style="font-size:11px;">
    <span class="leg present">✓ Đúng giờ</span>
    <span class="leg late">⚡ Đi trễ</span>
    <span class="leg leave">📋 Nghỉ phép</span>
    <span class="leg absent">✗ Vắng</span>
    <span class="leg noout">? Thiếu giờ ra</span>
    <span class="leg ot">OT</span>
    <span class="leg sun">— CN</span>
    <span class="leg no-shift">⚠️ Chưa có ca</span>
</div>

<!-- Bảng lịch -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-bordered table-sm sch-table mb-0">
<thead>
<tr class="table-dark">
    <th class="sticky-col" style="min-width:170px;z-index:5;">Nhân viên</th>
    <th class="text-center sticky-col2" style="min-width:56px;z-index:5;">Ca</th>
    <?php for($d=1;$d<=$daysInMon;$d++):
        $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow = (int)date('N',strtotime($ds));
        $isToday=$ds===date('Y-m-d'); $isSun=$dow===7; $isSat=$dow===6;
    ?>
    <th class="text-center px-0
        <?=$isSun?'hdr-sun':($isSat?'hdr-sat':'')?>
        <?=$isToday?'hdr-today':''?>"
        style="min-width:44px;font-size:10px;">
        <div style="opacity:.8"><?=['','T2','T3','T4','T5','T6','T7','CN'][$dow]?></div>
        <div class="fw-bold"><?=$d?></div>
    </th>
    <?php endfor; ?>
    <th class="text-center" style="min-width:36px;font-size:10px;">Công</th>
    <th class="text-center" style="min-width:34px;font-size:10px;">Phép</th>
    <th class="text-center" style="min-width:34px;font-size:10px;">Vắng</th>
    <th class="text-center" style="min-width:34px;font-size:10px;">Trễ</th>
    <th class="text-center" style="min-width:42px;font-size:10px;">OT(h)</th>
</tr>
</thead>
<tbody>

<?php foreach($grouped as $deptName => $emps): ?>
<tr class="dept-row">
    <td colspan="<?=$daysInMon+7?>" class="py-1 px-3">
        <span class="fw-bold" style="font-size:11px;">🏢 <?=htmlspecialchars($deptName)?></span>
    </td>
</tr>

<?php foreach($emps as $emp):
    $workDays=0; $leaveDays=0; $absentDays=0; $lateDays=0; $otHours=0;
?>
<tr class="emp-row">
    <!-- Tên -->
    <td class="sticky-col">
        <div class="fw-semibold" style="font-size:11px;line-height:1.3;"><?=htmlspecialchars($emp['full_name'])?></div>
        <div style="font-size:9px;color:#6b7280;"><?=$emp['employee_code']?></div>
    </td>
    <!-- Ca mặc định -->
    <td class="text-center sticky-col2 p-1">
        <?php if($emp['shift_id']): ?>
        <span class="badge d-block" style="background:<?=$emp['shift_color']?>;font-size:9px;white-space:nowrap;">
            <?=htmlspecialchars($emp['shift_code'])?>
        </span>
        <div style="font-size:8px;color:#6b7280;margin-top:1px;">
            <?=substr($emp['start_time'],0,5)?>
        </div>
        <?php else: ?>
        <span style="font-size:9px;color:#dc2626;">⚠️ Chưa có</span>
        <?php endif; ?>
    </td>

    <!-- Từng ngày -->
    <?php for($d=1;$d<=$daysInMon;$d++):
        $ds    = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow   = (int)date('N',strtotime($ds));
        $isSun = $dow===7; $isSat=$dow===6;
        $future= $ds>date('Y-m-d');
        $att   = $attMap[$emp['id']][$ds]   ?? null;
        $leave = $leaveMap[$emp['id']][$ds] ?? null;
        $ot    = $otMap[$emp['id']][$ds]    ?? null;

        $cls='sch-cell'; $html=''; $title='';

        if($isSun) {
            if($att && $att['check_in']) {
                $cls.=' c-sun-work';
                $ci=date('H:i',strtotime($att['check_in']));
                $html='<div class="ci" style="color:#7c3aed">'.$ci.'</div>';
                if($att['check_out']) $html.='<div class="co">'.date('H:i',strtotime($att['check_out'])).'</div>';
                if($ot){ $html.='<div class="ot-b">OT</div>'; $otHours+=$ot['ot_hours']; }
                $workDays++;
            } else {
                $cls.=' c-sun';
                $html='<span class="dash">—</span>';
            }
        } elseif($future) {
            $cls.=' c-future';
            // Hiện ca kế hoạch nếu có
            if($emp['shift_id']) {
                $html='<span style="color:#c7d2fe;font-size:9px;">'.substr($emp['start_time'],0,5).'</span>';
            }
        } elseif($leave && !$att) {
            $cls.=' c-leave';
            $lbl=match($leave){'annual'=>'Phép','sick'=>'Ốm','unpaid'=>'KHL',default=>'Phép'};
            $html='<span class="leave-t">'.$lbl.'</span>';
            $title='Nghỉ phép: '.$leave;
            $leaveDays++;
        } elseif($att && $att['check_in']) {
            $isLate=$att['is_late']??0;
            $noOut=empty($att['check_out']);
            $ci=date('H:i',strtotime($att['check_in']));
            $co=$att['check_out']?date('H:i',strtotime($att['check_out'])):null;

            if($noOut) $cls.=' c-noout';
            elseif($isLate) $cls.=' c-late';
            else $cls.=' c-ok';

            $html='<div class="ci'.($isLate?' ci-l':'').'">'.$ci.'</div>';
            $html.=$co?'<div class="co">'.$co.'</div>':'<div class="co co-m">?</div>';
            if($ot){ $html.='<div class="ot-b">OT</div>'; $otHours+=$ot['ot_hours']; }

            $title='Vào: '.$ci.($co?' Ra: '.$co:'').($isLate?' ⚡Trễ '.$att['late_minutes'].'p':'');
            $workDays++;
            if($isLate) $lateDays++;
        } else {
            $cls.= $isSat?' c-sat-absent':' c-absent';
            $html='<span class="absent-x">✗</span>';
            if(!$isSat) $absentDays++;
        }
    ?>
    <td class="<?=$cls?>" title="<?=htmlspecialchars($title)?>"><?=$html?></td>
    <?php endfor; ?>

    <!-- Tổng kết -->
    <td class="text-center fw-bold text-success" style="font-size:11px;"><?=$workDays?></td>
    <td class="text-center text-info" style="font-size:11px;"><?=$leaveDays?:'-'?></td>
    <td class="text-center <?=$absentDays>0?'text-danger fw-bold':'text-muted'?>" style="font-size:11px;"><?=$absentDays?:'-'?></td>
    <td class="text-center <?=$lateDays>0?'text-warning fw-bold':'text-muted'?>" style="font-size:11px;"><?=$lateDays?:'-'?></td>
    <td class="text-center <?=$otHours>0?'fw-bold':'text-muted'?>" style="font-size:11px;color:<?=$otHours>0?'#7c3aed':'inherit'?>"><?=$otHours>0?number_format($otHours,1).'h':'-'?></td>
</tr>
<?php endforeach; ?>
<?php endforeach; ?>

</tbody>
</table>
</div>
</div>
</div>

</div>
</div>

<style>
/* Layout */
.sticky-col  { position:sticky;left:0;background:#fff;z-index:3;box-shadow:2px 0 4px rgba(0,0,0,.06); }
.sticky-col2 { position:sticky;left:170px;background:#fff;z-index:3;box-shadow:2px 0 2px rgba(0,0,0,.04); }
.sch-table th,.sch-table td { vertical-align:middle; }

/* Header */
.hdr-sun   { background:#1a1a1a!important;color:#f87171!important; }
.hdr-sat   { background:#2d2d2d!important;color:#fbbf24!important; }
.hdr-today { background:#1d4ed8!important;color:#fff!important; }

/* Cells */
.sch-cell  { height:42px;text-align:center;padding:2px 1px;font-size:10px;line-height:1.2; }
.c-ok      { background:#f0fff4; }
.c-late    { background:#fffbf0; }
.c-absent  { background:#fff1f2; }
.c-sat-absent { background:#fafafa; }
.c-leave   { background:#eff6ff; }
.c-noout   { background:#fef3c7; }
.c-sun     { background:#f9f9f9; }
.c-sun-work{ background:#f5f3ff; }
.c-future  { background:#fafafa; }

.ci       { color:#16a34a;font-weight:700;font-size:10px; }
.ci-l     { color:#d97706!important; }
.co       { color:#6b7280;font-size:9px; }
.co-m     { color:#ef4444!important;font-weight:700; }
.absent-x { color:#dc2626;font-weight:700;font-size:12px; }
.dash     { color:#d1d5db; }
.leave-t  { color:#2563eb;font-weight:700;font-size:10px; }
.ot-b     { display:inline-block;background:#7c3aed;color:#fff;font-size:7px;padding:0 3px;border-radius:3px; }

/* Groups */
.dept-row td  { background:#f1f5f9!important;border-top:2px solid #e2e8f0!important; }
.emp-row:hover td { filter:brightness(.97); }

/* Legend */
.leg { padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600; }
.leg.present  { background:#f0fff4;color:#16a34a; }
.leg.late     { background:#fffbf0;color:#d97706; }
.leg.leave    { background:#eff6ff;color:#2563eb; }
.leg.absent   { background:#fff1f2;color:#dc2626; }
.leg.noout    { background:#fef3c7;color:#92400e; }
.leg.ot       { background:#f5f3ff;color:#7c3aed; }
.leg.sun      { background:#f9f9f9;color:#9ca3af; }
.leg.no-shift { background:#fff1f2;color:#dc2626; }

/* Print */
@media print {
    .main-content { margin-left:0!important; }
    .btn,.modal,nav,.topbar,.sidebar { display:none!important; }
    .table-responsive { overflow:visible!important; }
}
</style>

<?php include '../../../includes/footer.php'; ?>