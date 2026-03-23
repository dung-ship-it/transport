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

$pageTitle = 'Chấm công tổng hợp HR';

function hrQuery(PDO $pdo, string $sql, array $p=[]): array {
    try { $s=$pdo->prepare($sql);$s->execute($p);return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch(Exception $e){error_log($e->getMessage());return [];}
}

$viewMonth   = (int)($_GET['month']   ?? date('m'));
$viewYear    = (int)($_GET['year']    ?? date('Y'));
$filterUser  = (int)($_GET['user_id'] ?? 0);
$filterDept  = (int)($_GET['dept_id'] ?? 0);
$viewMode    = $_GET['view'] ?? 'month';

if ($viewMonth<1)  { $viewMonth=12; $viewYear--; }
if ($viewMonth>12) { $viewMonth=1;  $viewYear++; }

$daysInMon = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);

// ── Xử lý import CSV ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_FILES['excel_file'])
    && verifyCSRF($_POST['csrf_token'] ?? '')) {

    $file = $_FILES['excel_file'];
    $importDate = $_POST['import_date'] ?? date('Y-m-d');
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $importOk = 0; $importErr = 0; $importMsgs = [];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Lỗi upload file.'];
    } elseif (!in_array($ext, ['csv'])) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Chỉ hỗ trợ file CSV.'];
    } else {
        $rows = [];
        $handle = fopen($file['tmp_name'], 'r');
        fgetcsv($handle); // bỏ header
        while (($row = fgetcsv($handle)) !== false) {
            if (!empty($row[0])) $rows[] = $row;
        }
        fclose($handle);

        foreach ($rows as $ln => $row) {
            $empCode  = trim($row[0] ?? '');
            $checkIn  = trim($row[1] ?? '');
            $checkOut = trim($row[2] ?? '');
            if (empty($empCode)) continue;

            $empRows = hrQuery($pdo,
                "SELECT id FROM users WHERE employee_code=? AND is_active=TRUE LIMIT 1",
                [$empCode]);
            if (empty($empRows)) {
                $importErr++;
                $importMsgs[] = "Dòng ".($ln+2).": Không tìm thấy mã NV <strong>$empCode</strong>";
                continue;
            }
            $userId = $empRows[0]['id'];
            $ciTs = $checkIn  ? date('Y-m-d H:i:s', strtotime("$importDate $checkIn"))  : null;
            $coTs = $checkOut ? date('Y-m-d H:i:s', strtotime("$importDate $checkOut")) : null;
            $wh   = ($ciTs && $coTs) ? min(round((strtotime($coTs)-strtotime($ciTs))/3600,2),24) : 0;

            try {
                if ($ciTs && $coTs) {
                    $pdo->prepare("INSERT INTO hr_attendance (user_id,work_date,check_in,check_out,work_hours,status,source)
                        VALUES (?,?,?,?,?,'present','import')
                        ON CONFLICT (user_id,work_date) DO UPDATE
                        SET check_in=EXCLUDED.check_in,check_out=EXCLUDED.check_out,
                            work_hours=EXCLUDED.work_hours,source='import'")
                        ->execute([$userId,$importDate,$ciTs,$coTs,$wh]);
                } elseif ($ciTs) {
                    $pdo->prepare("INSERT INTO hr_attendance (user_id,work_date,check_in,status,source)
                        VALUES (?,?,?,'present','import')
                        ON CONFLICT (user_id,work_date) DO UPDATE
                        SET check_in=EXCLUDED.check_in,source='import'")
                        ->execute([$userId,$importDate,$ciTs]);
                }
                $importOk++;
            } catch (Exception $e) {
                $importErr++;
                $importMsgs[] = "Dòng ".($ln+2)." ($empCode): ".$e->getMessage();
            }
        }
        $msg = "✅ Import thành công <strong>$importOk</strong> bản ghi.";
        if ($importErr) $msg .= " <strong>$importErr</strong> lỗi.";
        if (!empty($importMsgs)) $msg .= '<br><small>'.implode('<br>',$importMsgs).'</small>';
        $_SESSION['flash'] = ['type'=>$importOk>0?'success':'warning','msg'=>$msg];
    }
    header('Location: all.php?'.http_build_query(array_filter(['month'=>$viewMonth,'year'=>$viewYear,'view'=>$viewMode])));
    exit;
}

// ── Xuất Excel (CSV) ─────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cham_cong_'.$viewMonth.'_'.$viewYear.'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['Mã NV','Họ tên','Phòng ban','Ngày công','Làm CN','Nghỉ phép','Vắng','Đi trễ','Phút trễ','Tổng giờ','OT(h)']);

    $empExport = hrQuery($pdo,
        "SELECT u.id,u.full_name,u.employee_code,d.name AS dept_name
         FROM users u JOIN roles r ON u.role_id=r.id
         LEFT JOIN departments d ON u.department_id=d.id
         WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY d.name,u.full_name");

    $attAllExp = hrQuery($pdo,"SELECT * FROM hr_attendance WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",[$viewMonth,$viewYear]);
    $attMapExp=[]; foreach($attAllExp as $a) $attMapExp[$a['user_id']][$a['work_date']]=$a;
    $leaveAllExp = hrQuery($pdo,"SELECT * FROM hr_leaves WHERE status='approved' AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?) AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",[$viewMonth,$viewMonth,$viewYear,$viewYear]);
    $leaveMapExp=[]; foreach($leaveAllExp as $lv){$s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);for($d=$s;$d<=$e;$d+=86400)$leaveMapExp[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];}
    $otAllExp = hrQuery($pdo,"SELECT * FROM hr_overtime WHERE status='approved' AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",[$viewMonth,$viewYear]);
    $otMapExp=[]; foreach($otAllExp as $ot) $otMapExp[$ot['user_id']][$ot['ot_date']]=$ot;

    foreach($empExport as $emp){
        $st=calcStats($emp['id'],$attMapExp,$leaveMapExp,$otMapExp,$viewMonth,$viewYear,$daysInMon);
        fputcsv($out,[$emp['employee_code'],$emp['full_name'],$emp['dept_name']??'',$st['work_days'],$st['sunday_work'],$st['leave_days'],$st['absent_days'],$st['late_count'],$st['late_minutes'],number_format($st['total_hours'],1),$st['ot_hours']]);
    }
    fclose($out); exit;
}

