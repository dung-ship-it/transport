<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('customers', 'crud');

$pageTitle = 'Thêm khách hàng';
$pdo    = getDBConnection();
$errors = [];
$data   = [];

$banks = ['Vietcombank','VietinBank','BIDV','Agribank','Techcombank','MB Bank',
          'ACB','VPBank','TPBank','Sacombank','HDBank','OCB','MSB','SHB','VIB'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    if (!trim($data['company_name'] ?? '')) $errors[] = 'Tên công ty không được trống';

    // Kiểm tra MST trùng
    if (!empty($data['tax_code'])) {
        $check = $pdo->prepare("SELECT id FROM customers WHERE tax_code = ?");
        $check->execute([trim($data['tax_code'])]);
        if ($check->fetch()) $errors[] = 'Mã số thuế đã tồn tại!';
    }

    if (empty($errors)) {
        $pdo->prepare("
            INSERT INTO customers (
                company_name, short_name, tax_code, legal_address, invoice_address,
                legal_representative, representative_title,
                primary_contact_name, primary_contact_phone, primary_contact_email,
                bank_name, bank_account_number, bank_branch,
                payment_terms, billing_cycle, billing_day,
                note, is_active, created_by
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,TRUE,?)
        ")->execute([
            trim($data['company_name']),
            trim($data['short_name']           ?? '') ?: null,
            trim($data['tax_code']             ?? '') ?: null,
            trim($data['legal_address']        ?? '') ?: null,
            trim($data['invoice_address']      ?? '') ?: null,
            trim($data['legal_representative'] ?? '') ?: null,
            trim($data['representative_title'] ?? '') ?: null,
            trim($data['primary_contact_name'] ?? '') ?: null,
            trim($data['primary_contact_phone']?? '') ?: null,
            trim($data['primary_contact_email']?? '') ?: null,
            trim($data['bank_name']            ?? '') ?: null,
            trim($data['bank_account_number']  ?? '') ?: null,
            trim($data['bank_branch']          ?? '') ?: null,
            (int)($data['payment_terms']       ?? 30),
            $data['billing_cycle']             ?? 'monthly',
            trim($data['billing_day']          ?? '') ?: null,
            trim($data['note']                 ?? '') ?: null,
            currentUser()['id'],
        ]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã thêm khách hàng!'];
        header('Location: index.php'); exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:1000px">

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h4 class="fw-bold mb-0">🏢 Thêm khách hàng mới</h4>
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

    <form method="POST">

        <!-- 1. Thông tin cơ bản -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-building me-1 text-primary"></i> 1. Thông tin cơ bản
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Tên công ty <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="company_name" class="form-control"
                               value="<?= htmlspecialchars($data['company_name'] ?? '') ?>"
                               placeholder="VD: Công ty TNHH ABC" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Tên viết tắt</label>
                        <input type="text" name="short_name" class="form-control"
                               value="<?= htmlspecialchars($data['short_name'] ?? '') ?>"
                               placeholder="VD: ABC">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Mã số thuế</label>
                        <input type="text" name="tax_code" class="form-control"
                               value="<?= htmlspecialchars($data['tax_code'] ?? '') ?>"
                               placeholder="0123456789">
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Pháp lý -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-file-contract me-1 text-warning"></i> 2. Thông tin pháp lý
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Địa chỉ pháp lý</label>
                        <textarea name="legal_address" class="form-control" rows="2"
                                  placeholder="Địa chỉ đăng ký kinh doanh"><?= htmlspecialchars($data['legal_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Địa chỉ xuất hoá đơn
                            <small class="text-muted fw-normal">(nếu khác địa chỉ pháp lý)</small>
                        </label>
                        <textarea name="invoice_address" class="form-control" rows="2"
                                  placeholder="Để trống nếu giống địa chỉ pháp lý"><?= htmlspecialchars($data['invoice_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Người đại diện pháp lý</label>
                        <input type="text" name="legal_representative" class="form-control"
                               value="<?= htmlspecialchars($data['legal_representative'] ?? '') ?>"
                               placeholder="Họ và tên">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Chức danh</label>
                        <input type="text" name="representative_title" class="form-control"
                               value="<?= htmlspecialchars($data['representative_title'] ?? '') ?>"
                               placeholder="VD: Giám đốc / Tổng Giám đốc">
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Liên hệ -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-address-book me-1 text-info"></i> 3. Đầu mối liên hệ chính
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Họ và tên</label>
                        <input type="text" name="primary_contact_name" class="form-control"
                               value="<?= htmlspecialchars($data['primary_contact_name'] ?? '') ?>"
                               placeholder="Nguyễn Văn A">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Số điện thoại</label>
                        <input type="text" name="primary_contact_phone" class="form-control"
                               value="<?= htmlspecialchars($data['primary_contact_phone'] ?? '') ?>"
                               placeholder="0901234567">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="primary_contact_email" class="form-control"
                               value="<?= htmlspecialchars($data['primary_contact_email'] ?? '') ?>"
                               placeholder="contact@company.com">
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Thanh toán -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white border-bottom py-2">
                <h6 class="fw-bold mb-0">
                    <i class="fas fa-university me-1 text-success"></i> 4. Thanh toán & Ngân hàng
                </h6>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Ngân hàng</label>
                        <select name="bank_name" class="form-select">
                            <option value="">-- Chọn ngân hàng --</option>
                            <?php foreach ($banks as $b): ?>
                            <option <?= ($data['bank_name']??'')===$b?'selected':'' ?>><?= $b ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Số tài khoản</label>
                        <input type="text" name="bank_account_number" class="form-control"
                               value="<?= htmlspecialchars($data['bank_account_number'] ?? '') ?>"
                               placeholder="Số tài khoản">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Chi nhánh</label>
                        <input type="text" name="bank_branch" class="form-control"
                               value="<?= htmlspecialchars($data['bank_branch'] ?? '') ?>"
                               placeholder="VD: Chi nhánh Hà Nội">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Payment Terms</label>
                        <div class="input-group">
                            <input type="number" name="payment_terms" class="form-control"
                                   value="<?= $data['payment_terms'] ?? 30 ?>"
                                   min="0" max="365">
                            <span class="input-group-text">ngày</span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Chu kỳ thanh toán</label>
                        <select name="billing_cycle" class="form-select"
                                onchange="toggleBillingDay(this.value)">
                            <option value="monthly" <?= ($data['billing_cycle']??'monthly')==='monthly'?'selected':'' ?>>
                                📅 Hàng tháng
                            </option>
                            <option value="weekly"  <?= ($data['billing_cycle']??'')==='weekly' ?'selected':'' ?>>
                                📆 Hàng tuần
                            </option>
                            <option value="custom"  <?= ($data['billing_cycle']??'')==='custom' ?'selected':'' ?>>
                                ⚙️ Tùy chỉnh
                            </option>
                        </select>
                    </div>
                    <div class="col-md-4" id="billingDayField">
                        <label class="form-label fw-semibold" id="billingDayLabel">
                            Ngày chốt hàng tháng
                        </label>
                        <input type="text" name="billing_day" class="form-control"
                               value="<?= htmlspecialchars($data['billing_day'] ?? '') ?>"
                               placeholder="VD: 25 (ngày 25 hàng tháng)">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Ghi chú</label>
                        <input type="text" name="note" class="form-control"
                               value="<?= htmlspecialchars($data['note'] ?? '') ?>"
                               placeholder="Ghi chú thêm...">
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary px-4">
                <i class="fas fa-save me-1"></i> Lưu khách hàng
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Hủy</a>
        </div>

    </form>
</div>
</div>

<script>
function toggleBillingDay(cycle) {
    const label = document.getElementById('billingDayLabel');
    const labels = {
        'monthly': 'Ngày chốt hàng tháng (VD: 25)',
        'weekly':  'Ngày chốt hàng tuần (VD: monday)',
        'custom':  'Mô tả chu kỳ tùy chỉnh',
    };
    label.textContent = labels[cycle] || 'Ngày chốt';
}
toggleBillingDay(document.querySelector('[name=billing_cycle]').value);
</script>

<?php include '../../includes/footer.php'; ?>