<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('vehicles', 'view');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
$tab = $_GET['tab'] ?? 'info';
if (!$id) { header('Location: index.php'); exit; }

// Load xe
$stmt = $pdo->prepare("
    SELECT v.*, vt.name AS type_name
    FROM vehicles v JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    WHERE v.id = ?
");
$stmt->execute([$id]);
$vehicle = $stmt->fetch();
if (!$vehicle) { header('Location: index.php'); exit; }

$pageTitle = 'Chi tiết xe: ' . $vehicle['plate_number'];

// Load bảo dưỡng
$maintenances = $pdo->prepare("
    SELECT m.*, u.full_name AS created_by_name, a.full_name AS approved_by_name
    FROM maintenance_logs m
    LEFT JOIN users u ON m.created_by = u.id
    LEFT JOIN users a ON m.approved_by = a.id
    WHERE m.vehicle_id = ?
    ORDER BY m.maintenance_date DESC
    LIMIT 20
");
$maintenances->execute([$id]);
$maintenances = $maintenances->fetchAll();

// Load xăng dầu
$fuelLogs = $pdo->prepare("
    SELECT f.*, d.user_id,
           u.full_name AS driver_name,
           a.full_name AS approved_by_name
    FROM fuel_logs f
    LEFT JOIN drivers d ON f.driver_id = d.id
    LEFT JOIN users u ON d.user_id = u.id
    LEFT JOIN users a ON f.approved_by = a.id
    WHERE f.vehicle_id = ?
    ORDER BY f.log_date DESC
    LIMIT 30
");
$fuelLogs->execute([$id]);
$fuelLogs = $fuelLogs->fetchAll();

// Tổng chi phí
$totalFuel  = array_sum(array_column($fuelLogs, 'total_cost'));
$totalMaint = array_sum(array_column($maintenances, 'total_cost'));

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h4 class="fw-bold mb-0">
                    🚛 <?= htmlspecialchars($vehicle['plate_number']) ?>
                    <?php if ($vehicle['is_active']): ?>
                    <span class="badge bg-success ms-1 fs-6">✅ Hoạt động</span>
                    <?php else: ?>
                    <span class="badge bg-secondary ms-1 fs-6">⏸ Ngừng</span>
                    <?php endif; ?>
                </h4>
                <p class="text-muted mb-0 small">
                    <?= htmlspecialchars($vehicle['type_name']) ?>
                    <?= $vehicle['capacity'] ? ' • ' . $vehicle['capacity'] . ' tấn' : '' ?>
                    <?= $vehicle['fuel_quota'] ? ' • ' . $vehicle['fuel_quota'] . ' L/100km' : '' ?>
                </p>
            </div>
        </div>
        <?php if (can('vehicles', 'crud')): ?>
        <div class="d-flex gap-2">
            <a href="edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
                <i class="fas fa-edit me-1"></i> Sửa thông tin
            </a>
            <a href="maintenance/create.php?vehicle_id=<?= $id ?>" class="btn btn-warning btn-sm">
                <i class="fas fa-tools me-1"></i> Thêm bảo dưỡng
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $tab==='info' ? 'active' : '' ?>"
               href="?id=<?= $id ?>&tab=info">
                <i class="fas fa-info-circle me-1"></i> Thông tin xe
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='maintenance' ? 'active' : '' ?>"
               href="?id=<?= $id ?>&tab=maintenance">
                <i class="fas fa-tools me-1"></i> Bảo dưỡng
                <span class="badge bg-secondary ms-1"><?= count($maintenances) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $tab==='fuel' ? 'active' : '' ?>"
               href="?id=<?= $id ?>&tab=fuel">
                <i class="fas fa-gas-pump me-1"></i> Xăng dầu
                <span class="badge bg-secondary ms-1"><?= count($fuelLogs) ?></span>
            </a>
        </li>
    </ul>

    <!-- Tab: Thông tin -->
    <?php if ($tab === 'info'): ?>
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📋 Thông tin cơ bản</h6>
                </div>
                <div class="card-body">
                    <?php $rows = [
                        ['Biển số',       $vehicle['plate_number']],
                        ['Loại xe',       $vehicle['type_name']],
                        ['Tải trọng',     $vehicle['capacity'] ? $vehicle['capacity'].' tấn' : '—'],
                        ['Định mức xăng', $vehicle['fuel_quota'] ? $vehicle['fuel_quota'].' L/100km' : '—'],
                        ['Ghi chú',       $vehicle['note'] ?? '—'],
                    ];
                    foreach ($rows as [$label, $value]): ?>
                    <div class="d-flex py-2 border-bottom">
                        <div class="text-muted" style="width:150px"><?= $label ?></div>
                        <div class="fw-semibold"><?= htmlspecialchars($value) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-2">
                    <h6 class="fw-bold mb-0">📅 Thời hạn pháp lý</h6>
                </div>
                <div class="card-body">
                    <?php
                    $legalItems = [
                        ['📋 Đăng kiểm',   $vehicle['registration_expiry']],
                        ['🛡️ Bảo hiểm',    $vehicle['insurance_expiry']],
                        ['🛣️ Phí đường bộ', $vehicle['road_tax_expiry']],
                        ['🔥 PCCC',         $vehicle['fire_insurance_expiry']],
                    ];
                    foreach ($legalItems as [$label, $expiry]):
                        if (!$expiry) { $badgeClass = 'secondary'; $badgeText = 'Chưa nhập'; }
                        elseif ($expiry < date('Y-m-d')) { $badgeClass = 'danger'; $badgeText = '❌ Quá hạn'; }
                        elseif ($expiry < date('Y-m-d', strtotime('+30 days'))) { $badgeClass = 'warning'; $badgeText = '⚠️ Sắp hết hạn'; }
                        else { $badgeClass = 'success'; $badgeText = '✅ Còn hạn'; }
                    ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="fw-semibold"><?= $label ?></div>
                        <div class="text-end">
                            <?php if ($expiry): ?>
                            <div class="small"><?= date('d/m/Y', strtotime($expiry)) ?></div>
                            <?php endif; ?>
                            <span class="badge bg-<?= $badgeClass ?>"><?= $badgeText ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Chi phí tổng hợp -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div class="col-md-4">
                            <div class="text-muted small">Tổng chi phí bảo dưỡng</div>
                            <div class="fw-bold fs-5 text-warning">
                                <?= number_format($totalMaint, 0, '.', ',') ?> ₫
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Tổng chi phí xăng dầu</div>
                            <div class="fw-bold fs-5 text-info">
                                <?= number_format($totalFuel, 0, '.', ',') ?> ₫
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Tổng chi phí vận hành</div>
                            <div class="fw-bold fs-5 text-danger">
                                <?= number_format($totalMaint + $totalFuel, 0, '.', ',') ?> ₫
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Bảo dưỡng -->
    <?php elseif ($tab === 'maintenance'): ?>
    <div class="d-flex justify-content-between mb-3">
        <h6 class="fw-bold mb-0">🔧 Lịch sử bảo dưỡng / sửa chữa</h6>
        <?php if (can('vehicles', 'crud') || can('expenses', 'create')): ?>
        <a href="maintenance/create.php?vehicle_id=<?= $id ?>"
           class="btn btn-warning btn-sm">
            <i class="fas fa-plus me-1"></i> Thêm bảo dưỡng
        </a>
        <?php endif; ?>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">Ngày</th>
                            <th>Loại</th>
                            <th>Nội dung</th>
                            <th>Garage</th>
                            <th>Km</th>
                            <th>Chi phí phụ tùng</th>
                            <th>Nhân công</th>
                            <th>Tổng tiền</th>
                            <th>Trạng thái</th>
                            <?php if (can('expenses', 'approve')): ?>
                            <th>Thao tác</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($maintenances)): ?>
                    <tr><td colspan="10" class="text-center py-4 text-muted">Chưa có lịch sử bảo dưỡng</td></tr>
                    <?php endif; ?>
                    <?php foreach ($maintenances as $m):
                        $typeLabels = [
                            'repair'=>['danger','🔧 Sửa chữa'],
                            'scheduled'=>['primary','📅 Định kỳ'],
                            'tire'=>['warning','🔄 Lốp xe'],
                            'oil'=>['info','🛢️ Thay dầu'],
                            'other'=>['secondary','📌 Khác'],
                        ];
                        [$tc, $tl] = $typeLabels[$m['maintenance_type'] ?? 'other'] ?? ['secondary','Khác'];
                        $statusLabels = ['pending'=>['warning','⏳ Chờ duyệt'],'in_progress'=>['primary','🔧 Đang sửa'],'completed'=>['success','✅ Hoàn thành']];
                        [$sc, $sl] = $statusLabels[$m['status'] ?? 'completed'] ?? ['success','✅'];
                    ?>
                    <tr>
                        <td class="ps-3 small"><?= date('d/m/Y', strtotime($m['maintenance_date'])) ?></td>
                        <td><span class="badge bg-<?= $tc ?>"><?= $tl ?></span></td>
                        <td><?= htmlspecialchars($m['description']) ?></td>
                        <td class="small"><?= htmlspecialchars($m['garage_name'] ?? '—') ?></td>
                        <td class="small"><?= $m['odometer_km'] ? number_format($m['odometer_km'], 0).' km' : '—' ?></td>
                        <td><?= $m['parts_cost'] ? number_format($m['parts_cost'], 0, '.', ',').' ₫' : '—' ?></td>
                        <td><?= $m['labor_cost'] ? number_format($m['labor_cost'], 0, '.', ',').' ₫' : '—' ?></td>
                        <td class="fw-bold text-danger">
                            <?= number_format((float)$m['total_cost'], 0, '.', ',') ?> ₫
                        </td>
                        <td><span class="badge bg-<?= $sc ?>"><?= $sl ?></span></td>
                        <?php if (can('expenses', 'approve')): ?>
                        <td>
                            <a href="maintenance/edit.php?id=<?= $m['id'] ?>&vehicle_id=<?= $id ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-edit"></i>
                            </a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($maintenances)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="ps-3">Tổng cộng</td>
                            <td><?= number_format(array_sum(array_column($maintenances,'parts_cost')), 0, '.', ',') ?> ₫</td>
                            <td><?= number_format(array_sum(array_column($maintenances,'labor_cost')), 0, '.', ',') ?> ₫</td>
                            <td class="text-danger"><?= number_format($totalMaint, 0, '.', ',') ?> ₫</td>
                            <td colspan="<?= can('expenses','approve') ? 2 : 1 ?>"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Tab: Xăng dầu -->
    <?php elseif ($tab === 'fuel'): ?>
    <div class="d-flex justify-content-between mb-3">
        <h6 class="fw-bold mb-0">⛽ Lịch sử xăng dầu</h6>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3">Ngày</th>
                            <th>Lái xe</th>
                            <th>Số lít</th>
                            <th>Giá/lít</th>
                            <th>Tổng tiền</th>
                            <th>Km</th>
                            <th>Trạm xăng</th>
                            <th>Trạng thái</th>
                            <?php if (can('expenses', 'approve')): ?>
                            <th>Thao tác</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($fuelLogs)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">Chưa có dữ liệu xăng dầu</td></tr>
                    <?php endif; ?>
                    <?php foreach ($fuelLogs as $f): ?>
                    <tr>
                        <td class="ps-3 small"><?= date('d/m/Y', strtotime($f['log_date'])) ?></td>
                        <td class="small"><?= htmlspecialchars($f['driver_name'] ?? '—') ?></td>
                        <td><?= number_format((float)$f['liters'], 1) ?> L</td>
                        <td><?= $f['price_per_liter'] ? number_format($f['price_per_liter'], 0, '.', ',').' ₫' : '—' ?></td>
                        <td class="fw-bold"><?= $f['total_cost'] ? number_format($f['total_cost'], 0, '.', ',').' ₫' : '—' ?></td>
                        <td class="small"><?= $f['odometer_km'] ? number_format($f['odometer_km'], 0).' km' : '—' ?></td>
                        <td class="small"><?= htmlspecialchars($f['station_name'] ?? '—') ?></td>
                        <td>
                            <?php if ($f['is_approved']): ?>
                            <span class="badge bg-success">✅ Đã duyệt</span>
                            <?php else: ?>
                            <span class="badge bg-warning">⏳ Chờ duyệt</span>
                            <?php endif; ?>
                        </td>
                        <?php if (can('expenses', 'approve')): ?>
                        <td>
                            <?php if (!$f['is_approved']): ?>
                            <a href="fuel/edit.php?id=<?= $f['id'] ?>&vehicle_id=<?= $id ?>"
                               class="btn btn-sm btn-outline-success">
                                <i class="fas fa-check"></i> Duyệt
                            </a>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($fuelLogs)): ?>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="ps-3">Tổng cộng</td>
                            <td><?= number_format(array_sum(array_column($fuelLogs,'liters')), 1) ?> L</td>
                            <td></td>
                            <td class="text-danger"><?= number_format($totalFuel, 0, '.', ',') ?> ₫</td>
                            <td colspan="<?= can('expenses','approve') ? 4 : 3 ?>"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
</div>

<?php include '../../includes/footer.php'; ?>