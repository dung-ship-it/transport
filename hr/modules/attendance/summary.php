<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();

$canViewAll = can('hr','manage') || in_array($user['role']??'',['admin','director','accountant','manager']);
if (!$canViewAll) { header('Location: index.php'); exit; }

$pageTitle = 'Tổng hợp chấm công';

function hrQuery(PDO $pdo, string $sql, array $p=[]): array {
    try { $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e){error_log($e->getMessage());return [];}
}

$viewMonth  = (int)($_GET['month']   ?? date('m'));
$viewYear   = (int)($_GET['year']    ?? date('Y'));
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterDept = (int)($_GET['dept_id'] ?? 0);

if ($viewMonth<1)  { $viewMonth=12; $viewYear--; }
if ($viewMonth>12) { $viewMonth=1;  $viewYear++; }

$daysInMon = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);

// ── Xuất CSV ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tonghop_cham_cong_'.$viewMonth.'_'.$viewYear.'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['Mã NV','Họ tên','Phòng ban','Ngày công','Làm CN','Giờ làm','Nghỉ phép','Vắng','Đi trễ','Phút trễ','Thiếu giờ ra','OT(h)']);

    $empExp=hrQuery($pdo,"SELECT u.id,u.full_name,u.employee_code,COALESCE(d.name,'') AS dept_name FROM users u JOIN roles r ON u.role_id=r.id LEFT JOIN departments d ON u.department_id=d.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY dept_name,u.full_name");
    $aE=hrQuery($pdo,"SELECT * FROM hr_attendance WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",[$viewMonth,$viewYear]);
    $aM=[];foreach($aE as $a)$aM[$a['user_id']][$a['work_date']]=$a;
    $lE=hrQuery($pdo,"SELECT * FROM hr_leaves WHERE status='approved' AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?) AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",[$viewMonth,$viewMonth,$viewYear,$viewYear]);
    $lM=[];foreach($lE as $lv){$s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);for($d=$s;$d<=$e;$d+=86400)$lM[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];}
    $oE=hrQuery($pdo,"SELECT * FROM hr_overtime WHERE status='approved' AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",[$viewMonth,$viewYear]);
    $oM=[];foreach($oE as $ot)$oM[$ot['user_id']][$ot['ot_date']]=$ot;

    foreach($empExp as $emp){
        $st=calcStats($emp['id'],$aM,$lM,$oM,$viewMonth,$viewYear,$daysInMon);
        fputcsv($out,[
            $emp['employee_code'],$emp['full_name'],$emp['dept_name'],
            $st['work_days'],$st['sunday_work'],
            number_format($st['total_hours'],1),
            $st['leave_days'],$st['absent_days'],
            $st['late_count'],$st['late_minutes'],
            $st['no_checkout'],$st['ot_hours']
        ]);
    }
    fclose($out); exit;
}

// ── Data ─────────────────────────────────────────────────────
$hasDepts=false;
try{$pdo->query("SELECT 1 FROM departments LIMIT 1");$hasDepts=true;}catch(Exception $e){}
$depts=$hasDepts?hrQuery($pdo,"SELECT * FROM departments ORDER BY name"):[];

$empSQL="SELECT u.id,u.full_name,u.employee_code,".
    ($hasDepts?"COALESCE(d.name,'Chưa phân phòng')":"'Tất cả nhân viên'").
    " AS dept_name,".($hasDepts?"d.id":"0")." AS dept_id
    FROM users u JOIN roles r ON u.role_id=r.id ".
    ($hasDepts?"LEFT JOIN departments d ON u.department_id=d.id":"").
    " WHERE u.is_active=TRUE AND r.name NOT IN('customer')";
$empParams=[];
if($filterUser){$empSQL.=" AND u.id=?";$empParams[]=$filterUser;}
if($filterDept&&$hasDepts){$empSQL.=" AND u.department_id=?";$empParams[]=$filterDept;}
$empSQL.=" ORDER BY dept_name,u.full_name";
$employees=hrQuery($pdo,$empSQL,$empParams);

