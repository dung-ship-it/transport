<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'create');

$pageTitle = 'Tạo tài khoản';
$pdo = getDBConnection();
$errors = [];
$data = [];

// Load roles & customers
$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$customers = $pdo->query("SELECT id, company_name FROM customers WHERE is_active=TRUE ORDER BY company_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'username'    => trim($_POST['username'] ?? ''),
        'full_name'   => trim($_POST['full_name'] ?? ''),
        'email'       => trim($_POST['email'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'role_id'     => (int)($_POST['role_id'] ?? 0),
        'password'    => $_POST['password'] ?? '',
        'password2'   => $_POST['password2'] ?? '',
        'customer_id' => (int)($_POST['customer_id'] ?? 0),
    ];

    // Validation
    if (!$data['username'])   $errors[] = 'Username không được trống';
    if (!$data['full_name'])  $errors[] = 'Họ tên không được trống';
    if (!$data['role_id'])    $errors[] = 'Vui lòng chọn Role';
    if (strlen($data['password']) < 4) $errors[] = 'Mật khẩu tối thiểu 4 ký tự';
    if ($data['password'] !== $data['password2']) $errors[] = 'Mật khẩu xác nhận không khớp';

    // Check username trùng
    $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmtCheck->execute([$data['username']]);
    if ($stmtCheck->fetch()) $errors[] = 'Username đã tồn tại';

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Tạo user
            $stmtU = $pdo->prepare("
                INSERT INTO users (username, password_hash, full_name, email, phone, role_id)
                VALUES (?, crypt(?, gen_salt('bf')), ?, ?, ?, ?)
                RETURNING id
            ");
            $stmtU->execute([
                $data['username'],
                $data['password'],
                $data['full_name'],
                $data['email'] ?: null,
                $data['phone'] ?: null,
                $data['role_id'],
            ]);
            $newUserId = $stmtU->fetchColumn();

            // Nếu là Customer → gán vào customer_users
            $roleName = '';
            foreach ($roles as $r) {
                if ($r['id'] == $data['role_id']) { $roleName = $r['name']; break; }
            }

            if ($roleName === 'customer' && $data['customer_id']) {
                $pdo->prepare("
                    INSERT INTO customer_users (customer_id, user_id, is_primary)
                    VALUES (?, ?, TRUE) ON CONFLICT DO NOTHING
                ")->execute([$data['customer_id'], $newUserId]);
            }

            // Nếu là Driver → tạo record trong bảng drivers
            if ($roleName === 'driver') {
                $pdo->prepare("
                    INSERT INTO drivers (user_id, hire_date, base_salary)
                    VALUES (?, CURRENT_DATE, 0)
                    ON CONFLICT (user_id) DO NOTHING
                ")->execute([$newUserId]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "✅ Tạo tài khoản <strong>{$data['username']}</strong> thành công!"];
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Lỗi hệ thống: ' . $e->getMessage();
        }
    }
}

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">➕ Tạo tài khoản mới</h4>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <form method="POST">

                        <!-- Thông tin cơ bản -->
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-user me-1"></i> Thông tin tài khoản
                        </h6>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Họ và tên <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="full_name" class="form-control"
                                       value="<?= htmlspecialchars($data['full_name'] ?? '') ?>"
                                       placeholder="Nguyễn Văn A" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Username <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="username" class="form-control"
                                       value="<?= htmlspecialchars($data['username'] ?? '') ?>"
                                       placeholder="nguyenvana" required>
                                <small class="text-muted">Chỉ dùng chữ thường, số, dấu _</small>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control"
                                       value="<?= htmlspecialchars($data['email'] ?? '') ?>"
                                       placeholder="email@company.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Điện thoại</label>
                                <input type="text" name="phone" class="form-control"
                                       value="<?= htmlspecialchars($data['phone'] ?? '') ?>"
                                       placeholder="0901234567">
                            </div>
                        </div>

                        <hr>
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-shield-alt me-1"></i> Phân quyền
                        </h6>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Role <span class="text-danger">*</span>
                            </label>
                            <select name="role_id" id="roleSelect" class="form-select" required
                                    onchange="toggleCustomerField(this)">
                                <option value="">-- Chọn Role --</option>
                                <?php foreach ($roles as $r):
                                    $badge = getRoleBadge($r['name']);
                                    // Admin không được tạo superadmin
                                    if (!hasRole('admin') && $r['name'] === 'superadmin') continue;
                                    if (hasRole('admin') && $r['name'] === 'admin' && !hasRole('superadmin')) continue;
                                ?>
                                <option value="<?= $r['id'] ?>"
                                    data-role="<?= $r['name'] ?>"
                                    <?= ($data['role_id'] ?? 0) == $r['id'] ? 'selected' : '' ?>>
                                    <?= $badge['icon'] ?> <?= $r['label'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Field chọn công ty (chỉ hiện khi role = customer) -->
                        <div class="mb-3" id="customerField" style="display:none">
                            <label class="form-label fw-semibold">
                                Thuộc công ty khách hàng
                                <span class="text-danger">*</span>
                            </label>
                            <select name="customer_id" class="form-select">
                                <option value="">-- Chọn công ty --</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"
                                    <?= ($data['customer_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['company_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <hr>
                        <h6 class="fw-bold text-primary mb-3">
                            <i class="fas fa-lock me-1"></i> Mật khẩu
                        </h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Mật khẩu <span class="text-danger">*</span>
                                </label>
                                <input type="password" name="password" class="form-control"
                                       placeholder="Tối thiểu 4 ký tự" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    Xác nhận mật khẩu <span class="text-danger">*</span>
                                </label>
                                <input type="password" name="password2" class="form-control"
                                       placeholder="Nhập lại mật khẩu" required>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Tạo tài khoản
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
                        </div>

                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar hướng dẫn -->
        <div class="col-md-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 pt-3">
                    <h6 class="fw-bold mb-0">📋 Phân quyền theo Role</h6>
                </div>
                <div class="card-body p-3">
                    <?php
                    $roleGuide = [
                        'superadmin' => ['danger',    'Xem dashboard, duyệt quyền đặc biệt, xem báo cáo'],
                        'admin'      => ['primary',   'CRUD users, phân quyền, duyệt lương, sửa mọi chỗ'],
                        'accountant' => ['info',      'Bảng giá, bảng kê, công nợ, tính lương, xuất báo giá'],
                        'dispatcher' => ['warning',   'Quản lý xe/tài xế, KPI, phân công, chi phí vận hành'],
                        'driver'     => ['success',   'Tạo chuyến theo mẫu, nhập xăng dầu, xem chuyến của mình'],
                        'customer'   => ['secondary', 'Xem chuyến công ty, confirm/reject, in bảng kê'],
                    ];
                    foreach ($roles as $r):
                        if (!isset($roleGuide[$r['name']])) continue;
                        [$bc, $desc] = $roleGuide[$r['name']];
                        $b = getRoleBadge($r['name']);
                    ?>
                    <div class="d-flex gap-2 mb-2">
                        <span class="badge bg-<?= $bc ?> mt-1" style="height:fit-content">
                            <?= $b['icon'] ?>
                        </span>
                        <div>
                            <div class="fw-semibold small"><?= $r['label'] ?></div>
                            <div class="text-muted" style="font-size:0.78rem"><?= $desc ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

<script>
function toggleCustomerField(select) {
    const opt = select.options[select.selectedIndex];
    const role = opt.getAttribute('data-role');
    const field = document.getElementById('customerField');
    field.style.display = (role === 'customer') ? 'block' : 'none';
}
// Chạy khi load (nếu có giá trị cũ)
toggleCustomerField(document.getElementById('roleSelect'));
</script>

<?php include '../../../includes/footer.php'; ?>