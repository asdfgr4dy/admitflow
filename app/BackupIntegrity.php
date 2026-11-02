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

    public static function databaseDumpTargetsOnlySchema(string $path, ?string $targetDatabase): bool
    {
        if (($targetDatabase !== null && !preg_match('/^[A-Za-z0-9_]+$/', $targetDatabase)) || !is_file($path) || !is_readable($path)) {
            return false;
        }
        $sql = file_get_contents($path);
        if (!is_string($sql)) return false;
        $tokens = self::sqlTokens($sql);
        if ($tokens === null || $tokens === []) return false;

        $count = count($tokens);
        $tableReferenceList = false;
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $upper = strtoupper($token['value']);
            $next = strtoupper($tokens[$index + 1]['value'] ?? '');

            if ($token['value'] === ';') $tableReferenceList = false;
            if (in_array($upper, ['WHERE', 'SET', 'VALUES', 'ON', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'RETURNING'], true)) $tableReferenceList = false;

            if ($upper === 'USE') {
                return false;
            }
            if (in_array($upper, ['CREATE', 'DROP', 'ALTER'], true) && in_array($next, ['DATABASE', 'SCHEMA'], true)) {
                return false;
            }

            $tableReference = in_array($upper, ['TABLE', 'TABLES', 'FROM', 'JOIN', 'INTO', 'UPDATE', 'REFERENCES', 'CALL', 'VIEW', 'PROCEDURE', 'FUNCTION', 'TRIGGER', 'EVENT'], true);
            if (in_array($upper, ['TABLE', 'TABLES', 'FROM', 'JOIN', 'INTO', 'UPDATE', 'REFERENCES'], true)) $tableReferenceList = true;
            $nameIndex = $index + 1;
            if ($upper === 'TABLE' || $upper === 'TABLES') {
                while (isset($tokens[$nameIndex]) && in_array(strtoupper($tokens[$nameIndex]['value']), ['IF', 'NOT', 'EXISTS', 'TEMPORARY', 'LOW_PRIORITY'], true)) $nameIndex++;
            }
            if ($tableReference && ($tokens[$nameIndex]['type'] ?? '') === 'identifier'
                && ($tokens[$nameIndex + 1]['value'] ?? '') === '.'
                && ($tokens[$nameIndex + 2]['type'] ?? '') === 'identifier'
                && ($targetDatabase === null || strcasecmp($tokens[$nameIndex]['value'], $targetDatabase) !== 0)) {
                return false;
            }

            if (($upper === 'TO' && $tableReferenceList) || ($token['value'] === ',' && $tableReferenceList)) {
                $nameIndex = $index + 1;
                if (($tokens[$nameIndex]['type'] ?? '') === 'identifier'
                    && ($tokens[$nameIndex + 1]['value'] ?? '') === '.'
                    && ($tokens[$nameIndex + 2]['type'] ?? '') === 'identifier'
                    && ($targetDatabase === null || strcasecmp($tokens[$nameIndex]['value'], $targetDatabase) !== 0)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function sqlTokens(string $sql, int $depth = 0): ?array
    {
        if ($depth > 8) return null;
        $tokens = [];
        $length = strlen($sql);
        for ($index = 0; $index < $length;) {
            $char = $sql[$index];
            if (ctype_space($char)) {
                $index++;
                continue;
            }
            if ($char === '#' || ($char === '-' && ($sql[$index + 1] ?? '') === '-' && ctype_space($sql[$index + 2] ?? "\0"))) {
                while ($index < $length && $sql[$index] !== "\n") $index++;
                continue;
            }
            if ($char === '/' && ($sql[$index + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $index + 2);
                if ($end === false) return null;
                if (($sql[$index + 2] ?? '') === '!') {
                    $comment = substr($sql, $index + 3, $end - $index - 3);
                    $comment = preg_replace('/^\s*\d{5,6}\s*/', '', $comment) ?? '';
                    $innerTokens = self::sqlTokens($comment, $depth + 1);
                    if ($innerTokens === null) return null;
                    $tokens = array_merge($tokens, $innerTokens);
                }
                $index = $end + 2;
                continue;
            }
            if ($char === '`') {
                $index++;
                $value = '';
                $closed = false;
                while ($index < $length) {
                    if ($sql[$index] === '`') {
                        if (($sql[$index + 1] ?? '') === '`') {
                            $value .= '`';
                            $index += 2;
                            continue;
                        }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $value .= $sql[$index++];
                }
                if (!$closed) return null;
                $tokens[] = ['type' => 'identifier', 'value' => $value];
                continue;
            }
            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $index++;
                $closed = false;
                while ($index < $length) {
                    if ($sql[$index] === '\\') {
                        $index += min(2, $length - $index);
                        continue;
                    }
                    if ($sql[$index] === $quote) {
                        if (($sql[$index + 1] ?? '') === $quote) {
                            $index += 2;
                            continue;
                        }
                        $index++;
                        $closed = true;
                        break;
                    }
                    $index++;
                }
                if (!$closed) return null;
                $tokens[] = ['type' => 'literal', 'value' => ''];
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$]/', $char)) {
                $start = $index++;
                while ($index < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$index])) $index++;
                $tokens[] = ['type' => 'identifier', 'value' => substr($sql, $start, $index - $start)];
                continue;
            }
            $tokens[] = ['type' => 'symbol', 'value' => $char];
            $index++;
        }
        return $tokens;
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

    public static function tarHasExpectedEntryCount(string $path, int $expectedCount): bool
    {
        if ($expectedCount < 1 || !self::tarHasSafeStructure($path)) return false;
        try {
            $archive = new PharData($path);
            $entryCount = 0;
            foreach (new RecursiveIteratorIterator($archive) as $entry) {
                if ($entry->isFile()) $entryCount++;
            }
            unset($archive);
            return $entryCount === $expectedCount;
        } catch (Throwable) {
            return false;
        }
    }
}