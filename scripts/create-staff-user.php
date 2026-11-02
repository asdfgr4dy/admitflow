<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/AuditLog.php';
require_once dirname(__DIR__) . '/app/SecurityPolicy.php';

$name = trim($argv[1] ?? '');
$email = mb_strtolower(trim($argv[2] ?? ''));
$role = trim($argv[3] ?? '');

if ($name === '' || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['reviewer', 'admin'], true)) {
    fwrite(STDERR, "Usage: php scripts/create-staff-user.php \"Full name\" email@example.test reviewer|admin\n");
    exit(2);
}

try {
    $database = Database::connection();
    if ($role === 'admin') {
        $adminCount = (int) $database->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND account_status = 'active'")->fetchColumn();
        if ($adminCount > 0) {
            fwrite(STDERR, "An active administrator already exists. Use the secured administration process to create another.\n");
            exit(1);
        }
    }

    $temporaryPassword = SecurityPolicy::generatePassword(['name' => $name, 'email' => $email]);
    $database->beginTransaction();
    $insert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status, must_change_password) VALUES (:name, :email, :password_hash, :role, \'active\', 1)');
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash(SecurityPolicy::normalizePassword($temporaryPassword), PASSWORD_DEFAULT),
        'role' => $role,
    ]);
    $userId = (int) $database->lastInsertId();
    AuditLog::record($database, null, 'user.staff_account_created', 'user', $userId, 'success', ['role' => $role], 'notice', 'System CLI');
    $database->commit();

    echo "Account created. Save this generated password now; it will not be shown again.\n";
    printf("Email: %s\nRole: %s\nTemporary password: %s\n", $email, $role, $temporaryPassword);
    echo "Sign in at /staff/login and change the password when prompted.\n";
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, "Staff account creation failed. Check the email is unused and database access is available.\n");
    exit(1);
}