$attRows=hrQuery($pdo,"SELECT * FROM hr_attendance WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",[$viewMonth,$viewYear]);
$attMap=[];foreach($attRows as $a)$attMap[$a['user_id']][$a['work_date']]=$a;

$leaveRows=hrQuery($pdo,"SELECT * FROM hr_leaves WHERE status='approved' AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?) AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",[$viewMonth,$viewMonth,$viewYear,$viewYear]);
$leaveMap=[];foreach($leaveRows as $lv){$s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);for($d=$s;$d<=$e;$d+=86400)$leaveMap[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];}

$otRows=hrQuery($pdo,"SELECT * FROM hr_overtime WHERE status='approved' AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",[$viewMonth,$viewYear]);
$otMap=[];foreach($otRows as $ot)$otMap[$ot['user_id']][$ot['ot_date']]=$ot;

function calcStats($uid,$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon):array{
    $st=['work_days'=>0,'absent_days'=>0,'leave_days'=>0,'late_count'=>0,
         'late_minutes'=>0,'total_hours'=>0,'ot_hours'=>0,'sunday_work'=>0,'no_checkout'=>0];
    for($d=1;$d<=$daysInMon;$d++){
        $ds=sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow=(int)date('N',strtotime($ds));
        if($ds>date('Y-m-d'))continue;
        $att=$attMap[$uid][$ds]??null;
        $leave=$leaveMap[$uid][$ds]??null;
        $ot=$otMap[$uid][$ds]??null;
        if($ot)$st['ot_hours']+=$ot['ot_hours'];
        if($dow===7){
            if($att&&$att['check_in']){
                $st['work_days']++;$st['sunday_work']++;$st['total_hours']+=($att['work_hours']??0);
            }
            continue;
        }
        if($leave&&!$att)$st['leave_days']++;
        elseif($att&&$att['check_in']){
            $st['work_days']++;$st['total_hours']+=($att['work_hours']??0);
            if($att['is_late']??0){$st['late_count']++;$st['late_minutes']+=($att['late_minutes']??0);}
            if(empty($att['check_out']))$st['no_checkout']++;
        } else $st['absent_days']++;
    }
    return $st;
}

// ── Grand totals & group ─────────────────────────────────────
$grouped=[];
foreach($employees as $emp)$grouped[$emp['dept_name']][]=$emp;

$grand=['work_days'=>0,'total_hours'=>0,'sunday_work'=>0,'leave_days'=>0,
        'absent_days'=>0,'late_count'=>0,'late_minutes'=>0,
        'no_checkout'=>0,'ot_hours'=>0];

$empList=hrQuery($pdo,"SELECT u.id,u.full_name,u.employee_code FROM users u JOIN roles r ON u.role_id=r.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY u.full_name");

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-3">

<!-- ── Tiêu đề ── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📋 Tổng hợp chấm công</h4>
        <small class="text-muted">Tháng <?=$viewMonth?>/<?=$viewYear?> · <?=count($employees)?> nhân viên</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>1]))?>" class="btn btn-sm btn-success">
            <i class="fas fa-file-excel me-1"></i>Xuất Excel
        </a>
        <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>In
        </button>
        <a href="all.php?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
           class="btn btn-sm btn-outline-info">
            <i class="fas fa-calendar me-1"></i>Xem theo tháng
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Về chấm công
        </a>
    </div>
</div>