// ── Data queries ─────────────────────────────────────────────
// Phòng ban
$depts = hrQuery($pdo,"SELECT * FROM departments ORDER BY name");

// Danh sách nhân viên
$empSQL = "SELECT u.id,u.full_name,u.employee_code,d.name AS dept_name,d.id AS dept_id
           FROM users u JOIN roles r ON u.role_id=r.id
           LEFT JOIN departments d ON u.department_id=d.id
           WHERE u.is_active=TRUE AND r.name NOT IN('customer')";
$empParams=[];
if ($filterUser) { $empSQL.=" AND u.id=?"; $empParams[]=$filterUser; }
if ($filterDept) { $empSQL.=" AND u.department_id=?"; $empParams[]=$filterDept; }
$empSQL.=" ORDER BY d.name,u.full_name";
$employees = hrQuery($pdo,$empSQL,$empParams);

// Map chấm công
$attRows = hrQuery($pdo,"SELECT * FROM hr_attendance WHERE EXTRACT(MONTH FROM work_date)=? AND EXTRACT(YEAR FROM work_date)=?",[$viewMonth,$viewYear]);
$attMap=[]; foreach($attRows as $a) $attMap[$a['user_id']][$a['work_date']]=$a;

// Map nghỉ phép
$leaveRows = hrQuery($pdo,"SELECT * FROM hr_leaves WHERE status='approved' AND (EXTRACT(MONTH FROM date_from)=? OR EXTRACT(MONTH FROM date_to)=?) AND (EXTRACT(YEAR FROM date_from)=? OR EXTRACT(YEAR FROM date_to)=?)",[$viewMonth,$viewMonth,$viewYear,$viewYear]);
$leaveMap=[]; foreach($leaveRows as $lv){$s=strtotime($lv['date_from']);$e=strtotime($lv['date_to']);for($d=$s;$d<=$e;$d+=86400)$leaveMap[$lv['user_id']][date('Y-m-d',$d)]=$lv['leave_type'];}

// Map OT
$otRows = hrQuery($pdo,"SELECT * FROM hr_overtime WHERE status='approved' AND EXTRACT(MONTH FROM ot_date)=? AND EXTRACT(YEAR FROM ot_date)=?",[$viewMonth,$viewYear]);
$otMap=[]; foreach($otRows as $ot) $otMap[$ot['user_id']][$ot['ot_date']]=$ot;

