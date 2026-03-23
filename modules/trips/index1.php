<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('trips', 'view_all');

$pageTitle = 'Tất cả chuyến xe';
$pdo = getDBConnection();

$filterStatus   = $_GET['status']   ?? '';
$filterDriver   = $_GET['driver']   ?? '';
$filterCustomer = $_GET['customer'] ?? '';
$filterMonth    = $_GET['month']    ?? date('Y-m');
$search         = trim($_GET['q']   ?? '');

[$year, $month] = explode('-', $filterMonth);

$where  = [
    "EXTRACT(MONTH FROM t.trip_date) = ?",
    "EXTRACT(YEAR  FROM t.trip_date) = ?",
];
$params = [(int)$month, (int)$year];

if ($filterStatus) {
    $where[]  = 't.status = ?';
    $params[] = $filterStatus;
}
if ($filterDriver) {
    $where[]  = 't.driver_id = ?';
    $params[] = (int)$filterDriver;
}
if ($filterCustomer) {
    $where[]  = 't.customer_id = ?';
    $params[] = (int)$filterCustomer;
}
if ($search) {
    $where[]  = "(t.trip_code ILIKE ? OR v.plate_number ILIKE ? OR t.pickup_location ILIKE ?)";
    $params   = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$whereStr = implode(' AND ', $where);

$trips = $pdo->prepare("
    SELECT t.*,
           u_d.full_name   AS driver_name,
           v.plate_number,
           v.capacity,
           c.company_name  AS customer_name,
           c.short_name    AS customer_short,
           u_c.full_name   AS confirmed_by_name
    FROM trips t
    JOIN drivers d    ON t.driver_id    = d.id
    JOIN users u_d    ON d.user_id      = u_d.id
    JOIN vehicles v   ON t.vehicle_id   = v.id
    LEFT JOIN customers c  ON t.customer_id = c.id
    LEFT JOIN users u_c    ON t.confirmed_by = u_c.id
    WHERE $whereStr
    ORDER BY t.trip_date DESC, t.id DESC
");
$trips->execute($params);
$trips = $trips->fetchAll();

$drivers = $pdo->query("
    SELECT d.id, u.full_name FROM drivers d JOIN users u ON d.user_id = u.id
    WHERE u.is_active = TRUE ORDER BY u.full_name
")->fetchAll();

$customers = $pdo->query("
    SELECT id, short_name, company_name FROM customers WHERE is_active=TRUE ORDER BY company_name
")->fetchAll();

// Status labels
$statusConfig = [
    'draft'     => ['secondary', '📝 Draft'],
    'submitted' => ['warning',   '📤 Đã gửi'],
    'completed' => ['primary',   '✅ Hoàn thành'],
    'confirmed' => ['success',   '👍 KH Duyệt'],
    'rejected'  => ['danger',    '❌ Từ chối'],
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">📋 Bảng theo dõi tình hình sử dụng xe</h4>
        <?php if (can('trips','create')): ?>
        <a href="create.php" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> Tạo chuyến mới
        </a>
        <?php endif; ?>
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
                <div class="col-md-2">
                    <select name="driver" class="form-select form-select-sm">
                        <option value="">-- Lái xe --</option>
                        <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= $filterDriver==$d['id']?'selected':'' ?>>
                            <?= htmlspecialchars($d['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="customer" class="form-select form-select-sm">
                        <option value="">-- Khách hàng --</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= $filterCustomer==$c['id']?'selected':'' ?>>
                            <?= htmlspecialchars($c['short_name'] ?: $c['company_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <?php foreach ($statusConfig as $val => [$cls, $lbl]): ?>
                        <option value="<?= $val ?>"
                            <?= $filterStatus===$val?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="text" name="q" class="form-control form-control-sm"
                           placeholder="🔍 Biển số, mã..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary me-1">Lọc</button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng theo dõi — theo file ảnh -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <!-- Header công ty -->
            <div class="text-center py-3 border-bottom">
                <div class="fw-bold">CÔNG TY TNHH DNA EXPRESS VIỆT NAM</div>
                <div class="small text-muted">
                    Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam
                </div>
                <h6 class="fw-bold mt-2">
                    BẢNG THEO DÕI TÌNH HÌNH SỬ DỤNG XE
                    — Tháng <?= $month ?>/<?= $year ?>
                </h6>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0"
                       style="font-size:0.82rem">
                    <thead style="background:#f8f9fa">
                        <tr class="text-center">
                            <th rowspan="2" class="align-middle" style="width:35px">STT</th>
                            <th rowspan="2" class="align-middle">Người lái</th>
                            <th rowspan="2" class="align-middle">Ngày</th>
                            <th rowspan="2" class="align-middle text-danger">
                                BIỂN SỐ XE<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle">TẢI TRỌNG</th>
                            <th rowspan="2" class="align-middle text-danger">
                                Khách Hàng<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle text-danger">
                                Điểm Đi<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle text-danger">
                                Điểm Đến 1<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle text-danger">
                                Số KM điểm đi<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle text-danger">
                                Số KM điểm kết thúc<br><small>(Bắt buộc)</small>
                            </th>
                            <th rowspan="2" class="align-middle text-danger">
                                Tổng số KM<br>tuyến đường
                            </th>
                            <th rowspan="2" class="align-middle">Vé Cầu Đường</th>
                            <th rowspan="2" class="align-middle">Ghi chú</th>
                            <th rowspan="2" class="align-middle">Trạng Thái</th>
                            <th rowspan="2" class="align-middle">Khách Hàng Duyệt</th>
                            <?php if (can('trips','confirm') || can('trips','view_all')): ?>
                            <th rowspan="2" class="align-middle text-center">Thao tác</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="16" class="text-center py-4 text-muted">
                            Không có dữ liệu tháng <?= $month ?>/<?= $year ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($trips as $i => $t):
                        [$sCls, $sLbl] = $statusConfig[$t['status']] ?? ['secondary', $t['status']];
                    ?>
                    <tr class="<?= $t['status']==='rejected'?'table-danger bg-opacity-10':'' ?>">
                        <td class="text-center text-muted small"><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($t['driver_name']) ?></div>
                            <div class="text-muted" style="font-size:0.72rem">
                                <?= $t['trip_code'] ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                            <?php if ($t['is_sunday']): ?>
                            <span class="badge bg-warning" title="Chủ nhật">CN</span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold text-primary text-center">
                            <?= htmlspecialchars($t['plate_number']) ?>
                        </td>
                        <td class="text-center">
                            <?= $t['capacity'] ? $t['capacity'].' tấn' : '—' ?>
                        </td>
                        <td>
                            <?= htmlspecialchars($t['customer_short'] ?: $t['customer_name'] ?? '—') ?>
                        </td>
                        <td class="text-uppercase">
                            <?= htmlspecialchars($t['pickup_location'] ?? '—') ?>
                        </td>
                        <td class="text-uppercase">
                            <?= htmlspecialchars($t['dropoff_location'] ?? '—') ?>
                        </td>
                        <td class="text-end">
                            <?= $t['odometer_start'] ? number_format($t['odometer_start'],0) : '—' ?>
                        </td>
                        <td class="text-end">
                            <?= $t['odometer_end'] ? number_format($t['odometer_end'],0) : '—' ?>
                        </td>
                        <td class="text-end fw-bold text-primary">
                            <?= $t['total_km'] ? number_format($t['total_km'],0).' km' : '—' ?>
                        </td>
                        <td class="text-end">
                            <?= $t['toll_fee'] ? number_format($t['toll_fee'],0,'.', ',').' ₫' : '—' ?>
                        </td>
                        <td class="small text-muted">
                            <?= htmlspecialchars($t['note'] ?? '—') ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-<?= $sCls ?>"><?= $sLbl ?></span>
                            <?php if ($t['rejection_reason']): ?>
                            <div class="text-danger small mt-1" title="<?= htmlspecialchars($t['rejection_reason']) ?>">
                                ❌ <?= mb_substr($t['rejection_reason'], 0, 30) ?>...
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($t['confirmed_by_name']): ?>
                            <div class="fw-semibold small text-success">
                                ✅ <?= htmlspecialchars($t['confirmed_by_name']) ?>
                            </div>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= $t['confirmed_at'] ? date('d/m/Y H:i', strtotime($t['confirmed_at'])) : '' ?>
                            </div>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if (can('trips','confirm') || can('trips','view_all')): ?>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <a href="detail.php?id=<?= $t['id'] ?>"
                                   class="btn btn-outline-info" title="Chi tiết">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (can('trips','confirm') && $t['status']==='submitted'): ?>
                                <a href="confirm.php?id=<?= $t['id'] ?>&action=approve"
                                   class="btn btn-outline-success" title="Duyệt"
                                   onclick="return confirm('Duyệt chuyến này?')">
                                    <i class="fas fa-check"></i>
                                </a>
                                <a href="confirm.php?id=<?= $t['id'] ?>&action=reject"
                                   class="btn btn-outline-danger" title="Từ chối"
                                   onclick="return confirm('Từ chối chuyến này?')">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($trips)): ?>
                    <tfoot style="background:#f8f9fa;font-weight:bold">
                        <tr>
                            <td colspan="10" class="text-end ps-3">
                                Tổng cộng (<?= count($trips) ?> chuyến):
                            </td>
                            <td class="text-end text-primary">
                                <?= number_format(array_sum(array_column($trips,'total_km')),0) ?> km
                            </td>
                            <td class="text-end">
                                <?= number_format(array_sum(array_column($trips,'toll_fee')),0,'.', ',') ?> ₫
                            </td>
                            <td colspan="<?= (can('trips','confirm')||can('trips','view_all'))?4:3 ?>"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</div>
</div>

<?php include '../../includes/footer.php'; ?>