<?php
require_once 'config/auth.php';
startSession();

// Nếu đã đăng nhập thì chuyển về select_module
if (!empty($_SESSION['user_id'])) {
    header('Location: /select_module.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        if (login($username, $password)) {
            // ✅ Luôn về select_module sau khi đăng nhập thành công
            header('Location: /select_module.php');
            exit;
        } else {
            $error = 'Tên đăng nhập hoặc mật khẩu không đúng!';
        }
    } else {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập - Hệ thống Điều Hành Xe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f3460 0%, #16213e 50%, #1a1a2e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            border: none;
        }
        .login-logo {
            width: 70px; height: 70px;
            background: linear-gradient(135deg, #0f3460, #e94560);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; margin: 0 auto 1rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #0f3460, #e94560);
            border: none; color: white; font-weight: 600;
            padding: 0.75rem;
            transition: opacity 0.2s;
        }
        .btn-login:hover { opacity: 0.9; color: white; }
        .form-control:focus {
            border-color: #0f3460;
            box-shadow: 0 0 0 0.2rem rgba(15,52,96,.25);
        }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card login-card">
                <div class="card-body p-4 p-md-5">

                    <!-- Logo & tiêu đề -->
                    <div class="text-center mb-4">
                        <div class="login-logo">🚛</div>
                        <h4 class="fw-bold mb-1">Điều Hành Xe</h4>
                        <p class="text-muted small">Đăng nhập để tiếp tục</p>
                    </div>

                    <!-- Thông báo lỗi -->
                    <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show py-2">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Form đăng nhập -->
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tên đăng nhập</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-user text-muted"></i>
                                </span>
                                <input type="text" name="username" class="form-control"
                                       placeholder="Nhập username..."
                                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                       required autofocus>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Mật khẩu</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-lock text-muted"></i>
                                </span>
                                <input type="password" name="password" id="passwordInput"
                                       class="form-control" placeholder="Nhập mật khẩu..." required>
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="togglePwd()">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-login w-100 rounded-3">
                            <i class="fas fa-sign-in-alt me-2"></i>Đăng nhập
                        </button>
                    </form>

                    <hr class="my-3">

                    <div class="text-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Liên hệ Admin nếu quên mật khẩu
                        </small>
                    </div>

                    <!-- Tài khoản demo -->
                    <div class="mt-3 p-2 bg-light rounded-3">
                        <small class="text-muted d-block mb-1 fw-semibold">🔑 Tài khoản demo:</small>
                        <small class="text-muted">
                            <code>admin</code> / <code>Admin@123</code> — Tổng GĐ<br>
                            <code>ketoan1</code> / <code>Admin@123</code> — Kế Toán<br>
                            <code>laixe01</code> / <code>Admin@123</code> — Lái Xe
                        </small>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePwd() {
    const inp = document.getElementById('passwordInput');
    const ico = document.getElementById('eyeIcon');
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        ico.classList.replace('fa-eye-slash', 'fa-eye');
    }
}
</script>
</body>
</html>