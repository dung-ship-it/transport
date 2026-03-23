<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('vehicles', 'view');

$pageTitle = 'Quản lý phương tiện';
$pdo = getDBConnection();

$filterType   = $_GET['type']   ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($filterType) {
    $where[]  = 'v.vehicle_type_id = ?';
    $params[] = $filterType;
}
if ($filterStatus !== '') {
    $where[]  = 'v.is_active = ?';
    $params[] = ($filterStatus === '1');
}
if ($search) {
    $where[]  = "(v.plate_number ILIKE ? OR v.note ILIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);

$vehicles = $pdo->prepare("
    SELECT v.*,
           vt.name AS type_name,
           u.full_name AS created_by_name,
           -- Trạng thái hạn pháp lý
           CASE
               WHEN v.registration_expiry < CURRENT_DATE THEN 'expired'
               WHEN v.registration_expiry < CURRENT_DATE + INTERVAL '30 days' THEN 'warning'
               ELSE 'ok'
           END AS reg_status,
           CASE
               WHEN v.insurance_expiry < CURRENT_DATE THEN 'expired'
               WHEN v.insurance_expiry < CURRENT_DATE + INTERVAL '30 days' THEN 'warning'
               ELSE 'ok'
           END AS ins_status,
           -- Chuyến đang chạy
           (SELECT COUNT(*) FROM trips t
            WHERE t.vehicle_id = v.id AND t.status = 'in_progress') AS active_trips,
           -- Km tháng này
           COALESCE((SELECT SUM(distance_km) FROM trips t
            WHERE t.vehicle_id = v.id
              AND EXTRACT(MONTH FROM t.trip_date) = EXTRACT(MONTH FROM CURRENT_DATE)
              AND EXTRACT(YEAR  FROM t.trip_date) = EXTRACT(YEAR  FROM CURRENT_DATE)
           ), 0) AS km_this_month
    FROM vehicles v
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    LEFT JOIN users u ON v.created_by = u.id
    WHERE $whereStr
    ORDER BY v.is_active DESC, v.plate_number
");
$vehicles->execute($params);
$vehicles = $vehicles->fetchAll();

$vehicleTypes = $pdo->query("SELECT * FROM vehicle_types WHERE is_active = TRUE ORDER BY name")->fetchAll();

// Thống kê nhanh
$stats = $pdo->query("
    SELECT
        COUNT(*)                                    AS total,
        COUNT(*) FILTER (WHERE is_active = TRUE)    AS active,
        COUNT(*) FILTER (WHERE registration_expiry < CURRENT_DATE + INTERVAL '30 days'
                           AND registration_expiry >= CURRENT_DATE) AS reg_warning,
        COUNT(*) FILTER (WHERE registration_expiry < CURRENT_DATE) AS reg_expired
    FROM vehicles
")->fetch();

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Tiêu đề -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold">🚛 Quản lý phương tiện</h4>
            <p class="text-muted mb-0">Tổng: <strong><?= $stats['total'] ?></strong> xe</p>
        </div>
        <?php if (can('vehicles', 'crud')): ?>
        <a href="create.php" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Thêm xe mới
        </a>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-primary"><?= $stats['total'] ?></div>
                <div class="small text-muted">Tổng xe</div>
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
                <div class="fs-3 fw-bold text-warning"><?= $stats['reg_warning'] ?></div>
                <div class="small text-muted">Sắp hết hạn đăng kiểm</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-3 fw-bold text-danger"><?= $stats['reg_expired'] ?></div>
                <div class="small text-muted">Quá hạn đăng kiểm</div>
            </div>
        </div>
    </div>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="🔍 Tìm biển số..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-3">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">-- Tất cả loại xe --</option>
                        <?php foreach ($vehicleTypes as $vt): ?>
                        <option value="<?= $vt['id'] ?>"
                            <?= $filterType == $vt['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($vt['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="1" <?= $filterStatus==='1' ? 'selected':'' ?>>Hoạt động</option>
                        <option value="0" <?= $filterStatus==='0' ? 'selected':'' ?>>Ngừng hoạt động</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary me-1">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Danh sách xe -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Biển số</th>
                            <th>Loại xe</th>
                            <th>Tải trọng</th>
                            <th>Định mức xăng</th>
                            <th>Đăng kiểm</th>
                            <th>Bảo hiểm</th>
                            <th>Phí ĐB</th>
                            <th>Km tháng này</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($vehicles)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-4 text-muted">
                            <i class="fas fa-truck fa-2x mb-2 d-block opacity-25"></i>
                            Chưa có xe nào
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($vehicles as $i => $v): ?>
                    <tr class="<?= !$v['is_active'] ? 'opacity-50' : '' ?>">
                        <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <a href="detail.php?id=<?= $v['id'] ?>"
                               class="fw-bold text-decoration-none text-primary">
                                <?= htmlspecialchars($v['plate_number']) ?>
                            </a>
                            <?php if ($v['active_trips'] > 0): ?>
                            <span class="badge bg-success ms-1">🚛 Đang chạy</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($v['type_name']) ?></td>
                        <td><?= $v['capacity'] ? $v['capacity'] . ' tấn' : '—' ?></td>
                        <td><?= $v['fuel_quota'] ? $v['fuel_quota'] . ' L/100km' : '—' ?></td>
                        <td>
                            <?php if ($v['registration_expiry']): ?>
                            <span class="badge bg-<?= $v['reg_status']==='expired' ? 'danger' : ($v['reg_status']==='warning' ? 'warning' : 'success') ?>">
                                <?= $v['reg_status']==='expired' ? '❌' : ($v['reg_status']==='warning' ? '⚠️' : '✅') ?>
                                <?= date('d/m/Y', strtotime($v['registration_expiry'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">Chưa nhập</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['insurance_expiry']): ?>
                            <span class="badge bg-<?= $v['ins_status']==='expired' ? 'danger' : ($v['ins_status']==='warning' ? 'warning' : 'success') ?>">
                                <?= $v['ins_status']==='expired' ? '❌' : ($v['ins_status']==='warning' ? '⚠️' : '✅') ?>
                                <?= date('d/m/Y', strtotime($v['insurance_expiry'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">Chưa nhập</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($v['road_tax_expiry']): ?>
                            <?php
                                $rtStatus = $v['road_tax_expiry'] < date('Y-m-d') ? 'expired' :
                                    ($v['road_tax_expiry'] < date('Y-m-d', strtotime('+30 days')) ? 'warning' : 'ok');
                            ?>
                            <span class="badge bg-<?= $rtStatus==='expired'?'danger':($rtStatus==='warning'?'warning':'success') ?>">
                                <?= date('d/m/Y', strtotime($v['road_tax_expiry'])) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold">
                            <?= number_format((float)$v['km_this_month'], 0) ?> km
                        </td>
                        <td>
                            <?php if ($v['is_active']): ?>
                            <span class="badge bg-success">✅ Hoạt động</span>
                            <?php else: ?>
                            <span class="badge bg-secondary">⏸ Ngừng</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="detail.php?id=<?= $v['id'] ?>"
                                   class="btn btn-outline-info" title="Chi tiết">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (can('vehicles', 'crud')): ?>
                                <a href="edit.php?id=<?= $v['id'] ?>"
                                   class="btn btn-outline-primary" title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <a href="delete.php?id=<?= $v['id'] ?>&action=<?= $v['is_active'] ? 'deactivate' : 'activate' ?>"
                                   class="btn btn-outline-<?= $v['is_active'] ? 'warning' : 'success' ?>"
                                   title="<?= $v['is_active'] ? 'Ngừng hoạt động' : 'Kích hoạt' ?>"
                                   onclick="return confirm('Xác nhận thay đổi trạng thái xe này?')">
                                    <i class="fas fa-<?= $v['is_active'] ? 'pause' : 'play' ?>"></i>
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