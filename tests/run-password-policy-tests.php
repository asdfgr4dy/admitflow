<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/SecurityPolicy.php';

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};
$errors = static fn (string $password, array $context = []): array => SecurityPolicy::passwordErrors($password, $context);

$assert(SecurityPolicy::passwordLengthLimits() === ['min' => 12, 'max' => 64, 'max_bytes' => 72], 'length limits expose minimum, character maximum, and byte maximum');
$assert(!SecurityPolicy::passwordLengthIsValid('Ab1!xyz7890') && SecurityPolicy::passwordLengthIsValid('Ab1!xyz78901'), '11 characters reject and 12 characters accept');
$assert(SecurityPolicy::passwordLengthIsValid(str_repeat('A', 64)) && !SecurityPolicy::passwordLengthIsValid(str_repeat('A', 65)), '64 characters accept and 65 characters reject');
$assert(SecurityPolicy::passwordLengthIsValid(str_repeat("\u{1F600}", 18)) && !SecurityPolicy::passwordLengthIsValid(str_repeat("\u{1F600}", 19)), '72 UTF-8 bytes are accepted but more than 72 bytes reject independently of character count');
$assert($errors('Abcdef12!xyz') === [], 'three character categories satisfy variety policy');
$assert(in_array('Use at least three of lowercase letters, uppercase letters, numbers, and symbols, or make the password at least 16 characters long.', $errors('uncommonwords'), true), 'two categories below 16 characters reject');
$assert($errors('uncommon phrase!!') === [], '16-character passphrase with spaces is accepted');
$assert($errors('Uncommon phrase 😀') === [], 'spaces and emoji remain accepted as password characters');
$assert($errors('ÉliteCafé!2026') === [], 'Unicode lowercase and uppercase letters participate in category checks');
$assert(in_array('Choose a password that is not commonly used.', $errors('PaSsWoRd'), true), 'common-password comparison is case-insensitive');
$assert(in_array('Choose a password that is not commonly used.', $errors('password123!'), true), 'common base with trailing digits and symbols rejects');
$assert($errors('Qz7!mV2#rT9x') === [], 'uncommon password is accepted');
$assert($errors('pleasantlyquietx') === [], 'exactly 16 characters use the passphrase exception without variety');
$assert($errors('Quiet rivers bend!') === [], 'spaces remain valid password characters');
$assert($errors('Quiet river 😀 2026') === [], 'emoji and spaces are accepted when other rules pass');
$assert($errors('ÉliteCafé!2026') === [], 'accented lowercase and uppercase letters count toward variety');
$assert(in_array('Choose a password that is not commonly used.', $errors('PASSWORD123'), true), 'common-password comparison ignores case and strips trailing digits');
$assert(in_array('Choose a password that is not commonly used.', $errors('password!!!!'), true), 'common-password comparison strips trailing symbols');
$assert(in_array('Choose a password that is not commonly used.', $errors('PASSWORD123!!!'), true), 'common-password comparison strips combined digit and symbol suffixes');
$assert(in_array('Password must not contain your name or email address.', $errors('WelcomeALEXANDRIA2026!', ['name' => 'Alexandria Example']), true), 'personal-name matching is case-insensitive');
$assert(in_array('Password must not contain your name or email address.', $errors('Secure!alex2026X', ['email' => 'alex.smith@example.test']), true), 'name-like segments of the email local part are protected');
$assert($errors('secure!candidate2026', ['email' => 'alex.smith@example.test']) === [], 'email domain is not used as a personal-information hint');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('111111111111'), true), 'long repeated numeric passwords reject');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('prefix87654321suffix'), true), 'descending numeric substrings reject inside other text');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('abcdefghijkl'), true), 'ascending alphabetic sequences reject');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('prefixZYXWVUTSsuffix'), true), 'descending alphabetic sequences reject case-insensitively');
$generated = SecurityPolicy::generatePassword(['name' => 'Generated Account', 'email' => 'generated@example.test']);
$assert(count(SecurityPolicy::passwordErrors($generated, ['name' => 'Generated Account', 'email' => 'generated@example.test'])) === 0 && mb_strlen($generated, 'UTF-8') >= 12 && mb_strlen($generated, 'UTF-8') <= 64 && strlen($generated) <= 72, 'generated one-time password satisfies the complete policy and all length ceilings');
$commonEntries = file(dirname(__DIR__) . '/resources/data/common-passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$assert(is_array($commonEntries) && count(array_unique($commonEntries)) === 10001, 'bundled common-password list has 10001 unique usable entries');
$staffScript = (string) file_get_contents(dirname(__DIR__) . '/scripts/create-staff-user.php');
$studentScript = (string) file_get_contents(dirname(__DIR__) . '/scripts/create-student-user.php');
$assert(str_contains($staffScript, 'SecurityPolicy::generatePassword') && str_contains($staffScript, 'SecurityPolicy::normalizePassword') && str_contains($staffScript, 'must_change_password') && str_contains($studentScript, 'SecurityPolicy::generatePassword') && str_contains($studentScript, 'SecurityPolicy::normalizePassword') && str_contains($studentScript, 'must_change_password'), 'both CLI account scripts use the validated generator, normalized hash input, and forced-change flag');
$assert(in_array('Password must not contain your name or email address.', $errors('Alexandria!2026', ['name' => 'Alexandria Example']), true), 'name part containment rejects');
$assert(in_array('Password must not contain your name or email address.', $errors('contactJsmith42!', ['email' => 'jsmith42@example.test']), true), 'email local-part containment rejects');
$assert($errors('Ab1!shortname', ['name' => 'Li Q']) === [], 'short name components do not become independent personal hints');
$assert($errors('Ab1!Li Qxyz0', ['name' => 'Li Q', 'email' => 'xy@example.test']) === [], 'short full-name and email-local hints do not reject passwords containing them verbatim');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('aaaaaaaaaaaa'), true), 'repeated character rejects');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('prefix12345678suffix'), true), 'ascending sequence within a longer value rejects');
$assert(in_array('Password must not be a repeated character or an obvious sequence.', $errors('zyxwvutsrqponm'), true), 'descending alphabetic sequence rejects');
$assert(!class_exists('Normalizer') || SecurityPolicy::normalizePassword("Cafe\u{0301}2026!Ab") === SecurityPolicy::normalizePassword("Café2026!Ab"), 'NFKC normalization is consistent when intl is installed');

