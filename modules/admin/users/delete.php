<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'edit');

$pdo = getDBConnection();
$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if (!$id || $id === currentUser()['id']) {
    header('Location: index.php');
    exit;
}

switch ($action) {
    case 'lock':
        $pdo->prepare("UPDATE users SET is_active = FALSE WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => '🔒 Đã khóa tài khoản!'];
        break;

    case 'unlock':
        $pdo->prepare("UPDATE users SET is_active = TRUE WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'msg' => '🔓 Đã mở khóa tài khoản!'];
        break;

    case 'delete':
        requirePermission('users', 'delete');
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => '🗑️ Đã xóa tài khoản!'];
        break;
}

header('Location: index.php');
exit;