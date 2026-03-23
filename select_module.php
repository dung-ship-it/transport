<?php
require_once 'config/database.php';
require_once 'config/auth.php';
require_once 'config/functions.php';
requireLogin();

$user = currentUser();
$role = $user['role'] ?? '';

// ── Customer và Driver không dùng select_module, redirect thẳng về dashboard của họ
if ($role === 'customer') {
    header('Location: /transport/customer/dashboard.php');
    exit;
}
if ($role === 'driver') {
    header('Location: /transport/driver/dashboard.php');
    exit;
}

// Định nghĩa các module và quyền truy cập
$modules = [
    'transport' => [
        'label'       => 'VẬN TẢI',
        'icon'        => 'fas fa-truck',
        'color'       => '#1a56db',
        'bg'          => 'linear-gradient(135deg, #1a56db 0%, #0e3a8c 100%)',
        'description' => 'Quản lý chuyến xe, lái xe,\nkhách hàng, nhiên liệu',
        'url'         => '/transport/dashboard.php',
        'roles'       => [], // [] = tất cả role đều thấy
        'stats_query' => "SELECT COUNT(*) FROM trips WHERE trip_date = CURDATE()",
        'stats_label' => 'chuyến hôm nay',
        'emoji'       => '🚛',
    ],
    'hr' => [
        'label'       => 'NHÂN SỰ',
        'icon'        => 'fas fa-users',
        'color'       => '#0e9f6e',
        'bg'          => 'linear-gradient(135deg, #0e9f6e 0%, #057a55 100%)',
        'description' => 'Chấm công, nghỉ phép, OT,\nbảng lương, KPI',
        'url'         => '/transport/hr/dashboard.php',
        'roles'       => [], // [] = tất cả
        'stats_query' => "SELECT COUNT(*) FROM users WHERE is_active = 1",
        'stats_label' => 'nhân viên',
        'emoji'       => '👥',
    ],
    'logistics' => [
        'label'       => 'LOGISTICS',
        'icon'        => 'fas fa-warehouse',
        'color'       => '#d03801',
        'bg'          => 'linear-gradient(135deg, #d03801 0%, #8b2000 100%)',
        'description' => 'Kho hàng, nhập xuất,\nđơn hàng, tồn kho',
        'url'         => '/transport/logistics/dashboard.php',
        'roles'       => [],
        'stats_query' => null,
        'stats_label' => 'Sắp ra mắt',
        'emoji'       => '📦',
        'coming_soon' => true,
    ],
];

$pdo = getDBConnection();

// Lấy stats cho từng module
foreach ($modules as $key => &$mod) {
    if ($mod['stats_query']) {
        try {
            $mod['stats_value'] = $pdo->query($mod['stats_query'])->fetchColumn();
        } catch (Exception $e) {
            $mod['stats_value'] = '—';
        }
    } else {
        $mod['stats_value'] = null;
    }
}
unset($mod);

