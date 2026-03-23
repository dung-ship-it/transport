<?php if (!defined('DB_HOST')) { header('Location: /transport/login.php'); exit; } ?>
<div class="main-content">
    <div class="container-fluid py-5 text-center">
        <div class="fs-1 mb-3">🚫</div>
        <h3 class="text-danger">Không có quyền truy cập</h3>
        <p class="text-muted">Bạn không có quyền xem trang này.</p>
        <a href="/transport/dashboard.php" class="btn btn-primary">
            <i class="fas fa-home me-1"></i> Về Dashboard
        </a>
    </div>
</div>