<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
requireLogin();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="mau_cham_cong_' . date('Ymd') . '.csv"');

// BOM UTF-8 để Excel đọc đúng tiếng Việt
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
// Header
fputcsv($out, ['mã nhân viên', 'giờ vào', 'giờ ra']);
// Dữ liệu mẫu
fputcsv($out, ['NV001', '08:00', '17:00']);
fputcsv($out, ['NV002', '08:15', '17:30']);
fputcsv($out, ['NV003', '07:45', '16:45']);
fclose($out);
exit;