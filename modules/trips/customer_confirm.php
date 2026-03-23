<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();

$pdo  = getDBConnection();
$user = currentUser();
$role = $user['role_name'] ?? $user['role'] ?? '';

$pageTitle = 'Xác nhận & Đánh giá lịch trình';

// ── Xác định customer từ user ────────────────────────────────
$customerId = null;
$cu         = null;

if ($role === 'customer') {
    $cuStmt = $pdo->prepare("
        SELECT cu.*, c.company_name, c.short_name, c.tax_code
        FROM customer_users cu
        JOIN customers c ON cu.customer_id = c.id
        WHERE cu.user_id = ? AND cu.is_active = TRUE
        LIMIT 1
    ");
    $cuStmt->execute([$user['id']]);
    $cu = $cuStmt->fetch();
    if ($cu) $customerId = $cu['customer_id'];
} elseif (in_array($role, ['admin','superadmin','dispatcher'])) {
    $customerId = (int)($_GET['customer_id'] ?? 0) ?: null;
}

// ── Xử lý POST ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $tripId = (int)($_POST['trip_id'] ?? 0);

    // Lấy trip + driver_id
    $tripCheck = $pdo->prepare("
        SELECT t.*, d.id AS driver_id_fk
        FROM trips t
        JOIN drivers d ON t.driver_id = d.id
        WHERE t.id = ? AND t.status = 'completed'
          " . ($customerId ? "AND t.customer_id = ?" : "") . "
    ");
    $tripCheck->execute($customerId ? [$tripId, $customerId] : [$tripId]);
    $tripRow = $tripCheck->fetch();

    if (!$tripRow) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy chuyến xe!'];
        header("Location: customer_confirm.php"); exit;
    }

    // ── XÁC NHẬN + ĐÁNH GIÁ ────────────────────────────────
    if ($action === 'confirm') {
        $pdo->prepare("
            UPDATE trips SET
                status       = 'confirmed',
                confirmed_by = ?,
                confirmed_at = NOW(),
                updated_at   = NOW()
            WHERE id = ?
        ")->execute([$user['id'], $tripId]);

        // Lưu đánh giá nếu có chọn sao
        $rating = (float)($_POST['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $rPunctual  = (int)($_POST['rating_punctual'] ?? 0) ?: null;
            $rAttitude  = (int)($_POST['rating_attitude'] ?? 0) ?: null;
            $rCargo     = (int)($_POST['rating_cargo']    ?? 0) ?: null;
            $rVehicle   = (int)($_POST['rating_vehicle']  ?? 0) ?: null;
            $comment    = trim($_POST['comment'] ?? '');
            $isComplaint = ($rating <= 2 && !empty($comment)) ? 1 : 0;

            $pdo->prepare("
                INSERT INTO driver_ratings
                    (trip_id, driver_id, customer_id, rated_by,
                     rating, rating_punctual, rating_attitude,
                     rating_cargo, rating_vehicle,
                     comment, is_complaint, rated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                ON CONFLICT (trip_id) DO UPDATE SET
                    rating          = EXCLUDED.rating,
                    rating_punctual = EXCLUDED.rating_punctual,
                    rating_attitude = EXCLUDED.rating_attitude,
                    rating_cargo    = EXCLUDED.rating_cargo,
                    rating_vehicle  = EXCLUDED.rating_vehicle,
                    comment         = EXCLUDED.comment,
                    is_complaint    = EXCLUDED.is_complaint,
                    rated_by        = EXCLUDED.rated_by,
                    rated_at        = NOW()
            ")->execute([
                $tripId,
                $tripRow['driver_id_fk'],
                $tripRow['customer_id'],
                $user['id'],
                $rating,
                $rPunctual, $rAttitude, $rCargo, $rVehicle,
                $comment ?: null,
                $isComplaint,
            ]);
        }

        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã xác nhận chuyến xe!'];

    // ── TỪ CHỐI ─────────────────────────────────────────────
    } elseif ($action === 'reject') {
        $reason = trim($_POST['rejection_reason'] ?? '');
        if (!$reason) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'⚠️ Vui lòng nhập lý do từ chối!'];
            header("Location: customer_confirm.php"); exit;
        }
        $pdo->prepare("
            UPDATE trips SET
                status           = 'rejected',
                rejection_reason = ?,
                confirmed_by     = ?,
                confirmed_at     = NOW(),
                updated_at       = NOW()
            WHERE id = ?
        ")->execute([$reason, $user['id'], $tripId]);
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'❌ Đã từ chối chuyến xe!'];

    // ── ĐÁNH GIÁ BỔ SUNG (trip đã confirmed nhưng chưa rate) ─
    } elseif ($action === 'rate') {
        $rating = (float)($_POST['rating'] ?? 0);
        if ($rating >= 1 && $rating <= 5) {
            $rPunctual  = (int)($_POST['rating_punctual'] ?? 0) ?: null;
            $rAttitude  = (int)($_POST['rating_attitude'] ?? 0) ?: null;
            $rCargo     = (int)($_POST['rating_cargo']    ?? 0) ?: null;
            $rVehicle   = (int)($_POST['rating_vehicle']  ?? 0) ?: null;
            $comment    = trim($_POST['comment'] ?? '');

            // Cho phép rate trip đã confirmed
            $confirmedTrip = $pdo->prepare("
                SELECT t.*, d.id AS driver_id_fk
                FROM trips t JOIN drivers d ON t.driver_id = d.id
                WHERE t.id = ? AND t.status = 'confirmed'
                  " . ($customerId ? "AND t.customer_id = ?" : "") . "
            ");
            $confirmedTrip->execute($customerId ? [$tripId, $customerId] : [$tripId]);
            $ct = $confirmedTrip->fetch();

            if ($ct) {
                $pdo->prepare("
                    INSERT INTO driver_ratings
                        (trip_id, driver_id, customer_id, rated_by,
                         rating, rating_punctual, rating_attitude,
                         rating_cargo, rating_vehicle,
                         comment, is_complaint, rated_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ON CONFLICT (trip_id) DO UPDATE SET
                        rating          = EXCLUDED.rating,
                        rating_punctual = EXCLUDED.rating_punctual,
                        rating_attitude = EXCLUDED.rating_attitude,
                        rating_cargo    = EXCLUDED.rating_cargo,
                        rating_vehicle  = EXCLUDED.rating_vehicle,
                        comment         = EXCLUDED.comment,
                        is_complaint    = EXCLUDED.is_complaint,
                        rated_by        = EXCLUDED.rated_by,
                        rated_at        = NOW()
                ")->execute([
                    $tripId,
                    $ct['driver_id_fk'],
                    $ct['customer_id'],
                    $user['id'],
                    $rating,
                    $rPunctual, $rAttitude, $rCargo, $rVehicle,
                    $comment ?: null,
                    ($rating <= 2 && !empty($comment)) ? 1 : 0,
                ]);
                $_SESSION['flash'] = ['type'=>'success','msg'=>'⭐ Đã lưu đánh giá!'];
            }
        }
    }

    $redirect = "customer_confirm.php"
        . ($customerId && $role !== 'customer' ? "?customer_id=$customerId" : "");
    header("Location: $redirect"); exit;
}

// ── Load dữ liệu hiển thị ────────────────────────────────────
$filterMonth = $_GET['month'] ?? date('Y-m');
[$fYear, $fMonth] = explode('-', $filterMonth);

$whereCustomer = $customerId ? "AND t.customer_id = $customerId" : "";

// Chờ xác nhận
$pendingTrips = $pdo->prepare("
    SELECT t.*,
           u.full_name    AS driver_name,
           v.plate_number, v.capacity,
           c.company_name AS customer_name,
           c.short_name   AS customer_short
    FROM trips t
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN customers c ON t.customer_id = c.id
    WHERE t.status = 'completed' $whereCustomer
      AND EXTRACT(MONTH FROM t.trip_date) = ?
      AND EXTRACT(YEAR  FROM t.trip_date) = ?
    ORDER BY t.trip_date ASC, v.plate_number
");
$pendingTrips->execute([(int)$fMonth, (int)$fYear]);
$pendingTrips = $pendingTrips->fetchAll();

// Đã xử lý (confirmed/rejected) + rating
$confirmedTrips = $pdo->prepare("
    SELECT t.*,
           u.full_name  AS driver_name,
           v.plate_number,
           c.short_name AS customer_short,
           cu_user.full_name AS confirmed_by_name,
           dr.id        AS rating_id,
           dr.rating, dr.comment, dr.is_complaint,
           dr.rating_punctual, dr.rating_attitude,
           dr.rating_cargo, dr.rating_vehicle,
           dr.rated_at
    FROM trips t
    JOIN drivers d   ON t.driver_id   = d.id
    JOIN users u     ON d.user_id     = u.id
    JOIN vehicles v  ON t.vehicle_id  = v.id
    JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users cu_user ON t.confirmed_by = cu_user.id
    LEFT JOIN driver_ratings dr ON dr.trip_id = t.id
    WHERE t.status IN ('confirmed','rejected') $whereCustomer
      AND EXTRACT(MONTH FROM t.trip_date) = ?
      AND EXTRACT(YEAR  FROM t.trip_date) = ?
    ORDER BY t.confirmed_at DESC
");
$confirmedTrips->execute([(int)$fMonth, (int)$fYear]);
$confirmedTrips = $confirmedTrips->fetchAll();

// Admin: danh sách KH để lọc
$customerList = [];
if (in_array($role, ['admin','superadmin','dispatcher'])) {
    $customerList = $pdo->query("
        SELECT id, company_name, short_name
        FROM customers WHERE is_active = TRUE ORDER BY company_name
    ")->fetchAll();
}

$ratingTexts = [1=>'😞 Tệ', 2=>'😕 Không tốt', 3=>'😐 Bình thường', 4=>'😊 Tốt', 5=>'🤩 Xuất sắc'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-check-circle me-2 text-success"></i>
                Xác nhận & Đánh giá lịch trình
            </h4>
            <?php if ($cu): ?>
            <small class="text-muted">
                <i class="fas fa-building me-1"></i>
                <?= htmlspecialchars($cu['company_name']) ?>
            </small>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <form method="GET" class="d-flex gap-2 align-items-center">
                <?php if ($customerId && $role !== 'customer'): ?>
                <input type="hidden" name="customer_id" value="<?= $customerId ?>">
                <?php endif; ?>
                <input type="month" name="month" class="form-control form-control-sm"
                       value="<?= $filterMonth ?>" onchange="this.form.submit()">
            </form>
            <?php if (!empty($customerList)): ?>
            <form method="GET" class="d-flex gap-2 align-items-center">
                <input type="hidden" name="month" value="<?= $filterMonth ?>">
                <select name="customer_id" class="form-select form-select-sm"
                        onchange="this.form.submit()" style="min-width:180px">
                    <option value="">-- Tất cả khách hàng --</option>
                    <?php foreach ($customerList as $cl): ?>
                    <option value="<?= $cl['id'] ?>"
                        <?= $customerId == $cl['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cl['short_name'] ?: $cl['company_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php showFlash(); ?>

    <!-- ══ CHUYẾN CHỜ XÁC NHẬN ══════════════════════════════ -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header py-2 d-flex justify-content-between align-items-center"
             style="background:#0f3460;color:#fff">
            <h6 class="mb-0 fw-bold">
                <i class="fas fa-clock me-2"></i>Chờ xác nhận
                <span class="badge bg-warning text-dark ms-2"><?= count($pendingTrips) ?></span>
            </h6>
            <small class="opacity-75">Tháng <?= $fMonth ?>/<?= $fYear ?></small>
        </div>

        <?php if (empty($pendingTrips)): ?>
        <div class="card-body text-center py-5">
            <i class="fas fa-check-double fa-3x text-success opacity-25 mb-3"></i>
            <p class="text-muted mb-0">Không có chuyến nào chờ xác nhận</p>
        </div>
        <?php else: ?>
        <div class="card-body p-0">
        <?php foreach ($pendingTrips as $trip): ?>
        <div class="border-bottom p-3 p-md-4">
            <div class="row g-3">

                <!-- Thông tin chuyến -->
                <div class="col-lg-5">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <code class="text-primary fw-bold fs-6">
                            <?= htmlspecialchars($trip['trip_code'] ?? '#'.$trip['id']) ?>
                        </code>
                        <div class="text-end">
                            <div class="fw-semibold small">
                                <?= date('d/m/Y', strtotime($trip['trip_date'])) ?>
                                <?php if ($trip['is_sunday'] ?? false): ?>
                                <span class="badge bg-warning text-dark ms-1">CN</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mb-2 small">
                        <i class="fas fa-user me-1 text-primary"></i>
                        <strong><?= htmlspecialchars($trip['driver_name']) ?></strong>
                        &nbsp;·&nbsp;
                        <i class="fas fa-truck me-1 text-muted"></i>
                        <?= htmlspecialchars($trip['plate_number']) ?>
                        <?= $trip['capacity'] ? '<small class="text-muted">('.$trip['capacity'].'T)</small>' : '' ?>
                    </div>

                    <div class="mb-2 small">
                        <div class="d-flex align-items-start gap-2">
                            <div class="d-flex flex-column align-items-center mt-1" style="gap:2px">
                                <div style="width:8px;height:8px;border-radius:50%;background:#dc3545;flex-shrink:0"></div>
                                <div style="width:1px;height:14px;background:#dee2e6"></div>
                                <div style="width:8px;height:8px;border-radius:50%;background:#198754;flex-shrink:0"></div>
                            </div>
                            <div>
                                <div class="text-uppercase fw-semibold">
                                    <?= htmlspecialchars($trip['pickup_location'] ?? '—') ?>
                                </div>
                                <div class="text-uppercase fw-semibold text-muted">
                                    <?= htmlspecialchars($trip['dropoff_location'] ?? '—') ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-3 small">
                        <?php if ($trip['total_km']): ?>
                        <span class="text-primary fw-semibold">
                            <i class="fas fa-road me-1"></i>
                            <?= number_format($trip['total_km'], 0) ?> km
                        </span>
                        <?php endif; ?>
                        <?php if ($trip['toll_fee']): ?>
                        <span class="text-warning">
                            <i class="fas fa-money-bill me-1"></i>
                            <?= number_format($trip['toll_fee'], 0, '.', ',') ?> đ
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($trip['note']): ?>
                    <div class="mt-2 small text-muted fst-italic p-2 rounded"
                         style="background:#f8f9fa">
                        <i class="fas fa-sticky-note me-1"></i>
                        <?= htmlspecialchars($trip['note']) ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Form đánh giá + xác nhận -->
                <div class="col-lg-7">
                    <div class="border rounded-3 p-3" style="background:#f8fff8;border-color:#c3e6cb!important">
                        <div class="fw-semibold text-success mb-3 small">
                            <i class="fas fa-star me-1"></i>Đánh giá chất lượng dịch vụ
                            <span class="text-muted fw-normal">(tùy chọn)</span>
                        </div>

                        <!-- Sao tổng -->
                        <div class="mb-3">
                            <div class="small fw-semibold mb-1 text-muted">Đánh giá tổng thể</div>
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-flex gap-1" id="stars-<?= $trip['id'] ?>">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star rating-star fs-4"
                                       style="color:#dee2e6;cursor:pointer;transition:color 0.1s"
                                       data-trip="<?= $trip['id'] ?>"
                                       data-val="<?= $i ?>"
                                       id="star-<?= $trip['id'] ?>-<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="small fw-semibold text-muted"
                                      id="ratingText-<?= $trip['id'] ?>">Chưa chọn</span>
                            </div>
                        </div>

                        <!-- 4 tiêu chí nhỏ -->
                        <div class="row g-2 mb-3">
                            <?php
                            $subCriteria = [
                                'punctual' => ['icon'=>'⏰','label'=>'Đúng giờ'],
                                'attitude' => ['icon'=>'😊','label'=>'Thái độ'],
                                'cargo'    => ['icon'=>'📦','label'=>'Hàng hóa'],
                                'vehicle'  => ['icon'=>'🚛','label'=>'Xe sạch sẽ'],
                            ];
                            foreach ($subCriteria as $cKey => $cInfo):
                            ?>
                            <div class="col-6">
                                <div class="p-2 rounded" style="background:#fff;border:1px solid #e9ecef">
                                    <div class="small text-muted mb-1">
                                        <?= $cInfo['icon'] ?> <?= $cInfo['label'] ?>
                                    </div>
                                    <div class="d-flex gap-1" id="substars-<?= $cKey ?>-<?= $trip['id'] ?>">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star sub-star"
                                           style="color:#dee2e6;cursor:pointer;font-size:0.9rem;transition:color 0.1s"
                                           data-trip="<?= $trip['id'] ?>"
                                           data-key="<?= $cKey ?>"
                                           data-val="<?= $i ?>"
                                           id="sub-<?= $cKey ?>-<?= $trip['id'] ?>-<?= $i ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Comment -->
                        <div class="mb-3">
                            <label class="form-label small fw-semibold mb-1">
                                💬 Phản ánh / Góp ý
                            </label>
                            <textarea class="form-control form-control-sm"
                                      id="comment-<?= $trip['id'] ?>"
                                      rows="2"
                                      placeholder="Nhận xét về thái độ lái xe, hàng hóa, đúng giờ..."></textarea>
                        </div>

                        <!-- Nút Xác nhận / Từ chối -->
                        <div class="d-flex gap-2">
                            <form method="POST" class="flex-fill"
                                  onsubmit="return prepareSubmit(event, <?= $trip['id'] ?>, 'confirm')">
                                <input type="hidden" name="action"          value="confirm">
                                <input type="hidden" name="trip_id"         value="<?= $trip['id'] ?>">
                                <input type="hidden" name="rating"          id="hRating-<?= $trip['id'] ?>">
                                <input type="hidden" name="rating_punctual" id="hPunctual-<?= $trip['id'] ?>">
                                <input type="hidden" name="rating_attitude" id="hAttitude-<?= $trip['id'] ?>">
                                <input type="hidden" name="rating_cargo"    id="hCargo-<?= $trip['id'] ?>">
                                <input type="hidden" name="rating_vehicle"  id="hVehicle-<?= $trip['id'] ?>">
                                <input type="hidden" name="comment"         id="hComment-<?= $trip['id'] ?>">
                                <button type="submit" class="btn btn-success w-100 fw-semibold">
                                    <i class="fas fa-check me-1"></i>Xác nhận chuyến
                                </button>
                            </form>
                            <button type="button"
                                    class="btn btn-outline-danger fw-semibold"
                                    style="min-width:110px"
                                    onclick="openRejectModal(<?= $trip['id'] ?>)">
                                <i class="fas fa-times me-1"></i>Từ chối
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══ ĐÃ XỬ LÝ ══════════════════════════════════════════ -->
    <?php if (!empty($confirmedTrips)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-2 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0">
                <i class="fas fa-history me-2 text-muted"></i>
                Đã xử lý — Tháng <?= $fMonth ?>/<?= $fYear ?>
            </h6>
            <span class="badge bg-secondary"><?= count($confirmedTrips) ?></span>
        </div>
        <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:0.83rem">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Chuyến / Ngày</th>
                    <th>Lái xe · Xe</th>
                    <th>Tuyến đường</th>
                    <th class="text-center">Trạng thái</th>
                    <th class="text-center">Đánh giá</th>
                    <th>Phản ánh</th>
                    <th class="text-center">Hành động</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($confirmedTrips as $t): ?>
            <tr>
                <td class="ps-3">
                    <code class="text-primary small fw-bold">
                        <?= htmlspecialchars($t['trip_code'] ?? '#'.$t['id']) ?>
                    </code>
                    <div class="text-muted small">
                        <?= date('d/m/Y', strtotime($t['trip_date'])) ?>
                    </div>
                </td>
                <td>
                    <div class="small fw-semibold"><?= htmlspecialchars($t['driver_name']) ?></div>
                    <div class="text-muted small"><?= htmlspecialchars($t['plate_number']) ?></div>
                </td>
                <td>
                    <div class="small text-uppercase">
                        <?= htmlspecialchars(mb_substr($t['pickup_location'] ?? '', 0, 18)) ?>
                    </div>
                    <div class="small text-muted text-uppercase">
                        → <?= htmlspecialchars(mb_substr($t['dropoff_location'] ?? '', 0, 18)) ?>
                    </div>
                    <?php if ($t['total_km']): ?>
                    <div class="text-primary small fw-semibold">
                        <?= number_format($t['total_km'], 0) ?> km
                    </div>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($t['status'] === 'confirmed'): ?>
                    <span class="badge bg-success">✅ Đã duyệt</span>
                    <?php else: ?>
                    <span class="badge bg-danger">❌ Từ chối</span>
                    <?php if ($t['rejection_reason']): ?>
                    <div class="small text-danger mt-1" style="max-width:150px">
                        <?= htmlspecialchars(mb_substr($t['rejection_reason'], 0, 60)) ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($t['confirmed_by_name']): ?>
                    <div class="text-muted" style="font-size:0.68rem">
                        <?= htmlspecialchars($t['confirmed_by_name']) ?>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- Rating hiển thị -->
                <td class="text-center">
                    <?php if ($t['rating']): ?>
                    <div class="mb-1">
                        <?php
                        $r = round($t['rating']);
                        for ($i = 1; $i <= 5; $i++):
                        ?>
                        <i class="fas fa-star"
                           style="font-size:0.7rem;color:<?= $i <= $r ? '#ffc107' : '#dee2e6' ?>"></i>
                        <?php endfor; ?>
                    </div>
                    <div class="fw-bold small
                        <?= $t['rating'] >= 4 ? 'text-success' : ($t['rating'] >= 3 ? 'text-warning' : 'text-danger') ?>">
                        <?= number_format($t['rating'], 1) ?>/5
                        — <?= $ratingTexts[(int)round($t['rating'])] ?? '' ?>
                    </div>
                    <?php if ($t['rating_punctual']): ?>
                    <div class="text-muted mt-1" style="font-size:0.68rem">
                        ⏰<?= $t['rating_punctual'] ?>
                        😊<?= $t['rating_attitude'] ?>
                        📦<?= $t['rating_cargo'] ?>
                        🚛<?= $t['rating_vehicle'] ?>
                    </div>
                    <?php endif; ?>
                    <?php elseif ($t['status'] === 'confirmed'): ?>
                    <!-- Chưa đánh giá → cho phép đánh giá bổ sung -->
                    <button class="btn btn-sm btn-outline-warning"
                            onclick="openRateModal(<?= $t['id'] ?>)">
                        <i class="fas fa-star me-1"></i>Đánh giá
                    </button>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>

                <!-- Comment / Phản ánh -->
                <td style="max-width:180px">
                    <?php if ($t['comment']): ?>
                    <span class="small <?= $t['is_complaint'] ? 'text-danger fw-semibold' : 'text-muted' ?>">
                        <?php if ($t['is_complaint']): ?>
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars(mb_substr($t['comment'], 0, 100)) ?>
                        <?= mb_strlen($t['comment']) > 100 ? '...' : '' ?>
                    </span>
                    <?php if ($t['rated_at']): ?>
                    <div class="text-muted" style="font-size:0.68rem">
                        <?= date('d/m/Y H:i', strtotime($t['rated_at'])) ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>

                <!-- Hành động: đánh giá bổ sung -->
                <td class="text-center">
                    <?php if ($t['status'] === 'confirmed' && !$t['rating_id']): ?>
                    <button class="btn btn-xs btn-outline-warning btn-sm"
                            onclick="openRateModal(<?= $t['id'] ?>)">
                        <i class="fas fa-star"></i>
                    </button>
                    <?php elseif ($t['rating_id']): ?>
                    <span class="badge bg-success bg-opacity-10 text-success">Đã đánh giá</span>
                    <?php else: ?>
                    —
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

<!-- ══ Modal Từ chối ═══════════════════════════════════════ -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-times-circle me-2"></i>Từ chối chuyến xe
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="rejectForm">
                <input type="hidden" name="action"  value="reject">
                <input type="hidden" name="trip_id" id="rejectTripId">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Lý do từ chối <span class="text-danger">*</span>
                        </label>
                        <textarea name="rejection_reason" class="form-control" rows="3"
                                  required placeholder="Nêu rõ lý do: số km sai, tuyến đường không đúng..."></textarea>
                    </div>
                    <div class="alert alert-warning small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Lái xe sẽ nhận thông báo và được phép chỉnh sửa lại chuyến.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-danger fw-bold">
                        <i class="fas fa-times me-2"></i>Xác nhận từ chối
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ Modal Đánh giá bổ sung ══════════════════════════════ -->
<div class="modal fade" id="rateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#0f3460;color:#fff">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-star me-2"></i>Đánh giá lái xe
                </h5>
                <button type="button" class="btn-close btn-close-white"
                        data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="rateForm">
                <input type="hidden" name="action"  value="rate">
                <input type="hidden" name="trip_id" id="rateTripId">
                <div class="modal-body">
                    <!-- Sao tổng modal -->
                    <div class="text-center mb-4">
                        <div class="small fw-semibold text-muted mb-2">Đánh giá tổng thể</div>
                        <div class="d-flex justify-content-center gap-2"
                             id="modalStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star rating-star-modal"
                               style="font-size:2rem;color:#dee2e6;cursor:pointer"
                               data-val="<?= $i ?>"
                               id="mstar-<?= $i ?>"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="modalRatingVal">
                        <div class="small fw-semibold mt-1" id="modalRatingText">Chưa chọn</div>
                    </div>

                    <!-- 4 tiêu chí -->
                    <div class="row g-2 mb-3">
                        <?php foreach ($subCriteria as $cKey => $cInfo): ?>
                        <div class="col-6">
                            <div class="p-2 rounded border">
                                <div class="small text-muted mb-1">
                                    <?= $cInfo['icon'] ?> <?= $cInfo['label'] ?>
                                </div>
                                <div class="d-flex gap-1">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star modal-sub-star"
                                       style="color:#dee2e6;cursor:pointer"
                                       data-key="<?= $cKey ?>"
                                       data-val="<?= $i ?>"
                                       id="msub-<?= $cKey ?>-<?= $i ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="rating_<?= $cKey ?>"
                                       id="mSub-<?= $cKey ?>">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-semibold">💬 Phản ánh / Góp ý</label>
                        <textarea name="comment" class="form-control" rows="3"
                                  placeholder="Nhận xét..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                            data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" class="btn btn-warning fw-bold">
                        <i class="fas fa-save me-2"></i>Lưu đánh giá
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.rating-star:hover,
.rating-star.hovered { color: #ffd700 !important; transform: scale(1.15); }
</style>

<script>
const ratingTexts = {
    1: '😞 Tệ', 2: '😕 Không tốt',
    3: '😐 Bình thường', 4: '😊 Tốt', 5: '🤩 Xuất sắc'
};

// ── Sao tổng trên card ────────────────────────────────────
document.querySelectorAll('.rating-star').forEach(star => {
    // Click
    star.addEventListener('click', function () {
        const trip = this.dataset.trip;
        const val  = parseInt(this.dataset.val);
        setStars(trip, val);
        document.getElementById(`hRating-${trip}`).value = val;
        const txt = document.getElementById(`ratingText-${trip}`);
        if (txt) txt.textContent = ratingTexts[val] || '';
    });
    // Hover in
    star.addEventListener('mouseenter', function () {
        const trip = this.dataset.trip;
        const val  = parseInt(this.dataset.val);
        hoverStars(trip, val);
    });
    // Hover out
    star.addEventListener('mouseleave', function () {
        const trip    = this.dataset.trip;
        const current = parseInt(document.getElementById(`hRating-${trip}`)?.value || 0);
        setStars(trip, current);
    });
});

function setStars(trip, val) {
    for (let i = 1; i <= 5; i++) {
        const s = document.getElementById(`star-${trip}-${i}`);
        if (s) s.style.color = i <= val ? '#ffc107' : '#dee2e6';
    }
}
function hoverStars(trip, val) {
    for (let i = 1; i <= 5; i++) {
        const s = document.getElementById(`star-${trip}-${i}`);
        if (s) s.style.color = i <= val ? '#ffd700' : '#dee2e6';
    }
}

// ── Sub-star trên card ────────────────────────────────────
document.querySelectorAll('.sub-star').forEach(star => {
    star.addEventListener('click', function () {
        const trip = this.dataset.trip;
        const key  = this.dataset.key;
        const val  = parseInt(this.dataset.val);
        setSubStars(key, trip, val);
        const map = { punctual:'hPunctual', attitude:'hAttitude', cargo:'hCargo', vehicle:'hVehicle' };
        const h   = document.getElementById(`${map[key]}-${trip}`);
        if (h) h.value = val;
    });
    star.addEventListener('mouseenter', function () {
        const trip = this.dataset.trip;
        const key  = this.dataset.key;
        const val  = parseInt(this.dataset.val);
        for (let i = 1; i <= 5; i++) {
            const s = document.getElementById(`sub-${key}-${trip}-${i}`);
            if (s) s.style.color = i <= val ? '#ffd700' : '#dee2e6';
        }
    });
    star.addEventListener('mouseleave', function () {
        const trip = this.dataset.trip;
        const key  = this.dataset.key;
        const map  = { punctual:'hPunctual', attitude:'hAttitude', cargo:'hCargo', vehicle:'hVehicle' };
        const cur  = parseInt(document.getElementById(`${map[key]}-${trip}`)?.value || 0);
        setSubStars(key, trip, cur);
    });
});

function setSubStars(key, trip, val) {
    for (let i = 1; i <= 5; i++) {
        const s = document.getElementById(`sub-${key}-${trip}-${i}`);
        if (s) s.style.color = i <= val ? '#ffc107' : '#dee2e6';
    }
}

// ── Submit: copy comment vào hidden ──────────────────────
function prepareSubmit(e, tripId, action) {
    const comment = document.getElementById(`comment-${tripId}`)?.value || '';
    document.getElementById(`hComment-${tripId}`).value = comment;
    return true;
}

// ── Modal Từ chối ─────────────────────────────────────────
function openRejectModal(tripId) {
    document.getElementById('rejectTripId').value = tripId;
    new bootstrap.Modal(document.getElementById('rejectModal')).show();
}

// ── Modal Đánh giá bổ sung ────────────────────────────────
let modalCurrentRating = 0;

function openRateModal(tripId) {
    document.getElementById('rateTripId').value = tripId;
    // Reset
    modalCurrentRating = 0;
    for (let i = 1; i <= 5; i++) {
        const s = document.getElementById(`mstar-${i}`);
        if (s) s.style.color = '#dee2e6';
    }
    document.getElementById('modalRatingVal').value = '';
    document.getElementById('modalRatingText').textContent = 'Chưa chọn';
    ['punctual','attitude','cargo','vehicle'].forEach(k => {
        document.getElementById(`mSub-${k}`).value = '';
        for (let i = 1; i <= 5; i++) {
            const s = document.getElementById(`msub-${k}-${i}`);
            if (s) s.style.color = '#dee2e6';
        }
    });
    new bootstrap.Modal(document.getElementById('rateModal')).show();
}

// Modal sao tổng
document.querySelectorAll('.rating-star-modal').forEach(star => {
    star.addEventListener('click', function () {
        const val = parseInt(this.dataset.val);
        modalCurrentRating = val;
        document.getElementById('modalRatingVal').value = val;
        document.getElementById('modalRatingText').textContent = ratingTexts[val] || '';
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`mstar-${i}`).style.color = i <= val ? '#ffc107' : '#dee2e6';
        }
    });
    star.addEventListener('mouseenter', function () {
        const val = parseInt(this.dataset.val);
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`mstar-${i}`).style.color = i <= val ? '#ffd700' : '#dee2e6';
        }
    });
    star.addEventListener('mouseleave', function () {
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`mstar-${i}`).style.color = i <= modalCurrentRating ? '#ffc107' : '#dee2e6';
        }
    });
});

// Modal sub-star
document.querySelectorAll('.modal-sub-star').forEach(star => {
    star.addEventListener('click', function () {
        const key = this.dataset.key;
        const val = parseInt(this.dataset.val);
        document.getElementById(`mSub-${key}`).value = val;
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`msub-${key}-${i}`).style.color = i <= val ? '#ffc107' : '#dee2e6';
        }
    });
    star.addEventListener('mouseenter', function () {
        const key = this.dataset.key;
        const val = parseInt(this.dataset.val);
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`msub-${key}-${i}`).style.color = i <= val ? '#ffd700' : '#dee2e6';
        }
    });
    star.addEventListener('mouseleave', function () {
        const key = this.dataset.key;
        const cur = parseInt(document.getElementById(`mSub-${key}`)?.value || 0);
        for (let i = 1; i <= 5; i++) {
            document.getElementById(`msub-${key}-${i}`).style.color = i <= cur ? '#ffc107' : '#dee2e6';
        }
    });
});
</script>

<?php include '../../includes/footer.php'; ?>