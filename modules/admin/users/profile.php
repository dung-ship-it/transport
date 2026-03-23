<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'view');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? currentUser()['id']);

// Load user + role
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role, r.label AS role_label
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$profile = $stmt->fetch();
if (!$profile) { header('Location: index.php'); exit; }

// Load salary components
$salaryComponents = $pdo->prepare("
    SELECT * FROM salary_components WHERE user_id = ? AND is_active = TRUE ORDER BY id
");
$salaryComponents->execute([$id]);
$salaryComponents = $salaryComponents->fetchAll();

$totalSalary = array_sum(array_column($salaryComponents, 'amount'));

$isCustomer = $profile['role'] === 'customer';
$canEdit    = can('users', 'edit');
$pageTitle  = 'Hồ sơ: ' . $profile['full_name'];

// Danh sách tỉnh thành (rút gọn)
$provinces = [
    'An Giang','Bà Rịa - Vũng Tàu','Bắc Giang','Bắc Kạn','Bạc Liêu',
    'Bắc Ninh','Bến Tre','Bình Định','Bình Dương','Bình Phước','Bình Thuận',
    'Cà Mau','Cần Thơ','Cao Bằng','Đà Nẵng','Đắk Lắk','Đắk Nông','Điện Biên',
    'Đồng Nai','Đồng Tháp','Gia Lai','Hà Giang','Hà Nam','Hà Nội','Hà Tĩnh',
    'Hải Dương','Hải Phòng','Hậu Giang','Hòa Bình','Hưng Yên','Khánh Hòa',
    'Kiên Giang','Kon Tum','Lai Châu','Lâm Đồng','Lạng Sơn','Lào Cai','Long An',
    'Nam Định','Nghệ An','Ninh Bình','Ninh Thuận','Phú Thọ','Phú Yên','Quảng Bình',
    'Quảng Nam','Quảng Ngãi','Quảng Ninh','Quảng Trị','Sóc Trăng','Sơn La',
    'Tây Ninh','Thái Bình','Thái Nguyên','Thanh Hóa','Thừa Thiên Huế','Tiền Giang',
    'TP. Hồ Chí Minh','Trà Vinh','Tuyên Quang','Vĩnh Long','Vĩnh Phúc',
    'Yên Bái',
];

$banks = [
    'Vietcombank','VietinBank','BIDV','Agribank','Techcombank','MB Bank',
    'ACB','VPBank','TPBank','Sacombank','HDBank','OCB','MSB','SeABank',
    'VIB','SHB','Eximbank','NamABank','BaoVietBank','ABBank','NCB','Pvcombank',
];

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-3" style="max-width:1100px">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h5 class="mb-0 fw-bold">
                    <i class="fas fa-id-badge me-1 text-primary"></i> Hồ sơ nhân viên
                </h5>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <?php $badge = getRoleBadge($profile['role']); ?>
                    <span class="badge bg-<?= $badge['class'] ?>"><?= $badge['icon'] ?> <?= $profile['role_label'] ?></span>
                    <strong><?= htmlspecialchars($profile['full_name']) ?></strong>
                    <span class="badge bg-secondary"><?= $profile['employee_code'] ?></span>
                    <?php if ($profile['role'] === 'driver'): ?>
                        <span class="text-muted small">Lái xe</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm">
                <i class="fas fa-user-cog me-1"></i> Tài khoản
            </a>
            <button class="btn btn-warning btn-sm" onclick="showChangePassword()">
                <i class="fas fa-key me-1"></i> Mật khẩu
            </button>
        </div>
    </div>

    <?php showFlash(); ?>

    <form method="POST" action="profile_save.php?id=<?= $id ?>">

    <!-- ── 1. THÔNG TIN CƠ BẢN ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-user-circle me-1 text-primary"></i> 1. Thông tin cơ bản
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">ID Nhân viên</label>
                    <input type="text" class="form-control bg-light"
                           value="<?= htmlspecialchars($profile['employee_code'] ?? '') ?>"
                           readonly>
                    <div class="form-text">Mã tự động, không thể đổi</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small fw-semibold text-muted">Họ và Tên</label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($profile['full_name']) ?>"
                           <?= !$canEdit ? 'readonly' : '' ?> required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">
                        Giới tính <span class="text-danger">*</span>
                    </label>
                    <select name="gender" class="form-select" <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="">-- Chọn --</option>
                        <option value="male"   <?= $profile['gender']==='male'   ?'selected':'' ?>>😊 Nam</option>
                        <option value="female" <?= $profile['gender']==='female' ?'selected':'' ?>>👩 Nữ</option>
                        <option value="other"  <?= $profile['gender']==='other'  ?'selected':'' ?>>🌈 Khác</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Hôn nhân</label>
                    <select name="marital_status" class="form-select" <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="">-- Chọn --</option>
                        <option value="single"   <?= $profile['marital_status']==='single'   ?'selected':'' ?>>Độc thân</option>
                        <option value="married"  <?= $profile['marital_status']==='married'  ?'selected':'' ?>>Đã kết hôn</option>
                        <option value="divorced" <?= $profile['marital_status']==='divorced' ?'selected':'' ?>>Ly hôn</option>
                        <option value="widowed"  <?= $profile['marital_status']==='widowed'  ?'selected':'' ?>>Góa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Ngày sinh</label>
                    <input type="date" name="date_of_birth" class="form-control"
                           value="<?= $profile['date_of_birth'] ?? '' ?>"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <?php if (!$isCustomer): ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Ngày vào công ty</label>
                    <input type="date" name="hire_date" class="form-control"
                           value="<?= $profile['hire_date'] ?? '' ?>"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Dân tộc</label>
                    <select name="ethnicity" class="form-select" <?= !$canEdit ? 'disabled' : '' ?>>
                        <?php foreach (['Kinh','Tày','Thái','Mường','Khmer','Mông','Nùng','Hoa','Dao','Khác'] as $e): ?>
                        <option <?= $profile['ethnicity']===$e?'selected':'' ?>><?= $e ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Số điện thoại</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-phone text-muted"></i></span>
                        <input type="text" name="phone" class="form-control"
                               value="<?= htmlspecialchars($profile['phone'] ?? '') ?>"
                               placeholder="0901234567"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope text-muted"></i></span>
                        <input type="email" name="email" class="form-control"
                               value="<?= htmlspecialchars($profile['email'] ?? '') ?>"
                               placeholder="email@company.com"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!$isCustomer): ?>

    <!-- ── 2. ĐỊA CHỈ THƯỜNG TRÚ ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-home me-1 text-success"></i> 2. Địa chỉ thường trú
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Tỉnh / Thành phố</label>
                    <select name="permanent_province" class="form-select" id="permProvince"
                            <?= !$canEdit ? 'disabled' : '' ?> onchange="syncTemp()">
                        <option value="">-- Chọn tỉnh/thành --</option>
                        <?php foreach ($provinces as $p): ?>
                        <option <?= $profile['permanent_province']===$p?'selected':'' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Xã / Phường</label>
                    <input type="text" name="permanent_district" class="form-control"
                           value="<?= htmlspecialchars($profile['permanent_district'] ?? '') ?>"
                           placeholder="VD: Xã Thiên Lộc"
                           <?= !$canEdit ? 'readonly' : '' ?> onchange="syncTemp()">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Đường / Phố</label>
                    <input type="text" name="permanent_street" class="form-control"
                           value="<?= htmlspecialchars($profile['permanent_street'] ?? '') ?>"
                           placeholder="VD: Đường Liên Xã"
                           <?= !$canEdit ? 'readonly' : '' ?> onchange="syncTemp()">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Số nhà / Thôn / Ấp</label>
                    <input type="text" name="permanent_address" class="form-control"
                           value="<?= htmlspecialchars($profile['permanent_address'] ?? '') ?>"
                           placeholder="VD: 58 Đường Liên Xã, Thôn Nhuế"
                           <?= !$canEdit ? 'readonly' : '' ?> onchange="syncTemp()">
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3. ĐỊA CHỈ TẠM TRÚ ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-map-marker-alt me-1 text-warning"></i> 3. Địa chỉ tạm trú
            </h6>
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" name="temp_same_as_permanent"
                       id="sameAddress" value="1"
                       <?= $profile['temp_same_as_permanent'] ? 'checked' : '' ?>
                       onchange="toggleTempAddress(this)">
                <label class="form-check-label small" for="sameAddress">
                    Giống địa chỉ thường trú
                </label>
            </div>
        </div>
        <div class="card-body" id="tempAddressFields"
             style="<?= $profile['temp_same_as_permanent'] ? 'opacity:0.5;pointer-events:none' : '' ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Tỉnh / Thành phố</label>
                    <select name="temp_province" class="form-select" id="tempProvince"
                            <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="">-- Chọn tỉnh/thành --</option>
                        <?php foreach ($provinces as $p): ?>
                        <option <?= $profile['temp_province']===$p?'selected':'' ?>><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Phường / Xã</label>
                    <input type="text" name="temp_district" id="tempDistrict" class="form-control"
                           value="<?= htmlspecialchars($profile['temp_district'] ?? '') ?>"
                           placeholder="VD: Xã Thiên Lộc"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Đường / Phố</label>
                    <input type="text" name="temp_street" id="tempStreet" class="form-control"
                           value="<?= htmlspecialchars($profile['temp_street'] ?? '') ?>"
                           placeholder="VD: Đường Liên Xã"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Số nhà / Thôn / Ấp</label>
                    <input type="text" name="temp_address" id="tempAddress" class="form-control"
                           value="<?= htmlspecialchars($profile['temp_address'] ?? '') ?>"
                           placeholder="VD: Số 58, Thôn Nhuế"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 4. GIẤY TỜ TÙY THÂN ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-id-card me-1 text-info"></i> 4. Giấy tờ tùy thân
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Số CMND / CCCD</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-card text-muted"></i></span>
                        <input type="text" name="id_number" class="form-control"
                               value="<?= htmlspecialchars($profile['id_number'] ?? '') ?>"
                               placeholder="001095553551"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                    <div class="form-text">Căn Cước Công Dân hoặc Chứng Minh Thư Nhân Dân: 12 số</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Ngày cấp</label>
                    <input type="date" name="id_issue_date" class="form-control"
                           value="<?= $profile['id_issue_date'] ?? '' ?>"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Nơi cấp</label>
                    <input type="text" name="id_issue_place" class="form-control"
                           value="<?= htmlspecialchars($profile['id_issue_place'] ?? '') ?>"
                           placeholder="VD: Cục Cảnh Sát"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 5. TÀI CHÍNH & BẢO HIỂM ── -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-university me-1 text-success"></i> 5. Tài chính & Bảo hiểm
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Số sổ BHXH</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:#e83e3e">
                            <i class="fas fa-shield-alt text-white" style="font-size:0.8rem"></i>
                        </span>
                        <input type="text" name="social_insurance" class="form-control"
                               value="<?= htmlspecialchars($profile['social_insurance'] ?? '') ?>"
                               placeholder="Số sổ bảo hiểm xã hội (nếu có)"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Mã số thuế cá nhân</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:#6c757d">
                            <i class="fas fa-receipt text-white" style="font-size:0.8rem"></i>
                        </span>
                        <input type="text" name="tax_code" class="form-control"
                               value="<?= htmlspecialchars($profile['tax_code'] ?? '') ?>"
                               placeholder="10 số MST cá nhân"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Tên ngân hàng</label>
                    <select name="bank_name" id="bankSelect" class="form-select"
                            onchange="updateBankPreview()"
                            <?= !$canEdit ? 'disabled' : '' ?>>
                        <option value="">-- Chọn ngân hàng --</option>
                        <?php foreach ($banks as $b): ?>
                        <option <?= $profile['bank_name']===$b?'selected':'' ?>><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Số tài khoản ngân hàng</label>
                    <div class="input-group">
                        <span class="input-group-text" style="background:#198754">
                            <i class="fas fa-credit-card text-white" style="font-size:0.8rem"></i>
                        </span>
                        <input type="text" name="bank_account" id="bankAccount" class="form-control"
                               value="<?= htmlspecialchars($profile['bank_account'] ?? '') ?>"
                               placeholder="Số tài khoản"
                               <?= !$canEdit ? 'readonly' : '' ?>>
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="copyAccount()" title="Copy">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Chi nhánh ngân hàng</label>
                    <input type="text" name="bank_branch" class="form-control"
                           value="<?= htmlspecialchars($profile['bank_branch'] ?? '') ?>"
                           placeholder="VD: Chi nhánh Hà Nội (nếu có)"
                           <?= !$canEdit ? 'readonly' : '' ?>>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Xem trước</label>
                    <div class="form-control bg-light text-muted small" id="bankPreview"
                         style="min-height:38px;line-height:1.8">
                        <?= $profile['bank_name'] ? htmlspecialchars($profile['bank_name']) : 'Chọn ngân hàng để xem' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 6. KHOẢN LƯƠNG ── -->
    <?php if (can('payroll', 'view') || can('payroll', 'submit')): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-dollar-sign me-1 text-success"></i>
                6. Thông tin chung / General information
            </h6>
            <?php if ($canEdit): ?>
            <button type="button" class="btn btn-success btn-sm" onclick="addSalaryRow()">
                <i class="fas fa-plus me-1"></i> Thêm khoản
            </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <table class="table mb-0" id="salaryTable">
                <thead style="background:#1a1a2e;color:white">
                    <tr>
                        <th class="ps-3">Khoản lương / Salary component</th>
                        <th class="text-end">Số tiền (VNĐ)</th>
                        <?php if ($canEdit): ?>
                        <th class="text-center" width="80">Thao tác</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="salaryBody">
                    <tr class="table-light">
                        <td class="ps-3 fw-semibold">
                            Lương Tổng / Gross salary <span class="text-muted fw-normal">(=1+2+3+…)</span>
                        </td>
                        <td class="text-end fw-bold text-danger" id="totalDisplay">
                            <?= number_format($totalSalary, 0, '.', ',') ?>
                        </td>
                        <?php if ($canEdit): ?><td></td><?php endif; ?>
                    </tr>
                    <?php if (empty($salaryComponents)): ?>
                    <tr id="emptyRow">
                        <td colspan="<?= $canEdit ? 3 : 2 ?>" class="text-center py-4 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                            Chưa có thông tin lương.
                            <?php if ($canEdit): ?>
                            <a href="#" onclick="addSalaryRow();return false"
                               class="text-primary">+ Thêm khoản lương</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($salaryComponents as $i => $sc): ?>
                    <tr class="salary-row" data-id="<?= $sc['id'] ?>">
                        <td class="ps-3">
                            <span class="text-muted me-2"><?= $i+1 ?>.</span>
                            <?php if ($canEdit): ?>
                            <input type="text" name="salary_name[]"
                                   class="form-control form-control-sm d-inline-block"
                                   style="width:auto;min-width:200px"
                                   value="<?= htmlspecialchars($sc['name']) ?>">
                            <input type="hidden" name="salary_id[]" value="<?= $sc['id'] ?>">
                            <?php else: ?>
                            <?= htmlspecialchars($sc['name']) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($canEdit): ?>
                            <input type="number" name="salary_amount[]"
                                   class="form-control form-control-sm text-end salary-input"
                                   style="width:150px;margin-left:auto"
                                   value="<?= $sc['amount'] ?>"
                                   oninput="recalcTotal()">
                            <?php else: ?>
                            <span class="fw-semibold">
                                <?= number_format($sc['amount'], 0, '.', ',') ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <?php if ($canEdit): ?>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="removeSalaryRow(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* !$isCustomer */ ?>

    <!-- ── BUTTONS ── -->
    <?php if ($canEdit): ?>
    <div class="d-flex justify-content-end gap-2 mb-4 pt-2 border-top">
        <a href="index.php" class="btn btn-outline-secondary px-4">
            <i class="fas fa-arrow-left me-1"></i> Quay lại
        </a>
        <button type="reset" class="btn btn-outline-warning px-4">
            <i class="fas fa-undo me-1"></i> Đặt lại
        </button>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fas fa-save me-1"></i> Lưu hồ sơ
        </button>
    </div>
    <?php endif; ?>

    </form>
