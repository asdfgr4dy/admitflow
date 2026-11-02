<?php
declare(strict_types=1);

final class AuditLog
{
    private static ?WeakMap $columnsByConnection = null;

    public static function record(
        PDO $database,
        ?array $user,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        string $outcome = 'success',
        array $details = [],
        ?string $severity = null,
        ?string $actorLabel = null,
        ?int $userId = null
    ): void {
        if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $action)) {
            throw new InvalidArgumentException('Audit action is invalid.');
        }
        if (!in_array($outcome, ['success', 'failure', 'warning'], true)) {
            throw new InvalidArgumentException('Audit outcome is invalid.');
        }

        $severity ??= $outcome === 'failure' ? 'warning' : 'info';
        if (!in_array($severity, ['info', 'notice', 'warning', 'critical'], true)) {
            throw new InvalidArgumentException('Audit severity is invalid.');
        }

        $actorLabel ??= (string) ($user['full_name'] ?? 'Anonymous');
        $actorLabel = trim($actorLabel);
        if ($actorLabel === '') $actorLabel = 'Anonymous';
        $userId ??= isset($user['id']) ? (int) $user['id'] : null;
        if ($actorLabel === 'Anonymous' && $userId !== null) {
            $actorQuery = $database->prepare('SELECT full_name FROM users WHERE id = :user_id');
            $actorQuery->execute(['user_id' => $userId]);
            $storedActor = $actorQuery->fetchColumn();
            if (is_string($storedActor) && trim($storedActor) !== '') $actorLabel = trim($storedActor);
        }
        $safeDetails = self::sanitizeDetails($details);
        $columns = self::columns($database);

        $values = [
            'user_id' => $userId,
            'actor_label' => mb_substr($actorLabel, 0, 160),
            'action' => $action,
            'outcome' => $outcome,
        ];
        if (isset($columns['entity_type'])) $values['entity_type'] = $entityType === null ? null : mb_substr($entityType, 0, 80);
        if (isset($columns['entity_id'])) $values['entity_id'] = $entityId;
        if (isset($columns['severity'])) $values['severity'] = $severity;
        if (isset($columns['ip_address'])) $values['ip_address'] = function_exists('request_ip') ? request_ip() : null;
        if (isset($columns['request_id'])) $values['request_id'] = self::requestId();
        if (isset($columns['details'])) $values['details'] = $safeDetails === [] ? null : json_encode($safeDetails, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (isset($columns['created_at'])) $values['created_at'] = gmdate('Y-m-d H:i:s');

        $insertColumns = array_keys($values);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $insertColumns);
        $statement = $database->prepare('INSERT INTO audit_logs (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $statement->execute($values);
    }

    public static function recordOncePerWindow(
        PDO $database,
        array $user,
        string $action,
        string $entityType,
        int $entityId,
        int $windowSeconds,
        array $details = [],
        ?string $severity = null
    ): bool {
        if ((int) ($user['id'] ?? 0) < 1 || $windowSeconds < 1) return false;
        $columns = self::columns($database);
        if (!isset($columns['created_at'])) {
            self::record($database, $user, $action, $entityType, $entityId, 'success', $details, $severity);
            return true;
        }

        $ownsTransaction = !$database->inTransaction();
        if ($ownsTransaction) $database->beginTransaction();
        try {
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $actorLock = $database->prepare('SELECT id FROM users WHERE id = :user_id FOR UPDATE');
                $actorLock->execute(['user_id' => (int) $user['id']]);
                if (!$actorLock->fetchColumn()) {
                    if ($ownsTransaction) $database->rollBack();
                    return false;
                }
            }
            $cutoff = gmdate('Y-m-d H:i:s', time() - $windowSeconds);
            $query = $database->prepare('SELECT 1 FROM audit_logs WHERE user_id = :user_id AND action = :action AND entity_type = :entity_type AND entity_id = :entity_id AND created_at >= :cutoff LIMIT 1');
            $query->execute(['user_id' => (int) $user['id'], 'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'cutoff' => $cutoff]);
            if ($query->fetchColumn()) {
                if ($ownsTransaction) $database->commit();
                return false;
            }

            self::record($database, $user, $action, $entityType, $entityId, 'success', $details, $severity);
            if ($ownsTransaction) $database->commit();
            return true;
        } catch (Throwable $error) {
            if ($ownsTransaction && $database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    private static function columns(PDO $database): array
    {
        self::$columnsByConnection ??= new WeakMap();
        if (isset(self::$columnsByConnection[$database])) return self::$columnsByConnection[$database];

        $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = $driver === 'sqlite'
            ? $database->query('PRAGMA table_info(audit_logs)')->fetchAll(PDO::FETCH_ASSOC)
            : $database->query('SHOW COLUMNS FROM audit_logs')->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($rows as $row) {
            $name = $driver === 'sqlite' ? ($row['name'] ?? '') : ($row['Field'] ?? '');
            if (is_string($name) && $name !== '') $columns[$name] = true;
        }
        foreach (['user_id', 'actor_label', 'action', 'outcome'] as $required) {
            if (!isset($columns[$required])) throw new RuntimeException('Audit log schema is missing a required column.');
        }
        self::$columnsByConnection[$database] = $columns;
        return $columns;
    }

    private static function requestId(): ?string
    {
        foreach ([$_SERVER['HTTP_X_REQUEST_ID'] ?? null, $_SERVER['REQUEST_ID'] ?? null, $_SERVER['UNIQUE_ID'] ?? null] as $candidate) {
            if (is_string($candidate) && preg_match('/^[a-zA-Z0-9._:-]{1,36}$/', $candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function sanitizeDetails(array $details, int $depth = 0): array
    {
        if ($depth >= 3) return [];
        $safe = [];
        foreach (array_slice($details, 0, 24, true) as $key => $value) {
            $key = (string) $key;
            if (preg_match('/password|passwd|hash|token|secret|credential|csrf|session|cookie|authorization|address|date.?of.?birth|birth.?date|document.?content|file.?content|storage.?name|search|query|feedback|description|instructions|note|body|message/i', $key)) continue;
            if (is_array($value)) {
                $nested = self::sanitizeDetails($value, $depth + 1);
                if ($nested !== []) $safe[$key] = $nested;
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 240) : $value;
            }
        }
        return $safe;
    }
}