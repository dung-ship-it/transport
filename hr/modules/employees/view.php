<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo       = getDBConnection();
$user      = currentUser();
$id        = (int)($_GET['id'] ?? 0);
$pageTitle = 'Hồ sơ nhân viên';

// ── Lấy thông tin nhân viên ───────────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name, r.label AS role_label
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp || $emp['role_name'] === 'customer') {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy nhân viên.'];
    header('Location: index.php');
    exit;
}

$canEdit = can('hr','manage');

// ── Thông tin lái xe nếu là driver ───────────────────────────
$driverInfo = null;
if ($emp['role_name'] === 'driver') {
    $driverInfo = $pdo->prepare("
        SELECT d.*, COUNT(t.id) AS total_trips,
               COALESCE(SUM(t.total_km),0) AS total_km,
               COALESCE(SUM(t.total_amount),0) AS total_revenue
        FROM drivers d
        LEFT JOIN trips t ON t.driver_id = d.id AND t.status IN ('completed','confirmed')
        WHERE d.user_id = ?
        GROUP BY d.id
    ");
    $driverInfo->execute([$id]);
    $driverInfo = $driverInfo->fetch();
}

// ── Cấu hình lương ────────────────────────────────────────────
$salaryConfig = null;
if (can('hr','manage')) {
    $scStmt = $pdo->prepare("SELECT * FROM hr_salary_configs WHERE user_id = ? AND is_active = TRUE LIMIT 1");
    $scStmt->execute([$id]);
    $salaryConfig = $scStmt->fetch();
}

// ── Lịch sử chấm công gần nhất (7 ngày) ──────────────────────
$attendanceRecent = [];
try {
    $attStmt = $pdo->prepare("
        SELECT * FROM hr_attendance
        WHERE user_id = ?
        ORDER BY work_date DESC LIMIT 10
    ");
    $attStmt->execute([$id]);
    $attendanceRecent = $attStmt->fetchAll();
} catch (Exception $e) { /* bảng chưa có */ }

// ── Thâm niên ─────────────────────────────────────────────────
$tenure = '—';
if ($emp['hire_date']) {
    $months = (int)((time() - strtotime($emp['hire_date'])) / (30.44 * 86400));
    $years  = floor($months / 12);
    $rem    = $months % 12;
    $tenure = $years > 0 ? "{$years} năm {$rem} tháng" : "{$months} tháng";
}

// ── Helpers hiển thị ─────────────────────────────────────────
$genderMap  = ['male'=>'👨 Nam','female'=>'👩 Nữ','other'=>'Khác'];
$maritalMap = ['single'=>'Độc thân','married'=>'Đã kết hôn','divorced'=>'Ly hôn','widowed'=>'Góa'];

function showVal($v, string $default = '—'): string {
    return $v ? htmlspecialchars($v) : "<span class='text-muted'>$default</span>";
}

$badge = getRoleBadge($emp['role_name']);

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- Tiêu đề -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-3">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h4 class="mb-0">👤 Hồ sơ nhân viên</h4>
            <div class="d-flex align-items-center gap-2 mt-1">
                <span class="badge bg-<?= $badge['class'] ?>">
                    <?= $badge['icon'] ?> <?= htmlspecialchars($emp['role_label'] ?: $emp['role_name']) ?>
                </span>
                <strong><?= htmlspecialchars($emp['full_name']) ?></strong>
                <code class="small"><?= htmlspecialchars($emp['employee_code'] ?? '') ?></code>
                <span class="badge <?= $emp['is_active'] ? 'bg-success' : 'bg-danger' ?>">
                    <?= $emp['is_active'] ? '✅ Đang làm' : '🔒 Đã nghỉ' ?>
                </span>
            </div>
        </div>
    </div>
    <?php if ($canEdit): ?>
    <div class="d-flex gap-2">
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">
            <i class="fas fa-edit me-1"></i>Chỉnh sửa
        </a>
    </div>
    <?php endif; ?>
</div>

<?php showFlash(); ?>

<div class="row g-4">

<!-- CỘT TRÁI: Avatar + tóm tắt -->
<div class="col-md-3">

    <div class="card border-0 shadow-sm mb-3 text-center">
        <div class="card-body py-4">
            <div class="rounded-circle mx-auto d-flex align-items-center justify-content-center fw-bold
                        bg-<?= $badge['class'] ?> text-white mb-3"
                 style="width:72px;height:72px;font-size:2rem">
                <?= mb_strtoupper(mb_substr($emp['full_name'],0,1)) ?>
            </div>
            <h6 class="fw-bold mb-1"><?= htmlspecialchars($emp['full_name']) ?></h6>
            <div class="text-muted small mb-2">
                <code><?= htmlspecialchars($emp['employee_code'] ?? '—') ?></code>
            </div>
            <span class="badge bg-<?= $badge['class'] ?> mb-2">
                <?= $badge['icon'] ?> <?= htmlspecialchars($emp['role_label'] ?: $emp['role_name']) ?>
            </span>
            <div class="text-muted small"><?= $genderMap[$emp['gender']] ?? '—' ?></div>
        </div>
    </div>

    <!-- Quick stats -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between mb-2">
                <small class="text-muted">Ngày vào làm</small>
                <small class="fw-semibold">
                    <?= $emp['hire_date'] ? date('d/m/Y',strtotime($emp['hire_date'])) : '—' ?>
                </small>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <small class="text-muted">Thâm niên</small>
                <small class="fw-semibold text-primary"><?= $tenure ?></small>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <small class="text-muted">Hôn nhân</small>
                <small><?= $maritalMap[$emp['marital_status']] ?? '—' ?></small>
            </div>
            <?php if ($salaryConfig): ?>
            <hr class="my-2">
            <div class="d-flex justify-content-between">
                <small class="text-muted">Lương CB</small>
                <small class="fw-semibold text-success">
                    <?= formatMoney((float)($salaryConfig['base_salary'] ?? 0)) ?>
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <?php if ($canEdit): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-3 d-grid gap-2">
            <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-edit me-1"></i>Chỉnh sửa hồ sơ
            </a>
            <?php if ($emp['is_active']): ?>
            <a href="delete.php?id=<?= $id ?>&action=deactivate"
               class="btn btn-outline-warning btn-sm"
               onclick="return confirm('Cho nhân viên này nghỉ việc?')">
                <i class="fas fa-user-slash me-1"></i>Cho nghỉ việc
            </a>
            <?php else: ?>
            <a href="delete.php?id=<?= $id ?>&action=activate"
               class="btn btn-outline-success btn-sm"
               onclick="return confirm('Kích hoạt lại nhân viên này?')">
                <i class="fas fa-user-check me-1"></i>Kích hoạt lại
            </a>
            <?php endif; ?>
            <a href="delete.php?id=<?= $id ?>&action=delete"
               class="btn btn-outline-danger btn-sm"
               onclick="return confirm('⚠️ Xóa vĩnh viễn nhân viên này? Không thể khôi phục!')">
                <i class="fas fa-trash me-1"></i>Xóa nhân viên
            </a>
        </div>
    </div>
    <?php endif; ?>

</div><!-- col-md-3 -->

<!-- CỘT PHẢI: Chi tiết -->
<div class="col-md-9">

    <!-- Section 1: Thông tin cơ bản -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2">
            <i class="fas fa-user me-2 text-primary"></i>Thông tin cơ bản
        </div>
        <div class="card-body">
            <div class="row g-3" style="font-size:.88rem">
                <div class="col-md-3">
                    <div class="text-muted small">Mã nhân viên</div>
                    <div class="fw-semibold"><code><?= showVal($emp['employee_code']) ?></code></div>
                </div>
                <div class="col-md-5">
                    <div class="text-muted small">Họ và tên</div>
                    <div class="fw-semibold"><?= showVal($emp['full_name']) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Giới tính</div>
                    <div><?= $genderMap[$emp['gender']] ?? '—' ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Hôn nhân</div>
                    <div><?= $maritalMap[$emp['marital_status']] ?? '—' ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Ngày sinh</div>
                    <div><?= $emp['date_of_birth'] ? date('d/m/Y',strtotime($emp['date_of_birth'])) : '—' ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Dân tộc</div>
                    <div><?= showVal($emp['ethnicity']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Email</div>
                    <div><?= showVal($emp['email']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Điện thoại</div>
                    <div><?= showVal($emp['phone']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Ngày vào làm</div>
                    <div><?= $emp['hire_date'] ? date('d/m/Y',strtotime($emp['hire_date'])) : '—' ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Thâm niên</div>
                    <div class="text-primary fw-semibold"><?= $tenure ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Username</div>
                    <div><code><?= showVal($emp['username']) ?></code></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Giấy tờ -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2">
            <i class="fas fa-id-badge me-2 text-info"></i>Giấy tờ tùy thân
        </div>
        <div class="card-body">
            <div class="row g-3" style="font-size:.88rem">
                <div class="col-md-4">
                    <div class="text-muted small">Số CMND / CCCD</div>
                    <div class="fw-semibold font-monospace"><?= showVal($emp['id_number']) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Ngày cấp</div>
                    <div><?= $emp['id_issue_date'] ? date('d/m/Y',strtotime($emp['id_issue_date'])) : '—' ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Nơi cấp</div>
                    <div><?= showVal($emp['id_issue_place']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Số sổ BHXH</div>
                    <div><?= showVal($emp['social_insurance']) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Mã số thuế cá nhân</div>
                    <div><?= showVal($emp['tax_code']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Địa chỉ -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2">
            <i class="fas fa-home me-2 text-warning"></i>Địa chỉ
        </div>
        <div class="card-body">
            <div class="row g-3" style="font-size:.88rem">
                <div class="col-md-6">
                    <div class="fw-semibold mb-1 text-primary">🏠 Thường trú</div>
                    <?php
                    $permParts = array_filter([
                        $emp['permanent_address'],
                        $emp['permanent_street'],
                        $emp['permanent_district'],
                        $emp['permanent_province'],
                    ]);
                    echo $permParts ? htmlspecialchars(implode(', ', $permParts)) : '<span class="text-muted">Chưa cập nhật</span>';
                    ?>
                </div>
                <div class="col-md-6">
                    <div class="fw-semibold mb-1 text-secondary">📍 Tạm trú</div>
                    <?php
                    if ($emp['temp_same_as_permanent']) {
                        echo '<span class="text-muted">Giống địa chỉ thường trú</span>';
                    } else {
                        $tempParts = array_filter([
                            $emp['temp_address'],
                            $emp['temp_street'],
                            $emp['temp_district'],
                            $emp['temp_province'],
                        ]);
                        echo $tempParts ? htmlspecialchars(implode(', ', $tempParts)) : '<span class="text-muted">Chưa cập nhật</span>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 4: Ngân hàng -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2">
            <i class="fas fa-university me-2 text-success"></i>Tài khoản ngân hàng
        </div>
        <div class="card-body">
            <?php if ($emp['bank_account']): ?>
            <div class="row g-3" style="font-size:.88rem">
                <div class="col-md-4">
                    <div class="text-muted small">Ngân hàng</div>
                    <div class="fw-semibold"><?= showVal($emp['bank_name']) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Số tài khoản</div>
                    <div class="fw-semibold font-monospace d-flex align-items-center gap-2">
                        <?= htmlspecialchars($emp['bank_account']) ?>
                        <button class="btn btn-xs btn-outline-secondary"
                                style="padding:1px 6px;font-size:.7rem"
                                onclick="navigator.clipboard.writeText('<?= htmlspecialchars($emp['bank_account']) ?>');this.textContent='✓'">
                            copy
                        </button>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Chi nhánh</div>
                    <div><?= showVal($emp['bank_branch']) ?></div>
                </div>
            </div>
            <?php else: ?>
            <div class="text-muted">Chưa cập nhật thông tin ngân hàng</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Section 5: Lái xe (nếu role = driver) -->
    <?php if ($driverInfo): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2">
            <i class="fas fa-truck me-2 text-success"></i>Thông tin lái xe
        </div>
        <div class="card-body">
            <div class="row g-3 text-center">
                <div class="col-md-3">
                    <div class="text-muted small">GPLX</div>
                    <div class="fw-semibold font-monospace"><?= showVal($driverInfo['license_number']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Loại GPLX</div>
                    <div><?= showVal($driverInfo['license_class']) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Tổng chuyến</div>
                    <div class="fw-bold text-primary"><?= number_format((int)$driverInfo['total_trips']) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Tổng KM</div>
                    <div class="fw-bold text-info"><?= number_format((float)$driverInfo['total_km'],0) ?></div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Doanh thu</div>
                    <div class="fw-bold text-success"><?= formatMoney((float)$driverInfo['total_revenue']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section 6: Cấu hình lương -->
    <?php if ($salaryConfig && can('hr','manage')): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between">
            <span><i class="fas fa-money-bill-wave me-2 text-success"></i>Cấu hình lương</span>
            <a href="../payroll/salary_config.php?user_id=<?= $id ?>"
               class="btn btn-outline-success btn-sm" style="font-size:.75rem;padding:2px 10px">
                <i class="fas fa-edit me-1"></i>Cấu hình
            </a>
        </div>
        <div class="card-body">
            <div class="row g-3 text-center" style="font-size:.88rem">
                <div class="col-md-3">
                    <div class="text-muted small">Lương cơ bản</div>
                    <div class="fw-bold text-success"><?= formatMoney((float)($salaryConfig['base_salary']??0)) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Phụ cấp</div>
                    <div class="fw-bold text-info"><?= formatMoney((float)($salaryConfig['allowance']??0)) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Phòng ban</div>
                    <div><?= showVal($salaryConfig['department']) ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Chức danh</div>
                    <div><?= showVal($salaryConfig['position']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section 7: Chấm công gần nhất -->
    <?php if (!empty($attendanceRecent)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-bold py-2 d-flex justify-content-between">
            <span><i class="fas fa-clock me-2 text-primary"></i>Chấm công gần nhất</span>
            <a href="../attendance/index.php?user_id=<?= $id ?>"
               class="btn btn-outline-primary btn-sm" style="font-size:.75rem;padding:2px 10px">
                Xem tất cả
            </a>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Ngày</th>
                        <th class="text-center">Check in</th>
                        <th class="text-center">Check out</th>
                        <th class="text-center">Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $attStatusMap = [
                    'present'  => ['class'=>'success','label'=>'Có mặt'],
                    'late'     => ['class'=>'warning','label'=>'Đi muộn'],
                    'absent'   => ['class'=>'danger', 'label'=>'Vắng'],
                    'half_day' => ['class'=>'info',   'label'=>'Nửa ngày'],
                    'leave'    => ['class'=>'secondary','label'=>'Nghỉ phép'],
                ];
                foreach ($attendanceRecent as $att):
                    $as = $attStatusMap[$att['status']] ?? ['class'=>'secondary','label'=>$att['status']];
                ?>
                <tr>
                    <td class="ps-3"><?= date('d/m/Y', strtotime($att['work_date'])) ?></td>
                    <td class="text-center">
                        <?= $att['check_in'] ? date('H:i', strtotime($att['check_in'])) : '—' ?>
                    </td>
                    <td class="text-center">
                        <?= $att['check_out'] ? date('H:i', strtotime($att['check_out'])) : '—' ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= $as['class'] ?>"><?= $as['label'] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div><!-- col-md-9 -->
</div><!-- row -->

</div>
</div>

<?php include '../../includes/footer.php'; ?>