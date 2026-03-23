<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
if (!can('expenses','create') && !can('expenses','approve') && !can('vehicles','view')) {
    requirePermission('expenses','view');
}

$pageTitle = 'Bảo dưỡng / Sửa chữa';
$pdo         = getDBConnection();
$currentUser = currentUser();
$canManage   = in_array($currentUser['role'] ?? '', ['admin', 'accountant']);

$filterVehicle = $_GET['vehicle'] ?? '';
$filterType    = $_GET['type']    ?? '';
$filterStatus  = $_GET['status']  ?? '';
$filterMonth   = $_GET['month']   ?? '';

$where  = ['1=1'];
$params = [];

if ($filterVehicle) {
    $where[]  = 'm.vehicle_id = ?';
    $params[] = (int)$filterVehicle;
}
if ($filterType) {
    $where[]  = 'm.maintenance_type = ?';
    $params[] = $filterType;
}
if ($filterStatus) {
    $where[]  = 'm.status = ?';
    $params[] = $filterStatus;
}
if ($filterMonth) {
    [$y, $mo] = explode('-', $filterMonth);
    $where[]  = 'EXTRACT(MONTH FROM COALESCE(m.maintenance_date, m.created_at)) = ?';
    $where[]  = 'EXTRACT(YEAR  FROM COALESCE(m.maintenance_date, m.created_at)) = ?';
    $params[] = (int)$mo;
    $params[] = (int)$y;
}

$whereStr = implode(' AND ', $where);

