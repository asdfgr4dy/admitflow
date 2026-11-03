<?php
declare(strict_types=1);

final class SecurityPolicy
{
    private const PASSWORD_MIN_LENGTH = 12;
    private const PASSWORD_MAX_LENGTH = 64;
    private const PASSWORD_MAX_BYTES = 72;

    public static function passwordLengthLimits(): array
    {
        return ['min' => self::PASSWORD_MIN_LENGTH, 'max' => self::PASSWORD_MAX_LENGTH, 'max_bytes' => self::PASSWORD_MAX_BYTES];
    }

    public static function passwordLengthIsValid(string $password): bool
    {
        if (!function_exists('mb_strlen')) {
            return false;
        }

        try {
            $normalized = self::normalizePassword($password);
        } catch (RuntimeException) {
            return false;
        }
        if (preg_match('//u', $normalized) !== 1) {
            return false;
        }

        $length = mb_strlen($normalized, 'UTF-8');
        return is_int($length)
            && $length >= self::PASSWORD_MIN_LENGTH
            && $length <= self::PASSWORD_MAX_LENGTH
            && strlen($normalized) <= self::PASSWORD_MAX_BYTES;
    }

    public static function normalizePassword(string $password): string
    {
        if (!class_exists('Normalizer')) {
            return $password;
        }

        $normalized = Normalizer::normalize($password, Normalizer::FORM_KC);
        if (!is_string($normalized)) {
            throw new RuntimeException('Password normalization failed.');
        }

        return $normalized;
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        if (password_verify($password, $hash)) {
            return true;
        }
        if (!class_exists('Normalizer')) {
            return false;
        }

        try {
            $normalized = self::normalizePassword($password);
        } catch (RuntimeException) {
            return false;
        }

        return $normalized !== $password && password_verify($normalized, $hash);
    }

    public static function generatePassword(array $context = []): string
    {
        $lowercase = 'abcdefghijkmnopqrstuvwxyz';
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $numbers = '23456789';
        $symbols = '!#$%+?@_';
        $allCharacters = $lowercase . $uppercase . $numbers . $symbols;

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $characters = [
                $lowercase[random_int(0, strlen($lowercase) - 1)],
                $uppercase[random_int(0, strlen($uppercase) - 1)],
                $numbers[random_int(0, strlen($numbers) - 1)],
                $symbols[random_int(0, strlen($symbols) - 1)],
            ];
            for ($index = 0; $index < 12; $index++) {
                $characters[] = $allCharacters[random_int(0, strlen($allCharacters) - 1)];
            }
            for ($index = count($characters) - 1; $index > 0; $index--) {
                $swapIndex = random_int(0, $index);
                [$characters[$index], $characters[$swapIndex]] = [$characters[$swapIndex], $characters[$index]];
            }

            $candidate = self::normalizePassword(implode('', $characters));
            if (self::passwordErrors($candidate, $context) === []) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a password that satisfies the configured policy.');
    }

    public static function passwordErrors(string $password, array $context = []): array
    {
        $errors = [];
        try {
            $normalized = self::normalizePassword($password);
        } catch (RuntimeException) {
            return ['Password validation is temporarily unavailable. Please try again later.'];
        }

        if (!function_exists('mb_strlen') || !function_exists('mb_strtolower')) {
            return ['Password validation is unavailable because the server is missing the required mbstring extension.'];
        }

        if (preg_match('//u', $normalized) !== 1) {
            $errors[] = 'Password must contain valid UTF-8 text.';
            return $errors;
        }

        $length = mb_strlen($normalized, 'UTF-8');
        if ($length < self::PASSWORD_MIN_LENGTH) {
            $errors[] = 'Password must be at least 12 characters long.';
        }
        if ($length > self::PASSWORD_MAX_LENGTH) {
            $errors[] = 'Password must be no more than 64 characters long.';
        }
        if (strlen($normalized) > self::PASSWORD_MAX_BYTES) {
            $errors[] = 'Password must be no more than 72 bytes when encoded as UTF-8.';
        }

        if ($length < 16) {
            $categories = [
                preg_match('/\p{Ll}/u', $normalized) === 1,
                preg_match('/\p{Lu}/u', $normalized) === 1,
                preg_match('/\p{N}/u', $normalized) === 1,
                preg_match('/[\p{P}\p{S}]/u', $normalized) === 1,
            ];
            if (count(array_filter($categories)) < 3) {
                $errors[] = 'Use at least three of lowercase letters, uppercase letters, numbers, and symbols, or make the password at least 16 characters long.';
            }
        }

        if (self::containsPersonalInformation($normalized, $context)) {
            $errors[] = 'Password must not contain your name or email address.';
        }

        if (self::isRepeatedCharacter($normalized) || self::containsSequentialPattern($normalized)) {
            $errors[] = 'Password must not be a repeated character or an obvious sequence.';
        }

        $commonPasswords = self::commonPasswords();
        if ($commonPasswords === null) {
            $errors[] = 'Password validation is temporarily unavailable. Please try again later.';
        } else {
            $candidate = mb_strtolower($normalized, 'UTF-8');
            $baseCandidate = preg_replace('/[\p{N}\p{P}\p{S}]+$/u', '', $candidate);
            if (isset($commonPasswords[$candidate]) || (is_string($baseCandidate) && $baseCandidate !== '' && isset($commonPasswords[$baseCandidate]))) {
                $errors[] = 'Choose a password that is not commonly used.';
            }
        }

        return $errors;
    }

