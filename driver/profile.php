<?php
require_once '../config/database.php';
require_once '../config/auth.php';
require_once '../config/functions.php';
requireLogin();
if (!hasRole('driver')) { header('Location: /transport/dashboard.php'); exit; }

$pageTitle = 'Hồ sơ của tôi';
$pdo  = getDBConnection();
$user = currentUser();

$stmt = $pdo->prepare("
    SELECT d.*, u.full_name, u.email, u.phone, u.username
    FROM drivers d JOIN users u ON d.user_id = u.id
    WHERE d.user_id = ?
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

include 'includes/header.php';
?>

<div class="driver-topbar">
    <div class="fw-bold">👤 Hồ sơ của tôi</div>
</div>

<div class="px-3 pt-3">

    <!-- Avatar & Tên -->
    <div class="driver-card text-center py-4 mb-3">
        <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center mx-auto mb-3"
             style="width:80px;height:80px;font-size:2rem">
            <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
        </div>
        <h5 class="fw-bold mb-1"><?= htmlspecialchars($user['full_name']) ?></h5>
        <span class="badge bg-success">🚗 Lái Xe</span>
    </div>

    <!-- Thông tin cá nhân -->
    <div class="driver-card">
        <div class="section-title mb-3">Thông tin cá nhân</div>
        <div class="row g-0">
            <?php $rows = [
                ['fas fa-user',       'Username',      $user['username']],
                ['fas fa-phone',      'Điện thoại',    $user['phone'] ?? '—'],
                ['fas fa-envelope',   'Email',         $user['email'] ?? '—'],
                ['fas fa-id-card',    'GPLX',          $profile['license_number'] ?? '—'],
                ['fas fa-car',        'Hạng GPLX',     $profile['license_class'] ?? '—'],
                ['fas fa-calendar',   'Hạn GPLX',      $profile['license_expiry'] ? date('d/m/Y', strtotime($profile['license_expiry'])) : '—'],
                ['fas fa-briefcase',  'Ngày vào làm',  $profile['hire_date'] ? date('d/m/Y', strtotime($profile['hire_date'])) : '—'],
            ];
            foreach ($rows as [$icon, $label, $value]): ?>
            <div class="col-12 py-2 border-bottom d-flex align-items-center gap-3">
                <div class="text-primary" style="width:20px;text-align:center">
                    <i class="<?= $icon ?>"></i>
                </div>
                <div class="text-muted small" style="width:110px"><?= $label ?></div>
                <div class="fw-semibold small"><?= htmlspecialchars($value) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Đăng xuất -->
    <a href="/transport/logout.php"
       class="btn btn-outline-danger btn-driver mb-3"
       onclick="return confirm('Đăng xuất?')">
        <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
    </a>

</div>

<?php include 'includes/bottom_nav.php'; ?>