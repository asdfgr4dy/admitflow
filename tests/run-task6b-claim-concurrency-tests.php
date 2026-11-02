<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/AuditLog.php';
require dirname(__DIR__) . '/app/StaffService.php';

function request_ip(): ?string
{
    return '192.0.2.91';
}

if (($argv[1] ?? '') === '--worker') {
    $databasePath = (string) ($argv[2] ?? '');
    $reviewerId = filter_var($argv[3] ?? null, FILTER_VALIDATE_INT);
    $readyPath = (string) ($argv[4] ?? '');
    $startPath = (string) ($argv[5] ?? '');
    if ($databasePath === '' || $reviewerId === false || $readyPath === '' || $startPath === '') exit(2);
    $database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    file_put_contents($readyPath . '.' . $reviewerId, 'ready');
    $deadline = microtime(true) + 10;
    while (!is_file($startPath) && microtime(true) < $deadline) usleep(1000);
    if (!is_file($startPath)) exit(3);
    try {
        $result = StaffService::claimApplication($database, ['id' => (int) $reviewerId, 'role' => 'reviewer', 'full_name' => 'Reviewer ' . $reviewerId], 1);
        echo $result['ok'] ? 'success' : 'conflict';
    } catch (Throwable $error) {
        fwrite(STDERR, $error::class . ': ' . $error->getMessage());
        exit(1);
    }
    exit(0);
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-claim-race-' . bin2hex(random_bytes(6));
if (!mkdir($root, 0700)) throw new RuntimeException('Could not create isolated claim-race directory.');
$databasePath = $root . DIRECTORY_SEPARATOR . 'claims.sqlite';
$readyPath = $root . DIRECTORY_SEPARATOR . 'ready';
$startPath = $root . DIRECTORY_SEPARATOR . 'start';
$database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, role TEXT NOT NULL, account_status TEXT NOT NULL)');
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, reference_number TEXT, status TEXT NOT NULL, deleted_at TEXT NULL)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, application_id INTEGER NOT NULL, reviewer_id INTEGER NOT NULL, assigned_by INTEGER NOT NULL, assigned_at TEXT DEFAULT CURRENT_TIMESTAMP, unassigned_at TEXT NULL)');
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT NOT NULL, action TEXT NOT NULL, entity_type TEXT, entity_id INTEGER, outcome TEXT NOT NULL, severity TEXT, ip_address TEXT, request_id TEXT, details TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
$database->exec("INSERT INTO users VALUES (2,'Reviewer A','reviewer','active'),(3,'Reviewer B','reviewer','active'),(9,'Race Applicant','student','active')");
$database->exec("INSERT INTO applications VALUES (1,'AF-CLAIM-RACE','submitted',NULL)");
$database = null;

$processes = [];
foreach ([2, 3] as $reviewerId) {
    $pipes = [];
    $command = [PHP_BINARY, __FILE__, '--worker', $databasePath, (string) $reviewerId, $readyPath, $startPath];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), getenv() ?: []);
    if (!is_resource($process)) throw new RuntimeException('Could not start isolated claim worker.');
    fclose($pipes[0]);
    $processes[] = [$process, $pipes[1], $pipes[2], $reviewerId];
}
$readyDeadline = microtime(true) + 10;
while ((!is_file($readyPath . '.2') || !is_file($readyPath . '.3')) && microtime(true) < $readyDeadline) usleep(1000);
$assert(is_file($readyPath . '.2') && is_file($readyPath . '.3'), 'both isolated workers reach the same start barrier');
file_put_contents($startPath, 'go');
$outcomes = [];
foreach ($processes as [$process, $stdout, $stderr, $reviewerId]) {
    $outcome = trim((string) stream_get_contents($stdout));
    $error = trim((string) stream_get_contents($stderr));
    fclose($stdout);
    fclose($stderr);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) throw new RuntimeException('Claim worker ' . $reviewerId . ' failed: ' . $error);
    $outcomes[] = $outcome;
}
$database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$activeCount = (int) $database->query('SELECT COUNT(*) FROM reviewer_assignments WHERE application_id=1 AND unassigned_at IS NULL')->fetchColumn();
$successAuditCount = (int) $database->query("SELECT COUNT(*) FROM audit_logs WHERE action='application.claimed' AND outcome='success'")->fetchColumn();
$assert(count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'success')) === 1 && count(array_filter($outcomes, static fn (string $outcome): bool => $outcome === 'conflict')) === 1, 'exactly one concurrent claimant succeeds and one receives conflict');
$assert($activeCount === 1 && $successAuditCount === 1, 'race leaves one active assignment and one successful claim audit');

$database = null;
foreach (glob($root . DIRECTORY_SEPARATOR . '*') ?: [] as $path) if (is_file($path)) unlink($path);
rmdir($root);
echo 'Task 6B isolated SQLite claim concurrency: ' . $assertions . " assertions, 0 failures.\n";