</div>
</div>

<!-- Modal đổi mật khẩu -->
<div class="modal fade" id="passwordModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold">🔑 Đổi mật khẩu</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="change_password.php?id=<?= $id ?>" method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Mật khẩu mới</label>
                        <input type="password" name="password" class="form-control" required minlength="4">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Xác nhận</label>
                        <input type="password" name="password2" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-sm btn-warning">Đổi mật khẩu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Địa chỉ tạm trú sync ──
function toggleTempAddress(cb) {
    const fields = document.getElementById('tempAddressFields');
    fields.style.opacity = cb.checked ? '0.5' : '1';
    fields.style.pointerEvents = cb.checked ? 'none' : 'auto';
    if (cb.checked) syncTemp();
}

function syncTemp() {
    if (!document.getElementById('sameAddress')?.checked) return;
    const prov = document.getElementById('permProvince');
    document.getElementById('tempProvince').value =
        prov ? prov.options[prov.selectedIndex]?.text : '';
    document.getElementById('tempDistrict').value =
        document.querySelector('[name=permanent_district]')?.value || '';
    document.getElementById('tempStreet').value =
        document.querySelector('[name=permanent_street]')?.value || '';
    document.getElementById('tempAddress').value =
        document.querySelector('[name=permanent_address]')?.value || '';
}

