<?php
declare(strict_types=1);

require_once __DIR__ . '/AuditLog.php';

final class AnnouncementService
{
    private const AUDIENCES = ['public', 'students', 'staff', 'all'];
    private const STATES = ['draft', 'scheduled', 'published', 'expired'];

    public static function canManage(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin';
    }

    public static function managementList(PDO $database, string $state = '', array $input = []): array
    {
        if ($state !== '' && !in_array($state, self::STATES, true)) {
            throw new DomainException('Choose a valid announcement status.');
        }
        $audience = trim((string) ($input['audience'] ?? ''));
        if ($audience !== '' && !in_array($audience, self::AUDIENCES, true)) {
            throw new DomainException('Choose a valid announcement audience.');
        }
        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 180) {
            throw new DomainException('Announcement search must be 180 characters or fewer.');
        }
        $dateFilters = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) {
                throw new DomainException('Enter valid announcement dates using year-month-day format.');
            }
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $dateFilters[$key] = ['display' => $value, 'parameter' => $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'operator' => $boundary === 'start' ? '>=' : '<'];
            }
        }
        if (isset($dateFilters['start_date'], $dateFilters['end_date']) && $dateFilters['start_date']['display'] > $dateFilters['end_date']['display']) {
            throw new DomainException('The announcement start date must not be later than its end date.');
        }
        $dateRange = trim((string) ($input['date_range'] ?? ''));
        if ($dateRange !== '' && !in_array($dateRange, ['last7', 'last30', 'custom'], true)) throw new DomainException('Choose a valid announcement date range.');
        if ($dateFilters !== [] && $dateRange === '') $dateRange = 'custom';
        $now = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
        $stateExpression = '(CASE WHEN is_published = 0 THEN \'draft\' WHEN ends_at IS NOT NULL AND ends_at < ' . $now . ' THEN \'expired\' WHEN starts_at IS NOT NULL AND starts_at > ' . $now . ' THEN \'scheduled\' ELSE \'published\' END)';
        $conditions = [];
        $parameters = [];
        if ($state !== '') {
            $conditions[] = $stateExpression . ' = :state';
            $parameters['state'] = $state;
        }
        if ($audience !== '') {
            $conditions[] = 'audience = :audience';
            $parameters['audience'] = $audience;
        }
        if ($search !== '') {
            $conditions[] = '(title LIKE :search_title OR body LIKE :search_body)';
            $parameters['search_title'] = '%' . $search . '%';
            $parameters['search_body'] = '%' . $search . '%';
        }
        foreach ($dateFilters as $key => $dateFilter) {
            $conditions[] = 'COALESCE(announcements.starts_at, announcements.created_at) ' . $dateFilter['operator'] . ' :' . $key . '_utc';
            $parameters[$key . '_utc'] = $dateFilter['parameter'];
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $count = $database->prepare('SELECT COUNT(*) FROM announcements' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $countConditions = $audience !== '' ? ' WHERE audience = :audience' : '';
        $stateCounts = $database->prepare('SELECT ' . $stateExpression . ' AS state, COUNT(*) AS total FROM announcements' . $countConditions . ' GROUP BY ' . $stateExpression);
        $stateCounts->execute($audience !== '' ? ['audience' => $audience] : []);
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($stateCounts->fetchAll() as $stateCount) {
            $counts[(string) $stateCount['state']] = (int) $stateCount['total'];
        }
        $pagination = Pagination::make($total, $input, 'page', 'per_page', 20);
        $statement = $database->prepare('SELECT announcements.id, announcements.title, announcements.body, announcements.audience, announcements.starts_at, announcements.ends_at, announcements.is_published, announcements.created_at, announcements.updated_at, users.full_name AS creator_name, ' . $stateExpression . ' AS state FROM announcements LEFT JOIN users ON users.id = announcements.created_by' . $where . ' ORDER BY announcements.updated_at DESC, announcements.id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        $pagination['base_url'] = '/staff/announcements';
        $filters = ['state' => $state, 'audience' => $audience, 'q' => $search, 'start_date' => $dateFilters['start_date']['display'] ?? '', 'end_date' => $dateFilters['end_date']['display'] ?? '', 'date_range' => $dateRange];
        $pagination['query'] = array_filter($filters, static fn (string $value): bool => $value !== '');

        return ['rows' => $rows, 'total' => $total, 'pagination' => $pagination, 'counts' => $counts, 'filters' => $filters];
    }

    public static function validateSaveInput(array $input, bool $isNew, ?DateTimeImmutable $now = null): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $audience = (string) ($input['audience'] ?? 'public');
        $publicationMode = (string) ($input['publication_mode'] ?? '');
        $startsAt = trim((string) ($input['starts_at'] ?? ''));
        $endsAt = trim((string) ($input['ends_at'] ?? ''));
        $errors = [];
        if ($title === '') $errors['title'] = 'Enter an announcement title.';
        elseif (mb_strlen($title) > 180) $errors['title'] = 'The title must be 180 characters or fewer.';
        if ($body === '') $errors['body'] = 'Enter an announcement message.';
        elseif (mb_strlen($body) > 10000) $errors['body'] = 'The message must be 10,000 characters or fewer.';
        if (!in_array($audience, self::AUDIENCES, true)) $errors['audience'] = 'Choose a valid target audience.';
        if ($isNew && !in_array($publicationMode, ['publish', 'schedule'], true)) $errors['publication_mode'] = 'Choose when to publish this announcement.';
        if ($isNew && $publicationMode === 'publish') {
            $startsAt = '';
            $endsAt = '';
        } elseif ($isNew && $publicationMode === 'schedule' && $startsAt === '') {
            $errors['starts_at'] = 'Choose a scheduled date and time.';
        }
        $parseLocalDateTime = static function (string $value): string|false|null {
            if ($value === '') return null;
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d\\TH:i') !== $value) return false;
            return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        };
        $startsUtc = $parseLocalDateTime($startsAt);
        $endsUtc = $parseLocalDateTime($endsAt);
        if ($startsUtc === false) $errors['starts_at'] = 'Enter a valid scheduled date and time.';
        if ($endsUtc === false) $errors['ends_at'] = 'Enter a valid end date and time.';
        if ($isNew && $publicationMode === 'schedule' && $startsUtc !== false && $startsUtc !== null) {
            $currentUtc = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
            if (new DateTimeImmutable($startsUtc, new DateTimeZone('UTC')) <= $currentUtc) {
                $errors['starts_at'] = 'The scheduled date and time must be in the future.';
            }
        }
        if ($startsUtc !== false && $endsUtc !== false && $startsUtc !== null && $endsUtc !== null && $endsUtc <= $startsUtc) {
            $errors['ends_at'] = 'The end time must be later than the start time.';
        }
        $values = [
            'title' => $title,
            'body' => $body,
            'audience' => $audience,
            'starts_at' => $startsUtc === false ? null : $startsUtc,
            'ends_at' => $endsUtc === false ? null : $endsUtc,
            'is_published' => $isNew ? 1 : ((string) ($input['is_published'] ?? '') === '1' ? 1 : 0),
        ];
        $formValues = ['title' => $title, 'body' => $body, 'audience' => $audience, 'publication_mode' => $publicationMode, 'starts_at' => (string) ($input['starts_at'] ?? '')];

        return ['ok' => $errors === [], 'values' => $values, 'form_values' => $formValues, 'errors' => $errors];
    }

    public static function activeForUser(PDO $database, ?array $user, int $limit = 10): array
    {
        $role = (string) ($user['role'] ?? 'public');
        $audiences = match ($role) {
            'student' => ['students', 'all'],
            'reviewer' => ['staff', 'all'],
            'admin' => self::AUDIENCES,
            default => ['public', 'all'],
        };
        $limit = max(1, min(20, $limit));
        $now = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
        $audienceParameters = [];
        foreach ($audiences as $index => $audience) {
            $audienceParameters['audience_' . $index] = $audience;
        }
        $audiencePlaceholders = implode(', ', array_map(static fn (int $index): string => ':audience_' . $index, array_keys($audiences)));
        $statement = $database->prepare('SELECT id, title, body, audience, starts_at, ends_at, created_at FROM announcements WHERE is_published = 1 AND audience IN (' . $audiencePlaceholders . ') AND (starts_at IS NULL OR starts_at <= ' . $now . ') AND (ends_at IS NULL OR ends_at >= ' . $now . ') ORDER BY COALESCE(starts_at, created_at) DESC, id DESC LIMIT ' . $limit);
        $statement->execute($audienceParameters);

        return $statement->fetchAll();
    }

    public static function activePage(PDO $database, ?array $user, array $input = []): array
    {
        $role = (string) ($user['role'] ?? 'public');
        $audiences = match ($role) {
            'student' => ['students', 'all'],
            'reviewer' => ['staff', 'all'],
            'admin' => self::AUDIENCES,
            default => ['public', 'all'],
        };
        $now = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
        $audienceParameters = [];
        foreach ($audiences as $index => $audience) {
            $audienceParameters['audience_' . $index] = $audience;
        }
        $audiencePlaceholders = implode(', ', array_map(static fn (int $index): string => ':audience_' . $index, array_keys($audiences)));
        $conditions = ['is_published = 1', 'audience IN (' . $audiencePlaceholders . ')', '(starts_at IS NULL OR starts_at <= ' . $now . ')', '(ends_at IS NULL OR ends_at >= ' . $now . ')'];
        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 180) throw new DomainException('Announcement search must be 180 characters or fewer.');
        $parameters = $audienceParameters;
        if ($search !== '') {
            $conditions[] = '(title LIKE :search_title OR body LIKE :search_body)';
            $parameters['search_title'] = '%' . $search . '%';
            $parameters['search_body'] = '%' . $search . '%';
        }
        $where = implode(' AND ', $conditions);
        $count = $database->prepare('SELECT COUNT(*) FROM announcements WHERE ' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pagination = Pagination::make($total, $input, 'page', 'per_page', 20);
        $statement = $database->prepare('SELECT id, title, body, audience, starts_at, ends_at, created_at FROM announcements WHERE ' . $where . ' ORDER BY COALESCE(starts_at, created_at) DESC, id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset']);
        $statement->execute($parameters);
        $pagination['base_url'] = '/announcements';
        $pagination['query'] = $search === '' ? [] : ['q' => $search];

        return ['rows' => $statement->fetchAll(), 'pagination' => $pagination, 'filters' => ['q' => $search]];
    }

    public static function visibleAnnouncement(PDO $database, int $announcementId, ?array $user): ?array
    {
        if ($announcementId < 1) {
            return null;
        }
        $role = (string) ($user['role'] ?? 'public');
        $audiences = match ($role) {
            'student' => ['students', 'all'],
            'reviewer' => ['staff', 'all'],
            'admin' => self::AUDIENCES,
            default => ['public', 'all'],
        };
        $now = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
        $audienceParameters = ['id' => $announcementId];
        foreach ($audiences as $index => $audience) {
            $audienceParameters['audience_' . $index] = $audience;
        }
        $audiencePlaceholders = implode(', ', array_map(static fn (int $index): string => ':audience_' . $index, array_keys($audiences)));
        $statement = $database->prepare('SELECT id, title, body, audience, starts_at, ends_at, is_published, created_at, updated_at FROM announcements WHERE id = :id AND is_published = 1 AND audience IN (' . $audiencePlaceholders . ') AND (starts_at IS NULL OR starts_at <= ' . $now . ') AND (ends_at IS NULL OR ends_at >= ' . $now . ') LIMIT 1');
        $statement->execute($audienceParameters);
        $announcement = $statement->fetch();

        return is_array($announcement) ? $announcement : null;
    }

    public static function delete(PDO $database, array $user, int $announcementId): void
    {
        if (!self::canManage($user)) {
            AuditLog::record($database, $user, 'announcement.delete_blocked', 'announcement', $announcementId > 0 ? $announcementId : null, 'failure', ['reason' => 'Administrator access is required.'], 'warning');
            throw new DomainException('Administrator access is required to manage announcements.');
        }
        if ($announcementId < 1) {
            AuditLog::record($database, $user, 'announcement.delete_blocked', 'announcement', null, 'warning', ['reason' => 'Invalid announcement identifier.'], 'warning');
            throw new DomainException('Choose a valid announcement.');
        }
        $database->beginTransaction();
        try {
            $select = $database->prepare('SELECT id FROM announcements WHERE id = :id' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $select->execute(['id' => $announcementId]);
            if (!$select->fetchColumn()) {
                throw new DomainException('That announcement no longer exists.');
            }
            $delete = $database->prepare('DELETE FROM announcements WHERE id = :id');
            $delete->execute(['id' => $announcementId]);
            AuditLog::record($database, $user, 'announcement.deleted', 'announcement', $announcementId);
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            if ($error instanceof DomainException) {
                try {
                    AuditLog::record($database, $user, 'announcement.delete_blocked', 'announcement', $announcementId, 'warning', ['reason' => $error->getMessage()], 'warning');
                } catch (Throwable $auditError) {
                    error_log('[admitflow announcement] Blocked delete audit failed (' . $auditError::class . ').');
                }
            }
            throw $error;
        }
    }

}