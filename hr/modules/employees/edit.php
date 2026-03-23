<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo       = getDBConnection();
$user      = currentUser();
$id        = (int)($_GET['id'] ?? 0);
$errors    = [];
$pageTitle = 'Chỉnh sửa nhân viên';

// ── Lấy thông tin nhân viên ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name, r.label AS role_label
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp || $emp['role_name'] === 'customer') {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy nhân viên.'];
    header('Location: index.php');
    exit;
}

// ── Xử lý POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_code = trim($_POST['employee_code'] ?? '');
    $full_name     = trim($_POST['full_name']     ?? '');
    $email         = trim($_POST['email']         ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $role_id       = (int)($_POST['role_id']      ?? 0);
    $gender        = $_POST['gender']             ?? 'male';
    $dob           = $_POST['date_of_birth']      ?: null;
    $hire_date     = $_POST['hire_date']          ?: null;
    $marital       = $_POST['marital_status']     ?? 'single';
    $ethnicity     = trim($_POST['ethnicity']     ?? '');
    $id_number     = trim($_POST['id_number']     ?? '');
    $id_issue_date = $_POST['id_issue_date']      ?: null;
    $id_issue_place= trim($_POST['id_issue_place']?? '');
    $social_ins    = trim($_POST['social_insurance'] ?? '');
    $tax_code      = trim($_POST['tax_code']      ?? '');
    $bank_name     = trim($_POST['bank_name']     ?? '');
    $bank_account  = trim($_POST['bank_account']  ?? '');
    $bank_branch   = trim($_POST['bank_branch']   ?? '');
    $perm_province = trim($_POST['permanent_province'] ?? '');
    $perm_district = trim($_POST['permanent_district'] ?? '');
    $perm_street   = trim($_POST['permanent_street']   ?? '');
    $perm_address  = trim($_POST['permanent_address']  ?? '');
    $temp_same     = isset($_POST['temp_same_as_permanent']);
    $temp_province = $temp_same ? $perm_province : trim($_POST['temp_province'] ?? '');
    $temp_district = $temp_same ? $perm_district : trim($_POST['temp_district'] ?? '');
    $temp_street   = $temp_same ? $perm_street   : trim($_POST['temp_street']   ?? '');
    $temp_address  = $temp_same ? $perm_address  : trim($_POST['temp_address']  ?? '');

    // Validate
    if (empty($employee_code)) $errors[] = 'Mã nhân viên không được để trống.';
    if (empty($full_name))     $errors[] = 'Họ tên không được để trống.';
    if (!$role_id)             $errors[] = 'Vui lòng chọn chức vụ.';

    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE employee_code = ? AND id != ?");
        $chk->execute([$employee_code, $id]);
        if ($chk->fetchColumn() > 0) $errors[] = 'Mã nhân viên đã tồn tại.';
    }

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE users SET
                employee_code=?, full_name=?, email=?, phone=?, role_id=?,
                gender=?, marital_status=?, date_of_birth=?, hire_date=?, ethnicity=?,
                id_number=?, id_issue_date=?, id_issue_place=?,
                social_insurance=?, tax_code=?,
                bank_name=?, bank_account=?, bank_branch=?,
                permanent_province=?, permanent_district=?, permanent_street=?, permanent_address=?,
                temp_same_as_permanent=?, temp_province=?, temp_district=?, temp_street=?, temp_address=?,
                updated_at=NOW()
            WHERE id=?
        ")->execute([
            $employee_code, $full_name, $email, $phone, $role_id,
            $gender, $marital, $dob, $hire_date, $ethnicity,
            $id_number, $id_issue_date, $id_issue_place,
            $social_ins, $tax_code,
            $bank_name, $bank_account, $bank_branch,
            $perm_province, $perm_district, $perm_street, $perm_address,
            $temp_same, $temp_province, $temp_district, $temp_street, $temp_address,
            $id
        ]);

        // Đổi mật khẩu nếu có nhập
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 6) {
                $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
            } elseif ($_POST['new_password'] !== $_POST['confirm_password']) {
                $errors[] = 'Xác nhận mật khẩu không khớp.';
            } else {
                $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?")
                    ->execute([$hash, $id]);
            }
        }

        if (empty($errors)) {
            $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Cập nhật nhân viên thành công!'];
            header('Location: view.php?id=' . $id);
            exit;
        }

        // Reload emp nếu có lỗi mật khẩu
        $stmt->execute([$id]);
        $emp = $stmt->fetch();
    }
}

