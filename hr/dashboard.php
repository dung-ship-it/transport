<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
requirePermission('hr', 'view');

$pdo       = getDBConnection();
$user      = currentUser();
$pageTitle = 'HR Dashboard';

$today        = date('Y-m-d');
$thisYear     = (int)date('Y');
$thisMonthNum = (int)date('m');

// ── Helpers ──────────────────────────────────────────────────
function hrCol(PDO $pdo, string $sql, array $p = []): mixed {
    try {
        $s = $pdo->prepare($sql); $s->execute($p);
        $v = $s->fetchColumn(); return $v === false ? 0 : $v;
    } catch (Exception $e) { error_log('hrCol: '.$e->getMessage()); return 0; }
}
function hrQuery(PDO $pdo, string $sql, array $p = []): array {
    try {
        $s = $pdo->prepare($sql); $s->execute($p);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { error_log('hrQuery: '.$e->getMessage()); return []; }
}

// ── KPIs ──────────────────────────────────────────────────────
$totalEmployees = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM users u
     JOIN roles r ON u.role_id = r.id
     WHERE u.is_active = TRUE AND r.name NOT IN ('customer')");

$presentToday = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_attendance WHERE work_date = ? AND status IN ('present','late')",
    [$today]);

$absentToday = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_attendance WHERE work_date = ? AND status = 'absent'",
    [$today]);

$pendingOT = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_overtime WHERE status = 'pending'");

$pendingLeave = (int)hrCol($pdo,
    "SELECT COUNT(*) FROM hr_leaves WHERE status = 'pending'");

// Bảng lương tháng hiện tại
$currentPayroll = hrQuery($pdo,
    "SELECT * FROM hr_payroll_periods
     WHERE period_year = ? AND period_month = ? LIMIT 1",
    [$thisYear, $thisMonthNum]);
$currentPayroll = $currentPayroll[0] ?? null;

// Tổng quỹ lương tháng này — PostgreSQL
$totalPayroll = (float)hrCol($pdo,
    "SELECT COALESCE(SUM(pi.net_salary),0)
     FROM hr_payroll_items pi
     JOIN hr_payroll_periods pp ON pi.period_id = pp.id
     WHERE pp.period_year = ? AND pp.period_month = ?",
    [$thisYear, $thisMonthNum]);

