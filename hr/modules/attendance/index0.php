<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo       = getDBConnection();
$user      = currentUser();
$pageTitle = 'Chấm công HR';

$today     = date('Y-m-d');
$viewMonth = (int)($_GET['month'] ?? date('m'));
$viewYear  = (int)($_GET['year']  ?? date('Y'));

if ($viewMonth < 1)  { $viewMonth = 12; $viewYear--; }
if ($viewMonth > 12) { $viewMonth = 1;  $viewYear++; }

// ── Helpers ─────────────────────────────────────────────────
function hrCol(PDO $pdo, string $sql, array $p = []): mixed {
    try {
        $s = $pdo->prepare($sql); $s->execute($p);
        $v = $s->fetchColumn(); return $v === false ? 0 : $v;
    } catch (Exception $e) { return 0; }
}
function hrQuery(PDO $pdo, string $sql, array $p = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

// ── Phân quyền xem ──────────────────────────────────────────
// Admin/superadmin/accountant/manager/director → xem tất cả
// Nhân viên thường → chỉ xem của mình
$userRole   = $user['role'] ?? '';
$canViewAll = in_array($userRole, ['superadmin','admin','director','accountant','manager']);

$viewUserId = $canViewAll
    ? (int)($_GET['user_id'] ?? $user['id'])
    : $user['id'];

// ── CSRF token đơn giản (session-based) ─────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_token'];

function verifyCsrf(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Xử lý chấm công thủ công ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Yêu cầu không hợp lệ.'];
        header('Location: index.php'); exit;
    }

    $action = $_POST['action'] ?? '';
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
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Lỗi chấm công: ' . $e->getMessage()];
        }
    } elseif ($action === 'check_out') {
        try {
            $pdo->prepare("
                UPDATE hr_attendance
                SET check_out  = ?,
                    work_hours = ROUND(EXTRACT(EPOCH FROM (?::timestamp - check_in)) / 3600, 2)
                WHERE user_id = ? AND work_date = ? AND check_out IS NULL
            ")->execute([$now, $now, $uid, $today]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã chấm công RA lúc ' . date('H:i')];
        } catch (Exception $e) {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Lỗi chấm công: ' . $e->getMessage()];
        }
    }
    header('Location: index.php'); exit;
}

// ── Dữ liệu chấm công ───────────────────────────────────────
$todayLog = hrQuery($pdo,
    "SELECT * FROM hr_attendance WHERE user_id = ? AND work_date = ? LIMIT 1",
    [$user['id'], $today]);
$todayLog = $todayLog[0] ?? null;

$monthLogs = hrQuery($pdo,
    "SELECT * FROM hr_attendance
     WHERE user_id = ?
       AND EXTRACT(MONTH FROM work_date) = ?
       AND EXTRACT(YEAR  FROM work_date) = ?
     ORDER BY work_date",
    [$viewUserId, $viewMonth, $viewYear]);

$logsMap = [];
foreach ($monthLogs as $l) $logsMap[$l['work_date']] = $l;

// ── Nghỉ phép được duyệt ───────��────────────────────────────
$leaves = hrQuery($pdo,
    "SELECT * FROM hr_leaves
     WHERE user_id = ? AND status = 'approved'
       AND (EXTRACT(MONTH FROM date_from) = ? OR EXTRACT(MONTH FROM date_to) = ?)
       AND (EXTRACT(YEAR  FROM date_from) = ? OR EXTRACT(YEAR  FROM date_to) = ?)",
    [$viewUserId, $viewMonth, $viewMonth, $viewYear, $viewYear]);

$leaveDays = [];
foreach ($leaves as $lv) {
    $s = strtotime($lv['date_from']); $e = strtotime($lv['date_to']);
    for ($d = $s; $d <= $e; $d += 86400)
        $leaveDays[date('Y-m-d', $d)] = $lv['leave_type'];
}

// ── Thống kê tháng ──────────────────────────────────────────
$totalWork  = count(array_filter($monthLogs, fn($l) => !empty($l['check_in'])));
$totalHours = (float)array_sum(array_column($monthLogs, 'work_hours'));
$lateDays   = count(array_filter($monthLogs, fn($l) => !empty($l['is_late'])));

// ── Danh sách nhân viên (cho manager+) ──────────────────────
$empList = $canViewAll ? hrQuery($pdo,
    "SELECT u.id, u.full_name, u.employee_code
     FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.is_active = TRUE AND r.name NOT IN ('customer')
     ORDER BY u.full_name") : [];

// ── Thông tin user đang xem ──────────────────────────────────
$viewUser = ($viewUserId !== $user['id'])
    ? (hrQuery($pdo, "SELECT * FROM users WHERE id = ? LIMIT 1", [$viewUserId])[0] ?? $user)
    : $user;

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- Tiêu đề -->
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

<!-- Flash message -->
<?php if (!empty($_SESSION['flash'])): ?>
<div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show" role="alert">
    <?= $_SESSION['flash']['msg'] ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['flash']); endif; ?>

<!-- Bộ lọc nhân viên (chỉ manager+) -->
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
        <button type="submit" class="btn btn-sm btn-primary">
            <i class="fas fa-search me-1"></i>Xem
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
</form>
</div>
</div>
<?php endif; ?>

