<?php
require_once '../../../config/database.php';
require_once '../../../config/auth.php';
require_once '../../../config/functions.php';
requireLogin();
requirePermission('users', 'edit');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v === '' ? null : $v;
}

function nullDate(?string $val): ?string {
    $v = trim($val ?? '');
    return ($v === '' || $v === '0000-00-00') ? null : $v;
}

$pdo->beginTransaction();
try {
    // ── Boolean phải truyền dạng string 'true'/'false' cho PostgreSQL ──
    $tempSame = isset($_POST['temp_same_as_permanent']) ? 'true' : 'false';

    $stmt = $pdo->prepare("
        UPDATE users SET
            full_name              = :full_name,
            phone                  = :phone,
            email                  = :email,
            gender                 = :gender,
            marital_status         = :marital_status,
            date_of_birth          = :date_of_birth,
            hire_date              = :hire_date,
            ethnicity              = :ethnicity,
            permanent_province     = :permanent_province,
            permanent_district     = :permanent_district,
            permanent_street       = :permanent_street,
            permanent_address      = :permanent_address,
            temp_same_as_permanent = :temp_same::boolean,
            temp_province          = :temp_province,
            temp_district          = :temp_district,
            temp_street            = :temp_street,
            temp_address           = :temp_address,
            id_number              = :id_number,
            id_issue_date          = :id_issue_date,
            id_issue_place         = :id_issue_place,
            social_insurance       = :social_insurance,
            tax_code               = :tax_code,
            bank_name              = :bank_name,
            bank_account           = :bank_account,
            bank_branch            = :bank_branch,
            updated_at             = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        ':full_name'              => nullIfEmpty($_POST['full_name']          ?? ''),
        ':phone'                  => nullIfEmpty($_POST['phone']              ?? ''),
        ':email'                  => nullIfEmpty($_POST['email']              ?? ''),
        ':gender'                 => nullIfEmpty($_POST['gender']             ?? ''),
        ':marital_status'         => nullIfEmpty($_POST['marital_status']     ?? ''),
        ':date_of_birth'          => nullDate($_POST['date_of_birth']         ?? ''),
        ':hire_date'              => nullDate($_POST['hire_date']             ?? ''),
        ':ethnicity'              => nullIfEmpty($_POST['ethnicity']          ?? ''),
        ':permanent_province'     => nullIfEmpty($_POST['permanent_province'] ?? ''),
        ':permanent_district'     => nullIfEmpty($_POST['permanent_district'] ?? ''),
        ':permanent_street'       => nullIfEmpty($_POST['permanent_street']   ?? ''),
        ':permanent_address'      => nullIfEmpty($_POST['permanent_address']  ?? ''),
        ':temp_same'              => $tempSame,   // ← 'true' hoặc 'false' dạng string
        ':temp_province'          => nullIfEmpty($_POST['temp_province']      ?? ''),
        ':temp_district'          => nullIfEmpty($_POST['temp_district']      ?? ''),
        ':temp_street'            => nullIfEmpty($_POST['temp_street']        ?? ''),
        ':temp_address'           => nullIfEmpty($_POST['temp_address']       ?? ''),
        ':id_number'              => nullIfEmpty($_POST['id_number']          ?? ''),
        ':id_issue_date'          => nullDate($_POST['id_issue_date']         ?? ''),
        ':id_issue_place'         => nullIfEmpty($_POST['id_issue_place']     ?? ''),
        ':social_insurance'       => nullIfEmpty($_POST['social_insurance']   ?? ''),
        ':tax_code'               => nullIfEmpty($_POST['tax_code']           ?? ''),
        ':bank_name'              => nullIfEmpty($_POST['bank_name']          ?? ''),
        ':bank_account'           => nullIfEmpty($_POST['bank_account']       ?? ''),
        ':bank_branch'            => nullIfEmpty($_POST['bank_branch']        ?? ''),
        ':id'                     => $id,
    ]);

    // ── Khoản lương ──
    if (isset($_POST['salary_name'])) {
        $names   = $_POST['salary_name']   ?? [];
        $amounts = $_POST['salary_amount'] ?? [];

        $pdo->prepare("DELETE FROM salary_components WHERE user_id = ?")->execute([$id]);

        foreach ($names as $i => $name) {
            if (trim($name) === '') continue;
            $pdo->prepare("
                INSERT INTO salary_components (user_id, name, amount)
                VALUES (?, ?, ?)
            ")->execute([
                $id,
                trim($name),
                (float)($amounts[$i] ?? 0)
            ]);
        }
    }

    $pdo->commit();
    $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Đã lưu hồ sơ nhân viên!'];

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => '❌ Lỗi: ' . $e->getMessage()];
}

header("Location: profile.php?id=$id");
exit;