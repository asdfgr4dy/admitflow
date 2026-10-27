<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SecurityPolicy.php';

date_default_timezone_set('Asia/Manila');

$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('ADMITFLOW_FORCE_SECURE_COOKIES') === '1';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['_csrf'] ?? '';
    if (!SecurityPolicy::csrfMatches(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('Your form session expired. Go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function portal_path_for_user(?array $user): string
{
    if ($user === null) {
        return '/';
    }

    return match ($user['role'] ?? null) {
        'student' => '/portal',
        'reviewer' => '/staff',
        'admin' => '/admin',
        default => '/login',
    };
}

function require_student(): array
{
    $user = current_user();
    if (!SecurityPolicy::hasRole($user, ['student'])) {
        redirect('/login');
    }

    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($message) ? $message : null;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function render_page(string $view, array $data = []): void
{
    if (class_exists('NotificationService') && ($user = current_user()) !== null) {
        $dismissed = is_array($_SESSION['dismissed_derived_notifications'] ?? null) ? $_SESSION['dismissed_derived_notifications'] : [];
        $feed = NotificationService::feed(Database::connection(), $user, $dismissed, 100);
        $activeDerivedKeys = $feed['derived_keys'] ?? [];
        $_SESSION['dismissed_derived_notifications'] = array_values(array_intersect($dismissed, $activeDerivedKeys));
        $data['notificationFeed'] = NotificationService::feed(Database::connection(), $user, $_SESSION['dismissed_derived_notifications']);
    }
    extract($data, EXTR_SKIP);
    $contentView = dirname(__DIR__) . '/resources/views/' . $view . '.php';
    if (!is_file($contentView)) {
        http_response_code(500);
        exit('Page template is unavailable.');
    }

    require dirname(__DIR__) . '/resources/views/layout.php';
}

function request_ip(): ?string
{
    $address = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($address) && filter_var($address, FILTER_VALIDATE_IP) ? $address : null;
}

function refresh_authenticated_user(PDO $database): ?array
{
    $sessionUser = current_user();
    if ($sessionUser === null) {
        return null;
    }

    $statement = $database->prepare('SELECT id, full_name, email, role FROM users WHERE id = :id AND account_status = \'active\' LIMIT 1');
    $statement->execute(['id' => $sessionUser['id']]);
    $user = $statement->fetch();
    if (!is_array($user)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];

    return $_SESSION['user'];
}