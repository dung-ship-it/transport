<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pageTitle = 'Danh sách nhân viên';
$pdo       = getDBConnection();

// ── Tham số lọc ──────────────────────────────────────────────
$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterDept   = $_GET['dept']   ?? '';
$search       = trim($_GET['q'] ?? '');

// ── Build WHERE — loại bỏ customer ───────────────────────────
$where  = ["r.name NOT IN ('customer')"];
$params = [];

if ($filterRole !== '') {
    $where[]  = 'r.name = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $where[]  = 'u.is_active = ?';
    $params[] = ($filterStatus === '1');
}
if ($filterDept !== '') {
    $where[]  = 'sc.department = ?';
    $params[] = $filterDept;
}
if ($search !== '') {
    $where[]  = "(u.full_name ILIKE ? OR u.username ILIKE ? OR u.email ILIKE ?
                  OR u.phone ILIKE ? OR u.employee_code ILIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s, $s);
}

$whereStr = implode(' AND ', $where);

// ── Query chính ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        u.id, u.username, u.full_name, u.email, u.phone,
        u.is_active, u.role_id, u.avatar, u.created_at,
        u.employee_code, u.hire_date, u.gender, u.date_of_birth,
        r.name  AS role,
        r.label AS role_label,
        r.id    AS role_sort,
        COALESCE(sc.department, '—') AS department,
        COALESCE(sc.position,   '—') AS position,
        COALESCE(sc.base_salary + sc.allowance, 0) AS monthly_salary
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN hr_salary_configs sc
           ON sc.user_id = u.id AND sc.is_active = TRUE
    WHERE $whereStr
    ORDER BY r.id, u.full_name
");
$stmt->execute($params);
$employees = $stmt->fetchAll();