// ── KPI tổng ─────────────────────────────────────────────────
$kpiTotal    = count($employees);
$kpiChecked  = 0; $kpiLate = 0; $kpiNoOut = 0;
foreach($employees as $emp){
    $today = date('Y-m-d');
    $att   = $attMap[$emp['id']][$today] ?? null;
    if ($att && $att['check_in'])  $kpiChecked++;
    if ($att && ($att['is_late']??0)) $kpiLate++;
    if ($att && $att['check_in'] && !$att['check_out']) $kpiNoOut++;
}

function calcStats($uid,$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon):array {
    $st=['work_days'=>0,'absent_days'=>0,'leave_days'=>0,'late_count'=>0,'late_minutes'=>0,'total_hours'=>0,'ot_hours'=>0,'sunday_work'=>0];
    for($d=1;$d<=$daysInMon;$d++){
        $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
        $dow = (int)date('N',strtotime($ds));
        if($ds>date('Y-m-d')) continue;
        $att  =$attMap[$uid][$ds]  ??null;
        $leave=$leaveMap[$uid][$ds]??null;
        $ot   =$otMap[$uid][$ds]   ??null;
        if($ot) $st['ot_hours']+=$ot['ot_hours'];
        if($dow===7){
            if($att&&$att['check_in']){$st['work_days']++;$st['sunday_work']++;$st['total_hours']+=($att['work_hours']??0);}
            continue;
        }
        if($leave&&!$att) $st['leave_days']++;
        elseif($att&&$att['check_in']){
            $st['work_days']++;$st['total_hours']+=($att['work_hours']??0);
            if($att['is_late']??0){$st['late_count']++;$st['late_minutes']+=($att['late_minutes']??0);}
        } else $st['absent_days']++;
    }
    return $st;
}

// Nhóm nhân viên theo phòng ban
$grouped = [];
foreach($employees as $emp){
    $dept = $emp['dept_name'] ?? 'Chưa phân phòng';
    $grouped[$dept][] = $emp;
}

$empList = hrQuery($pdo,"SELECT u.id,u.full_name,u.employee_code FROM users u JOIN roles r ON u.role_id=r.id WHERE u.is_active=TRUE AND r.name NOT IN('customer') ORDER BY u.full_name");
$csrf = generateCSRF();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- Title + actions -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">📊 Bảng chấm công tổng hợp</h4>
        <small class="text-muted">Tháng <?=$viewMonth?>/<?=$viewYear?> · <?=count($employees)?> nhân viên</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?<?=http_build_query(array_merge($_GET,['export'=>1]))?>"
           class="btn btn-sm btn-success">
            <i class="fas fa-file-excel me-1"></i>Xuất Excel
        </a>
        <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
            <i class="fas fa-print me-1"></i>In
        </button>
        <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-upload me-1"></i>Import CSV
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i>Về chấm công
        </a>
    </div>
</div>

<?php showFlash(); ?>

<!-- KPI Cards -->
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

<!-- Bộ lọc -->
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <input type="hidden" name="view" value="<?=htmlspecialchars($viewMode)?>">
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
    <?php if (!empty($depts)): ?>
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
    <div class="col-md-2">
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
        <a href="all.php" class="btn btn-outline-secondary btn-sm">↺</a>
    </div>
    <div class="col-auto ms-auto">
        <div class="btn-group">
            <a href="?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>&view=month"
               class="btn btn-sm <?=$viewMode==='month'?'btn-primary':'btn-outline-primary'?>">
                <i class="fas fa-calendar me-1"></i>Tháng
            </a>
            <a href="?month=<?=$viewMonth?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>&view=summary"
               class="btn btn-sm <?=$viewMode==='summary'?'btn-primary':'btn-outline-primary'?>">
                <i class="fas fa-table me-1"></i>Tổng hợp
            </a>
        </div>
    </div>
</form>
</div>
</div>

<?php if ($viewMode==='month'): ?>
<!-- ══ VIEW THÁNG ════════════════════════════════════════════ -->

<!-- Chú thích -->
<div class="d-flex flex-wrap gap-2 mb-2 align-items-center" style="font-size:11px;">
    <span class="legend-item present">✓ Đúng giờ</span>
    <span class="legend-item late">⚡ Đi trễ</span>
    <span class="legend-item leave">📋 Nghỉ phép</span>
    <span class="legend-item absent">✗ Vắng</span>
    <span class="legend-item ot">OT</span>
    <span class="legend-item sunday">— Chủ nhật</span>
