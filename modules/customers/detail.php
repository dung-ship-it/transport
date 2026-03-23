<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('customers', 'view');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'info';
if (!$id) { header('Location: index.php'); exit; }

// Load customer
$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();
if (!$customer) { header('Location: index.php'); exit; }

$pageTitle = $customer['company_name'];
$canEdit   = can('customers', 'crud');

// ── Xử lý POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Thêm user vào khách hàng
    if ($action === 'add_user' && $canEdit) {
        $userId    = (int)$_POST['user_id'];
        $role      = $_POST['cu_role'] ?? 'viewer';
        $isPrimary = isset($_POST['is_primary']) ? 'true' : 'false';
        $pdo->prepare("
            INSERT INTO customer_users (customer_id, user_id, role, is_primary)
            VALUES (?, ?, ?, ?::boolean)
            ON CONFLICT (customer_id, user_id) DO UPDATE
            SET role = EXCLUDED.role, is_primary = EXCLUDED.is_primary, is_active = TRUE
        ")->execute([$id, $userId, $role, $isPrimary]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã thêm tài khoản!'];
        header("Location: detail.php?id=$id&tab=users"); exit;
    }

    // Tạo user mới và thêm vào customer luôn
    if ($action === 'create_and_add_user' && $canEdit) {
        $newUsername  = trim(strtolower($_POST['new_username'] ?? ''));
        $newFullName  = trim($_POST['new_full_name'] ?? '');
        $newEmail     = trim($_POST['new_email'] ?? '');
        $newPassword  = $_POST['new_password'] ?? '';
        $newCuRole    = $_POST['new_cu_role'] ?? 'viewer';
        $newIsPrimary = isset($_POST['new_is_primary']) ? 'true' : 'false';

        $errors = [];
        if (!$newUsername) $errors[] = 'Username không được trống';
        if (!$newFullName) $errors[] = 'Họ tên không được trống';
        if (strlen($newPassword) < 6) $errors[] = 'Mật khẩu tối thiểu 6 ký tự';
        if (!preg_match('/^[a-z0-9_]+$/', $newUsername))
            $errors[] = 'Username chỉ dùng chữ thường, số, dấu _';

        $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $check->execute([$newUsername]);
        if ($check->fetchColumn()) $errors[] = "Username '<strong>$newUsername</strong>' đã tồn tại";

        if (empty($errors)) {
            $roleId = $pdo->query("SELECT id FROM roles WHERE name = 'customer'")->fetchColumn();
            $pdo->prepare("
                INSERT INTO users
                    (username, password_hash, full_name, email, role_id, is_active, created_at)
                VALUES (?, ?, ?, ?, ?, TRUE, NOW())
            ")->execute([
                $newUsername,
                password_hash($newPassword, PASSWORD_BCRYPT),
                $newFullName,
                $newEmail ?: null,
                $roleId,
            ]);
            $newUserId = $pdo->lastInsertId();
            $pdo->prepare("
                INSERT INTO customer_users
                    (customer_id, user_id, role, is_primary, is_active)
                VALUES (?, ?, ?, ?::boolean, TRUE)
                ON CONFLICT (customer_id, user_id) DO UPDATE
                SET role = EXCLUDED.role, is_active = TRUE
            ")->execute([$id, $newUserId, $newCuRole, $newIsPrimary]);

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => "✅ Đã tạo tài khoản <strong>$newUsername</strong>"
                        . " — Mật khẩu: <code>$newPassword</code>"
                        . " — Đã thêm vào công ty!"
            ];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ ' . implode('<br>', $errors)];
        }
        header("Location: detail.php?id=$id&tab=users"); exit;
    }

    // Toggle user active
    if ($action === 'toggle_user' && $canEdit) {
        $cuId = (int)$_POST['cu_id'];
        $pdo->prepare("
            UPDATE customer_users SET is_active = NOT is_active WHERE id = ?
        ")->execute([$cuId]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã cập nhật!'];
        header("Location: detail.php?id=$id&tab=users"); exit;
    }

    // ── MỚI: Sửa quyền user ─────────────────────────────────
    if ($action === 'edit_user_role' && $canEdit) {
        $cuId      = (int)$_POST['cu_id'];
        $newRole   = $_POST['cu_role'] ?? 'viewer';
        $isPrimary = isset($_POST['is_primary']) ? true : false;

        // Nếu set primary → bỏ primary của user khác trong cùng KH
        if ($isPrimary) {
            $pdo->prepare("
                UPDATE customer_users SET is_primary = FALSE
                WHERE customer_id = ? AND id != ?
            ")->execute([$id, $cuId]);
        }

        $pdo->prepare("
            UPDATE customer_users
            SET role = ?, is_primary = ?
            WHERE id = ? AND customer_id = ?
        ")->execute([$newRole, $isPrimary ? 'true' : 'false', $cuId, $id]);

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã cập nhật quyền hạn!'];
        header("Location: detail.php?id=$id&tab=users"); exit;
    }

    // ── MỚI: Xóa user khỏi customer ─────────────────────────
    if ($action === 'remove_user' && $canEdit) {
        $cuId = (int)$_POST['cu_id'];
        $pdo->prepare("
            DELETE FROM customer_users WHERE id = ? AND customer_id = ?
        ")->execute([$cuId, $id]);
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'🗑️ Đã xóa tài khoản khỏi công ty!'];
        header("Location: detail.php?id=$id&tab=users"); exit;
    }

    // Tạo price book
    if ($action === 'create_pricebook' && $canEdit) {
        $pdo->prepare("
            UPDATE price_books SET is_active = FALSE
            WHERE customer_id = ?
              AND valid_from <= ?
              AND (valid_to IS NULL OR valid_to >= ?)
        ")->execute([$id, $_POST['valid_from'], $_POST['valid_from']]);

        $pdo->prepare("
            INSERT INTO price_books (customer_id, name, valid_from, valid_to, is_active, note, created_by)
            VALUES (?, ?, ?, ?, TRUE, ?, ?)
        ")->execute([
            $id,
            trim($_POST['pb_name']),
            $_POST['valid_from'],
            $_POST['valid_to'] ?: null,
            trim($_POST['pb_note'] ?? '') ?: null,
            currentUser()['id'],
        ]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã tạo bảng giá!'];
        header("Location: detail.php?id=$id&tab=pricebook"); exit;
    }

    // Lưu price rule
    if ($action === 'save_rule' && $canEdit) {
        $pbId      = (int)$_POST['price_book_id'];
        $vehicleId = (int)$_POST['vehicle_id'];
        $mode      = $_POST['pricing_mode'] ?? 'combo';

        $existRule = $pdo->prepare("SELECT id FROM price_rules WHERE price_book_id=? AND vehicle_id=?");
        $existRule->execute([$pbId, $vehicleId]);
        $existId = $existRule->fetchColumn();

        $params = [
            $mode,
            $_POST['combo_monthly_price']   !== '' ? (float)$_POST['combo_monthly_price']   : null,
            $_POST['combo_km_limit']        !== '' ? (float)$_POST['combo_km_limit']         : null,
            $_POST['over_km_price']         !== '' ? (float)$_POST['over_km_price']          : null,
            $_POST['standard_price_per_km'] !== '' ? (float)$_POST['standard_price_per_km']  : null,
            isset($_POST['toll_included']) ? 'true' : 'false',
            (float)($_POST['holiday_surcharge']    ?? 0),
            (float)($_POST['sunday_surcharge']     ?? 0),
            (float)($_POST['waiting_fee_per_hour'] ?? 0),
            trim($_POST['rule_note'] ?? '') ?: null,
        ];

        if ($existId) {
            $pdo->prepare("
                UPDATE price_rules SET
                    pricing_mode = ?, combo_monthly_price = ?, combo_km_limit = ?,
                    over_km_price = ?, standard_price_per_km = ?,
                    toll_included = ?::boolean, holiday_surcharge = ?,
                    sunday_surcharge = ?, waiting_fee_per_hour = ?, note = ?
                WHERE id = ?
            ")->execute([...$params, $existId]);
        } else {
            $pdo->prepare("
                INSERT INTO price_rules
                    (price_book_id, vehicle_id, pricing_mode, combo_monthly_price,
                     combo_km_limit, over_km_price, standard_price_per_km,
                     toll_included, holiday_surcharge, sunday_surcharge,
                     waiting_fee_per_hour, note)
                VALUES (?,?,?,?,?,?,?,?::boolean,?,?,?,?)
            ")->execute([$pbId, $vehicleId, ...$params]);
        }
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã lưu bảng giá!'];
        header("Location: detail.php?id=$id&tab=pricebook&pb=$pbId"); exit;
    }
}

// ── Load data cho từng tab ───────────────────────────────────
$customerUsers = $pdo->prepare("
    SELECT cu.*, u.username, u.full_name, u.email, u.phone, u.is_active AS user_active
    FROM customer_users cu
    JOIN users u ON cu.user_id = u.id
    WHERE cu.customer_id = ?
    ORDER BY cu.is_primary DESC, u.full_name
");
$customerUsers->execute([$id]);
$customerUsers = $customerUsers->fetchAll();

$priceBooks = $pdo->prepare("
    SELECT pb.*,
           u.full_name AS created_by_name,
           COUNT(pr.id) AS rule_count
    FROM price_books pb
    LEFT JOIN users u  ON pb.created_by = u.id
    LEFT JOIN price_rules pr ON pr.price_book_id = pb.id
    WHERE pb.customer_id = ?
    GROUP BY pb.id, u.full_name
    ORDER BY pb.valid_from DESC
");
$priceBooks->execute([$id]);
$priceBooks = $priceBooks->fetchAll();

$selectedPbId = (int)($_GET['pb'] ?? ($priceBooks[0]['id'] ?? 0));
$priceRules   = [];
if ($selectedPbId) {
    $stmt = $pdo->prepare("
        SELECT pr.*, v.plate_number, vt.name AS vehicle_type
        FROM price_rules pr
        JOIN vehicles v       ON pr.vehicle_id = v.id
        JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
        WHERE pr.price_book_id = ?
        ORDER BY v.plate_number
    ");
    $stmt->execute([$selectedPbId]);
    $priceRules = $stmt->fetchAll();
}

// Load available users — 1 user = 1 công ty
$availableUsers = $pdo->prepare("
    SELECT u.id, u.full_name, u.username
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE r.name = 'customer'
      AND u.is_active = TRUE
      AND u.id NOT IN (
          SELECT cu2.user_id FROM customer_users cu2
          WHERE cu2.is_active = TRUE AND cu2.customer_id != ?
      )
      AND u.id NOT IN (
          SELECT cu3.user_id FROM customer_users cu3
          WHERE cu3.is_active = TRUE AND cu3.customer_id = ?
      )
    ORDER BY u.full_name
");
$availableUsers->execute([$id, $id]);
$availableUsers = $availableUsers->fetchAll();

$availableVehicles = $pdo->prepare("
    SELECT v.id, v.plate_number, vt.name AS type_name
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.is_active = TRUE
    ORDER BY v.plate_number
");
$availableVehicles->execute();
$availableVehicles = $availableVehicles->fetchAll();

$trips = $pdo->prepare("
    SELECT t.*, v.plate_number, d_u.full_name AS driver_name
    FROM trips t
    JOIN vehicles v ON t.vehicle_id = v.id
    JOIN drivers d  ON t.driver_id  = d.id
    JOIN users d_u  ON d.user_id    = d_u.id
    WHERE t.customer_id = ?
    ORDER BY t.trip_date DESC LIMIT 30
");
$trips->execute([$id]);
$trips = $trips->fetchAll();

$randomPass = substr(str_shuffle('abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 10);

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h4 class="fw-bold mb-0">
                    🏢 <?= htmlspecialchars($customer['company_name']) ?>
                    <?php if ($customer['is_active']): ?>
                    <span class="badge bg-success fs-6 ms-1">✅ Active</span>
                    <?php else: ?>
                    <span class="badge bg-danger fs-6 ms-1">⏸ Ngừng</span>
                    <?php endif; ?>
                </h4>
                <div class="text-muted small">
                    <span class="badge bg-secondary me-1"><?= $customer['customer_code'] ?></span>
                    <?= htmlspecialchars($customer['tax_code'] ?? '') ?>
                </div>
            </div>
        </div>
        <?php if ($canEdit): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-warning btn-sm">
            <i class="fas fa-edit me-1"></i> Sửa thông tin
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
        <?php foreach ([
            ['info',       'fas fa-building',           'Thông tin công ty'],
            ['users',      'fas fa-users',               'Tài khoản (' . count($customerUsers) . ')'],
            ['pricebook',  'fas fa-tags',                'Bảng giá (' . count($priceBooks) . ')'],
            ['trips',      'fas fa-route',               'Lịch sử chuyến'],
            ['statements', 'fas fa-file-invoice-dollar', 'Bảng kê / Công nợ'],
        ] as [$key, $icon, $label]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab===$key?'active':'' ?>"
               href="?id=<?= $id ?>&tab=<?= $key ?>">
                <i class="<?= $icon ?> me-1"></i><?= $label ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>

    <!-- ══ TAB 1: THÔNG TIN CÔNG TY ══════════════════════════ -->
    <?php if ($tab === 'info'): ?>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📋 Thông tin cơ bản & Pháp lý</h6>
                </div>
                <div class="card-body">
                    <?php $rows = [
                        ['Mã khách hàng',   $customer['customer_code']],
                        ['Tên công ty',      $customer['company_name']],
                        ['Tên viết tắt',     $customer['short_name'] ?? '—'],
                        ['Mã số thuế',       $customer['tax_code'] ?? '—'],
                        ['Địa chỉ pháp lý', $customer['legal_address'] ?? '—'],
                        ['Địa chỉ HĐ',      $customer['invoice_address'] ?? '—'],
                        ['Người đại diện',   $customer['legal_representative'] ?? '—'],
                        ['Chức danh',        $customer['representative_title'] ?? '—'],
                    ];
                    foreach ($rows as [$label, $value]): ?>
                    <div class="d-flex py-2 border-bottom">
                        <div class="text-muted small" style="width:150px;flex-shrink:0"><?= $label ?></div>
                        <div class="small fw-semibold"><?= htmlspecialchars($value) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📞 Liên hệ</h6>
                </div>
                <div class="card-body">
                    <?php $rows = [
                        ['Đầu mối', $customer['primary_contact_name']  ?? '—'],
                        ['SĐT',     $customer['primary_contact_phone'] ?? '—'],
                        ['Email',   $customer['primary_contact_email'] ?? '—'],
                    ];
                    foreach ($rows as [$label, $value]): ?>
                    <div class="d-flex py-2 border-bottom">
                        <div class="text-muted small" style="width:100px;flex-shrink:0"><?= $label ?></div>
                        <div class="small fw-semibold"><?= htmlspecialchars($value) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">🏦 Thanh toán</h6>
                </div>
                <div class="card-body">
                    <?php
                    $cycleMap = ['monthly'=>'Hàng tháng','weekly'=>'Hàng tuần','custom'=>'Tùy chỉnh'];
                    $rows = [
                        ['Ngân hàng',    $customer['bank_name'] ?? '—'],
                        ['Số TK',        $customer['bank_account_number'] ?? '—'],
                        ['Chi nhánh',    $customer['bank_branch'] ?? '—'],
                        ['Payment Terms','NET ' . ($customer['payment_terms'] ?? 30) . ' ngày'],
                        ['Chu kỳ TT',   $cycleMap[$customer['billing_cycle']] ?? '—'],
                        ['Ngày chốt',    $customer['billing_day'] ?? '—'],
                    ];
                    foreach ($rows as [$label, $value]): ?>
                    <div class="d-flex py-2 border-bottom">
                        <div class="text-muted small" style="width:130px;flex-shrink:0"><?= $label ?></div>
                        <div class="small fw-semibold"><?= htmlspecialchars($value) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: TÀI KHOẢN ══════════════════════════════════ -->
    <?php elseif ($tab === 'users'): ?>
    <div class="row g-3">

        <!-- Danh sách tài khoản -->
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">👥 Danh sách tài khoản</h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th class="ps-3">Họ tên</th>
                                <th>Username</th>
                                <th>Quyền</th>
                                <th>Đầu mối</th>
                                <th>Trạng thái</th>
                                <?php if ($canEdit): ?>
                                <th class="text-center">Thao tác</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($customerUsers)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                Chưa có tài khoản
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($customerUsers as $cu):
                            $roleMap = [
                                'viewer'         => ['secondary', '👁 Xem'],
                                'approver'       => ['warning',   '✅ Duyệt'],
                                'admin_customer' => ['danger',    '👑 Admin'],
                            ];
                            [$rc, $rl] = $roleMap[$cu['role']] ?? ['secondary', $cu['role']];
                        ?>
                        <tr class="<?= !$cu['is_active'] ? 'opacity-50' : '' ?>">
                            <td class="ps-3 fw-semibold">
                                <?= htmlspecialchars($cu['full_name']) ?>
                            </td>
                            <td><code><?= htmlspecialchars($cu['username']) ?></code></td>
                            <td><span class="badge bg-<?= $rc ?>"><?= $rl ?></span></td>
                            <td>
                                <?= $cu['is_primary']
                                    ? '<span class="badge bg-primary">⭐ Chính</span>'
                                    : '<span class="text-muted small">—</span>' ?>
                            </td>
                            <td>
                                <?= $cu['is_active']
                                    ? '<span class="badge bg-success">✅</span>'
                                    : '<span class="badge bg-danger">🔒</span>' ?>
                            </td>
                            <?php if ($canEdit): ?>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">

                                    <!-- Nút Sửa quyền — MỚI -->
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Sửa quyền"
                                            onclick="openEditRole(
                                                <?= $cu['id'] ?>,
                                                '<?= htmlspecialchars(addslashes($cu['full_name'])) ?>',
                                                '<?= $cu['role'] ?>',
                                                <?= $cu['is_primary'] ? 'true' : 'false' ?>
                                            )">
                                        <i class="fas fa-user-edit"></i>
                                    </button>

                                    <!-- Nút Lock/Unlock — giữ nguyên -->
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_user">
                                        <input type="hidden" name="cu_id"  value="<?= $cu['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-<?= $cu['is_active']?'warning':'success' ?>"
                                                title="<?= $cu['is_active']?'Khóa tài khoản':'Mở khóa' ?>"
                                                onclick="return confirm('Xác nhận?')">
                                            <i class="fas fa-<?= $cu['is_active']?'lock':'lock-open' ?>"></i>
                                        </button>
                                    </form>

                                    <!-- Nút Xóa — MỚI -->
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="remove_user">
                                        <input type="hidden" name="cu_id"  value="<?= $cu['id'] ?>">
                                        <button type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Xóa khỏi công ty"
                                                onclick="return confirm('Xóa tài khoản <?= htmlspecialchars(addslashes($cu['full_name'])) ?> khỏi công ty này?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>

                                </div>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($canEdit): ?>
        <div class="col-md-5">

            <!-- Form 1: Thêm user đã có -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">➕ Thêm tài khoản có sẵn</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Chọn tài khoản</label>
                            <select name="user_id" class="form-select" required>
                                <option value="">-- Chọn user role Customer --</option>
                                <?php foreach ($availableUsers as $u): ?>
                                <option value="<?= $u['id'] ?>">
                                    <?= htmlspecialchars($u['full_name']) ?>
                                    (<?= htmlspecialchars($u['username']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                <?php if (empty($availableUsers)): ?>
                                <span class="text-warning">⚠️ Không có user khả dụng.</span>
                                <?php else: ?>
                                <?= count($availableUsers) ?> user chưa thuộc công ty nào.
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Quyền hạn</label>
                            <select name="cu_role" class="form-select">
                                <option value="viewer">👁 Viewer — chỉ xem chuyến</option>
                                <option value="approver">✅ Approver — xem + confirm/reject</option>
                                <option value="admin_customer">👑 Admin — quản lý users công ty</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_primary"
                                   class="form-check-input" id="isPrimary">
                            <label class="form-check-label" for="isPrimary">
                                ⭐ Đầu mối chính (nhận thông báo)
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"
                                <?= empty($availableUsers) ? 'disabled' : '' ?>>
                            <i class="fas fa-plus me-1"></i> Thêm tài khoản
                        </button>
                    </form>
                </div>
            </div>

            <!-- Form 2: Tạo user mới và thêm luôn -->
            <div class="card border-0 shadow-sm border-start border-success border-3">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0 text-success">
                        <i class="fas fa-user-plus me-1"></i> Tạo tài khoản mới
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_and_add_user">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">
                                Họ tên <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="new_full_name"
                                   class="form-control form-control-sm"
                                   placeholder="Nguyễn Văn A" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">
                                Username <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="new_username"
                                   class="form-control form-control-sm"
                                   placeholder="nguyenvana" required
                                   pattern="[a-z0-9_]+"
                                   title="Chỉ dùng chữ thường, số, dấu _">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="new_email"
                                   class="form-control form-control-sm"
                                   placeholder="email@company.com">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">
                                Mật khẩu <span class="text-danger">*</span>
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="text" name="new_password" id="newPassword"
                                       class="form-control" required
                                       value="<?= htmlspecialchars($randomPass) ?>">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="genPass()" title="Tạo mật khẩu ngẫu nhiên">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </div>
                            <small class="text-muted">
                                Ghi lại mật khẩu này để gửi cho khách hàng
                            </small>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Quyền hạn</label>
                            <select name="new_cu_role" class="form-select form-select-sm">
                                <option value="viewer">👁 Viewer — chỉ xem chuyến</option>
                                <option value="approver">✅ Approver — xem + confirm/reject</option>
                                <option value="admin_customer">👑 Admin — quản lý users công ty</option>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="new_is_primary"
                                   class="form-check-input" id="newIsPrimary">
                            <label class="form-check-label small" for="newIsPrimary">
                                ⭐ Đầu mối chính (nhận thông báo)
                            </label>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-user-plus me-1"></i> Tạo & Thêm vào công ty
                        </button>
                    </form>
                </div>
            </div>

        </div>
        <?php endif; ?>
    </div>

    <!-- ══ TAB 3: BẢNG GIÁ ════════════════════════════════════ -->
    <?php elseif ($tab === 'pricebook'): ?>
    <div class="row g-3">

        <div class="col-md-3">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📚 Các bảng giá</h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (empty($priceBooks)): ?>
                    <div class="list-group-item text-muted small text-center py-3">
                        Chưa có bảng giá
                    </div>
                    <?php endif; ?>
                    <?php foreach ($priceBooks as $pb): ?>
                    <a href="?id=<?= $id ?>&tab=pricebook&pb=<?= $pb['id'] ?>"
                       class="list-group-item list-group-item-action
                              <?= $selectedPbId==$pb['id']?'active':'' ?>">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold small">
                                <?= htmlspecialchars($pb['name']) ?>
                            </span>
                            <?php if ($pb['is_active']): ?>
                            <span class="badge bg-success">✅</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">—</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted" style="font-size:0.72rem">
                            <?= date('d/m/Y', strtotime($pb['valid_from'])) ?>
                            — <?= $pb['valid_to']
                                  ? date('d/m/Y', strtotime($pb['valid_to']))
                                  : '∞' ?>
                        </div>
                        <div class="text-muted" style="font-size:0.72rem">
                            <?= $pb['rule_count'] ?> dòng giá
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">➕ Tạo bảng giá mới</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create_pricebook">
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Tên bảng giá</label>
                            <input type="text" name="pb_name"
                                   class="form-control form-control-sm"
                                   placeholder="VD: Bảng giá Q2/2026" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">Hiệu lực từ</label>
                            <input type="date" name="valid_from"
                                   class="form-control form-control-sm" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-semibold">
                                Đến ngày
                                <span class="text-muted fw-normal">(trống = vô thời hạn)</span>
                            </label>
                            <input type="date" name="valid_to"
                                   class="form-control form-control-sm">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Ghi chú</label>
                            <input type="text" name="pb_note"
                                   class="form-control form-control-sm"
                                   placeholder="Ghi chú...">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-plus me-1"></i> Tạo bảng giá
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-md-9">
            <?php if ($selectedPbId): ?>
            <?php
            $selectedPb = null;
            foreach ($priceBooks as $pb) {
                if ($pb['id'] === $selectedPbId) { $selectedPb = $pb; break; }
            }
            ?>
            <?php if ($selectedPb): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0">
                            📋 <?= htmlspecialchars($selectedPb['name']) ?>
                            <?php if ($selectedPb['is_active']): ?>
                            <span class="badge bg-success ms-1">✅ Đang áp dụng</span>
                            <?php endif; ?>
                        </h6>
                        <span class="text-muted small">
                            <?= date('d/m/Y', strtotime($selectedPb['valid_from'])) ?>
                            — <?= $selectedPb['valid_to']
                                  ? date('d/m/Y', strtotime($selectedPb['valid_to']))
                                  : '∞ Vô thời hạn' ?>
                        </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover align-middle mb-0">
                        <thead style="background:#f8f9fa">
                            <tr>
                                <th class="ps-3">Biển số xe</th>
                                <th>Tải trọng</th>
                                <th>COMBO / THƯỜNG</th>
                                <th>Đơn giá COMBO</th>
                                <th>KM COMBO/tháng</th>
                                <th>Quá KM</th>
                                <th>Đơn giá THƯỜNG</th>
                                <th>Phụ phí</th>
                                <?php if ($canEdit): ?>
                                <th class="text-center">Thao tác</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($priceRules)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <i class="fas fa-tags fa-2x mb-2 d-block opacity-25"></i>
                                Chưa có dòng giá — thêm xe bên dưới
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($priceRules as $rule): ?>
                        <tr>
                            <td class="ps-3 fw-bold text-primary">
                                <?= htmlspecialchars($rule['plate_number']) ?>
                            </td>
                            <td class="text-muted small"><?= $rule['vehicle_type'] ?></td>
                            <td>
                                <?php if ($rule['pricing_mode'] === 'combo'): ?>
                                <span class="badge bg-primary">✅ COMBO</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">THƯỜNG</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $rule['combo_monthly_price']
                                    ? '<span class="fw-bold text-success">'
                                      . number_format($rule['combo_monthly_price'],0,'.', ',')
                                      . ' ₫</span>'
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?= $rule['combo_km_limit']
                                    ? number_format($rule['combo_km_limit'],0) . ' km'
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?= $rule['over_km_price']
                                    ? number_format($rule['over_km_price'],0,'.', ',') . ' ₫/km'
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td>
                                <?= $rule['standard_price_per_km']
                                    ? number_format($rule['standard_price_per_km'],0,'.', ',') . ' ₫/km'
                                    : '<span class="text-muted">—</span>' ?>
                            </td>
                            <td class="small">
                                <?= $rule['toll_included']
                                    ? '🚫 Không tính cầu'
                                    : '✅ Tính cầu đường' ?>
                                <?= $rule['holiday_surcharge'] > 0
                                    ? '<br>🎉 Lễ: +' . $rule['holiday_surcharge'] . '%' : '' ?>
                                <?= $rule['sunday_surcharge'] > 0
                                    ? '<br>☀️ CN: +' . $rule['sunday_surcharge'] . '%'  : '' ?>
                            </td>
                            <?php if ($canEdit): ?>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        onclick="editRule(<?= htmlspecialchars(json_encode($rule)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($priceRules)): ?>
                <div class="card-footer bg-light py-2">
                    <div class="row g-2 small text-muted">
                        <div class="col-auto"><strong>Phụ phí chung:</strong></div>
                        <div class="col-auto">
                            Cầu đường:
                            <span class="<?= $priceRules[0]['toll_included']
                                            ? 'text-danger' : 'text-success' ?>">
                                <?= $priceRules[0]['toll_included'] ? 'Không tính' : 'Có tính' ?>
                            </span>
                        </div>
                        <div class="col-auto">
                            Phụ phí Lễ Tết:
                            <strong><?= $priceRules[0]['holiday_surcharge'] ?>%</strong>
                            — Tự chọn các ngày nghỉ lễ
                        </div>
                        <div class="col-auto">
                            Phụ phí Chủ nhật:
                            <strong><?= $priceRules[0]['sunday_surcharge'] ?>%</strong>
                            — Tự động lấy ngày chủ nhật
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($canEdit): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0" id="ruleFormTitle">➕ Thêm xe vào bảng giá</h6>
                </div>
                <div class="card-body">
                    <form method="POST" id="ruleForm">
                        <input type="hidden" name="action"        value="save_rule">
                        <input type="hidden" name="price_book_id" value="<?= $selectedPbId ?>">
                        <input type="hidden" name="rule_id"       id="ruleId" value="0">

                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">
                                    Biển số xe <span class="text-danger">*</span>
                                </label>
                                <select name="vehicle_id" id="vehicleSelect"
                                        class="form-select" required>
                                    <option value="">-- Chọn xe --</option>
                                    <?php foreach ($availableVehicles as $v): ?>
                                    <option value="<?= $v['id'] ?>">
                                        <?= htmlspecialchars($v['plate_number']) ?>
                                        (<?= $v['type_name'] ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Loại giá</label>
                                <select name="pricing_mode" id="pricingMode" class="form-select"
                                        onchange="togglePricingMode(this.value)">
                                    <option value="combo">✅ COMBO (tháng)</option>
                                    <option value="standard">📏 THƯỜNG (theo km)</option>
                                </select>
                            </div>
                            <div class="col-md-2" id="comboPrice">
                                <label class="form-label fw-semibold">Đơn giá COMBO</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="combo_monthly_price"
                                           class="form-control" step="100000" min="0"
                                           placeholder="40000000">
                                    <span class="input-group-text">₫</span>
                                </div>
                            </div>
                            <div class="col-md-2" id="comboKm">
                                <label class="form-label fw-semibold">KM COMBO/tháng</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="combo_km_limit"
                                           class="form-control" step="100" min="0"
                                           placeholder="3000">
                                    <span class="input-group-text">km</span>
                                </div>
                            </div>
                            <div class="col-md-2" id="overKmPrice">
                                <label class="form-label fw-semibold">Giá quá KM</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="over_km_price"
                                           class="form-control" step="500" min="0"
                                           placeholder="8000">
                                    <span class="input-group-text">₫/km</span>
                                </div>
                            </div>
                            <div class="col-md-3" id="standardPrice" style="display:none">
                                <label class="form-label fw-semibold">Đơn giá theo KM</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="standard_price_per_km"
                                           class="form-control" step="500" min="0"
                                           placeholder="15000">
                                    <span class="input-group-text">₫/km</span>
                                </div>
                            </div>

                            <div class="col-12">
                                <hr class="my-1">
                                <p class="fw-semibold small mb-2">Phụ phí chung:</p>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox"
                                           name="toll_included" id="tollIncluded">
                                    <label class="form-check-label small" for="tollIncluded">
                                        Đã bao gồm cầu đường
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">
                                    Phụ phí Lễ Tết (%)
                                </label>
                                <input type="number" name="holiday_surcharge"
                                       class="form-control form-control-sm"
                                       step="1" min="0" max="200" value="0">
                                <small class="text-muted">Tự chọn ngày nghỉ lễ</small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">
                                    Phụ phí Chủ nhật (%)
                                </label>
                                <input type="number" name="sunday_surcharge"
                                       class="form-control form-control-sm"
                                       step="1" min="0" max="200" value="0">
                                <small class="text-muted">Tự động lấy ngày CN</small>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold">Phí chờ/giờ</label>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="waiting_fee_per_hour"
                                           class="form-control" step="10000" min="0" value="0">
                                    <span class="input-group-text">₫/h</span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold">Ghi chú</label>
                                <input type="text" name="rule_note"
                                       class="form-control form-control-sm"
                                       placeholder="Ghi chú...">
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> Lưu dòng giá
                            </button>
                            <button type="button" class="btn btn-outline-secondary"
                                    onclick="resetRuleForm()">Reset</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php else: ?>
            <div class="card border-0 shadow-sm p-5 text-center text-muted">
                <i class="fas fa-tags fa-3x mb-3 opacity-25"></i>
                <p>Chọn bảng giá ở bên trái để xem chi tiết</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ TAB 4: LỊCH SỬ CHUYẾN XE ══════════════════════════ -->
    <?php elseif ($tab === 'trips'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🚛 30 chuyến xe gần nhất</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">Ngày</th>
                            <th>Mã chuyến</th>
                            <th>Xe</th>
                            <th>Lái xe</th>
                            <th>Tuyến</th>
                            <th>KM</th>
                            <th>Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4 text-muted">
                            Chưa có chuyến xe
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($trips as $t): ?>
                    <tr>
                        <td class="ps-3 small">
                            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                        </td>
                        <td><code><?= $t['trip_code'] ?></code></td>
                        <td class="fw-bold"><?= $t['plate_number'] ?></td>
                        <td class="small"><?= htmlspecialchars($t['driver_name']) ?></td>
                        <td class="small">
                            <?= htmlspecialchars($t['route_from'] ?? '') ?>
                            <?= $t['route_to'] ? ' → ' . htmlspecialchars($t['route_to']) : '' ?>
                        </td>
                        <td>
                            <?= $t['distance_km']
                                ? number_format($t['distance_km'],0).' km'
                                : '—' ?>
                        </td>
                        <td>
                            <?php
                            $sLabels = [
                                'scheduled'   => ['warning', '📅 Chờ'],
                                'in_progress' => ['primary', '🚛 Đang chạy'],
                                'completed'   => ['success', '✅ Hoàn thành'],
                                'confirmed'   => ['info',    '👍 Đã confirm'],
                                'cancelled'   => ['danger',  '❌ Hủy'],
                            ];
                            [$sc, $sl] = $sLabels[$t['status']] ?? ['secondary', $t['status']];
                            ?>
                            <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ══ TAB 5: BẢNG KÊ / CÔNG NỢ ══════════════════════════ -->
    <?php elseif ($tab === 'statements'): ?>
    <div class="card border-0 shadow-sm p-4 text-center">
        <i class="fas fa-file-invoice-dollar fa-3x mb-3 text-muted opacity-25"></i>
        <h5 class="text-muted">Bảng kê / Công nợ</h5>
        <p class="text-muted">Xem tất cả bảng kê của khách hàng này</p>
        <a href="/modules/statements/index.php?customer_id=<?= $id ?>"
           class="btn btn-primary">
            <i class="fas fa-external-link-alt me-1"></i> Xem bảng kê
        </a>
    </div>
    <?php endif; ?>

</div>
</div>

<!-- ══ MODAL: Sửa quyền tài khoản ═══════════════════════════ -->
<div class="modal fade" id="modalEditRole" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-bold">✏️ Sửa quyền tài khoản</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user_role">
                <input type="hidden" name="cu_id"  id="editCuId">
                <div class="modal-body">
                    <p class="fw-semibold text-primary mb-3" id="editUserName"></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quyền hạn</label>
                        <select name="cu_role" id="editCuRole" class="form-select">
                            <option value="viewer">👁 Viewer — chỉ xem chuyến</option>
                            <option value="approver">✅ Approver — xem + confirm/reject</option>
                            <option value="admin_customer">👑 Admin — quản lý users công ty</option>
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_primary"
                               class="form-check-input" id="editIsPrimary">
                        <label class="form-check-label" for="editIsPrimary">
                            ⭐ Đầu mối chính (nhận thông báo)
                        </label>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm"
                            data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-save me-1"></i> Lưu thay đổi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle COMBO / Standard fields
function togglePricingMode(mode) {
    const isCombo = mode === 'combo';
    ['comboPrice','comboKm','overKmPrice'].forEach(id => {
        document.getElementById(id).style.display = isCombo ? 'block' : 'none';
    });
    document.getElementById('standardPrice').style.display = isCombo ? 'none' : 'block';
}

// Edit rule — fill form
function editRule(rule) {
    document.getElementById('ruleFormTitle').textContent =
        '✏️ Sửa dòng giá: ' + rule.plate_number;
    document.getElementById('ruleId').value = rule.id;
    document.querySelector('[name=vehicle_id]').value            = rule.vehicle_id;
    document.querySelector('[name=pricing_mode]').value          = rule.pricing_mode;
    document.querySelector('[name=combo_monthly_price]').value   = rule.combo_monthly_price || '';
    document.querySelector('[name=combo_km_limit]').value        = rule.combo_km_limit || '';
    document.querySelector('[name=over_km_price]').value         = rule.over_km_price || '';
    document.querySelector('[name=standard_price_per_km]').value = rule.standard_price_per_km || '';
    document.querySelector('[name=toll_included]').checked       = rule.toll_included;
    document.querySelector('[name=holiday_surcharge]').value     = rule.holiday_surcharge || 0;
    document.querySelector('[name=sunday_surcharge]').value      = rule.sunday_surcharge  || 0;
    document.querySelector('[name=waiting_fee_per_hour]').value  = rule.waiting_fee_per_hour || 0;
    document.querySelector('[name=rule_note]').value             = rule.note || '';
    togglePricingMode(rule.pricing_mode);
    document.getElementById('ruleForm').scrollIntoView({ behavior: 'smooth' });
}

function resetRuleForm() {
    document.getElementById('ruleForm').reset();
    document.getElementById('ruleId').value = 0;
    document.getElementById('ruleFormTitle').textContent = '➕ Thêm xe vào bảng giá';
    togglePricingMode('combo');
}

// Tạo mật khẩu ngẫu nhiên
function genPass() {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789@#!';
    let pass = '';
    for (let i = 0; i < 10; i++) {
        pass += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('newPassword').value = pass;
}

// Mở modal sửa quyền — MỚI
function openEditRole(cuId, fullName, role, isPrimary) {
    document.getElementById('editCuId').value       = cuId;
    document.getElementById('editUserName').textContent = '👤 ' + fullName;
    document.getElementById('editCuRole').value     = role;
    document.getElementById('editIsPrimary').checked = isPrimary;
    new bootstrap.Modal(document.getElementById('modalEditRole')).show();
}

// Khởi tạo
togglePricingMode(document.getElementById('pricingMode')?.value || 'combo');
</script>

<?php include '../../includes/footer.php'; ?>