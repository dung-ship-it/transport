<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('customer')) { header('Location: /dashboard.php'); exit; }

$pdo  = getDBConnection();
$user = currentUser();

$cuStmt = $pdo->prepare("
    SELECT cu.*, c.company_name, c.short_name, c.customer_code, c.tax_code
    FROM customer_users cu JOIN customers c ON cu.customer_id = c.id
    WHERE cu.user_id = ? AND cu.is_active = TRUE LIMIT 1
");
$cuStmt->execute([$user['id']]);
$cu = $cuStmt->fetch();
if (!$cu) { exit('Chưa liên kết khách hàng!'); }

$customerId = $cu['customer_id'];
$cuRole     = $cu['role'];
$canApprove = in_array($cuRole, ['approver', 'admin_customer']);
$pageTitle  = 'Danh sách chuyến xe';

$filterStatus   = $_GET['status']    ?? '';
$filterVehicle  = $_GET['vehicle']   ?? '';
$filterMonth    = $_GET['month']     ?? date('Y-m');
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
[$year, $month] = explode('-', $filterMonth);

$where  = ['t.customer_id = ?'];
$params = [$customerId];

if ($filterDateFrom && $filterDateTo) {
    $where[]  = 't.trip_date BETWEEN ? AND ?';
    $params[] = $filterDateFrom;
    $params[] = $filterDateTo;
} else {
    $where[]  = 'EXTRACT(MONTH FROM t.trip_date) = ?';
    $where[]  = 'EXTRACT(YEAR  FROM t.trip_date) = ?';
    $params[] = (int)$month;
    $params[] = (int)$year;
}
if ($filterStatus)  { $where[] = 't.status = ?';     $params[] = $filterStatus; }
if ($filterVehicle) { $where[] = 't.vehicle_id = ?'; $params[] = (int)$filterVehicle; }

$whereStr = implode(' AND ', $where);

$trips = $pdo->prepare("
    SELECT t.*,
           u.full_name    AS driver_name,
           v.plate_number, v.capacity,
           cu2.full_name  AS confirmed_by_name
    FROM trips t
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN vehicles v  ON t.vehicle_id  = v.id
    LEFT JOIN users cu2 ON t.confirmed_by = cu2.id
    WHERE $whereStr
    ORDER BY t.trip_date DESC, t.id DESC
");
$trips->execute($params);
$trips = $trips->fetchAll();

$myVehicles = $pdo->prepare("
    SELECT DISTINCT v.id, v.plate_number
    FROM trips t JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.customer_id = ? ORDER BY v.plate_number
");
$myVehicles->execute([$customerId]);
$myVehicles = $myVehicles->fetchAll();

$statusConfig = [
    'draft'     => ['secondary', '📝 Draft'],
    'submitted' => ['warning',   '📤 Đã gửi'],
    'completed' => ['primary',   '✅ Hoàn thành'],
    'confirmed' => ['success',   '👍 Đã duyệt'],
    'rejected'  => ['danger',    '❌ Từ chối'],
];

$totalKm   = array_sum(array_column($trips, 'total_km'));
$totalToll = array_sum(array_column($trips, 'toll_fee'));

// ✅ include TRƯỚC khi xuất HTML
include '../includes/customer_header.php';
?>

<!-- Topbar -->
<div class="d-flex justify-content-between align-items-center px-3 py-2"
     style="background:#0f3460;color:#fff">
    <div class="fw-bold">🏢 <?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></div>
    <div class="d-flex gap-2">
        <a href="dashboard.php" class="btn btn-sm btn-outline-light">
            <i class="fas fa-home"></i>
        </a>
        <a href="/logout.php" class="btn btn-sm btn-outline-light">
            <i class="fas fa-sign-out-alt"></i>
        </a>
    </div>
</div>

<!-- Nav -->
<nav class="bg-white border-bottom px-3 py-1 d-flex gap-3 overflow-auto"
     style="font-size:0.88rem">
    <a href="dashboard.php" class="nav-link text-muted">
        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
    </a>
    <a href="trips.php" class="nav-link fw-semibold text-primary border-bottom border-primary border-2 pb-1">
        <i class="fas fa-route me-1"></i>Chuyến xe
    </a>
    <a href="reports.php" class="nav-link text-muted">
        <i class="fas fa-chart-bar me-1"></i>Báo cáo
    </a>
    <a href="print_statement.php" class="nav-link text-muted">
        <i class="fas fa-print me-1"></i>In bảng kê
    </a>
</nav>

<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0">📋 Bảng theo dõi tình hình sử dụng xe</h5>
        <a href="print_statement.php?month=<?= $filterMonth ?>"
           class="btn btn-sm btn-outline-secondary" target="_blank">
            <i class="fas fa-print me-1"></i>In bảng kê
        </a>
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
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $filterDateFrom ?>" placeholder="Từ ngày">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $filterDateTo ?>" placeholder="Đến ngày">
                </div>
                <div class="col-md-2">
                    <select name="vehicle" class="form-select form-select-sm">
                        <option value="">-- Tất cả xe --</option>
                        <?php foreach ($myVehicles as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $filterVehicle==$v['id']?'selected':'' ?>>
                            <?= $v['plate_number'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <?php foreach ($statusConfig as $val => [$cls, $lbl]): ?>
                        <option value="<?= $val ?>" <?= $filterStatus===$val?'selected':'' ?>>
                            <?= $lbl ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary me-1">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                    <a href="trips.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bảng -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="text-center py-3 border-bottom">
                <div class="fw-bold">CÔNG TY TNHH DNA EXPRESS VIỆT NAM</div>
                <div class="small text-muted">
                    Địa chỉ: Cụm công nghiệp Hạp Lĩnh, Phường Hạp Lĩnh, Tỉnh Bắc Ninh, Việt Nam
                </div>
                <div class="small text-muted">MST: 0107514537</div>
                <h6 class="fw-bold mt-2 mb-0">BẢNG THEO DÕI TÌNH HÌNH SỬ DỤNG XE</h6>
                <div class="small text-muted">
                    Khách hàng: <strong><?= htmlspecialchars($cu['company_name']) ?></strong>
                    <?php if ($filterDateFrom && $filterDateTo): ?>
                    | Từ <?= date('d/m/Y', strtotime($filterDateFrom)) ?>
                    đến <?= date('d/m/Y', strtotime($filterDateTo)) ?>
                    <?php else: ?>
                    | Tháng <?= $month ?>/<?= $year ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0"
                       style="font-size:0.8rem">
                    <thead style="background:#fff3cd">
                        <tr class="text-center">
                            <th style="width:35px">STT</th>
                            <th>Người lái</th>
                            <th>Ngày</th>
                            <th class="text-danger fw-bold">BIỂN SỐ XE<br><small>(Bắt buộc)</small></th>
                            <th>TẢI TRỌNG</th>
                            <th class="text-danger fw-bold">Khách Hàng<br><small>(Bắt buộc)</small></th>
                            <th class="text-danger fw-bold">Điểm Đi<br><small>(Bắt buộc)</small></th>
                            <th class="text-danger fw-bold">Điểm Đến 1<br><small>(Bắt buộc)</small></th>
                            <th class="text-danger fw-bold">Số KM điểm đi<br><small>(Bắt buộc)</small></th>
                            <th class="text-danger fw-bold">Số KM kết thúc<br><small>(Bắt buộc)</small></th>
                            <th class="text-danger fw-bold">Tổng KM<br>tuyến đường</th>
                            <th>Vé Cầu Đường</th>
                            <th>Ghi chú</th>
                            <th>Trạng Thái</th>
                            <th>Khách Hàng Duyệt</th>
                            <?php if ($canApprove): ?>
                            <th class="text-center">Thao tác</th>
                            <?php endif; ?>
                        </tr>
                        <tr class="text-center text-muted" style="font-size:0.72rem;background:#fffde7">
                            <td>1</td>
                            <td>Tự động lấy theo tài khoản đăng nhập (không thể sửa)</td>
                            <td>Tự động theo ngày (có thể sửa)</td>
                            <td>DROP CHỌN (từ danh sách xe điều hành tạo)</td>
                            <td>Tự Động LẤY THEO BIỂN SỐ (không sửa)</td>
                            <td>DROP CHỌN (từ danh sách các khách hàng tạo)</td>
                            <td>Viết hoa hết</td>
                            <td>Viết hoa hết</td>
                            <td colspan="3">Tự tính</td>
                            <td></td><td></td>
                            <td>Hoàn Thành / Từ Chối</td>
                            <td>Users của khách hàng duyệt (họ tên, ngày giờ)</td>
                            <?php if ($canApprove): ?><td></td><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($trips)): ?>
                    <tr>
                        <td colspan="<?= $canApprove ? 16 : 15 ?>"
                            class="text-center py-5 text-muted">Không có dữ liệu</td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($trips as $i => $t):
                        [$sCls, $sLbl] = $statusConfig[$t['status']] ?? ['secondary', $t['status']];
                    ?>
                    <tr class="<?= $t['status']==='rejected' ? 'table-danger bg-opacity-10' : ($t['status']==='confirmed' ? 'table-success bg-opacity-10' : '') ?>">
                        <td class="text-center text-muted"><?= $i + 1 ?></td>
                        <td><div class="fw-semibold"><?= htmlspecialchars($t['driver_name']) ?></div></td>
                        <td class="text-center text-nowrap">
                            <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                            <?php if ($t['is_sunday']): ?>
                            <br><span class="badge bg-warning" style="font-size:0.65rem">Chủ nhật</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center fw-bold text-primary"><?= htmlspecialchars($t['plate_number']) ?></td>
                        <td class="text-center"><?= $t['capacity'] ? $t['capacity'].' tấn' : '—' ?></td>
                        <td class="text-center"><?= htmlspecialchars($cu['short_name'] ?: $cu['company_name']) ?></td>
                        <td class="text-uppercase fw-semibold"><?= htmlspecialchars($t['pickup_location'] ?? '—') ?></td>
                        <td class="text-uppercase fw-semibold"><?= htmlspecialchars($t['dropoff_location'] ?? '—') ?></td>
                        <td class="text-end"><?= $t['odometer_start'] ? number_format($t['odometer_start'],0) : '—' ?></td>
                        <td class="text-end"><?= $t['odometer_end']   ? number_format($t['odometer_end'],0)   : '—' ?></td>
                        <td class="text-end fw-bold text-primary">
                            <?= $t['total_km'] ? number_format($t['total_km'],0).' km' : '—' ?>
                        </td>
                        <td class="text-end">
                            <?= $t['toll_fee'] ? number_format($t['toll_fee'],0,'.', ',').' ₫' : '—' ?>
                        </td>
                        <td class="small text-muted"><?= htmlspecialchars($t['note'] ?? '') ?></td>
                        <td class="text-center">
                            <span class="badge bg-<?= $sCls ?>"><?= $sLbl ?></span>
                            <?php if ($t['rejection_reason']): ?>
                            <div class="text-danger small mt-1"
                                 title="<?= htmlspecialchars($t['rejection_reason']) ?>">
                                ❌ <?= mb_substr($t['rejection_reason'],0,25) ?>...
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
                        <?php if ($canApprove): ?>
                        <td class="text-center text-nowrap">
                            <?php if ($t['status'] === 'completed'): ?>
                            <a href="trip_confirm.php?id=<?= $t['id'] ?>&action=confirm"
                               class="btn btn-success btn-sm"
                               style="font-size:0.72rem;padding:2px 6px"
                               onclick="return confirm('Duyệt chuyến này?')">
                                <i class="fas fa-check"></i> Duyệt
                            </a>
                            <a href="trip_confirm.php?id=<?= $t['id'] ?>&action=reject"
                               class="btn btn-outline-danger btn-sm"
                               style="font-size:0.72rem;padding:2px 6px">
                                <i class="fas fa-times"></i> Từ chối
                            </a>
                            <?php elseif ($t['status'] === 'confirmed'): ?>
                            <span class="text-success small">✅ Đã duyệt</span>
                            <?php elseif ($t['status'] === 'rejected'): ?>
                            <span class="text-danger small">❌ Đã từ chối</span>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($trips)): ?>
                    <tfoot style="background:#f8f9fa;font-weight:bold">
                        <tr>
                            <td colspan="10" class="text-end">
                                Tổng (<?= count($trips) ?> chuyến):
                            </td>
                            <td class="text-end text-primary"><?= number_format($totalKm,0) ?> km</td>
                            <td class="text-end"><?= number_format($totalToll,0,'.', ',') ?> ₫</td>
                            <td colspan="<?= $canApprove ? 4 : 3 ?>"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include '../includes/customer_footer.php'; // ✅ ?>