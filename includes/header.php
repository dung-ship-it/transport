<?php
$pageTitle = $pageTitle ?? 'Điều Hành Xe';
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Điều Hành Xe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/style.css" rel="stylesheet">
</head>
<body>

<!-- ── Topbar mobile (chỉ hiện trên điện thoại) ── -->
<nav class="navbar navbar-dark bg-dark d-md-none px-3 py-2">
    <button class="btn btn-outline-secondary btn-sm" id="sidebarToggleMobile">
        <i class="fas fa-bars"></i>
    </button>
    <span class="navbar-brand mb-0 fs-6 fw-bold mx-auto">
        <i class="fas fa-truck me-1"></i><?= htmlspecialchars($pageTitle) ?>
    </span>
    <a href="/select_module.php"
       class="btn btn-outline-light btn-sm"
       title="Đổi nghiệp vụ">
        <i class="fas fa-th-large"></i>
    </a>
</nav>

<div class="wrapper d-flex">