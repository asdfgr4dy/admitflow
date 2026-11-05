<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/DemoAccountCredentials.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$target = ['host' => 'db.demo.example.test', 'port' => 3306, 'database' => 'admitflow_school_demo'];
$assert(!DemoAccountCredentials::isEnabled(['ADMITFLOW_APP_ENV' => 'production'], $target, $target['database']), 'demo mode is disabled by default outside local mode');
$assert(!DemoAccountCredentials::isEnabled(['ADMITFLOW_APP_ENV' => 'production', 'ADMITFLOW_DEMO_MODE' => 'off'], $target, $target['database']), 'explicit off always disables demo credentials');
$assert(DemoAccountCredentials::isEnabled(['ADMITFLOW_APP_ENV' => 'local'], $target, $target['database']), 'existing local demo mode remains enabled when local credentials exist');
$assert(DemoAccountCredentials::isEnabled(['ADMITFLOW_APP_ENV' => 'local', 'ADMITFLOW_DEMO_MODE' => 'local'], $target, $target['database']), 'local mode can be explicitly enabled');
$assert(!DemoAccountCredentials::isEnabled(['ADMITFLOW_APP_ENV' => 'production', 'ADMITFLOW_DEMO_MODE' => 'local'], $target, $target['database']), 'local demo mode cannot be enabled in a hosted environment');

$hostedEnvironment = [
    'ADMITFLOW_APP_ENV' => 'production',
    'ADMITFLOW_DEMO_MODE' => 'hosted',
    'ADMITFLOW_DEMO_EXPECTED_DB_HOST' => $target['host'],
    'ADMITFLOW_DEMO_EXPECTED_DB_NAME' => $target['database'],
];
$assert(DemoAccountCredentials::isEnabled($hostedEnvironment, $target, $target['database']), 'hosted mode enables only with explicit matching host and database settings');
$assert(!DemoAccountCredentials::isEnabled(array_diff_key($hostedEnvironment, ['ADMITFLOW_DEMO_EXPECTED_DB_HOST' => true]), $target, $target['database']), 'hosted mode fails closed when expected host is missing');
$assert(!DemoAccountCredentials::isEnabled(array_merge($hostedEnvironment, ['ADMITFLOW_DEMO_EXPECTED_DB_HOST' => 'db.production.example.test']), $target, $target['database']), 'hosted mode refuses an unexpected database host');
$assert(!DemoAccountCredentials::isEnabled(array_merge($hostedEnvironment, ['ADMITFLOW_DEMO_EXPECTED_DB_NAME' => 'admitflow']), $target, $target['database']), 'hosted mode refuses an unexpected configured schema');
$assert(!DemoAccountCredentials::isEnabled($hostedEnvironment, $target, 'admitflow_production'), 'hosted mode verifies the actual connected schema name');
$assert(!DemoAccountCredentials::isEnabled($hostedEnvironment, array_merge($target, ['database' => 'other_demo']), $target['database']), 'hosted mode refuses mismatch between expected and effective database configuration');

$identities = DemoAccountCredentials::IDENTITIES;
$assert(count($identities) === 3, 'exactly three existing demo identities are configured');
$assert(array_column($identities, 'email') === ['demo.admin@example.test', 'demo.reviewer@example.test', 'demo.student@example.test'], 'demo cards retain the existing usernames matched by the configured passwords');
$expectedRoles = ['admin' => 'admin', 'reviewer' => 'reviewer', 'student' => 'student'];
foreach ($expectedRoles as $key => $role) {
    $assert(($identities[$key]['role'] ?? null) === $role, $key . ' identity retains its existing role');
}

