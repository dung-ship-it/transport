<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo    = getDBConnection();
$user   = currentUser();
$errors = [];
$editShift = null;

// ── XỬ LÝ FORM ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $sid   = (int)$_POST['shift_id'];
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM employee_shifts WHERE shift_id=?");
        $inUse->execute([$sid]);
        if ($inUse->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'��� Không thể xóa ca đang có nhân viên được phân công.'];
        } else {
            $pdo->prepare("DELETE FROM work_shifts WHERE id=?")->execute([$sid]);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xóa ca làm việc.'];
        }
        header('Location: shift_setup.php'); exit;
    }

    if ($action === 'toggle') {
        $sid    = (int)$_POST['shift_id'];
        $status = $_POST['current_status'] === 'true' || $_POST['current_status'] === '1';
        $pdo->prepare("UPDATE work_shifts SET is_active=? WHERE id=?")->execute([!$status, $sid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã cập nhật trạng thái ca.'];
        header('Location: shift_setup.php'); exit;
    }

    if (in_array($action, ['create','update'])) {
        $shift_code         = strtoupper(trim($_POST['shift_code'] ?? ''));
        $shift_name         = trim($_POST['shift_name'] ?? '');
        $start_time         = $_POST['start_time'] ?? '';
        $end_time           = $_POST['end_time'] ?? '';
        $late_threshold     = (int)($_POST['late_threshold'] ?? 15);
        $break_minutes      = (int)($_POST['break_minutes'] ?? 60);
        $work_hours         = (float)($_POST['work_hours'] ?? 8);
        $ot_multiplier      = (float)($_POST['ot_multiplier'] ?? 1.5);
        $weekend_multiplier = (float)($_POST['weekend_multiplier'] ?? 2.0);
        $holiday_multiplier = (float)($_POST['holiday_multiplier'] ?? 3.0);
        $color              = $_POST['color'] ?? '#0d6efd';
        $sid                = (int)($_POST['shift_id'] ?? 0);

        if (empty($shift_code)) $errors[] = 'Mã ca không được để trống.';
        if (empty($shift_name)) $errors[] = 'Tên ca không được để trống.';
        if (empty($start_time)) $errors[] = 'Giờ bắt đầu không được để trống.';
        if (empty($end_time))   $errors[] = 'Giờ kết thúc không được để trống.';

        if (empty($errors)) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM work_shifts WHERE shift_code=? AND id!=?");
            $chk->execute([$shift_code, $sid]);
            if ($chk->fetchColumn() > 0) $errors[] = 'Mã ca đã tồn tại.';
        }

        if (empty($errors)) {
            if ($action === 'create') {
                $pdo->prepare("INSERT INTO work_shifts
                    (shift_code,shift_name,start_time,end_time,late_threshold,break_minutes,
                     work_hours,ot_multiplier,weekend_multiplier,holiday_multiplier,color,created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$shift_code,$shift_name,$start_time,$end_time,$late_threshold,
                               $break_minutes,$work_hours,$ot_multiplier,$weekend_multiplier,
                               $holiday_multiplier,$color,$user['id']]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Đã tạo ca <strong>$shift_name</strong>!"];
            } else {
                $pdo->prepare("UPDATE work_shifts SET
                    shift_code=?,shift_name=?,start_time=?,end_time=?,late_threshold=?,
                    break_minutes=?,work_hours=?,ot_multiplier=?,weekend_multiplier=?,
                    holiday_multiplier=?,color=? WHERE id=?")
                    ->execute([$shift_code,$shift_name,$start_time,$end_time,$late_threshold,
                               $break_minutes,$work_hours,$ot_multiplier,$weekend_multiplier,
                               $holiday_multiplier,$color,$sid]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Đã cập nhật ca <strong>$shift_name</strong>."];
            }
            header('Location: shift_setup.php'); exit;
        }
        if ($action === 'update') { $editShift = $_POST; $editShift['id'] = $sid; }
    }
}

if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM work_shifts WHERE id=?");
    $s->execute([(int)$_GET['edit']]);
    $editShift = $s->fetch(PDO::FETCH_ASSOC);
}

$shifts = $pdo->query("SELECT ws.*, u.full_name AS creator,
    (SELECT COUNT(*) FROM employee_shifts es WHERE es.shift_id=ws.id) AS assigned_count
    FROM work_shifts ws LEFT JOIN users u ON ws.created_by=u.id
    ORDER BY ws.is_active DESC, ws.start_time ASC")->fetchAll(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
$pageTitle = 'Setup ca làm việc';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">⚙️ Setup Ca làm việc</h4>
        <p class="text-muted mb-0 small">Cấu hình giờ vào/ra, hệ số OT cho từng ca</p>
    </div>
    <div class="d-flex gap-2">
        <a href="shift_assign.php" class="btn btn-success btn-sm">
            <i class="fas fa-user-clock me-1"></i>Phân công ca
        </a>
        <a href="shift_schedule.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-calendar-alt me-1"></i>Lịch ca tháng
        </a>
    </div>
</div>

<?php showFlash(); ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
<!-- ── FORM ── -->
<div class="col-lg-5">
<div class="card border-0 shadow-sm">
    <div class="card-header fw-bold <?=$editShift?'bg-warning':'bg-primary text-white'?>">
        <?=$editShift?'✏️ Chỉnh sửa: '.htmlspecialchars($editShift['shift_name']):'➕ Tạo ca mới'?>
    </div>
    <div class="card-body">
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
        <input type="hidden" name="action" value="<?=$editShift?'update':'create'?>">
        <?php if($editShift): ?><input type="hidden" name="shift_id" value="<?=$editShift['id']?>"><?php endif; ?>

        <div class="row g-2 mb-3">
            <div class="col-5">
                <label class="form-label fw-semibold small">Mã ca <span class="text-danger">*</span></label>
                <input type="text" name="shift_code" class="form-control form-control-sm text-uppercase"
                       value="<?=htmlspecialchars($editShift['shift_code']??'')?>"
                       placeholder="VD: CA1" maxlength="20" required>
            </div>
            <div class="col-7">
                <label class="form-label fw-semibold small">Tên ca <span class="text-danger">*</span></label>
                <input type="text" name="shift_name" class="form-control form-control-sm"
                       value="<?=htmlspecialchars($editShift['shift_name']??'')?>"
                       placeholder="VD: Ca hành chính" required>
            </div>
        </div>

        <div class="card bg-light border-0 p-3 mb-3">
            <p class="fw-semibold small mb-2">🕐 Giờ làm việc</p>
            <div class="row g-2">
                <div class="col-6">
                    <label class="form-label small mb-1">Giờ bắt đầu <span class="text-danger">*</span></label>
                    <input type="time" name="start_time" id="startTime" class="form-control form-control-sm"
                           value="<?=$editShift['start_time']??'08:00'?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1">Giờ kết thúc <span class="text-danger">*</span></label>
                    <input type="time" name="end_time" id="endTime" class="form-control form-control-sm"
                           value="<?=$editShift['end_time']??'17:00'?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1">Nghỉ trưa (phút)</label>
                    <input type="number" name="break_minutes" id="breakMin" class="form-control form-control-sm"
                           value="<?=$editShift['break_minutes']??60?>" min="0" max="120">
                </div>
                <div class="col-6">
                    <label class="form-label small mb-1">Giờ chuẩn (tự tính)</label>
                    <input type="number" name="work_hours" id="workHours" class="form-control form-control-sm bg-white"
                           value="<?=$editShift['work_hours']??8?>" step="0.5" readonly>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1">Ngưỡng trễ (phút)</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text">±</span>
                        <input type="number" name="late_threshold" class="form-control form-control-sm"
                               value="<?=$editShift['late_threshold']??15?>" min="0" max="60">
                        <span class="input-group-text">phút</span>
                    </div>
                    <div class="form-text" style="font-size:10px;">Đến trễ trong khoảng này sẽ không bị tính trễ</div>
                </div>
            </div>
        </div>

        <div class="card bg-light border-0 p-3 mb-3">
            <p class="fw-semibold small mb-2">💰 Hệ số lương OT</p>
            <div class="row g-2">
                <div class="col-4">
                    <label class="form-label small mb-1">Ngày thường</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="ot_multiplier" class="form-control form-control-sm"
                               value="<?=$editShift['ot_multiplier']??1.5?>" step="0.25" min="1" max="5">
                        <span class="input-group-text">x</span>
                    </div>
                </div>
                <div class="col-4">
                    <label class="form-label small mb-1">Cuối tuần</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="weekend_multiplier" class="form-control form-control-sm"
                               value="<?=$editShift['weekend_multiplier']??2.0?>" step="0.25" min="1" max="5">
                        <span class="input-group-text">x</span>
                    </div>
                </div>
                <div class="col-4">
                    <label class="form-label small mb-1">Ngày lễ</label>
                    <div class="input-group input-group-sm">
                        <input type="number" name="holiday_multiplier" class="form-control form-control-sm"
                               value="<?=$editShift['holiday_multiplier']??3.0?>" step="0.25" min="1" max="5">
                        <span class="input-group-text">x</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold small">🎨 Màu hiển thị</label>
            <div class="d-flex gap-2 flex-wrap mb-2" id="colorPicker">
                <?php
                $colors = ['#0d6efd'=>'Xanh','#198754'=>'Xanh lá','#fd7e14'=>'Cam',
                           '#6f42c1'=>'Tím','#dc3545'=>'Đỏ','#20c997'=>'Ngọc',
                           '#6c757d'=>'Xám','#d63384'=>'Hồng','#0dcaf0'=>'Xanh nhạt'];
                $sel = $editShift['color'] ?? '#0d6efd';
                foreach($colors as $hex => $name):
                ?>
                <div class="color-dot <?=$hex===$sel?'selected':''?>"
                     style="background:<?=$hex?>;width:28px;height:28px;border-radius:50%;cursor:pointer;border:3px solid <?=$hex===$sel?'#000':'transparent'?>;"
                     data-color="<?=$hex?>" title="<?=$name?>" onclick="selectColor('<?=$hex?>')"></div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="color" id="colorInput" value="<?=$sel?>">
        </div>

        <!-- Preview -->
        <div id="shiftPreview" class="rounded p-2 mb-3 d-flex justify-content-between align-items-center"
             style="background:<?=$sel?>20;border-left:4px solid <?=$sel?>">
            <div>
                <div class="fw-bold" id="pvName"><?=htmlspecialchars($editShift['shift_name']??'Tên ca')?></div>
                <small class="text-muted" id="pvInfo"><?=$editShift['work_hours']??8?>h &bull; Trễ ±<?=$editShift['late_threshold']??15?>p</small>
            </div>
            <span class="badge" id="pvBadge" style="background:<?=$sel?>"><?=($editShift['start_time']??'08:00')?>–<?=($editShift['end_time']??'17:00')?></span>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-grow-1">
                <i class="fas fa-save me-1"></i><?=$editShift?'Lưu thay đổi':'Tạo ca'?>
            </button>
            <?php if($editShift): ?>
            <a href="shift_setup.php" class="btn btn-outline-secondary">Huỷ</a>
            <?php endif; ?>
        </div>
    </form>
    </div>
</div>
</div>

<!-- ── DANH SÁCH CA ── -->
<div class="col-lg-7">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold">
        📋 Danh sách ca (<?=count($shifts)?> ca)
    </div>
    <div class="card-body p-0">
    <?php if(empty($shifts)): ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-clock fa-3x mb-3 d-block opacity-25"></i>
        Chưa có ca nào. Hãy tạo ca đầu tiên!
    </div>
    <?php endif; ?>
    <?php foreach($shifts as $sh): ?>
    <div class="p-3 border-bottom <?=!$sh['is_active']?'opacity-50':''?>" style="<?=!$sh['is_active']?'background:#fafafa':''?>">
        <div class="d-flex align-items-start gap-3">
            <div style="background:<?=$sh['color']?>;width:14px;height:14px;border-radius:50%;margin-top:4px;flex-shrink:0;"></div>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div>
                        <span class="fw-bold"><?=htmlspecialchars($sh['shift_name'])?></span>
                        <code class="ms-2 small text-muted"><?=$sh['shift_code']?></code>
                        <?php if(!$sh['is_active']): ?><span class="badge bg-secondary ms-1">Tắt</span><?php endif; ?>
                    </div>
                    <span class="badge" style="background:<?=$sh['color']?>;font-size:12px;">
                        <?=substr($sh['start_time'],0,5)?>–<?=substr($sh['end_time'],0,5)?>
                    </span>
                </div>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <span class="badge bg-light text-dark border">⏱️ <?=$sh['work_hours']?>h</span>
                    <span class="badge bg-light text-dark border">🍽️ <?=$sh['break_minutes']?>p nghỉ</span>
                    <span class="badge bg-light text-dark border">⚡ trễ ±<?=$sh['late_threshold']?>p</span>
                    <span class="badge bg-warning text-dark">OT <?=$sh['ot_multiplier']?>x</span>
                    <span class="badge bg-info text-white">CN <?=$sh['weekend_multiplier']?>x</span>
                    <span class="badge bg-danger text-white">Lễ <?=$sh['holiday_multiplier']?>x</span>
                    <span class="badge bg-primary">👥 <?=$sh['assigned_count']?> NV</span>
                </div>
                <div class="d-flex gap-1">
                    <a href="?edit=<?=$sh['id']?>" class="btn btn-xs btn-outline-primary">
                        <i class="fas fa-edit me-1"></i>Sửa
                    </a>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="shift_id" value="<?=$sh['id']?>">
                        <input type="hidden" name="current_status" value="<?=$sh['is_active']?'true':'false'?>">
                        <button class="btn btn-xs <?=$sh['is_active']?'btn-outline-secondary':'btn-outline-success'?>">
                            <?=$sh['is_active']?'🔇 Tắt':'✅ Bật'?>
                        </button>
                    </form>
                    <?php if($sh['assigned_count']==0): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="shift_id" value="<?=$sh['id']?>">
                        <button class="btn btn-xs btn-outline-danger"
                                onclick="return confirm('Xóa ca <?=htmlspecialchars(addslashes($sh['shift_name']))?>?')">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
</div>
</div><!-- /row -->
</div>
</div>

<style>
.btn-xs { padding:2px 8px;font-size:12px; }
.color-dot.selected { transform:scale(1.2); }
</style>

<script>
function selectColor(hex) {
    document.querySelectorAll('.color-dot').forEach(el => {
        el.style.border='3px solid transparent'; el.classList.remove('selected');
    });
    const el = document.querySelector(`.color-dot[data-color="${hex}"]`);
    if(el){ el.style.border='3px solid #000'; el.classList.add('selected'); }
    document.getElementById('colorInput').value = hex;
    updatePreview();
}
function calcHours() {
    const s=document.getElementById('startTime').value;
    const e=document.getElementById('endTime').value;
    const b=parseInt(document.getElementById('breakMin').value)||0;
    if(!s||!e) return;
    let [sh,sm]=s.split(':').map(Number), [eh,em]=e.split(':').map(Number);
    let sm2=sh*60+sm, em2=eh*60+em;
    if(em2<=sm2) em2+=1440;
    document.getElementById('workHours').value=Math.max(0,((em2-sm2-b)/60).toFixed(2));
    updatePreview();
}
function updatePreview() {
    const name  = document.querySelector('[name="shift_name"]').value||'Tên ca';
    const start = document.getElementById('startTime').value;
    const end   = document.getElementById('endTime').value;
    const hours = document.getElementById('workHours').value;
    const late  = document.querySelector('[name="late_threshold"]').value;
    const color = document.getElementById('colorInput').value;
    document.getElementById('pvName').textContent  = name;
    document.getElementById('pvBadge').textContent = `${start}–${end}`;
    document.getElementById('pvBadge').style.background = color;
    document.getElementById('pvInfo').textContent  = `${hours}h • Trễ ±${late}p`;
    document.getElementById('shiftPreview').style.background    = color+'20';
    document.getElementById('shiftPreview').style.borderLeftColor = color;
}
['startTime','endTime','breakMin'].forEach(id=>document.getElementById(id)?.addEventListener('input',calcHours));
document.querySelector('[name="shift_name"]')?.addEventListener('input',updatePreview);
document.querySelector('[name="late_threshold"]')?.addEventListener('input',updatePreview);
</script>

<?php include '../../../includes/footer.php'; ?>