<?php $currentPage = basename($_SERVER['PHP_SELF'], '.php'); ?>
<nav class="bottom-nav">
    <a href="/driver/dashboard.php"
       class="<?= $currentPage==='dashboard' ? 'active' : '' ?>">
        <i class="fas fa-home"></i>Trang chủ
    </a>
    <a href="/driver/trips.php"
       class="<?= in_array($currentPage,['trips','trip_detail']) ? 'active' : '' ?>">
        <i class="fas fa-route"></i>Chuyến xe
    </a>
    <a href="/driver/trip_create.php"
       class="<?= $currentPage==='trip_create' ? 'active' : '' ?>">
        <i class="fas fa-plus-circle fa-lg"></i>Tạo chuyến
    </a>
    <a href="/driver/fuel_history.php"
       class="<?= in_array($currentPage,['fuel_history','fuel_add']) ? 'active' : '' ?>">
        <i class="fas fa-gas-pump"></i>Xăng dầu
    </a>
    <a href="/driver/profile.php"
       class="<?= $currentPage==='profile' ? 'active' : '' ?>">
        <i class="fas fa-user"></i>Tôi
    </a>
</nav>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>