<?php
require_once __DIR__ . '/database.php';

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function requireLogin(): void {
    startSession();
    if (empty($_SESSION['user_id'])) {
        header('Location: /transport/login.php');
        exit;
    }
}

function currentUser(): array {
    startSession();
    return $_SESSION['user'] ?? [];
}

function hasRole(string ...$roles): bool {
    $user = currentUser();
    return in_array($user['role'] ?? '', $roles, true);
}

function can(string $module, string $action): bool {
    startSession();
    $perms = $_SESSION['permissions'] ?? [];
    return in_array($module . '.' . $action, $perms, true);
}

function getHomeUrl(): string {
    $role = currentUser()['role'] ?? '';
    return match($role) {
        'driver'   => '/transport/driver/dashboard.php',
        'customer' => '/transport/customer/dashboard.php',
        default    => '/transport/select_module.php',
    };
}

function login(string $username, string $password): bool {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role, r.label AS role_label
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = ? AND u.is_active = TRUE
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        startSession();
        session_regenerate_id(true);

        // Load permissions (MySQL dùng CONCAT, không phải ||)
        $stmtP = $pdo->prepare("
            SELECT CONCAT(p.module, '.', p.action) AS perm
            FROM role_permissions rp
            JOIN permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ?
        ");
        $stmtP->execute([$user['role_id']]);
        $permissions = $stmtP->fetchAll(PDO::FETCH_COLUMN);

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['permissions'] = $permissions;
        $_SESSION['user'] = [
            'id'         => $user['id'],
            'username'   => $user['username'],
            'full_name'  => $user['full_name'],
            'email'      => $user['email'],
            'phone'      => $user['phone'],
            'role'       => $user['role'],
            'role_label' => $user['role_label'],
            'avatar'     => $user['avatar'],
        ];
        return true;
    }
    return false;
}

function logout(): void {
    startSession();
    session_destroy();
    header('Location: /transport/login.php');
    exit;
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        // Hiển thị trang lỗi đơn giản thay vì include (tránh lỗi header đã gửi)
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head><body>
        <div class="container py-5 text-center">
            <div style="font-size:4rem">🚫</div>
            <h4 class="mt-3">Không có quyền truy cập</h4>
            <p class="text-muted">Bạn không có quyền xem trang này.</p>
            <a href="/transport/dashboard.php" class="btn btn-primary">Về Dashboard</a>
        </div></body></html>';
        exit;
    }
}

function requirePermission(string $module, string $action): void {
    if (!can($module, $action)) {
        http_response_code(403);
        include __DIR__ . '/../includes/403.php';
        exit;
    }
}