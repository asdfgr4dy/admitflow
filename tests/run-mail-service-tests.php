<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/MailService.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$baseEnvironment = [
    'ADMITFLOW_APP_ENV' => 'local',
    'ADMITFLOW_MAIL_ENABLED' => '1',
    'ADMITFLOW_MAIL_HOST' => 'smtp.example.test',
    'ADMITFLOW_MAIL_PORT' => '587',
    'ADMITFLOW_MAIL_USERNAME' => 'mailer@example.test',
    'ADMITFLOW_MAIL_PASSWORD' => 'mock-secret-value',
    'ADMITFLOW_MAIL_ENCRYPTION' => 'tls',
    'ADMITFLOW_MAIL_FROM_ADDRESS' => 'no-reply@example.test',
    'ADMITFLOW_MAIL_FROM_NAME' => 'AdmitFlow Admissions',
    'ADMITFLOW_MAIL_REPLY_TO' => 'help@example.test',
    'ADMITFLOW_APP_URL' => 'http://127.0.0.1:8000',
];
$configuration = MailService::configuration($baseEnvironment, dirname(__DIR__) . '/config/nonexistent-mail-test.php');
$assert($configuration['errors'] === [] && $configuration['port'] === 587 && $configuration['encryption'] === 'tls', 'STARTTLS configuration validates without connecting to a provider');
$exampleConfiguration = MailService::configuration($baseEnvironment, dirname(__DIR__) . '/config/mail.example.php');
$assert($exampleConfiguration['errors'] === [] && $exampleConfiguration['host'] === 'smtp.example.test' && $exampleConfiguration['password'] === 'mock-secret-value', 'process environment overrides the safe placeholder mail example');
$assert(MailService::configurationStatus([], dirname(__DIR__) . '/config/nonexistent-mail-test.php')['status'] === 'Disabled', 'SMTP disabled status is explicit');
$assert(MailService::configurationStatus(['ADMITFLOW_MAIL_ENABLED' => '1'], dirname(__DIR__) . '/config/nonexistent-mail-test.php')['status'] === 'Needs configuration', 'missing SMTP settings produce an admin-safe incomplete status');
$assert(!MailService::isValidApplicationUrl('http://example.test', false) && MailService::isValidApplicationUrl('https://admitflow.example.test', false) && !MailService::isValidApplicationUrl('https://user:pass@example.test', false), 'production URLs require HTTPS and reject embedded credentials');
$assert(MailService::isValidApplicationUrl('http://localhost:8000', true) && !MailService::isValidApplicationUrl('http://192.0.2.1:8000', true), 'insecure application URLs are restricted to local development hosts');
$sslConfiguration = MailService::configuration(array_replace($baseEnvironment, ['ADMITFLOW_MAIL_PORT' => '465', 'ADMITFLOW_MAIL_ENCRYPTION' => 'ssl']), dirname(__DIR__) . '/config/nonexistent-mail-test.php');
$assert($sslConfiguration['errors'] === [] && $sslConfiguration['port'] === 465 && $sslConfiguration['encryption'] === 'ssl', 'implicit SSL configuration validates on the configured secure port');
$invalidConfiguration = MailService::configuration(array_replace($baseEnvironment, ['ADMITFLOW_MAIL_PORT' => '0', 'ADMITFLOW_MAIL_ENCRYPTION' => 'plain']), dirname(__DIR__) . '/config/nonexistent-mail-test.php');
$assert(count($invalidConfiguration['errors']) >= 2, 'invalid ports and unsupported encryption modes are rejected before transport');

$token = str_repeat('a', 64);
$captured = null;
$result = MailService::sendPasswordReset('person@example.test', 'Casey Example', $token, static function (array $config, array $message) use (&$captured): bool {
    $captured = [$config, $message];
    return true;
}, $configuration);
$assert($result['ok'] && $captured[1]['to'] === 'person@example.test' && str_contains($captured[1]['text'], 'http://127.0.0.1:8000/reset-password?token=' . $token), 'password-reset content is rendered and passed only to the SMTP transport');
$assert(str_contains($captured[1]['html'], 'Choose a new password') && str_contains($captured[1]['text'], '30 minutes') && str_contains($captured[1]['text'], 'If you did not request'), 'reset HTML and plain text include matching action, expiration, and unsolicited-request guidance');

$invitation = MailService::sendStaffInvitation('reviewer@example.test', 'Morgan Reviewer', $token, true, static function (array $config, array $message): bool {
    return $message['to'] === 'reviewer@example.test' && str_contains($message['subject'], 'setup link') && str_contains($message['text'], 'Morgan');
}, $configuration);
$assert($invitation['ok'], 'invitation resend uses the intended recipient and dedicated invitation wording');
$failure = MailService::sendStaffInvitation('reviewer@example.test', 'Morgan Reviewer', $token, false, static fn (): bool => false, $configuration);
$assert(!$failure['ok'] && !str_contains($failure['message'], 'mock-secret-value'), 'transport rejection is reported without exposing SMTP credentials');
$invalidAddress = MailService::sendTestMessage("victim@example.test\r\nBcc: other@example.test", static fn (): bool => true);
$assert(!$invalidAddress['ok'], 'test message rejects recipient header injection');
$testMessage = MailService::sendTestMessage('admin@example.test', static fn (array $config, array $message): bool => $message['to'] === 'admin@example.test' && str_contains($message['text'], 'does not confirm inbox delivery'), $configuration);
$assert($testMessage['ok'], 'administrator SMTP test message reports success only when the mock provider accepts it');
$assert(!str_contains($testMessage['message'], 'mock-secret-value'), 'SMTP test responses never expose configured credentials');
$invalidToken = MailService::sendPasswordReset('person@example.test', 'Casey', 'not-a-token', static fn (): bool => true);
$assert(!$invalidToken['ok'], 'mail delivery rejects malformed reset tokens');

echo "Mail service tests: {$assertions} assertions, 0 failures.\n";