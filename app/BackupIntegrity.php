<?php
declare(strict_types=1);

final class BackupIntegrity
{
    private const REQUIRED_TABLES = [
        'users', 'student_profiles', 'academic_years', 'academic_programs', 'applications',
        'admission_requirements', 'application_documents', 'document_reviews', 'reviewer_assignments',
        'screening_notes', 'interviews', 'enrollment_records', 'announcements', 'notifications',
        'application_status_history', 'audit_logs', 'password_reset_tokens', 'login_attempts',
        'backups', 'system_settings',
    ];

    public static function checksumMatches(string $path, string $expectedChecksum): bool
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) < 1 || !preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)) {
            return false;
        }
        $actual = hash_file('sha256', $path);
        return is_string($actual) && hash_equals($expectedChecksum, $actual);
    }

    public static function databaseDumpHasExpectedStructure(string $path): bool
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) < 1024) {
            return false;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $header = fread($handle, 4096);
        if (!is_string($header) || stripos($header, 'dump') === false || str_contains($header, "\0")) {
            fclose($handle);
            return false;
        }
        rewind($handle);

        $foundTables = [];
        $hasSqlStatement = false;
        $lastBytes = '';
        while (($line = fgets($handle)) !== false) {
            if (str_contains($line, "\0") || preg_match('/(?:^|\s)(?:ERROR|FATAL):/i', $line)) {
                fclose($handle);
                return false;
            }
            if (preg_match('/^CREATE TABLE\s+`?([a-z_]+)`?/i', trim($line), $match)) {
                $foundTables[strtolower($match[1])] = true;
            }
            if (preg_match('/^(INSERT INTO|CREATE TABLE|ALTER TABLE|SET |LOCK TABLES)/i', trim($line))) {
                $hasSqlStatement = true;
            }
            $lastBytes = substr($lastBytes . $line, -4096);
        }
        fclose($handle);

        $hasCompletionMarker = stripos($lastBytes, 'Dump completed on') !== false;
        return $hasSqlStatement && $hasCompletionMarker && array_diff(self::REQUIRED_TABLES, array_keys($foundTables)) === [];
    }

    public static function tarHasSafeStructure(string $path): bool
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) < 512) {
            return false;
        }
        try {
            $archive = new PharData($path);
            foreach (new RecursiveIteratorIterator($archive) as $entry) {
                $relative = str_replace('\\', '/', $entry->getPathName());
                if (str_contains($relative, '../') || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative)) {
                    return false;
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}