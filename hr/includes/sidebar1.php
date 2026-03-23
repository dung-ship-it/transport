<?php
// Xác định trang hiện tại để highlight active
$currentPath = $_SERVER['PHP_SELF'] ?? '';

function hrNavActive(string $path, string $current): string {
    return str_contains($current, $path) ? 'active' : '';
}

// Đếm pending để hiện badge
function hrBadge(PDO $pdo, string $sql): int {
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Exception $e) { return 0; }
}

$pendingLeaveCount = isset($pdo) ? hrBadge($pdo,
    "SELECT COUNT(*) FROM hr_leaves WHERE status='pending'") : 0;
$pendingOTCount    = isset($pdo) ? hrBadge($pdo,
    "SELECT COUNT(*) FROM hr_overtime WHERE status='pending'") : 0;
$totalPending = $pendingLeaveCount + $pendingOTCount;
?>

<!-- ══ SIDEBAR ══════════════════════════════════════════════ -->
<nav class="sidebar" id="hrSidebar">

    <!-- Brand -->
    <a href="/transport/hr/dashboard.php" class="sidebar-brand">
        <div class="brand-icon">👥</div>
        <div>
            <div class="brand-text">HR System</div>
            <div class="brand-sub">Nhân sự & Tiền lương</div>
        </div>
    </a>

    <!-- Chuyển module -->
    <div style="padding:10px 12px 4px">
        <a href="/transport/select_module.php"
           class="d-flex align-items-center gap-2 px-2 py-2 rounded text-decoration-none"
           style="background:rgba(255,255,255,.05);color:#94a3b8;font-size:.78rem">
            <i class="fas fa-th-large"></i>
            <span>Đổi nghiệp vụ</span>
        </a>
    </div>

    <!-- Nav: Tổng quan -->
    <div class="sidebar-section">Tổng quan</div>

    <a href="/transport/hr/dashboard.php"
       class="sidebar-link <?= hrNavActive('hr/dashboard', $currentPath) ?>">
        <i class="fas fa-tachometer-alt nav-icon"></i>
        <span>Dashboard HR</span>
    </a>

    <!-- Nav: Nhân viên -->
    <div class="sidebar-section">Nhân viên</div>

    <a href="/transport/hr/modules/employees/index.php"
       class="sidebar-link <?= hrNavActive('employees', $currentPath) ?>">
        <i class="fas fa-users nav-icon"></i>
        <span>Danh sách nhân viên</span>
    </a>

    <!-- Nav: Chấm công & Nghỉ phép -->
    <div class="sidebar-section">Chấm công</div>

    <a href="/transport/hr/modules/attendance/index.php"
       class="sidebar-link <?= hrNavActive('attendance', $currentPath) ?>">
        <i class="fas fa-clock nav-icon"></i>
        <span>Chấm công</span>
    </a>

    <a href="/transport/hr/modules/overtime/index.php"
       class="sidebar-link <?= hrNavActive('overtime', $currentPath) ?>">
        <i class="fas fa-business-time nav-icon"></i>
        <span>Tăng ca (OT)</span>
        <?php if ($pendingOTCount > 0): ?>
        <span class="badge bg-info badge-count"><?= $pendingOTCount ?></span>
        <?php endif; ?>
    </a>

    <a href="/transport/hr/modules/leave/index.php"
       class="sidebar-link <?= hrNavActive('leave', $currentPath) ?>">
        <i class="fas fa-calendar-minus nav-icon"></i>
        <span>Nghỉ phép</span>
        <?php if ($pendingLeaveCount > 0): ?>
        <span class="badge bg-warning text-dark badge-count"><?= $pendingLeaveCount ?></span>
        <?php endif; ?>
    </a>

    <!-- Nav: Lương -->
    <div class="sidebar-section">Tiền lương</div>

    <a href="/transport/hr/modules/payroll/index.php"
       class="sidebar-link <?= hrNavActive('payroll', $currentPath) ?>">
        <i class="fas fa-money-bill-wave nav-icon"></i>
        <span>Bảng lương</span>
    </a>

    <!-- Nav: Báo cáo -->
    <div class="sidebar-section">Báo cáo</div>

    <a href="/transport/hr/modules/reports/index.php"
       class="sidebar-link <?= hrNavActive('reports', $currentPath) ?>">
        <i class="fas fa-chart-bar nav-icon"></i>
        <span>Báo cáo HR</span>
    </a>

    <!-- Footer sidebar -->
    <div style="padding:16px;margin-top:auto;border-top:1px solid rgba(255,255,255,.06)">
        <div style="font-size:.72rem;color:#475569;margin-bottom:8px">
            <?= htmlspecialchars($user['full_name'] ?? '') ?>
        </div>
        <a href="/transport/logout.php"
           style="color:#64748b;font-size:.75rem;text-decoration:none">
            <i class="fas fa-sign-out-alt me-1"></i>Đăng xuất
        </a>
    </div>

</nav>

<!-- ══ TOPBAR ════════════════════════════════════════════════ -->
<div class="topbar">
    <!-- Toggle mobile -->
    <button class="btn btn-sm btn-light d-md-none me-2"
            onclick="document.getElementById('hrSidebar').classList.toggle('show')">
        <i class="fas fa-bars"></i>
    </button>

    <span class="topbar-module-badge">👥 HR</span>

    <nav class="topbar-breadcrumb">
        <a href="/transport/hr/dashboard.php">HR</a>
        <?php if (isset($pageTitle) && $pageTitle !== 'HR Dashboard'): ?>
        <span class="mx-1 text-muted">/</span>
        <span><?= htmlspecialchars($pageTitle) ?></span>
        <?php endif; ?>
    </nav>

    <div class="ms-auto d-flex align-items-center gap-3">
        <?php if ($totalPending > 0): ?>
        <span class="badge bg-danger rounded-pill" title="Yêu cầu chờ duyệt">
            <i class="fas fa-bell me-1"></i><?= $totalPending ?>
        </span>
        <?php endif; ?>

        <div class="d-flex align-items-center gap-2" style="font-size:.83rem">
            <div style="width:30px;height:30px;background:linear-gradient(135deg,#0e9f6e,#057a55);
                        border-radius:50%;display:flex;align-items:center;justify-content:center;
                        color:#fff;font-weight:700;font-size:.8rem">
                <?= mb_strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1)) ?>
            </div>
            <span class="d-none d-md-inline text-muted">
                <?= htmlspecialchars($user['full_name'] ?? '') ?>
            </span>
        </div>
    </div>
</div>