// OT tháng này — PostgreSQL EXTRACT
$totalOTHours = (float)hrCol($pdo,
    "SELECT COALESCE(SUM(ot_hours),0) FROM hr_overtime
     WHERE EXTRACT(YEAR FROM ot_date) = EXTRACT(YEAR FROM CURRENT_DATE)
       AND EXTRACT(MONTH FROM ot_date) = EXTRACT(MONTH FROM CURRENT_DATE)
       AND status = 'approved'");

// Chấm công 7 ngày gần nhất
$attendanceChart = hrQuery($pdo, "
    SELECT work_date::text AS work_date,
           COUNT(*) FILTER (WHERE status IN ('present','late')) AS present,
           COUNT(*) FILTER (WHERE status = 'absent') AS absent
    FROM hr_attendance
    WHERE work_date >= CURRENT_DATE - INTERVAL '6 days'
    GROUP BY work_date ORDER BY work_date
");

// Pending approvals
$pendingOTList = hrQuery($pdo, "
    SELECT ho.*, u.full_name
    FROM hr_overtime ho JOIN users u ON ho.user_id = u.id
    WHERE ho.status = 'pending'
    ORDER BY ho.ot_date DESC LIMIT 5
");

$pendingLeaveList = hrQuery($pdo, "
    SELECT hl.*, u.full_name
    FROM hr_leaves hl JOIN users u ON hl.user_id = u.id
    WHERE hl.status = 'pending'
    ORDER BY hl.date_from ASC LIMIT 5
");

// Top OT tháng này
$topOT = hrQuery($pdo, "
    SELECT u.full_name,
           SUM(ho.ot_hours) AS total_ot,
           COUNT(ho.id) AS ot_count
    FROM hr_overtime ho JOIN users u ON ho.user_id = u.id
    WHERE EXTRACT(YEAR FROM ho.ot_date)  = EXTRACT(YEAR FROM CURRENT_DATE)
      AND EXTRACT(MONTH FROM ho.ot_date) = EXTRACT(MONTH FROM CURRENT_DATE)
      AND ho.status = 'approved'
    GROUP BY u.id, u.full_name
    ORDER BY total_ot DESC LIMIT 5
");

// Lương theo department
$salaryByDept = hrQuery($pdo, "
    SELECT sc.department,
           COUNT(*) AS headcount,
           COALESCE(SUM(sc.base_salary + sc.allowance), 0) AS est_cost
    FROM hr_salary_configs sc
    WHERE sc.is_active = TRUE
    GROUP BY sc.department
    ORDER BY est_cost DESC
");

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">👥 HR Dashboard</h4>
        <small class="text-muted">
            <?= date('l, d/m/Y') ?> —
            <?php if ($currentPayroll): ?>
                Bảng lương <?= $thisMonthNum ?>/<?= $thisYear ?>:
                <span class="badge bg-<?= $currentPayroll['status']==='locked'?'success':'warning text-dark' ?>">
                    <?= $currentPayroll['status']==='locked' ? '🔒 Đã chốt' : '⏳ Đang xử lý' ?>
                </span>
            <?php else: ?>
                <span class="badge bg-secondary">Chưa tạo bảng lương tháng này</span>
            <?php endif; ?>
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="modules/attendance/index.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-clock me-1"></i>Chấm công
        </a>
        <a href="modules/payroll/index.php" class="btn btn-sm btn-success">
            <i class="fas fa-money-bill-wave me-1"></i>Bảng lương
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
<?php
$kpis = [
    ['val'=>$totalEmployees,                        'lbl'=>'Tổng nhân viên',  'icon'=>'fas fa-users',          'color'=>'primary',   'sub'=>'đang làm việc'],
    ['val'=>$presentToday,                          'lbl'=>'Có mặt hôm nay', 'icon'=>'fas fa-user-check',     'color'=>'success',   'sub'=>'đã chấm công'],
    ['val'=>$absentToday,                           'lbl'=>'Vắng mặt',       'icon'=>'fas fa-user-times',     'color'=>'danger',    'sub'=>'hôm nay'],
    ['val'=>$pendingLeave.' yêu cầu',               'lbl'=>'Chờ duyệt phép', 'icon'=>'fas fa-calendar-times', 'color'=>'warning',   'sub'=>'cần xử lý'],
    ['val'=>$pendingOT.' yêu cầu',                  'lbl'=>'Chờ duyệt OT',  'icon'=>'fas fa-hourglass-half',  'color'=>'info',      'sub'=>'tăng ca chờ duyệt'],
    ['val'=>number_format($totalOTHours,1).'h',     'lbl'=>'OT tháng này',   'icon'=>'fas fa-business-time',  'color'=>'secondary', 'sub'=>'đã được duyệt'],
    ['val'=>formatMoney($totalPayroll),             'lbl'=>'Quỹ lương tháng','icon'=>'fas fa-money-bill-wave','color'=>'success',   'sub'=>date('m/Y')],
    ['val'=>$totalEmployees>0 ? formatMoney($totalPayroll/$totalEmployees) : '0 đ',
                                                    'lbl'=>'Lương TB/người', 'icon'=>'fas fa-coins',          'color'=>'primary',   'sub'=>'tháng này'],
];
foreach ($kpis as $k): ?>
<div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body py-3">
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="<?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
                <small class="text-muted"><?= $k['lbl'] ?></small>
            </div>
            <div class="fw-bold fs-5 text-<?= $k['color'] ?>"><?= $k['val'] ?></div>
            <div class="text-muted" style="font-size:.75rem"><?= $k['sub'] ?></div>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Row 2: Chart + Pending -->
<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">📅 Chấm công 7 ngày gần nhất</h6>
            </div>
            <div class="card-body">
                <?php if (!empty($attendanceChart)): ?>
                <canvas id="attendanceChart" height="120"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-4">Chưa có dữ liệu chấm công</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2 d-flex justify-content-between">
                <h6 class="fw-bold mb-0">🔔 Cần phê duyệt</h6>
                <?php if ($pendingLeave + $pendingOT > 0): ?>
                <span class="badge bg-danger rounded-pill"><?= $pendingLeave + $pendingOT ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($pendingLeaveList)): ?>
                <div class="px-3 pt-2 pb-1"><small class="text-muted fw-semibold">📋 NGHỈ PHÉP</small></div>
                <?php foreach ($pendingLeaveList as $lv): ?>
                <div class="d-flex align-items-center px-3 py-2 border-bottom" style="font-size:.82rem">
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= htmlspecialchars($lv['full_name']) ?></div>
                        <div class="text-muted">
                            <?= date('d/m',strtotime($lv['date_from'])) ?> →
                            <?= date('d/m',strtotime($lv['date_to'])) ?>
                            (<?= $lv['days_count'] ?> ngày)
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="modules/leave/index.php?action=approve&id=<?= $lv['id'] ?>"
                           class="btn btn-xs btn-success" style="font-size:.72rem;padding:2px 8px">✓</a>
                        <a href="modules/leave/index.php?action=reject&id=<?= $lv['id'] ?>"
                           class="btn btn-xs btn-danger" style="font-size:.72rem;padding:2px 8px">✗</a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($pendingOTList)): ?>
                <div class="px-3 pt-2 pb-1"><small class="text-muted fw-semibold">⏱ TĂNG CA</small></div>
                <?php foreach ($pendingOTList as $ot): ?>
                <div class="d-flex align-items-center px-3 py-2 border-bottom" style="font-size:.82rem">
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= htmlspecialchars($ot['full_name']) ?></div>
                        <div class="text-muted">
                            <?= date('d/m/Y',strtotime($ot['ot_date'])) ?> ·
                            <?= $ot['ot_hours'] ?>h · x<?= $ot['ot_rate'] ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <a href="modules/overtime/index.php?action=approve&id=<?= $ot['id'] ?>"
                           class="btn btn-xs btn-success" style="font-size:.72rem;padding:2px 8px">✓</a>
                        <a href="modules/overtime/index.php?action=reject&id=<?= $ot['id'] ?>"
                           class="btn btn-xs btn-danger" style="font-size:.72rem;padding:2px 8px">✗</a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (empty($pendingLeaveList) && empty($pendingOTList)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-check-circle text-success fs-4 d-block mb-2"></i>
                    Không có yêu cầu chờ duyệt
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Row 3: Top OT + Lương phòng ban -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">🏆 Top tăng ca tháng này</h6>
            </div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr><th class="ps-3">Nhân viên</th><th class="text-center">Lần OT</th><th class="text-end">Tổng giờ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($topOT as $r): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= htmlspecialchars($r['full_name']) ?></td>
                    <td class="text-center"><?= $r['ot_count'] ?></td>
                    <td class="text-end fw-bold text-info"><?= number_format((float)$r['total_ot'],1) ?>h</td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($topOT)): ?>
                <tr><td colspan="3" class="text-center text-muted py-3">Chưa có OT được duyệt</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-2">
                <h6 class="fw-bold mb-0">🏢 Chi phí lương theo phòng ban</h6>
            </div>
            <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0" style="font-size:.83rem">
                <thead class="table-light">
                    <tr><th class="ps-3">Phòng ban</th><th class="text-center">Headcount</th><th class="text-end">Ước tính/tháng</th></tr>
                </thead>
                <tbody>
                <?php foreach ($salaryByDept as $r): ?>
                <tr>
                    <td class="ps-3"><?= htmlspecialchars($r['department'] ?? 'Chưa phân loại') ?></td>
                    <td class="text-center"><?= $r['headcount'] ?></td>
                    <td class="text-end fw-bold text-success"><?= formatMoney((float)$r['est_cost']) ?></td>
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

<!-- Quick links -->
<div class="row g-3">
<?php
$links = [
    ['url'=>'modules/employees/index.php', 'icon'=>'fas fa-users',          'label'=>'Nhân viên',  'color'=>'primary'],
    ['url'=>'modules/attendance/index.php','icon'=>'fas fa-clock',           'label'=>'Chấm công',  'color'=>'success'],
    ['url'=>'modules/overtime/index.php',  'icon'=>'fas fa-business-time',   'label'=>'Tăng ca',    'color'=>'info'],
    ['url'=>'modules/leave/index.php',     'icon'=>'fas fa-calendar-minus',  'label'=>'Nghỉ phép',  'color'=>'warning'],
    ['url'=>'modules/payroll/index.php',   'icon'=>'fas fa-money-bill-wave', 'label'=>'Bảng lương', 'color'=>'success'],
    ['url'=>'modules/reports/index.php',   'icon'=>'fas fa-chart-bar',       'label'=>'Báo cáo HR', 'color'=>'secondary'],
];
foreach ($links as $l): ?>
<div class="col-6 col-md-2">
    <a href="<?= $l['url'] ?>" class="card border-0 shadow-sm text-decoration-none h-100">
        <div class="card-body text-center py-3">
            <i class="<?= $l['icon'] ?> fa-2x text-<?= $l['color'] ?> mb-2"></i>
            <div class="small fw-semibold text-dark"><?= $l['label'] ?></div>
        </div>
    </a>
</div>
<?php endforeach; ?>
</div>

</div>
</div>

<?php if (!empty($attendanceChart)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const attData = <?= json_encode($attendanceChart, JSON_UNESCAPED_UNICODE) ?>;
new Chart(document.getElementById('attendanceChart'), {
    type: 'bar',
    data: {
        labels: attData.map(r => r.work_date),
        datasets: [
            { label:'Có mặt', data:attData.map(r=>parseInt(r.present)), backgroundColor:'rgba(14,159,110,.7)', borderRadius:4 },
            { label:'Vắng',   data:attData.map(r=>parseInt(r.absent)),  backgroundColor:'rgba(224,36,36,.6)',  borderRadius:4 }
        ]
    },
    options: { responsive:true, plugins:{legend:{position:'top'}}, scales:{x:{stacked:false},y:{beginAtZero:true,ticks:{stepSize:1}}} }
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>