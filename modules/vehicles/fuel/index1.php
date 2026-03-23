<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('expenses', 'approve');

$pageTitle = 'Quản lý xăng dầu';
$pdo = getDBConnection();

$filterStatus  = $_GET['status']  ?? '';
$filterVehicle = $_GET['vehicle'] ?? '';
$filterMonth   = $_GET['month']   ?? date('Y-m');
[$year, $month] = explode('-', $filterMonth);

$where  = ["EXTRACT(MONTH FROM f.log_date)=?", "EXTRACT(YEAR FROM f.log_date)=?"];
$params = [(int)$month, (int)$year];

if ($filterStatus === 'pending') {
    $where[] = 'f.is_approved = FALSE';
} elseif ($filterStatus === 'approved') {
    $where[] = 'f.is_approved = TRUE';
}
if ($filterVehicle) {
    $where[]  = 'f.vehicle_id = ?';
    $params[] = (int)$filterVehicle;
}

$whereStr = implode(' AND ', $where);

$logs = $pdo->prepare("
    SELECT f.*,
           v.plate_number, v.fuel_quota,
           u.full_name  AS driver_name,
           a.full_name  AS approved_by_name
    FROM fuel_logs f
    JOIN vehicles v  ON f.vehicle_id = v.id
    JOIN drivers d   ON f.driver_id  = d.id
    JOIN users u     ON d.user_id    = u.id
    LEFT JOIN users a ON f.approved_by = a.id
    WHERE $whereStr
    ORDER BY f.is_approved ASC, f.log_date DESC
");
$logs->execute($params);
$logs = $logs->fetchAll();

$vehicles = $pdo->query("SELECT id, plate_number FROM vehicles WHERE is_active=TRUE ORDER BY plate_number")->fetchAll();

// Xử lý approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    $approveId = (int)$_POST['approve_id'];
    $pdo->prepare("
        UPDATE fuel_logs SET
            is_approved = TRUE,
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ")->execute([currentUser()['id'], $approveId]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã duyệt xăng dầu!'];
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">⛽ Quản lý xăng dầu</h4>
        <span class="badge bg-warning fs-6">
            <?= count(array_filter($logs, fn($l) => !$l['is_approved'])) ?> chờ duyệt
        </span>
    </div>

    <?php showFlash(); ?>

    <!-- Bộ lọc -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2">
                <div class="col-md-2">
                    <input type="month" name="month" class="form-control form-control-sm"
                           value="<?= $filterMonth ?>">
                </div>
                <div class="col-md-3">
                    <select name="vehicle" class="form-select form-select-sm">
                        <option value="">-- Tất cả xe --</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            <?= $filterVehicle==$v['id']?'selected':'' ?>>
                            <?= $v['plate_number'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <option value="pending"  <?= $filterStatus==='pending' ?'selected':'' ?>>⏳ Chờ duyệt</option>
                        <option value="approved" <?= $filterStatus==='approved'?'selected':'' ?>>✅ Đã duyệt</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary me-1">Lọc</button>
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
                            <th class="ps-3">Ngày</th>
                            <th>Xe</th>
                            <th>Lái xe</th>
                            <th>Km trước</th>
                            <th>Km sau</th>
                            <th>Km đi</th>
                            <th>Số lít</th>
                            <th>Số tiền</th>
                            <th>₫/lít</th>
                            <th>L/100km</th>
                            <th>So ĐM</th>
                            <th>Hóa đơn</th>
                            <th>Trạng thái</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="14" class="text-center py-4 text-muted">Không có dữ liệu</td></tr>
                    <?php endif; ?>
                    <?php foreach ($logs as $log):
                        // So sánh với định mức
                        $effClass = '';
                        $effIcon  = '';
                        if ($log['fuel_efficiency'] && $log['fuel_quota']) {
                            if ($log['fuel_efficiency'] > $log['fuel_quota'] * 1.1) {
                                $effClass = 'text-danger fw-bold';
                                $effIcon  = '⚠️';
                            } elseif ($log['fuel_efficiency'] > $log['fuel_quota']) {
                                $effClass = 'text-warning fw-bold';
                                $effIcon  = '🔶';
                            } else {
                                $effClass = 'text-success';
                                $effIcon  = '✅';
                            }
                        }
                    ?>
                    <tr class="<?= !$log['is_approved'] ? 'table-warning bg-opacity-25' : '' ?>">
                        <td class="ps-3 small">
                            <?= date('d/m/Y', strtotime($log['log_date'])) ?>
                        </td>
                        <td class="fw-bold"><?= $log['plate_number'] ?></td>
                        <td class="small"><?= htmlspecialchars($log['driver_name']) ?></td>
                        <td><?= $log['km_before'] ? number_format($log['km_before'],0) : '—' ?></td>
                        <td><?= $log['km_after']  ? number_format($log['km_after'], 0) : '—' ?></td>
                        <td><?= $log['km_driven'] ? number_format($log['km_driven'],0).' km' : '—' ?></td>
                        <td><?= $log['liters_filled'] ?> L</td>
                        <td class="fw-semibold"><?= number_format($log['amount'],0,'.', ',') ?> ₫</td>
                        <td class="small"><?= $log['price_per_liter'] ? number_format($log['price_per_liter'],0,'.', ',') : '—' ?></td>
                        <td class="<?= $effClass ?>">
                            <?= $effIcon ?>
                            <?= $log['fuel_efficiency'] ? $log['fuel_efficiency'].' L' : '—' ?>
                        </td>
                        <td class="small text-muted">
                            ĐM: <?= $log['fuel_quota'] ? $log['fuel_quota'].' L' : '—' ?>
                        </td>
                        <td>
                            <?php if ($log['receipt_img']): ?>
                            <a href="<?= htmlspecialchars($log['receipt_img']) ?>"
                               target="_blank" class="btn btn-xs btn-outline-info btn-sm">
                                <i class="fas fa-image"></i>
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['is_approved']): ?>
                            <span class="badge bg-success">✅ Đã duyệt</span>
                            <div class="small text-muted"><?= $log['approved_by_name'] ?></div>
                            <?php else: ?>
                            <span class="badge bg-warning">⏳ Chờ duyệt</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$log['is_approved']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="approve_id" value="<?= $log['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success"
                                        onclick="return confirm('Duyệt xăng dầu này?')">
                                    <i class="fas fa-check me-1"></i>Duyệt
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($logs)): ?>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="6" class="ps-3">Tổng tháng <?= $month ?>/<?= $year ?></td>
                            <td><?= number_format(array_sum(array_column($logs,'liters_filled')),1) ?> L</td>
                            <td><?= number_format(array_sum(array_column($logs,'amount')),0,'.', ',') ?> ₫</td>
                            <td colspan="6"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../../../includes/footer.php'; ?>