// ── Roles để lọc (bỏ customer) ───────────────────────────────
$roles = $pdo->query("
    SELECT * FROM roles
    WHERE name NOT IN ('customer')
    ORDER BY id
")->fetchAll();

// ── Danh sách phòng ban ───────────────────────────────────────
$depts = $pdo->query("
    SELECT DISTINCT department
    FROM hr_salary_configs
    WHERE department IS NOT NULL AND department <> ''
    ORDER BY department
")->fetchAll(PDO::FETCH_COLUMN);

// ── KPI ───────────────────────────────────────────────────────
$kpiTotal    = count(array_filter($employees, fn($e) => $e['is_active']));
$kpiInactive = count(array_filter($employees, fn($e) => !$e['is_active']));
$kpiDrivers  = count(array_filter($employees, fn($e) => $e['role'] === 'driver' && $e['is_active']));
$kpiNew      = count(array_filter($employees, fn($e) =>
    $e['created_at'] &&
    date('Y-m', strtotime($e['created_at'])) === date('Y-m')
));

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- ── Tiêu đề ──────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1 fw-bold">👤 Danh sách nhân viên</h4>
        <p class="text-muted mb-0">
            Tổng: <strong><?= count($employees) ?></strong> nhân viên
            (không bao gồm khách hàng)
        </p>
    </div>
    <?php if (can('hr', 'manage')): ?>
    <a href="form.php" class="btn btn-primary btn-sm">
        <i class="fas fa-user-plus me-1"></i>Thêm nhân viên
    </a>
    <?php endif; ?>
</div>

<?php showFlash(); ?>

<!-- ── KPI Cards ─────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['val'=>$kpiTotal,    'lbl'=>'Đang làm việc', 'icon'=>'fas fa-users',     'color'=>'primary'],
        ['val'=>$kpiDrivers,  'lbl'=>'Lái xe',         'icon'=>'fas fa-truck',     'color'=>'success'],
        ['val'=>$kpiNew,      'lbl'=>'Mới tháng này',  'icon'=>'fas fa-user-plus', 'color'=>'info'],
        ['val'=>$kpiInactive, 'lbl'=>'Đã nghỉ việc',   'icon'=>'fas fa-user-slash','color'=>'secondary'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center
                            bg-<?= $k['color'] ?> bg-opacity-10 flex-shrink-0"
                     style="width:42px;height:42px">
                    <i class="<?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
                    <div class="text-muted" style="font-size:.75rem"><?= $k['lbl'] ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Bộ lọc ──────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">

        <div class="col-md-3">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="🔍 Tìm tên, mã NV, email, SĐT..."
                   value="<?= htmlspecialchars($search) ?>">
        </div>

        <div class="col-md-2">
            <select name="role" class="form-select form-select-sm">
                <option value="">-- Tất cả role --</option>
                <?php foreach ($roles as $r): ?>
                <option value="<?= $r['name'] ?>"
                        <?= $filterRole === $r['name'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <select name="dept" class="form-select form-select-sm">
                <option value="">-- Phòng ban --</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= htmlspecialchars($d) ?>"
                        <?= $filterDept === $d ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">-- Trạng thái --</option>
                <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Đang làm việc</option>
                <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Đã nghỉ việc</option>
            </select>
        </div>

        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="fas fa-search me-1"></i>Lọc
            </button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
        </div>

    </form>
    </div>
</div>

<!-- ── Bảng nhóm theo Role ───────────────────────────────────── -->
<?php
// Nhóm theo role_label (giống file gốc)
$grouped = [];
foreach ($employees as $e) {
    $grouped[$e['role_label']][] = $e;
}

if (empty($grouped)): ?>
<div class="card border-0 shadow-sm p-5 text-center text-muted">
    <i class="fas fa-users fa-3x opacity-25 d-block mb-2"></i>
    Không tìm thấy nhân viên nào
</div>
<?php endif; ?>

<?php foreach ($grouped as $roleLabel => $groupEmployees):
    $firstEmp  = $groupEmployees[0];
    $badge     = getRoleBadge($firstEmp['role']);
    $roleColor = $badge['class'] ?? 'secondary';
?>
<div class="card border-0 shadow-sm mb-3">

    <!-- Group header -->
    <div class="card-header bg-white border-0 pt-3 pb-2">
        <h6 class="fw-bold mb-0">
            <span class="badge bg-<?= $roleColor ?> me-2">
                <?= $badge['icon'] ?> <?= htmlspecialchars($roleLabel) ?>
            </span>
            <span class="text-muted fw-normal">
                (<?= count($groupEmployees) ?> nhân viên)
            </span>
        </h6>
    </div>

    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr>
                <th class="ps-3" width="40">#</th>
                <th>Họ tên</th>
                <th>Mã NV</th>
                <th>Phòng ban / Chức vụ</th>
                <th>Email</th>
                <th>Điện thoại</th>
                <th class="text-center">Ngày vào</th>
                <th class="text-end">Lương cơ bản</th>
                <th class="text-center">Trạng thái</th>
                <?php if (can('hr', 'manage')): ?>
                <th class="text-center">Thao tác</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($groupEmployees as $i => $emp):
            // Tính thâm niên
            $tenure = '—';
            if ($emp['hire_date']) {
                $months = (int)((time() - strtotime($emp['hire_date'])) / (30.44 * 86400));
                $years  = floor($months / 12);
                $rem    = $months % 12;
                $tenure = $years > 0 ? "{$years}n {$rem}th" : "{$months}th";
            }
        ?>
        <tr class="<?= !$emp['is_active'] ? 'table-secondary opacity-75' : '' ?>">

            <!-- STT -->
            <td class="ps-3 text-muted"><?= $i + 1 ?></td>

            <!-- Họ tên + avatar -->
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-<?= $roleColor ?> text-white
                                d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                         style="width:34px;height:34px;font-size:.85rem">
                        <?= mb_strtoupper(mb_substr($emp['full_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($emp['full_name']) ?></div>
                        <small class="text-muted">@<?= htmlspecialchars($emp['username']) ?></small>
                    </div>
                </div>
            </td>

            <!-- Mã NV -->
            <td>
                <code style="font-size:.75rem">
                    <?= htmlspecialchars($emp['employee_code'] ?? '—') ?>
                </code>
            </td>

            <!-- Phòng ban / Chức vụ -->
            <td>
                <div><?= htmlspecialchars($emp['department']) ?></div>
                <small class="text-muted"><?= htmlspecialchars($emp['position']) ?></small>
            </td>

            <!-- Email -->
            <td class="text-muted"><?= htmlspecialchars($emp['email'] ?? '—') ?></td>

            <!-- SĐT -->
            <td><?= htmlspecialchars($emp['phone'] ?? '—') ?></td>

            <!-- Ngày vào + thâm niên -->
            <td class="text-center">
                <?php if ($emp['hire_date']): ?>
                <div><?= date('d/m/Y', strtotime($emp['hire_date'])) ?></div>
                <small class="text-muted"><?= $tenure ?></small>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>

            <!-- Lương -->
            <td class="text-end">
                <?php if ($emp['monthly_salary'] > 0): ?>
                <span class="fw-semibold text-success">
                    <?= formatMoney((float)$emp['monthly_salary']) ?>
                </span>
                <?php else: ?>
                <span class="text-muted small">Chưa cấu hình</span>
                <?php endif; ?>
            </td>

            <!-- Trạng thái -->
            <td class="text-center">
                <?php if ($emp['is_active']): ?>
                <span class="badge bg-success">✅ Đang làm</span>
                <?php else: ?>
                <span class="badge bg-danger">🔒 Đã nghỉ</span>
                <?php endif; ?>
            </td>

            <!-- Thao tác -->
            <?php if (can('hr', 'manage')): ?>
            <td class="text-center">
                <div class="btn-group btn-group-sm">
                    <!-- Xem hồ sơ -->
                    <a href="view.php?id=<?= $emp['id'] ?>"
                       class="btn btn-outline-secondary" title="Hồ sơ">
                        <i class="fas fa-id-badge"></i>
                    </a>
                    <!-- Sửa -->
                    <a href="form.php?id=<?= $emp['id'] ?>"
                       class="btn btn-outline-primary" title="Sửa">
                        <i class="fas fa-edit"></i>
                    </a>
                    <!-- Khóa / Mở khóa -->
                    <?php if ($emp['is_active']): ?>
                    <a href="?toggle=<?= $emp['id'] ?>&state=0&<?= http_build_query($_GET) ?>"
                       class="btn btn-outline-warning" title="Cho nghỉ việc"
                       onclick="return confirm('Cho nhân viên này nghỉ việc?')">
                        <i class="fas fa-user-slash"></i>
                    </a>
                    <?php else: ?>
                    <a href="?toggle=<?= $emp['id'] ?>&state=1&<?= http_build_query($_GET) ?>"
                       class="btn btn-outline-success" title="Kích hoạt lại"
                       onclick="return confirm('Kích hoạt lại nhân viên này?')">
                        <i class="fas fa-user-check"></i>
                    </a>
                    <?php endif; ?>
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
<?php endforeach; ?>

</div><!-- /.container-fluid -->
</div><!-- /.main-content -->

<?php
// ── Xử lý toggle active (GET đơn giản) ──────────────────────
if (isset($_GET['toggle']) && isset($_GET['state']) && can('hr', 'manage')) {
    $uid   = (int)$_GET['toggle'];
    $state = (int)$_GET['state'];
    try {
        $pdo->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$state, $uid]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Đã cập nhật trạng thái nhân viên.'];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Lỗi: '.$e->getMessage()];
    }
    // Redirect sạch GET params
    $clean = $_GET;
    unset($clean['toggle'], $clean['state']);
    header('Location: index.php?' . http_build_query($clean));
    exit;
}

include '../../includes/footer.php';
?>