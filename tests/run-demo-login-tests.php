<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/DemoAccountCredentials.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$expected = [
    'admin' => ['email' => 'demo.admin@example.test', 'role' => 'admin'],
    'reviewer' => ['email' => 'demo.reviewer@example.test', 'role' => 'reviewer'],
    'student' => ['email' => 'demo.student@example.test', 'role' => 'student'],
];
$identities = DemoAccountCredentials::IDENTITIES;
$assert(count($identities) === 3, 'exactly three demo identities are configured');
foreach ($expected as $key => $account) {
    $assert(($identities[$key]['email'] ?? null) === $account['email'] && ($identities[$key]['role'] ?? null) === $account['role'], $key . ' identity has the expected fictional username and role');
}

$root = dirname(__DIR__);
$provisioner = (string) file_get_contents($root . '/scripts/create-demo-accounts.php');
$frontController = (string) file_get_contents($root . '/public/index.php');
$loginView = (string) file_get_contents($root . '/resources/views/auth/login.php');
$loginScript = (string) file_get_contents($root . '/public/assets/js/app.js');
$ignore = (string) file_get_contents($root . '/.gitignore');
$configProtection = (string) file_get_contents($root . '/config/.htaccess');
$router = (string) file_get_contents($root . '/public/router.php');
$assert(str_contains($provisioner, "PHP_SAPI !== 'cli'") && str_contains($provisioner, "'127.0.0.1'") && str_contains($provisioner, '3307') && str_contains($provisioner, "'admitflow'"), 'provisioner is CLI-only and guarded to the approved database target');
$assert(str_contains($provisioner, 'SELECT COUNT(*) FROM users WHERE email IN') && str_contains($provisioner, 'At least one proposed demo username already exists') && str_contains($provisioner, 'beginTransaction') && str_contains($provisioner, 'commit()'), 'provisioner checks all usernames and creates accounts transactionally without updating existing users');
$assert(str_contains($provisioner, 'password_hash(') && str_contains($provisioner, 'SecurityPolicy::generatePassword') && str_contains($provisioner, "INSERT INTO student_profiles (user_id)"), 'provisioner creates distinct hashed passwords and the required student profile');
$assert(str_contains($provisioner, 'DemoAccountCredentials::localPath()') && str_contains($provisioner, 'chmod($temporaryConfig, 0600)') && str_contains($ignore, '/config/demo-accounts.local.php'), 'generated credentials are stored in a private Git-ignored local PHP config');
$assert(str_contains($configProtection, 'Require all denied') && str_contains($router, 'str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR)'), 'Apache denies config access and built-in routing serves static files only from public');
$assert(str_contains($frontController, 'DemoAccountCredentials::forLogin()') && substr_count($frontController, "'demoLoginIdentities' => DemoAccountCredentials::forLogin()") === 2, 'login page receives credentials on initial and failed-login rendering');
$assert(str_contains($loginView, 'Local demo only') && str_contains($loginView, '>DEMO</span>') && str_contains($loginView, '<details class="demo-account-details">') && str_contains($loginView, 'Try a demo account'), 'login view uses a collapsed native disclosure with a local-only divider and demo tag');
$assert(str_contains($loginView, "'name' => 'Administrator'") && str_contains($loginView, "'name' => 'Admissions reviewer'") && str_contains($loginView, "'name' => 'Student applicant'") && substr_count($loginView, 'class="demo-account-row"') === 1 && str_contains($loginView, 'demo-account-avatar') && str_contains($loginView, 'demo-account-use'), 'demo disclosure defines compact role rows with avatars, descriptions, and Use controls');
$assert(str_contains($loginView, 'type="button"') && str_contains($loginView, 'data-demo-email') && str_contains($loginView, 'data-demo-password') && str_contains($loginView, 'aria-label="Use <?= e($demoRole[\'name\']) ?> demo account"'), 'Use buttons preserve escaped credential hooks and keyboard-accessible labels');
$demoStyles = (string) file_get_contents($root . '/public/assets/css/app.css');
$assert(str_contains($demoStyles, '.demo-account-row { display: grid;') && str_contains($demoStyles, '.demo-account-use { min-width: 48px;') && !str_contains($loginView, 'style='), 'demo rows use scoped responsive CSS without inline style attributes');
$demoHandler = substr($loginScript, 0, (int) strpos($loginScript, 'const tableDropdownSelector'));
$assert(str_contains($demoHandler, "emailInput.value = demoEmail") && str_contains($demoHandler, 'passwordInput.value = demoPassword'), 'demo click fills both username and password');
$assert(!str_contains($demoHandler, 'captcha') && !str_contains($demoHandler, 'submit(') && !str_contains($demoHandler, '.submit'), 'demo click leaves CAPTCHA untouched and does not submit the form');

fwrite(STDOUT, 'Demo login tests passed: ' . $assertions . " assertions, 0 failures.\n");
