<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'edit');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $password  = $_POST['password']  ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (strlen($password) < 4) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Mật khẩu tối thiểu 4 ký tự!'];
    } elseif ($password !== $password2) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Mật khẩu xác nhận không khớp!'];
    } else {
        $pdo->prepare("
            UPDATE users SET password_hash = crypt(?, gen_salt('bf')) WHERE id = ?
        ")->execute([$password, $id]);
        $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ Đổi mật khẩu thành công!'];
    }
}

header("Location: profile.php?id=$id");
exit;