$badge = getRoleBadge($user['role']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chọn Nghiệp Vụ — ERP System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ── Reset & Base ── */
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            background: #0f172a;
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(30, 64, 175, 0.15) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(5, 122, 85, 0.12) 0%, transparent 50%),
                radial-gradient(ellipse at 60% 80%, rgba(180, 30, 0, 0.10) 0%, transparent 50%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            overflow-x: hidden;
        }

        /* ── Header ── */
        .page-header {
            text-align: center;
            margin-bottom: 48px;
            animation: fadeDown 0.6s ease;
        }

        .company-logo {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #1a56db, #0e9f6e);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
            margin: 0 auto 16px;
            box-shadow: 0 8px 32px rgba(26,86,219,0.35);
        }

        .page-header h1 {
            font-size: clamp(1.4rem, 3vw, 2rem);
            font-weight: 700;
            color: #f1f5f9;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #94a3b8;
            font-size: 0.95rem;
        }

        /* ── User badge ── */
        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 50px;
            padding: 8px 16px 8px 8px;
            margin-bottom: 12px;
            backdrop-filter: blur(10px);
        }

        .user-avatar {
            width: 34px; height: 34px;
            background: linear-gradient(135deg, #1a56db, #7c3aed);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: #fff; font-size: 14px;
            flex-shrink: 0;
        }

        .user-info-text .name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #f1f5f9;
            line-height: 1.2;
        }

        .user-info-text .role {
            font-size: 0.72rem;
            color: #94a3b8;
        }

        /* ── Module grid ── */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            max-width: 960px;
            width: 100%;
            animation: fadeUp 0.7s ease 0.1s both;
        }

        @media (max-width: 768px) {
            .modules-grid { grid-template-columns: 1fr; max-width: 400px; }
        }

        /* ── Module card ── */
        .module-card {
            position: relative;
            border-radius: 24px;
            overflow: hidden;
            cursor: pointer;
            text-decoration: none;
            display: block;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1),
                        box-shadow 0.3s ease;
            outline: none;
        }

        .module-card:not(.coming-soon):hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 24px 60px rgba(0,0,0,0.4);
        }

        .module-card:not(.coming-soon):active {
            transform: translateY(-4px) scale(1.01);
        }

        .module-card.coming-soon {
            cursor: default;
            opacity: 0.6;
            filter: grayscale(30%);
        }

        /* Inner gradient bg */
        .card-bg {
            padding: 36px 28px 28px;
            min-height: 260px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        /* Decorative circles */
        .card-bg::before, .card-bg::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.07);
            pointer-events: none;
        }

        .card-bg::before {
            width: 160px; height: 160px;
            top: -50px; right: -40px;
        }

        .card-bg::after {
            width: 100px; height: 100px;
            bottom: -30px; left: -20px;
        }

        /* Card content */
        .card-icon {
            width: 56px; height: 56px;
            background: rgba(255,255,255,0.18);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px;
            color: #fff;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .card-emoji {
            font-size: 22px;
            margin-bottom: 4px;
        }

        .card-label {
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: 1px;
            margin-bottom: 8px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .card-desc {
            font-size: 0.82rem;
            color: rgba(255,255,255,0.75);
            line-height: 1.5;
            white-space: pre-line;
        }

        /* Stats bar */
        .card-stats {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .stats-number {
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            line-height: 1;
        }

        .stats-label {
            font-size: 0.72rem;
            color: rgba(255,255,255,0.65);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .card-arrow {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: 14px;
            transition: background 0.2s, transform 0.2s;
        }

        .module-card:not(.coming-soon):hover .card-arrow {
            background: rgba(255,255,255,0.3);
            transform: translateX(3px);
        }

        /* Coming soon badge */
        .coming-soon-badge {
            position: absolute;
            top: 16px; right: 16px;
            background: rgba(255,255,255,0.2);
            color: #fff;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.3);
            backdrop-filter: blur(10px);
        }

        /* Ripple on click */
        .module-card .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.25);
            transform: scale(0);
            animation: rippleAnim 0.6s linear;
            pointer-events: none;
        }

        @keyframes rippleAnim {
            to { transform: scale(4); opacity: 0; }
        }

        /* Footer */
        .page-footer {
            margin-top: 40px;
            text-align: center;
            animation: fadeUp 0.7s ease 0.3s both;
        }

        .page-footer a {
            color: #64748b;
            font-size: 0.82rem;
            text-decoration: none;
            transition: color 0.2s;
        }

        .page-footer a:hover { color: #94a3b8; }

        /* Animations */
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Staggered card animation */
        .module-card:nth-child(1) { animation: fadeUp 0.6s ease 0.15s both; }
        .module-card:nth-child(2) { animation: fadeUp 0.6s ease 0.25s both; }
        .module-card:nth-child(3) { animation: fadeUp 0.6s ease 0.35s both; }

        /* Loading overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.85);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
            backdrop-filter: blur(8px);
        }

        .loading-overlay.show { display: flex; }

        .loading-spinner {
            width: 48px; height: 48px;
            border: 4px solid rgba(255,255,255,0.15);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        .loading-text {
            color: #f1f5f9;
            font-size: 0.9rem;
            font-weight: 500;
        }
    </style>
</head>
<body>

<!-- Loading overlay -->
<div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner"></div>
    <div class="loading-text" id="loadingText">Đang mở...</div>
</div>

<!-- Header -->
<div class="page-header">
    <div class="company-logo">🏢</div>

    <!-- User info -->
    <div class="user-badge">
        <div class="user-avatar">
            <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
        </div>
        <div class="user-info-text">
            <div class="name"><?= htmlspecialchars($user['full_name']) ?></div>
            <div class="role">
                <?= $badge['icon'] ?> <?= $badge['label'] ?>
            </div>
        </div>
    </div>

    <h1>Chọn nghiệp vụ</h1>
    <p>Chọn module bạn muốn làm việc hôm nay</p>
</div>

<!-- Module cards -->
<div class="modules-grid">
    <?php foreach ($modules as $key => $mod): ?>

    <?php if (!empty($mod['coming_soon'])): ?>
    <div class="module-card coming-soon">
    <?php else: ?>
    <a href="<?= $mod['url'] ?>"
       class="module-card"
       onclick="handleModuleClick(event, '<?= $key ?>', '<?= $mod['label'] ?>', '<?= $mod['url'] ?>')">
    <?php endif; ?>

        <div class="card-bg" style="background: <?= $mod['bg'] ?>">

            <?php if (!empty($mod['coming_soon'])): ?>
            <div class="coming-soon-badge">🚧 Sắp ra mắt</div>
            <?php endif; ?>

            <div>
                <div class="card-icon">
                    <i class="<?= $mod['icon'] ?>"></i>
                </div>
                <div class="card-label"><?= $mod['label'] ?></div>
                <div class="card-desc"><?= $mod['description'] ?></div>
            </div>

            <div class="card-stats">
                <div>
                    <?php if ($mod['stats_value'] !== null): ?>
                    <div class="stats-number"><?= $mod['stats_value'] ?></div>
                    <div class="stats-label"><?= $mod['stats_label'] ?></div>
                    <?php else: ?>
                    <div class="stats-label" style="font-size:0.8rem;text-transform:none;">
                        <?= $mod['stats_label'] ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($mod['coming_soon'])): ?>
                <div class="card-arrow">
                    <i class="fas fa-arrow-right"></i>
                </div>
                <?php endif; ?>
            </div>

        </div>

    <?php if (!empty($mod['coming_soon'])): ?>
    </div>
    <?php else: ?>
    </a>
    <?php endif; ?>

    <?php endforeach; ?>
</div>

<!-- Footer -->
<div class="page-footer">
    <a href="/transport/logout.php">
        <i class="fas fa-sign-out-alt me-1"></i>Đăng xuất
    </a>
    <span style="color:#334155; margin: 0 12px">·</span>
    <span style="color:#334155; font-size:0.82rem">
        <?= date('l, d/m/Y') ?>
    </span>
</div>

<script>
function handleModuleClick(e, moduleKey, moduleLabel, url) {
    e.preventDefault();

    // Ripple effect
    const card  = e.currentTarget;
    const rect  = card.getBoundingClientRect();
    const rip   = document.createElement('span');
    const size  = Math.max(rect.width, rect.height) * 2;
    rip.className = 'ripple';
    rip.style.cssText = `
        width:${size}px; height:${size}px;
        left:${e.clientX - rect.left - size/2}px;
        top:${e.clientY - rect.top - size/2}px;
    `;
    card.appendChild(rip);
    setTimeout(() => rip.remove(), 700);

    // Show loading
    const overlay = document.getElementById('loadingOverlay');
    const text    = document.getElementById('loadingText');
    const labels  = {
        transport: '🚛 Đang mở Vận Tải...',
        hr:        '👥 Đang mở Nhân Sự...',
        logistics: '📦 Đang mở Logistics...',
    };
    text.textContent = labels[moduleKey] || 'Đang mở...';
    overlay.classList.add('show');

    // Navigate after short delay
    setTimeout(() => { window.location.href = url; }, 400);
}

// Keyboard navigation
document.querySelectorAll('.module-card:not(.coming-soon)').forEach((card, i) => {
    card.setAttribute('tabindex', i + 1);
    card.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            card.click();
        }
    });
});
</script>

</body>
</html>