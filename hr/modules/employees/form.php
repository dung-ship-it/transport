<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo    = getDBConnection();
$user   = currentUser();
$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];
$emp    = [];

$pageTitle = $isEdit ? 'Chỉnh sửa nhân viên' : 'Thêm nhân viên mới';

// ── Nếu edit: load dữ liệu nhân viên ────────────────────────
if ($isEdit) {
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name, r.label AS role_label
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();

    if (!$emp || $emp['role_name'] === 'customer') {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Không tìm thấy nhân viên.'];
        header('Location: index.php');
        exit;
    }

    // Không được tự sửa chính mình nếu không phải admin
    // (vẫn cho phép, chỉ cần có quyền hr.manage)
}

// ── Auto-generate mã NV tiếp theo (chỉ dùng khi tạo mới) ────
$suggestCode = '';
if (!$isEdit) {
    $lastCode    = $pdo->query("
        SELECT employee_code FROM users
        WHERE employee_code ~ '^NV[0-9]+$'
        ORDER BY LENGTH(employee_code) DESC, employee_code DESC
        LIMIT 1
    ")->fetchColumn();
    $nextNum     = $lastCode ? (intval(substr($lastCode, 2)) + 1) : 1;
    $suggestCode = 'NV' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

// ── Helper: lấy giá trị form (POST > DB > default) ──────────
function fv(string $key, array $row = [], string $default = ''): string {
    global $errors;
    if (!empty($errors) && array_key_exists($key, $_POST)) {
        return htmlspecialchars((string)$_POST[$key]);
    }
    return htmlspecialchars((string)($row[$key] ?? $default));
}
function fvSel(string $key, string $option, array $row = []): string {
    global $errors;
    $v = (!empty($errors) && array_key_exists($key, $_POST))
        ? $_POST[$key]
        : ($row[$key] ?? '');
    return (string)$v === $option ? 'selected' : '';
}
function fvChk(string $key, array $row = []): string {
    global $errors;
    $v = (!empty($errors) && array_key_exists($key, $_POST))
        ? isset($_POST[$key])
        : (bool)($row[$key] ?? false);
    return $v ? 'checked' : '';
}

// ── Xử lý POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Thu thập dữ liệu chung ────────────────────────────────
    $employee_code  = trim($_POST['employee_code']  ?? '');
    $full_name      = trim($_POST['full_name']       ?? '');
    $email          = trim($_POST['email']           ?? '');
    $phone          = trim($_POST['phone']           ?? '');
    $role_id        = (int)($_POST['role_id']        ?? 0);
    $gender         = $_POST['gender']               ?? 'male';
    $dob            = $_POST['date_of_birth']         ?: null;
    $hire_date      = $_POST['hire_date']             ?: null;
    $marital        = $_POST['marital_status']        ?? 'single';
    $ethnicity      = trim($_POST['ethnicity']        ?? 'Kinh');
    $id_number      = trim($_POST['id_number']        ?? '');
    $id_issue_date  = $_POST['id_issue_date']         ?: null;
    $id_issue_place = trim($_POST['id_issue_place']   ?? '');
    $social_ins     = trim($_POST['social_insurance'] ?? '');
    $tax_code       = trim($_POST['tax_code']         ?? '');
    $bank_name      = trim($_POST['bank_name']        ?? '');
    $bank_account   = trim($_POST['bank_account']     ?? '');
    $bank_branch    = trim($_POST['bank_branch']      ?? '');
    $perm_province  = trim($_POST['permanent_province'] ?? '');
    $perm_district  = trim($_POST['permanent_district'] ?? '');
    $perm_street    = trim($_POST['permanent_street']    ?? '');
    $perm_address   = trim($_POST['permanent_address']   ?? '');
    $temp_same      = isset($_POST['temp_same_as_permanent']);
    $temp_province  = $temp_same ? $perm_province  : trim($_POST['temp_province']  ?? '');
    $temp_district  = $temp_same ? $perm_district  : trim($_POST['temp_district']  ?? '');
    $temp_street    = $temp_same ? $perm_street    : trim($_POST['temp_street']    ?? '');
    $temp_address   = $temp_same ? $perm_address   : trim($_POST['temp_address']   ?? '');

    // ── Validate chung ────────────────────────────────────────
    if (empty($employee_code)) $errors[] = 'Mã nhân viên không được để trống.';
    if (empty($full_name))     $errors[] = 'Họ và tên không được để trống.';
    if (!$role_id)             $errors[] = 'Vui lòng chọn chức vụ.';

    // ── Validate riêng cho CREATE ─────────────────────────────
    if (!$isEdit) {
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password']       ?? '';
        $password2 = $_POST['password2']      ?? '';

        if (empty($username))      $errors[] = 'Tên đăng nhập không được để trống.';
        if (strlen($password) < 6) $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
        if ($password !== $password2) $errors[] = 'Xác nhận mật khẩu không khớp.';
    }

    // ── Check trùng employee_code ─────────────────────────────
    if (empty($errors)) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE employee_code = ?" .
                             ($isEdit ? " AND id != $id" : ""));
        $chk->execute([$employee_code]);
        if ($chk->fetchColumn() > 0) $errors[] = 'Mã nhân viên đã tồn tại.';
    }

    // ── Check trùng username (chỉ CREATE) ─────────────────────
    if (!$isEdit && empty($errors)) {
        $chk2 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $chk2->execute([$username]);
        if ($chk2->fetchColumn() > 0) $errors[] = 'Tên đăng nhập đã tồn tại.';
    }

    // ── Lưu DB nếu không có lỗi ──────────────────────────────
    if (empty($errors)) {

        $commonCols = [
            'employee_code'          => $employee_code,
            'full_name'              => $full_name,
            'email'                  => $email,
            'phone'                  => $phone,
            'role_id'                => $role_id,
            'gender'                 => $gender,
            'marital_status'         => $marital,
            'date_of_birth'          => $dob,
            'hire_date'              => $hire_date,
            'ethnicity'              => $ethnicity,
            'id_number'              => $id_number,
            'id_issue_date'          => $id_issue_date,
            'id_issue_place'         => $id_issue_place,
            'social_insurance'       => $social_ins,
            'tax_code'               => $tax_code,
            'bank_name'              => $bank_name,
            'bank_account'           => $bank_account,
            'bank_branch'            => $bank_branch,
            'permanent_province'     => $perm_province,
            'permanent_district'     => $perm_district,
            'permanent_street'       => $perm_street,
            'permanent_address'      => $perm_address,
            'temp_same_as_permanent' => $temp_same,
            'temp_province'          => $temp_province,
            'temp_district'          => $temp_district,
            'temp_street'            => $temp_street,
            'temp_address'           => $temp_address,
        ];

        if ($isEdit) {
            // ── UPDATE ────────────────────────────────────────
            $set    = implode(' = ?, ', array_keys($commonCols)) . ' = ?, updated_at = NOW()';
            $values = array_values($commonCols);
            $values[] = $id;
            $pdo->prepare("UPDATE users SET $set WHERE id = ?")->execute($values);

            // Đổi mật khẩu nếu nhập
            $newPw = $_POST['new_password'] ?? '';
            if ($newPw !== '') {
                if (strlen($newPw) < 6) {
                    $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
                } elseif ($newPw !== ($_POST['confirm_password'] ?? '')) {
                    $errors[] = 'Xác nhận mật khẩu mới không khớp.';
                } else {
                    $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([password_hash($newPw, PASSWORD_DEFAULT), $id]);
                }
            }

            if (empty($errors)) {
                $_SESSION['flash'] = ['type' => 'success',
                    'msg' => '✅ Cập nhật nhân viên <strong>' . htmlspecialchars($full_name) . '</strong> thành công!'];
                header('Location: view.php?id=' . $id);
                exit;
            }

        } else {
            // ── INSERT ────────────────────────────────────────
            $commonCols['username']      = $username;
            $commonCols['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $commonCols['is_active']     = true;

            $cols   = implode(', ', array_keys($commonCols));
            $places = implode(', ', array_fill(0, count($commonCols), '?'));
            $pdo->prepare("INSERT INTO users ($cols, created_at, updated_at)
                           VALUES ($places, NOW(), NOW())")
                ->execute(array_values($commonCols));

            $newId = $pdo->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success',
                'msg' => '✅ Thêm nhân viên <strong>' . htmlspecialchars($full_name) . '</strong> thành công!'];
            header('Location: view.php?id=' . $newId);
            exit;
        }
    }
}