    private static function commonPasswords(): ?array
    {
        static $loaded = false;
        static $passwords = null;
        if ($loaded) {
            return $passwords;
        }
        $loaded = true;

        $path = dirname(__DIR__) . '/resources/data/common-passwords.txt';
        if (!is_file($path) || !is_readable($path)) {
            error_log('[admitflow] Common password list is missing or unreadable.');
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || $lines === []) {
            error_log('[admitflow] Common password list is invalid or unreadable.');
            return null;
        }

        $passwords = [];
        foreach ($lines as $line) {
            $entry = rtrim($line, "\r");
            if ($entry === '' || trim($entry) !== $entry || preg_match('//u', $entry) !== 1) {
                error_log('[admitflow] Common password list contains an invalid entry.');
                $passwords = null;
                return null;
            }
            $normalized = self::normalizePassword($entry);
            $lowercase = mb_strtolower($normalized, 'UTF-8');
            if ($entry !== $lowercase) {
                error_log('[admitflow] Common password list contains a non-lowercase entry.');
                $passwords = null;
                return null;
            }
            $passwords[$lowercase] = true;
        }

        if ($passwords === []) {
            error_log('[admitflow] Common password list contains no usable entries.');
            return null;
        }

        return $passwords;
    }

    private static function containsPersonalInformation(string $password, array $context): bool
    {
        $passwordLower = mb_strtolower($password, 'UTF-8');
        $hints = [];
        $email = is_string($context['email'] ?? null) ? $context['email'] : '';
        if ($email !== '') {
            $localPart = explode('@', $email, 2)[0];
            $localPartCharacters = preg_replace('/[^\p{L}\p{M}\p{N}]/u', '', self::normalizeHint($localPart));
            if (is_string($localPartCharacters) && mb_strlen($localPartCharacters, 'UTF-8') >= 4) {
                $hints[] = $localPart;
                foreach (preg_split('/[^\p{L}\p{M}\p{N}]+/u', $localPart, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                    if (mb_strlen($part, 'UTF-8') >= 4) {
                        $hints[] = $part;
                    }
                }
            }
        }

        foreach (['name', 'full_name', 'first_name', 'last_name'] as $key) {
            $name = is_string($context[$key] ?? null) ? $context[$key] : '';
            if ($name === '') {
                continue;
            }
            $nameCharacters = preg_replace('/[^\p{L}\p{M}\p{N}]/u', '', self::normalizeHint($name));
            if (is_string($nameCharacters) && mb_strlen($nameCharacters, 'UTF-8') >= 4) {
                $hints[] = $name;
            }
            foreach (preg_split('/[^\p{L}\p{M}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                if (mb_strlen($part, 'UTF-8') >= 4) {
                    $hints[] = $part;
                }
            }
        }

        foreach (array_unique($hints) as $hint) {
            $hint = self::normalizeHint($hint);
            if ($hint !== '' && str_contains($passwordLower, $hint)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHint(string $value): string
    {
        if (class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function isRepeatedCharacter(string $password): bool
    {
        return preg_match('/\A(.)\1+\z/us', $password) === 1;
    }

    private static function containsSequentialPattern(string $password): bool
    {
        $characters = preg_split('//u', mb_strtolower($password, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return false;
        }

        $run = 1;
        $direction = 0;
        $previous = '';
        foreach ($characters as $character) {
            $isAsciiAlphaNumeric = strlen($character) === 1 && preg_match('/[a-z0-9]/', $character) === 1;
            if (!$isAsciiAlphaNumeric || $previous === '') {
                $run = 1;
                $direction = 0;
                $previous = $isAsciiAlphaNumeric ? $character : '';
                continue;
            }

            $sameGroup = (ctype_digit($previous) && ctype_digit($character))
                || (ctype_alpha($previous) && ctype_alpha($character));
            $difference = ord($character) - ord($previous);
            if ($sameGroup && in_array($difference, [-1, 1], true) && ($direction === 0 || $direction === $difference)) {
                $run++;
                $direction = $difference;
            } else {
                $run = 1;
                $direction = 0;
            }
            if ($run >= 8) {
                return true;
            }
            $previous = $character;
        }

        return false;
    }

    public static function csrfMatches(string $expected, mixed $submitted): bool
    {
        return is_string($submitted) && $expected !== '' && hash_equals($expected, $submitted);
    }

    public static function hasRole(?array $user, array $allowedRoles): bool
    {
        return $user !== null && isset($user['role']) && in_array($user['role'], $allowedRoles, true);
    }

    public static function ownsRecord(int $authenticatedUserId, int $recordOwnerId): bool
    {
        return $authenticatedUserId > 0 && $authenticatedUserId === $recordOwnerId;
    }
}