$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, role TEXT NOT NULL, password_hash TEXT NOT NULL)');
$passwords = [];
foreach ($identities as $key => $identity) $passwords[$key] = 'fixture-' . bin2hex(random_bytes(12));
$credentialPath = tempnam(sys_get_temp_dir(), 'admitflow-demo-credentials-');
if ($credentialPath === false) throw new RuntimeException('Could not create temporary credential fixture.');
file_put_contents($credentialPath, "<?php\nreturn " . var_export($passwords, true) . ";\n", LOCK_EX);
$insert = $database->prepare('INSERT INTO users (email, role, password_hash) VALUES (:email, :role, :password_hash)');
foreach ($identities as $key => $identity) {
    $insert->execute(['email' => $identity['email'], 'role' => $identity['role'], 'password_hash' => password_hash($passwords[$key], PASSWORD_DEFAULT)]);
}
try {
    $disabledAccounts = DemoAccountCredentials::forDatabase($database, 'admitflow_school_demo', ['ADMITFLOW_APP_ENV' => 'production'], $target, $credentialPath);
    $assert($disabledAccounts === [], 'credential file present in deployment does not expose buttons while hosted mode is disabled');

    $localAccounts = DemoAccountCredentials::forDatabase($database, 'admitflow_school_demo', ['ADMITFLOW_APP_ENV' => 'local'], $target, $credentialPath);
    $assert(count($localAccounts) === 3, 'local mode returns the three existing accounts when configured roles and hashes match');
    foreach ($identities as $key => $identity) {
        $assert(($localAccounts[$key]['email'] ?? null) === $identity['email'] && ($localAccounts[$key]['role'] ?? null) === $identity['role'] && ($localAccounts[$key]['password'] ?? null) === $passwords[$key], $key . ' local account preserves its existing login identity');
    }

    $hostedAccounts = DemoAccountCredentials::forDatabase($database, $target['database'], $hostedEnvironment, $target, $credentialPath);
    $assert(count($hostedAccounts) === 3, 'explicit hosted mode returns the same existing accounts for the matching demo database');
    $wrongDatabaseAccounts = DemoAccountCredentials::forDatabase($database, 'admitflow_production', $hostedEnvironment, $target, $credentialPath);
    $assert($wrongDatabaseAccounts === [], 'hosted login credentials are hidden for the production database');

    $database->exec("UPDATE users SET role='student' WHERE email='demo.admin@example.test'");
    $assert(DemoAccountCredentials::forDatabase($database, $target['database'], $hostedEnvironment, $target, $credentialPath) === [], 'credential cards stay hidden if any existing demo role differs');
    $database->exec("UPDATE users SET role='admin' WHERE email='demo.admin@example.test'");
    $database->exec("UPDATE users SET password_hash='not-a-valid-match' WHERE email='demo.reviewer@example.test'");
    $assert(DemoAccountCredentials::forDatabase($database, $target['database'], $hostedEnvironment, $target, $credentialPath) === [], 'credential cards stay hidden if stored password hashes no longer match the existing credentials');
} finally {
    if (is_file($credentialPath)) unlink($credentialPath);
}

$root = dirname(__DIR__);
$provisioner = (string) file_get_contents($root . '/scripts/create-demo-accounts.php');
$frontController = (string) file_get_contents($root . '/public/index.php');
$loginView = (string) file_get_contents($root . '/resources/views/auth/login.php');
$loginScript = (string) file_get_contents($root . '/public/assets/js/app.js');
$readme = (string) file_get_contents($root . '/README.md');
$gitignore = (string) file_get_contents($root . '/.gitignore');
$assert(str_contains($provisioner, '--verify-existing') && !str_contains($provisioner, 'INSERT INTO users') && !str_contains($provisioner, 'beginTransaction') && !str_contains($provisioner, 'SecurityPolicy::generatePassword'), 'legacy script now verifies only and cannot generate accounts or modify records');
$assert(str_contains($frontController, 'DemoAccountCredentials::forLogin($database)') && substr_count($frontController, 'DemoAccountCredentials::forLogin($database)') === 2, 'existing login form receives credentials only from the environment-gated verifier');
$assert(str_contains($loginView, 'Demo Accounts') && str_contains($loginView, 'aria-label="Demo accounts"') && !str_contains($loginView, 'Local demo only') && str_contains($loginView, 'Try a demo account') && str_contains($loginView, 'data-demo-email') && str_contains($loginView, 'data-demo-password') && str_contains($loginScript, 'passwordInput.value = demoPassword') && str_contains($loginScript, 'emailInput.value = demoEmail'), 'demo account label is environment-neutral and cards/autofill hooks remain intact');
$demoHandler = substr($loginScript, 0, (int) strpos($loginScript, 'const tableDropdownSelector'));
$assert(!str_contains($demoHandler, 'captcha') && !str_contains($demoHandler, 'submit('), 'demo autofill leaves CAPTCHA and form submission behavior unchanged');
$assert(str_contains($readme, 'Hosted Demo Accounts') && str_contains($readme, 'demo.admin@example.test') && str_contains($readme, 'demo.reviewer@example.test') && str_contains($readme, 'demo.student@example.test') && str_contains($readme, 'separate preserved accounts') && str_contains($readme, 'ADMITFLOW_DEMO_MODE=hosted') && str_contains($readme, 'ADMITFLOW_DEMO_EXPECTED_DB_HOST') && str_contains($readme, 'ADMITFLOW_DEMO_EXPECTED_DB_NAME') && str_contains($readme, '--verify-existing'), 'README identifies the existing card accounts and documents isolated hosted verification without embedding credentials');
$assert(str_contains($gitignore, '/config/demo-accounts.local.php'), 'private existing demo passwords stay excluded from source control');

fwrite(STDOUT, 'Demo login and hosted configuration tests passed: ' . $assertions . " assertions, 0 failures.\n");