// ── Dữ liệu dropdown ─────────────────────────────────────────
$roles = $pdo->query("
    SELECT * FROM roles WHERE name NOT IN ('customer') ORDER BY id
")->fetchAll();

$banks = [
    'Vietcombank','VietinBank','BIDV','Agribank','Techcombank',
    'MBBank','ACB','VPBank','TPBank','Sacombank','HDBank','VIB',
    'OCB','MSB','SeABank','Eximbank','SHB','LPBank',
    'NamABank','BacABank','ABBank','VietABank','PVcomBank',
    'NCB','Kienlongbank','CBBank','OceanBank','GPBank',
    'Cake','Timo','Ngân hàng khác',
];

$roleGuide = [
    'superadmin' => ['color' => 'danger',  'icon' => '👑', 'desc' => 'Toàn quyền hệ thống'],
    'admin'      => ['color' => 'warning', 'icon' => '🛡️', 'desc' => 'Quản trị hệ thống'],
    'accountant' => ['color' => 'info',    'icon' => '💰', 'desc' => 'Kế toán, bảng lương, công nợ'],
    'dispatcher' => ['color' => 'primary', 'icon' => '🚦', 'desc' => 'Điều phối chuyến xe'],
    'driver'     => ['color' => 'success', 'icon' => '🚛', 'desc' => 'Lái xe, xem chuyến của mình'],
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- Tiêu đề -->
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="<?= $isEdit ? 'view.php?id='.$id : 'index.php' ?>"
       class="btn btn-outline-secondary btn-sm">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div>
        <h4 class="mb-0">
            <?= $isEdit ? '✏️ Chỉnh sửa nhân viên' : '➕ Thêm nhân viên mới' ?>
        </h4>
        <?php if ($isEdit): ?>
        <small class="text-muted">
            <strong><?= htmlspecialchars($emp['full_name']) ?></strong>
            · <code><?= htmlspecialchars($emp['employee_code'] ?? '') ?></code>
        </small>
        <?php else: ?>
        <small class="text-muted">Điền đầy đủ thông tin để tạo tài khoản nhân viên</small>
        <?php endif; ?>
    </div>
</div>

<?php showFlash(); ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <strong>❌ Vui lòng kiểm tra lại:</strong>
    <ul class="mb-0 mt-1">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="empForm" novalidate>

<div class="row g-4">
<div class="col-lg-8">

<!-- ══ SECTION 1: Thông tin cơ bản ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-user me-2 text-primary"></i>1. Thông tin cơ bản
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label fw-semibold">
                    Mã nhân viên <span class="text-danger">*</span>
                </label>
                <input type="text" name="employee_code" class="form-control"
                       value="<?= fv('employee_code', $emp, $suggestCode) ?>" required>
            </div>

            <div class="col-md-8">
                <label class="form-label fw-semibold">
                    Họ và tên <span class="text-danger">*</span>
                </label>
                <input type="text" name="full_name" class="form-control" id="fullNameInput"
                       value="<?= fv('full_name', $emp) ?>"
                       placeholder="Nguyễn Văn A" required>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Giới tính</label>
                <select name="gender" class="form-select">
                    <option value="male"   <?= fvSel('gender','male',   $emp) ?>>👨 Nam</option>
                    <option value="female" <?= fvSel('gender','female', $emp) ?>>👩 Nữ</option>
                    <option value="other"  <?= fvSel('gender','other',  $emp) ?>>Khác</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Hôn nhân</label>
                <select name="marital_status" class="form-select">
                    <option value="single"   <?= fvSel('marital_status','single',   $emp) ?>>Độc thân</option>
                    <option value="married"  <?= fvSel('marital_status','married',  $emp) ?>>Đã kết hôn</option>
                    <option value="divorced" <?= fvSel('marital_status','divorced', $emp) ?>>Ly hôn</option>
                    <option value="widowed"  <?= fvSel('marital_status','widowed',  $emp) ?>>Góa</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Ngày sinh</label>
                <input type="date" name="date_of_birth" class="form-control"
                       value="<?= fv('date_of_birth', $emp) ?>"
                       max="<?= date('Y-m-d', strtotime('-16 years')) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Dân tộc</label>
                <input type="text" name="ethnicity" class="form-control"
                       value="<?= fv('ethnicity', $emp, 'Kinh') ?>"
                       placeholder="Kinh">
            </div>

            <div class="col-md-5">
                <label class="form-label fw-semibold">Email</label>
                <div class="input-group">
                    <span class="input-group-text">✉️</span>
                    <input type="email" name="email" class="form-control"
                           value="<?= fv('email', $emp) ?>"
                           placeholder="email@company.com">
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Số điện thoại</label>
                <div class="input-group">
                    <span class="input-group-text">📱</span>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= fv('phone', $emp) ?>"
                           placeholder="0901234567">
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label fw-semibold">Ngày vào làm</label>
                <input type="date" name="hire_date" class="form-control"
                       value="<?= fv('hire_date', $emp, date('Y-m-d')) ?>">
            </div>

        </div>
    </div>
</div>

<!-- ══ SECTION 2: Chức vụ ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-shield-alt me-2 text-success"></i>2. Chức vụ
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label fw-semibold">
                    Chức vụ / Role <span class="text-danger">*</span>
                </label>
                <select name="role_id" class="form-select" id="roleSelect" required>
                    <option value="">-- Chọn chức vụ --</option>
                    <?php foreach ($roles as $r): ?>
                    <option value="<?= $r['id'] ?>"
                            data-role="<?= $r['name'] ?>"
                            <?= fvSel('role_id', (string)$r['id'], $emp) ?>>
                        <?= htmlspecialchars($r['label'] ?: ucfirst($r['name'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2" id="roleBadgePreview"></div>
            </div>
            <div class="col-md-7 d-flex align-items-end">
                <div class="p-2 bg-light rounded w-100 small text-muted">
                    <i class="fas fa-info-circle text-info me-1"></i>
                    <?php if ($isEdit): ?>
                    Username: <code><?= htmlspecialchars($emp['username'] ?? '') ?></code>
                    — Đổi mật khẩu ở phần bên dưới nếu cần
                    <?php else: ?>
                    Sau khi lưu, nhân viên có thể đăng nhập ngay bằng username và mật khẩu bạn đặt
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ SECTION 3: Giấy tờ tùy thân ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-id-badge me-2 text-info"></i>3. Giấy tờ tùy thân
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Số CMND / CCCD</label>
                <div class="input-group">
                    <span class="input-group-text">🪪</span>
                    <input type="text" name="id_number" class="form-control"
                           value="<?= fv('id_number', $emp) ?>"
                           placeholder="12 chữ số" maxlength="12"
                           oninput="this.value=this.value.replace(/\D/g,'')">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Ngày cấp</label>
                <input type="date" name="id_issue_date" class="form-control"
                       value="<?= fv('id_issue_date', $emp) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Nơi cấp</label>
                <input type="text" name="id_issue_place" class="form-control"
                       value="<?= fv('id_issue_place', $emp) ?>"
                       placeholder="Cục Cảnh sát QLHC...">
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Số sổ BHXH</label>
                <div class="input-group">
                    <span class="input-group-text">🏥</span>
                    <input type="text" name="social_insurance" class="form-control"
                           value="<?= fv('social_insurance', $emp) ?>"
                           placeholder="Số sổ bảo hiểm xã hội">
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Mã số thuế cá nhân</label>
                <div class="input-group">
                    <span class="input-group-text">🧾</span>
                    <input type="text" name="tax_code" class="form-control"
                           value="<?= fv('tax_code', $emp) ?>"
                           placeholder="10 số MST cá nhân" maxlength="13">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ SECTION 4: Địa chỉ thường trú ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-home me-2 text-warning"></i>4. Địa chỉ thường trú
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Tỉnh / Thành phố</label>
                <input type="text" name="permanent_province" class="form-control perm-addr"
                       data-target="tp_prov"
                       value="<?= fv('permanent_province', $emp) ?>"
                       placeholder="Hà Nội">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Quận / Huyện</label>
                <input type="text" name="permanent_district" class="form-control perm-addr"
                       data-target="tp_dist"
                       value="<?= fv('permanent_district', $emp) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Phường / Xã</label>
                <input type="text" name="permanent_street" class="form-control perm-addr"
                       data-target="tp_str"
                       value="<?= fv('permanent_street', $emp) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Số nhà / Thôn</label>
                <input type="text" name="permanent_address" class="form-control perm-addr"
                       data-target="tp_addr"
                       value="<?= fv('permanent_address', $emp) ?>">
            </div>
        </div>
    </div>
</div>

<!-- ══ SECTION 5: Địa chỉ tạm trú ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd d-flex justify-content-between align-items-center">
        <span><i class="fas fa-map-marker-alt me-2 text-danger"></i>5. Địa chỉ tạm trú</span>
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox"
                   name="temp_same_as_permanent" id="sameAddr"
                   <?= fvChk('temp_same_as_permanent', $emp) ?>
                   onchange="toggleSameAddr(this.checked)">
            <label class="form-check-label fw-semibold small" for="sameAddr">
                ✅ Giống địa chỉ thường trú
            </label>
        </div>
    </div>
    <div class="card-body" id="tempAddrBlock">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">Tỉnh / Thành phố</label>
                <input type="text" name="temp_province" id="tp_prov" class="form-control"
                       value="<?= fv('temp_province', $emp) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Quận / Huyện</label>
                <input type="text" name="temp_district" id="tp_dist" class="form-control"
                       value="<?= fv('temp_district', $emp) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Phường / Xã</label>
                <input type="text" name="temp_street" id="tp_str" class="form-control"
                       value="<?= fv('temp_street', $emp) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Số nhà / Thôn</label>
                <input type="text" name="temp_address" id="tp_addr" class="form-control"
                       value="<?= fv('temp_address', $emp) ?>">
            </div>
        </div>
    </div>
</div>

<!-- ══ SECTION 6: Ngân hàng ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-university me-2 text-success"></i>6. Tài khoản ngân hàng
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Tên ngân hàng</label>
                <select name="bank_name" class="form-select" id="bankSelect"
                        onchange="updateBankPreview()">
                    <option value="">-- Chọn ngân hàng --</option>
                    <?php foreach ($banks as $b): ?>
                    <option value="<?= $b ?>" <?= fvSel('bank_name', $b, $emp) ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Số tài khoản</label>
                <div class="input-group">
                    <span class="input-group-text">💳</span>
                    <input type="text" name="bank_account" id="bankAccount" class="form-control"
                           value="<?= fv('bank_account', $emp) ?>"
                           placeholder="Số tài khoản ngân hàng"
                           oninput="updateBankPreview()">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Chi nhánh</label>
                <input type="text" name="bank_branch" class="form-control"
                       value="<?= fv('bank_branch', $emp) ?>"
                       placeholder="Chi nhánh Hà Nội">
            </div>
            <div class="col-12">
                <div id="bankPreview" class="p-2 bg-light rounded border d-none" style="font-size:.85rem"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══ SECTION 7: Đăng nhập ══ -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header section-hd">
        <i class="fas fa-lock me-2 text-warning"></i>
        <?= $isEdit ? '7. Đổi mật khẩu <small class="text-muted fw-normal">(để trống nếu không đổi)</small>'
                    : '7. Thông tin đăng nhập' ?>
    </div>
    <div class="card-body">
        <div class="row g-3">

        <?php if (!$isEdit): ?>
            <div class="col-md-4">
                <label class="form-label fw-semibold">
                    Tên đăng nhập <span class="text-danger">*</span>
                </label>
                <input type="text" name="username" class="form-control" id="usernameInput"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="nguyenvana" required pattern="[a-zA-Z0-9_]+">
                <div class="form-text">Chỉ dùng chữ không dấu, số, gạch dưới</div>
            </div>
        <?php endif; ?>

            <div class="col-md-<?= $isEdit ? '6' : '4' ?>">
                <label class="form-label fw-semibold">
                    <?= $isEdit ? 'Mật khẩu mới' : 'Mật khẩu' ?>
                    <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
                </label>
                <div class="input-group">
                    <input type="password" name="<?= $isEdit ? 'new_password' : 'password' ?>"
                           class="form-control" id="pw1"
                           placeholder="Tối thiểu 6 ký tự"
                           <?= !$isEdit ? 'required minlength="6"' : '' ?>>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePw('pw1','eye1')">
                        <i class="fas fa-eye" id="eye1"></i>
                    </button>
                </div>
            </div>

            <div class="col-md-<?= $isEdit ? '6' : '4' ?>">
                <label class="form-label fw-semibold">
                    Xác nhận <?= $isEdit ? 'mật khẩu mới' : 'mật khẩu' ?>
                    <?= !$isEdit ? '<span class="text-danger">*</span>' : '' ?>
                </label>
                <div class="input-group">
                    <input type="password" name="<?= $isEdit ? 'confirm_password' : 'password2' ?>"
                           class="form-control" id="pw2"
                           placeholder="Nhập lại mật khẩu"
                           <?= !$isEdit ? 'required' : '' ?>>
                    <button type="button" class="btn btn-outline-secondary"
                            onclick="togglePw('pw2','eye2')">
                        <i class="fas fa-eye" id="eye2"></i>
                    </button>
                </div>
                <div class="form-text text-danger d-none" id="pwMismatch">
                    ⚠️ Mật khẩu không khớp
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Nút lưu -->
<div class="d-flex gap-2 mb-5">
    <button type="submit" class="btn btn-primary px-5 fw-bold">
        <i class="fas fa-save me-2"></i>
        <?= $isEdit ? 'Lưu thay đổi' : 'Tạo nhân viên' ?>
    </button>
    <a href="<?= $isEdit ? 'view.php?id='.$id : 'index.php' ?>"
       class="btn btn-outline-secondary px-4">Huỷ</a>
</div>

</div><!-- col-lg-8 -->

<!-- ══ SIDEBAR PHẢI ══ -->
<div class="col-lg-4">

    <!-- Avatar preview -->
    <?php if ($isEdit): ?>
    <div class="card border-0 shadow-sm mb-3 text-center">
        <div class="card-body py-3">
            <?php $badge = getRoleBadge($emp['role_name'] ?? ''); ?>
            <div class="rounded-circle mx-auto d-flex align-items-center justify-content-center fw-bold
                        bg-<?= $badge['class'] ?> text-white mb-2"
                 style="width:60px;height:60px;font-size:1.4rem">
                <?= mb_strtoupper(mb_substr($emp['full_name'],0,1)) ?>
            </div>
            <div class="fw-semibold"><?= htmlspecialchars($emp['full_name']) ?></div>
            <small class="text-muted"><code><?= htmlspecialchars($emp['employee_code']??'') ?></code></small>
            <div class="mt-2">
                <span class="badge bg-<?= $badge['class'] ?>">
                    <?= $badge['icon'] ?> <?= htmlspecialchars($emp['role_label']??$emp['role_name']??'') ?>
                </span>
            </div>
            <hr class="my-2">
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-info btn-sm w-100">
                <i class="fas fa-eye me-1"></i>Xem hồ sơ
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mô tả chức vụ -->
    <div class="card border-0 shadow-sm mb-3 sticky-top" style="top:80px">
        <div class="card-body">
            <h6 class="fw-bold mb-3">
                <i class="fas fa-info-circle text-info me-2"></i>Mô tả chức vụ
            </h6>
            <?php foreach ($roles as $r):
                $rg = $roleGuide[$r['name']] ?? ['color'=>'secondary','icon'=>'👤','desc'=>''];
            ?>
            <div class="d-flex gap-2 mb-2 align-items-start" style="font-size:.82rem">
                <span class="badge bg-<?= $rg['color'] ?> flex-shrink-0 mt-1">
                    <?= $rg['icon'] ?> <?= htmlspecialchars($r['label'] ?: ucfirst($r['name'])) ?>
                </span>
                <small class="text-muted"><?= $rg['desc'] ?></small>
            </div>
            <?php endforeach; ?>

            <?php if (!$isEdit): ?>
            <hr>
            <!-- Progress hoàn thiện form -->
            <div class="mt-2">
                <small class="fw-semibold text-muted">📊 Mức hoàn thiện form</small>
                <div class="progress mt-1" style="height:8px">
                    <div class="progress-bar bg-success" id="formProgress" style="width:0%"></div>
                </div>
                <small class="text-muted" id="formProgressText">0%</small>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- col-lg-4 -->

</div><!-- row -->
</form>

</div><!-- container -->
</div><!-- main-content -->

<style>
.section-hd {
    background: linear-gradient(90deg,#f8f9fa,#fff);
    font-weight: 700; color: #333;
    border-bottom: 2px solid #e9ecef;
    padding: 10px 20px;
}
</style>

<script>
// ── Toggle show/hide password ─────────────────────────────────
function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    input.type  = input.type === 'password' ? 'text' : 'password';
    document.getElementById(iconId).className =
        input.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}

// ── Kiểm tra mật khẩu khớp ───────────────────────────────────
document.getElementById('pw2').addEventListener('input', function() {
    const pw1 = document.getElementById('pw1').value;
    const msg = document.getElementById('pwMismatch');
    msg.classList.toggle('d-none', !this.value || this.value === pw1);
});

// ── Toggle tạm trú ≡ thường trú ──────────────────────────────
function toggleSameAddr(checked) {
    const block = document.getElementById('tempAddrBlock');
    block.style.opacity       = checked ? '0.4' : '1';
    block.style.pointerEvents = checked ? 'none' : '';
    if (checked) syncAddr();
}
function syncAddr() {
    document.querySelectorAll('.perm-addr').forEach(el => {
        const target = document.getElementById(el.dataset.target);
        if (target) target.value = el.value;
    });
}
document.querySelectorAll('.perm-addr').forEach(el => {
    el.addEventListener('input', () => {
        if (document.getElementById('sameAddr').checked) syncAddr();
    });
});
// Áp dụng trạng thái ban đầu
(function() {
    const cb = document.getElementById('sameAddr');
    if (cb && cb.checked) toggleSameAddr(true);
})();

// ── Role badge preview ────────────────────────────────────────
const roleColors = {superadmin:'danger',admin:'warning',accountant:'info',dispatcher:'primary',driver:'success'};
const roleIcons  = {superadmin:'👑',admin:'🛡️',accountant:'💰',dispatcher:'🚦',driver:'🚛'};
function updateRoleBadge() {
    const sel  = document.getElementById('roleSelect');
    const opt  = sel.options[sel.selectedIndex];
    const role = opt?.dataset?.role || '';
    const prev = document.getElementById('roleBadgePreview');
    if (prev) {
        prev.innerHTML = role
            ? `<span class="badge bg-${roleColors[role]||'secondary'} fs-6">${roleIcons[role]||'👤'} ${opt.text}</span>`
            : '';
    }
}
document.getElementById('roleSelect').addEventListener('change', updateRoleBadge);
updateRoleBadge(); // áp dụng ban đầu

// ── Bank preview ──────────────────────────────────────────────
const bankLogos = {
    Vietcombank:'🟩',VietinBank:'🟦',BIDV:'🟥',Agribank:'🟫',
    Techcombank:'🔴',MBBank:'⬛',ACB:'🔵',VPBank:'🟧',TPBank:'🟣'
};
function updateBankPreview() {
    const bank    = document.getElementById('bankSelect').value;
    const account = (document.getElementById('bankAccount')?.value || '').trim();
    const preview = document.getElementById('bankPreview');
    if (!preview) return;
    if (bank || account) {
        const logo = bank ? (bankLogos[bank] || '🏦') : '';
        preview.innerHTML = `<span class="fw-semibold">${logo} ${bank || ''}</span>
            ${account ? `<span class="ms-2 font-monospace text-primary fw-bold">${account}</span>` : ''}`;
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
}
updateBankPreview();

<?php if (!$isEdit): ?>
// ── Auto-suggest username ─────────────────────────────────────
document.getElementById('fullNameInput').addEventListener('blur', function() {
    const un = document.getElementById('usernameInput');
    if (!un || un.value) return;
    const map = {'à':'a','á':'a','ả':'a','ã':'a','ạ':'a','ă':'a','ằ':'a','ắ':'a','ẳ':'a','ẵ':'a','ặ':'a','â':'a','ầ':'a','ấ':'a','ẩ':'a','ẫ':'a','ậ':'a','đ':'d','è':'e','é':'e','ẻ':'e','ẽ':'e','ẹ':'e','ê':'e','ề':'e','ế':'e','ể':'e','ễ':'e','ệ':'e','ì':'i','í':'i','ỉ':'i','ĩ':'i','ị':'i','ò':'o','ó':'o','ỏ':'o','õ':'o','ọ':'o','ô':'o','ồ':'o','ố':'o','ổ':'o','ỗ':'o','ộ':'o','ơ':'o','ờ':'o','ớ':'o','ở':'o','ỡ':'o','ợ':'o','ù':'u','ú':'u','ủ':'u','ũ':'u','ụ':'u','ư':'u','ừ':'u','ứ':'u','ử':'u','ữ':'u','ự':'u','ỳ':'y','ý':'y','ỷ':'y','ỹ':'y','ỵ':'y'};
    const parts = this.value.toLowerCase().split('').map(c => map[c]||c).join('').split(' ').filter(Boolean);
    if (parts.length >= 2) {
        un.value = (parts.at(-1) + parts.slice(0,-1).map(p=>p[0]).join('')).replace(/[^a-z0-9_]/g,'');
    }
});

// ── Progress bar form ─────────────────────────────────────────
const trackFields = [
    'full_name','employee_code','gender','date_of_birth','ethnicity',
    'email','phone','hire_date','role_id',
    'id_number','social_insurance','tax_code',
    'permanent_province','bank_account','bank_name','username','password'
];
function updateProgress() {
    const filled = trackFields.filter(name => {
        const el = document.querySelector(`[name="${name}"]`);
        return el && el.value && el.value.trim() !== '' && el.value !== '0';
    }).length;
    const pct = Math.round(filled / trackFields.length * 100);
    const bar = document.getElementById('formProgress');
    const txt = document.getElementById('formProgressText');
    if (bar) {
        bar.style.width = pct + '%';
        bar.className   = 'progress-bar ' +
            (pct < 40 ? 'bg-danger' : pct < 70 ? 'bg-warning' : 'bg-success');
    }
    if (txt) txt.textContent = pct + '%';
}
document.querySelectorAll('input,select').forEach(el =>
    el.addEventListener('change', updateProgress));
document.querySelectorAll('input').forEach(el =>
    el.addEventListener('input', updateProgress));
updateProgress();
<?php endif; ?>
</script>

<?php include '../../includes/footer.php'; ?>