if (!function_exists('e')) {
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('csrf_token')) {
    function csrf_token(): string { return 'password-render-csrf'; }
}
if (!function_exists('take_flash')) {
    function take_flash(): ?array { return null; }
}
$render = static function (string $view, array $data): string {
    extract($data, EXTR_SKIP);
    ob_start();
    require dirname(__DIR__) . '/resources/views/auth/' . $view . '.php';
    return (string) ob_get_clean();
};
$renderedViews = [
    $render('register', ['values' => [], 'result' => null]),
    $render('change-password', ['user' => ['full_name' => 'Render User', 'email' => 'render@example.test', 'must_change_password' => 1], 'passwordRequirements' => SecurityPolicy::passwordLengthLimits()]),
    $render('reset-password', ['passwordContext' => ['name' => 'Reset User', 'email' => 'reset@example.test'], 'token' => 'fixture-token', 'result' => null]),
];
$requiredChecklistMessages = ['At least 12 characters', 'Mix 3 of 4 character types, or use at least 16 characters', 'Does not contain your name or email', 'Passwords match', 'Common-password validation is checked securely when you submit.'];
foreach ($renderedViews as $viewIndex => $markup) {
    $assert(str_contains($markup, 'data-password-checklist') && str_contains($markup, 'aria-live="polite"'), 'auth password view ' . ($viewIndex + 1) . ' renders an announced shared checklist');
    $assert(str_contains($markup, 'id="password-checklist"'), 'auth password view ' . ($viewIndex + 1) . ' uses the checklist ID expected by the client initializer');
    foreach ($requiredChecklistMessages as $message) {
        $assert(str_contains($markup, $message), 'auth password view ' . ($viewIndex + 1) . ' renders checklist message: ' . $message);
    }
    $assert(substr_count($markup, 'minlength="12"') >= 2 && substr_count($markup, 'maxlength="128"') >= 2 && substr_count($markup, 'autocomplete="new-password"') >= 2 && substr_count($markup, 'data-password-toggle') >= 2, 'auth password view ' . ($viewIndex + 1) . ' renders bounded new-password fields and show/hide controls');
    $assert(!preg_match('/<script\b|\sstyle\s*=|\son[a-z]+\s*=/i', $markup), 'auth password view ' . ($viewIndex + 1) . ' contains no inline scripts, styles, or event handlers');
}
$assert(!str_contains(implode('', $renderedViews), 'onpaste') && !str_contains(implode('', $renderedViews), 'preventDefault()'), 'password inputs do not disable pasting');

echo 'Password normalization environment: ' . (class_exists('Normalizer') ? 'intl available' : 'intl unavailable; raw input preserved') . ".\n";

if ($failures !== []) {
    fwrite(STDERR, 'Password policy tests: ' . $assertions . ' assertions, ' . count($failures) . " failures.\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . "\n");
    }
    exit(1);
}

echo 'Password policy tests: ' . $assertions . ' assertions, 0 failures.\n';
