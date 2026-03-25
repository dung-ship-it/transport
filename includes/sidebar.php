
<?php $user = currentUser(); ?>
<nav class="sidebar bg-dark text-white" id="sidebar">

    <!-- User info -->
    <div class="p-3 border-bottom border-secondary">
        <div class="d-flex align-items-center gap-2">
            <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center fw-bold"
                 style="width:40px;height:40px;font-size:1.1rem;flex-shrink:0">
                <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
            </div>
            <div class="overflow-hidden">
                <div class="small fw-semibold text-white text-truncate">
                    <?= htmlspecialchars($user['full_name']) ?>
                </div>
                <?php $badge = getRoleBadge($user['role']); ?>
                <span class="badge bg-<?= $badge['class'] ?> small">
                    <?= $badge['icon'] ?> <?= $badge['label'] ?>
                </span>
            </div>
        </div>
    </div>

    <ul class="nav flex-column p-2">

        <!-- ── DASHBOARD ── -->
        <li class="nav-item">
            <a href="/dashboard.php" class="nav-link text-white-50 hover-nav">
                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
            </a>
        </li>

        <!-- ── QUẢN TRỊ ── -->
        <?php if (can('users','view') || can('users','assign_role')): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Quản Trị</small>
        </li>

        <?php if (can('users', 'view')): ?>
        <li class="nav-item">
            <a href="/modules/admin/users/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-users-cog me-2"></i>Quản lý Users
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('users', 'assign_role')): ?>
        <li class="nav-item">
            <a href="/modules/admin/roles/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-shield-alt me-2"></i>Phân quyền
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── XE & NHIÊN LIỆU ── -->
        <?php if (
            can('vehicles','view') || can('vehicles','crud') ||
            can('expenses','create') || can('expenses','approve') ||
            can('fuel','create') || can('fuel','view_all')
        ): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Xe & Nhiên Liệu</small>
        </li>

        <?php if (can('vehicles', 'view') || can('vehicles', 'crud')): ?>
        <li class="nav-item">
            <a href="/modules/vehicles/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-truck me-2"></i>Quản lý xe
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('expenses', 'create') || can('expenses', 'approve')): ?>
        <li class="nav-item">
            <a href="/modules/vehicles/maintenance/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-tools me-2"></i>Sửa chữa / Bảo dưỡng
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('fuel', 'create')): ?>
        <li class="nav-item">
            <a href="/modules/vehicles/fuel/add.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-gas-pump me-2"></i>Nhập nhiên liệu
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('fuel', 'view_all')): ?>
        <li class="nav-item">
            <a href="/modules/vehicles/fuel/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-gas-pump me-2"></i>Quản lý xăng dầu
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── CHUYẾN XE ── -->
        <?php if (can('trips', 'view_all')): ?>
        <li class="nav-item">
            <a href="/modules/trips/create.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-list me-2"></i>Tạo chuyến xe
            </a>
        </li>
        <?php endif; ?>

        <?php if (
            can('trips','create') || can('trips','view_own') ||
            can('trips','view_all') || can('trips','confirm') ||
            can('statements','view_own')
        ): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Chuyến Xe</small>
        </li>

         <?php if (can('trips', 'confirm')): ?>
        <li class="nav-item">
            <a href="/modules/trips/confirm.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-check-circle me-2"></i>Xác nhận lịch trình
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('trips', 'view_own')): ?>
        <li class="nav-item">
            <a href="/modules/trips/my_trips.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-route me-2"></i>Lịch trình của tôi
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('trips', 'view_all')): ?>
        <li class="nav-item">
            <a href="/modules/trips/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-list me-2"></i>Tất cả chuyến xe
            </a>
        </li>
        <?php endif; ?>

       

        <?php if (can('statements', 'view_own')): ?>
        <li class="nav-item">
            <a href="/modules/statements/my_statements.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-file-invoice me-2"></i>Bảng kê của tôi
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── KHÁCH HÀNG ── -->
        <?php if (
            can('customers','view') || can('customers','crud') ||
            can('pricebook','view') || can('pricebook','crud') ||
            can('statements','crud')
        ): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Khách Hàng</small>
        </li>

        <?php if (can('customers', 'crud') || can('customers', 'view')): ?>
        <li class="nav-item">
            <a href="/modules/customers/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-building me-2"></i>Khách hàng
            </a>
        </li>
        <?php endif; ?>

        
        <?php if (can('statements', 'crud')): ?>
        <li class="nav-item">
            <a href="/modules/statements/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-file-invoice-dollar me-2"></i>Bảng kê công nợ
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── LƯƠNG & KPI ── -->
        <?php if (
            can('payroll','view') || can('payroll','approve') || can('payroll','submit') ||
            can('kpi','view') || can('kpi','manage') || can('kpi','calculate')
        ): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Lương & KPI</small>
        </li>

        <?php if (can('payroll','view') || can('payroll','approve') || can('payroll','submit')): ?>
        <li class="nav-item">
            <a href="/modules/payroll/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-money-check-alt me-2"></i>Bảng lương
            </a>
        </li>
        <?php endif; ?>

        <?php if (can('kpi','view') || can('kpi','manage') || can('kpi','calculate')): ?>
        <li class="nav-item">
            <a href="/modules/kpi/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-chart-bar me-2"></i>KPI Lái Xe
            </a>
        </li>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ── BÁO CÁO ── -->
        <?php if (
            can('reports','view_full') ||
            can('reports','view_operations') ||
            can('reports','view_own')
        ): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Báo Cáo</small>
        </li>
        <li class="nav-item">
            <a href="/modules/reports/index.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-chart-pie me-2"></i>Báo cáo tổng hợp
            </a>
        </li>
        <?php endif; ?>

    </ul>
<!-- Đổi module -->
    <div class="px-3 pb-2">
        <a href="/select_module.php"
           class="btn btn-outline-secondary btn-sm w-100">
            <i class="fas fa-th-large me-2"></i>Đổi nghiệp vụ
        </a>
    </div>
    <!-- Đăng xuất -->
    <div class="mt-auto p-3 border-top border-secondary">
        <a href="/logout.php" class="nav-link text-danger hover-nav">
            <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
        </a>
    </div>

</nav>
