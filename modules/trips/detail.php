<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*,
           u_d.full_name   AS driver_name,
           u_d.phone       AS driver_phone,
           v.plate_number, v.capacity, vt.name AS vehicle_type,
           c.company_name, c.short_name, c.customer_code,
           u_c.full_name   AS confirmed_by_name,
           u_a.full_name   AS approved_by_name,
           u_r.full_name   AS rejected_by_name
    FROM trips t
    JOIN drivers d    ON t.driver_id    = d.id
    JOIN users u_d    ON d.user_id      = u_d.id
    JOIN vehicles v   ON t.vehicle_id   = v.id
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    LEFT JOIN customers c  ON t.customer_id  = c.id
    LEFT JOIN users u_c    ON t.confirmed_by = u_c.id
    LEFT JOIN users u_a    ON t.approved_by  = u_a.id
    LEFT JOIN users u_r    ON t.rejected_by  = u_r.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$trip = $stmt->fetch();
if (!$trip) { header('Location: index.php'); exit; }

$pageTitle = 'Chuyến ' . $trip['trip_code'];

$statusConfig = [
    'draft'     => ['secondary', '📝 Draft'],
    'submitted' => ['warning',   '📤 Đã gửi'],
    'completed' => ['primary',   '✅ Hoàn thành'],
    'confirmed' => ['success',   '👍 KH Duyệt'],
    'rejected'  => ['danger',    '❌ Từ chối'],
];
[$sCls, $sLbl] = $statusConfig[$trip['status']] ?? ['secondary', $trip['status']];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4" style="max-width:800px">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div>
                <h4 class="fw-bold mb-0">
                    🚛 <?= $trip['trip_code'] ?>
                    <span class="badge bg-<?= $sCls ?> fs-6 ms-1"><?= $sLbl ?></span>
                </h4>
                <div class="text-muted small">
                    <?= date('d/m/Y', strtotime($trip['trip_date'])) ?>
                </div>
            </div>
        </div>
        <?php if (can('trips','confirm') && $trip['status']==='submitted'): ?>
        <div class="d-flex gap-2">
            <a href="confirm.php?id=<?= $id ?>&action=approve"
               class="btn btn-success btn-sm"
               onclick="return confirm('Duyệt chuyến này?')">
                <i class="fas fa-check me-1"></i> Duyệt
            </a>
            <a href="confirm.php?id=<?= $id ?>&action=reject"
               class="btn btn-danger btn-sm">
                <i class="fas fa-times me-1"></i> Từ chối
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- Thông tin chuyến -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📋 Thông tin chuyến xe</h6>
        </div>
        <div class="card-body">
            <div class="row g-0">
                <?php
                $rows = [
                    ['Mã chuyến',     $trip['trip_code']],
                    ['Lái xe',        $trip['driver_name'] . ($trip['driver_phone'] ? ' — '.$trip['driver_phone'] : '')],
                    ['Biển số xe',    $trip['plate_number'] . ' (' . $trip['vehicle_type'] . ')'],
                    ['Tải trọng',     $trip['capacity'] ? $trip['capacity'].' tấn' : '—'],
                    ['Khách hàng',    '[' . $trip['customer_code'] . '] ' . ($trip['company_name'] ?? '—')],
                    ['Điểm đi',       strtoupper($trip['pickup_location'] ?? '—')],
                    ['Điểm đến',      strtoupper($trip['dropoff_location'] ?? '—')],
                    ['KM điểm đi',    $trip['odometer_start'] ? number_format($trip['odometer_start'],0).' km' : '—'],
                    ['KM kết thúc',   $trip['odometer_end']   ? number_format($trip['odometer_end'],0).' km'   : '—'],
                    ['Tổng KM',       $trip['total_km']       ? '<strong class="text-primary">' . number_format($trip['total_km'],0).' km</strong>' : '—'],
                    ['Vé cầu đường',  $trip['toll_fee']       ? number_format($trip['toll_fee'],0,'.', ',').' ₫' : '—'],
                    ['Ghi chú',       htmlspecialchars($trip['note'] ?? '—')],
                ];
                foreach ($rows as [$label, $value]):
                ?>
                <div class="col-md-6">
                    <div class="d-flex py-2 border-bottom px-2">
                        <div class="text-muted" style="width:130px;flex-shrink:0;font-size:0.85rem"><?= $label ?></div>
                        <div class="fw-semibold small"><?= $value ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Timeline trạng thái -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">📅 Timeline</h6>
        </div>
        <div class="card-body">
            <div class="timeline">
                <div class="d-flex gap-3 mb-3">
                    <div class="badge bg-primary rounded-circle d-flex align-items-center justify-content-center"
                         style="width:32px;height:32px;flex-shrink:0">1</div>
                    <div>
                        <div class="fw-semibold">Tạo chuyến</div>
                        <div class="small text-muted">
                            <?= date('d/m/Y H:i', strtotime($trip['created_at'])) ?>
                        </div>
                    </div>
                </div>

                <?php if ($trip['approved_at']): ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="badge bg-success rounded-circle d-flex align-items-center justify-content-center"
                         style="width:32px;height:32px;flex-shrink:0">2</div>
                    <div>
                        <div class="fw-semibold">
                            ✅ Quản lý duyệt — <?= $trip['approved_by_name'] ?>
                        </div>
                        <div class="small text-muted">
                            <?= date('d/m/Y H:i', strtotime($trip['approved_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($trip['confirmed_at']): ?>
                <div class="d-flex gap-3 mb-3">
                    <div class="badge bg-success rounded-circle d-flex align-items-center justify-content-center"
                         style="width:32px;height:32px;flex-shrink:0">3</div>
                    <div>
                        <div class="fw-semibold">
                            👍 Khách hàng confirm — <?= $trip['confirmed_by_name'] ?>
                        </div>
                        <div class="small text-muted">
                            <?= date('d/m/Y H:i', strtotime($trip['confirmed_at'])) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($trip['rejection_reason']): ?>
                <div class="d-flex gap-3">
                    <div class="badge bg-danger rounded-circle d-flex align-items-center justify-content-center"
                         style="width:32px;height:32px;flex-shrink:0">!</div>
                    <div>
                        <div class="fw-semibold text-danger">
                            ❌ Từ chối — <?= $trip['rejected_by_name'] ?>
                        </div>
                        <div class="small text-muted">
                            <?= $trip['rejected_at'] ? date('d/m/Y H:i', strtotime($trip['rejected_at'])) : '' ?>
                        </div>
                        <div class="alert alert-danger py-1 px-2 mt-1 small">
                            <?= htmlspecialchars($trip['rejection_reason']) ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../../includes/footer.php'; ?>