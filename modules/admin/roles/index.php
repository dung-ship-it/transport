<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'assign_role');

$pdo       = getDBConnection();
$user      = currentUser();
$pageTitle = 'Phân quyền hệ thống';

// ── Xử lý POST lưu quyền ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role'])) {
    $roleId      = (int)$_POST['role_id'];
    $permIds     = array_map('intval', $_POST['perms'] ?? []);

    // Xóa tất cả quyền cũ của role này
    $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")
        ->execute([$roleId]);

    // Insert quyền mới
    if (!empty($permIds)) {
        $ins = $pdo->prepare("
            INSERT INTO role_permissions (role_id, permission_id)
            VALUES (?, ?)
            ON CONFLICT DO NOTHING
        ");
        foreach ($permIds as $pid) {
            $ins->execute([$roleId, $pid]);
        }
    }

    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => '✅ Đã lưu phân quyền thành công!'
    ];
    header("Location: index.php?role_id=$roleId");
    exit;
}

// ── Load dữ liệu ────────────────────────────────────────────
// Danh sách roles (bỏ superadmin ra khỏi chỉnh sửa)
$roles = $pdo->query("
    SELECT * FROM roles ORDER BY id
")->fetchAll();

// Role đang chọn
$selectedRoleId = (int)($_GET['role_id'] ?? ($roles[0]['id'] ?? 0));

// Tất cả permissions nhóm theo module
$allPerms = $pdo->query("
    SELECT * FROM permissions ORDER BY module, action
")->fetchAll();

// Group theo module
$permsByModule = [];
foreach ($allPerms as $p) {
    $permsByModule[$p['module']][] = $p;
}

// Quyền hiện tại của role được chọn
$currentPerms = [];
if ($selectedRoleId) {
    $rp = $pdo->prepare("
        SELECT permission_id FROM role_permissions WHERE role_id = ?
    ");
    $rp->execute([$selectedRoleId]);
    $currentPerms = array_column($rp->fetchAll(), 'permission_id');
}

// Thông tin role đang chọn
$selectedRole = null;
foreach ($roles as $r) {
    if ($r['id'] === $selectedRoleId) { $selectedRole = $r; break; }
}

// Nhãn module tiếng Việt
$moduleLabels = [
    'dashboard'  => ['icon' => 'fa-tachometer-alt', 'label' => 'Dashboard',        'color' => 'primary'],
    'users'      => ['icon' => 'fa-users-cog',       'label' => 'Quản lý Users',    'color' => 'danger'],
    'vehicles'   => ['icon' => 'fa-truck',            'label' => 'Quản lý Xe',       'color' => 'info'],
    'expenses'   => ['icon' => 'fa-tools',            'label' => 'Sửa chữa / Chi phí','color' => 'warning'],
    'fuel'       => ['icon' => 'fa-gas-pump',         'label' => 'Nhiên liệu',       'color' => 'success'],
    'trips'      => ['icon' => 'fa-route',            'label' => 'Chuyến xe',        'color' => 'primary'],
    'customers'  => ['icon' => 'fa-building',         'label' => 'Khách hàng',       'color' => 'info'],
    'pricebook'  => ['icon' => 'fa-tags',             'label' => 'Bảng giá',         'color' => 'success'],
    'statements' => ['icon' => 'fa-file-invoice-dollar','label'=>'Bảng kê công nợ', 'color' => 'warning'],
    'payroll'    => ['icon' => 'fa-money-check-alt',  'label' => 'Bảng lương',       'color' => 'success'],
    'kpi'        => ['icon' => 'fa-chart-bar',        'label' => 'KPI Lái Xe',       'color' => 'info'],
    'reports'    => ['icon' => 'fa-chart-pie',        'label' => 'Báo cáo',          'color' => 'primary'],
    'drivers'    => ['icon' => 'fa-id-card',          'label' => 'Lái xe',           'color' => 'secondary'],
];

// Nhãn action tiếng Việt
$actionLabels = [
    'view'             => ['icon' => 'fa-eye',          'label' => 'Xem',              'color' => 'info'],
    'view_own'         => ['icon' => 'fa-user-eye',     'label' => 'Xem của mình',     'color' => 'info'],
    'view_all'         => ['icon' => 'fa-eye',          'label' => 'Xem tất cả',       'color' => 'info'],
    'view_full'        => ['icon' => 'fa-eye',          'label' => 'Xem đầy đủ',       'color' => 'info'],
    'view_accountant'  => ['icon' => 'fa-calculator',  'label' => 'Xem kế toán',       'color' => 'info'],
    'view_dispatcher'  => ['icon' => 'fa-truck',       'label' => 'Xem vận hành',      'color' => 'info'],
    'view_operations'  => ['icon' => 'fa-cogs',        'label' => 'Xem vận hành',      'color' => 'info'],
    'create'           => ['icon' => 'fa-plus',        'label' => 'Tạo mới',           'color' => 'success'],
    'crud'             => ['icon' => 'fa-edit',        'label' => 'Thêm/Sửa/Xóa',     'color' => 'warning'],
    'edit'             => ['icon' => 'fa-pencil-alt',  'label' => 'Sửa',               'color' => 'warning'],
    'delete'           => ['icon' => 'fa-trash',       'label' => 'Xóa',               'color' => 'danger'],
    'approve'          => ['icon' => 'fa-check-double','label' => 'Duyệt',             'color' => 'success'],
    'confirm'          => ['icon' => 'fa-check-circle','label' => 'Xác nhận',          'color' => 'success'],
    'submit'           => ['icon' => 'fa-paper-plane', 'label' => 'Gửi',               'color' => 'primary'],
    'assign_role'      => ['icon' => 'fa-user-shield', 'label' => 'Phân quyền',        'color' => 'danger'],
    'manage'           => ['icon' => 'fa-cogs',        'label' => 'Quản lý',           'color' => 'warning'],
    'calculate'        => ['icon' => 'fa-calculator',  'label' => 'Tính toán',         'color' => 'info'],
    'export'           => ['icon' => 'fa-download',    'label' => 'Xuất file',         'color' => 'secondary'],
];

// Màu role badge
$roleBadgeColors = [
    'superadmin' => 'danger',
    'admin'      => 'warning',
    'accountant' => 'info',
    'dispatcher' => 'primary',
    'driver'     => 'success',
    'customer'   => 'secondary',
];

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-shield-alt me-2 text-warning"></i>Phân quyền hệ thống
            </h4>
            <small class="text-muted">
                Chọn role và tích/bỏ tích các quyền tương ứng
            </small>
        </div>
    </div>

    <?php showFlash(); ?>

    <div class="row g-4">

        <!-- ── CỘT TRÁI: Danh sách roles ── -->
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-dark text-white py-2">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-users me-2"></i>Danh sách Role
                    </h6>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($roles as $role):
                        $badgeColor = $roleBadgeColors[$role['name']] ?? 'secondary';
                        $isSelected = $role['id'] === $selectedRoleId;
                        // Đếm số quyền của role này
                        $countStmt = $pdo->prepare("
                            SELECT COUNT(*) FROM role_permissions WHERE role_id = ?
                        ");
                        $countStmt->execute([$role['id']]);
                        $permCount = $countStmt->fetchColumn();
                    ?>
                    <a href="?role_id=<?= $role['id'] ?>"
                       class="list-group-item list-group-item-action py-3
                              <?= $isSelected ? 'active bg-primary border-primary text-white' : '' ?>">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="badge bg-<?= $badgeColor ?> me-2">
                                    <?= ucfirst($role['name']) ?>
                                </span>
                                <?php if ($role['display_name'] ?? ''): ?>
                                <small class="<?= $isSelected ? 'text-white-50' : 'text-muted' ?>">
                                    <?= htmlspecialchars($role['display_name']) ?>
                                </small>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-<?= $isSelected ? 'white text-primary' : 'secondary' ?>">
                                <?= $permCount ?>
                            </span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Hướng dẫn -->
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body p-3">
                    <div class="small fw-semibold text-muted mb-2">
                        <i class="fas fa-info-circle me-1"></i>Hướng dẫn
                    </div>
                    <div class="small text-muted lh-lg">
                        • Chọn role bên trái<br>
                        • Tích/bỏ tích các quyền<br>
                        • Nhấn <strong>Lưu</strong> để áp dụng<br>
                        • Thay đổi có hiệu lực ngay
                    </div>
                    <hr class="my-2">
                    <div class="small">
                        <span class="badge bg-info me-1">Xem</span>
                        <span class="badge bg-success me-1">Tạo/Duyệt</span>
                        <span class="badge bg-warning me-1">Sửa</span>
                        <span class="badge bg-danger">Xóa</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── CỘT PHẢI: Ma trận quyền ── -->
        <div class="col-md-9">
            <?php if ($selectedRole): ?>
            <form method="POST" id="permForm">
                <input type="hidden" name="role_id"   value="<?= $selectedRoleId ?>">
                <input type="hidden" name="save_role" value="1">

                <div class="card border-0 shadow-sm">
                    <div class="card-header py-2 d-flex justify-content-between align-items-center"
                         style="background:#0f3460;color:#fff">
                        <div>
                            <i class="fas fa-shield-alt me-2"></i>
                            <strong>
                                <?= ucfirst($selectedRole['name']) ?>
                                <?php if ($selectedRole['display_name'] ?? ''): ?>
                                — <?= htmlspecialchars($selectedRole['display_name']) ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <button type="button" class="btn btn-sm btn-light"
                                    onclick="toggleAll(true)">
                                <i class="fas fa-check-square me-1"></i>Chọn tất cả
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-light"
                                    onclick="toggleAll(false)">
                                <i class="fas fa-square me-1"></i>Bỏ tất cả
                            </button>
                            <button type="submit" class="btn btn-sm btn-warning fw-bold">
                                <i class="fas fa-save me-1"></i>Lưu phân quyền
                            </button>
                        </div>
                    </div>

                    <div class="card-body p-3">
                        <?php foreach ($permsByModule as $module => $perms):
                            $modInfo = $moduleLabels[$module] ?? [
                                'icon'  => 'fa-cog',
                                'label' => ucfirst($module),
                                'color' => 'secondary',
                            ];
                            // Kiểm tra module có quyền nào được tick không
                            $moduleHasAny = false;
                            foreach ($perms as $p) {
                                if (in_array($p['id'], $currentPerms)) {
                                    $moduleHasAny = true;
                                    break;
                                }
                            }
                        ?>
                        <div class="module-block mb-3 border rounded-3 overflow-hidden">
                            <!-- Module header -->
                            <div class="d-flex align-items-center justify-content-between px-3 py-2
                                        <?= $moduleHasAny ? 'bg-' . $modInfo['color'] . ' bg-opacity-10' : 'bg-light' ?>"
                                 style="border-bottom:1px solid #dee2e6;cursor:pointer"
                                 onclick="toggleModule('mod-<?= $module ?>')">
                                <div class="d-flex align-items-center gap-2">
                                    <!-- Checkbox chọn toàn module -->
                                    <input type="checkbox"
                                           class="form-check-input module-master"
                                           id="master-<?= $module ?>"
                                           data-module="<?= $module ?>"
                                           onclick="event.stopPropagation();toggleModuleCheck('<?= $module ?>', this.checked)"
                                           <?= $moduleHasAny ? 'checked' : '' ?>>
                                    <i class="fas <?= $modInfo['icon'] ?> text-<?= $modInfo['color'] ?>"></i>
                                    <span class="fw-semibold">
                                        <?= htmlspecialchars($modInfo['label']) ?>
                                    </span>
                                    <span class="badge bg-<?= $modInfo['color'] ?> bg-opacity-75 small">
                                        <?= count($perms) ?> quyền
                                    </span>
                                </div>
                                <i class="fas fa-chevron-down text-muted small" id="chevron-<?= $module ?>"></i>
                            </div>

                            <!-- Danh sách permissions -->
                            <div class="module-perms px-3 py-2" id="mod-<?= $module ?>">
                                <div class="row g-2">
                                <?php foreach ($perms as $p):
                                    $actInfo = $actionLabels[$p['action']] ?? [
                                        'icon'  => 'fa-cog',
                                        'label' => ucfirst($p['action']),
                                        'color' => 'secondary',
                                    ];
                                    $checked = in_array($p['id'], $currentPerms);
                                ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="perm-item d-flex align-items-center gap-2 p-2 rounded
                                                <?= $checked ? 'bg-' . $actInfo['color'] . ' bg-opacity-10 border border-' . $actInfo['color'] . ' border-opacity-25' : 'bg-light' ?>">
                                        <input type="checkbox"
                                               class="form-check-input perm-check mod-<?= $module ?>"
                                               name="perms[]"
                                               value="<?= $p['id'] ?>"
                                               id="perm-<?= $p['id'] ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               onchange="updatePermStyle(this, '<?= $actInfo['color'] ?>')">
                                        <label for="perm-<?= $p['id'] ?>"
                                               class="small mb-0 cursor-pointer d-flex align-items-center gap-1"
                                               style="cursor:pointer">
                                            <span class="badge bg-<?= $actInfo['color'] ?>"
                                                  style="font-size:0.6rem">
                                                <i class="fas <?= $actInfo['icon'] ?>"></i>
                                                <?= htmlspecialchars($actInfo['label']) ?>
                                            </span>
                                            <span class="text-muted" style="font-size:0.75rem">
                                                <?= htmlspecialchars($p['label'] ?? $p['action']) ?>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="card-footer bg-light d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Đang chỉnh: <strong><?= ucfirst($selectedRole['name']) ?></strong>
                            — <span id="checkedCount"><?= count($currentPerms) ?></span> quyền được chọn
                        </small>
                        <button type="submit" class="btn btn-primary fw-bold">
                            <i class="fas fa-save me-2"></i>Lưu phân quyền
                        </button>
                    </div>
                </div>
            </form>

            <?php else: ?>
            <div class="card border-0 shadow-sm p-5 text-center">
                <i class="fas fa-shield-alt fa-3x text-muted opacity-25 mb-3"></i>
                <p class="text-muted">Chọn một role bên trái để chỉnh sửa quyền</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</div>

<style>
.module-perms { transition: all 0.2s ease; }
.perm-item    { transition: background 0.15s, border 0.15s; }
.hover-perm:hover { background: #f0f4ff !important; }
.cursor-pointer   { cursor: pointer; }
</style>

<script>
// ── Toggle hiện/ẩn module ──────────────────────────────────
function toggleModule(id) {
    const el  = document.getElementById(id);
    const mod = id.replace('mod-', '');
    const ch  = document.getElementById('chevron-' + mod);
    const hidden = el.style.display === 'none';
    el.style.display  = hidden ? 'block' : 'none';
    ch.style.transform = hidden ? 'rotate(0deg)' : 'rotate(-90deg)';
}

// ── Chọn/bỏ toàn bộ trong một module ──────────────────────
function toggleModuleCheck(module, checked) {
    document.querySelectorAll('.mod-' + module).forEach(cb => {
        cb.checked = checked;
        updatePermStyle(cb, null);
    });
    updateCount();
}

// ── Chọn/bỏ tất cả ────────────────────────────────────────
function toggleAll(checked) {
    document.querySelectorAll('.perm-check').forEach(cb => {
        cb.checked = checked;
        updatePermStyle(cb, null);
    });
    document.querySelectorAll('.module-master').forEach(cb => {
        cb.checked = checked;
    });
    updateCount();
}

// ── Cập nhật style khi tick/bỏ ────────────────────────────
function updatePermStyle(cb, color) {
    const item = cb.closest('.perm-item');
    if (!item) return;

    // Lấy màu từ badge nếu không truyền vào
    if (!color) {
        const badge = item.querySelector('.badge');
        if (badge) {
            const cls = [...badge.classList].find(c => c.startsWith('bg-') && c !== 'bg-opacity-10');
            color = cls ? cls.replace('bg-', '') : 'secondary';
        }
    }

    if (cb.checked) {
        item.className = `perm-item d-flex align-items-center gap-2 p-2 rounded bg-${color} bg-opacity-10 border border-${color} border-opacity-25`;
    } else {
        item.className = 'perm-item d-flex align-items-center gap-2 p-2 rounded bg-light';
    }

    // Cập nhật master checkbox của module
    const mod = cb.classList[2]?.replace('mod-', '');
    if (mod) {
        const allInMod    = document.querySelectorAll('.mod-' + mod);
        const checkedInMod = document.querySelectorAll('.mod-' + mod + ':checked');
        const master = document.getElementById('master-' + mod);
        if (master) master.checked = checkedInMod.length > 0;
    }

    updateCount();
}

// ── Cập nhật đếm số quyền ─────────────────────────────────
function updateCount() {
    const count = document.querySelectorAll('.perm-check:checked').length;
    const el = document.getElementById('checkedCount');
    if (el) el.textContent = count;
}

// ── Confirm trước khi lưu ─────────────────────────────────
document.getElementById('permForm')?.addEventListener('submit', function(e) {
    const role    = '<?= htmlspecialchars($selectedRole['name'] ?? '') ?>';
    const count   = document.querySelectorAll('.perm-check:checked').length;
    if (!confirm(`Xác nhận lưu ${count} quyền cho role "${role}"?`)) {
        e.preventDefault();
    }
});

// ── Khởi tạo: collapse các module chưa có quyền ───────────
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.module-block').forEach(block => {
        const master = block.querySelector('.module-master');
        const modId  = master?.dataset.module;
        if (modId) {
            const chevron = document.getElementById('chevron-' + modId);
            const content = document.getElementById('mod-' + modId);
            if (master && !master.checked && content) {
                content.style.display  = 'none';
                if (chevron) chevron.style.transform = 'rotate(-90deg)';
            }
        }
    });
});
</script>

<?php include '../../../includes/footer.php'; ?>