</div>

<!-- Điều hướng tháng -->
<div class="d-flex align-items-center gap-2 mb-2">
    <a href="?month=<?=$viewMonth-1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>&view=month"
       class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-left"></i></a>
    <strong>T<?=$viewMonth?>/<?=$viewYear?></strong>
    <a href="?month=<?=$viewMonth+1?>&year=<?=$viewYear?>&user_id=<?=$filterUser?>&dept_id=<?=$filterDept?>&view=month"
       class="btn btn-sm btn-outline-secondary"><i class="fas fa-chevron-right"></i></a>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div class="table-responsive" id="attTable">
<table class="table table-bordered table-sm mb-0 att-table">
    <thead>
    <tr class="table-dark">
        <th class="sticky-col" style="min-width:170px;z-index:5;">Nhân viên</th>
        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds  = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow = (int)date('N',strtotime($ds));
            $isToday=$ds===date('Y-m-d'); $isSun=$dow===7; $isSat=$dow===6;
        ?>
        <th class="text-center px-0
            <?=$isSun?'col-sun':($isSat?'col-sat':'')?>
            <?=$isToday?'col-today':''?>"
            style="min-width:44px;font-size:10px;">
            <div style="opacity:.8"><?=['','T2','T3','T4','T5','T6','T7','CN'][$dow]?></div>
            <div class="fw-bold"><?=$d?></div>
        </th>
        <?php endfor; ?>
        <th class="text-center" style="min-width:36px;font-size:10px;">Công</th>
        <th class="text-center" style="min-width:32px;font-size:10px;">Phép</th>
        <th class="text-center" style="min-width:32px;font-size:10px;">Vắng</th>
        <th class="text-center" style="min-width:32px;font-size:10px;">Trễ</th>
        <th class="text-center" style="min-width:42px;font-size:10px;">Trữ(p)</th>
        <th class="text-center" style="min-width:38px;font-size:10px;">OT(h)</th>
    </tr>
    </thead>
    <tbody>

    <?php foreach($grouped as $deptName => $emps): ?>
    <!-- Nhóm phòng ban -->
    <tr class="dept-row">
        <td colspan="<?=$daysInMon+7?>" class="py-1 px-3">
            <span style="font-size:11px;font-weight:700;color:#374151;">
                🏢 <?=htmlspecialchars($deptName)?>
            </span>
        </td>
    </tr>

    <?php foreach($emps as $emp):
        $st = calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
    ?>
    <tr class="emp-row">
        <td class="sticky-col">
            <div class="fw-semibold" style="font-size:11px;line-height:1.3;"><?=htmlspecialchars($emp['full_name'])?></div>
            <div style="font-size:9px;color:#6b7280;"><?=$emp['employee_code']?></div>
        </td>

        <?php for($d=1;$d<=$daysInMon;$d++):
            $ds    = sprintf('%04d-%02d-%02d',$viewYear,$viewMonth,$d);
            $dow   = (int)date('N',strtotime($ds));
            $isSun = $dow===7; $isSat = $dow===6;
            $future= $ds>date('Y-m-d');
            $att   = $attMap[$emp['id']][$ds]   ?? null;
            $leave = $leaveMap[$emp['id']][$ds] ?? null;
            $ot    = $otMap[$emp['id']][$ds]    ?? null;

            $cellClass = 'att-cell'; $cellContent = '';
            $title = '';

            if ($isSun) {
                if ($att && $att['check_in']) {
                    $ci = date('H:i', strtotime($att['check_in']));
                    $cellClass .= ' cell-sunday-work';
                    $cellContent = '<div class="ci">'.$ci.'</div>';
                    if ($ot) $cellContent .= '<div class="ot-badge">OT</div>';
                    $title = 'CN - Vào: '.$ci;
                    if ($att['check_out']) {
                        $co = date('H:i',strtotime($att['check_out']));
                        $cellContent .= '<div class="co">'.$co.'</div>';
                        $title .= ' Ra: '.$co;
                    }
                } else {
                    $cellClass .= ' cell-sun';
                    $cellContent = '<span class="dash">—</span>';
                }
            } elseif ($future) {
                $cellClass .= ' cell-future';
                $cellContent = '';
            } elseif ($leave && !$att) {
                $cellClass .= ' cell-leave';
                $leaveLabel = match($leave) {
                    'annual'  => 'Phép',
                    'sick'    => 'ốm',
                    'unpaid'  => 'KHL',
                    default   => 'Phép'
                };
                $cellContent = '<span class="leave-text">'.$leaveLabel.'</span>';
                $title = 'Nghỉ phép: '.$leave;
            } elseif ($att && $att['check_in']) {
                $isLate = $att['is_late'] ?? 0;
                $noOut  = empty($att['check_out']);
                $ci = date('H:i', strtotime($att['check_in']));
                $co = $att['check_out'] ? date('H:i', strtotime($att['check_out'])) : null;

                if ($noOut) {
                    $cellClass .= ' cell-noout';
                } elseif ($isLate) {
                    $cellClass .= ' cell-late';
                } else {
                    $cellClass .= ' cell-present';
                }

                $cellContent = '<div class="ci'.($isLate?' ci-late':'').'">'.$ci.'</div>';
                if ($co) {
                    $cellContent .= '<div class="co">'.$co.'</div>';
                } else {
                    $cellContent .= '<div class="co co-miss">?</div>';
                }
                if ($ot) $cellContent .= '<div class="ot-badge">OT</div>';

                $title = 'Vào: '.$ci;
                if ($co) $title .= ' Ra: '.$co;
                if ($isLate) $title .= ' (Trễ '.($att['late_minutes']??0).'p)';
                if ($noOut) $title .= ' ⚠️ Chưa ra';
            } else {
                $cellClass .= $isSat ? ' cell-sat-absent' : ' cell-absent';
                $cellContent = '<span class="absent-x">✗</span>';
                $title = 'Vắng';
            }
        ?>
        <td class="<?=$cellClass?>" title="<?=htmlspecialchars($title)?>"><?=$cellContent?></td>
        <?php endfor; ?>

        <!-- Tổng kết -->
        <td class="text-center fw-bold text-success" style="font-size:11px;"><?=$st['work_days']?></td>
        <td class="text-center text-info" style="font-size:11px;"><?=$st['leave_days']?:'-'?></td>
        <td class="text-center <?=$st['absent_days']>0?'text-danger fw-bold':'text-muted'?>" style="font-size:11px;"><?=$st['absent_days']?:'-'?></td>
        <td class="text-center <?=$st['late_count']>0?'text-warning fw-bold':'text-muted'?>" style="font-size:11px;"><?=$st['late_count']?:'-'?></td>
        <td class="text-center <?=$st['late_minutes']>0?'text-warning':'text-muted'?>" style="font-size:11px;"><?=$st['late_minutes']>0?$st['late_minutes'].'p':'-'?></td>
        <td class="text-center <?=$st['ot_hours']>0?'fw-bold':'text-muted'?>" style="font-size:11px;color:<?=$st['ot_hours']>0?'#7c3aed':'inherit'?>"><?=$st['ot_hours']>0?number_format($st['ot_hours'],1).'h':'-'?></td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>

    </tbody>
