<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'view');

$pageTitle = 'Quản lý Users';
$pdo = getDBConnection();

$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterRole) {
    $where[]  = 'r.name = ?';
    $params[] = $filterRole;
}
if ($filterStatus !== '') {
    $where[]  = 'u.is_active = ?';
    $params[] = ($filterStatus === '1');
}
if ($search) {
    $where[]  = "(u.full_name ILIKE ? OR u.username ILIKE ? OR u.email ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT u.id, u.username, u.full_name, u.email, u.phone,
           u.is_active, u.role_id, u.avatar, u.created_at,
           r.name AS role, r.label AS role_label, r.id AS role_sort,
           COUNT(DISTINCT cu.customer_id) AS customer_count
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN customer_users cu ON cu.user_id = u.id
    WHERE $whereStr
    GROUP BY u.id, u.username, u.full_name, u.email, u.phone,
             u.is_active, u.role_id, u.avatar, u.created_at,
             r.name, r.label, r.id
    ORDER BY r.id, u.full_name
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$roles = $pdo->query("SELECT * FROM roles ORDER BY id")->fetchAll();

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold">👥 Quản lý Users</h4>
            <p class="text-muted mb-0">Tổng: <strong><?= count($users) ?></strong> tài khoản</p>
        </div>
        <?php if (can('users', 'create')): ?>
        <a href="/modules/admin/users/create.php" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Tạo tài khoản
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="🔍 Tìm tên, username, email..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="role" class="form-select form-select-sm">
                        <option value="">-- Tất cả Role --</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['name'] ?>"
                            <?= $filterRole === $r['name'] ? 'selected' : '' ?>>
                            <?= $r['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Đang hoạt động</option>
                        <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Đã khóa</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary me-1">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
			<a href="profile.php?id=<?= $u['id'] ?>"
   				class="btn btn-outline-secondary" title="Hồ sơ">
   				 <i class="fas fa-id-badge"></i>
				</a>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng users nhóm theo role -->
    <?php
    $grouped = [];
    foreach ($users as $u) {
        $grouped[$u['role_label']][] = $u;
    }
    foreach ($grouped as $roleLabel => $groupUsers):
        $firstUser = $groupUsers[0];
        $badge     = getRoleBadge($firstUser['role']);
    ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-0 pt-3 pb-2">
            <h6 class="fw-bold mb-0">
                <span class="badge bg-<?= $badge['class'] ?> me-2">
                    <?= $badge['icon'] ?> <?= htmlspecialchars($roleLabel) ?>
                </span>
                <span class="text-muted fw-normal">(<?= count($groupUsers) ?> tài khoản)</span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" width="40">#</th>
                            <th>Họ tên</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Điện thoại</th>
                            <?php if ($firstUser['role'] === 'customer'): ?>
                            <th>Công ty</th>
                            <?php endif; ?>
                            <th>Trạng thái</th>
                            <th>Ngày tạo</th>
                            <?php if (can('users', 'edit')): ?>
                            <th class="text-center">Thao tác</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groupUsers as $i => $u): ?>
                        <tr class="<?= !$u['is_active'] ? 'table-secondary opacity-75' : '' ?>">
                            <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-<?= $badge['class'] ?> text-white
                                                d-flex align-items-center justify-content-center fw-bold"
                                         style="width:34px;height:34px;font-size:0.85rem;flex-shrink:0">
                                        <?= mb_strtoupper(mb_substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($u['username']) ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                            <td><?= htmlspecialchars($u['email'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                            <?php if ($firstUser['role'] === 'customer'): ?>
                            <td>
                                <?php if ($u['customer_count'] > 0): ?>
                                    <span class="badge bg-info"><?= $u['customer_count'] ?> công ty</span>
                                <?php else: ?>
                                    <span class="text-muted small">Chưa gán</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($u['is_active']): ?>
                                    <span class="badge bg-success">✅ Hoạt động</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">🔒 Đã khóa</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                            </td>
                            <?php if (can('users', 'edit')): ?>
                            <td class="text-center">
				<div class="btn-group btn-group-sm">
    					<a href="profile.php?id=<?= $u['id'] ?>"      // ← THÊM
       					class="btn btn-outline-secondary" title="Hồ sơ">
       					 <i class="fas fa-id-badge"></i>
    					</a>
                                <div class="btn-group btn-group-sm">
                                    <a href="edit.php?id=<?= $u['id'] ?>"
                                       class="btn btn-outline-primary" title="Sửa">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($u['is_active']): ?>
                                    <a href="delete.php?id=<?= $u['id'] ?>&action=lock"
                                       class="btn btn-outline-warning" title="Khóa"
                                       onclick="return confirm('Khóa tài khoản này?')">
                                        <i class="fas fa-lock"></i>
                                    </a>
                                    <?php else: ?>
                                    <a href="delete.php?id=<?= $u['id'] ?>&action=unlock"
                                       class="btn btn-outline-success" title="Mở khóa"
                                       onclick="return confirm('Mở khóa tài khoản này?')">
                                        <i class="fas fa-lock-open"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (can('users', 'delete') && $u['id'] !== currentUser()['id']): ?>
                                    <a href="delete.php?id=<?= $u['id'] ?>&action=delete"
                                       class="btn btn-outline-danger" title="Xóa"
                                       onclick="return confirm('Xóa tài khoản? Không thể khôi phục!')">
                                        <i class="fas fa-trash"></i>
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

</div>
</div>

<?php include '../../../includes/footer.php'; ?>