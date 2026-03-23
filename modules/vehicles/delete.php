<?php
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/functions.php';
requireLogin();
requirePermission('vehicles', 'crud');

$pdo    = getDBConnection();
$id     = (int)($_GET['id'] ?? 0);
$action = $_GET['action'] ?? '';

if ($id) {
    if ($action === 'deactivate') {
        $pdo->prepare("UPDATE vehicles SET is_active=FALSE, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([currentUser()['id'], $id]);
        $_SESSION['flash'] = ['type'=>'warning','msg'=>'⏸ Đã ngừng hoạt động xe!'];
    } elseif ($action === 'activate') {
        $pdo->prepare("UPDATE vehicles SET is_active=TRUE, updated_by=?, updated_at=NOW() WHERE id=?")
            ->execute([currentUser()['id'], $id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đã kích hoạt xe!'];
    }
}

header('Location: index.php');
exit;