</table>
</div>
</div>
</div>

<?php else: ?>
<!-- ══ VIEW TỔNG HỢP ════════════════════════════════════════ -->
<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<table class="table table-hover table-bordered align-middle mb-0" style="font-size:.85rem">
    <thead class="table-dark">
        <tr>
            <th style="min-width:200px;">Nhân viên</th>
            <th class="text-center">Ngày công</th>
            <th class="text-center">Làm CN</th>
            <th class="text-center">Giờ làm</th>
            <th class="text-center">Nghỉ phép</th>
            <th class="text-center">Vắng</th>
            <th class="text-center">Đi trễ</th>
            <th class="text-center">Phút trễ</th>
            <th class="text-center">OT(h)</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach($grouped as $deptName => $emps): ?>
    <tr style="background:#f8fafc;">
        <td colspan="9" class="py-1 px-3 fw-bold text-secondary" style="font-size:11px;">
            🏢 <?=htmlspecialchars($deptName)?>
        </td>
    </tr>
    <?php foreach($emps as $emp):
        $st=calcStats($emp['id'],$attMap,$leaveMap,$otMap,$viewMonth,$viewYear,$daysInMon);
    ?>
    <tr>
        <td>
            <div class="fw-semibold"><?=htmlspecialchars($emp['full_name'])?></div>
            <div class="text-muted" style="font-size:11px;"><?=$emp['employee_code']?></div>
        </td>
        <td class="text-center fw-bold text-success"><?=$st['work_days']?></td>
        <td class="text-center" style="color:<?=$st['sunday_work']>0?'#7c3aed':'#9ca3af'?>;font-weight:<?=$st['sunday_work']>0?700:400?>">
            <?=$st['sunday_work']>0?$st['sunday_work']:'-'?>
        </td>
        <td class="text-center"><?=number_format($st['total_hours'],1)?>h</td>
        <td class="text-center text-info"><?=$st['leave_days']?:'-'?></td>
        <td class="text-center <?=$st['absent_days']>0?'text-danger fw-bold':'text-muted'?>"><?=$st['absent_days']?:'-'?></td>
        <td class="text-center"><?=$st['late_count']>0?'<span class="badge bg-warning text-dark">'.$st['late_count'].' lần</span>':'-'?></td>
        <td class="text-center text-warning"><?=$st['late_minutes']>0?$st['late_minutes'].'p':'-'?></td>
        <td class="text-center"><?=$st['ot_hours']>0?'<span class="badge" style="background:#7c3aed">'.number_format($st['ot_hours'],1).'h</span>':'-'?></td>
    </tr>
    <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>
