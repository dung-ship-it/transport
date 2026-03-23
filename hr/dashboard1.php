<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo          = getDBConnection();
$user         = currentUser();
$pageTitle    = 'HR Dashboard';

$today        = date('Y-m-d');
$thisYear     = (int)date('Y');
$thisMonthNum = (int)date('m');

// ── Helpers ──────────────────────────────────────────────────
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

// ── KPIs ─────────────────────────────────────────────────────

// Tổng nhân viên active (join roles, loại customer)
$totalEmployees = (int)hrCol($pdo, "
    SELECT COUNT(*) FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.is_active = TRUE
      AND r.name NOT IN ('customer')
");

// Có mặt / vắng hôm nay
$presentToday = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_attendance
     WHERE work_date = ? AND status IN ('present','late')",
    [$today]);

$absentToday = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_attendance
     WHERE work_date = ? AND status = 'absent'",
    [$today]);

// Chưa chấm công hôm nay
$notCheckedIn = max(0, $totalEmployees - $presentToday - $absentToday);

// Pending OT / Leave
$pendingOT    = (int)hrCol($pdo, "SELECT COUNT(*) FROM hr_overtime WHERE status = 'pending'");
$pendingLeave = (int)hrCol($pdo, "SELECT COUNT(*) FROM hr_leaves   WHERE status = 'pending'");

// Bảng lương tháng hiện tại
$currentPayroll = hrQuery($pdo,
    "SELECT * FROM hr_payroll_periods
     WHERE period_year = ? AND period_month = ? LIMIT 1",
    [$thisYear, $thisMonthNum]);
$currentPayroll = $currentPayroll[0] ?? null;

// Tổng quỹ lương tháng này
$totalPayroll = (float)hrCol($pdo, "
    SELECT COALESCE(SUM(pi.net_salary), 0)
    FROM hr_payroll_items pi
    JOIN hr_payroll_periods pp ON pi.period_id = pp.id
    WHERE pp.period_year = ? AND pp.period_month = ?",
    [$thisYear, $thisMonthNum]);

