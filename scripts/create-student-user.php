<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/Database.php';

$name = trim($argv[1] ?? '');
$email = mb_strtolower(trim($argv[2] ?? ''));

if ($name === '' || mb_strlen($name) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/create-student-user.php \"Full name\" email@example.test\n");
    exit(2);
}

try {
    $database = Database::connection();
    $temporaryPassword = rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'AZ'), '=');
    $database->beginTransaction();

    $insert = $database->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (:name, :email, :password_hash, \'student\', \'active\')');
    $insert->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
    ]);
    $userId = (int) $database->lastInsertId();

    $profile = $database->prepare('INSERT INTO student_profiles (user_id) VALUES (:user_id)');
    $profile->execute(['user_id' => $userId]);

    $audit = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, severity) VALUES (:user_id, \'System CLI\', \'account.student_created\', \'user\', :entity_id, \'success\', \'notice\')');
    $audit->execute(['user_id' => $userId, 'entity_id' => $userId]);
    $database->commit();

    echo "Student account created. Save this generated password now; it will not be shown again.\n";
    printf("Email: %s\nRole: student\nTemporary password: %s\n", $email, $temporaryPassword);
    echo "Sign in at /login.\n";
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) {
        $database->rollBack();
    }
    fwrite(STDERR, "Student account creation failed. Check whether the email is already in use and database access is available.\n");
    exit(1);
}
