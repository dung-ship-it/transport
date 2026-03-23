<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('hr', 'manage');

$pdo    = getDBConnection();
$user   = currentUser();
$id     = (int)($_GET['id']     ?? 0);
$action = $_GET['action'] ?? '';

if (!$id) {
    header('Location: index.php');
    exit;
}

// ── Kiểm tra nhân viên tồn tại ───────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name
    FROM users u JOIN roles r ON u.role_id = r.id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp || $emp['role_name'] === 'customer') {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Không tìm thấy nhân viên.'];
    header('Location: index.php');
    exit;
}

// ── Không được tự xóa chính mình ─────────────────────────────
if ($id === $user['id']) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'❌ Không thể thực hiện thao tác với chính mình.'];
    header('Location: view.php?id='.$id);
    exit;
}

// ── Xử lý action ─────────────────────────────────────────────
switch ($action) {

    case 'deactivate':
        $pdo->prepare("UPDATE users SET is_active = FALSE, updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        $_SESSION['flash'] = ['type'=>'warning',
            'msg'=>'⚠️ Đã cho nhân viên <strong>'.htmlspecialchars($emp['full_name']).'</strong> nghỉ việc.'];
        header('Location: view.php?id='.$id);
        exit;

    case 'activate':
        $pdo->prepare("UPDATE users SET is_active = TRUE, updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
        $_SESSION['flash'] = ['type'=>'success',
            'msg'=>'✅ Đã kích hoạt lại nhân viên <strong>'.htmlspecialchars($emp['full_name']).'</strong>.'];
        header('Location: view.php?id='.$id);
        exit;

    case 'delete':
        // Kiểm tra ràng buộc: nhân viên đã có chuyến xe
        $hasTrips = 0;
        if ($emp['role_name'] === 'driver') {
            $hasTrips = (int)$pdo->prepare("
                SELECT COUNT(*) FROM drivers d
                JOIN trips t ON t.driver_id = d.id
                WHERE d.user_id = ?
            ")->execute([$id]) ? $pdo->query("
                SELECT COUNT(*) FROM drivers d
                JOIN trips t ON t.driver_id = d.id
                WHERE d.user_id = $id
            ")->fetchColumn() : 0;
        }

        if ($hasTrips > 0) {
            $_SESSION['flash'] = ['type'=>'danger',
                'msg'=>'❌ Không thể xóa: nhân viên đã có <strong>'.$hasTrips.'</strong> chuyến xe trong hệ thống. Hãy cho nghỉ việc thay vì xóa.'];
            header('Location: view.php?id='.$id);
            exit;
        }

        try {
            $pdo->beginTransaction();

            // Xóa dữ liệu liên quan
            foreach ([
                "DELETE FROM hr_attendance WHERE user_id = ?",
                "DELETE FROM hr_overtime WHERE user_id = ?",
                "DELETE FROM hr_leaves WHERE user_id = ?",
                "DELETE FROM hr_salary_configs WHERE user_id = ?",
                "DELETE FROM hr_payroll_items WHERE user_id = ?",
                "DELETE FROM drivers WHERE user_id = ?",
                "DELETE FROM users WHERE id = ?",
            ] as $sql) {
                $pdo->prepare($sql)->execute([$id]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success',
                'msg'=>'✅ Đã xóa nhân viên <strong>'.htmlspecialchars($emp['full_name']).'</strong>.'];
            header('Location: index.php');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('delete employee: '.$e->getMessage());
            $_SESSION['flash'] = ['type'=>'danger',
                'msg'=>'❌ Lỗi khi xóa nhân viên: '.$e->getMessage()];
            header('Location: view.php?id='.$id);
            exit;
        }

    default:
        header('Location: index.php');
        exit;
}