// Tổng giờ OT tháng này (đã duyệt)
$totalOTHours = (float)hrCol($pdo, "
    SELECT COALESCE(SUM(ot_hours), 0) FROM hr_overtime
    WHERE DATE_TRUNC('month', ot_date) = DATE_TRUNC('month', CURRENT_DATE)
      AND status = 'approved'");

// ── Biểu đồ chấm công 7 ngày ─────────────────────────────
$attendanceChart = hrQuery($pdo, "
    SELECT work_date::text,
           COUNT(*) FILTER (WHERE status IN ('present','late')) AS present,
           COUNT(*) FILTER (WHERE status = 'absent')            AS absent
    FROM hr_attendance
    WHERE work_date >= CURRENT_DATE - INTERVAL '6 days'
    GROUP BY work_date
    ORDER BY work_date
");

// ── Danh sách chờ duyệt ───────────────────────────────────
$pendingLeaveList = hrQuery($pdo, "
    SELECT hl.id, hl.leave_type, hl.date_from, hl.date_to,
           hl.days_count, hl.reason,
           u.full_name
    FROM hr_leaves hl
    JOIN users u ON hl.user_id = u.id
    WHERE hl.status = 'pending'
    ORDER BY hl.date_from ASC
    LIMIT 5
");

$pendingOTList = hrQuery($pdo, "
    SELECT ho.id, ho.ot_date, ho.ot_hours, ho.ot_type,
           ho.ot_rate, ho.reason,
           u.full_name
    FROM hr_overtime ho
    JOIN users u ON ho.user_id = u.id
    WHERE ho.status = 'pending'
    ORDER BY ho.ot_date DESC
    LIMIT 5
");

// ── Top OT tháng này ─────────────────────────────────────
$topOT = hrQuery($pdo, "
    SELECT u.full_name,
           SUM(ho.ot_hours) AS total_ot,
           COUNT(ho.id)     AS ot_count
    FROM hr_overtime ho
    JOIN users u ON ho.user_id = u.id
    WHERE DATE_TRUNC('month', ho.ot_date) = DATE_TRUNC('month', CURRENT_DATE)
      AND ho.status = 'approved'
    GROUP BY u.id, u.full_name
    ORDER BY total_ot DESC
    LIMIT 5
");

// ── Lương ước tính theo phòng ban ────────────────────────
$salaryByDept = hrQuery($pdo, "
    SELECT COALESCE(sc.department, 'Chưa phân loại') AS department,
           COUNT(*)                                    AS headcount,
           COALESCE(SUM(sc.base_salary + sc.allowance), 0) AS est_cost
    FROM hr_salary_configs sc
    WHERE sc.is_active = TRUE
    GROUP BY sc.department
    ORDER BY est_cost DESC
");

// ── Nhân viên mới nhất (5 người) ─────────────────────────
$recentEmployees = hrQuery($pdo, "
    SELECT u.id, u.full_name, u.employee_code,
           u.hire_date, u.email, u.phone,
           r.name AS role_name,
           COALESCE(sc.department, '—')  AS department,
           COALESCE(sc.position,   '—')  AS position
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN hr_salary_configs sc ON sc.user_id = u.id AND sc.is_active = TRUE
    WHERE u.is_active = TRUE
      AND r.name NOT IN ('customer')
    ORDER BY u.hire_date DESC NULLS LAST, u.created_at DESC
    LIMIT 5
");

// ── Tổng nhân viên theo phòng ban ────────────────────────
$headcountByDept = hrQuery($pdo, "
    SELECT COALESCE(sc.department, 'Chưa phân loại') AS department,
           COUNT(DISTINCT u.id) AS cnt
    FROM users u
    JOIN roles r ON u.role_id = r.id
    LEFT JOIN hr_salary_configs sc ON sc.user_id = u.id AND sc.is_active = TRUE
    WHERE u.is_active = TRUE AND r.name NOT IN ('customer')
    GROUP BY sc.department
    ORDER BY cnt DESC
");

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<!-- ── Tiêu đề ──────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">👥 HR Dashboard</h4>
        <small class="text-muted">
            <?= date('l, d/m/Y') ?> —
            <?php if ($currentPayroll): ?>
                Bảng lương <?= $thisMonthNum ?>/<?= $thisYear ?>:
                <span class="badge bg-<?= $currentPayroll['status']==='locked' ? 'success' : 'warning text-dark' ?>">
                    <?= $currentPayroll['status']==='locked' ? '🔒 Đã chốt' : '⏳ Đang xử lý' ?>
                </span>
            <?php else: ?>
                <span class="badge bg-secondary">Chưa tạo bảng lương tháng <?= $thisMonthNum ?>/<?= $thisYear ?></span>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="modules/attendance/index.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-clock me-1"></i>Chấm công
        </a>
        <a href="modules/leave/index.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-calendar-minus me-1"></i>Nghỉ phép
        </a>
        <a href="modules/overtime/index.php" class="btn btn-sm btn-outline-info">
            <i class="fas fa-business-time me-1"></i>Tăng ca
        </a>
        <a href="modules/payroll/index.php" class="btn btn-sm btn-success">
            <i class="fas fa-money-bill-wave me-1"></i>Bảng lương
        </a>
    </div>
</div>

<!-- ── KPI Cards ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        [
            'val'   => $totalEmployees,
            'lbl'   => 'Tổng nhân viên',
            'icon'  => 'fas fa-users',
            'color' => 'primary',
            'sub'   => 'đang làm việc',
            'link'  => 'modules/employees/index.php',
        ],
        [
            'val'   => $presentToday,
            'lbl'   => 'Có mặt hôm nay',
            'icon'  => 'fas fa-user-check',
            'color' => 'success',
            'sub'   => 'đã chấm công',
            'link'  => 'modules/attendance/index.php',
        ],
        [
            'val'   => $absentToday,
            'lbl'   => 'Vắng mặt',
            'icon'  => 'fas fa-user-times',
            'color' => 'danger',
            'sub'   => 'hôm nay',
            'link'  => 'modules/attendance/index.php',
        ],
        [
            'val'   => $notCheckedIn,
            'lbl'   => 'Chưa chấm công',
            'icon'  => 'fas fa-user-clock',
            'color' => 'secondary',
            'sub'   => 'hôm nay',
            'link'  => 'modules/attendance/index.php',
        ],
        [
            'val'   => $pendingLeave . ' yêu cầu',
            'lbl'   => 'Chờ duyệt phép',
            'icon'  => 'fas fa-calendar-times',
            'color' => 'warning',
            'sub'   => 'cần xử lý',
            'link'  => 'modules/leave/index.php',
        ],
        [
            'val'   => $pendingOT . ' yêu cầu',
            'lbl'   => 'Chờ duyệt OT',
            'icon'  => 'fas fa-hourglass-half',
            'color' => 'info',
            'sub'   => 'tăng ca chờ duyệt',
            'link'  => 'modules/overtime/index.php',
        ],
        [
            'val'   => formatMoney($totalPayroll),
            'lbl'   => 'Quỹ lương tháng',
            'icon'  => 'fas fa-money-bill-wave',
            'color' => 'success',
            'sub'   => date('m/Y'),
            'link'  => 'modules/payroll/index.php',
        ],
        [
            'val'   => $totalEmployees > 0
                        ? formatMoney(round($totalPayroll / $totalEmployees))
                        : '0 đ',
            'lbl'   => 'Lương TB/người',
            'icon'  => 'fas fa-coins',
            'color' => 'primary',
            'sub'   => 'tháng ' . $thisMonthNum . '/' . $thisYear,
            'link'  => 'modules/payroll/index.php',
        ],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-3">
        <a href="<?= $k['link'] ?>" class="text-decoration-none">
        <div class="card border-0 shadow-sm h-100 hover-card">
            <div class="card-body py-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="<?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
                    <small class="text-muted"><?= $k['lbl'] ?></small>
                </div>
                <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
                <div class="text-muted" style="font-size:.75rem"><?= $k['sub'] ?></div>
            </div>
        </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Row 2: Biểu đồ + Pending ─────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Biểu đồ chấm công 7 ngày -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">📅 Chấm công 7 ngày gần nhất</h6>
                <a href="modules/attendance/index.php" class="btn btn-xs btn-outline-secondary"
                   style="font-size:.75rem;padding:2px 10px">Xem chi tiết</a>
            </div>
            <div class="card-body">
                <?php if (!empty($attendanceChart)): ?>
                <canvas id="attendanceChart" height="120"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-calendar-check fa-3x opacity-25 mb-3 d-block"></i>
                    Chưa có dữ liệu chấm công
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Cần phê duyệt -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0">🔔 Cần phê duyệt</h6>
                <?php if ($pendingLeave + $pendingOT > 0): ?>
                <span class="badge bg-danger rounded-pill"><?= $pendingLeave + $pendingOT ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0" style="max-height:320px;overflow-y:auto">

                <?php if (!empty($pendingLeaveList)): ?>
                <div class="px-3 pt-2 pb-1 bg-light border-bottom">
                    <small class="text-muted fw-semibold">
                        <i class="fas fa-calendar-minus me-1 text-warning"></i>NGHỈ PHÉP
                        <span class="badge bg-warning text-dark ms-1"><?= count($pendingLeaveList) ?></span>
                    </small>
                </div>
                <?php
                $leaveTypeLabels = [
                    'annual'   => ['label'=>'Phép năm',  'color'=>'primary'],
                    'sick'     => ['label'=>'Ốm đau',    'color'=>'danger'],
                    'unpaid'   => ['label'=>'Không lương','color'=>'secondary'],
                    'maternity'=> ['label'=>'Thai sản',  'color'=>'pink'],
                    'other'    => ['label'=>'Khác',      'color'=>'info'],
                ];
                foreach ($pendingLeaveList as $lv):
                    $lt = $leaveTypeLabels[$lv['leave_type']] ?? ['label'=>$lv['leave_type'],'color'=>'secondary'];
                ?>
                <div class="d-flex align-items-center px-3 py-2 border-bottom" style="font-size:.82rem">
                    <div class="flex-grow-1 me-2">
                        <div class="fw-semibold"><?= htmlspecialchars($lv['full_name']) ?></div>
                        <div class="text-muted">
                            <span class="badge bg-<?= $lt['color'] ?>" style="font-size:.65rem">
                                <?= $lt['label'] ?>
                            </span>
                            <?= date('d/m', strtotime($lv['date_from'])) ?> →
                            <?= date('d/m', strtotime($lv['date_to'])) ?>
                            <strong>(<?= $lv['days_count'] ?> ngày)</strong>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="modules/leave/index.php?action=approve&id=<?= $lv['id'] ?>"
                           class="btn btn-success btn-sm py-0 px-2"
                           style="font-size:.72rem" title="Duyệt">✓</a>
                        <a href="modules/leave/index.php?action=reject&id=<?= $lv['id'] ?>"
                           class="btn btn-danger btn-sm py-0 px-2"
                           style="font-size:.72rem" title="Từ chối">✗</a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($pendingOTList)): ?>
                <div class="px-3 pt-2 pb-1 bg-light border-bottom">
                    <small class="text-muted fw-semibold">
                        <i class="fas fa-business-time me-1 text-info"></i>TĂNG CA
                        <span class="badge bg-info ms-1"><?= count($pendingOTList) ?></span>
                    </small>
                </div>
                <?php
                $otTypeLabels = [
                    'weekday' => ['label'=>'Ngày thường','color'=>'primary'],
                    'weekend' => ['label'=>'Cuối tuần',  'color'=>'warning'],
                    'holiday' => ['label'=>'Ngày lễ',    'color'=>'danger'],
                ];
                foreach ($pendingOTList as $ot):
                    $ott = $otTypeLabels[$ot['ot_type']] ?? ['label'=>$ot['ot_type'],'color'=>'secondary'];
                ?>
                <div class="d-flex align-items-center px-3 py-2 border-bottom" style="font-size:.82rem">
                    <div class="flex-grow-1 me-2">
                        <div class="fw-semibold"><?= htmlspecialchars($ot['full_name']) ?></div>
                        <div class="text-muted">
                            <span class="badge bg-<?= $ott['color'] ?>" style="font-size:.65rem">
                                <?= $ott['label'] ?>
                            </span>
                            <?= date('d/m/Y', strtotime($ot['ot_date'])) ?> ·
                            <strong><?= $ot['ot_hours'] ?>h</strong> ·
                            x<?= $ot['ot_rate'] ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1 flex-shrink-0">
                        <a href="modules/overtime/index.php?action=approve&id=<?= $ot['id'] ?>"
                           class="btn btn-success btn-sm py-0 px-2"
                           style="font-size:.72rem" title="Duyệt">✓</a>
                        <a href="modules/overtime/index.php?action=reject&id=<?= $ot['id'] ?>"
                           class="btn btn-danger btn-sm py-0 px-2"
                           style="font-size:.72rem" title="Từ chối">✗</a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($pendingLeaveList) && empty($pendingOTList)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-check-circle text-success fa-2x d-block mb-2"></i>
                    Không có yêu cầu chờ duyệt
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- ── Row 3: Top OT + Headcount by Dept ────────────────── -->
<div class="row g-3 mb-4">

    <!-- Top OT -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">🏆 Top tăng ca tháng <?= $thisMonthNum ?>/<?= $thisYear ?></h6>
            </div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Nhân viên</th>
                        <th class="text-center">Lần</th>
                        <th class="text-end pe-3">Giờ OT</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($topOT as $i => $r): ?>
                <tr>
                    <td class="ps-3">
                        <?php if ($i === 0): ?>
                            <span class="text-warning fw-bold">🥇</span>
                        <?php elseif ($i === 1): ?>
                            <span class="text-secondary fw-bold">🥈</span>
                        <?php elseif ($i === 2): ?>
                            <span style="color:#cd7f32" class="fw-bold">🥉</span>
                        <?php else: ?>
                            <span class="text-muted"><?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
                    <td class="text-center"><?= $r['ot_count'] ?></td>
                    <td class="text-end pe-3 fw-bold text-info"><?= number_format((float)$r['total_ot'],1) ?>h</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topOT)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Chưa có OT được duyệt</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Headcount theo phòng ban -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">🏢 Nhân sự theo phòng ban</h6>
            </div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Phòng ban</th>
                        <th class="text-center">Nhân sự</th>
                        <th class="text-end pe-3">Tỷ lệ</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($headcountByDept as $r):
                    $pct = $totalEmployees > 0
                        ? round($r['cnt'] / $totalEmployees * 100) : 0;
                ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars($r['department']) ?></td>
                    <td class="text-center fw-bold"><?= $r['cnt'] ?></td>
                    <td class="text-end pe-3">
                        <div class="d-flex align-items-center gap-1 justify-content-end">
                            <div class="progress flex-grow-1" style="height:6px;width:50px">
                                <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $pct ?>%</small>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($headcountByDept)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Chưa phân phòng ban</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- Ước tính quỹ lương theo phòng ban -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">💰 Quỹ lương ước tính theo PB</h6>
            </div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Phòng ban</th>
                        <th class="text-center">Người</th>
                        <th class="text-end pe-3">Ước tính/tháng</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($salaryByDept as $r): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars($r['department']) ?></td>
                    <td class="text-center"><?= $r['headcount'] ?></td>
                    <td class="text-end pe-3 fw-bold text-success"><?= formatMoney((float)$r['est_cost']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($salaryByDept)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Chưa có cấu hình lương</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Row 4: Nhân viên mới nhất ────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
        <h6 class="fw-bold mb-0">👤 Nhân viên mới nhất</h6>
        <a href="modules/employees/index.php" class="btn btn-sm btn-outline-primary"
           style="font-size:.75rem">Xem tất cả</a>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size:.83rem">
        <thead class="table-light">
            <tr>
                <th class="ps-3">Mã NV</th>
                <th>Họ tên</th>
                <th>Phòng ban</th>
                <th>Chức vụ</th>
                <th>Ngày vào</th>
                <th>SĐT</th>
                <th class="text-center">Role</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $roleColors = [
            'admin'      => 'warning',
            'superadmin' => 'danger',
            'accountant' => 'info',
            'dispatcher' => 'primary',
            'driver'     => 'success',
            'customer'   => 'secondary',
        ];
        foreach ($recentEmployees as $emp):
            $rc = $roleColors[$emp['role_name']] ?? 'secondary';
        ?>
        <tr>
            <td class="ps-3">
                <code style="font-size:.75rem"><?= htmlspecialchars($emp['employee_code'] ?? '—') ?></code>
            </td>
            <td class="fw-semibold"><?= htmlspecialchars($emp['full_name']) ?></td>
            <td><?= htmlspecialchars($emp['department']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($emp['position']) ?></td>
            <td><?= $emp['hire_date'] ? date('d/m/Y', strtotime($emp['hire_date'])) : '—' ?></td>
            <td class="text-muted"><?= htmlspecialchars($emp['phone'] ?? '—') ?></td>
            <td class="text-center">
                <span class="badge bg-<?= $rc ?>">
                    <?= ucfirst($emp['role_name']) ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentEmployees)): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">Chưa có nhân viên</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<!-- ── Quick Links ───────────────────────────────────────── -->
<div class="row g-3">
    <?php
    $links = [
        ['url'=>'modules/employees/index.php', 'icon'=>'fas fa-users',          'label'=>'Nhân viên',  'color'=>'primary',   'desc'=>'Quản lý hồ sơ'],
        ['url'=>'modules/attendance/index.php', 'icon'=>'fas fa-clock',          'label'=>'Chấm công',  'color'=>'success',   'desc'=>'Điểm danh hàng ngày'],
        ['url'=>'modules/overtime/index.php',   'icon'=>'fas fa-business-time',  'label'=>'Tăng ca',    'color'=>'info',      'desc'=>'Quản lý OT'],
        ['url'=>'modules/leave/index.php',      'icon'=>'fas fa-calendar-minus', 'label'=>'Nghỉ phép',  'color'=>'warning',   'desc'=>'Duyệt đơn nghỉ'],
        ['url'=>'modules/payroll/index.php',    'icon'=>'fas fa-money-bill-wave','label'=>'Bảng lương', 'color'=>'success',   'desc'=>'Tính & chốt lương'],
        ['url'=>'modules/reports/index.php',    'icon'=>'fas fa-chart-bar',      'label'=>'Báo cáo HR', 'color'=>'secondary', 'desc'=>'Thống kê nhân sự'],
    ];
    foreach ($links as $l): ?>
    <div class="col-6 col-md-2">
        <a href="<?= $l['url'] ?>" class="card border-0 shadow-sm text-decoration-none h-100 hover-card">
            <div class="card-body text-center py-3">
                <i class="<?= $l['icon'] ?> fa-2x text-<?= $l['color'] ?> mb-2"></i>
                <div class="small fw-semibold text-dark"><?= $l['label'] ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= $l['desc'] ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

</div><!-- /.container-fluid -->
</div><!-- /.main-content -->

<style>
.hover-card { transition: transform .15s, box-shadow .15s; }
.hover-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,.12) !important;
}
</style>

<?php if (!empty($attendanceChart)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const attData = <?= json_encode($attendanceChart, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('attendanceChart'), {
    type: 'bar',
    data: {
        labels: attData.map(r => r.work_date),
        datasets: [
            {
                label: 'Có mặt',
                data: attData.map(r => parseInt(r.present) || 0),
                backgroundColor: 'rgba(14,159,110,.75)',
                borderRadius: 5,
                borderSkipped: false,
            },
            {
                label: 'Vắng mặt',
                data: attData.map(r => parseInt(r.absent) || 0),
                backgroundColor: 'rgba(224,36,36,.65)',
                borderRadius: 5,
                borderSkipped: false,
            }
        ]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                callbacks: {
                    label: ctx => ctx.dataset.label + ': ' + ctx.raw + ' người'
                }
            }
        },
        scales: {
            x: { stacked: false, grid: { display: false } },
            y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f0f0f0' } }
        }
    }
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>