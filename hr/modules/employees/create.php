<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo    = getDBConnection();
$user   = currentUser();
$pageTitle = 'Thêm nhân viên mới';
$errors = [];

// ── Auto-generate mã NV tiếp theo ────────────────────────────
$lastCode = $pdo->query("
    SELECT employee_code FROM users
    WHERE employee_code ~ '^NV[0-9]+$'
    ORDER BY LENGTH(employee_code) DESC, employee_code DESC
    LIMIT 1
")->fetchColumn();
$nextNum     = $lastCode ? (intval(substr($lastCode, 2)) + 1) : 1;
$suggestCode = 'NV' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);

// ── Xử lý POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_code = trim($_POST['employee_code'] ?? '');
    $full_name     = trim($_POST['full_name']     ?? '');
    $username      = trim($_POST['username']      ?? '');
    $password      = $_POST['password']           ?? '';
    $password2     = $_POST['password2']          ?? '';
    $email         = trim($_POST['email']         ?? '');
    $phone         = trim($_POST['phone']         ?? '');
    $role_id       = (int)($_POST['role_id']      ?? 0);
    $gender        = $_POST['gender']             ?? 'male';
    $dob           = $_POST['date_of_birth']      ?: null;
    $hire_date     = $_POST['hire_date']          ?: null;
    $marital       = $_POST['marital_status']     ?? 'single';
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
    $ethnicity     = trim($_POST['ethnicity']     ?? '');

    // Validate
    if (empty($employee_code)) $errors[] = 'Mã nhân viên không được để trống.';
    if (empty($full_name))     $errors[] = 'Họ tên không được để trống.';
    if (empty($username))      $errors[] = 'Tên đăng nhập không được để trống.';
    if (strlen($password) < 6) $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    if ($password !== $password2) $errors[] = 'Xác nhận mật khẩu không khớp.';
    if (!$role_id)             $errors[] = 'Vui lòng chọn chức vụ.';

    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetchColumn() > 0) $errors[] = 'Tên đăng nhập đã tồn tại.';

        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE employee_code = ?");
        $chk2->execute([$employee_code]);
        if ($chk2->fetchColumn() > 0) $errors[] = 'Mã nhân viên đã tồn tại.';
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (
                employee_code, full_name, username, password_hash,
                email, phone, role_id, is_active,
                gender, marital_status, date_of_birth, hire_date, ethnicity,
                id_number, id_issue_date, id_issue_place,
                social_insurance, tax_code,
                bank_name, bank_account, bank_branch,
                permanent_province, permanent_district, permanent_street, permanent_address,
                temp_same_as_permanent, temp_province, temp_district, temp_street, temp_address,
                created_at, updated_at
            ) VALUES (
                ?,?,?,?,?,?,?,TRUE,
                ?,?,?,?,?,
                ?,?,?,
                ?,?,
                ?,?,?,
                ?,?,?,?,
                ?,?,?,?,?,
                NOW(), NOW()
            )
        ")->execute([
            $employee_code, $full_name, $username, $hash,
            $email, $phone, $role_id,
            $gender, $marital, $dob, $hire_date, $ethnicity,
            $id_number, $id_issue_date, $id_issue_place,
            $social_ins, $tax_code,
            $bank_name, $bank_account, $bank_branch,
            $perm_province, $perm_district, $perm_street, $perm_address,
            $temp_same, $temp_province, $temp_district, $temp_street, $temp_address,
        ]);

        $_SESSION['flash'] = ['type'=>'success',
            'msg'=>'✅ Tạo nhân viên <strong>'.htmlspecialchars($full_name).'</strong> thành công!'];
        header('Location: index.php');
        exit;
    }
}

