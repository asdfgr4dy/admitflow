<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/Pagination.php';
require dirname(__DIR__) . '/app/StaffService.php';
require dirname(__DIR__) . '/app/ApplicationService.php';
require dirname(__DIR__) . '/app/ReportExport.php';
require dirname(__DIR__) . '/vendor/autoload.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) throw new RuntimeException('Failed: ' . $message);
};
$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-report-http-' . bin2hex(random_bytes(8)) . '.sqlite';
$database = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, full_name TEXT, role TEXT, account_status TEXT)');
$database->exec('CREATE TABLE academic_programs (id INTEGER PRIMARY KEY, name TEXT)');
$database->exec('CREATE TABLE academic_years (id INTEGER PRIMARY KEY, label TEXT, starts_on TEXT, ends_on TEXT, is_open INTEGER, application_deadline TEXT)');
$database->exec('CREATE TABLE applications (id INTEGER PRIMARY KEY, reference_number TEXT, student_id INTEGER, academic_program_id INTEGER, academic_year_id INTEGER, status TEXT, submitted_at TEXT, deleted_at TEXT, updated_at TEXT, applicant_profile_snapshot TEXT)');
$database->exec('CREATE TABLE reviewer_assignments (id INTEGER PRIMARY KEY, application_id INTEGER, reviewer_id INTEGER, unassigned_at TEXT)');
$database->exec('CREATE TABLE application_status_history (id INTEGER PRIMARY KEY, application_id INTEGER, from_status TEXT, to_status TEXT, changed_by INTEGER, created_at TEXT)');
$database->exec('CREATE TABLE admission_requirements (id INTEGER PRIMARY KEY, is_active INTEGER, is_required INTEGER, academic_program_id INTEGER, academic_year_id INTEGER)');
$database->exec('CREATE TABLE application_documents (id INTEGER PRIMARY KEY, application_id INTEGER, requirement_id INTEGER, is_current INTEGER, review_status TEXT)');
$database->exec('CREATE TABLE document_reviews (id INTEGER PRIMARY KEY, document_id INTEGER, reviewer_id INTEGER, decision TEXT, reviewed_at TEXT)');
$database->exec('CREATE TABLE interviews (id INTEGER PRIMARY KEY, application_id INTEGER, status TEXT, starts_at_utc TEXT, updated_at TEXT)');
$database->exec('CREATE TABLE screening_notes (id INTEGER PRIMARY KEY, application_id INTEGER, created_at TEXT)');
$database->exec('CREATE TABLE enrollment_records (id INTEGER PRIMARY KEY, application_id INTEGER, status TEXT)');
$database->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, actor_label TEXT NOT NULL, action TEXT, entity_type TEXT, entity_id INTEGER, outcome TEXT, severity TEXT, ip_address TEXT, request_id TEXT, details TEXT, created_at TEXT NOT NULL)');
$database->exec("INSERT INTO users VALUES (1, 'HTTP Fixture Admin', 'admin', 'active'), (2, 'Active Applicant', 'student', 'active'), (3, 'Archived Applicant', 'student', 'active')");
$database->exec("INSERT INTO academic_programs VALUES (1, 'Fixture Program')");
$database->exec("INSERT INTO academic_years VALUES (1, 'Fixture Year', '2026-01-01', '2027-01-01', 1, '2026-12-01')");
$database->exec("INSERT INTO applications VALUES (1, 'AF-HTTP-ACTIVE', 2, 1, 1, 'submitted', '2026-10-25 00:00:00', NULL, '2026-10-25 00:00:00', '{\"address_line\":\"ACTIVE RESIDENTIAL SECRET\",\"date_of_birth\":\"2000-01-01\"}'), (2, 'AF-HTTP-ARCHIVED', 3, 1, 1, 'submitted', '2026-10-24 00:00:00', NULL, '2026-10-24 00:00:00', '{\"address_line\":\"ARCHIVED RESIDENTIAL SECRET\",\"date_of_birth\":\"1999-02-02\"}')");
$database->exec('INSERT INTO admission_requirements VALUES (1, 1, 1, NULL, NULL)');
$database->exec("INSERT INTO application_status_history VALUES (1, 1, 'draft', 'submitted', 2, '2026-10-25 00:00:00'), (2, 2, 'draft', 'submitted', 3, '2026-10-24 00:00:00')");
$database = null;

