<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo  = getDBConnection();
$user = currentUser();
$pageTitle = 'Chấm công HR';

$today     = date('Y-m-d');
$viewMonth = (int)($_GET['month'] ?? date('m'));
$viewYear  = (int)($_GET['year']  ?? date('Y'));

if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

// Kiểm tra quyền xem tất cả
$canViewAll = can('hr', 'manage');

// Nếu không phải manager trở lên → chỉ xem của mình
$viewUserId = $canViewAll
    ? (int)($_GET['user_id'] ?? $user['id'])
    : $user['id'];

function hrCol(PDO $pdo, string $sql, array $p = []): mixed {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        $v = $s->fetchColumn();
        return $v === false ? 0 : $v;
    } catch (Exception $e) { return 0; }
}
function hrQuery(PDO $pdo, string $sql, array $p = []): array {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── Xử lý chấm công thủ công ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && verifyCSRF($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'];
    $now    = date('Y-m-d H:i:s');
    $uid    = $user['id'];

    if ($action === 'check_in') {
        try {
            $pdo->prepare("
                INSERT INTO hr_attendance (user_id, work_date, check_in, status, source)
                VALUES (?, ?, ?, 'present', 'manual')
                ON CONFLICT (user_id, work_date) DO NOTHING
            ")->execute([$uid, $today, $now]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã chấm công VÀO lúc ' . date('H:i')];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Lỗi chấm công: ' . $e->getMessage()];
        }
    } elseif ($action === 'check_out') {
    try {
        $pdo->prepare("
            UPDATE hr_attendance
            SET check_out  = ?,
                work_hours = LEAST(
                    ROUND(EXTRACT(EPOCH FROM (?::timestamp - check_in)) / 3600, 2),
                    24.00
                )
            WHERE user_id = ? AND work_date = ? AND check_out IS NULL
        ")->execute([$now, $now, $uid, $today]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã chấm công RA lúc ' . date('H:i')];
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Lỗi chấm công: ' . $e->getMessage()];
    }
}
    header('Location: index.php');
    exit;
}

// ── Chấm công hôm nay (luôn của user hiện tại) ──────────────
$todayAttRows = hrQuery($pdo,
    "SELECT * FROM hr_attendance WHERE user_id = ? AND work_date = ? LIMIT 1",
    [$user['id'], $today]);
$todayLog = $todayAttRows[0] ?? null;

// ── Chấm công tháng xem ─────────────────────────────────────
$monthLogs = hrQuery($pdo,
    "SELECT * FROM hr_attendance
     WHERE user_id = ?
       AND EXTRACT(MONTH FROM work_date) = ?
       AND EXTRACT(YEAR  FROM work_date) = ?
     ORDER BY work_date",
    [$viewUserId, $viewMonth, $viewYear]);
$logsMap = [];
foreach ($monthLogs as $l) $logsMap[$l['work_date']] = $l;

// ── Nghỉ phép được duyệt ────────────────────────────────────
$leaves = hrQuery($pdo,
    "SELECT * FROM hr_leaves
     WHERE user_id = ? AND status = 'approved'
       AND (EXTRACT(MONTH FROM date_from) = ? OR EXTRACT(MONTH FROM date_to) = ?)
       AND (EXTRACT(YEAR  FROM date_from) = ? OR EXTRACT(YEAR  FROM date_to) = ?)",
    [$viewUserId, $viewMonth, $viewMonth, $viewYear, $viewYear]);
$leaveDays = [];
foreach ($leaves as $lv) {
    $s = strtotime($lv['date_from']);
    $e = strtotime($lv['date_to']);
    for ($d = $s; $d <= $e; $d += 86400)
        $leaveDays[date('Y-m-d', $d)] = $lv['leave_type'];
}

// ── Thống kê tháng ──────────────────────────────────────────
$totalWork  = count(array_filter($monthLogs, fn($l) => !empty($l['check_in'])));
$totalHours = (float)array_sum(array_column($monthLogs, 'work_hours'));
$lateDays   = count(array_filter($monthLogs, fn($l) => !empty($l['is_late'])));

// ── Danh sách nhân viên (chỉ khi canViewAll) ────────────────
$empList = $canViewAll ? hrQuery($pdo,
    "SELECT u.id, u.full_name, u.employee_code
     FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.is_active = TRUE AND r.name NOT IN ('customer')
     ORDER BY u.full_name") : [];

// ── Thông tin user đang xem ──────────────────────────────────
$viewUser = $user;
if ($viewUserId !== $user['id']) {
    $rows = hrQuery($pdo, "SELECT * FROM users WHERE id = ? LIMIT 1", [$viewUserId]);
    if ($rows) $viewUser = $rows[0];
}

// ── OT đang chờ duyệt của user (hiện thị nhanh) ─────────────
$pendingOT = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_overtime WHERE user_id = ? AND status = 'pending'",
    [$user['id']]);

// ── Nghỉ phép đang chờ duyệt ────────────────────────────────
$pendingLeave = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_leaves WHERE user_id = ? AND status = 'pending'",
    [$user['id']]);

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">⏰ Chấm công</h4>
        <small class="text-muted">
            <?= htmlspecialchars($viewUser['full_name']) ?> · <?= date('l, d/m/Y') ?>
        </small>
    </div>
    <?php if ($canViewAll): ?>
    <div class="d-flex gap-2">
        <a href="all.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-table me-1"></i>Bảng tổng hợp
        </a>
    </div>
    <?php endif; ?>
</div>

<?php showFlash(); ?>

<!-- ── Bộ lọc nhân viên (chỉ manager+) ── -->
<?php if ($canViewAll && !empty($empList)): ?>
<div class="card border-0 shadow-sm mb-3">
<div class="card-body py-2">
<form method="GET" class="row g-2 align-items-end">
    <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Nhân viên</label>
        <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ($empList as $e): ?>
            <option value="<?= $e['id'] ?>" <?= $e['id'] == $viewUserId ? 'selected' : '' ?>>
                <?= htmlspecialchars($e['employee_code'] . ' - ' . $e['full_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Tháng</label>
        <select name="month" class="form-select form-select-sm">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m == $viewMonth ? 'selected' : '' ?>>Tháng <?= $m ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1">Năm</label>
        <select name="year" class="form-select form-select-sm">
            <?php for ($y = date('Y') - 1; $y <= date('Y') + 1; $y++): ?>
            <option value="<?= $y ?>" <?= $y == $viewYear ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Xem</button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
</form>
</div>
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── Cột trái ── -->
    <div class="col-lg-4">

        <!-- Chấm công hôm nay (chỉ hiện khi xem chính mình) -->
        <?php if ($viewUserId === $user['id']): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-primary text-white py-2">
                <h6 class="mb-0 fw-bold">📅 Hôm nay — <?= date('d/m/Y') ?></h6>
            </div>
            <div class="card-body text-center py-4">
                <div class="row mb-3">
                    <div class="col-6 border-end">
                        <div class="text-muted small mb-1">Giờ vào</div>
                        <div class="fs-4 fw-bold <?= $todayLog && $todayLog['check_in'] ? 'text-success' : 'text-muted' ?>">
                            <?= $todayLog && $todayLog['check_in']
                                ? date('H:i', strtotime($todayLog['check_in']))
                                : '--:--' ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted small mb-1">Giờ ra</div>
                        <div class="fs-4 fw-bold <?= $todayLog && $todayLog['check_out'] ? 'text-danger' : 'text-muted' ?>">
                            <?= $todayLog && $todayLog['check_out']
                                ? date('H:i', strtotime($todayLog['check_out']))
                                : '--:--' ?>
                        </div>
                    </div>
                </div>

                <?php if ($todayLog && $todayLog['check_out']): ?>
                <div class="alert alert-success py-2">
                    ✅ Hoàn thành ca · <strong><?= $todayLog['work_hours'] ?>h</strong>
                    <?php if (!empty($todayLog['is_late'])): ?>
                    <br><small class="text-warning">⚡ Đi trễ hôm nay</small>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fas fa-info-circle me-1"></i>Chấm công thủ công
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRF() ?>">
                    <?php if (!$todayLog || !$todayLog['check_in']): ?>
                        <input type="hidden" name="action" value="check_in">
                        <button type="submit" class="btn btn-success btn-lg w-100"
                                onclick="return confirm('Chấm công VÀO lúc '+new Date().toLocaleTimeString('vi-VN')+'?')">
                            <i class="fas fa-sign-in-alt me-2"></i>Chấm công VÀO
                        </button>
                    <?php elseif (!$todayLog['check_out']): ?>
                        <div class="alert alert-info py-2 small mb-2">
                            Đã vào: <?= date('H:i', strtotime($todayLog['check_in'])) ?>
                        </div>
                        <input type="hidden" name="action" value="check_out">
                        <button type="submit" class="btn btn-danger btn-lg w-100"
                                onclick="return confirm('Chấm công RA lúc '+new Date().toLocaleTimeString('vi-VN')+'?')">
                            <i class="fas fa-sign-out-alt me-2"></i>Chấm công RA
                        </button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Thống kê tháng -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">📊 Tháng <?= $viewMonth ?>/<?= $viewYear ?></h6>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="fas fa-check-circle text-success me-2"></i>Ngày công</span>
                    <strong><?= $totalWork ?> ngày</strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="fas fa-clock text-primary me-2"></i>Tổng giờ làm</span>
                    <strong><?= number_format($totalHours, 1) ?> giờ</strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="fas fa-exclamation-circle text-warning me-2"></i>Đi trễ</span>
                    <strong class="text-warning"><?= $lateDays ?> lần</strong>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span><i class="fas fa-umbrella-beach text-info me-2"></i>Nghỉ phép</span>
                    <strong class="text-info"><?= count($leaveDays) ?> ngày</strong>
                </li>
            </ul>
        </div>

        <!-- Quick actions -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">⚡ Thao tác nhanh</h6>
            </div>
            <div class="card-body p-2">
                <div class="d-grid gap-2">
                    <a href="../overtime/request.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-business-time me-2"></i>Đăng ký tăng ca (OT)
                        <?php if ($pendingOT > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $pendingOT ?> chờ</span>
                        <?php endif; ?>
                    </a>
                    <a href="../leave/request.php" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-calendar-minus me-2"></i>Xin nghỉ phép
                        <?php if ($pendingLeave > 0): ?>
                        <span class="badge bg-warning text-dark ms-1"><?= $pendingLeave ?> chờ</span>
                        <?php endif; ?>
                    </a>
                    <?php if ($canViewAll): ?>
                    <hr class="my-1">
                    <a href="../overtime/manage.php" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-clipboard-check me-2"></i>Duyệt OT
                    </a>
                    <a href="../leave/manage.php" class="btn btn-outline-info btn-sm">
                        <i class="fas fa-calendar-check me-2"></i>Duyệt nghỉ phép
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Cột phải: Lịch tháng ── -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <a href="?user_id=<?= $viewUserId ?>&month=<?= $viewMonth - 1 ?>&year=<?= $viewYear ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <h6 class="mb-0 fw-bold">
                    📅 Tháng <?= $viewMonth ?>/<?= $viewYear ?>
                    <?php if ($viewUserId !== $user['id']): ?>
                    <small class="text-muted fw-normal">— <?= htmlspecialchars($viewUser['full_name']) ?></small>
                    <?php endif; ?>
                </h6>
                <a href="?user_id=<?= $viewUserId ?>&month=<?= $viewMonth + 1 ?>&year=<?= $viewYear ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <div class="card-body p-2">
                <!-- Chú thích -->
                <div class="d-flex flex-wrap gap-2 mb-2 px-1">
                    <span class="badge-legend bg-success text-white">✅ Đúng giờ</span>
                    <span class="badge-legend bg-warning text-dark">⚠️ Trễ</span>
                    <span class="badge-legend bg-info text-white">🏖️ Phép</span>
                    <span class="badge-legend bg-danger text-white">❌ Vắng</span>
                    <span class="badge-legend bg-light text-muted border">— CN</span>
                </div>

                <table class="table table-bordered calendar-table mb-0" style="font-size:12px;">
                    <thead class="table-dark">
                        <tr>
                            <th>T2</th><th>T3</th><th>T4</th>
                            <th>T5</th><th>T6</th><th>T7</th>
                            <th class="text-danger">CN</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $firstDay    = mktime(0, 0, 0, $viewMonth, 1, $viewYear);
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
                    $startDow    = (int)date('N', $firstDay); // 1=Mon..7=Sun

                    echo '<tr>';
                    for ($i = 1; $i < $startDow; $i++) echo '<td class="bg-light"></td>';

                    $col = $startDow;
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $ds      = sprintf('%04d-%02d-%02d', $viewYear, $viewMonth, $day);
                        $dow     = (int)date('N', mktime(0, 0, 0, $viewMonth, $day, $viewYear));
                        $isToday = ($ds === date('Y-m-d'));
                        $isSun   = ($dow === 7);
                        $log     = $logsMap[$ds] ?? null;
                        $isLeave = isset($leaveDays[$ds]);
                        $future  = ($ds > date('Y-m-d'));

                        $bg = '';
                        $content = '';

                        if ($isSun) {
                            $bg = 'bg-light';
                            $content = '<small class="text-muted">CN</small>';
                        } elseif ($future) {
                            $content = '';
                        } elseif ($isLeave && !$log) {
                            $bg = 'leave-cell';
                            $content = '<div class="text-info fw-bold small">🏖️ Phép</div>';
                        } elseif ($log && !empty($log['check_in'])) {
                            $bg = !empty($log['is_late']) ? 'late-cell' : 'present-cell';
                            $ci = date('H:i', strtotime($log['check_in']));
                            $co = !empty($log['check_out']) ? date('H:i', strtotime($log['check_out'])) : '?';
                            $content = '<div class="att-time">
                                <span class="badge bg-success badge-sm">▶' . $ci . '</span><br>
                                <span class="badge bg-danger badge-sm mt-1">◼' . $co . '</span>
                            </div>';
                        } else {
                            $bg = 'absent-cell';
                            $content = '<div class="text-danger fw-bold">❌</div>';
                        }

                        $todayClass = $isToday ? 'border border-primary border-2' : '';
                        $numClass   = $isToday ? 'fw-bold text-primary' : '';
                        echo "<td class='calendar-day $bg $todayClass'>
                            <div class='day-number $numClass'>$day</div>
                            $content
                        </td>";

                        if ($col % 7 === 0 && $day < $daysInMonth) echo '</tr><tr>';
                        $col++;
                    }
                    while ($col % 7 !== 1) { echo '<td class="bg-light"></td>'; $col++; }
                    echo '</tr>';
                    ?>
                    </tbody>
                </table>
            </div>

            <!-- Ghi chú chi tiết tháng -->
            <?php if (!empty($monthLogs)): ?>
            <div class="card-footer bg-white py-2">
                <div class="row text-center g-2">
                    <div class="col-3">
                        <div class="small text-muted">Ngày công</div>
                        <div class="fw-bold text-success"><?= $totalWork ?></div>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Tổng giờ</div>
                        <div class="fw-bold text-primary"><?= number_format($totalHours, 1) ?>h</div>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Đi trễ</div>
                        <div class="fw-bold text-warning"><?= $lateDays ?> lần</div>
                    </div>
                    <div class="col-3">
                        <div class="small text-muted">Nghỉ phép</div>
                        <div class="fw-bold text-info"><?= count($leaveDays) ?> ngày</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Bảng chi tiết chấm công tháng -->
        <?php if (!empty($monthLogs)): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">📋 Chi tiết chấm công tháng <?= $viewMonth ?>/<?= $viewYear ?></h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0" style="font-size:.83rem">
                        <thead class="table-light">
                            <tr>
                                <th>Ngày</th>
                                <th>Thứ</th>
                                <th>Giờ vào</th>
                                <th>Giờ ra</th>
                                <th class="text-center">Giờ làm</th>
                                <th class="text-center">Trạng thái</th>
                                <th>Ghi chú</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $dowVi = ['', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'CN'];
                        foreach ($monthLogs as $log):
                            $dow  = (int)date('N', strtotime($log['work_date']));
                            $late = !empty($log['is_late']);
                        ?>
                        <tr class="<?= $late ? 'table-warning' : '' ?>">
                            <td class="fw-semibold"><?= date('d/m', strtotime($log['work_date'])) ?></td>
                            <td class="<?= $dow === 7 ? 'text-danger' : '' ?>"><?= $dowVi[$dow] ?></td>
                            <td class="<?= $late ? 'text-warning fw-bold' : 'text-success' ?>">
                                <?= !empty($log['check_in']) ? date('H:i', strtotime($log['check_in'])) : '—' ?>
                                <?php if ($late): ?>
                                <small class="badge bg-warning text-dark">trễ</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($log['check_out']) ? date('H:i', strtotime($log['check_out'])) : '<span class="text-danger small">chưa ra</span>' ?>
                            </td>
                            <td class="text-center">
                                <?= $log['work_hours'] > 0 ? number_format((float)$log['work_hours'], 1) . 'h' : '—' ?>
                            </td>
                            <td class="text-center">
                                <?php
                                $status = $log['status'] ?? 'present';
                                $statusBadge = match($status) {
                                    'present' => '<span class="badge bg-success">✅ Có mặt</span>',
                                    'absent'  => '<span class="badge bg-danger">❌ Vắng</span>',
                                    'late'    => '<span class="badge bg-warning text-dark">⚡ Trễ</span>',
                                    default   => '<span class="badge bg-secondary">' . htmlspecialchars($status) . '</span>',
                                };
                                echo $statusBadge;
                                ?>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars($log['note'] ?? '') ?>
                                <?php if (($log['source'] ?? '') === 'manual'): ?>
                                <span class="badge bg-light text-muted border" style="font-size:10px;">✍️ Thủ công</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

<style>
.calendar-table td  { height: 65px; vertical-align: top; padding: 4px; }
.day-number         { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
.present-cell       { background: #f0fff4; }
.late-cell          { background: #fffbf0; }
.leave-cell         { background: #e8f4fd; }
.absent-cell        { background: #fff5f5; }
.att-time .badge-sm { font-size: 10px; padding: 2px 4px; }
.badge-legend       { font-size: 11px; padding: 3px 8px; border-radius: 20px; display: inline-block; }
</style>

<?php include '../../../includes/footer.php'; ?>