<?php endif; ?>

</div>
</div>

<!-- Modal Import -->
<div class="modal fade" id="importModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
    <div class="modal-header border-0">
        <h6 class="modal-title fw-bold"><i class="fas fa-upload text-success me-2"></i>Import chấm công từ CSV</h6>
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
        <button type="submit" class="btn btn-success"><i class="fas fa-upload me-2"></i>Import</button>
    </div>
</form>
</div>
</div>
</div>

<style>
/* Layout */
.sticky-col { position:sticky;left:0;background:#fff;z-index:3;box-shadow:2px 0 4px rgba(0,0,0,.06); }
.att-table  { border-collapse:separate;border-spacing:0; }

/* Header cols */
.col-sun   { background:#1f1f1f!important;color:#f87171!important; }
.col-sat   { background:#2d2d2d!important;color:#fbbf24!important; }
.col-today { background:#1d4ed8!important;color:#fff!important; }

/* Body cells */
.att-cell  { height:42px;vertical-align:middle;text-align:center;padding:2px 1px;font-size:10px;line-height:1.2; }
.cell-present     { background:#f0fff4; }
.cell-late        { background:#fffbf0; }
.cell-absent      { background:#fff1f2; }
.cell-sat-absent  { background:#fafafa; }
.cell-leave       { background:#eff6ff; }
.cell-noout       { background:#fef3c7; }
.cell-sun         { background:#f9f9f9; }
.cell-sunday-work { background:#f5f3ff; }
.cell-future      { background:#fafafa; }

/* Cell content */
.ci         { color:#16a34a;font-weight:700;font-size:10px; }
.ci-late    { color:#d97706!important; }
.co         { color:#6b7280;font-size:9px; }
.co-miss    { color:#ef4444!important;font-weight:700; }
.absent-x   { color:#dc2626;font-weight:700;font-size:12px; }
.dash       { color:#d1d5db; }
.leave-text { color:#2563eb;font-weight:700;font-size:10px; }
.ot-badge   { display:inline-block;background:#7c3aed;color:#fff;font-size:8px;padding:0 3px;border-radius:3px;margin-top:1px; }

/* Groups */
.dept-row td { background:#f1f5f9!important;border-top:2px solid #e2e8f0!important; }
.emp-row:hover td { background:#fafbff!important; }
.emp-row:hover td.cell-present { background:#dcfce7!important; }
.emp-row:hover td.cell-absent  { background:#ffe4e6!important; }

/* Legend */
.legend-item { padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600; }
.legend-item.present { background:#f0fff4;color:#16a34a; }
.legend-item.late    { background:#fffbf0;color:#d97706; }
.legend-item.leave   { background:#eff6ff;color:#2563eb; }
.legend-item.absent  { background:#fff1f2;color:#dc2626; }
.legend-item.ot      { background:#f5f3ff;color:#7c3aed; }
.legend-item.sunday  { background:#f9f9f9;color:#9ca3af; }

/* Print */
@media print {
    .main-content { margin-left:0!important; }
    .btn, .modal, nav, .topbar, .sidebar { display:none!important; }
    .table-responsive { overflow:visible!important; }
}
</style>

<?php include '../../includes/footer.php'; ?>