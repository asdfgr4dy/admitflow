<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Pagination.php';
require dirname(__DIR__, 2) . '/app/AuditLog.php';
require dirname(__DIR__, 2) . '/app/StaffService.php';
require dirname(__DIR__, 2) . '/app/ReportExport.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function request_ip(): ?string
{
    return '192.0.2.61';
}

$database = new PDO('sqlite:' . (string) getenv('ADMITFLOW_REPORT_TEST_DB'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$user = ['id' => 1, 'full_name' => 'HTTP Fixture Admin', 'role' => 'admin'];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$applicationId = (int) ($_POST['application_id'] ?? 0);

if ($path === '/archive') {
    StaffService::archiveApplication($database, $user, $applicationId);
    http_response_code(204);
    exit;
}
if ($path === '/restore') {
    StaffService::restoreApplication($database, $user, $applicationId);
    http_response_code(204);
    exit;
}

$report = StaffService::applications($database, $user, $_POST, true);
if ($path === '/staff/reports.csv') {
    $bytes = ReportExport::csv($report);
    StaffService::recordReportExport($database, $user, $_POST, count($report['rows']));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
if ($path === '/staff/reports.pdf') {
    $bytes = ReportExport::pdf($report, StaffService::filters($database, $user));
    StaffService::recordReportExport($database, $user, $_POST, count($report['rows']));
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
http_response_code(404);
