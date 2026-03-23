<?php
$pageTitle = $pageTitle ?? 'Driver Portal';
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { -webkit-tap-highlight-color: transparent; }

        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            padding-bottom: 80px; /* chừa chỗ bottom nav */
        }

        /* ── Top Bar ── */
        .driver-topbar {
            background: linear-gradient(135deg, #1a1a2e 0%, #0f3460 100%);
            color: white;
            padding: 12px 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        /* ── Cards ── */
        .driver-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 12px;
            border: none;
        }

        .stat-card {
            border-radius: 16px;
            padding: 16px;
            color: white;
            text-align: center;
        }

        /* ── Trip Card ── */
        .trip-card {
            background: white;
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 12px;
            border-left: 4px solid #0f3460;
            cursor: pointer;
            transition: transform 0.15s;
        }
        .trip-card:active { transform: scale(0.98); }
        .trip-card.status-completed  { border-left-color: #198754; }
        .trip-card.status-in_progress{ border-left-color: #0d6efd; }
        .trip-card.status-scheduled  { border-left-color: #ffc107; }

        /* ── Bottom Nav ── */
        .bottom-nav {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: white;
            border-top: 1px solid #e9ecef;
            display: flex;
            z-index: 200;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
        }
        .bottom-nav a {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 8px 4px;
            text-decoration: none;
            color: #6c757d;
            font-size: 0.7rem;
            transition: color 0.2s;
        }
        .bottom-nav a.active,
        .bottom-nav a:hover { color: #0f3460; }
        .bottom-nav a i { font-size: 1.3rem; margin-bottom: 2px; }

        /* ── Buttons ── */
        .btn-driver {
            border-radius: 12px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
        }

        /* ── Badges ── */
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* ── Form ── */
        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px;
            font-size: 1rem;
        }

        /* ── Section title ── */
        .section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            margin: 16px 0 8px;
        }
    </style>
</head>
<body>