<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('customers', 'view');

$pageTitle = 'Quản lý Khách hàng';
$pdo = getDBConnection();

$search        = trim($_GET['q']      ?? '');
$filterStatus  = $_GET['status']      ?? '';
$filterCycle   = $_GET['cycle']       ?? '';

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = "(c.company_name ILIKE ? OR c.customer_code ILIKE ? OR c.tax_code ILIKE ? OR c.primary_contact_phone ILIKE ?)";
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
if ($filterStatus !== '') {
    $where[]  = 'c.is_active = ?';
    $params[] = ($filterStatus === '1') ? 'true' : 'false';
}
if ($filterCycle) {
    $where[]  = 'c.billing_cycle = ?';
    $params[] = $filterCycle;
}

$whereStr = implode(' AND ', $where);

$customers = $pdo->prepare("
    SELECT c.*,
        u.full_name AS created_by_name,
        -- Số user
        (SELECT COUNT(*) FROM customer_users cu
         WHERE cu.customer_id = c.id AND cu.is_active = TRUE) AS user_count,
        -- Bảng giá active
        (SELECT pb.name FROM price_books pb
         WHERE pb.customer_id = c.id AND pb.is_active = TRUE
           AND pb.valid_from <= CURRENT_DATE
           AND (pb.valid_to IS NULL OR pb.valid_to >= CURRENT_DATE)
         ORDER BY pb.valid_from DESC LIMIT 1) AS active_price_book,
        -- Trips tháng này
        (SELECT COUNT(*) FROM trips t
         WHERE t.customer_id = c.id
           AND EXTRACT(MONTH FROM t.trip_date) = EXTRACT(MONTH FROM CURRENT_DATE)
           AND EXTRACT(YEAR  FROM t.trip_date) = EXTRACT(YEAR  FROM CURRENT_DATE)
        ) AS trips_this_month
    FROM customers c
    LEFT JOIN users u ON c.created_by = u.id
    WHERE $whereStr
    ORDER BY c.is_active DESC, c.company_name
");
$customers->execute($params);
$customers = $customers->fetchAll();

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*)                                       AS total,
        COUNT(*) FILTER (WHERE is_active = TRUE)       AS active,
        COUNT(*) FILTER (WHERE billing_cycle='monthly') AS monthly,
        COUNT(*) FILTER (WHERE billing_cycle='weekly')  AS weekly
    FROM customers
")->fetch();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1">🏢 Quản lý Khách hàng</h4>
            <p class="text-muted mb-0">Tổng: <strong><?= $stats['total'] ?></strong> khách hàng</p>
        </div>
        <?php if (can('customers', 'crud')): ?>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Thêm khách hàng
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-primary"><?= $stats['total'] ?></div>
                <div class="small text-muted">Tổng KH</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-success"><?= $stats['active'] ?></div>
                <div class="small text-muted">Đang hoạt động</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-info"><?= $stats['monthly'] ?></div>
                <div class="small text-muted">Thanh toán tháng</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-warning"><?= $stats['weekly'] ?></div>
                <div class="small text-muted">Thanh toán tuần</div>
            </div>
        </div>
    </div>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2">
                <div class="col-md-4">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="🔍 Tìm tên, mã KH, MST, SĐT..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="1" <?= $filterStatus==='1'?'selected':'' ?>>Hoạt động</option>
                        <option value="0" <?= $filterStatus==='0'?'selected':'' ?>>Ngừng</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="cycle" class="form-select form-select-sm">
                        <option value="">-- Chu kỳ TT --</option>
                        <option value="monthly" <?= $filterCycle==='monthly'?'selected':'' ?>>Hàng tháng</option>
                        <option value="weekly"  <?= $filterCycle==='weekly' ?'selected':'' ?>>Hàng tuần</option>
                        <option value="custom"  <?= $filterCycle==='custom' ?'selected':'' ?>>Tùy chỉnh</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-sm btn-primary me-1">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">Mã KH</th>
                            <th>Tên công ty</th>
                            <th>MST</th>
                            <th>Đầu mối</th>
                            <th>Chu kỳ TT</th>
                            <th>Users</th>
                            <th>Bảng giá</th>
                            <th>Chuyến tháng này</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="10" class="text-center py-5 text-muted">
                            <i class="fas fa-building fa-2x mb-2 d-block opacity-25"></i>
                            Chưa có khách hàng nào
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($customers as $c): ?>
                    <tr class="<?= !$c['is_active'] ? 'opacity-50' : '' ?>">
                        <td class="ps-3">
                            <span class="badge bg-secondary"><?= $c['customer_code'] ?></span>
                        </td>
                        <td>
                            <a href="detail.php?id=<?= $c['id'] ?>"
                               class="fw-bold text-decoration-none text-primary">
                                <?= htmlspecialchars($c['company_name']) ?>
                            </a>
                            <?php if ($c['short_name']): ?>
                            <div class="text-muted small"><?= htmlspecialchars($c['short_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($c['tax_code'] ?? '—') ?></td>
                        <td>
                            <div class="small fw-semibold">
                                <?= htmlspecialchars($c['primary_contact_name'] ?? '—') ?>
                            </div>
                            <div class="small text-muted">
                                <?= htmlspecialchars($c['primary_contact_phone'] ?? '') ?>
                            </div>
                        </td>
                        <td>
                            <?php
                            $cycleLabels = [
                                'monthly' => ['info',    'Hàng tháng'],
                                'weekly'  => ['warning', 'Hàng tuần'],
                                'custom'  => ['secondary','Tùy chỉnh'],
                            ];
                            [$cc, $cl] = $cycleLabels[$c['billing_cycle']] ?? ['secondary', $c['billing_cycle']];
                            ?>
                            <span class="badge bg-<?= $cc ?>"><?= $cl ?></span>
                            <?php if ($c['payment_terms']): ?>
                            <div class="text-muted small">NET <?= $c['payment_terms'] ?> ngày</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($c['user_count'] > 0): ?>
                            <span class="badge bg-primary"><?= $c['user_count'] ?> users</span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['active_price_book']): ?>
                            <span class="badge bg-success">
                                <i class="fas fa-check me-1"></i>
                                <?= htmlspecialchars($c['active_price_book']) ?>
                            </span>
                            <?php else: ?>
                            <span class="badge bg-warning">⚠️ Chưa có giá</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-semibold">
                            <?= $c['trips_this_month'] ?>
                        </td>
                        <td>
                            <?php if ($c['is_active']): ?>
                            <span class="badge bg-success">✅ Hoạt động</span>
                            <?php else: ?>
                            <span class="badge bg-danger">⏸ Ngừng</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="detail.php?id=<?= $c['id'] ?>"
                                   class="btn btn-outline-info" title="Chi tiết">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (can('customers', 'crud')): ?>
                                <a href="detail.php?id=<?= $c['id'] ?>&tab=pricebook"
                                   class="btn btn-outline-success" title="Cài giá">
                                    <i class="fas fa-tags"></i>
                                </a>
                                <a href="detail.php?id=<?= $c['id'] ?>&tab=users"
                                   class="btn btn-outline-primary" title="Tài khoản">
                                    <i class="fas fa-users"></i>
                                </a>
                                <a href="edit.php?id=<?= $c['id'] ?>"
                                   class="btn btn-outline-warning" title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../../includes/footer.php'; ?>