<div class="row g-3">

    <!-- ── CỘT TRÁI ── -->
    <div class="col-lg-4">

        <!-- Chấm công hôm nay (chỉ khi xem chính mình) -->
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
                    <div class="alert alert-success py-2 mb-0">
                        ✅ Hoàn thành ca · <strong><?= number_format((float)$todayLog['work_hours'], 1) ?>h</strong>
                        <?php if (!empty($todayLog['is_late'])): ?>
                        <br><small class="text-warning">⚡ Đi trễ</small>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        Chấm công thủ công (chưa lắp máy)
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <?php if (!$todayLog || !$todayLog['check_in']): ?>
                            <input type="hidden" name="action" value="check_in">
                            <button type="submit" class="btn btn-success btn-lg w-100"
                                    onclick="return confirm('Xác nhận chấm công VÀO?')">
                                <i class="fas fa-sign-in-alt me-2"></i>Chấm công VÀO
                            </button>
                        <?php else: ?>
                            <div class="alert alert-info py-2 small mb-2">
                                Đã vào: <strong><?= date('H:i', strtotime($todayLog['check_in'])) ?></strong>
                            </div>
                            <input type="hidden" name="action" value="check_out">
                            <button type="submit" class="btn btn-danger btn-lg w-100"
                                    onclick="return confirm('Xác nhận chấm công RA?')">
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

        <!-- Quick links -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-2">
                <div class="d-grid gap-2">
                    <a href="../overtime/request.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-business-time me-2"></i>Đăng ký tăng ca (OT)
                    </a>
                    <a href="../leave/request.php" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-calendar-minus me-2"></i>Xin nghỉ phép
                    </a>
                    <?php if ($canViewAll): ?>
                    <a href="all.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-table me-2"></i>Bảng chấm công tổng hợp
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── CỘT PHẢI: Lịch tháng ── -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <a href="?user_id=<?= $viewUserId ?>&month=<?= $viewMonth - 1 ?>&year=<?= $viewYear ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <h6 class="mb-0 fw-bold">📅 Tháng <?= $viewMonth ?>/<?= $viewYear ?></h6>
                <a href="?user_id=<?= $viewUserId ?>&month=<?= $viewMonth + 1 ?>&year=<?= $viewYear ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
            <div class="card-body p-2">

                <!-- Chú thích -->
                <div class="d-flex flex-wrap gap-2 mb-2 px-1">
                    <span class="badge bg-success">✅ Đúng giờ</span>
                    <span class="badge bg-warning text-dark">⚡ Trễ</span>
                    <span class="badge bg-info">🏖️ Phép</span>
                    <span class="badge bg-danger">❌ Vắng</span>
                    <span class="badge bg-light text-muted border">— CN</span>
                </div>

                <table class="table table-bordered calendar-table mb-0" style="font-size:12px;">
                    <thead class="table-dark">
                        <tr>
                            <th>T2</th><th>T3</th><th>T4</th><th>T5</th>
                            <th>T6</th><th>T7</th><th class="text-danger">CN</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $firstDay    = mktime(0, 0, 0, $viewMonth, 1, $viewYear);
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $viewMonth, $viewYear);
                    $startDow    = (int)date('N', $firstDay); // 1=Mon … 7=Sun

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

                        $bg = ''; $content = '';

                        if ($isSun) {
                            $bg      = 'bg-light';
                            $content = '<small class="text-muted">CN</small>';
                        } elseif ($future) {
                            $content = '';
                        } elseif ($isLeave && !$log) {
                            $bg      = 'leave-cell';
                            $leaveLabel = [
                                'annual' => 'Phép', 'sick' => 'Ốm',
                                'unpaid' => 'KL',   'other' => 'Khác',
                            ][$leaveDays[$ds]] ?? 'Phép';
                            $content = '<div class="text-info fw-bold">🏖️<br><small>'.$leaveLabel.'</small></div>';
                        } elseif ($log && $log['check_in']) {
                            $bg = (!empty($log['is_late'])) ? 'late-cell' : 'present-cell';
                            $checkIn  = date('H:i', strtotime($log['check_in']));
                            $checkOut = $log['check_out'] ? date('H:i', strtotime($log['check_out'])) : '?';
                            $content  = '<div class="att-time">
                                <span class="badge bg-success badge-sm">▶ '.$checkIn.'</span><br>
                                <span class="badge bg-danger badge-sm mt-1">◼ '.$checkOut.'</span>
                            </div>';
                        } else {
                            $bg      = 'absent-cell';
                            $content = '<div class="text-danger fw-bold">❌</div>';
                        }

                        $todayBorder = $isToday ? 'border border-primary border-2' : '';
                        $dayNumClass = $isToday ? 'fw-bold text-primary' : '';
                        echo "<td class='calendar-day {$bg} {$todayBorder}'>
                                <div class='day-number {$dayNumClass}'>{$day}</div>
                                {$content}
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
        </div>
    </div>
</div><!-- /.row -->

</div><!-- /.container-fluid -->
</div><!-- /.main-content -->

<style>
.calendar-table td { height: 70px; vertical-align: top; padding: 4px; }
.day-number { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
.present-cell { background: #f0fff4; }
.late-cell    { background: #fffbf0; }
.leave-cell   { background: #e8f4fd; }
.absent-cell  { background: #fff5f5; }
.att-time .badge-sm { font-size: 10px; padding: 2px 5px; }
</style>

<?php include '../../includes/footer.php'; ?>