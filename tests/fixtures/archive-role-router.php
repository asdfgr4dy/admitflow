<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Database.php';

if (getenv('ADMITFLOW_ARCHIVE_HTTP_TEST') !== '1') {
    http_response_code(404);
    exit('Archive HTTP test fixture is disabled.');
}

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$requestHost = parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
if ($remoteAddress !== '127.0.0.1' || $requestHost !== '127.0.0.1') {
    http_response_code(404);
    exit('Archive HTTP test fixture accepts loopback requests only.');
}

$role = (string) ($_GET['_test_role'] ?? '');
if (!in_array($role, ['admin', 'reviewer', 'student'], true)) {
    http_response_code(400);
    exit('Invalid test role.');
}

$database = Database::connection();
$statement = $database->prepare('SELECT id, full_name, email, role, must_change_password FROM users WHERE role = :role AND account_status = \'active\' ORDER BY id LIMIT 1');
$statement->execute(['role' => $role]);
$user = $statement->fetch();
if (!is_array($user)) {
    http_response_code(500);
    exit('No active test account exists for the requested role.');
}

session_start();
$_SESSION['user'] = [
    'id' => (int) $user['id'],
    'full_name' => $user['full_name'],
    'email' => $user['email'],
    'role' => $user['role'],
    'must_change_password' => (int) $user['must_change_password'],
];
$_SESSION['last_activity'] = time();
session_write_close();

require dirname(__DIR__, 2) . '/public/router.php';
