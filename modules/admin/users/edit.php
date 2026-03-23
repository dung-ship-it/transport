<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'edit');

$pageTitle = 'Sửa tài khoản';
$pdo = getDBConnection();
$errors = [];

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Load user
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role FROM users u
    JOIN roles r ON u.role_id = r.id WHERE u.id = ?
");
$stmt->execute([$id]);
$editUser = $stmt->fetch();
if (!$editUser) { header('Location: index.php'); exit; }

$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();
$customers = $pdo->query("SELECT id, company_name FROM customers WHERE is_active=TRUE ORDER BY company_name")->fetchAll();

// Customer hiện tại của user này
$currentCustomer = $pdo->prepare("SELECT customer_id FROM customer_users WHERE user_id = ? LIMIT 1");
$currentCustomer->execute([$id]);
$currentCustomerId = $currentCustomer->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name   = trim($_POST['full_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $role_id     = (int)($_POST['role_id'] ?? 0);
    $password    = $_POST['password'] ?? '';
    $password2   = $_POST['password2'] ?? '';
    $customer_id = (int)($_POST['customer_id'] ?? 0);
    $is_active   = isset($_POST['is_active']) ? true : false;

    if (!$full_name) $errors[] = 'Họ tên không được trống';
    if (!$role_id)   $errors[] = 'Vui lòng chọn Role';
    if ($password && strlen($password) < 4) $errors[] = 'Mật khẩu tối thiểu 4 ký tự';
    if ($password && $password !== $password2) $errors[] = 'Mật khẩu xác nhận không khớp';

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            if ($password) {
                $pdo->prepare("
                    UPDATE users SET full_name=?, email=?, phone=?, role_id=?,
                    is_active=?, password_hash=crypt(?, gen_salt('bf')), updated_at=NOW()
                    WHERE id=?
                ")->execute([$full_name, $email ?: null, $phone ?: null, $role_id, $is_active, $password, $id]);
            } else {
                $pdo->prepare("
                    UPDATE users SET full_name=?, email=?, phone=?, role_id=?,
                    is_active=?, updated_at=NOW() WHERE id=?
                ")->execute([$full_name, $email ?: null, $phone ?: null, $role_id, $is_active, $id]);
            }

            // Cập nhật customer_users
            $roleName = '';
            foreach ($roles as $r) {
                if ($r['id'] == $role_id) { $roleName = $r['name']; break; }
            }
            if ($roleName === 'customer') {
                $pdo->prepare("DELETE FROM customer_users WHERE user_id = ?")->execute([$id]);
                if ($customer_id) {
                    $pdo->prepare("
                        INSERT INTO customer_users (customer_id, user_id, is_primary)
                        VALUES (?, ?, TRUE) ON CONFLICT DO NOTHING
                    ")->execute([$customer_id, $id]);
                }
            }

            // Tạo driver record nếu đổi sang role driver
            if ($roleName === 'driver') {
                $pdo->prepare("
                    INSERT INTO drivers (user_id, hire_date, base_salary)
                    VALUES (?, CURRENT_DATE, 0) ON CONFLICT (user_id) DO NOTHING
                ")->execute([$id]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Cập nhật tài khoản thành công!'];
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Lỗi: ' . $e->getMessage();
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
        <h4 class="fw-bold mb-0">✏️ Sửa tài khoản: <span class="text-primary"><?= htmlspecialchars($editUser['username']) ?></span></h4>
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

    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control"
                                   value="<?= htmlspecialchars($editUser['full_name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control"
                                   value="<?= htmlspecialchars($editUser['username']) ?>" disabled>
                            <small class="text-muted">Không thể đổi username</small>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Điện thoại</label>
                            <input type="text" name="phone" class="form-control"
                                   value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                            <select name="role_id" id="roleSelect" class="form-select"
                                    onchange="toggleCustomerField(this)">
                                <?php foreach ($roles as $r):
                                    $b = getRoleBadge($r['name']);
                                ?>
                                <option value="<?= $r['id'] ?>"
                                    data-role="<?= $r['name'] ?>"
                                    <?= $editUser['role_id'] == $r['id'] ? 'selected' : '' ?>>
                                    <?= $b['icon'] ?> <?= $r['label'] ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Trạng thái</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active"
                                       id="isActive" <?= $editUser['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="isActive">Đang hoạt động</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3" id="customerField"
                         style="display:<?= $editUser['role'] === 'customer' ? 'block' : 'none' ?>">
                        <label class="form-label fw-semibold">Thuộc công ty khách hàng</label>
                        <select name="customer_id" class="form-select">
                            <option value="">-- Chọn công ty --</option>
                            <?php foreach ($customers as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                <?= $currentCustomerId == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['company_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>
                    <h6 class="fw-bold text-primary mb-3">
                        <i class="fas fa-lock me-1"></i> Đổi mật khẩu
                        <small class="text-muted fw-normal">(để trống nếu không đổi)</small>
                    </h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <input type="password" name="password" class="form-control"
                                   placeholder="Mật khẩu mới...">
                        </div>
                        <div class="col-md-6">
                            <input type="password" name="password2" class="form-control"
                                   placeholder="Xác nhận mật khẩu mới...">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fas fa-save me-1"></i> Lưu thay đổi
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>
</div>

<script>
function toggleCustomerField(select) {
    const role = select.options[select.selectedIndex].getAttribute('data-role');
    document.getElementById('customerField').style.display =
        (role === 'customer') ? 'block' : 'none';
}
</script>

<?php include '../../../includes/footer.php'; ?>