$records = $pdo->prepare("
    SELECT m.*,
           COALESCE(m.maintenance_date, m.created_at::DATE) AS mdate,
           v.plate_number,
           vt.name AS vehicle_type,
           u.full_name AS created_by_name,
           a.full_name AS approved_by_name
    FROM maintenance_logs m
    JOIN vehicles v       ON m.vehicle_id      = v.id
    JOIN vehicle_types vt ON v.vehicle_type_id = vt.id
    LEFT JOIN users u     ON m.created_by      = u.id
    LEFT JOIN users a     ON m.approved_by     = a.id
    WHERE $whereStr
    ORDER BY COALESCE(m.maintenance_date, m.created_at::DATE) DESC
");
$records->execute($params);
$records = $records->fetchAll();

$vehicles  = $pdo->query("SELECT id, plate_number FROM vehicles ORDER BY plate_number")->fetchAll();
$totalCost = array_sum(array_column($records, 'total_cost'));

// Xử lý approve
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_id'])) {
    $pdo->prepare("
        UPDATE maintenance_logs SET
            status      = 'completed',
            approved_by = ?,
            approved_at = NOW()
        WHERE id = ?
    ")->execute([$currentUser['id'], (int)$_POST['approve_id']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã duyệt!'];
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

// Xử lý xóa từ index
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id']) && $canManage) {
    $pdo->prepare("DELETE FROM maintenance_logs WHERE id = ?")
        ->execute([(int)$_POST['delete_id']]);
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xóa bản ghi bảo dưỡng!'];
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

include '../../../includes/header.php';
include '../../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">🔧 Bảo dưỡng / Sửa chữa</h4>
        <?php if (can('expenses','create') || can('vehicles','crud')): ?>
        <a href="create.php" class="btn btn-warning">
            <i class="fas fa-plus me-1"></i> Thêm bảo dưỡng
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
                <div class="col-md-3">
                    <select name="vehicle" class="form-select form-select-sm">
                        <option value="">-- Tất cả xe --</option>
                        <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>"
                            <?= $filterVehicle == $v['id'] ? 'selected' : '' ?>>
                            <?= $v['plate_number'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">-- Loại --</option>
                        <option value="repair"    <?= $filterType==='repair'   ?'selected':'' ?>>🔧 Sửa chữa</option>
                        <option value="scheduled" <?= $filterType==='scheduled'?'selected':'' ?>>📅 Định kỳ</option>
                        <option value="tire"      <?= $filterType==='tire'     ?'selected':'' ?>>🔄 Lốp xe</option>
                        <option value="oil"       <?= $filterType==='oil'      ?'selected':'' ?>>🛢️ Thay dầu</option>
                        <option value="other"     <?= $filterType==='other'    ?'selected':'' ?>>📌 Khác</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">-- Trạng thái --</option>
                        <option value="pending"     <?= $filterStatus==='pending'    ?'selected':'' ?>>⏳ Chờ duyệt</option>
                        <option value="in_progress" <?= $filterStatus==='in_progress'?'selected':'' ?>>🔧 Đang sửa</option>
                        <option value="completed"   <?= $filterStatus==='completed'  ?'selected':'' ?>>✅ Hoàn thành</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-primary me-1">Lọc</button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <span class="ms-2 text-muted small">
                        Tổng: <strong class="text-danger">
                            <?= number_format($totalCost, 0, '.', ',') ?> ₫
                        </strong>
                    </span>
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
                            <th>Loại</th>
                            <th>Nội dung</th>
                            <th>Garage</th>
                            <th>Km</th>
                            <th>Phụ tùng</th>
                            <th>Nhân công</th>
                            <th>Tổng tiền</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="11" class="text-center py-5 text-muted">
                            <i class="fas fa-tools fa-2x mb-2 d-block opacity-25"></i>
                            Chưa có dữ liệu bảo dưỡng
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php
                    $typeLabels = [
                        'repair'    => ['danger',   '🔧 Sửa chữa'],
                        'scheduled' => ['primary',  '📅 Định kỳ'],
                        'tire'      => ['warning',  '🔄 Lốp xe'],
                        'oil'       => ['info',     '🛢️ Thay dầu'],
                        'other'     => ['secondary','📌 Khác'],
                    ];
                    $statusLabels = [
                        'pending'     => ['warning', '⏳ Chờ duyệt'],
                        'in_progress' => ['primary', '🔧 Đang sửa'],
                        'completed'   => ['success', '✅ Hoàn thành'],
                    ];
                    foreach ($records as $m):
                        [$tc, $tl] = $typeLabels[$m['maintenance_type'] ?? 'other'] ?? ['secondary','Khác'];
                        [$sc, $sl] = $statusLabels[$m['status'] ?? 'completed']     ?? ['success','✅'];
                    ?>
                    <tr class="<?= $m['status']==='pending' ? 'table-warning bg-opacity-25' : '' ?>">
                        <td class="ps-3 small">
                            <?= date('d/m/Y', strtotime($m['mdate'])) ?>
                        </td>
                        <td>
                            <a href="/transport/modules/vehicles/detail.php?id=<?= $m['vehicle_id'] ?>&tab=maintenance"
                               class="fw-bold text-decoration-none">
                                <?= $m['plate_number'] ?>
                            </a>
                            <div class="text-muted" style="font-size:0.75rem"><?= $m['vehicle_type'] ?></div>
                        </td>
                        <td><span class="badge bg-<?= $tc ?>"><?= $tl ?></span></td>
                        <td><?= htmlspecialchars($m['description'] ?? '—') ?></td>
                        <td class="small"><?= htmlspecialchars($m['garage_name'] ?? '—') ?></td>
                        <td class="small">
                            <?= $m['odometer_km'] ? number_format($m['odometer_km'],0).' km' : '—' ?>
                        </td>
                        <td><?= $m['parts_cost'] ? number_format($m['parts_cost'],0,'.', ',').' ₫' : '—' ?></td>
                        <td><?= $m['labor_cost'] ? number_format($m['labor_cost'],0,'.', ',').' ₫' : '—' ?></td>
                        <td class="fw-bold text-danger">
                            <?= number_format((float)$m['total_cost'],0,'.', ',') ?> ₫
                        </td>
                        <td>
                            <span class="badge bg-<?= $sc ?>"><?= $sl ?></span>
                            <?php if ($m['approved_by_name']): ?>
                            <div class="text-muted" style="font-size:0.7rem">
                                <?= htmlspecialchars($m['approved_by_name']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <?php if ($canManage): ?>
                                <!-- Nút Sửa -->
                                <a href="edit.php?id=<?= $m['id'] ?>"
                                   class="btn btn-outline-primary" title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php endif; ?>

                                <!-- Nút Duyệt -->
                                <?php if (can('expenses','approve') && $m['status']==='pending'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="approve_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-outline-success"
                                            title="Duyệt"
                                            onclick="return confirm('Duyệt bảo dưỡng này?')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                </form>
                                <?php endif; ?>

                                <!-- Nút Xóa (chỉ admin/kế toán) -->
                                <?php if ($canManage): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger"
                                            title="Xóa"
                                            onclick="return confirm('⚠️ Xóa bản ghi bảo dưỡng này?\nHành động không thể hoàn tác!')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if (!empty($records)): ?>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="6" class="ps-3">
                                Tổng cộng (<?= count($records) ?> bản ghi)
                            </td>
                            <td><?= number_format(array_sum(array_column($records,'parts_cost')),0,'.', ',') ?> ₫</td>
                            <td><?= number_format(array_sum(array_column($records,'labor_cost')),0,'.', ',') ?> ₫</td>
                            <td class="text-danger">
                                <?= number_format($totalCost,0,'.', ',') ?> ₫
                            </td>
                            <td colspan="2"></td>
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