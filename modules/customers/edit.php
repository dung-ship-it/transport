<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('customers', 'crud');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { header('Location: index.php'); exit; }

$pageTitle = 'Sửa: ' . $customer['company_name'];
$errors    = [];

// ── Xử lý POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'company_name'           => trim($_POST['company_name'] ?? ''),
        'short_name'             => trim($_POST['short_name'] ?? '') ?: null,
        'tax_code'               => trim($_POST['tax_code'] ?? '') ?: null,
        'legal_address'          => trim($_POST['legal_address'] ?? '') ?: null,
        'invoice_address'        => trim($_POST['invoice_address'] ?? '') ?: null,
        'legal_representative'   => trim($_POST['legal_representative'] ?? '') ?: null,
        'representative_title'   => trim($_POST['representative_title'] ?? '') ?: null,
        'primary_contact_name'   => trim($_POST['primary_contact_name'] ?? '') ?: null,
        'primary_contact_phone'  => trim($_POST['primary_contact_phone'] ?? '') ?: null,
        'primary_contact_email'  => trim($_POST['primary_contact_email'] ?? '') ?: null,
        'bank_name'              => trim($_POST['bank_name'] ?? '') ?: null,
        'bank_account_number'    => trim($_POST['bank_account_number'] ?? '') ?: null,
        'bank_branch'            => trim($_POST['bank_branch'] ?? '') ?: null,
        'payment_terms'          => (int)($_POST['payment_terms'] ?? 30),
        'billing_cycle'          => $_POST['billing_cycle'] ?? 'monthly',
        'billing_day'            => (int)($_POST['billing_day'] ?? 1) ?: null,
        'is_active'              => isset($_POST['is_active']) ? true : false,
        'note'                   => trim($_POST['note'] ?? '') ?: null,
    ];

    // Validate
    if (!$data['company_name']) $errors[] = 'Tên công ty không được trống';
    if ($data['primary_contact_email'] && !filter_var($data['primary_contact_email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Email liên hệ không hợp lệ';

    // Kiểm tra MST trùng (trừ chính nó)
    if ($data['tax_code']) {
        $dupCheck = $pdo->prepare("SELECT id FROM customers WHERE tax_code = ? AND id != ?");
        $dupCheck->execute([$data['tax_code'], $id]);
        if ($dupCheck->fetchColumn()) $errors[] = "Mã số thuế <strong>{$data['tax_code']}</strong> đã tồn tại";
    }

    if (empty($errors)) {
        $pdo->prepare("
            UPDATE customers SET
                company_name          = ?,
                short_name            = ?,
                tax_code              = ?,
                legal_address         = ?,
                invoice_address       = ?,
                legal_representative  = ?,
                representative_title  = ?,
                primary_contact_name  = ?,
                primary_contact_phone = ?,
                primary_contact_email = ?,
                bank_name             = ?,
                bank_account_number   = ?,
                bank_branch           = ?,
                payment_terms         = ?,
                billing_cycle         = ?,
                billing_day           = ?,
                is_active             = ?,
                note                  = ?,
                updated_at            = NOW()
            WHERE id = ?
        ")->execute([
            $data['company_name'],
            $data['short_name'],
            $data['tax_code'],
            $data['legal_address'],
            $data['invoice_address'],
            $data['legal_representative'],
            $data['representative_title'],
            $data['primary_contact_name'],
            $data['primary_contact_phone'],
            $data['primary_contact_email'],
            $data['bank_name'],
            $data['bank_account_number'],
            $data['bank_branch'],
            $data['payment_terms'],
            $data['billing_cycle'],
            $data['billing_day'],
            $data['is_active'] ? 'true' : 'false',
            $data['note'],
            $id,
        ]);

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => '✅ Đã cập nhật thông tin công ty <strong>'
                      . htmlspecialchars($data['company_name']) . '</strong>!'
        ];
        header("Location: detail.php?id=$id&tab=info"); exit;
    }

    // Nếu có lỗi — giữ lại giá trị vừa nhập
    $customer = array_merge($customer, $data);
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="detail.php?id=<?= $id ?>&tab=info" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h4 class="fw-bold mb-0">
                ✏️ Sửa thông tin khách hàng
            </h4>
            <small class="text-muted">
                <?= htmlspecialchars($customer['customer_code']) ?>
                — <?= htmlspecialchars($customer['company_name']) ?>
            </small>
        </div>
    </div>

    <!-- Lỗi -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <strong>Có lỗi xảy ra:</strong>
        <ul class="mb-0 mt-1">
            <?php foreach ($errors as $e): ?>
            <li><?= $e ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST">
    <div class="row g-4">

        <!-- CỘT TRÁI -->
        <div class="col-md-6">

            <!-- Thông tin cơ bản -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📋 Thông tin cơ bản & Pháp lý</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Tên công ty <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="company_name" class="form-control"
                               value="<?= htmlspecialchars($customer['company_name']) ?>"
                               required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tên viết tắt</label>
                            <input type="text" name="short_name" class="form-control"
                                   value="<?= htmlspecialchars($customer['short_name'] ?? '') ?>"
                                   placeholder="VD: SCAN, DNA...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Mã số thuế</label>
                            <input type="text" name="tax_code" class="form-control"
                                   value="<?= htmlspecialchars($customer['tax_code'] ?? '') ?>"
                                   placeholder="0123456789">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label fw-semibold">Địa chỉ pháp lý</label>
                        <textarea name="legal_address" class="form-control" rows="2"
                                  placeholder="Địa chỉ đăng ký kinh doanh..."><?= htmlspecialchars($customer['legal_address'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Địa chỉ xuất hóa đơn</label>
                        <textarea name="invoice_address" class="form-control" rows="2"
                                  placeholder="Để trống nếu giống địa chỉ pháp lý..."><?= htmlspecialchars($customer['invoice_address'] ?? '') ?></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Người đại diện pháp lý</label>
                            <input type="text" name="legal_representative" class="form-control"
                                   value="<?= htmlspecialchars($customer['legal_representative'] ?? '') ?>"
                                   placeholder="Họ tên GĐ/TGĐ...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Chức danh</label>
                            <input type="text" name="representative_title" class="form-control"
                                   value="<?= htmlspecialchars($customer['representative_title'] ?? '') ?>"
                                   placeholder="Giám đốc, TGĐ...">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Ghi chú nội bộ</label>
                        <textarea name="note" class="form-control" rows="2"
                                  placeholder="Ghi chú nội bộ..."><?= htmlspecialchars($customer['note'] ?? '') ?></textarea>
                    </div>
                    <div class="mt-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active"
                               id="isActive" <?= $customer['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="isActive">
                            Đang hoạt động
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- CỘT PHẢI -->
        <div class="col-md-6">

            <!-- Liên hệ -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📞 Thông tin liên hệ</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Người liên hệ chính</label>
                        <input type="text" name="primary_contact_name" class="form-control"
                               value="<?= htmlspecialchars($customer['primary_contact_name'] ?? '') ?>"
                               placeholder="Họ tên người liên hệ...">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Số điện thoại</label>
                            <input type="tel" name="primary_contact_phone" class="form-control"
                                   value="<?= htmlspecialchars($customer['primary_contact_phone'] ?? '') ?>"
                                   placeholder="0912345678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email liên hệ</label>
                            <input type="email" name="primary_contact_email" class="form-control"
                                   value="<?= htmlspecialchars($customer['primary_contact_email'] ?? '') ?>"
                                   placeholder="contact@company.com">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Thanh toán -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🏦 Thông tin thanh toán</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tên ngân hàng</label>
                            <input type="text" name="bank_name" class="form-control"
                                   value="<?= htmlspecialchars($customer['bank_name'] ?? '') ?>"
                                   placeholder="VD: Vietcombank, BIDV...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Số tài khoản</label>
                            <input type="text" name="bank_account_number" class="form-control"
                                   value="<?= htmlspecialchars($customer['bank_account_number'] ?? '') ?>"
                                   placeholder="0123456789">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Chi nhánh ngân hàng</label>
                            <input type="text" name="bank_branch" class="form-control"
                                   value="<?= htmlspecialchars($customer['bank_branch'] ?? '') ?>"
                                   placeholder="Chi nhánh Hà Nội...">
                        </div>
                    </div>
                    <hr class="my-3">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Payment Terms
                                <small class="text-muted fw-normal">(ngày)</small>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">NET</span>
                                <input type="number" name="payment_terms" class="form-control"
                                       min="0" max="365"
                                       value="<?= (int)($customer['payment_terms'] ?? 30) ?>">
                                <span class="input-group-text">ngày</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Chu kỳ thanh toán</label>
                            <select name="billing_cycle" class="form-select">
                                <?php foreach ([
                                    'monthly' => 'Hàng tháng',
                                    'weekly'  => 'Hàng tuần',
                                    'custom'  => 'Tùy chỉnh',
                                ] as $val => $lbl): ?>
                                <option value="<?= $val ?>"
                                    <?= ($customer['billing_cycle'] ?? 'monthly') === $val ? 'selected' : '' ?>>
                                    <?= $lbl ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Ngày chốt
                                <small class="text-muted fw-normal">(trong tháng)</small>
                            </label>
                            <input type="number" name="billing_day" class="form-control"
                                   min="1" max="31"
                                   value="<?= (int)($customer['billing_day'] ?? 1) ?>"
                                   placeholder="VD: 25">
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Nút lưu -->
    <div class="d-flex gap-2 justify-content-end mt-2 mb-4">
        <a href="detail.php?id=<?= $id ?>&tab=info"
           class="btn btn-outline-secondary">
            <i class="fas fa-times me-1"></i> Hủy
        </a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fas fa-save me-1"></i> Lưu thay đổi
        </button>
    </div>

    </form>

</div>
</div>

<?php include '../../includes/footer.php'; ?>