<?php

function getRoleBadge(string $role): array {
    return match($role) {
        'ceo'        => ['class' => 'danger',    'icon' => '👑', 'label' => 'Tổng Giám Đốc'],
        'director'   => ['class' => 'primary',   'icon' => '🏢', 'label' => 'Giám Đốc'],
        'accountant' => ['class' => 'info',      'icon' => '💼', 'label' => 'Kế Toán'],
        'dispatcher' => ['class' => 'warning',   'icon' => '🚛', 'label' => 'Điều Hành Xe'],
        'driver'     => ['class' => 'success',   'icon' => '🚗', 'label' => 'Lái Xe'],
        'customer'   => ['class' => 'secondary', 'icon' => '🏪', 'label' => 'Khách Hàng'],
        default      => ['class' => 'secondary', 'icon' => '👤', 'label' => 'Người dùng'],
    };
}

function showFlash(): void {
    if (!empty($_SESSION['flash'])) {
        $f    = $_SESSION['flash'];
        $type = htmlspecialchars($f['type']);
        $msg  = $f['msg']; // Giữ nguyên HTML (tag <strong> v.v.)
        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$msg}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
        unset($_SESSION['flash']);
    }
}

function setFlash(string $type, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function formatMoney(float $amount): string {
    return number_format($amount, 0, '.', ',') . ' ₫';
}

function formatDate(string $date, string $format = 'd/m/Y'): string {
    if (empty($date)) return '—';
    return date($format, strtotime($date));
}

function formatDateTime(string $datetime): string {
    if (empty($datetime)) return '—';
    return date('H:i d/m/Y', strtotime($datetime));
}

function tripStatusBadge(string $status): array {
    return match($status) {
        'scheduled'   => ['warning',   '📅 Chờ xuất phát'],
        'in_progress' => ['primary',   '🚛 Đang chạy'],
        'completed'   => ['success',   '✅ Hoàn thành'],
        'confirmed'   => ['info',      '👍 Khách đã confirm'],
        'invoiced'    => ['dark',      '🧾 Đã xuất hóa đơn'],
        'cancelled'   => ['danger',    '❌ Đã hủy'],
        default       => ['secondary', '❓ Không xác định'],
    };
}

// ── CSRF Token ────────────────────────────────────────────────
function generateCSRF(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(string $token): bool {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}