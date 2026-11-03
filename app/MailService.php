<?php
declare(strict_types=1);

final class MailService
{
    private const ENVIRONMENT_KEYS = [
        'enabled' => 'ADMITFLOW_MAIL_ENABLED',
        'host' => 'ADMITFLOW_MAIL_HOST',
        'port' => 'ADMITFLOW_MAIL_PORT',
        'username' => 'ADMITFLOW_MAIL_USERNAME',
        'password' => 'ADMITFLOW_MAIL_PASSWORD',
        'encryption' => 'ADMITFLOW_MAIL_ENCRYPTION',
        'from_address' => 'ADMITFLOW_MAIL_FROM_ADDRESS',
        'from_name' => 'ADMITFLOW_MAIL_FROM_NAME',
        'reply_to' => 'ADMITFLOW_MAIL_REPLY_TO',
        'app_url' => 'ADMITFLOW_APP_URL',
    ];

    public static function configuration(?array $environment = null, ?string $configPath = null): array
    {
        $environment ??= self::environment();
        $configPath ??= dirname(__DIR__) . '/config/mail.local.php';
        try {
            $configuration = is_file($configPath) ? require $configPath : [];
        } catch (Throwable) {
            return ['enabled' => false, 'errors' => ['The private mail configuration file could not be loaded.']];
        }
        if (!is_array($configuration)) {
            return ['enabled' => false, 'errors' => ['The private mail configuration file must return an array.']];
        }
        foreach (self::ENVIRONMENT_KEYS as $key => $environmentKey) {
            if (array_key_exists($environmentKey, $environment)) {
                $configuration[$key] = $environment[$environmentKey];
            }
        }

        $enabledValue = $configuration['enabled'] ?? '0';
        $enabled = in_array($enabledValue, [true, 1, '1'], true);
        $errors = [];
        if (!in_array($enabledValue, [true, false, 1, 0, '1', '0'], true)) {
            $errors[] = 'ADMITFLOW_MAIL_ENABLED must be 1 or 0.';
        }
        $configuration['enabled'] = $enabled;
        $configuration['host'] = trim((string) ($configuration['host'] ?? ''));
        $configuration['port'] = filter_var($configuration['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) ?: 0;
        $configuration['username'] = trim((string) ($configuration['username'] ?? ''));
        $configuration['password'] = (string) ($configuration['password'] ?? '');
        $configuration['encryption'] = strtolower(trim((string) ($configuration['encryption'] ?? '')));
        $configuration['from_address'] = trim((string) ($configuration['from_address'] ?? ''));
        $configuration['from_name'] = trim(str_replace(["\r", "\n"], '', (string) ($configuration['from_name'] ?? '')));
        $configuration['reply_to'] = trim((string) ($configuration['reply_to'] ?? ''));
        $configuration['app_url'] = rtrim(trim((string) ($configuration['app_url'] ?? '')), '/');
        $configuration['app_environment'] = (string) ($environment['ADMITFLOW_APP_ENV'] ?? getenv('ADMITFLOW_APP_ENV') ?: '');
        if ($configuration['app_url'] === '' && $configuration['app_environment'] === 'local') $configuration['app_url'] = 'http://127.0.0.1:8000';

        if ($enabled) {
            if (!self::validHost($configuration['host'])) $errors[] = 'Set a valid ADMITFLOW_MAIL_HOST.';
            if ($configuration['port'] === 0) $errors[] = 'Set a valid ADMITFLOW_MAIL_PORT between 1 and 65535.';
            if ($configuration['username'] === '' || preg_match('/[\r\n\x00]/', $configuration['username'])) $errors[] = 'Set a valid ADMITFLOW_MAIL_USERNAME.';
            if ($configuration['password'] === '' || preg_match('/[\r\n\x00]/', $configuration['password'])) $errors[] = 'Set ADMITFLOW_MAIL_PASSWORD.';
            if (!in_array($configuration['encryption'], ['tls', 'ssl'], true)) $errors[] = 'Set ADMITFLOW_MAIL_ENCRYPTION to tls or ssl.';
            if (filter_var($configuration['from_address'], FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $configuration['from_address'])) $errors[] = 'Set a valid ADMITFLOW_MAIL_FROM_ADDRESS.';
            if ($configuration['from_name'] === '') $errors[] = 'Set ADMITFLOW_MAIL_FROM_NAME.';
            if ($configuration['reply_to'] !== '' && filter_var($configuration['reply_to'], FILTER_VALIDATE_EMAIL) === false) $errors[] = 'Set ADMITFLOW_MAIL_REPLY_TO to a valid email address or leave it empty.';
            if (!self::isValidApplicationUrl($configuration['app_url'], $configuration['app_environment'] === 'local')) $errors[] = 'Set ADMITFLOW_APP_URL to an absolute HTTPS URL; local HTTP is allowed only on localhost.';
        }

        $configuration['errors'] = $errors;
        return $configuration;
    }

    public static function configurationStatus(?array $environment = null, ?string $configPath = null): array
    {
        $configuration = self::configuration($environment, $configPath);
        if (!$configuration['enabled'] && $configuration['errors'] === []) {
            return ['status' => 'Disabled', 'configured' => false, 'message' => 'SMTP mail is disabled. Set ADMITFLOW_MAIL_ENABLED=1 and provide the required SMTP settings to send email.'];
        }
        if ($configuration['errors'] !== []) {
            return ['status' => 'Needs configuration', 'configured' => false, 'message' => implode(' ', $configuration['errors'])];
        }
        return ['status' => 'Configured', 'configured' => true, 'message' => 'SMTP settings are present. A real provider acceptance has not been tested.'];
    }

    public static function sendPasswordReset(string $email, string $fullName, string $token, ?callable $transport = null, ?array $configuration = null): array
    {
        return self::sendTokenMessage('password-reset', $email, $fullName, $token, false, $transport, $configuration);
    }

    public static function sendStaffInvitation(string $email, string $fullName, string $token, bool $resend = false, ?callable $transport = null, ?array $configuration = null): array
    {
        return self::sendTokenMessage('staff-invitation', $email, $fullName, $token, $resend, $transport, $configuration);
    }

    public static function sendTestMessage(string $email, ?callable $transport = null, ?array $configuration = null): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email)) {
            return ['ok' => false, 'message' => 'Enter a valid recipient email address.'];
        }
        $configuration ??= self::configuration();
        if (!$configuration['enabled'] || $configuration['errors'] !== []) {
            return ['ok' => false, 'message' => self::configurationStatus()['message']];
        }
        $template = self::renderTemplate('smtp-test', ['recipientName' => 'there']);
        return self::deliver($configuration, $email, '', $template, $transport);
    }

    public static function deliverForTesting(array $configuration, string $email, string $name, array $message, ?callable $transport = null): array
    {
        return self::deliver($configuration, $email, $name, $message, $transport);
    }

    public static function isValidApplicationUrl(string $url, bool $allowInsecureLocal): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        if (strtolower((string) ($parts['scheme'] ?? '')) === 'https') return true;
        $host = strtolower(trim((string) $parts['host'], '[]'));
        return $allowInsecureLocal && strtolower((string) ($parts['scheme'] ?? '')) === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function sendTokenMessage(string $templateName, string $email, string $fullName, string $token, bool $resend, ?callable $transport, ?array $configuration): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $email) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return ['ok' => false, 'message' => 'The email or invitation request is invalid.'];
        }
        $configuration ??= self::configuration();
        if (!$configuration['enabled'] || $configuration['errors'] !== []) {
            if ($templateName === 'password-reset' && self::shouldUseLocalFallback($configuration)) {
                return self::writeLocalMailbox($email, $fullName, $token, $configuration['app_url']);
            }
            $status = self::configurationStatus();
            error_log('[admitflow] Email delivery is unavailable (' . ($status['status'] === 'Configured' ? 'transport' : 'configuration') . ').');
            return ['ok' => false, 'message' => $status['message']];
        }

        $url = $configuration['app_url'] . '/reset-password?token=' . rawurlencode($token);
        $recipientName = self::firstName($fullName);
        $templateData = ['recipientName' => $recipientName, 'resetUrl' => $url, 'expiresMinutes' => 30, 'resend' => $resend];
        $message = self::renderTemplate($templateName, $templateData);
        return self::deliver($configuration, $email, $recipientName, $message, $transport);
    }

    private static function deliver(array $configuration, string $email, string $name, array $message, ?callable $transport): array
    {
        if ($transport !== null) {
            try {
                $accepted = (bool) $transport($configuration, ['to' => $email, 'name' => $name] + $message);
                return ['ok' => $accepted, 'message' => $accepted ? 'The SMTP transport accepted the message.' : 'The mail transport did not accept the message.'];
            } catch (Throwable $error) {
                error_log('[admitflow] Mockable mail transport failed (' . $error::class . ').');
                return ['ok' => false, 'message' => 'The mail transport did not accept the message.'];
            }
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            error_log('[admitflow] SMTP delivery unavailable: Composer dependencies are not installed.');
            return ['ok' => false, 'message' => 'Composer mail dependencies are unavailable. Run composer install.'];
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            error_log('[admitflow] SMTP delivery unavailable: mail library is missing.');
            return ['ok' => false, 'message' => 'The SMTP mail library is unavailable. Run composer install.'];
        }

        try {
            $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $configuration['host'];
            $mailer->Port = $configuration['port'];
            $mailer->SMTPAuth = true;
            $mailer->Username = $configuration['username'];
            $mailer->Password = $configuration['password'];
            $mailer->Timeout = 10;
            $mailer->SMTPDebug = 0;
            $mailer->SMTPSecure = $configuration['encryption'] === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->CharSet = 'UTF-8';
            $mailer->setFrom($configuration['from_address'], $configuration['from_name']);
            if ($configuration['reply_to'] !== '') $mailer->addReplyTo($configuration['reply_to']);
            $mailer->addAddress($email, $name);
            $mailer->Subject = $message['subject'];
            $mailer->isHTML(true);
            $mailer->Body = $message['html'];
            $mailer->AltBody = $message['text'];
            $mailer->send();
            return ['ok' => true, 'message' => 'The SMTP provider accepted the message.'];
        } catch (Throwable $error) {
            error_log('[admitflow] SMTP delivery failed (' . $error::class . ').');
            return ['ok' => false, 'message' => 'The SMTP provider did not accept the message. Check the host, port, encryption, and provider credentials.'];
        }
    }

    private static function renderTemplate(string $name, array $data): array
    {
        $templatePath = dirname(__DIR__) . '/resources/mail/' . $name . '.php';
        if (!is_file($templatePath)) throw new RuntimeException('Email template is unavailable.');
        $recipientName = (string) ($data['recipientName'] ?? 'there');
        $resetUrl = (string) ($data['resetUrl'] ?? '');
        $expiresMinutes = (int) ($data['expiresMinutes'] ?? 30);
        $resend = (bool) ($data['resend'] ?? false);
        return require $templatePath;
    }

    private static function firstName(string $name): string
    {
        $name = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return $parts[0] ?? 'there';
    }

    private static function validHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/\s|[\r\n,;]/', $host)) return false;
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) return true;
        return preg_match('/\A(?=.{1,253}\z)(?:localhost|(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)\z/', $host) === 1;
    }

    private static function environment(): array
    {
        $values = [];
        foreach (array_values(self::ENVIRONMENT_KEYS) as $key) {
            $value = getenv($key);
            if ($value !== false) $values[$key] = $value;
        }
        $appEnvironment = getenv('ADMITFLOW_APP_ENV');
        if ($appEnvironment !== false) $values['ADMITFLOW_APP_ENV'] = $appEnvironment;
        return $values;
    }

    private static function shouldUseLocalFallback(array $configuration): bool
    {
        return $configuration['app_environment'] === 'local'
            && !$configuration['enabled']
            && getenv('ADMITFLOW_MAIL_LOCAL_FALLBACK') === '1';
    }

    private static function writeLocalMailbox(string $email, string $fullName, string $token, string $baseUrl): array
    {
        if (!self::isValidApplicationUrl($baseUrl, true)) return ['ok' => false, 'message' => 'Local mail requires a valid localhost ADMITFLOW_APP_URL.'];
        $directory = dirname(__DIR__) . '/storage/mailbox';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            error_log('[admitflow] Local development mailbox is unavailable.');
            return ['ok' => false, 'message' => 'The local development mailbox is unavailable.'];
        }
        @chmod($directory, 0700);
        $url = $baseUrl . '/reset-password?token=' . rawurlencode($token);
        $message = self::renderTemplate('password-reset', ['recipientName' => self::firstName($fullName), 'resetUrl' => $url, 'expiresMinutes' => 30]);
        $safeName = self::firstName($fullName);
        $content = 'To: ' . $email . "\nSubject: " . $message['subject'] . "\nContent-Type: text/plain; charset=UTF-8\n\n" . $message['text'];
        $file = $directory . '/' . bin2hex(random_bytes(16)) . '.eml';
        if (file_put_contents($file, $content, LOCK_EX) === false || !chmod($file, 0600)) {
            @unlink($file);
            error_log('[admitflow] Local development mailbox could not store a message.');
            return ['ok' => false, 'message' => 'The local development mailbox could not store the message.'];
        }
        return ['ok' => true, 'message' => 'Message stored in the explicitly enabled local development mailbox.'];
    }
}