// ── Dữ liệu dropdown ─────────────────────────────────────────
$roles = $pdo->query("
    SELECT * FROM roles WHERE name NOT IN ('customer') ORDER BY id
")->fetchAll();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h4 class="mb-0">➕ Thêm nhân viên mới</h4>
        <small class="text-muted">Điền đầy đủ thông tin để tạo tài khoản</small>
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

<form method="POST" id="createForm">

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
                           value="<?= htmlspecialchars($_POST['employee_code'] ?? $suggestCode) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>"
                           placeholder="Nguyễn Văn A" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Giới tính</label>
                    <select name="gender" class="form-select">
                        <option value="male"   <?= ($_POST['gender']??'male')==='male'  ?'selected':'' ?>>👨 Nam</option>
                        <option value="female" <?= ($_POST['gender']??'')==='female'   ?'selected':'' ?>>👩 Nữ</option>
                        <option value="other"  <?= ($_POST['gender']??'')==='other'    ?'selected':'' ?>>Khác</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Hôn nhân</label>
                    <select name="marital_status" class="form-select">
                        <option value="single"   <?= ($_POST['marital_status']??'single')==='single'  ?'selected':'' ?>>Độc thân</option>
                        <option value="married"  <?= ($_POST['marital_status']??'')==='married'       ?'selected':'' ?>>Đã kết hôn</option>
                        <option value="divorced" <?= ($_POST['marital_status']??'')==='divorced'      ?'selected':'' ?>>Ly hôn</option>
                        <option value="widowed"  <?= ($_POST['marital_status']??'')==='widowed'       ?'selected':'' ?>>Góa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ngày sinh</label>
                    <input type="date" name="date_of_birth" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_of_birth'] ?? '') ?>"
                           max="<?= date('Y-m-d', strtotime('-16 years')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Dân tộc</label>
                    <input type="text" name="ethnicity" class="form-control"
                           value="<?= htmlspecialchars($_POST['ethnicity'] ?? 'Kinh') ?>"
                           placeholder="Kinh">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           placeholder="email@company.com">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số điện thoại</label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                           placeholder="0901234567">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Ngày vào làm</label>
                    <input type="date" name="hire_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['hire_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- Phân quyền -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-shield-alt me-2 text-success"></i>Phân quyền
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Chức vụ / Role <span class="text-danger">*</span></label>
                    <select name="role_id" class="form-select" required id="roleSelect">
                        <option value="">-- Chọn chức vụ --</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"
                                data-role="<?= $r['name'] ?>"
                                <?= (($_POST['role_id'] ?? '') == $r['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['label'] ?: ucfirst($r['name'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2" id="roleBadgePreview"></div>
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
                           value="<?= htmlspecialchars($_POST['id_number'] ?? '') ?>"
                           placeholder="12 chữ số" maxlength="12">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ngày cấp</label>
                    <input type="date" name="id_issue_date" class="form-control"
                           value="<?= htmlspecialchars($_POST['id_issue_date'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Nơi cấp</label>
                    <input type="text" name="id_issue_place" class="form-control"
                           value="<?= htmlspecialchars($_POST['id_issue_place'] ?? '') ?>"
                           placeholder="Cục Cảnh sát...">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Số sổ BHXH</label>
                    <input type="text" name="social_insurance" class="form-control"
                           value="<?= htmlspecialchars($_POST['social_insurance'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Mã số thuế cá nhân</label>
                    <input type="text" name="tax_code" class="form-control"
                           value="<?= htmlspecialchars($_POST['tax_code'] ?? '') ?>"
                           placeholder="10 số MST">
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
                           value="<?= htmlspecialchars($_POST['permanent_province'] ?? '') ?>"
                           placeholder="VD: Hà Nội">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Quận / Huyện</label>
                    <input type="text" name="permanent_district" class="form-control"
                           value="<?= htmlspecialchars($_POST['permanent_district'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phường / Xã / Đường</label>
                    <input type="text" name="permanent_street" class="form-control"
                           value="<?= htmlspecialchars($_POST['permanent_street'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số nhà / Thôn / Ấp</label>
                    <input type="text" name="permanent_address" class="form-control"
                           value="<?= htmlspecialchars($_POST['permanent_address'] ?? '') ?>">
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
                       id="sameAddr" onchange="toggleSameAddr(this.checked)">
                <label class="form-check-label small fw-semibold" for="sameAddr">
                    ✅ Giống thường trú
                </label>
            </div>
        </div>
        <div class="card-body" id="tempAddrBlock">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Tỉnh / Thành phố</label>
                    <input type="text" name="temp_province" id="tp_prov" class="form-control"
                           value="<?= htmlspecialchars($_POST['temp_province'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Quận / Huyện</label>
                    <input type="text" name="temp_district" id="tp_dist" class="form-control"
                           value="<?= htmlspecialchars($_POST['temp_district'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Phường / Xã / Đường</label>
                    <input type="text" name="temp_street" id="tp_str" class="form-control"
                           value="<?= htmlspecialchars($_POST['temp_street'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Số nhà / Thôn / Ấp</label>
                    <input type="text" name="temp_address" id="tp_addr" class="form-control"
                           value="<?= htmlspecialchars($_POST['temp_address'] ?? '') ?>">
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
                        <option value="<?= $b ?>" <?= ($_POST['bank_name']??'')===$b?'selected':'' ?>>
                            <?= $b ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Số tài khoản</label>
                    <input type="text" name="bank_account" class="form-control"
                           value="<?= htmlspecialchars($_POST['bank_account'] ?? '') ?>"
                           placeholder="Số tài khoản ngân hàng">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Chi nhánh</label>
                    <input type="text" name="bank_branch" class="form-control"
                           value="<?= htmlspecialchars($_POST['bank_branch'] ?? '') ?>"
                           placeholder="VD: Chi nhánh Hà Nội">
                </div>
            </div>
        </div>
    </div>

    <!-- Đăng nhập -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-lock me-2 text-warning"></i>Thông tin đăng nhập
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tên đăng nhập <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" id="usernameInput"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           placeholder="nguyenvana" required
                           pattern="[a-zA-Z0-9_]+">
                    <div class="form-text">Chỉ dùng chữ không dấu, số, gạch dưới</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Mật khẩu <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password" class="form-control" id="pw1"
                               placeholder="Tối thiểu 6 ký tự" required minlength="6">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePw('pw1','eye1')">
                            <i class="fas fa-eye" id="eye1"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Xác nhận mật khẩu <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" name="password2" class="form-control" id="pw2"
                               placeholder="Nhập lại mật khẩu" required>
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
            <i class="fas fa-user-plus me-2"></i>Tạo nhân viên
        </button>
        <a href="index.php" class="btn btn-outline-secondary px-4">Huỷ</a>
    </div>

    </div><!-- col-lg-8 -->

    <!-- Sidebar hướng dẫn -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm bg-light sticky-top" style="top:80px">
            <div class="card-body">
                <h6 class="fw-bold mb-3">📋 Mô tả chức vụ</h6>
                <?php
                $roleGuide = [
                    'superadmin' => ['color'=>'danger',  'icon'=>'👑', 'desc'=>'Toàn quyền hệ thống'],
                    'admin'      => ['color'=>'warning', 'icon'=>'🛡️', 'desc'=>'Quản trị hệ thống'],
                    'accountant' => ['color'=>'info',    'icon'=>'💰', 'desc'=>'Kế toán, bảng lương, công nợ'],
                    'dispatcher' => ['color'=>'primary', 'icon'=>'🚦', 'desc'=>'Điều phối chuyến xe'],
                    'driver'     => ['color'=>'success', 'icon'=>'🚛', 'desc'=>'Lái xe, xem chuyến của mình'],
                ];
                foreach ($roles as $r):
                    $rg = $roleGuide[$r['name']] ?? ['color'=>'secondary','icon'=>'👤','desc'=>''];
                ?>
                <div class="mb-2">
                    <span class="badge bg-<?= $rg['color'] ?> me-1">
                        <?= $rg['icon'] ?> <?= htmlspecialchars($r['label'] ?: ucfirst($r['name'])) ?>
                    </span>
                    <small class="text-muted"><?= $rg['desc'] ?></small>
                </div>
                <?php endforeach; ?>
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
    const icon  = document.getElementById(iconId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

document.getElementById('pw2').addEventListener('input', function() {
    const match = this.value === document.getElementById('pw1').value || this.value === '';
    document.getElementById('pwMismatch').classList.toggle('d-none', match);
});

function toggleSameAddr(checked) {
    const block = document.getElementById('tempAddrBlock');
    block.style.opacity = checked ? '0.4' : '1';
    block.style.pointerEvents = checked ? 'none' : '';
    if (checked) {
        document.getElementById('tp_prov').value  = document.querySelector('[name=permanent_province]').value;
        document.getElementById('tp_dist').value  = document.querySelector('[name=permanent_district]').value;
        document.getElementById('tp_str').value   = document.querySelector('[name=permanent_street]').value;
        document.getElementById('tp_addr').value  = document.querySelector('[name=permanent_address]').value;
    }
}

// Role badge preview
const roleColors = {superadmin:'danger',admin:'warning',accountant:'info',dispatcher:'primary',driver:'success'};
const roleIcons  = {superadmin:'👑',admin:'🛡️',accountant:'💰',dispatcher:'🚦',driver:'🚛'};
document.getElementById('roleSelect').addEventListener('change', function() {
    const opt  = this.options[this.selectedIndex];
    const role = opt.dataset.role;
    const prev = document.getElementById('roleBadgePreview');
    prev.innerHTML = role
        ? `<span class="badge bg-${roleColors[role]||'secondary'} fs-6">${roleIcons[role]||'👤'} ${opt.text}</span>`
        : '';
});

// Auto-suggest username từ họ tên
document.querySelector('input[name="full_name"]').addEventListener('blur', function() {
    const un = document.getElementById('usernameInput');
    if (un.value) return;
    const map = {'à':'a','á':'a','ả':'a','ã':'a','ạ':'a','ă':'a','ằ':'a','ắ':'a','ẳ':'a','ẵ':'a','ặ':'a','â':'a','ầ':'a','ấ':'a','ẩ':'a','ẫ':'a','ậ':'a','đ':'d','è':'e','é':'e','ẻ':'e','ẽ':'e','ẹ':'e','ê':'e','ề':'e','ế':'e','ể':'e','ễ':'e','ệ':'e','ì':'i','í':'i','ỉ':'i','ĩ':'i','ị':'i','ò':'o','ó':'o','ỏ':'o','õ':'o','ọ':'o','ô':'o','ồ':'o','ố':'o','ổ':'o','ỗ':'o','ộ':'o','ơ':'o','ờ':'o','ớ':'o','ở':'o','ỡ':'o','ợ':'o','ù':'u','ú':'u','ủ':'u','ũ':'u','ụ':'u','ư':'u','ừ':'u','ứ':'u','ử':'u','ữ':'u','ự':'u','ỳ':'y','ý':'y','ỷ':'y','ỹ':'y','ỵ':'y'};
    let name = this.value.toLowerCase().split('').map(c => map[c]||c).join('');
    const parts = name.split(' ').filter(Boolean);
    if (parts.length >= 2) {
        const first = parts[parts.length - 1];
        const inits = parts.slice(0,-1).map(p=>p[0]).join('');
        un.value = (first + inits).replace(/[^a-z0-9_]/g,'');
    }
});
</script>

<?php include '../../includes/footer.php'; ?>