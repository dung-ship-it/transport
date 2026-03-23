<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo  = getDBConnection();
$user = currentUser();

// ── XỬ LÝ ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $user_ids       = array_map('intval', $_POST['user_ids'] ?? []);
        $shift_id       = (int)$_POST['shift_id'];
        $effective_date = $_POST['effective_date'];
        $end_date       = $_POST['end_date'] ?: null;

        if (empty($user_ids) || !$shift_id) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Vui lòng chọn ca và ít nhất 1 nhân viên.'];
        } else {
            $count = 0;
            foreach ($user_ids as $uid) {
                // Kết thúc ca cũ
                $pdo->prepare("UPDATE employee_shifts
                    SET end_date = ?::date - INTERVAL '1 day'
                    WHERE user_id=? AND (end_date IS NULL OR end_date >= ?)")
                    ->execute([$effective_date, $uid, $effective_date]);
                // Thêm ca mới
                $pdo->prepare("INSERT INTO employee_shifts (user_id,shift_id,effective_date,end_date,created_by)
                    VALUES (?,?,?,?,?)")
                    ->execute([$uid,$shift_id,$effective_date,$end_date,$user['id']]);
                $count++;
            }
            $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Đã phân công ca cho <strong>$count</strong> nhân viên."];
        }
        header('Location: shift_assign.php'); exit;
    }

    if ($action === 'remove') {
        $pdo->prepare("DELETE FROM employee_shifts WHERE id=?")->execute([(int)$_POST['assign_id']]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xóa phân công ca.'];
        header('Location: shift_assign.php'); exit;
    }

    if ($action === 'bulk_remove') {
        $ids = array_map('intval', $_POST['selected_ids'] ?? []);
        foreach ($ids as $id) {
            $pdo->prepare("DELETE FROM employee_shifts WHERE id=?")->execute([$id]);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xóa '.count($ids).' phân công.'];
        header('Location: shift_assign.php'); exit;
    }
}

// ── DATA ─────────────────────────────────────────────────────
$shifts = $pdo->query("SELECT * FROM work_shifts WHERE is_active=TRUE ORDER BY start_time")
              ->fetchAll(PDO::FETCH_ASSOC);

// Kiểm tra departments
$hasDepts = false;
try { $pdo->query("SELECT 1 FROM departments LIMIT 1"); $hasDepts = true; } catch(Exception $e){}
$depts = $hasDepts ? $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];

// Nhân viên + ca hiện tại
$empSQL = "SELECT u.id, u.full_name, u.employee_code,
           " . ($hasDepts ? "COALESCE(d.name,'Chưa phân phòng')" : "'Tất cả nhân viên'") . " AS dept_name,
           " . ($hasDepts ? "u.department_id" : "0") . " AS department_id,
           ws.shift_name AS current_shift, ws.color AS shift_color,
           ws.start_time, ws.end_time,
           es.effective_date, es.end_date AS assign_end, es.id AS assign_id
    FROM users u
    JOIN roles r ON u.role_id=r.id
    " . ($hasDepts ? "LEFT JOIN departments d ON u.department_id=d.id" : "") . "
    LEFT JOIN employee_shifts es ON es.user_id=u.id
        AND es.effective_date <= CURRENT_DATE
        AND (es.end_date IS NULL OR es.end_date >= CURRENT_DATE)
    LEFT JOIN work_shifts ws ON es.shift_id=ws.id
    WHERE u.is_active=TRUE AND r.name NOT IN('customer')
    ORDER BY dept_name, u.full_name";

$employees = $pdo->query($empSQL)->fetchAll(PDO::FETCH_ASSOC);

// Thống kê
$totalEmp    = count($employees);
$assignedEmp = count(array_filter($employees, fn($e) => $e['current_shift']));
$noShift     = $totalEmp - $assignedEmp;

$csrf = generateCSRF();
$pageTitle = 'Phân công ca';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">👥 Phân công Ca làm việc</h4>
        <p class="text-muted small mb-0">Gán ca mặc định cho từng nhân viên</p>
    </div>
    <div class="d-flex gap-2">
        <a href="shift_schedule.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-calendar-alt me-1"></i>Lịch ca tháng
        </a>
        <a href="shift_setup.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cog me-1"></i>Setup ca
        </a>
    </div>
</div>

<?php showFlash(); ?>

<!-- KPI -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?=$totalEmp?></div>
            <div class="small text-muted">👥 Tổng nhân viên</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?=$assignedEmp?></div>
            <div class="small text-muted">✅ Đã phân công</div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold <?=$noShift>0?'text-danger':'text-muted'?>"><?=$noShift?></div>
            <div class="small text-muted">⚠️ Chưa có ca</div>
        </div>
    </div>
</div>

<div class="row g-4">

<!-- ── FORM PHÂN CÔNG ── -->
<div class="col-lg-4">
<div class="card border-0 shadow-sm" style="position:sticky;top:70px;">
    <div class="card-header bg-success text-white fw-bold">➕ Phân công ca mới</div>
    <div class="card-body">
    <form method="POST" id="assignForm">
        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
        <input type="hidden" name="action" value="assign">

        <div class="mb-3">
            <label class="form-label fw-semibold small">Chọn ca <span class="text-danger">*</span></label>
            <select name="shift_id" class="form-select form-select-sm" required id="shiftSel">
                <option value="">-- Chọn ca --</option>
                <?php foreach($shifts as $sh): ?>
                <option value="<?=$sh['id']?>"
                        data-start="<?=substr($sh['start_time'],0,5)?>"
                        data-end="<?=substr($sh['end_time'],0,5)?>"
                        data-color="<?=$sh['color']?>">
                    <?=htmlspecialchars($sh['shift_name'])?> (<?=substr($sh['start_time'],0,5)?>–<?=substr($sh['end_time'],0,5)?>)
                </option>
                <?php endforeach; ?>
            </select>
            <div id="shiftBadge" class="mt-2 d-none">
                <span class="badge fs-6" id="shiftBadgeText"></span>
            </div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-6">
                <label class="form-label fw-semibold small">Từ ngày <span class="text-danger">*</span></label>
                <input type="date" name="effective_date" class="form-control form-control-sm"
                       value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-6">
                <label class="form-label fw-semibold small">Đến ngày</label>
                <input type="date" name="end_date" class="form-control form-control-sm">
                <div class="form-text" style="font-size:10px;">Trống = không giới hạn</div>
            </div>
        </div>

        <?php if($hasDepts && !empty($depts)): ?>
        <div class="mb-2">
            <label class="form-label fw-semibold small">Lọc phòng ban</label>
            <select class="form-select form-select-sm" id="deptFilter">
                <option value="">-- Tất cả --</option>
                <?php foreach($depts as $d): ?>
                <option value="<?=$d['id']?>"><?=htmlspecialchars($d['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="mb-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label fw-semibold small mb-0">
                    Chọn nhân viên <span class="text-danger">*</span>
                </label>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-xs btn-outline-primary" onclick="selAll(true)">Tất cả</button>
                    <button type="button" class="btn btn-xs btn-outline-secondary" onclick="selAll(false)">Bỏ chọn</button>
                    <button type="button" class="btn btn-xs btn-outline-warning" onclick="selNoShift()">Chưa có ca</button>
                </div>
            </div>
            <div class="border rounded" style="max-height:240px;overflow-y:auto;">
                <?php foreach($employees as $emp): ?>
                <div class="emp-item form-check px-3 py-2 border-bottom"
                     data-dept="<?=$emp['department_id']??0?>"
                     data-has-shift="<?=$emp['current_shift']?'1':'0'?>">
                    <input class="form-check-input emp-cb" type="checkbox"
                           name="user_ids[]" value="<?=$emp['id']?>" id="e<?=$emp['id']?>">
                    <label class="form-check-label w-100 small" for="e<?=$emp['id']?>">
                        <div class="fw-semibold"><?=htmlspecialchars($emp['full_name'])?></div>
                        <div class="text-muted" style="font-size:10px;">
                            <?=$emp['employee_code']?> &bull; <?=htmlspecialchars($emp['dept_name'])?>
                            <?php if($emp['current_shift']): ?>
                            &bull; <span class="badge" style="background:<?=$emp['shift_color']?>;font-size:9px;"><?=htmlspecialchars($emp['current_shift'])?></span>
                            <?php else: ?>
                            &bull; <span class="text-danger" style="font-size:10px;">Chưa có ca</span>
                            <?php endif; ?>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <small class="text-muted"><span id="selCount">0</span> NV được chọn</small>
        </div>

        <button type="submit" class="btn btn-success w-100 mt-2">
            <i class="fas fa-save me-2"></i>Phân công ca
        </button>
    </form>
    </div>
</div>
</div>

<!-- ── BẢNG HIỆN TẠI ── -->
<div class="col-lg-8">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
        <span>📋 Ca hiện tại của nhân viên</span>
        <div class="d-flex gap-2">
            <input type="text" id="searchEmp" placeholder="Tìm nhân viên..."
                   class="form-control form-control-sm" style="width:180px;">
        </div>
    </div>
    <div class="card-body p-0">
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?=$csrf?>">
        <input type="hidden" name="action" value="bulk_remove">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light">
            <tr>
                <th width="35">
                    <input type="checkbox" id="chkAll" onchange="document.querySelectorAll('.row-cb').forEach(c=>c.checked=this.checked)">
                </th>
                <th>Nhân viên</th>
                <th>Phòng ban</th>
                <th>Ca hiện tại</th>
                <th>Giờ làm</th>
                <th>Áp dụng từ</th>
                <th class="text-center">Xóa</th>
            </tr>
        </thead>
        <tbody id="empTableBody">
        <?php
        $prevDept2 = null;
        foreach($employees as $emp):
            if($emp['dept_name'] !== $prevDept2):
                $prevDept2 = $emp['dept_name'];
        ?>
        <tr class="table-secondary dept-header-row">
            <td colspan="7" class="py-1 ps-3 fw-bold small">
                🏢 <?=htmlspecialchars($emp['dept_name'])?>
            </td>
        </tr>
        <?php endif; ?>
        <tr class="emp-row" data-name="<?=strtolower(htmlspecialchars($emp['full_name']))?>" data-code="<?=strtolower($emp['employee_code'])?>">
            <td>
                <?php if($emp['assign_id']): ?>
                <input type="checkbox" class="form-check-input row-cb" name="selected_ids[]" value="<?=$emp['assign_id']?>">
                <?php endif; ?>
            </td>
            <td>
                <div class="fw-semibold"><?=htmlspecialchars($emp['full_name'])?></div>
                <div class="text-muted" style="font-size:11px;"><?=$emp['employee_code']?></div>
            </td>
            <td><small class="text-muted"><?=htmlspecialchars($emp['dept_name'])?></small></td>
            <td>
                <?php if($emp['current_shift']): ?>
                <span class="badge rounded-pill" style="background:<?=$emp['shift_color']?>">
                    <?=htmlspecialchars($emp['current_shift'])?>
                </span>
                <?php if($emp['assign_end']): ?>
                <div style="font-size:10px;color:#6b7280;">đến <?=date('d/m/Y',strtotime($emp['assign_end']))?></div>
                <?php endif; ?>
                <?php else: ?>
                <span class="badge bg-danger">⚠️ Chưa phân công</span>
                <?php endif; ?>
            </td>
            <td>
                <small class="text-muted">
                    <?=$emp['start_time']?substr($emp['start_time'],0,5).'–'.substr($emp['end_time'],0,5):'-'?>
                </small>
            </td>
            <td>
                <small><?=$emp['effective_date']?date('d/m/Y',strtotime($emp['effective_date'])):'-'?></small>
            </td>
            <td class="text-center">
                <?php if($emp['assign_id']): ?>
                <button type="button" class="btn btn-xs btn-outline-danger"
                        onclick="quickRemove(<?=$emp['assign_id']?>, '<?=htmlspecialchars(addslashes($emp['full_name']))?>')">
                    <i class="fas fa-times"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div class="p-2 border-top d-flex justify-content-between align-items-center">
        <small class="text-muted">Chọn nhiều để xóa hàng loạt</small>
        <button type="submit" class="btn btn-sm btn-danger"
                onclick="return confirm('Xóa các phân công đã chọn?')">
            <i class="fas fa-trash me-1"></i>Xóa đã chọn
        </button>
    </div>
    </form>
    </div>
</div>
</div>

</div><!-- /row -->

<!-- Quick remove form -->
<form method="POST" id="removeForm">
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
    <input type="hidden" name="action" value="remove">
    <input type="hidden" name="assign_id" id="removeId">
</form>

</div>
</div>

<style>.btn-xs{padding:2px 8px;font-size:12px;}</style>

<script>
// Ca preview
document.getElementById('shiftSel').addEventListener('change', function() {
    const opt=this.options[this.selectedIndex];
    const badge=document.getElementById('shiftBadge');
    const text=document.getElementById('shiftBadgeText');
    if(opt.value){
        badge.classList.remove('d-none');
        text.textContent=opt.text;
        text.style.background=opt.dataset.color||'#0d6efd';
    } else badge.classList.add('d-none');
});

// Lọc phòng ban
const deptFilter = document.getElementById('deptFilter');
if(deptFilter) deptFilter.addEventListener('change', function(){
    const id=this.value;
    document.querySelectorAll('.emp-item').forEach(el=>{
        el.style.display=(!id||el.dataset.dept==id)?'':'none';
    });
    updateCount();
});

function selAll(checked){
    document.querySelectorAll('.emp-item:not([style*="none"]) .emp-cb').forEach(c=>c.checked=checked);
    updateCount();
}
function selNoShift(){
    document.querySelectorAll('.emp-item').forEach(el=>{
        const cb=el.querySelector('.emp-cb');
        if(cb) cb.checked=(el.dataset.hasShift==='0' && el.style.display!=='none');
    });
    updateCount();
}
function updateCount(){
    document.getElementById('selCount').textContent=document.querySelectorAll('.emp-cb:checked').length;
}
document.querySelectorAll('.emp-cb').forEach(c=>c.addEventListener('change',updateCount));

// Quick remove
function quickRemove(id, name){
    if(!confirm(`Xóa phân công ca của ${name}?`)) return;
    document.getElementById('removeId').value=id;
    document.getElementById('removeForm').submit();
}

// Tìm kiếm nhân viên
document.getElementById('searchEmp').addEventListener('input', function(){
    const q=this.value.toLowerCase();
    document.querySelectorAll('.emp-row').forEach(row=>{
        const match=row.dataset.name.includes(q)||row.dataset.code.includes(q);
        row.style.display=match?'':'none';
    });
    document.querySelectorAll('.dept-header-row').forEach(hdr=>{
        let next=hdr.nextElementSibling;
        let anyVisible=false;
        while(next && !next.classList.contains('dept-header-row')){
            if(next.style.display!=='none') anyVisible=true;
            next=next.nextElementSibling;
        }
        hdr.style.display=anyVisible?'':'none';
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>