// ── Field helper: lấy từ POST nếu có lỗi, không thì từ DB ────
function fv(string $key, $row): string {
    global $errors;
    $v = !empty($errors) && isset($_POST[$key]) ? $_POST[$key] : ($row[$key] ?? '');
    return htmlspecialchars((string)$v);
}
function fvSel(string $key, string $option, $row): string {
    global $errors;
    $v = !empty($errors) && isset($_POST[$key]) ? $_POST[$key] : ($row[$key] ?? '');
    return $v === $option ? 'selected' : '';
}

$roles = $pdo->query("SELECT * FROM roles WHERE name NOT IN ('customer') ORDER BY id")->fetchAll();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h4 class="mb-0">✏️ Chỉnh sửa nhân viên</h4>
        <small class="text-muted fw-semibold"><?= htmlspecialchars($emp['full_name']) ?></small>
    </div>
</div>

<?php showFlash(); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <strong>❌ Vui lòng kiểm tra lại:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST">

<div class="row g-4">
<div class="col-lg-8">

    <!-- Thông tin cơ bản -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-user me-2 text-primary"></i>Thông tin cơ bản
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Mã nhân viên <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code" class="form-control"
                           value="<?= fv('employee_code', $emp) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= fv('full_name', $emp) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Giới tính</label>
                    <select name="gender" class="form-select">
                        <option value="male"   <?= fvSel('gender','male',  $emp) ?>>👨 Nam</option>
                        <option value="female" <?= fvSel('gender','female',$emp) ?>>👩 Nữ</option>
                        <option value="other"  <?= fvSel('gender','other', $emp) ?>>Khác</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Hôn nhân</label>
                    <select name="marital_status" class="form-select">
                        <option value="single"   <?= fvSel('marital_status','single',  $emp) ?>>Độc thân</option>
                        <option value="married"  <?= fvSel('marital_status','married', $emp) ?>>Đã kết hôn</option>
                        <option value="divorced" <?= fvSel('marital_status','divorced',$emp) ?>>Ly hôn</option>
                        <option value="widowed"  <?= fvSel('marital_status','widowed', $emp) ?>>Góa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ngày sinh</label>
                    <input type="date" name="date_of_birth" class="form-control"
                           value="<?= fv('date_of_birth',$emp) ?>"
                           max="<?= date('Y-m-d', strtotime('-16 years')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Dân tộc</label>
                    <input type="text" name="ethnicity" class="form-control"
                           value="<?= fv('ethnicity',$emp) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= fv('email',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số điện thoại</label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= fv('phone',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ngày vào làm</label>
                    <input type="date" name="hire_date" class="form-control"
                           value="<?= fv('hire_date',$emp) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Phân quyền -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-shield-alt me-2 text-success"></i>Chức vụ
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Chức vụ / Role <span class="text-danger">*</span></label>
                    <select name="role_id" class="form-select" required>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"
                                <?= fvSel('role_id', (string)$r['id'], $emp) ?>>
                            <?= htmlspecialchars($r['label'] ?: ucfirst($r['name'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="p-2 bg-light rounded w-100">
                        <small>
                            <i class="fas fa-info-circle text-info me-1"></i>
                            Username: <code><?= htmlspecialchars($emp['username']) ?></code>
                            — Đổi mật khẩu bên dưới nếu cần
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Giấy tờ -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-id-badge me-2 text-info"></i>Giấy tờ tùy thân
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Số CMND / CCCD</label>
                    <input type="text" name="id_number" class="form-control"
                           value="<?= fv('id_number',$emp) ?>" maxlength="12">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ngày cấp</label>
                    <input type="date" name="id_issue_date" class="form-control"
                           value="<?= fv('id_issue_date',$emp) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nơi cấp</label>
                    <input type="text" name="id_issue_place" class="form-control"
                           value="<?= fv('id_issue_place',$emp) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Số sổ BHXH</label>
                    <input type="text" name="social_insurance" class="form-control"
                           value="<?= fv('social_insurance',$emp) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Mã số thuế cá nhân</label>
                    <input type="text" name="tax_code" class="form-control"
                           value="<?= fv('tax_code',$emp) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Địa chỉ thường trú -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-home me-2 text-warning"></i>Địa chỉ thường trú
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tỉnh / Thành phố</label>
                    <input type="text" name="permanent_province" class="form-control"
                           value="<?= fv('permanent_province',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Quận / Huyện</label>
                    <input type="text" name="permanent_district" class="form-control"
                           value="<?= fv('permanent_district',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phường / Xã</label>
                    <input type="text" name="permanent_street" class="form-control"
                           value="<?= fv('permanent_street',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số nhà / Thôn</label>
                    <input type="text" name="permanent_address" class="form-control"
                           value="<?= fv('permanent_address',$emp) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Địa chỉ tạm trú -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
            <span><i class="fas fa-map-marker-alt me-2 text-danger"></i>Địa chỉ tạm trú</span>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="temp_same_as_permanent"
                       id="sameAddr"
                       <?= $emp['temp_same_as_permanent'] ? 'checked' : '' ?>
                       onchange="toggleSameAddr(this.checked)">
                <label class="form-check-label small fw-semibold" for="sameAddr">
                    ✅ Giống thường trú
                </label>
            </div>
        </div>
        <div class="card-body" id="tempAddrBlock"
             style="<?= $emp['temp_same_as_permanent'] ? 'opacity:.4;pointer-events:none;' : '' ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tỉnh / Thành phố</label>
                    <input type="text" name="temp_province" id="tp_prov" class="form-control"
                           value="<?= fv('temp_province',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Quận / Huyện</label>
                    <input type="text" name="temp_district" id="tp_dist" class="form-control"
                           value="<?= fv('temp_district',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phường / Xã</label>
                    <input type="text" name="temp_street" id="tp_str" class="form-control"
                           value="<?= fv('temp_street',$emp) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số nhà / Thôn</label>
                    <input type="text" name="temp_address" id="tp_addr" class="form-control"
                           value="<?= fv('temp_address',$emp) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Ngân hàng -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-university me-2 text-success"></i>Tài khoản ngân hàng
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tên ngân hàng</label>
                    <select name="bank_name" class="form-select">
                        <option value="">-- Chọn ngân hàng --</option>
                        <?php foreach (['Vietcombank','VietinBank','BIDV','Agribank','Techcombank',
                                'MBBank','ACB','VPBank','TPBank','Sacombank','HDBank','VIB',
                                'OCB','MSB','SeABank','Eximbank','SHB','LPBank','Ngân hàng khác'] as $b): ?>
                        <option value="<?= $b ?>" <?= fvSel('bank_name',$b,$emp) ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Số tài khoản</label>
                    <input type="text" name="bank_account" class="form-control"
                           value="<?= fv('bank_account',$emp) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Chi nhánh</label>
                    <input type="text" name="bank_branch" class="form-control"
                           value="<?= fv('bank_branch',$emp) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Đổi mật khẩu -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-key me-2 text-warning"></i>Đổi mật khẩu
            <small class="text-muted fw-normal">(để trống nếu không đổi)</small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Mật khẩu mới</label>
                    <div class="input-group">
                        <input type="password" name="new_password" class="form-control" id="pw1"
                               placeholder="Tối thiểu 6 ký tự" minlength="6">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePw('pw1','eye1')">
                            <i class="fas fa-eye" id="eye1"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Xác nhận mật khẩu mới</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password" class="form-control" id="pw2"
                               placeholder="Nhập lại mật khẩu mới">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePw('pw2','eye2')">
                            <i class="fas fa-eye" id="eye2"></i>
                        </button>
                    </div>
                    <div class="form-text text-danger d-none" id="pwMismatch">⚠️ Mật khẩu không khớp</div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary px-4">
            <i class="fas fa-save me-2"></i>Lưu thay đổi
        </button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary px-4">Huỷ</a>
    </div>

</div><!-- col-lg-8 -->

<!-- Sidebar thông tin nhanh -->
<div class="col-lg-4">
    <div class="card border-0 shadow-sm sticky-top" style="top:80px">
        <div class="card-body text-center">
            <div class="rounded-circle mx-auto d-flex align-items-center justify-content-center fw-bold
                        bg-primary text-white mb-3"
                 style="width:64px;height:64px;font-size:1.5rem">
                <?= mb_strtoupper(mb_substr($emp['full_name'],0,1)) ?>
            </div>
            <div class="fw-bold"><?= htmlspecialchars($emp['full_name']) ?></div>
            <div class="text-muted small mb-2">
                <code><?= htmlspecialchars($emp['employee_code']??'—') ?></code>
            </div>
            <?php $badge = getRoleBadge($emp['role_name']); ?>
            <span class="badge bg-<?= $badge['class'] ?>">
                <?= $badge['icon'] ?> <?= htmlspecialchars($emp['role_label']?:$emp['role_name']) ?>
            </span>
            <hr>
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-info btn-sm w-100 mb-2">
                <i class="fas fa-eye me-1"></i>Xem hồ sơ
            </a>
            <?php if ($emp['is_active']): ?>
            <a href="?toggle=<?= $id ?>&state=0" class="btn btn-outline-danger btn-sm w-100"
               onclick="return confirm('Cho nhân viên này nghỉ việc?')">
                <i class="fas fa-user-slash me-1"></i>Cho nghỉ việc
            </a>
            <?php else: ?>
            <a href="?toggle=<?= $id ?>&state=1" class="btn btn-outline-success btn-sm w-100"
               onclick="return confirm('Kích hoạt lại nhân viên này?')">
                <i class="fas fa-user-check me-1"></i>Kích hoạt lại
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

</div><!-- row -->
</form>

</div>
</div>

<script>
function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    input.type = input.type === 'password' ? 'text' : 'password';
    document.getElementById(iconId).className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
document.getElementById('pw2').addEventListener('input', function() {
    const match = !this.value || this.value === document.getElementById('pw1').value;
    document.getElementById('pwMismatch').classList.toggle('d-none', match);
});
function toggleSameAddr(checked) {
    const block = document.getElementById('tempAddrBlock');
    block.style.opacity = checked ? '0.4' : '1';
    block.style.pointerEvents = checked ? 'none' : '';
    if (checked) {
        document.getElementById('tp_prov').value = document.querySelector('[name=permanent_province]').value;
        document.getElementById('tp_dist').value = document.querySelector('[name=permanent_district]').value;
        document.getElementById('tp_str').value  = document.querySelector('[name=permanent_street]').value;
        document.getElementById('tp_addr').value = document.querySelector('[name=permanent_address]').value;
    }
}
</script>

<?php
// Toggle active
if (isset($_GET['toggle']) && isset($_GET['state'])) {
    $state = (int)$_GET['state'];
    $pdo->prepare("UPDATE users SET is_active=?, updated_at=NOW() WHERE id=?")
        ->execute([$state, $id]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'Đã cập nhật trạng thái nhân viên.'];
    header('Location: view.php?id='.$id);
    exit;
}

include '../../includes/footer.php';
?>