// ── Ngân hàng preview ──
function updateBankPreview() {
    const bank = document.getElementById('bankSelect').value;
    const acct = document.getElementById('bankAccount').value;
    document.getElementById('bankPreview').textContent =
        bank ? (bank + (acct ? ' — ' + acct : '')) : 'Chọn ngân hàng để xem';
}
document.getElementById('bankAccount')?.addEventListener('input', updateBankPreview);

function copyAccount() {
    const val = document.getElementById('bankAccount').value;
    if (val) {
        navigator.clipboard.writeText(val);
        alert('Đã copy: ' + val);
    }
}

// ── Khoản lương ──
let salaryRowIndex = <?= count($salaryComponents) ?>;

function addSalaryRow() {
    const empty = document.getElementById('emptyRow');
    if (empty) empty.remove();

    salaryRowIndex++;
    const tr = document.createElement('tr');
    tr.className = 'salary-row';
    tr.innerHTML = `
        <td class="ps-3">
            <span class="text-muted me-2">${salaryRowIndex}.</span>
            <input type="text" name="salary_name[]"
                   class="form-control form-control-sm d-inline-block"
                   style="width:auto;min-width:200px"
                   placeholder="VD: Lương cơ bản, Phụ cấp...">
            <input type="hidden" name="salary_id[]" value="0">
        </td>
        <td class="text-end">
            <input type="number" name="salary_amount[]"
                   class="form-control form-control-sm text-end salary-input"
                   style="width:150px;margin-left:auto"
                   value="0" oninput="recalcTotal()">
        </td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger"
                    onclick="removeSalaryRow(this)">
                <i class="fas fa-times"></i>
            </button>
        </td>`;
    document.getElementById('salaryBody').appendChild(tr);
    recalcTotal();
}

function removeSalaryRow(btn) {
    btn.closest('tr').remove();
    recalcTotal();
    // Cập nhật lại số thứ tự
    document.querySelectorAll('.salary-row').forEach((row, i) => {
        const num = row.querySelector('.text-muted');
        if (num) num.textContent = (i + 1) + '.';
    });
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('.salary-input').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    document.getElementById('totalDisplay').textContent =
        new Intl.NumberFormat('vi-VN').format(total);
}

function showChangePassword() {
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
}
</script>

<?php include '../../../includes/footer.php'; ?>