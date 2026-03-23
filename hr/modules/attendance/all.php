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

$pageTitle = 'Bảng chấm công tổng hợp';

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

// ── AJAX: Lưu chấm công từng ô ──────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['ajax_action']??'')==='save_att') {
    header('Content-Type: application/json');
    if (!verifyCSRF($_POST['csrf_token']??'')) {
        echo json_encode(['ok'=>false,'msg'=>'CSRF error']); exit;
    }
    $uid      = (int)$_POST['user_id'];
    $workDate = $_POST['work_date'];
    $checkIn  = trim($_POST['check_in']??'');
    $checkOut = trim($_POST['check_out']??'');
    $note     = trim($_POST['note']??'');
    $deleteIt = ($_POST['delete_record']??'0')==='1';

    if ($deleteIt) {
        try {
            $pdo->prepare("DELETE FROM hr_attendance WHERE user_id=? AND work_date=?")
                ->execute([$uid,$workDate]);
            echo json_encode(['ok'=>true,'msg'=>'Đã xóa bản ghi.']); exit;
        } catch(Exception $e){
            echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit;
        }
    }

    if (empty($checkIn)) {
        echo json_encode(['ok'=>false,'msg'=>'Vui lòng nhập giờ vào.']); exit;
    }

    $ciTs = date('Y-m-d H:i:s', strtotime("$workDate $checkIn"));
    $coTs = $checkOut ? date('Y-m-d H:i:s', strtotime("$workDate $checkOut")) : null;
    $wh   = 0;
    if ($ciTs && $coTs) {
        $diff = strtotime($coTs) - strtotime($ciTs);
        $wh   = $diff > 0 ? min(round($diff/3600,2), 24) : 0;
    }

    $isLate = 0; $lateMin = 0;
    try {
        $shiftStmt = $pdo->prepare("
            SELECT ws.start_time, ws.late_threshold
            FROM employee_shifts es
            JOIN work_shifts ws ON es.shift_id=ws.id
            WHERE es.user_id=? AND es.effective_date<=?
              AND (es.end_date IS NULL OR es.end_date>=?)
            ORDER BY es.effective_date DESC LIMIT 1");
        $shiftStmt->execute([$uid,$workDate,$workDate]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
        if ($shift) {
            $shiftStart = strtotime("$workDate ".$shift['start_time']);
            $actualIn   = strtotime($ciTs);
            $threshold  = (int)($shift['late_threshold']??15);
            $lateMin    = max(0,(int)(($actualIn-$shiftStart)/60));
            $isLate     = $lateMin>$threshold ? 1 : 0;
            if (!$isLate) $lateMin = 0;
        }
    } catch(Exception $e){}

    try {
        if ($coTs) {
            $pdo->prepare("INSERT INTO hr_attendance
                (user_id,work_date,check_in,check_out,work_hours,status,source,is_late,late_minutes,note)
                VALUES (?,?,?,?,?,'present','manual',?,?,?)
                ON CONFLICT (user_id,work_date) DO UPDATE
                SET check_in=EXCLUDED.check_in,check_out=EXCLUDED.check_out,
                    work_hours=EXCLUDED.work_hours,source='manual',
                    is_late=EXCLUDED.is_late,late_minutes=EXCLUDED.late_minutes,
                    note=EXCLUDED.note")
                ->execute([$uid,$workDate,$ciTs,$coTs,$wh,$isLate,$lateMin,$note]);
        } else {
            $pdo->prepare("INSERT INTO hr_attendance
                (user_id,work_date,check_in,status,source,is_late,late_minutes,note)
                VALUES (?,?,?,'present','manual',?,?,?)
                ON CONFLICT (user_id,work_date) DO UPDATE
                SET check_in=EXCLUDED.check_in,check_out=NULL,work_hours=0,source='manual',
                    is_late=EXCLUDED.is_late,late_minutes=EXCLUDED.late_minutes,
                    note=EXCLUDED.note")
                ->execute([$uid,$workDate,$ciTs,$isLate,$lateMin,$note]);
        }
        $lateMsg = $isLate ? " (Trễ {$lateMin}p)" : '';
        echo json_encode([
            'ok'=>true,
            'msg'=>"✅ Đã lưu chấm công ".date('d/m',strtotime($workDate)).$lateMsg,
            'ci'=>$checkIn,'co'=>$checkOut,'wh'=>number_format($wh,1),
            'isLate'=>$isLate,'latMin'=>$lateMin,'date'=>$workDate,'uid'=>$uid,
        ]);
    } catch(Exception $e){
        echo json_encode(['ok'=>false,'msg'=>'Lỗi: '.$e->getMessage()]);
    }
    exit;
}

// ── Import CSV ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST'
    && isset($_FILES['excel_file'])
    && verifyCSRF($_POST['csrf_token']??'')) {

    $file=$_FILES['excel_file'];
    $importDate=$_POST['import_date']??date('Y-m-d');
    $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION));
    $importOk=0;$importErr=0;$importMsgs=[];

    if ($file['error']!==UPLOAD_ERR_OK){
        $_SESSION['flash']=['type'=>'danger','msg'=>'❌ Lỗi upload file.'];
    } elseif ($ext!=='csv'){
        $_SESSION['flash']=['type'=>'danger','msg'=>'❌ Chỉ hỗ trợ file CSV.'];
    } else {
        $rows=[];
        $handle=fopen($file['tmp_name'],'r');
        fgetcsv($handle);
        while(($row=fgetcsv($handle))!==false){ if(!empty($row[0])) $rows[]=$row; }
        fclose($handle);

        foreach($rows as $ln=>$row){
            $empCode=trim($row[0]??'');
            $checkIn=trim($row[1]??'');
            $checkOut=trim($row[2]??'');
            if(empty($empCode)) continue;
            $empRows=hrQuery($pdo,"SELECT id FROM users WHERE employee_code=? AND is_active=TRUE LIMIT 1",[$empCode]);
            if(empty($empRows)){$importErr++;$importMsgs[]="Dòng ".($ln+2).": Không tìm thấy mã NV <strong>$empCode</strong>";continue;}
            $userId=$empRows[0]['id'];
            $ciTs=$checkIn  ? date('Y-m-d H:i:s',strtotime("$importDate $checkIn"))  : null;
            $coTs=$checkOut ? date('Y-m-d H:i:s',strtotime("$importDate $checkOut")) : null;
            $wh=($ciTs&&$coTs)?min(round((strtotime($coTs)-strtotime($ciTs))/3600,2),24):0;
            try {
                if($ciTs&&$coTs){
                    $pdo->prepare("INSERT INTO hr_attendance(user_id,work_date,check_in,check_out,work_hours,status,source)
                        VALUES(?,?,?,?,?,'present','import')
                        ON CONFLICT(user_id,work_date) DO UPDATE
                        SET check_in=EXCLUDED.check_in,check_out=EXCLUDED.check_out,
                            work_hours=EXCLUDED.work_hours,source='import'")
                        ->execute([$userId,$importDate,$ciTs,$coTs,$wh]);
                } elseif($ciTs){
                    $pdo->prepare("INSERT INTO hr_attendance(user_id,work_date,check_in,status,source)
                        VALUES(?,?,?,'present','import')
                        ON CONFLICT(user_id,work_date) DO UPDATE
                        SET check_in=EXCLUDED.check_in,source='import'")
                        ->execute([$userId,$importDate,$ciTs]);
                }
                $importOk++;
            } catch(Exception $e){$importErr++;$importMsgs[]="Dòng ".($ln+2)." ($empCode): ".$e->getMessage();}
        }
        $msg="✅ Import thành công <strong>$importOk</strong> bản ghi.";
        if($importErr) $msg.=" <strong>$importErr</strong> lỗi.";
        if(!empty($importMsgs)) $msg.='<br><small>'.implode('<br>',$importMsgs).'</small>';
        $_SESSION['flash']=['type'=>$importOk>0?'success':'warning','msg'=>$msg];
    }
    header('Location: all.php?'.http_build_query(array_filter(['month'=>$viewMonth,'year'=>$viewYear])));
    exit;
}

// ── Xuất CSV ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cham_cong_'.$viewMonth.'_'.$viewYear.'.csv"');
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','w');
    fputcsv($out,['Mã NV','Họ tên','Phòng ban','Ngày công','Làm CN','Nghỉ phép','Vắng','Đi trễ','Phút trễ','Tổng giờ','OT(h)']);

    $empExp=hrQuery($pdo,"SELECT u.id,u.full_name,u.employee_code,COALESCE(d.name,'') AS dept_name FROM users u JOIN roles r ON u.role_id=r.id LEFT JOIN departments d ON u.department_id=d.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY dept_name,u.full_name");
    $aE=hrQuery($pdo,"SELECT * FROM hr_attendance WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",[$viewMonth,$viewYear]);
    $aM=[];foreach($aE as $a)$aM[$a['user_id']][$a['work_date']]=$a;
    $lE=hrQuery($pdo,"SELECT * FROM hr_leaves WHERE status='approved' AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?) AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",[$viewMonth,$viewMonth,$viewYear,$viewYear]);
    $lM=[];foreach($lE as $lv){$s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);for($d=$s;$d<=$e;$d+=86400)$lM[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];}
    $oE=hrQuery($pdo,"SELECT * FROM hr_overtime WHERE status='approved' AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",[$viewMonth,$viewYear]);
    $oM=[];foreach($oE as $ot)$oM[$ot['user_id']][$ot['ot_date']]=$ot;

    function calcStatsExport($uid,$aM,$lM,$oM,$vm,$vy,$dim):array{
        $st=['work_days'=>0,'absent_days'=>0,'leave_days'=>0,'late_count'=>0,'late_minutes'=>0,'total_hours'=>0,'ot_hours'=>0,'sunday_work'=>0];
        for($d=1;$d<=$dim;$d++){
            $ds=sprintf('%04d-%02d-%02d',$vy,$vm,$d);
            $dow=(int)date('N',strtotime($ds));
            if($ds>date('Y-m-d'))continue;
            $att=$aM[$uid][$ds]??null;$leave=$lM[$uid][$ds]??null;$ot=$oM[$uid][$ds]??null;
            if($ot)$st['ot_hours']+=$ot['ot_hours'];
            if($dow===7){if($att&&$att['check_in']){$st['work_days']++;$st['sunday_work']++;$st['total_hours']+=($att['work_hours']??0);}continue;}
            if($leave&&!$att)$st['leave_days']++;
            elseif($att&&$att['check_in']){$st['work_days']++;$st['total_hours']+=($att['work_hours']??0);if($att['is_late']??0){$st['late_count']++;$st['late_minutes']+=($att['late_minutes']??0);}}
            else $st['absent_days']++;
        }
        return $st;
    }
    foreach($empExp as $emp){
        $st=calcStatsExport($emp['id'],$aM,$lM,$oM,$viewMonth,$viewYear,$daysInMon);
        fputcsv($out,[$emp['employee_code'],$emp['full_name'],$emp['dept_name'],$st['work_days'],$st['sunday_work'],$st['leave_days'],$st['absent_days'],$st['late_count'],$st['late_minutes'],number_format($st['total_hours'],1),$st['ot_hours']]);
    }
    fclose($out);exit;
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

$kpiTotal=count($employees);
$kpiChecked=$kpiLate=$kpiNoOut=0;
$todayStr=date('Y-m-d');
foreach($employees as $emp){
    $att=$attMap[$emp['id']][$todayStr]??null;
    if($att&&$att['check_in'])$kpiChecked++;
    if($att&&($att['is_late']??0))$kpiLate++;
    if($att&&$att['check_in']&&!$att['check_out'])$kpiNoOut++;
}

function calcStats($uid,$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon):array{
    $st=['work_days'=>0,'absent_days'=>0,'leave_days'=>0,'late_count'=>0,'late_minutes'=>0,
         'total_hours'=>0,'ot_hours'=>0,'sunday_work'=>0,'no_checkout'=>0];
    for($d=1;$d<=$daysInMon;$d++){
        $ds=sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow=(int)date('N',strtotime($ds));
        if($ds>date('Y-m-d'))continue;
        $att=$attMap[$uid][$ds]??null;
        $leave=$leaveMap[$uid][$ds]??null;
        $ot=$otMap[$uid][$ds]??null;
        if($ot)$st['ot_hours']+=$ot['ot_hours'];
        if($dow===7){
            if($att&&$att['check_in']){$st['work_days']++;$st['sunday_work']++;$st['total_hours']+=($att['work_hours']??0);}
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

$grouped=[];
foreach($employees as $emp)$grouped[$emp['dept_name']][]=$emp;

$empList=hrQuery($pdo,"SELECT u.id,u.full_name,u.employee_code FROM users u JOIN roles r ON u.role_id=r.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY u.full_name");
$csrf=generateCSRF();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-3">

<!-- ── Tiêu đề ── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📊 Bảng chấm công tổng hợp</h4>
        <small class="text-muted">Tháng <?=$viewMonth?>/<?=$viewYear?> · <?=count($employees)?> nhân viên</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>1]))?>" class="btn btn-sm btn-success">
            <i class="fas fa-file-excel me-1"></i>Xuất Excel
        </a>
        <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>In
        </button>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-upload me-1"></i>Import CSV
        </button>
        <a href="summary.php?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
           class="btn btn-sm btn-outline-info">
            <i class="fas fa-table me-1"></i>Xem tổng hợp
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Về chấm công
        </a>
    </div>
</div>

<?php showFlash(); ?>

<!-- ── KPI Cards ── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?=$kpiTotal?></div>
            <div class="small text-muted">👥 Tổng nhân viên</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?=$kpiChecked?></div>
            <div class="small text-muted">✅ NV đã chấm công</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?=$kpiLate?></div>
            <div class="small text-muted">⚡ Lượt đi trễ</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-danger"><?=$kpiNoOut?></div>
            <div class="small text-muted">⚠️ Thiếu giờ ra</div>
        </div>
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
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Lọc</button>
        <a href="all.php" class="btn btn-outline-secondary btn-sm">↺</a>
    </div>
</form>
</div>
</div>

<!-- ── Chú thích + điều hướng ── -->
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center" style="font-size:11px;">
    <span class="leg present">✓ Đúng giờ</span>
    <span class="leg late">⚡ Đi trễ</span>
    <span class="leg leave">📋 Nghỉ phép</span>
    <span class="leg absent">✗ Vắng</span>
    <span class="leg noout">? Thiếu giờ ra</span>
    <span class="leg ot">OT</span>
    <span class="leg sunday">— CN</span>
    <span class="ms-auto text-muted" style="font-size:10px;">
        <i class="fas fa-hand-pointer me-1"></i>Click vào ô để chấm công / chỉnh sửa
    </span>
</div>

<div class="d-flex align-items-center gap-2 mb-2">
    <a href="?month=<?=$viewMonth-1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
       class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i></a>
    <strong>T<?=$viewMonth?>/<?=$viewYear?></strong>
    <a href="?month=<?=$viewMonth+1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>"
       class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-right"></i></a>
</div>

<!-- ── Bảng chính ── -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive" style="max-height:75vh;overflow:auto;">
<table class="table table-bordered table-sm mb-0 att-table" id="attTable">
    <thead style="position:sticky;top:0;z-index:10;">
    <tr class="table-dark">
        <th class="sticky-col" style="min-width:180px;z-index:15;font-size:11px;">Nhân viên</th>
        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow = (int)date('N',strtotime($ds));
            $isToday=$ds===date('Y-m-d'); $isSun=$dow===7; $isSat=$dow===6;
        ?>
        <th class="text-center px-0
            <?=$isSun?'col-sun':($isSat?'col-sat':'')?>
            <?=$isToday?'col-today':''?>"
            style="min-width:52px;font-size:10px;">
            <div style="opacity:.75;font-size:9px;"><?=['','T2','T3','T4','T5','T6','T7','CN'][$dow]?></div>
            <div class="fw-bold"><?=$d?></div>
        </th>
        <?php endfor; ?>
        <!-- Cột tổng kết -->
        <th class="text-center th-sum" style="min-width:40px;font-size:10px;white-space:nowrap;">Công</th>
        <th class="text-center th-sum" style="min-width:38px;font-size:10px;white-space:nowrap;">Phép</th>
        <th class="text-center th-sum" style="min-width:38px;font-size:10px;white-space:nowrap;">Vắng</th>
        <th class="text-center th-sum" style="min-width:38px;font-size:10px;white-space:nowrap;">Trễ</th>
        <th class="text-center th-sum" style="min-width:44px;font-size:10px;white-space:nowrap;">Trễ(p)</th>
        <th class="text-center th-sum" style="min-width:44px;font-size:10px;white-space:nowrap;">⚠️Ra</th>
        <th class="text-center th-sum" style="min-width:44px;font-size:10px;white-space:nowrap;">OT(h)</th>
    </tr>
    </thead>
    <tbody>

    <?php foreach($grouped as $deptName=>$emps): ?>
    <tr class="dept-row">
        <td colspan="<?=$daysInMon+8?>" class="py-1 px-3">
            <span style="font-size:11px;font-weight:700;color:#374151;">🏢 <?=htmlspecialchars($deptName)?></span>
        </td>
    </tr>

    <?php foreach($emps as $emp):
        $st=calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
    ?>
    <tr class="emp-row" data-uid="<?=$emp['id']?>" data-name="<?=htmlspecialchars($emp['full_name'],ENT_QUOTES)?>">
        <td class="sticky-col" style="z-index:2;">
            <div class="fw-semibold" style="font-size:11px;line-height:1.3;"><?=htmlspecialchars($emp['full_name'])?></div>
            <div style="font-size:9px;color:#6b7280;"><?=$emp['employee_code']?></div>
        </td>

        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds    = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow   = (int)date('N',strtotime($ds));
            $isSun = $dow===7; $isSat=$dow===6;
            $future= $ds>date('Y-m-d');
            $att   = $attMap[$emp['id']][$ds]   ?? null;
            $leave = $leaveMap[$emp['id']][$ds] ?? null;
            $ot    = $otMap[$emp['id']][$ds]    ?? null;

            $cellClass='att-cell'; $cellContent=''; $title='';
            $ciVal=$att&&$att['check_in']  ? date('H:i',strtotime($att['check_in']))  : '';
            $coVal=$att&&$att['check_out'] ? date('H:i',strtotime($att['check_out'])) : '';
            $editable=!$future;

            if ($isSun){
                if($att&&$att['check_in']){
                    $ci=date('H:i',strtotime($att['check_in']));
                    $co=$att['check_out']?date('H:i',strtotime($att['check_out'])):null;
                    $cellClass.=' cell-sunday-work';
                    $cellContent='<div class="ci" style="color:#7c3aed;">'.$ci.'</div>';
                    $cellContent.=$co?'<div class="co">'.$co.'</div>':'<div class="co co-miss">?</div>';
                    if($ot) $cellContent.='<div class="ot-badge">OT</div>';
                    $title='CN · Vào:'.$ci.($co?' Ra:'.$co:'');
                } else {
                    $cellClass.=' cell-sun';
                    $cellContent='<span class="dash">—</span>';
                    $title='Chủ nhật';
                }
            } elseif($future){
                $cellClass.=' cell-future';
                $title='Ngày tương lai';
            } elseif($leave&&!$att){
                $cellClass.=' cell-leave';
                $leaveLabel=match($leave){'annual'=>'Phép','sick'=>'Ốm','unpaid'=>'KHL',default=>'Phép'};
                $cellContent='<span class="leave-text">'.$leaveLabel.'</span>';
                $title='Nghỉ phép: '.$leave;
            } elseif($att&&$att['check_in']){
                $isLate=$att['is_late']??0;
                $noOut=empty($att['check_out']);
                $ci=date('H:i',strtotime($att['check_in']));
                $co=$att['check_out']?date('H:i',strtotime($att['check_out'])):null;
                if($noOut)       $cellClass.=' cell-noout';
                elseif($isLate)  $cellClass.=' cell-late';
                else             $cellClass.=' cell-present';
                $cellContent='<div class="ci'.($isLate?' ci-late':'').'">'.$ci.'</div>';
                $cellContent.=$co?'<div class="co">'.$co.'</div>':'<div class="co co-miss">?</div>';
                if($ot) $cellContent.='<div class="ot-badge">OT</div>';
                $title='Vào:'.$ci.($co?' Ra:'.$co:'').($isLate?' ⚡Trễ'.($att['late_minutes']??0).'p':'').($noOut?' ⚠️Chưa ra':'');
            } else {
                $cellClass.=$isSat?' cell-sat-absent':' cell-absent';
                $cellContent='<span class="absent-x">✗</span>';
                $title='Vắng';
            }

            $dataAttrs=$editable
                ? sprintf('data-editable="1" data-date="%s" data-uid="%d" data-ci="%s" data-co="%s" data-note="%s" data-has="%d"',
                    $ds,$emp['id'],$ciVal,$coVal,
                    htmlspecialchars($att['note']??'',ENT_QUOTES),
                    $att?1:0)
                : '';
        ?>
        <td class="<?=$cellClass?> <?=$editable?'cell-editable':''?>"
            title="<?=htmlspecialchars($title)?>"
            <?=$dataAttrs?>><?=$cellContent?></td>
        <?php endfor; ?>

        <!-- Tổng kết hàng -->
        <td class="text-center fw-bold text-success stat-cell" style="font-size:11px;"><?=$st['work_days']?></td>
        <td class="text-center text-info stat-cell" style="font-size:11px;"><?=$st['leave_days']?:'-'?></td>
        <td class="text-center <?=$st['absent_days']>0?'text-danger fw-bold':'text-muted'?> stat-cell" style="font-size:11px;"><?=$st['absent_days']?:'-'?></td>
        <td class="text-center <?=$st['late_count']>0?'text-warning fw-bold':'text-muted'?> stat-cell" style="font-size:11px;"><?=$st['late_count']?:'-'?></td>
        <td class="text-center <?=$st['late_minutes']>0?'text-warning fw-bold':'text-muted'?> stat-cell" style="font-size:11px;"><?=$st['late_minutes']>0?$st['late_minutes'].'p':'-'?></td>
        <td class="text-center <?=$st['no_checkout']>0?'text-danger fw-bold':'text-muted'?> stat-cell" style="font-size:11px;"><?=$st['no_checkout']?:'-'?></td>
        <td class="text-center <?=$st['ot_hours']>0?'fw-bold':'text-muted'?> stat-cell" style="font-size:11px;color:<?=$st['ot_hours']>0?'#7c3aed':'inherit'?>"><?=$st['ot_hours']>0?number_format($st['ot_hours'],1).'h':'-'?></td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>

    </tbody>
</table>
</div>
</div>
</div>

</div><!-- /container -->
</div><!-- /main-content -->

<!-- ══ MODAL CHẤM CÔNG / CHỈNH SỬA ══ -->
<div class="modal fade" id="attModal" tabindex="-1">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header py-2 border-0" style="background:linear-gradient(135deg,#1d4ed8,#0e3a8c);">
        <div>
            <h6 class="modal-title text-white fw-bold mb-0" id="attModalTitle">Chấm công</h6>
            <div class="text-white opacity-75" style="font-size:11px;" id="attModalSub"></div>
        </div>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body p-3">
        <div id="attModalAlert" class="d-none mb-2"></div>

        <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">⏰ Giờ vào <span class="text-danger">*</span></label>
            <input type="time" id="attCi" class="form-control form-control-sm">
        </div>
        <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">⏰ Giờ ra</label>
            <input type="time" id="attCo" class="form-control form-control-sm">
            <div class="form-text" style="font-size:10px;">Để trống nếu chưa ra ca</div>
        </div>

        <div id="attPreview" class="rounded p-2 mb-3 d-none"
             style="background:#f0f9ff;border-left:3px solid #0ea5e9;font-size:11px;">
            <i class="fas fa-clock me-1 text-info"></i>
            <span id="attPreviewText"></span>
        </div>

        <div class="mb-3">
            <label class="form-label small fw-semibold mb-1">📝 Ghi chú</label>
            <input type="text" id="attNote" class="form-control form-control-sm"
                   placeholder="Ghi chú (tuỳ chọn)">
        </div>

        <div class="d-flex gap-2">
            <button type="button" class="btn btn-primary btn-sm flex-grow-1" id="attSaveBtn">
                <i class="fas fa-save me-1"></i>Lưu
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm" id="attDeleteBtn"
                    title="Xóa bản ghi này" style="display:none;">
                <i class="fas fa-trash"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Huỷ</button>
        </div>
    </div>
</div>
</div>
</div>

<!-- ══ MODAL IMPORT CSV ══ -->
<div class="modal fade" id="importModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
    <div class="modal-header border-0">
        <h6 class="modal-title fw-bold">
            <i class="fas fa-upload text-success me-2"></i>Import chấm công từ CSV
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="fas fa-info-circle me-1"></i>
            File CSV gồm 3 cột: <strong>mã nhân viên | giờ vào (HH:MM) | giờ ra (HH:MM)</strong>
            <br>
            <a href="download_template.php" class="fw-semibold mt-1 d-inline-block">
                <i class="fas fa-download me-1"></i>Tải file mẫu (.csv)
            </a>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Ngày chấm công <span class="text-danger">*</span></label>
            <input type="date" name="import_date" class="form-control" value="<?=date('Y-m-d')?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label fw-semibold">Chọn file CSV <span class="text-danger">*</span></label>
            <input type="file" name="excel_file" class="form-control" accept=".csv" required>
        </div>
        <div class="alert alert-warning py-2 small mb-0">
            <i class="fas fa-exclamation-triangle me-1"></i>
            Nếu đã có dữ liệu ngày đó sẽ <strong>cập nhật đè</strong>.
        </div>
    </div>
    <div class="modal-footer border-0">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
        <button type="submit" class="btn btn-success">
            <i class="fas fa-upload me-2"></i>Import
        </button>
    </div>
</form>
</div>
</div>
</div>

<style>
/* ── Layout ── */
.sticky-col {
    position: sticky; left: 0; background: #fff;
    z-index: 3; box-shadow: 2px 0 4px rgba(0,0,0,.07);
}
.att-table { border-collapse: separate; border-spacing: 0; }

/* ── Header cols ── */
.col-sun   { background: #1a1a2e !important; color: #f87171 !important; }
.col-sat   { background: #2d2d3d !important; color: #fbbf24 !important; }
.col-today { background: #1d4ed8 !important; color: #fff    !important; }
.th-sum    { background: #1e293b !important; color: #94a3b8 !important; }

/* ── Body cells ── */
.att-cell {
    height: 48px; vertical-align: middle; text-align: center;
    padding: 2px 2px; font-size: 10px; line-height: 1.25;
}
.cell-present     { background: #f0fff4; }
.cell-late        { background: #fffbf0; }
.cell-absent      { background: #fff1f2; }
.cell-sat-absent  { background: #f8f9fa; }
.cell-leave       { background: #eff6ff; }
.cell-noout       { background: #fef9c3; }
.cell-sun         { background: #f5f5f5; }
.cell-sunday-work { background: #f5f3ff; }
.cell-future      { background: #fafafa; }

/* ── Editable hover ── */
.cell-editable { cursor: pointer; transition: filter .12s, outline .12s; }
.cell-editable:hover {
    filter: brightness(.88);
    outline: 2px solid #3b82f6;
    outline-offset: -2px;
    z-index: 1;
    position: relative;
}

/* ── Cell content ── */
.ci         { color: #16a34a; font-weight: 700; font-size: 10px; }
.ci-late    { color: #d97706 !important; }
.co         { color: #6b7280; font-size: 9px; }
.co-miss    { color: #ef4444 !important; font-weight: 700; }
.absent-x   { color: #dc2626; font-weight: 700; font-size: 13px; }
.dash       { color: #d1d5db; font-size: 12px; }
.leave-text { color: #2563eb; font-weight: 700; font-size: 10px; }
.ot-badge   {
    display: inline-block; background: #7c3aed; color: #fff;
    font-size: 8px; padding: 0 3px; border-radius: 3px; margin-top: 1px;
}

/* ── Group rows ── */
.dept-row td {
    background: #1e293b !important;
    color: #e2e8f0 !important;
    border-top: 2px solid #334155 !important;
    font-size: 11px;
}
.emp-row:hover td { filter: brightness(.97); }

/* ── Stat cells ── */
.stat-cell { background: #f8fafc; }

/* ── Legend ── */
.leg {
    padding: 2px 8px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
}
.leg.present { background: #f0fff4; color: #16a34a; }
.leg.late    { background: #fffbf0; color: #d97706; }
.leg.leave   { background: #eff6ff; color: #2563eb; }
.leg.absent  { background: #fff1f2; color: #dc2626; }
.leg.noout   { background: #fef9c3; color: #92400e; }
.leg.ot      { background: #f5f3ff; color: #7c3aed; }
.leg.sunday  { background: #f5f5f5; color: #9ca3af; }

/* ── Print ── */
@media print {
    .main-content { margin-left: 0 !important; }
    .btn, .modal, nav, .topbar, .sidebar { display: none !important; }
    .table-responsive { overflow: visible !important; max-height: none !important; }
    .sticky-col { position: static !important; box-shadow: none; }
}
</style>

<script>
const CSRF = <?=json_encode($csrf)?>;
let currentCell = null;

// ── Mở modal khi click ô editable ──
document.getElementById('attTable')?.addEventListener('click', function(e) {
    const td = e.target.closest('td[data-editable]');
    if (!td) return;
    currentCell = td;

    const uid    = td.dataset.uid;
    const date   = td.dataset.date;
    const ci     = td.dataset.ci  || '';
    const co     = td.dataset.co  || '';
    const note   = td.dataset.note || '';
    const hasRec = td.dataset.has === '1';

    const empRow  = td.closest('tr');
    const empName = empRow?.dataset.name || '';

    const d   = new Date(date + 'T00:00:00');
    const days= ['CN','T2','T3','T4','T5','T6','T7'];
    const dow = days[d.getDay()];
    const df  = String(d.getDate()).padStart(2,'0') + '/'
              + String(d.getMonth()+1).padStart(2,'0') + '/'
              + d.getFullYear();

    document.getElementById('attModalTitle').textContent =
        hasRec ? '✏️ Chỉnh sửa chấm công' : '⏰ Chấm công mới';
    document.getElementById('attModalSub').textContent =
        empName + ' · ' + dow + ' ' + df;
    document.getElementById('attCi').value   = ci;
    document.getElementById('attCo').value   = co;
    document.getElementById('attNote').value = note;
    document.getElementById('attModalAlert').className = 'd-none mb-2';
    document.getElementById('attDeleteBtn').style.display = hasRec ? '' : 'none';

    document.getElementById('attSaveBtn').dataset.uid  = uid;
    document.getElementById('attSaveBtn').dataset.date = date;
    document.getElementById('attDeleteBtn').dataset.uid  = uid;
    document.getElementById('attDeleteBtn').dataset.date = date;

    updatePreview();
    new bootstrap.Modal(document.getElementById('attModal')).show();
});

// ── Preview giờ làm ──
function updatePreview() {
    const ci  = document.getElementById('attCi').value;
    const co  = document.getElementById('attCo').value;
    const prev= document.getElementById('attPreview');
    const txt = document.getElementById('attPreviewText');
    if (ci && co) {
        let [ch,cm]=ci.split(':').map(Number), [oh,om]=co.split(':').map(Number);
        let sm=ch*60+cm, em=oh*60+om;
        if(em<=sm) em+=1440;
        txt.textContent = `Số giờ làm: ${((em-sm)/60).toFixed(1)}h`;
        prev.classList.remove('d-none');
    } else {
        prev.classList.add('d-none');
    }
}
document.getElementById('attCi')?.addEventListener('input', updatePreview);
document.getElementById('attCo')?.addEventListener('input', updatePreview);

// ── Lưu chấm công ──
document.getElementById('attSaveBtn')?.addEventListener('click', async function() {
    const uid  = this.dataset.uid;
    const date = this.dataset.date;
    const ci   = document.getElementById('attCi').value;
    const co   = document.getElementById('attCo').value;
    const note = document.getElementById('attNote').value;

    if (!ci) { showAlert('warning','⚠️ Vui lòng nhập giờ vào.'); return; }
    setLoading(true);

    try {
        const fd = new FormData();
        fd.append('ajax_action','save_att');
        fd.append('csrf_token',CSRF);
        fd.append('user_id',uid);
        fd.append('work_date',date);
        fd.append('check_in',ci);
        fd.append('check_out',co);
        fd.append('note',note);

        const res  = await fetch('all.php',{method:'POST',body:fd});
        const data = await res.json();

        if (data.ok) {
            showAlert('success', data.msg);
            updateCell(currentCell, data);
            setTimeout(()=>{
                bootstrap.Modal.getInstance(document.getElementById('attModal'))?.hide();
            }, 900);
        } else {
            showAlert('danger','❌ '+data.msg);
        }
    } catch(e){ showAlert('danger','❌ Lỗi kết nối.'); }
    setLoading(false);
});

// ── Xóa bản ghi ──
document.getElementById('attDeleteBtn')?.addEventListener('click', async function() {
    if (!confirm('Xóa bản ghi chấm công này?')) return;
    const uid  = this.dataset.uid;
    const date = this.dataset.date;
    setLoading(true);

    try {
        const fd = new FormData();
        fd.append('ajax_action','save_att');
        fd.append('csrf_token',CSRF);
        fd.append('user_id',uid);
        fd.append('work_date',date);
        fd.append('check_in','');
        fd.append('delete_record','1');

        const res  = await fetch('all.php',{method:'POST',body:fd});
        const data = await res.json();

        if (data.ok) {
            if (currentCell) {
                currentCell.className = 'att-cell cell-absent cell-editable';
                currentCell.innerHTML = '<span class="absent-x">✗</span>';
                currentCell.dataset.ci  = '';
                currentCell.dataset.co  = '';
                currentCell.dataset.has = '0';
                currentCell.title = 'Vắng';
            }
            bootstrap.Modal.getInstance(document.getElementById('attModal'))?.hide();
        } else {
            showAlert('danger','❌ '+data.msg);
        }
    } catch(e){ showAlert('danger','❌ Lỗi kết nối.'); }
    setLoading(false);
});

// ── Cập nhật ô sau khi lưu ──
function updateCell(td, data) {
    if (!td) return;
    const ci     = data.ci;
    const co     = data.co;
    const isLate = data.isLate == 1;
    const latMin = data.latMin;

    td.className = 'att-cell cell-editable ';
    if (!co)          td.className += 'cell-noout';
    else if (isLate)  td.className += 'cell-late';
    else              td.className += 'cell-present';

    let html = `<div class="ci${isLate?' ci-late':''}">${ci}</div>`;
    html    += co ? `<div class="co">${co}</div>` : '<div class="co co-miss">?</div>';
    td.innerHTML = html;

    td.dataset.ci  = ci;
    td.dataset.co  = co;
    td.dataset.has = '1';
    td.title = `Vào:${ci}` + (co?` Ra:${co}`:'')
             + (isLate?` ⚡Trễ${latMin}p`:'')
             + (!co?' ⚠️Chưa ra':'');
}

// ── Helpers ──
function showAlert(type, msg) {
    const el = document.getElementById('attModalAlert');
    el.className = `alert alert-${type} py-2 small mb-2`;
    el.innerHTML = msg;
}
function setLoading(on) {
    const btn = document.getElementById('attSaveBtn');
    btn.disabled = on;
    btn.innerHTML = on
        ? '<span class="spinner-border spinner-border-sm me-1"></span>Đang lưu...'
        : '<i class="fas fa-save me-1"></i>Lưu';
}
</script>

<?php include '../../includes/footer.php'; ?>