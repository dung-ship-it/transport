<?php $user = currentUser(); ?>
<nav class="sidebar bg-dark text-white" id="sidebar">

    <!-- User info -->
    <div class="p-3 border-bottom border-secondary">
        <div class="d-flex align-items-center gap-2">
            <div class="bg-info rounded-circle d-flex align-items-center justify-content-center fw-bold"
                 style="width:40px;height:40px;font-size:1.1rem;flex-shrink:0">
                <?= mb_strtoupper(mb_substr($user['full_name'], 0, 1)) ?>
            </div>
            <div class="overflow-hidden">
                <div class="small fw-semibold text-white text-truncate">
                    <?= htmlspecialchars($user['full_name']) ?>
                </div>
                <?php
                // Lấy tên công ty của khách hàng
                $cuInfo = null;
                try {
                    $cuStmt = $pdo->prepare("
                        SELECT c.company_name, c.short_name
                        FROM customer_users cu
                        JOIN customers c ON cu.customer_id = c.id
                        WHERE cu.user_id = ? AND cu.is_active = TRUE
                        LIMIT 1
                    ");
                    $cuStmt->execute([$user['id']]);
                    $cuInfo = $cuStmt->fetch();
                } catch (Exception $e) {}
                ?>
                <span class="badge bg-info small">
                    <i class="fas fa-building me-1"></i>
                    <?= htmlspecialchars($cuInfo['short_name'] ?: ($cuInfo['company_name'] ?? 'Khách hàng')) ?>
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

        <!-- ── CHUYẾN XE ── -->
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Chuyến Xe</small>
        </li>

        <li class="nav-item">
            <a href="/modules/trips/customer_confirm.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-check-circle me-2"></i>Xác nhận lịch trình
            </a>
        </li>

        <!-- ── CÔNG NỢ ── -->
        <?php if (can('statements', 'view_own') || can('statements', 'crud')): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Công Nợ</small>
        </li>

        <li class="nav-item">
            <a href="/modules/statements/my_statements.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-file-invoice me-2"></i>Bảng kê của tôi
            </a>
        </li>
        <?php endif; ?>

        <!-- ── BÁO CÁO ── -->
        <?php if (can('reports', 'view_own')): ?>
        <li class="nav-item mt-2">
            <small class="text-muted px-3 text-uppercase"
                   style="font-size:0.62rem;letter-spacing:0.5px">Báo Cáo</small>
        </li>
        <li class="nav-item">
            <a href="/modules/reports/customer_report.php"
               class="nav-link text-white-50 hover-nav">
                <i class="fas fa-chart-pie me-2"></i>Báo cáo vận chuyển
            </a>
        </li>
        <?php endif; ?>

    </ul>

    <!-- Đăng xuất -->
    <div class="mt-auto p-3 border-top border-secondary">
        <a href="/logout.php" class="nav-link text-danger hover-nav">
            <i class="fas fa-sign-out-alt me-2"></i>Đăng xuất
        </a>
    </div>

</nav>