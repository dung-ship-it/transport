<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('kpi', 'view');

$pdo       = getDBConnection();
$user      = currentUser();
$pageTitle = 'KPI Lái Xe';
$canManage = can('kpi', 'manage');
$canCalc   = can('kpi', 'calculate');

// ── Kỳ mặc định: 26 tháng trước → 25 tháng này ─────────────
$today = new DateTime();
$day   = (int)$today->format('d');
if ($day >= 26) {
    $periodFrom = (clone $today)->setDate((int)$today->format('Y'), (int)$today->format('m'), 26);
    $periodTo   = (clone $today)->modify('+1 month')->setDate(
        (int)(clone $today)->modify('+1 month')->format('Y'),
        (int)(clone $today)->modify('+1 month')->format('m'),
        25
    );
} else {
    $periodFrom = (clone $today)->modify('-1 month')->setDate(
        (int)(clone $today)->modify('-1 month')->format('Y'),
        (int)(clone $today)->modify('-1 month')->format('m'),
        26
    );
    $periodTo = (clone $today)->setDate((int)$today->format('Y'), (int)$today->format('m'), 25);
}

$dateFrom     = $_GET['date_from']  ?? $periodFrom->format('Y-m-d');
$dateTo       = $_GET['date_to']    ?? $periodTo->format('Y-m-d');
$filterDriver = (int)($_GET['driver_id'] ?? 0);
$tab          = $_GET['tab'] ?? 'overview';

// ── Load KPI config ─────────────────────────────────────────
$kpiConfig = [];
$cfgRows   = $pdo->query("SELECT * FROM kpi_config WHERE is_active = TRUE ORDER BY id")->fetchAll();
foreach ($cfgRows as $c) {
    $kpiConfig[$c['key']] = $c;
}

// ── Lưu config khi POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config']) && $canManage) {
    foreach (['fuel','safety','vehicle','customer'] as $key) {
        $weight = (float)($_POST["weight_$key"] ?? 0);
        $target = (float)($_POST["target_$key"] ?? 0);
        $pdo->prepare("
            UPDATE kpi_config SET weight = ?, target = ?, updated_at = NOW() WHERE key = ?
        ")->execute([$weight, $target, $key]);
    }
    $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã lưu cấu hình KPI!'];
    header("Location: index.php?tab=config&date_from=$dateFrom&date_to=$dateTo"); exit;
}

