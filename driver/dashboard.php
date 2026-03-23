<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) {
    header('Location: /dashboard.php'); exit;
}

$pageTitle = 'Trang chủ';
$pdo  = getDBConnection();
$user = currentUser();
$today        = date('Y-m-d');
$now          = date('Y-m-d H:i:s');
$currentMonth = (int)date('m');
$currentYear  = (int)date('Y');

// Lấy driver_id
$driverRow = $pdo->prepare("SELECT * FROM drivers WHERE user_id = ?");
$driverRow->execute([$user['id']]);
$driver   = $driverRow->fetch();
$driverId = $driver['id'] ?? 0;

// ── Helper ─────────────────────────────────────────────────────
function safeCol(PDO $pdo, string $sql, array $p = []): mixed {
    try { $s = $pdo->prepare($sql); $s->execute($p); $v = $s->fetchColumn(); return $v === false ? 0 : $v; }
    catch (Exception $e) { return 0; }
}
function safeQuery(PDO $pdo, string $sql, array $p = []): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_ASSOC); }
    catch (Exception $e) { return []; }
}

// ── Xử lý chấm công thủ công ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['att_action'])) {
    $action = $_POST['att_action'];
    if ($action === 'check_in') {
        try {
            $pdo->prepare("
                INSERT INTO hr_attendance (user_id, work_date, check_in, status, source)
                VALUES (?, ?, ?, 'present', 'manual')
                ON CONFLICT (user_id, work_date) DO NOTHING
            ")->execute([$user['id'], $today, $now]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Chấm công VÀO lúc ' . date('H:i')];
        } catch (Exception $e) { /* ignore */ }
    } elseif ($action === 'check_out') {
        try {
            $pdo->prepare("
                UPDATE hr_attendance
                SET check_out  = ?,
                    work_hours = ROUND(EXTRACT(EPOCH FROM (?::timestamp - check_in)) / 3600, 2)
                WHERE user_id = ? AND work_date = ? AND check_out IS NULL
            ")->execute([$now, $now, $user['id'], $today]);
            $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Chấm công RA lúc ' . date('H:i')];
        } catch (Exception $e) { /* ignore */ }
    }
    header('Location: dashboard.php');
    exit;
}

// ── Chấm công hôm nay ─────────────────────────────────────────
$todayAttRows = safeQuery($pdo,
    "SELECT * FROM hr_attendance WHERE user_id = ? AND work_date = ? LIMIT 1",
    [$user['id'], $today]);
$todayAtt = $todayAttRows[0] ?? null;

// ── Thống kê chấm công tháng hiện tại ────────────────────────
$attMonthRows = safeQuery($pdo,
    "SELECT * FROM hr_attendance
     WHERE user_id = ?
       AND EXTRACT(MONTH FROM work_date) = ?
       AND EXTRACT(YEAR  FROM work_date) = ?
     ORDER BY work_date",
    [$user['id'], $currentMonth, $currentYear]);

$attWorkDays  = count(array_filter($attMonthRows, fn($l) => $l['check_in']));
$attTotalHrs  = (float)array_sum(array_column($attMonthRows, 'work_hours'));
$attLateDays  = count(array_filter($attMonthRows, fn($l) => ($l['is_late'] ?? 0)));

// Nghỉ phép tháng này
$leaveDaysCount = (int)safeCol($pdo, "
    SELECT COALESCE(SUM(days_count), 0)
    FROM hr_leaves
    WHERE user_id = ? AND status = 'approved'
      AND EXTRACT(MONTH FROM date_from) = ?
      AND EXTRACT(YEAR  FROM date_from) = ?
", [$user['id'], $currentMonth, $currentYear]);

// OT tháng này
$otHours = (float)safeCol($pdo, "
    SELECT COALESCE(SUM(ot_hours), 0)
    FROM hr_overtime
    WHERE user_id = ? AND status = 'approved'
      AND EXTRACT(MONTH FROM ot_date) = ?
      AND EXTRACT(YEAR  FROM ot_date) = ?
", [$user['id'], $currentMonth, $currentYear]);

// ── Chuyến xe ─────────────────────────────────────────────────
// Thống kê kỳ (26 tháng trước → 25 tháng này)
$day = (int)date('d');
if ($day >= 26) {
    $kyCurrent = date('Y-m-26');
    $kyEnd     = date('Y-m-25', strtotime('+1 month'));
} else {
    $kyCurrent = date('Y-m-26', strtotime('-1 month'));
    $kyEnd     = date('Y-m-25');
}

$statsRow = safeQuery($pdo, "
    SELECT
        COUNT(*)                                          AS total_trips,
        COUNT(*) FILTER (WHERE status = 'completed')     AS completed,
        COUNT(*) FILTER (WHERE status = 'in_progress')   AS in_progress,
        COALESCE(SUM(total_km), 0)                       AS total_km
    FROM trips
    WHERE driver_id = ? AND trip_date BETWEEN ? AND ?
", [$driverId, $kyCurrent, $kyEnd]);
$stats = $statsRow[0] ?? ['total_trips'=>0,'completed'=>0,'in_progress'=>0,'total_km'=>0];

// Chuyến hôm nay
$tripsToday = safeQuery($pdo, "
    SELECT t.*, c.company_name AS customer_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN vehicles  v ON t.vehicle_id  = v.id
    WHERE t.driver_id = ? AND t.trip_date = ?
    ORDER BY t.departure_time
", [$driverId, $today]);

// Chuyến sắp tới (3 ngày)
$upcoming = safeQuery($pdo, "
    SELECT t.*, c.company_name AS customer_name, v.plate_number
    FROM trips t
    JOIN customers c ON t.customer_id = c.id
    JOIN vehicles  v ON t.vehicle_id  = v.id
    WHERE t.driver_id = ?
      AND t.trip_date > ?
      AND t.trip_date <= (CURRENT_DATE + INTERVAL '3 days')
      AND t.status = 'scheduled'
    ORDER BY t.trip_date, t.departure_time
", [$driverId, $today]);

// ── Đơn OT / nghỉ phép đang chờ duyệt ───────────────────────
$pendingOT = (int)safeCol($pdo,
    "SELECT COUNT(*) FROM hr_overtime WHERE user_id = ? AND status = 'pending'",
    [$user['id']]);
$pendingLeave = (int)safeCol($pdo,
    "SELECT COUNT(*) FROM hr_leaves WHERE user_id = ? AND status = 'pending'",
    [$user['id']]);

include 'includes/header.php';

$statusColors = [
    'scheduled'   => 'warning',
    'in_progress' => 'primary',
    'completed'   => 'success',
    'confirmed'   => 'info',
    'rejected'    => 'danger',
];
$statusLabels = [
    'scheduled'   => '📅 Chờ xuất phát',
    'in_progress' => '🚛 Đang chạy',
    'completed'   => '✅ Hoàn thành',
    'confirmed'   => '👍 Đã confirm',
    'rejected'    => '❌ Từ chối',
];
?>

<!-- Top Bar -->
<div class="driver-topbar">
    <div class="d-flex justify-content-between align-items-center">
        <div>
            <?php
            $nameParts = explode(' ', trim($user['full_name']));
            $firstName = end($nameParts);
            ?>
            <div class="fw-bold fs-6">Xin chào, <?= htmlspecialchars($firstName) ?> 👋</div>
            <div style="font-size:0.75rem;opacity:0.75"><?= date('l, d/m/Y') ?></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-success">🚗 Lái Xe</span>
            <a href="/logout.php" class="text-white opacity-75">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</div>

<div class="px-3 pt-3 pb-5">

    <?php showFlash(); ?>

    <!-- ══════════════════════════════════════════
         CHẤM CÔNG HÔM NAY
    ══════════════════════════════════════════ -->
    <div class="section-title">⏰ Chấm công hôm nay</div>
    <div class="driver-card mb-3">
        <div class="row text-center mb-3">
            <div class="col-6 border-end">
                <div class="text-muted small mb-1">Giờ vào</div>
                <div class="fs-3 fw-bold <?= $todayAtt && $todayAtt['check_in'] ? 'text-success' : 'text-muted' ?>">
                    <?= $todayAtt && $todayAtt['check_in']
                        ? date('H:i', strtotime($todayAtt['check_in']))
                        : '--:--' ?>
                </div>
            </div>
            <div class="col-6">
                <div class="text-muted small mb-1">Giờ ra</div>
                <div class="fs-3 fw-bold <?= $todayAtt && $todayAtt['check_out'] ? 'text-danger' : 'text-muted' ?>">
                    <?= $todayAtt && $todayAtt['check_out']
                        ? date('H:i', strtotime($todayAtt['check_out']))
                        : '--:--' ?>
                </div>
            </div>
        </div>

        <?php if ($todayAtt && $todayAtt['check_out']): ?>
            <!-- Đã hoàn thành ca -->
            <div class="alert alert-success py-2 mb-2 text-center">
                ✅ Hoàn thành ca hôm nay ·
                <strong><?= number_format((float)$todayAtt['work_hours'], 1) ?>h</strong>
                <?php if ($todayAtt['is_late'] ?? 0): ?>
                    · <span class="text-warning fw-semibold">⚡ Trễ <?= $todayAtt['late_minutes'] ?> phút</span>
                <?php endif; ?>
            </div>

        <?php elseif ($todayAtt && $todayAtt['check_in'] && !$todayAtt['check_out']): ?>
            <!-- Đã vào, chưa ra -->
            <div class="alert alert-info py-2 mb-2 text-center small">
                Đã chấm vào <?= date('H:i', strtotime($todayAtt['check_in'])) ?>
                · Đang làm việc...
            </div>
            <form method="POST">
                <input type="hidden" name="att_action" value="check_out">
                <button type="submit" class="btn btn-danger w-100 rounded-pill fw-bold"
                        onclick="return confirm('Xác nhận chấm công RA lúc ' + new Date().toLocaleTimeString('vi-VN') + '?')">
                    <i class="fas fa-sign-out-alt me-2"></i>Chấm công RA
                </button>
            </form>

        <?php else: ?>
            <!-- Chưa vào -->
            <form method="POST">
                <input type="hidden" name="att_action" value="check_in">
                <button type="submit" class="btn btn-success w-100 rounded-pill fw-bold"
                        onclick="return confirm('Xác nhận chấm công VÀO lúc ' + new Date().toLocaleTimeString('vi-VN') + '?')">
                    <i class="fas fa-sign-in-alt me-2"></i>Chấm công VÀO
                </button>
            </form>
        <?php endif; ?>

        <!-- Thống kê nhanh chấm công tháng -->
        <div class="row text-center mt-3 pt-2 border-top g-0">
            <div class="col-3">
                <div class="fw-bold text-success"><?= $attWorkDays ?></div>
                <div style="font-size:0.68rem;" class="text-muted">Ngày công</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-primary"><?= number_format($attTotalHrs, 1) ?>h</div>
                <div style="font-size:0.68rem;" class="text-muted">Tổng giờ</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-warning"><?= $attLateDays ?></div>
                <div style="font-size:0.68rem;" class="text-muted">Lần trễ</div>
            </div>
            <div class="col-3">
                <div class="fw-bold text-info"><?= number_format($otHours, 1) ?>h</div>
                <div style="font-size:0.68rem;" class="text-muted">OT tháng</div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         THỐNG KÊ CHUYẾN XE
    ══════════════════════════════════════════ -->
    <div class="section-title">📊 Chuyến xe kỳ này</div>
    <div class="row g-2 mb-3">
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#0f3460,#1a4a8a)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['total_trips'] ?></div>
                <div style="font-size:0.72rem;opacity:0.85">
                    Chuyến 26/<?= date('m', strtotime('-1 month')) ?> — 25/<?= date('m') ?>
                </div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#198754,#20a060)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['completed'] ?></div>
                <div style="font-size:0.72rem;opacity:0.85">Đã hoàn thành</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#fd7e14,#e8650a)">
                <div style="font-size:1.8rem;font-weight:700">
                    <?= number_format((float)$stats['total_km'], 0) ?>
                </div>
                <div style="font-size:0.72rem;opacity:0.85">Km kỳ này</div>
            </div>
        </div>
        <div class="col-6">
            <div class="stat-card" style="background:linear-gradient(135deg,#0dcaf0,#0aa8cc)">
                <div style="font-size:1.8rem;font-weight:700"><?= $stats['in_progress'] ?></div>
                <div style="font-size:0.72rem;opacity:0.85">Đang chạy</div>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════
         CHUYẾN XE HÔM NAY
    ══════════════════════════════════════════ -->
    <div class="section-title">📅 Chuyến xe hôm nay</div>
    <?php if (empty($tripsToday)): ?>
    <div class="driver-card text-center py-3">
        <div style="font-size:2.5rem">😴</div>
        <div class="text-muted mt-1">Không có chuyến nào hôm nay</div>
        <a href="/driver/trip_create.php" class="btn btn-primary btn-sm mt-2 rounded-pill">
            + Tạo chuyến mới
        </a>
    </div>
    <?php else: ?>
    <?php foreach ($tripsToday as $trip):
        $sc   = $statusColors[$trip['status']] ?? 'secondary';
        $sl   = $statusLabels[$trip['status']] ?? $trip['status'];
        $from = $trip['pickup_location']  ?? $trip['route_from'] ?? '';
        $to   = $trip['dropoff_location'] ?? $trip['route_to']   ?? '';
    ?>
    <a href="/driver/trip_detail.php?id=<?= $trip['id'] ?>" class="text-decoration-none">
        <div class="trip-card status-<?= $trip['status'] ?>">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <code class="text-primary fw-bold"><?= htmlspecialchars($trip['trip_code']) ?></code>
                <span class="badge bg-<?= $sc ?> status-badge"><?= $sl ?></span>
            </div>
            <div class="fw-semibold mb-1"><?= htmlspecialchars($trip['customer_name']) ?></div>
            <?php if ($from || $to): ?>
            <div class="d-flex align-items-center gap-1 text-muted small mb-2">
                <i class="fas fa-map-marker-alt text-danger"></i>
                <?= htmlspecialchars($from) ?>
                <i class="fas fa-arrow-right mx-1"></i>
                <i class="fas fa-map-marker-alt text-success"></i>
                <?= htmlspecialchars($to) ?>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-3 small text-muted">
                <span><i class="fas fa-clock me-1"></i><?= !empty($trip['departure_time']) ? substr($trip['departure_time'], 0, 5) : '--:--' ?></span>
                <span><i class="fas fa-car me-1"></i><?= htmlspecialchars($trip['plate_number']) ?></span>
                <?php if (!empty($trip['total_km'])): ?>
                <span><i class="fas fa-road me-1"></i><?= number_format($trip['total_km'], 0) ?> km</span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════
         CHUYẾN SẮP TỚI
    ══════════════════════════════════════════ -->
    <?php if (!empty($upcoming)): ?>
    <div class="section-title">🔜 Sắp tới (3 ngày)</div>
    <?php foreach ($upcoming as $trip):
        $from = $trip['pickup_location']  ?? $trip['route_from'] ?? '';
        $to   = $trip['dropoff_location'] ?? $trip['route_to']   ?? '';
    ?>
    <a href="/driver/trip_detail.php?id=<?= $trip['id'] ?>" class="text-decoration-none">
        <div class="trip-card" style="border-left-color:#6c757d">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <code class="text-secondary"><?= htmlspecialchars($trip['trip_code']) ?></code>
                <small class="text-muted"><?= date('d/m', strtotime($trip['trip_date'])) ?></small>
            </div>
            <div class="fw-semibold small"><?= htmlspecialchars($trip['customer_name']) ?></div>
            <?php if ($from || $to): ?>
            <div class="text-muted small">
                <?= htmlspecialchars($from) ?>
                <?php if ($from && $to): ?> → <?php endif; ?>
                <?= htmlspecialchars($to) ?>
            </div>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>

       <!-- ══════════════════════════════════════════
         HR NHANH: OT & NGHỈ PHÉP
    ══════════════════════════════════════════ -->
    <div class="section-title">
        📋 Đơn từ của tôi
        <?php if ($pendingOT + $pendingLeave > 0): ?>
        <span class="badge bg-danger ms-1"><?= $pendingOT + $pendingLeave ?> chờ duyệt</span>
        <?php endif; ?>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-6">
            <a href="/driver/ot_request.php" class="text-decoration-none">
                <div class="driver-card d-flex align-items-center gap-2 py-2 px-3">
                    <div style="font-size:1.8rem">⏱️</div>
                    <div>
                        <div class="fw-semibold small">Đăng ký OT</div>
                        <?php if ($pendingOT > 0): ?>
                        <div style="font-size:0.68rem;" class="text-warning">
                            <?= $pendingOT ?> đơn chờ duyệt
                        </div>
                        <?php else: ?>
                        <div style="font-size:0.68rem;" class="text-muted">
                            <?= number_format($otHours, 1) ?>h tháng này
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/leave_request.php" class="text-decoration-none">
                <div class="driver-card d-flex align-items-center gap-2 py-2 px-3">
                    <div style="font-size:1.8rem">🏖️</div>
                    <div>
                        <div class="fw-semibold small">Xin nghỉ phép</div>
                        <?php if ($pendingLeave > 0): ?>
                        <div style="font-size:0.68rem;" class="text-warning">
                            <?= $pendingLeave ?> đơn chờ duyệt
                        </div>
                        <?php else: ?>
                        <div style="font-size:0.68rem;" class="text-muted">
                            <?= $leaveDaysCount ?> ngày phép tháng này
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-12">
            <a href="/driver/attendance.php" class="text-decoration-none">
                <div class="driver-card d-flex align-items-center justify-content-between px-3 py-2">
                    <div class="d-flex align-items-center gap-2">
                        <div style="font-size:1.8rem">📅</div>
                        <div>
                            <div class="fw-semibold small">Bảng chấm công của tôi</div>
                            <div style="font-size:0.68rem;" class="text-muted">
                                Tháng <?= $currentMonth ?>/<?= $currentYear ?> ·
                                <?= $attWorkDays ?> ngày công ·
                                <?= number_format($attTotalHrs, 1) ?>h
                            </div>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-muted"></i>
                </div>
            </a>
        </div>
    </div>
    <!-- ══════════════════════════════════════════
         QUICK ACTIONS
    ══════════════════════════════════════════ -->
    <div class="section-title">⚡ Thao tác nhanh</div>
    <div class="row g-2 mb-4">
        <div class="col-6">
            <a href="/driver/trip_create.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">🚛</div>
                    <div class="small fw-semibold mt-1">Tạo chuyến xe</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/fuel_add.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">⛽</div>
                    <div class="small fw-semibold mt-1">Nhập xăng dầu</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/trips.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">📋</div>
                    <div class="small fw-semibold mt-1">Lịch sử chuyến</div>
                </div>
            </a>
        </div>
        <div class="col-6">
            <a href="/driver/profile.php" class="text-decoration-none">
                <div class="driver-card text-center py-3">
                    <div style="font-size:2rem">👤</div>
                    <div class="small fw-semibold mt-1">Hồ sơ của tôi</div>
                </div>
            </a>
        </div>
    </div>

</div><!-- /.px-3 -->

<?php include 'includes/bottom_nav.php'; ?>