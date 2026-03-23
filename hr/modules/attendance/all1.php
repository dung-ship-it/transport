<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();

// Chỉ manager, accountant, admin, director mới xem được tất cả
$canViewAll = can('hr','manage') || in_array($user['role']??'',['admin','director','accountant','manager']);
if (!$canViewAll) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Chấm công tổng hợp HR';

function hrQuery(PDO $pdo, string $sql, array $p=[]): array {
    try { $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e){error_log($e->getMessage());return [];}
}

$viewMonth  = (int)($_GET['month']   ?? date('m'));
$viewYear   = (int)($_GET['year']    ?? date('Y'));
$filterUser = (int)($_GET['user_id'] ?? 0);
$viewMode   = $_GET['view'] ?? 'month';

if ($viewMonth<1)  { $viewMonth=12; $viewYear--; }
if ($viewMonth>12) { $viewMonth=1;  $viewYear++; }

$daysInMon = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);

// Danh sách nhân viên
$empSQL = "SELECT u.id, u.full_name, u.employee_code FROM users u
           JOIN roles r ON u.role_id = r.id
           WHERE u.is_active = TRUE AND r.name NOT IN ('customer')";
$empParams = [];
if ($filterUser) { $empSQL .= " AND u.id = ?"; $empParams[] = $filterUser; }
$empSQL .= " ORDER BY u.full_name";
$employees = hrQuery($pdo, $empSQL, $empParams);

// Map chấm công
$attRows = hrQuery($pdo,
    "SELECT * FROM hr_attendance
     WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",
    [$viewMonth, $viewYear]);
$attMap = [];
foreach ($attRows as $a) $attMap[$a['user_id']][$a['work_date']] = $a;

// Map nghỉ phép
$leaveRows = hrQuery($pdo,
    "SELECT * FROM hr_leaves WHERE status='approved'
       AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?)
       AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",
    [$viewMonth, $viewMonth, $viewYear, $viewYear]);
$leaveMap = [];
foreach ($leaveRows as $lv) {
    $s=strtotime($lv['date_from']); $e=strtotime($lv['date_to']);
    for($d=$s;$d<=$e;$d+=86400) $leaveMap[$lv['user_id']][date('Y-m-d',$d)] = $lv['leave_type'];
}

// Map OT
$otRows = hrQuery($pdo,
    "SELECT * FROM hr_overtime WHERE status='approved'
       AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",
    [$viewMonth, $viewYear]);
$otMap = [];
foreach ($otRows as $ot) $otMap[$ot['user_id']][$ot['ot_date']] = $ot;

function calcStats($uid, $attMap, $leaveMap, $otMap, $viewMonth, $viewYear, $daysInMon) {
    $st = ['work_days'=>0,'absent_days'=>0,'leave_days'=>0,'late_count'=>0,'late_minutes'=>0,'total_hours'=>0,'ot_hours'=>0];
    for($d=1;$d<=$daysInMon;$d++) {
        $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow = (int)date('N',strtotime($ds));
        if($dow===7 || $ds>date('Y-m-d')) continue;
        $att   = $attMap[$uid][$ds]   ?? null;
        $leave = $leaveMap[$uid][$ds] ?? null;
        $ot    = $otMap[$uid][$ds]    ?? null;
        if($ot) $st['ot_hours']+=$ot['ot_hours'];
        if($leave && !$att)          $st['leave_days']++;
        elseif($att && $att['check_in']) {
            $st['work_days']++;
            $st['total_hours']+=$att['work_hours'];
            if($att['is_late']??0){ $st['late_count']++; $st['late_minutes']+=$att['late_minutes']??0; }
        } else $st['absent_days']++;
    }
    return $st;
}

$empList = hrQuery($pdo,
    "SELECT u.id, u.full_name, u.employee_code FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY u.full_name");

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">📊 Chấm công tổng hợp HR</h4>
        <small class="text-muted">Tháng <?=$viewMonth?>/<?=$viewYear?> · <?=count($employees)?> nhân viên</small>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-arrow-left me-1"></i>Về chấm công
    </a>
</div>