// ── Tính KPI khi POST ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['calculate']) && $canCalc) {
    $calcFrom = $_POST['calc_from'];
    $calcTo   = $_POST['calc_to'];

    // Lấy tất cả lái xe có chuyến trong kỳ
    $drvStmt = $pdo->prepare("
        SELECT DISTINCT d.id AS driver_id, u.full_name
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        JOIN users u   ON d.user_id   = u.id
        WHERE t.trip_date BETWEEN ? AND ?
          AND t.status IN ('completed','confirmed')
    ");
    $drvStmt->execute([$calcFrom, $calcTo]);
    $drivers = $drvStmt->fetchAll();

    foreach ($drivers as $drv) {
        $did = $drv['driver_id'];

        // 1. Dữ liệu chuyến xe
        $tripData = $pdo->prepare("
            SELECT COUNT(*) AS total_trips,
                   COALESCE(SUM(total_km), 0) AS total_km
            FROM trips
            WHERE driver_id = ? AND trip_date BETWEEN ? AND ?
              AND status IN ('completed','confirmed')
        ");
        $tripData->execute([$did, $calcFrom, $calcTo]);
        $td = $tripData->fetch();

        // 2. Dữ liệu nhiên liệu — lấy định mức riêng theo từng xe
        $fuelData = $pdo->prepare("
            SELECT
                fl.vehicle_id,
                COALESCE(v.fuel_quota, 0)                    AS target_fuel_rate,
                COALESCE(SUM(fl.liters_filled), 0)           AS total_liters,
                COALESCE(SUM(fl.km_after - fl.km_before), 0) AS fuel_km
            FROM fuel_logs fl
            JOIN vehicles v ON fl.vehicle_id = v.id
            WHERE fl.driver_id = ?
              AND fl.log_date BETWEEN ? AND ?
              AND fl.km_after  IS NOT NULL
              AND fl.km_before IS NOT NULL
            GROUP BY fl.vehicle_id, v.fuel_quota
        ");
        $fuelData->execute([$did, $calcFrom, $calcTo]);
        $fuelRows = $fuelData->fetchAll();

        // Tính tổng hợp nhiều xe: weighted average theo km
        $totalLiters    = 0;
        $totalFuelKm    = 0;
        $weightedTarget = 0;

        foreach ($fuelRows as $fr) {
            $totalLiters    += (float)$fr['total_liters'];
            $totalFuelKm    += (float)$fr['fuel_km'];
            // Định mức trung bình có trọng số theo km
            $weightedTarget += (float)$fr['target_fuel_rate'] * (float)$fr['fuel_km'];
        }

        // Định mức trung bình theo km (fallback về config nếu không có data)
        $targetFuelRate = ($totalFuelKm > 0)
            ? ($weightedTarget / $totalFuelKm)
            : (float)($kpiConfig['fuel']['target'] ?? 8.5);

        $actualFuelRate = ($totalFuelKm > 0)
            ? ($totalLiters / $totalFuelKm * 100)
            : 0;

        // 3. Hư hỏng / bảo dưỡng lỗi chủ quan
        $faults = 0;
        try {
            $faultData = $pdo->prepare("
                SELECT COUNT(*) FROM vehicle_maintenance vm
                JOIN trips t ON t.vehicle_id = vm.vehicle_id
                WHERE t.driver_id = ?
                  AND vm.maintenance_date BETWEEN ? AND ?
                  AND vm.is_driver_fault = TRUE
            ");
            $faultData->execute([$did, $calcFrom, $calcTo]);
            $faults = (int)($faultData->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            $faults = 0;
        }

        // 4. Điểm khách hàng
        $avgRating = 0;
        try {
            $ratingData = $pdo->prepare("
                SELECT COALESCE(AVG(rating), 0)
                FROM driver_ratings
                WHERE driver_id = ? AND rated_at::date BETWEEN ? AND ?
            ");
            $ratingData->execute([$did, $calcFrom, $calcTo]);
            $avgRating = (float)($ratingData->fetchColumn() ?? 0);
        } catch (PDOException $e) {
            $avgRating = 0;
        }

        // ── Tính điểm nhiên liệu so với định mức riêng của xe ──
        if ($actualFuelRate <= 0 || $targetFuelRate <= 0) {
            $scoreFuel = 100;
        } elseif ($actualFuelRate <= $targetFuelRate) {
            $scoreFuel = 100;
        } elseif ($actualFuelRate <= $targetFuelRate * 1.05) {
            // Vượt ≤5%: 90–100
            $scoreFuel = 100 - (($actualFuelRate - $targetFuelRate) / ($targetFuelRate * 0.05)) * 10;
        } elseif ($actualFuelRate <= $targetFuelRate * 1.10) {
            // Vượt 5–10%: 70–90
            $scoreFuel = 90 - (($actualFuelRate - $targetFuelRate * 1.05) / ($targetFuelRate * 0.05)) * 20;
        } elseif ($actualFuelRate <= $targetFuelRate * 1.20) {
            // Vượt 10–20%: 40–70
            $scoreFuel = 70 - (($actualFuelRate - $targetFuelRate * 1.10) / ($targetFuelRate * 0.10)) * 30;
        } else {
            // Vượt >20%: 0–40
            $scoreFuel = max(0, 40 - (($actualFuelRate - $targetFuelRate * 1.20) / ($targetFuelRate * 0.10)) * 10);
        }

        // ── An toàn: mỗi lần lỗi -25đ ──────────────────────────
        $scoreSafety = max(0, 100 - ($faults * 25));

        // ── Bảo quản xe: mỗi lần vi phạm -20đ ─────────────────
        $scoreVehicle = max(0, 100 - ($faults * 20));

        // ── Khách hàng: scale 1–5 sao → 0–100 ─────────────────
        $scoreCustomer = $avgRating > 0 ? ($avgRating / 5) * 100 : 80;

        // ── Điểm tổng hợp theo trọng số ────────────────────────
        $wFuel     = (float)($kpiConfig['fuel']['weight']     ?? 40) / 100;
        $wSafety   = (float)($kpiConfig['safety']['weight']   ?? 40) / 100;
        $wVehicle  = (float)($kpiConfig['vehicle']['weight']  ?? 10) / 100;
        $wCustomer = (float)($kpiConfig['customer']['weight'] ?? 10) / 100;

        $scoreTotal = $scoreFuel     * $wFuel
                    + $scoreSafety   * $wSafety
                    + $scoreVehicle  * $wVehicle
                    + $scoreCustomer * $wCustomer;

        // Grade
        $grade = 'D';
        if ($scoreTotal >= 95)      $grade = 'A+';
        elseif ($scoreTotal >= 85)  $grade = 'A';
        elseif ($scoreTotal >= 75)  $grade = 'B';
        elseif ($scoreTotal >= 60)  $grade = 'C';

        // Upsert
        $pdo->prepare("
            INSERT INTO kpi_scores
                (driver_id, period_from, period_to,
                 score_fuel, score_safety, score_vehicle, score_customer,
                 actual_fuel_rate, target_fuel_rate,
                 maintenance_faults, customer_rating,
                 total_km, total_fuel_liters, total_trips,
                 score_total, grade, calculated_by, calculated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON CONFLICT (driver_id, period_from, period_to)
            DO UPDATE SET
                score_fuel         = EXCLUDED.score_fuel,
                score_safety       = EXCLUDED.score_safety,
                score_vehicle      = EXCLUDED.score_vehicle,
                score_customer     = EXCLUDED.score_customer,
                actual_fuel_rate   = EXCLUDED.actual_fuel_rate,
                target_fuel_rate   = EXCLUDED.target_fuel_rate,
                maintenance_faults = EXCLUDED.maintenance_faults,
                customer_rating    = EXCLUDED.customer_rating,
                total_km           = EXCLUDED.total_km,
                total_fuel_liters  = EXCLUDED.total_fuel_liters,
                total_trips        = EXCLUDED.total_trips,
                score_total        = EXCLUDED.score_total,
                grade              = EXCLUDED.grade,
                calculated_by      = EXCLUDED.calculated_by,
                calculated_at      = NOW()
        ")->execute([
            $did, $calcFrom, $calcTo,
            round($scoreFuel, 2),    round($scoreSafety, 2),
            round($scoreVehicle, 2), round($scoreCustomer, 2),
            round($actualFuelRate, 4), round($targetFuelRate, 4),
            $faults, $avgRating > 0 ? $avgRating : null,
            $td['total_km'], $totalLiters, $td['total_trips'],
            round($scoreTotal, 2), $grade,
            $user['id'],
        ]);
    }

    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => '✅ Đã tính KPI cho ' . count($drivers) . ' lái xe!'
    ];
    header("Location: index.php?date_from=$calcFrom&date_to=$calcTo&tab=overview"); exit;
}

// ── Load KPI scores để hiển thị ─────────────────────────────
$scoreQuery  = "
    SELECT ks.*, u.full_name AS driver_name, u.id AS user_id, d.license_number
    FROM kpi_scores ks
    JOIN drivers d ON ks.driver_id = d.id
    JOIN users u   ON d.user_id    = u.id
    WHERE ks.period_from = ? AND ks.period_to = ?
";
$scoreParams = [$dateFrom, $dateTo];
if ($filterDriver) {
    $scoreQuery   .= " AND ks.driver_id = ?";
    $scoreParams[] = $filterDriver;
}
$scoreQuery .= " ORDER BY ks.score_total DESC";

$scoresStmt = $pdo->prepare($scoreQuery);
$scoresStmt->execute($scoreParams);
$scores = $scoresStmt->fetchAll();

// ── Danh sách lái xe cho bộ lọc ─────────────────────────────
$driverList = $pdo->query("
    SELECT d.id, u.full_name
    FROM drivers d JOIN users u ON d.user_id = u.id
    WHERE d.is_active = TRUE ORDER BY u.full_name
")->fetchAll();

// ── Helper functions ─────────────────────────────────────────
function gradeColor(string $grade): string {
    return match(true) {
        $grade === 'A+' => 'success',
        $grade === 'A'  => 'info',
        $grade === 'B'  => 'primary',
        $grade === 'C'  => 'warning',
        default         => 'danger',
    };
}

function scoreBar(float $score): string {
    $color = $score >= 85 ? 'success' : ($score >= 70 ? 'warning' : 'danger');
    return "<div class='progress' style='height:6px;border-radius:3px'>
                <div class='progress-bar bg-{$color}' style='width:{$score}%'></div>
            </div>";
}

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- ── Header ── -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-chart-bar me-2 text-primary"></i>KPI Lái Xe
            </h4>
            <small class="text-muted">
                Kỳ: <strong><?= date('d/m/Y', strtotime($dateFrom)) ?></strong>
                → <strong><?= date('d/m/Y', strtotime($dateTo)) ?></strong>
            </small>
        </div>
        <?php if ($canCalc): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#calcModal">
            <i class="fas fa-calculator me-2"></i>Tính KPI
        </button>
        <?php endif; ?>
    </div>

    <?php showFlash(); ?>

    <!-- ── Tabs ── -->
    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a href="?tab=overview&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
               class="nav-link <?= $tab==='overview'?'active':'' ?>">
                <i class="fas fa-chart-pie me-1"></i>Tổng quan
            </a>
        </li>
        <li class="nav-item">
            <a href="?tab=detail&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
               class="nav-link <?= $tab==='detail'?'active':'' ?>">
                <i class="fas fa-table me-1"></i>Chi tiết
            </a>
        </li>
        <?php if ($canManage): ?>
        <li class="nav-item">
            <a href="?tab=config&date_from=<?= $dateFrom ?>&date_to=<?= $dateTo ?>"
               class="nav-link <?= $tab==='config'?'active':'' ?>">
                <i class="fas fa-cog me-1"></i>Cấu hình
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <!-- ── Bộ lọc ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="<?= $tab ?>">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Từ ngày</label>
                    <input type="date" name="date_from" class="form-control form-control-sm"
                           value="<?= $dateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Đến ngày</label>
                    <input type="date" name="date_to" class="form-control form-control-sm"
                           value="<?= $dateTo ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold mb-1">Lái xe</label>
                    <select name="driver_id" class="form-select form-select-sm">
                        <option value="">-- Tất cả --</option>
                        <?php foreach ($driverList as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= $filterDriver == $d['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['full_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-search me-1"></i>Lọc
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- ══════════════════════════════════════════════════════
         TAB: TỔNG QUAN
    ══════════════════════════════════════════════════════ -->
    <?php if (empty($scores)): ?>
    <div class="card border-0 shadow-sm p-5 text-center">
        <i class="fas fa-chart-bar fa-3x text-muted opacity-25 mb-3"></i>
        <h5 class="text-muted">Chưa có dữ liệu KPI</h5>
        <p class="text-muted small">Nhấn <strong>Tính KPI</strong> để tính toán cho kỳ này</p>
    </div>
    <?php else: ?>

    <?php
    $avgScore   = array_sum(array_column($scores, 'score_total')) / count($scores);
    $gradeCount = array_count_values(array_column($scores, 'grade'));
    ?>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-primary border-4">
                <div class="fs-2 fw-bold text-primary"><?= count($scores) ?></div>
                <div class="small text-muted">Lái xe được đánh giá</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-success border-4">
                <div class="fs-2 fw-bold text-success"><?= number_format($avgScore, 1) ?></div>
                <div class="small text-muted">Điểm trung bình</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-warning border-4">
                <div class="fs-2 fw-bold text-warning">
                    <?= ($gradeCount['A+'] ?? 0) + ($gradeCount['A'] ?? 0) ?>
                </div>
                <div class="small text-muted">Xuất sắc (A+ / A)</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3 border-start border-danger border-4">
                <div class="fs-2 fw-bold text-danger"><?= $gradeCount['D'] ?? 0 ?></div>
                <div class="small text-muted">Cần cải thiện (D)</div>
            </div>
        </div>
    </div>

    <!-- Bảng xếp hạng -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">🏆 Bảng xếp hạng KPI</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:0.85rem">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-3 text-center" style="width:50px">Hạng</th>
                        <th>Lái xe</th>
                        <th class="text-center">Grade</th>
                        <th class="text-center">
                            ⛽ Nhiên liệu
                            <br><small class="fw-normal opacity-75">(<?= $kpiConfig['fuel']['weight'] ?? 40 ?>%)</small>
                        </th>
                        <th class="text-center">
                            🛡️ An toàn
                            <br><small class="fw-normal opacity-75">(<?= $kpiConfig['safety']['weight'] ?? 40 ?>%)</small>
                        </th>
                        <th class="text-center">
                            🔧 Bảo quản
                            <br><small class="fw-normal opacity-75">(<?= $kpiConfig['vehicle']['weight'] ?? 10 ?>%)</small>
                        </th>
                        <th class="text-center">
                            ⭐ KH chấm
                            <br><small class="fw-normal opacity-75">(<?= $kpiConfig['customer']['weight'] ?? 10 ?>%)</small>
                        </th>
                        <th class="text-end pe-3">Điểm tổng</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($scores as $i => $s): ?>
                <tr>
                    <td class="text-center ps-3">
                        <?php if ($i === 0):      ?><span class="fs-5">🥇</span>
                        <?php elseif ($i === 1):  ?><span class="fs-5">🥈</span>
                        <?php elseif ($i === 2):  ?><span class="fs-5">🥉</span>
                        <?php else:               ?><span class="text-muted fw-bold"><?= $i+1 ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($s['driver_name']) ?></div>
                        <div class="text-muted small">
                            <?= $s['total_trips'] ?> chuyến
                            · <?= number_format($s['total_km'], 0) ?> km
                        </div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-<?= gradeColor($s['grade']) ?> fs-6 px-3">
                            <?= $s['grade'] ?>
                        </span>
                    </td>

                    <!-- Nhiên liệu -->
                    <td class="text-center">
                        <div class="fw-semibold <?= $s['score_fuel'] >= 85 ? 'text-success' : ($s['score_fuel'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= number_format($s['score_fuel'], 1) ?>đ
                        </div>
                        <?= scoreBar((float)$s['score_fuel']) ?>
                        <?php if ($s['actual_fuel_rate'] > 0): ?>
                        <div class="text-muted" style="font-size:0.7rem">
                            <?= number_format($s['actual_fuel_rate'], 2) ?> L/100km
                            (ĐM: <?= number_format($s['target_fuel_rate'], 1) ?>)
                            <?php if ($s['actual_fuel_rate'] > $s['target_fuel_rate']): ?>
                            <span class="text-danger">
                                ▲ <?= number_format($s['actual_fuel_rate'] - $s['target_fuel_rate'], 2) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-success">✓</span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-muted" style="font-size:0.7rem">Chưa có dữ liệu</div>
                        <?php endif; ?>
                    </td>

                    <!-- An toàn -->
                    <td class="text-center">
                        <div class="fw-semibold <?= $s['score_safety'] >= 85 ? 'text-success' : ($s['score_safety'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= number_format($s['score_safety'], 1) ?>đ
                        </div>
                        <?= scoreBar((float)$s['score_safety']) ?>
                        <div class="text-muted" style="font-size:0.7rem">
                            <?= $s['maintenance_faults'] ?> lần lỗi
                        </div>
                    </td>

                    <!-- Bảo quản -->
                    <td class="text-center">
                        <div class="fw-semibold <?= $s['score_vehicle'] >= 85 ? 'text-success' : ($s['score_vehicle'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= number_format($s['score_vehicle'], 1) ?>đ
                        </div>
                        <?= scoreBar((float)$s['score_vehicle']) ?>
                    </td>

                    <!-- Khách hàng -->
                    <td class="text-center">
                        <div class="fw-semibold <?= $s['score_customer'] >= 85 ? 'text-success' : ($s['score_customer'] >= 70 ? 'text-warning' : 'text-danger') ?>">
                            <?= number_format($s['score_customer'], 1) ?>đ
                        </div>
                        <?= scoreBar((float)$s['score_customer']) ?>
                        <?php if ($s['customer_rating']): ?>
                        <div class="text-muted" style="font-size:0.7rem">
                            ⭐ <?= number_format($s['customer_rating'], 1) ?>/5
                        </div>
                        <?php else: ?>
                        <div class="text-muted" style="font-size:0.7rem">Chưa có đánh giá</div>
                        <?php endif; ?>
                    </td>

                    <!-- Tổng -->
                    <td class="text-end pe-3">
                        <div class="fw-bold fs-5 text-<?= gradeColor($s['grade']) ?>">
                            <?= number_format($s['score_total'], 1) ?>
                        </div>
                        <div class="small text-muted">/100</div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'detail'): ?>
    <!-- ══════════════════════════════════════════════════════
         TAB: CHI TIẾT
    ══════════════════════════════════════════════════════ -->
    <?php if (empty($scores)): ?>
    <div class="card border-0 shadow-sm p-5 text-center">
        <i class="fas fa-chart-bar fa-3x text-muted opacity-25 mb-3"></i>
        <p class="text-muted">Chưa có dữ liệu. Nhấn <strong>Tính KPI</strong> trước.</p>
    </div>
    <?php endif; ?>

    <?php foreach ($scores as $s): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center"
             style="background:#0f3460;color:#fff">
            <div class="fw-bold">
                👤 <?= htmlspecialchars($s['driver_name']) ?>
            </div>
            <span class="badge bg-<?= gradeColor($s['grade']) ?> fs-6">
                <?= $s['grade'] ?> — <?= number_format($s['score_total'], 1) ?>đ
            </span>
        </div>
        <div class="card-body">

            <!-- 4 tiêu chí -->
            <div class="row g-3">
            <?php
            $criteria = [
                [
                    'key'    => 'fuel',
                    'label'  => '⛽ Nhiên liệu',
                    'score'  => (float)$s['score_fuel'],
                    'detail' => $s['actual_fuel_rate'] > 0
                        ? number_format($s['actual_fuel_rate'], 2) . ' L/100km'
                          . ' (Định mức xe: ' . number_format($s['target_fuel_rate'], 1) . ' L/100km)'
                        : 'Không có dữ liệu nhiên liệu',
                ],
                [
                    'key'    => 'safety',
                    'label'  => '🛡️ An toàn',
                    'score'  => (float)$s['score_safety'],
                    'detail' => $s['maintenance_faults'] . ' lần hư hỏng lỗi chủ quan',
                ],
                [
                    'key'    => 'vehicle',
                    'label'  => '🔧 Bảo quản xe',
                    'score'  => (float)$s['score_vehicle'],
                    'detail' => 'Dựa trên lỗi vi phạm bảo dưỡng',
                ],
                [
                    'key'    => 'customer',
                    'label'  => '⭐ Khách hàng',
                    'score'  => (float)$s['score_customer'],
                    'detail' => $s['customer_rating']
                        ? 'Điểm trung bình: ' . number_format($s['customer_rating'], 1) . '/5'
                        : 'Chưa có đánh giá từ KH (mặc định 80đ)',
                ],
            ];
            foreach ($criteria as $cr):
                $sc  = $cr['score'];
                $col = $sc >= 85 ? 'success' : ($sc >= 70 ? 'warning' : 'danger');
                $w   = (float)($kpiConfig[$cr['key']]['weight'] ?? 0);
            ?>
            <div class="col-md-3">
                <div class="card border-<?= $col ?> border-2 h-100">
                    <div class="card-body text-center p-3">
                        <div class="fs-5 mb-1"><?= $cr['label'] ?></div>
                        <div class="display-6 fw-bold text-<?= $col ?>">
                            <?= number_format($sc, 1) ?>
                        </div>
                        <div class="text-muted small mb-2">/ 100 điểm</div>
                        <div class="progress mb-2" style="height:8px">
                            <div class="progress-bar bg-<?= $col ?>" style="width:<?= $sc ?>%"></div>
                        </div>
                        <div class="text-muted small"><?= $cr['detail'] ?></div>
                        <div class="badge bg-<?= $col ?> bg-opacity-10 text-<?= $col ?> mt-2">
                            Trọng số: <?= $w ?>%
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>

            <!-- Công thức -->
            <?php
            $wF = $kpiConfig['fuel']['weight']     ?? 40;
            $wS = $kpiConfig['safety']['weight']   ?? 40;
            $wV = $kpiConfig['vehicle']['weight']  ?? 10;
            $wC = $kpiConfig['customer']['weight'] ?? 10;
            ?>
            <div class="mt-3 p-3 rounded" style="background:#f8f9fa;font-size:0.82rem">
                <strong>📐 Công thức tính:</strong><br>
                <span class="text-warning"><?= number_format($s['score_fuel'],1) ?> × <?= $wF ?>%</span>
                + <span class="text-danger"><?= number_format($s['score_safety'],1) ?> × <?= $wS ?>%</span>
                + <span class="text-info"><?= number_format($s['score_vehicle'],1) ?> × <?= $wV ?>%</span>
                + <span class="text-success"><?= number_format($s['score_customer'],1) ?> × <?= $wC ?>%</span>
                = <strong class="text-<?= gradeColor($s['grade']) ?> fs-6">
                    <?= number_format($s['score_total'], 2) ?> điểm
                  </strong>
            </div>

            <!-- Thống kê bổ sung -->
            <div class="row g-2 mt-2">
                <div class="col-md-3 col-6">
                    <div class="p-2 rounded text-center" style="background:#f0f4ff">
                        <div class="fw-bold text-primary"><?= $s['total_trips'] ?></div>
                        <div class="small text-muted">Chuyến xe</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-2 rounded text-center" style="background:#f0f4ff">
                        <div class="fw-bold text-primary"><?= number_format($s['total_km'], 0) ?> km</div>
                        <div class="small text-muted">Tổng km</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-2 rounded text-center" style="background:#f0f4ff">
                        <div class="fw-bold text-primary"><?= number_format($s['total_fuel_liters'], 1) ?> L</div>
                        <div class="small text-muted">Nhiên liệu</div>
                    </div>
                </div>
                <div class="col-md-3 col-6">
                    <div class="p-2 rounded text-center" style="background:#f0f4ff">
                        <div class="fw-bold text-primary">
                            <?= $s['actual_fuel_rate'] > 0 ? number_format($s['actual_fuel_rate'], 2) . ' L/100km' : '—' ?>
                        </div>
                        <div class="small text-muted">Tiêu hao TT</div>
                    </div>
                </div>
            </div>

            <div class="text-muted small mt-2">
                <i class="fas fa-clock me-1"></i>
                Tính lúc: <?= date('d/m/Y H:i', strtotime($s['calculated_at'])) ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php elseif ($tab === 'config' && $canManage): ?>
    <!-- ══════════════════════════════════════════════════════
         TAB: CẤU HÌNH
    ══════════════════════════════════════════════════════ -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2">
            <h6 class="fw-bold mb-0">⚙️ Cấu hình trọng số & định mức KPI</h6>
        </div>
        <div class="card-body">
            <div class="alert alert-info small mb-3">
                <i class="fas fa-info-circle me-1"></i>
                <strong>Định mức nhiên liệu</strong> được lấy từ từng xe (cột <code>fuel_quota</code>
                trong bảng <code>vehicles</code>). Giá trị tại đây chỉ dùng làm <strong>fallback</strong>
                khi xe chưa có định mức.
            </div>
            <form method="POST">
                <input type="hidden" name="save_config" value="1">
                <div class="row g-4">
                <?php
                $configUI = [
                    'fuel'     => ['label'=>'⛽ Nhiên liệu',   'color'=>'warning', 'unit'=>'L/100km','targetLabel'=>'Định mức fallback (L/100km)'],
                    'safety'   => ['label'=>'🛡️ An toàn',      'color'=>'danger',  'unit'=>'lần',   'targetLabel'=>'Mục tiêu lỗi (0 = tốt nhất)'],
                    'vehicle'  => ['label'=>'🔧 Bảo quản xe',  'color'=>'info',    'unit'=>'lần',   'targetLabel'=>'Mục tiêu lỗi bảo dưỡng'],
                    'customer' => ['label'=>'⭐ KH chấm điểm', 'color'=>'success', 'unit'=>'điểm/5','targetLabel'=>'Điểm mục tiêu (tối đa 5)'],
                ];
                $totalWeight = array_sum(array_column($kpiConfig, 'weight'));
                ?>
                <?php foreach ($configUI as $key => $ui): ?>
                <div class="col-md-6">
                    <div class="card border-<?= $ui['color'] ?> border-2">
                        <div class="card-header bg-<?= $ui['color'] ?> bg-opacity-10 py-2">
                            <h6 class="fw-bold mb-0"><?= $ui['label'] ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">Trọng số (%)</label>
                                    <div class="input-group">
                                        <input type="number" name="weight_<?= $key ?>"
                                               class="form-control weight-input"
                                               step="1" min="0" max="100"
                                               value="<?= $kpiConfig[$key]['weight'] ?? 0 ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-semibold">
                                        <?= $ui['targetLabel'] ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="number" name="target_<?= $key ?>"
                                               class="form-control"
                                               step="0.1" min="0"
                                               value="<?= $kpiConfig[$key]['target'] ?? 0 ?>">
                                        <span class="input-group-text"><?= $ui['unit'] ?></span>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="small text-muted">
                                        <?= htmlspecialchars($kpiConfig[$key]['description'] ?? '') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>

                <!-- Kiểm tra tổng trọng số -->
                <div class="alert alert-info mt-3 d-flex align-items-center gap-2">
                    <i class="fas fa-info-circle"></i>
                    Tổng trọng số hiện tại:
                    <strong id="totalWeightDisplay"
                            style="color:<?= $totalWeight == 100 ? '#198754' : '#dc3545' ?>">
                        <?= $totalWeight ?>%
                    </strong>
                    <span id="weightWarning" class="text-danger ms-2"
                          <?= $totalWeight == 100 ? 'style="display:none"' : '' ?>>
                        ⚠️ Tổng phải = 100%
                    </span>
                </div>

                <!-- Bảng xếp loại -->
                <div class="mt-3 p-3 border rounded-3" style="background:#f8f9fa">
                    <h6 class="fw-bold mb-2">📊 Bảng xếp loại</h6>
                    <div class="d-flex gap-3 flex-wrap">
                        <span class="badge bg-success px-3 py-2">A+ ≥ 95 điểm</span>
                        <span class="badge bg-info px-3 py-2">A ≥ 85 điểm</span>
                        <span class="badge bg-primary px-3 py-2">B ≥ 75 điểm</span>
                        <span class="badge bg-warning text-dark px-3 py-2">C ≥ 60 điểm</span>
                        <span class="badge bg-danger px-3 py-2">D &lt; 60 điểm</span>
                    </div>
                </div>

                <!-- Ghi chú điểm nhiên liệu -->
                <div class="mt-3 p-3 border rounded-3" style="background:#fff8e1">
                    <h6 class="fw-bold mb-2">⛽ Thang điểm Nhiên liệu</h6>
                    <table class="table table-sm table-bordered mb-0" style="font-size:0.82rem">
                        <thead class="table-light">
                            <tr>
                                <th>Mức tiêu hao so với định mức xe</th>
                                <th class="text-center">Điểm</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>≤ Định mức xe</td><td class="text-center text-success fw-bold">100</td></tr>
                            <tr><td>Vượt ≤ 5%</td><td class="text-center text-success">90 – 100</td></tr>
                            <tr><td>Vượt 5% – 10%</td><td class="text-center text-warning">70 – 90</td></tr>
                            <tr><td>Vượt 10% – 20%</td><td class="text-center text-warning">40 – 70</td></tr>
                            <tr><td>Vượt > 20%</td><td class="text-center text-danger fw-bold">0 – 40</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 text-end">
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-save me-2"></i>Lưu cấu hình
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /.container-fluid -->
</div><!-- /.main-content -->

<!-- ── Modal tính KPI ── -->
<?php if ($canCalc): ?>
<div class="modal fade" id="calcModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#0f3460;color:#fff">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-calculator me-2"></i>Tính KPI theo kỳ
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="calculate" value="1">
                <div class="modal-body">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        Hệ thống tự động lấy dữ liệu <strong>nhiên liệu</strong>,
                        <strong>chuyến xe</strong> và <strong>đánh giá KH</strong>
                        trong kỳ để tính điểm KPI. Định mức nhiên liệu lấy từng xe.
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Từ ngày</label>
                            <input type="date" name="calc_from" class="form-control"
                                   value="<?= $dateFrom ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Đến ngày</label>
                            <input type="date" name="calc_to" class="form-control"
                                   value="<?= $dateTo ?>">
                        </div>
                    </div>
                    <div class="mt-3 p-2 rounded" style="background:#f8f9fa;font-size:0.82rem">
                        <strong>Công thức:</strong><br>
                        Điểm tổng
                        = NL × <?= $kpiConfig['fuel']['weight'] ?? 40 ?>%
                        + AT × <?= $kpiConfig['safety']['weight'] ?? 40 ?>%
                        + BQ × <?= $kpiConfig['vehicle']['weight'] ?? 10 ?>%
                        + KH × <?= $kpiConfig['customer']['weight'] ?? 10 ?>%
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-play me-2"></i>Bắt đầu tính
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.weight-input').forEach(inp => {
    inp.addEventListener('input', () => {
        let total = 0;
        document.querySelectorAll('.weight-input').forEach(i => {
            total += parseFloat(i.value) || 0;
        });
        const disp = document.getElementById('totalWeightDisplay');
        const warn = document.getElementById('weightWarning');
        if (disp) {
            disp.textContent = total + '%';
            disp.style.color = total === 100 ? '#198754' : '#dc3545';
        }
        if (warn) warn.style.display = total === 100 ? 'none' : 'inline';
    });
});
</script>

<?php include '../../includes/footer.php'; ?>