<!-- ── Bộ lọc ── -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1">Tháng</label>
        <select name="month" class="form-select form-select-sm">
            <?php for($m=1;$m<=12;$m++): ?>
            <option value="<?=$m?>" <?=$m==$viewMonth?'selected':''?>>Tháng <?=$m?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-6 col-md-1">
        <label class="form-label small fw-semibold mb-1">Năm</label>
        <select name="year" class="form-select form-select-sm">
            <?php for($y=date('Y')-1;$y<=date('Y')+1;$y++): ?>
            <option value="<?=$y?>" <?=$y==$viewYear?'selected':''?>><?=$y?></option>
            <?php endfor; ?>
        </select>
    </div>
    <?php if(!empty($depts)): ?>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Phòng ban</label>
        <select name="dept_id" class="form-select form-select-sm">
            <option value="">-- Tất cả --</option>
            <?php foreach($depts as $d): ?>
            <option value="<?=$d['id']?>" <?=$filterDept==$d['id']?'selected':''?>><?=htmlspecialchars($d['name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Nhân viên</label>
        <select name="user_id" class="form-select form-select-sm">
            <option value="">-- Tất cả --</option>
            <?php foreach($empList as $e): ?>
            <option value="<?=$e['id']?>" <?=$filterUser==$e['id']?'selected':''?>><?=htmlspecialchars($e['employee_code'].' - '.$e['full_name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="fas fa-search me-1"></i>Lọc
        </button>
        <a href="summary.php" class="btn btn-outline-secondary btn-sm">↺</a>
    </div>
    <!-- Điều hướng tháng -->
    <div class="col-auto ms-auto d-flex align-items-center gap-2">
        <a href="?month=<?=$viewMonth-1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
           class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i></a>
        <strong class="small">T<?=$viewMonth?>/<?=$viewYear?></strong>
        <a href="?month=<?=$viewMonth+1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
           class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-right"></i></a>
    </div>
</form>
</div>
</div>

<!-- ── Bảng tổng hợp ── -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-bordered table-hover align-middle mb-0 summary-table" id="summaryTable">
    <thead class="table-dark" style="position:sticky;top:0;z-index:5;">
        <tr>
            <th class="sticky-col" style="min-width:200px;z-index:10;">Nhân viên</th>
            <th class="text-center" style="min-width:60px;">
                <i class="fas fa-check-circle text-success me-1"></i>Ngày<br>công
            </th>
            <th class="text-center" style="min-width:55px;">
                <i class="fas fa-sun me-1" style="color:#f59e0b;"></i>Làm<br>CN
            </th>
            <th class="text-center" style="min-width:65px;">
                <i class="fas fa-clock text-primary me-1"></i>Giờ<br>làm
            </th>
            <th class="text-center" style="min-width:60px;">
                <i class="fas fa-umbrella-beach text-info me-1"></i>Nghỉ<br>phép
            </th>
            <th class="text-center" style="min-width:55px;">
                <i class="fas fa-times-circle text-danger me-1"></i>Vắng
            </th>
            <th class="text-center" style="min-width:55px;">
                <i class="fas fa-bolt text-warning me-1"></i>Đi<br>trễ
            </th>
            <th class="text-center" style="min-width:65px;">
                <i class="fas fa-stopwatch text-warning me-1"></i>Phút<br>trễ
            </th>
            <th class="text-center" style="min-width:65px;">
                <i class="fas fa-exclamation-triangle text-danger me-1"></i>Thiếu<br>giờ ra
            </th>
            <th class="text-center" style="min-width:65px;">
                <i class="fas fa-business-time me-1" style="color:#7c3aed;"></i>OT<br>(giờ)
            </th>
            <th class="text-center" style="min-width:80px;">
                <i class="fas fa-calculator me-1" style="color:#0ea5e9;"></i>Tổng<br>ngày làm
            </th>
        </tr>
    </thead>
    <tbody>

    <?php foreach($grouped as $deptName=>$emps): ?>
    <!-- Header phòng ban -->
    <tr class="dept-header">
        <td colspan="11" class="py-2 px-3">
            <i class="fas fa-building me-2"></i><?=htmlspecialchars($deptName)?>
            <span class="badge bg-secondary ms-2"><?=count($emps)?> người</span>
        </td>
    </tr>

    <?php
    $deptGrand=['work_days'=>0,'total_hours'=>0,'sunday_work'=>0,'leave_days'=>0,
                'absent_days'=>0,'late_count'=>0,'late_minutes'=>0,'no_checkout'=>0,'ot_hours'=>0];
    foreach($emps as $emp):
        $st=calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
        foreach($deptGrand as $k=>&$v) $v+=$st[$k]; unset($v);
        foreach($grand as $k=>&$v) $v+=$st[$k]; unset($v);
        // Tổng ngày = ngày công bình thường + làm CN
        $totalDays = $st['work_days']; // already includes sunday_work
    ?>
    <tr class="emp-row">
        <td class="sticky-col">
            <div class="fw-semibold" style="font-size:12px;"><?=htmlspecialchars($emp['full_name'])?></div>
            <div class="text-muted" style="font-size:10px;"><?=$emp['employee_code']?></div>
        </td>

        <!-- Ngày công (không tính CN) -->
        <td class="text-center">
            <span class="fs-6 fw-bold text-success"><?=$st['work_days']?></span>
        </td>

        <!-- Làm CN -->
        <td class="text-center">
            <?php if($st['sunday_work']>0): ?>
            <span class="badge" style="background:#7c3aed;"><?=$st['sunday_work']?></span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>

        <!-- Giờ làm -->
        <td class="text-center">
            <span class="fw-semibold text-primary"><?=number_format($st['total_hours'],1)?></span>
            <div class="text-muted" style="font-size:9px;">giờ</div>
        </td>

        <!-- Nghỉ phép -->
        <td class="text-center">
            <?php if($st['leave_days']>0): ?>
            <span class="badge bg-info text-white"><?=$st['leave_days']?> ngày</span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>

        <!-- Vắng -->
        <td class="text-center">
            <?php if($st['absent_days']>0): ?>
            <span class="badge bg-danger"><?=$st['absent_days']?> ngày</span>
            <?php else: ?>
            <span class="text-success" style="font-size:13px;">✓</span>
            <?php endif; ?>
        </td>

        <!-- Đi trễ -->
        <td class="text-center">
            <?php if($st['late_count']>0): ?>
            <span class="badge bg-warning text-dark"><?=$st['late_count']?> lần</span>
            <?php else: ?>
            <span class="text-success" style="font-size:13px;">✓</span>
            <?php endif; ?>
        </td>

        <!-- Phút trễ -->
        <td class="text-center">
            <?php if($st['late_minutes']>0): ?>
            <span class="fw-bold text-warning"><?=$st['late_minutes']?>p</span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>

        <!-- Thiếu giờ ra -->
        <td class="text-center">
            <?php if($st['no_checkout']>0): ?>
            <span class="badge bg-danger"><?=$st['no_checkout']?> lần</span>
            <?php else: ?>
            <span class="text-success" style="font-size:13px;">✓</span>
            <?php endif; ?>
        </td>

        <!-- OT -->
        <td class="text-center">
            <?php if($st['ot_hours']>0): ?>
            <span class="badge" style="background:#7c3aed;"><?=number_format($st['ot_hours'],1)?>h</span>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
        </td>

        <!-- Tổng ngày làm (bao gồm cả CN) -->
        <td class="text-center">
            <span class="fw-bold" style="font-size:14px;color:#0ea5e9;"><?=$totalDays?></span>
            <?php if($st['sunday_work']>0): ?>
            <div style="font-size:9px;color:#7c3aed;">+<?=$st['sunday_work']?> CN</div>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>

    <!-- Subtotal phòng ban -->
    <tr class="dept-subtotal">
        <td class="sticky-col text-muted" style="font-size:11px;font-style:italic;padding-left:24px;">
            Σ <?=htmlspecialchars($deptName)?>
        </td>
        <td class="text-center fw-bold text-success" style="font-size:11px;"><?=$deptGrand['work_days']?></td>
        <td class="text-center fw-bold" style="font-size:11px;color:#7c3aed;"><?=$deptGrand['sunday_work']?:'-'?></td>
        <td class="text-center fw-bold text-primary" style="font-size:11px;"><?=number_format($deptGrand['total_hours'],1)?>h</td>
        <td class="text-center text-info" style="font-size:11px;"><?=$deptGrand['leave_days']?:'-'?></td>
        <td class="text-center <?=$deptGrand['absent_days']>0?'text-danger fw-bold':'text-muted'?>" style="font-size:11px;"><?=$deptGrand['absent_days']?:'-'?></td>
        <td class="text-center <?=$deptGrand['late_count']>0?'text-warning fw-bold':'text-muted'?>" style="font-size:11px;"><?=$deptGrand['late_count']?:'-'?></td>
        <td class="text-center <?=$deptGrand['late_minutes']>0?'text-warning':'text-muted'?>" style="font-size:11px;"><?=$deptGrand['late_minutes']>0?$deptGrand['late_minutes'].'p':'-'?></td>
        <td class="text-center <?=$deptGrand['no_checkout']>0?'text-danger':'text-muted'?>" style="font-size:11px;"><?=$deptGrand['no_checkout']?:'-'?></td>
        <td class="text-center" style="font-size:11px;color:#7c3aed;"><?=$deptGrand['ot_hours']>0?number_format($deptGrand['ot_hours'],1).'h':'-'?></td>
        <td class="text-center fw-bold" style="font-size:11px;color:#0ea5e9;"><?=$deptGrand['work_days']?></td>
    </tr>

    <?php endforeach; ?>
    </tbody>

    <!-- ── GRAND TOTAL ── -->
    <tfoot>
    <tr class="grand-total">
        <td class="sticky-col fw-bold" style="font-size:13px;">
            <i class="fas fa-sigma me-1"></i>TỔNG CỘNG
        </td>
        <td class="text-center">
            <span class="fs-5 fw-bold text-success"><?=$grand['work_days']?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold" style="color:#7c3aed;"><?=$grand['sunday_work']?:'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold text-primary"><?=number_format($grand['total_hours'],1)?>h</span>
        </td>
        <td class="text-center">
            <span class="fw-bold text-info"><?=$grand['leave_days']?:'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold <?=$grand['absent_days']>0?'text-danger':'text-muted'?>"><?=$grand['absent_days']?:'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold <?=$grand['late_count']>0?'text-warning':'text-muted'?>"><?=$grand['late_count']?:'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold <?=$grand['late_minutes']>0?'text-warning':'text-muted'?>"><?=$grand['late_minutes']>0?$grand['late_minutes'].'p':'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold <?=$grand['no_checkout']>0?'text-danger':'text-muted'?>"><?=$grand['no_checkout']?:'-'?></span>
        </td>
        <td class="text-center">
            <span class="fw-bold" style="color:#7c3aed;"><?=$grand['ot_hours']>0?number_format($grand['ot_hours'],1).'h':'-'?></span>
        </td>
        <td class="text-center">
            <span class="fs-5 fw-bold" style="color:#0ea5e9;"><?=$grand['work_days']?></span>
        </td>
    </tr>
    </tfoot>
</table>
</div>
</div>
</div>

</div><!-- /container -->
</div><!-- /main-content -->

<style>
/* ── Layout ── */
.sticky-col {
    position: sticky; left: 0; background: #fff;
    z-index: 3; box-shadow: 2px 0 4px rgba(0,0,0,.07);
}
.summary-table { border-collapse: separate; border-spacing: 0; }
.summary-table th, .summary-table td {
    vertical-align: middle;
    font-size: 12px;
}

/* ── Rows ── */
.dept-header td {
    background: #1e293b !important;
    color: #e2e8f0 !important;
    font-weight: 700;
    font-size: 12px;
    border-top: 3px solid #334155 !important;
}
.dept-subtotal td {
    background: #f1f5f9 !important;
    border-top: 1px dashed #cbd5e1 !important;
    border-bottom: 2px solid #94a3b8 !important;
}
.emp-row:hover td { background: #f8fafc; }

/* ── Grand total ── */
.grand-total td {
    background: #0f172a !important;
    color: #f8fafc !important;
    border-top: 3px solid #3b82f6 !important;
    padding: 12px 8px !important;
}
.grand-total .sticky-col {
    background: #0f172a !important;
    color: #f8fafc !important;
}

/* ── Print ── */
@media print {
    .main-content { margin-left: 0 !important; }
    .btn, .modal, nav, .topbar, .sidebar, form { display: none !important; }
    .table-responsive { overflow: visible !important; }
    .sticky-col { position: static !important; box-shadow: none; }
    .dept-header td { background: #333 !important; -webkit-print-color-adjust: exact; }
    .grand-total td  { background: #111 !important; -webkit-print-color-adjust: exact; }
}
</style>

<?php include '../../includes/footer.php'; ?>