<!-- Bộ lọc -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Tháng</label>
        <select name="month" class="form-select form-select-sm">
            <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>" <?=$m==$viewMonth?'selected':''?>>Tháng <?=$m?></option><?php endfor; ?>
        </select>
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small fw-semibold mb-1">Năm</label>
        <select name="year" class="form-select form-select-sm">
            <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?><option value="<?=$y?>" <?=$y==$viewYear?'selected':''?>><?=$y?></option><?php endfor; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Nhân viên</label>
        <select name="user_id" class="form-select form-select-sm">
            <option value="">-- Tất cả --</option>
            <?php foreach($empList as $e): ?>
            <option value="<?=$e['id']?>" <?=$filterUser==$e['id']?'selected':''?>><?= htmlspecialchars($e['employee_code'].' - '.$e['full_name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2 d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm">Lọc</button>
        <a href="all.php" class="btn btn-outline-secondary btn-sm">↺</a>
    </div>
    <div class="col-md-3">
        <div class="btn-group">
            <a href="?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&view=month"
               class="btn btn-sm <?=$viewMode==='month'?'btn-primary':'btn-outline-primary'?>">📅 Tháng</a>
            <a href="?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&view=summary"
               class="btn btn-sm <?=$viewMode==='summary'?'btn-primary':'btn-outline-primary'?>">📋 Tổng hợp</a>
        </div>
    </div>
</form>
</div>
</div>

<?php if ($viewMode==='month'): ?>
<!-- View theo tháng (ma trận ngày) -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-bordered table-sm mb-0" style="font-size:11px;">
    <thead>
    <tr class="table-dark">
        <th class="sticky-col" style="min-width:160px;">Nhân viên</th>
        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow = (int)date('N',strtotime($ds));
            $isToday = $ds===date('Y-m-d');
            $isSun = $dow===7; ?>
        <th class="text-center px-0 <?=$isSun?'text-danger':''?> <?=$isToday?'bg-primary text-white':''?>"
            style="min-width:36px;">
            <div><?=['','T2','T3','T4','T5','T6','T7','CN'][$dow]?></div>
            <div class="fw-bold"><?=$d?></div>
        </th>
        <?php endfor; ?>
        <th class="text-center" style="min-width:40px;">Công</th>
        <th class="text-center" style="min-width:38px;">Phép</th>
        <th class="text-center" style="min-width:38px;">Vắng</th>
        <th class="text-center" style="min-width:38px;">Trễ</th>
        <th class="text-center" style="min-width:42px;">OT(h)</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach($employees as $emp):
        $st = calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
    ?>
    <tr>
        <td class="sticky-col">
            <div class="fw-semibold" style="font-size:12px;"><?= htmlspecialchars($emp['full_name']) ?></div>
            <div class="text-muted" style="font-size:10px;"><?= $emp['employee_code'] ?></div>
        </td>
        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds    = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow   = (int)date('N',strtotime($ds));
            $isSun = $dow===7;
            $future= $ds>date('Y-m-d');
            $att   = $attMap[$emp['id']][$ds]   ?? null;
            $leave = $leaveMap[$emp['id']][$ds] ?? null;
            $ot    = $otMap[$emp['id']][$ds]    ?? null;
            $bg='#fff'; $content='';
            if($isSun)              { $bg='#f5f5f5'; $content='<span style="color:#ccc">—</span>'; }
            elseif($future)         { $bg='#fafafa'; }
            elseif($leave && !$att) { $bg='#e8f4fd'; $content='<span style="color:#0284c7;font-weight:600;">Phép</span>'; }
            elseif($att && $att['check_in']) {
                $bg = ($att['is_late']??0) ? '#fffbf0' : '#f0fff4';
                $content = '<span style="color:'.( ($att['is_late']??0)?'#d97706':'#16a34a').';font-weight:bold;">'.(($att['is_late']??0)?'⚡':'✓').'</span>';
                if($ot) $content .= '<br><span style="color:#6f42c1;font-size:9px;">OT</span>';
            } else { $bg='#fff5f5'; $content='<span style="color:#dc2626;font-weight:bold;">✗</span>'; }
        ?>
        <td class="text-center p-0" style="background:<?=$bg?>;height:38px;vertical-align:middle;"><?=$content?></td>
        <?php endfor; ?>
        <td class="text-center fw-bold text-success"><?=$st['work_days']?></td>
        <td class="text-center text-info"><?=$st['leave_days']?:'-'?></td>
        <td class="text-center <?=$st['absent_days']>0?'text-danger fw-bold':'text-muted'?>"><?=$st['absent_days']?:'-'?></td>
        <td class="text-center <?=$st['late_count']>0?'text-warning fw-bold':'text-muted'?>"><?=$st['late_count']?:'-'?></td>
        <td class="text-center <?=$st['ot_hours']>0?'text-purple fw-bold':'text-muted'?>"><?=$st['ot_hours']>0?number_format($st['ot_hours'],1).'h':'-'?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
</div>

<?php else: ?>
<!-- View tổng hợp -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<table class="table table-hover table-bordered align-middle mb-0">
    <thead class="table-dark">
        <tr>
            <th class="sticky-col" style="min-width:180px;">Nhân viên</th>
            <th class="text-center">Ngày công</th>
            <th class="text-center">Giờ làm</th>
            <th class="text-center">Nghỉ phép</th>
            <th class="text-center">Vắng</th>
            <th class="text-center">Đi trễ</th>
            <th class="text-center">Phút trễ</th>
            <th class="text-center fw-bold">Tổng OT(h)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($employees as $emp):
        $st = calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
    ?>
    <tr>
        <td class="sticky-col">
            <div class="fw-semibold small"><?= htmlspecialchars($emp['full_name']) ?></div>
            <div class="text-muted" style="font-size:10px;"><?= $emp['employee_code'] ?></div>
        </td>
        <td class="text-center fw-bold text-success"><?=$st['work_days']?></td>
        <td class="text-center"><?=number_format($st['total_hours'],1)?>h</td>
        <td class="text-center text-info"><?=$st['leave_days']?:'-'?></td>
        <td class="text-center <?=$st['absent_days']>0?'text-danger fw-bold':'text-muted'?>"><?=$st['absent_days']?:'-'?></td>
        <td class="text-center"><?=$st['late_count']>0?'<span class="badge bg-warning text-dark">'.$st['late_count'].' lần</span>':'-'?></td>
        <td class="text-center small <?=$st['late_minutes']>0?'text-warning':''?>"><?=$st['late_minutes']>0?$st['late_minutes'].'p':'-'?></td>
        <td class="text-center fw-bold"><?=$st['ot_hours']>0?'<span class="badge" style="background:#6f42c1">'.number_format($st['ot_hours'],1).'h</span>':'-'?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>

</div>
</div>

<style>
.sticky-col{position:sticky;left:0;background:#fff;z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.06);}
.text-purple{color:#6f42c1!important;}
</style>
<?php include '../../includes/footer.php'; ?>