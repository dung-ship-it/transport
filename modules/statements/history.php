<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Lịch sử bảng kê';

// Lấy danh sách kỳ đã chốt (COALESCE cho các cột có thể chưa tồn tại)
try {
    $periods = $pdo->query("
        SELECT
            sp.id,
            sp.period_from,
            sp.period_to,
            COALESCE(sp.period_label, 'Kỳ ' || to_char(sp.period_from,'DD/MM/YYYY') || ' - ' || to_char(sp.period_to,'DD/MM/YYYY')) AS period_label,
            COALESCE(sp.status, 'draft')      AS status,
            COALESCE(sp.total_amount, 0)      AS total_amount,
            COALESCE(sp.total_trips, 0)       AS total_trips,
            COALESCE(sp.total_km, 0)          AS total_km,
            COALESCE(sp.customer_count, 0)    AS customer_count,
            sp.locked_at,
            sp.created_at,
            u1.full_name AS locked_by_name,
            u2.full_name AS created_by_name
        FROM statement_periods sp
        LEFT JOIN users u1 ON sp.locked_by  = u1.id
        LEFT JOIN users u2 ON sp.created_by = u2.id
        ORDER BY sp.period_from DESC
    ")->fetchAll();
} catch (\Exception $e) {
    $periods = [];
    $dbError = $e->getMessage();
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">📋 Lịch sử bảng kê đã chốt</h4>
            <small class="text-muted">Danh sách các kỳ đã được chốt công nợ</small>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus me-1"></i>Tạo bảng kê mới
            </a>
        </div>
    </div>

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Lỗi database: <?= htmlspecialchars($dbError) ?>
        <hr>
        <small>Hãy chạy file <code>migration_statements.sql</code> để tạo các cột còn thiếu.</small>
    </div>
    <?php endif; ?>

    <!-- Tổng quan -->
    <?php if (!empty($periods)):
        $totalLocked = count(array_filter($periods, fn($p) => $p['status'] === 'locked'));
        $totalRevenue = array_sum(array_column(
            array_filter($periods, fn($p) => $p['status'] === 'locked'),
            'total_amount'
        ));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-2 fw-bold text-success"><?= $totalLocked ?></div>
                <div class="small text-muted">Kỳ đã chốt</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
                <div class="fs-2 fw-bold text-warning"><?= count($periods) - $totalLocked ?></div>
                <div class="small text-muted">Kỳ nháp</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
                <div class="fs-4 fw-bold text-primary"><?= number_format($totalRevenue, 0, '.', ',') ?> ₫</div>
                <div class="small text-muted">Tổng doanh thu đã chốt</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Tên kỳ</th>
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
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                        Chưa có kỳ nào được tạo
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($periods as $p): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $p['id'] ?></td>
                    <td class="fw-semibold">
                        <?= htmlspecialchars($p['period_label'] ?? '—') ?>
                    </td>
                    <td class="text-nowrap"><?= date('d/m/Y', strtotime($p['period_from'])) ?></td>
                    <td class="text-nowrap"><?= date('d/m/Y', strtotime($p['period_to'])) ?></td>
                    <td class="text-center">
                        <span class="badge bg-info"><?= $p['customer_count'] ?></span>
                    </td>
                    <td class="text-center"><?= number_format($p['total_trips']) ?></td>
                    <td class="text-end small"><?= number_format($p['total_km'], 0) ?> km</td>
                    <td class="text-end fw-bold <?= $p['status']==='locked' ? 'text-success' : 'text-muted' ?>">
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
                    <td class="small text-muted text-nowrap">
                        <?= $p['locked_at'] ? date('d/m/Y H:i', strtotime($p['locked_at'])) : '—' ?>
                    </td>
                    <td class="text-center" style="white-space:nowrap">
                        <!-- Xem chi tiết trong trang báo cáo -->
                        <a href="../reports/index.php?tab=revenue&date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                           class="btn btn-xs btn-outline-success" title="Xem báo cáo doanh thu">
                            <i class="fas fa-chart-bar"></i>
                        </a>
                        <!-- Mở lại bảng kê -->
                        <a href="index.php?date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                           class="btn btn-xs btn-outline-primary" title="Mở bảng kê">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($p['status'] === 'locked'): ?>
                        <!-- Nút in -->
                        <a href="index.php?date_from=<?= $p['period_from'] ?>&date_to=<?= $p['period_to'] ?>"
                           class="btn btn-xs btn-outline-secondary" title="In bảng kê">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>
<style>
.btn-xs { padding: 3px 8px; font-size: 11px; border-radius: 4px; }
</style>
<?php include '../../includes/footer.php'; ?>