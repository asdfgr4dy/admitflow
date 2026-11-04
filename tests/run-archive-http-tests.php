<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Database.php';

$originalAppEnv = getenv('ADMITFLOW_APP_ENV');
$originalDatabasePort = getenv('ADMITFLOW_DB_PORT');
putenv('ADMITFLOW_APP_ENV=local');
putenv('ADMITFLOW_DB_PORT=3307');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};

$process = null;
$pipes = [];
try {
    $config = Database::loadConfiguration(Database::environment());
    if ($config['host'] !== '127.0.0.1' || (int) $config['port'] !== 3307 || $config['database'] !== 'admitflow') {
        throw new RuntimeException('Archive HTTP tests require the approved local admitflow database on 127.0.0.1:3307.');
    }
    $database = Database::connection();
    $activeRoles = $database->query("SELECT role FROM users WHERE account_status = 'active' GROUP BY role")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['admin', 'reviewer', 'student'] as $requiredRole) {
        if (!in_array($requiredRole, $activeRoles, true)) {
            throw new RuntimeException('An active account is required for each archive HTTP role test.');
        }
    }

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    if ($socket === false) throw new RuntimeException('Could not reserve a local test port: ' . $errorMessage);
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);

    $environment = getenv();
    $environment['ADMITFLOW_APP_ENV'] = 'local';
    $environment['ADMITFLOW_DB_PORT'] = '3307';
    $environment['ADMITFLOW_ARCHIVE_HTTP_TEST'] = '1';
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', __DIR__ . '/fixtures/archive-role-router.php'];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) throw new RuntimeException('Could not start the archive HTTP test server.');
    fclose($pipes[0]);

    $baseUrl = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    if (!$ready) throw new RuntimeException('The archive HTTP test server did not start.');

    $request = static function (string $path, string $method = 'GET') use ($baseUrl): array {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 8]]);
        $body = @file_get_contents($baseUrl . $path, false, $context);
        $headers = $http_response_header ?? [];
        preg_match('/\s(\d{3})\s/', (string) ($headers[0] ?? ''), $matches);
        return ['status' => (int) ($matches[1] ?? 0), 'body' => is_string($body) ? $body : ''];
    };

    $adminPage = $request('/admin/archive?_test_role=admin');
    $assert($adminPage['status'] === 200 && str_contains($adminPage['body'], '<h1>Archive</h1>'), 'administrator can open the archive page');
    $searchedPage = $request('/admin/archive?_test_role=admin&q=archive-test-no-match&status=accepted&per_page=10&page=1');
    $assert($searchedPage['status'] === 200 && str_contains($searchedPage['body'], 'No archived applications match these filters.') && str_contains($searchedPage['body'], 'value="accepted" selected') && str_contains($searchedPage['body'], 'Reset Filters'), 'administrator archive search/status filters retain selection and show reset and empty-state content');
    $reviewerPage = $request('/admin/archive?_test_role=reviewer');
    $assert($reviewerPage['status'] === 403, 'reviewer receives HTTP 403 for direct archive access');
    $studentPage = $request('/admin/archive?_test_role=student');
    $assert($studentPage['status'] === 403, 'student receives HTTP 403 for direct archive access');
    $reviewerArchive = $request('/staff/application/archive?_test_role=reviewer', 'POST');
    $reviewerRestore = $request('/admin/archive/restore?_test_role=reviewer', 'POST');
    $studentArchive = $request('/staff/application/archive?_test_role=student', 'POST');
    $assert($reviewerArchive['status'] === 403 && $reviewerRestore['status'] === 403 && $studentArchive['status'] === 403, 'non-admin archive and restore POSTs return 403 before CSRF checks');
    $adminArchive = $request('/staff/application/archive?_test_role=admin', 'POST');
    $assert($adminArchive['status'] === 419, 'administrator archive action still requires a valid CSRF token');

    fwrite(STDOUT, 'Archive HTTP tests passed: ' . $assertions . PHP_EOL);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ([1, 2] as $pipeIndex) {
            if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) fclose($pipes[$pipeIndex]);
        }
        proc_close($process);
    }
    putenv($originalAppEnv === false ? 'ADMITFLOW_APP_ENV' : 'ADMITFLOW_APP_ENV=' . $originalAppEnv);
    putenv($originalDatabasePort === false ? 'ADMITFLOW_DB_PORT' : 'ADMITFLOW_DB_PORT=' . $originalDatabasePort);
}