$server = null;
$baseUrl = '';
$serverPort = random_int(19000, 29000);
$environment = getenv();
$environment['ADMITFLOW_REPORT_TEST_DB'] = $databasePath;
$command = [PHP_BINARY, '-S', '127.0.0.1:' . $serverPort, '-t', dirname(__DIR__) . '/tests/fixtures', dirname(__DIR__) . '/tests/fixtures/report-export-router.php'];
$serverLog = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'admitflow-report-http-' . bin2hex(random_bytes(6)) . '.log';
$descriptors = [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']];
try {
    $server = proc_open($command, $descriptors, $pipes, dirname(__DIR__), $environment);
    if (!is_resource($server)) throw new RuntimeException('Could not start the isolated report HTTP fixture.');
    fclose($pipes[0]);
    $baseUrl = 'http://127.0.0.1:' . $serverPort;
    $connection = false;
    for ($attempt = 0; $attempt < 20 && !is_resource($connection); $attempt++) {
        $connection = @stream_socket_client('tcp://127.0.0.1:' . $serverPort, $errorCode, $errorMessage, 0.25);
    }
    $serverStarted = is_resource($connection);
    if (is_resource($connection)) fclose($connection);
    if (!$serverStarted) throw new RuntimeException('The isolated report HTTP fixture did not start: ' . (string) @file_get_contents($serverLog));

    $post = static function (string $path, array $fields = []) use ($baseUrl): array {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
            'content' => http_build_query($fields),
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $body = file_get_contents($baseUrl . $path, false, $context);
        $headers = $http_response_header ?? [];
        return ['body' => is_string($body) ? $body : '', 'headers' => $headers];
    };
    $fetchDatabase = static function () use ($databasePath): PDO {
        return new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    };
    $status = static function (array $response): int {
        foreach ($response['headers'] as $header) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) return (int) $match[1];
        return 0;
    };
    $pdfPageSize = static function (string $bytes): array {
        $match = [];
        preg_match('/MediaBox\s+\[0(?:\.000)?\s+0(?:\.000)?\s+([\d.]+)\s+([\d.]+)\]/', $bytes, $match);
        return [(float) ($match[1] ?? 0), (float) ($match[2] ?? 0)];
    };

    $database = $fetchDatabase();
    StaffService::archiveApplication($database, ['id' => 1, 'full_name' => 'HTTP Fixture Admin', 'role' => 'admin'], 2);
    $database = null;
    $csv = $post('/staff/reports.csv');
    $pdf = $post('/staff/reports.pdf');
    $assert($status($csv) === 200 && str_contains(implode("\n", $csv['headers']), 'text/csv') && str_contains($csv['body'], 'AF-HTTP-ACTIVE') && !str_contains($csv['body'], 'AF-HTTP-ARCHIVED'), 'HTTP CSV uses the report export route and excludes archived rows while retaining active rows');
    $assert($status($pdf) === 200 && str_starts_with($pdf['body'], '%PDF-') && str_contains(implode("\n", $pdf['headers']), 'application/pdf'), 'HTTP PDF response: ' . json_encode(['status' => $status($pdf), 'body' => substr($pdf['body'], 0, 500), 'headers' => $pdf['headers'] ]));
    $activeReport = StaffService::applications($fetchDatabase(), ['id' => 1, 'role' => 'admin'], [], true);
    $pdfBytes = ReportExport::pdf($activeReport, StaffService::filters($fetchDatabase(), ['id' => 1, 'role' => 'admin']));
    $assert(!str_contains($csv['body'], 'RESIDENTIAL SECRET') && !str_contains($pdfBytes, 'RESIDENTIAL SECRET') && !str_contains($csv['body'], '2000-01-01') && !str_contains($pdfBytes, '2000-01-01'), 'CSV and PDF outputs exclude residential addresses and dates of birth');
    $auditCounts = $fetchDatabase()->query("SELECT action, COUNT(*) AS total FROM audit_logs WHERE action='report.exported' GROUP BY action")->fetchAll();
    $assert((int) ($auditCounts[0]['total'] ?? 0) === 2, 'CSV and PDF HTTP exports each write exactly one report.exported audit row');

    $portraitPdf = $post('/staff/reports.pdf', ['orientation' => 'portrait']);
    $landscapePdf = $post('/staff/reports.pdf', ['orientation' => 'landscape']);
    $invalidOrientationPdf = $post('/staff/reports.pdf', ['orientation' => 'sideways']);
    $portraitSize = $pdfPageSize($portraitPdf['body']);
    $landscapeSize = $pdfPageSize($landscapePdf['body']);
    $invalidSize = $pdfPageSize($invalidOrientationPdf['body']);
    $assert($status($portraitPdf) === 200 && $portraitSize[1] > $portraitSize[0] && abs($portraitSize[0] - 595.28) < 1 && abs($portraitSize[1] - 841.89) < 1, 'HTTP PDF export orientation=portrait produces an A4 portrait MediaBox');
    $assert($status($landscapePdf) === 200 && $landscapeSize[0] > $landscapeSize[1] && abs($landscapeSize[0] - 841.89) < 1 && abs($landscapeSize[1] - 595.28) < 1, 'HTTP PDF export orientation=landscape produces an A4 landscape MediaBox');
    $assert($status($invalidOrientationPdf) === 200 && $invalidSize[0] > $invalidSize[1] && abs($invalidSize[0] - 841.89) < 1 && abs($invalidSize[1] - 595.28) < 1, 'invalid HTTP PDF orientation safely falls back to landscape');
    $orientationAuditCount = (int) $fetchDatabase()->query("SELECT COUNT(*) FROM audit_logs WHERE action='report.exported'")->fetchColumn();
    $assert($orientationAuditCount === 5, 'each portrait, landscape, and invalid-orientation PDF export records one report.exported audit row');

    $restored = $post('/restore', ['application_id' => 2]);
    $restoredCsv = $post('/staff/reports.csv');
    $restoredPdf = $post('/staff/reports.pdf');
    $pdfTemplate = (string) file_get_contents(dirname(__DIR__) . '/resources/views/staff/reports-pdf.php');
    $reportServiceSource = (string) file_get_contents(dirname(__DIR__) . '/app/StaffService.php');
    $assert($status($restored) === 204 && str_contains($restoredCsv['body'], 'AF-HTTP-ARCHIVED') && str_contains($restoredPdf['body'], '%PDF-') && $restoredPdf['body'] !== $pdf['body'], 'restoring the application returns it to CSV and changes the rendered PDF response');
    $assert(!str_contains($csv['body'], 'RESIDENTIAL SECRET') && !str_contains($csv['body'], '2000-01-01') && !str_contains($csv['body'], '1999-02-02') && !str_contains($pdfTemplate, 'address_line') && !str_contains($pdfTemplate, 'date_of_birth') && !str_contains($reportServiceSource, 'a.address_line') && !str_contains($reportServiceSource, 'a.date_of_birth'), 'HTTP export data projection and PDF template omit residential address and date-of-birth fields');
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if (is_file($databasePath)) unlink($databasePath);
    if (is_file($serverLog)) unlink($serverLog);
}

fwrite(STDOUT, 'Report export HTTP tests passed: ' . $assertions . PHP_EOL);
fwrite(STDOUT, 'PDF MediaBoxes (points): ' . json_encode(['portrait' => $portraitSize, 'landscape' => $landscapeSize, 'invalid_fallback' => $invalidSize]) . PHP_EOL);
