<?php
// HR module header — dùng chung Bootstrap/FontAwesome với transport
$baseUrl = '/transport';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'HR System') ?> — ERP</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        /* ── Layout chung ── */
        body { background:#f5f7fa; font-family:'Segoe UI',system-ui,sans-serif; }

        .sidebar {
            width: 240px;
            min-height: 100vh;
            background: linear-gradient(180deg, #0f3460 0%, #16213e 100%);
            position: fixed;
            top: 0; left: 0;
            z-index: 1000;
            overflow-y: auto;
            transition: width .25s;
        }

        .sidebar-brand {
            padding: 16px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .sidebar-brand .brand-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #0e9f6e, #057a55);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .sidebar-brand .brand-text {
            color: #f1f5f9;
            font-weight: 700;
            font-size: .9rem;
            line-height: 1.2;
        }

        .sidebar-brand .brand-sub {
            color: #64748b;
            font-size: .7rem;
        }

        .sidebar-section {
            padding: 12px 16px 4px;
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #475569;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            color: #94a3b8;
            text-decoration: none;
            font-size: .83rem;
            border-left: 3px solid transparent;
            transition: all .15s;
        }

        .sidebar-link:hover {
            color: #f1f5f9;
            background: rgba(255,255,255,.05);
            border-left-color: #0e9f6e;
        }

        .sidebar-link.active {
            color: #fff;
            background: rgba(14,159,110,.15);
            border-left-color: #0e9f6e;
            font-weight: 600;
        }

        .sidebar-link .nav-icon {
            width: 18px;
            text-align: center;
            font-size: .85rem;
            flex-shrink: 0;
        }

        .sidebar-link .badge-count {
            margin-left: auto;
            font-size: .65rem;
        }

        /* ── Main content ── */
        .main-content {
            margin-left: 240px;
            min-height: 100vh;
            transition: margin-left .25s;
        }

        /* ── Topbar ── */
        .topbar {
            height: 56px;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 900;
            box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }

        .topbar-module-badge {
            background: linear-gradient(135deg, #0e9f6e, #057a55);
            color: #fff;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .5px;
        }

        .topbar-breadcrumb {
            color: #64748b;
            font-size: .82rem;
        }

        .topbar-breadcrumb a {
            color: #0e9f6e;
            text-decoration: none;
        }

        /* ── Mobile ── */
        @media (max-width: 768px) {
            .sidebar { width: 0; overflow: hidden; }
            .sidebar.show { width: 240px; }
            .main-content { margin-left: 0; }
        }

        /* ── Utils ── */
        .page-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08);
            border: none;
        }
    </style>
</head>
<body>