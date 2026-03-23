<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();

// Kiểm tra quyền
$roleStmt = $pdo->prepare("SELECT r.name FROM roles r JOIN users u ON u.role_id = r.id WHERE u.id = ?");
$roleStmt->execute([$user['id']]);
$userRole = strtolower($roleStmt->fetchColumn() ?? '');
$allowedRoles = ['admin', 'superadmin', 'accountant', 'ketoan', 'ke_toan', 'manager'];
if (!in_array($userRole, $allowedRoles) && !can('statements', 'view')) {
    header('Location: /transport/dashboard.php'); exit;
}

// Lấy danh sách kỳ — dùng COALESCE để handle null
$periods = $pdo->query("
    SELECT
        sp.id,
        COALESCE(sp.period_label,
            TO_CHAR(sp.period_from,'DD/MM/YYYY') || ' – ' || TO_CHAR(sp.period_to,'DD/MM/YYYY')
        )                           AS period_label,
        sp.period_from,
        sp.period_to,
        COALESCE(sp.customer_count, 0) AS customer_count,
        COALESCE(sp.total_trips,    0) AS total_trips,
        COALESCE(sp.total_km,       0) AS total_km,
        COALESCE(sp.total_amount,   0) AS total_amount,
        sp.status,
        sp.locked_at,
        sp.created_at,
        u1.full_name AS locked_by_name,
        u2.full_name AS created_by_name
    FROM statement_periods sp
    LEFT JOIN users u1 ON sp.locked_by  = u1.id
    LEFT JOIN users u2 ON sp.created_by = u2.id
    ORDER BY sp.period_from DESC
")->fetchAll();

$pageTitle = 'Lịch sử bảng kê';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">📋 Lịch sử bảng kê đã chốt</h4>
            <small class="text-muted"><?= count($periods) ?> kỳ</small>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>Tạo bảng kê mới
            </a>
            <a href="/transport/modules/reports/index.php?tab=revenue" class="btn btn-info btn-sm">
                <i class="fas fa-chart-bar me-1"></i>Báo cáo tổng hợp
            </a>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- Stat cards -->
    <?php if (!empty($periods)): ?>
    <?php
    $lockedPeriods = array_filter($periods, fn($p) => $p['status'] === 'locked');
    $totalRevenue  = array_sum(array_column($lockedPeriods, 'total_amount'));
    $totalTripsAll = array_sum(array_column($lockedPeriods, 'total_trips'));
    $totalKmAll    = array_sum(array_column($lockedPeriods, 'total_km'));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
                <div class="fs-2 fw-bold text-primary"><?= count($lockedPeriods) ?></div>
                <div class="small text-muted">Kỳ đã chốt</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-info border-4">
                <div class="fs-2 fw-bold text-info"><?= number_format($totalTripsAll) ?></div>
                <div class="small text-muted">Tổng chuyến</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-2 fw-bold text-success"><?= number_format($totalKmAll, 0) ?> km</div>
                <div class="small text-muted">Tổng KM</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
                <div class="fs-2 fw-bold text-warning"><?= number_format($totalRevenue, 0, '.', ',') ?> ₫</div>
                <div class="small text-muted">Tổng doanh thu</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle" style="font-size:0.88rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Kỳ bảng kê</th>
                        <th>Từ ngày</th>
                        <th>Đến ngày</th>
                        <th class="text-center">KH</th>
                        <th class="text-center">Chuyến</th>
                        <th class="text-end">Tổng KM</th>
                        <th class="text-end">Tổng tiền</th>
                        <th>Trạng thái</th>
                        <th>Người chốt</th>
                        <th>Ngày chốt</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($periods)): ?>
                <tr>
                    <td colspan="12" class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
                        Chưa có kỳ nào được chốt
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($periods as $p): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $p['id'] ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($p['period_label']) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['period_from'])) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['period_to'])) ?></td>
                    <td class="text-center">
                        <span class="badge bg-info"><?= $p['customer_count'] ?></span>
                    </td>
                    <td class="text-center fw-semibold"><?= number_format($p['total_trips']) ?></td>
                    <td class="text-end text-primary"><?= number_format($p['total_km'], 0) ?> km</td>
                    <td class="text-end fw-bold text-success">
                        <?= number_format($p['total_amount'], 0, '.', ',') ?> ₫
                    </td>
                    <td>
                        <?php if ($p['status'] === 'locked'): ?>
                        <span class="badge bg-success">🔒 Đã chốt</span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">📝 Nháp</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= htmlspecialchars($p['locked_by_name'] ?? '—') ?></td>
                    <td class="small text-muted">
                        <?= $p['locked_at'] ? date('d/m/Y H:i', strtotime($p['locked_at'])) : '—' ?>
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <a href="index.php?date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                               class="btn btn-outline-secondary" title="Mở bảng kê">
                                <i class="fas fa-folder-open"></i>
                            </a>
                            <a href="/transport/modules/reports/index.php?tab=revenue&date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                               class="btn btn-outline-primary" title="Xem báo cáo">
                                <i class="fas fa-chart-bar"></i>
                            </a>
                            <?php if ($p['status'] === 'locked'): ?>
                            <a href="/transport/modules/reports/index.php?tab=pl&date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                               class="btn btn-outline-info" title="Lãi/lỗ kỳ này">
                                <i class="fas fa-balance-scale"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <?php if (!empty($lockedPeriods ?? [])): ?>
                <tfoot class="table-dark">
                    <tr>
                        <td class="ps-3 fw-bold" colspan="5">TỔNG (<?= count($lockedPeriods) ?> kỳ đã chốt)</td>
                        <td class="text-center fw-bold"><?= number_format($totalTripsAll) ?></td>
                        <td class="text-end fw-bold"><?= number_format($totalKmAll, 0) ?> km</td>
                        <td class="text-end fw-bold text-warning"><?= number_format($totalRevenue, 0, '.', ',') ?> ₫</td>
                        <td colspan="4"></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
            </div>
        </div>
    </div>
</div>
</div>
<style>.btn-xs { padding: 2px 8px; font-size: 12px; }</style>
<?php include '../../includes/footer.php'; ?>