<?php
declare(strict_types=1);

final class NotificationService
{
    public static function feed(PDO $database, array $user, array $dismissed = [], int $limit = 8): array
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || !in_array($role, ['student', 'reviewer', 'admin'], true)) {
            return ['items' => [], 'unread_count' => 0, 'persistent_unread_count' => 0];
        }

        $unread = $database->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL');
        $unread->execute(['user_id' => $userId]);
        $persistentUnread = (int) $unread->fetchColumn();
        $stored = $database->prepare('SELECT id, type, title, message, related_type, related_id, read_at, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 100');
        $stored->execute(['user_id' => $userId]);
        $items = [];
        foreach ($stored->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'key' => null,
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'created_at' => (string) $row['created_at'],
                'read_at' => $row['read_at'],
                'derived' => false,
                'href' => self::notificationHref($database, $user, $row),
            ];
        }

        $derived = self::derivedAlerts($database, $user);
        $derivedUnread = 0;
        foreach ($derived as $item) {
            $dismissedItem = in_array($item['key'], $dismissed, true);
            $item['read_at'] = $dismissedItem ? 'dismissed' : null;
            if (!$dismissedItem) {
                $derivedUnread++;
            }
            $items[] = $item;
        }
        usort($items, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']));

        return [
            'items' => array_slice($items, 0, max(1, min($limit, 100))),
            'unread_count' => $persistentUnread + $derivedUnread,
            'persistent_unread_count' => $persistentUnread,
            'derived_keys' => array_column($derived, 'key'),
        ];
    }

    public static function feedPage(PDO $database, array $user, array $dismissed, array $input): array
    {
        $role = (string) ($user['role'] ?? '');
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || !in_array($role, ['student', 'reviewer', 'admin'], true)) {
            $pagination = Pagination::make(0, $input);
            return ['items' => [], 'pagination' => $pagination, 'filters' => ['q' => '', 'status' => '']];
        }

        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 180) throw new DomainException('Keep notification search under 180 characters.');
        $readFilter = trim((string) ($input['status'] ?? ''));
        if ($readFilter !== '' && !in_array($readFilter, ['read', 'unread'], true)) throw new DomainException('Choose a valid notification read status.');
        $storedConditions = ['user_id = :user_id'];
        $storedParameters = ['user_id' => $userId];
        if ($readFilter === 'read') $storedConditions[] = 'read_at IS NOT NULL';
        if ($readFilter === 'unread') $storedConditions[] = 'read_at IS NULL';
        if ($search !== '') {
            $storedConditions[] = '(title LIKE :search_title OR message LIKE :search_message)';
            $storedParameters['search_title'] = '%' . $search . '%';
            $storedParameters['search_message'] = '%' . $search . '%';
        }
        $storedWhere = implode(' AND ', $storedConditions);
        $persistentCount = $database->prepare('SELECT COUNT(*) FROM notifications WHERE ' . $storedWhere);
        $persistentCount->execute($storedParameters);
        $persistentTotal = (int) $persistentCount->fetchColumn();
        $derived = self::derivedAlerts($database, $user);
        foreach ($derived as &$item) {
            $item['read_at'] = in_array($item['key'], $dismissed, true) ? 'dismissed' : null;
        }
        unset($item);
        $derived = array_values(array_filter($derived, static function (array $item) use ($search, $readFilter): bool {
            if ($readFilter === 'read' && empty($item['read_at'])) return false;
            if ($readFilter === 'unread' && !empty($item['read_at'])) return false;
            return $search === '' || mb_stripos($item['title'] . ' ' . $item['message'], $search) !== false;
        }));
        usort($derived, static fn (array $left, array $right): int => strcmp((string) $right['created_at'], (string) $left['created_at']) ?: strcmp((string) $left['key'], (string) $right['key']));

        $pagination = Pagination::make($persistentTotal + count($derived), $input, 'page', 'per_page', 20);
        $persistentOffset = max(0, $pagination['offset'] - count($derived));
        $fetchLimit = $pagination['page_size'] + count($derived);
        $rankConditions = ['ahead.user_id = notifications.user_id', '(ahead.created_at > notifications.created_at OR (ahead.created_at = notifications.created_at AND ahead.id > notifications.id))'];
        $rankParameters = [];
        if ($readFilter === 'read') $rankConditions[] = 'ahead.read_at IS NOT NULL';
        if ($readFilter === 'unread') $rankConditions[] = 'ahead.read_at IS NULL';
        if ($search !== '') {
            $rankConditions[] = '(ahead.title LIKE :rank_search_title OR ahead.message LIKE :rank_search_message)';
            $rankParameters['rank_search_title'] = '%' . $search . '%';
            $rankParameters['rank_search_message'] = '%' . $search . '%';
        }
        $stored = $database->prepare('SELECT id, type, title, message, related_type, related_id, read_at, created_at, (SELECT COUNT(*) FROM notifications ahead WHERE ' . implode(' AND ', $rankConditions) . ') AS newer_count FROM notifications WHERE ' . $storedWhere . ' ORDER BY created_at DESC, id DESC LIMIT ' . $fetchLimit . ' OFFSET ' . $persistentOffset);
        $stored->execute($storedParameters + $rankParameters);
        $aheadConditions = ['user_id = :user_id', 'created_at >= :created_at'];
        if ($readFilter === 'read') $aheadConditions[] = 'read_at IS NOT NULL';
        if ($readFilter === 'unread') $aheadConditions[] = 'read_at IS NULL';
        if ($search !== '') $aheadConditions[] = '(title LIKE :search_title OR message LIKE :search_message)';
        $persistentAhead = $database->prepare('SELECT COUNT(*) FROM notifications WHERE ' . implode(' AND ', $aheadConditions));
        $derivedRankOffset = [];
        foreach ($derived as $index => $item) {
            $persistentAhead->execute($storedParameters + ['created_at' => $item['created_at']]);
            $derivedRankOffset[$index] = (int) $persistentAhead->fetchColumn() + $index;
        }

        $items = [];
        foreach ($stored->fetchAll() as $row) {
            $rank = (int) $row['newer_count'];
            if ($rank < $pagination['offset'] || $rank >= $pagination['offset'] + $pagination['page_size']) {
                continue;
            }
            $items[] = [
                'id' => (int) $row['id'],
                'key' => null,
                'title' => (string) $row['title'],
                'message' => (string) $row['message'],
                'created_at' => (string) $row['created_at'],
                'read_at' => $row['read_at'],
                'derived' => false,
                'href' => self::notificationHref($database, $user, $row),
                '_rank' => $rank,
            ];
        }
        foreach ($derived as $index => $item) {
            $rank = $derivedRankOffset[$index];
            if ($rank >= $pagination['offset'] && $rank < $pagination['offset'] + $pagination['page_size']) {
                $item['_rank'] = $rank;
                $items[] = $item;
            }
        }
        usort($items, static fn (array $left, array $right): int => ($left['_rank'] <=> $right['_rank']));
        foreach ($items as &$item) {
            unset($item['_rank']);
        }
        unset($item);

        $filters = ['q' => $search, 'status' => $readFilter];
        $pagination['base_url'] = '/notifications';
        $pagination['target'] = 'notification-results';
        $pagination['query'] = array_filter($filters, static fn (string $value): bool => $value !== '');

        return ['items' => $items, 'pagination' => $pagination, 'filters' => $filters];
    }

    public static function markRead(PDO $database, array $user, int $notificationId): bool
    {
        if ($notificationId < 1 || (int) ($user['id'] ?? 0) < 1) {
            return false;
        }
        $statement = $database->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE id = :id AND user_id = :user_id AND read_at IS NULL');
        $statement->execute(['id' => $notificationId, 'user_id' => $user['id']]);

        return $statement->rowCount() === 1;
    }

    public static function markAllRead(PDO $database, array $user): int
    {
        if ((int) ($user['id'] ?? 0) < 1) {
            return 0;
        }
        $statement = $database->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = :user_id AND read_at IS NULL');
        $statement->execute(['user_id' => $user['id']]);

        return $statement->rowCount();
    }

    public static function dismissDerived(PDO $database, array $user, string $key, array &$dismissed): bool
    {
        if (!preg_match('/^(?:student|reviewer|admin):[a-z0-9:_-]{1,180}$/', $key)) {
            return false;
        }
        foreach (self::derivedAlerts($database, $user) as $item) {
            if ($item['key'] === $key) {
                if (!in_array($key, $dismissed, true)) {
                    $dismissed[] = $key;
                }
                return true;
            }
        }

        return false;
    }

    public static function dismissAllDerived(PDO $database, array $user, array &$dismissed): int
    {
        $count = 0;
        foreach (self::derivedAlerts($database, $user) as $item) {
            if (!in_array($item['key'], $dismissed, true)) {
                $dismissed[] = $item['key'];
                $count++;
            }
        }
        $activeKeys = array_column(self::derivedAlerts($database, $user), 'key');
        $dismissed = array_values(array_intersect(array_unique($dismissed), $activeKeys));

        return $count;
    }

    private static function notificationHref(PDO $database, array $user, array $notification): string
    {
        $relatedType = (string) ($notification['related_type'] ?? '');
        $relatedId = (int) ($notification['related_id'] ?? 0);
        $role = (string) $user['role'];
        if ($relatedType === 'application' && $relatedId > 0) {
            return self::applicationHref($database, $user, $relatedId);
        }
        if ($relatedType === 'application_document' && $relatedId > 0) {
            if ($role === 'student') {
                $document = $database->prepare('SELECT a.id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = :document_id AND d.is_current = 1 AND a.student_id = :student_id AND a.deleted_at IS NULL LIMIT 1');
                $document->execute(['document_id' => $relatedId, 'student_id' => (int) $user['id']]);
                $applicationId = (int) $document->fetchColumn();
                return $applicationId > 0 ? self::applicationHref($database, $user, $applicationId, 'required-documents-title') : '/portal';
            }
            $document = StaffService::authorizedDocument($database, $user, $relatedId);
            return is_array($document) ? self::applicationHref($database, $user, (int) $document['application_id']) : ($role === 'admin' ? '/admin' : '/staff');
        }
        if ($relatedType === 'interview' && $relatedId > 0) {
            $query = $database->prepare('SELECT application_id FROM interviews WHERE id = :id LIMIT 1');
            $query->execute(['id' => $relatedId]);
            $applicationId = (int) $query->fetchColumn();
            if ($applicationId > 0) {
                return self::applicationHref($database, $user, $applicationId, 'student-interviews-title');
            }
        }
        if (in_array($relatedType, ['user', 'account', 'profile'], true) && $relatedId === (int) ($user['id'] ?? 0)) {
            return '/settings/profile';
        }

        return match ($role) {
            'student' => '/portal',
            'admin' => '/admin',
            default => '/staff',
        };
    }

    private static function applicationHref(PDO $database, array $user, int $applicationId, string $studentFragment = 'application-status-title'): string
    {
        if ($user['role'] === 'student') {
            $query = $database->prepare('SELECT id, status FROM applications WHERE id = :id AND student_id = :user_id AND deleted_at IS NULL');
            $query->execute(['id' => $applicationId, 'user_id' => $user['id']]);
            $application = $query->fetch();
            if (!is_array($application)) {
                return '/portal';
            }
            return $application['status'] === 'draft'
                ? '/application?application_id=' . (int) $application['id'] . '&step=2'
                : '/portal?application_id=' . (int) $application['id'] . '#' . rawurlencode($studentFragment);
        }
        if ($user['role'] === 'admin') {
            $query = $database->prepare('SELECT id FROM applications WHERE id = :id AND deleted_at IS NULL AND status <> \'draft\' LIMIT 1');
            $query->execute(['id' => $applicationId]);
            return $query->fetchColumn() ? '/staff/application/' . $applicationId : '/admin';
        }
        $query = $database->prepare('SELECT a.id FROM applications a WHERE a.id = :id AND a.deleted_at IS NULL AND a.status <> \'draft\' AND (NOT EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.unassigned_at IS NULL) OR EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.reviewer_id = :reviewer_id AND assigned.unassigned_at IS NULL))');
        $query->execute(['id' => $applicationId, 'reviewer_id' => $user['id']]);

        return $query->fetchColumn() ? '/staff/application/' . $applicationId : '/staff';
    }

    private static function derivedAlerts(PDO $database, array $user): array
    {
        return match ($user['role']) {
            'student' => self::studentAlerts($database, (int) $user['id']),
            'reviewer' => self::reviewerAlerts($database, $user),
            'admin' => self::adminAlerts($database),
            default => [],
        };
    }

    private static function studentAlerts(PDO $database, int $userId): array
    {
        $alerts = [];
        $applicationQuery = $database->prepare('SELECT a.id, a.status, a.academic_program_id, a.academic_year_id, a.submitted_at, a.preferred_campus, a.previous_school, a.senior_high_strand, u.full_name, p.mobile_number, p.date_of_birth, p.address_line, p.municipality, p.province, p.emergency_contact_name, p.emergency_contact_number FROM applications a JOIN users u ON u.id = a.student_id LEFT JOIN student_profiles p ON p.user_id = a.student_id WHERE a.student_id = :user_id AND a.deleted_at IS NULL ORDER BY (a.status = \'draft\') DESC, a.updated_at DESC LIMIT 1');
        $applicationQuery->execute(['user_id' => $userId]);
        $application = $applicationQuery->fetch();
        if (!is_array($application)) {
            self::addAlert($alerts, 'student:start-application', 'Start your application', 'Your student account does not have an application yet.', '/application');
            return $alerts;
        }
        $applicationId = (int) $application['id'];
        if ($application['status'] === 'draft') {
            $required = ['mobile_number', 'date_of_birth', 'address_line', 'municipality', 'province', 'emergency_contact_name', 'emergency_contact_number', 'academic_program_id', 'academic_year_id', 'preferred_campus', 'previous_school', 'senior_high_strand'];
            $missing = array_values(array_filter($required, static fn (string $field): bool => trim((string) ($application[$field] ?? '')) === ''));
            if (trim((string) ($application['full_name'] ?? '')) === '') {
                $missing[] = 'full_name';
            }
            if ($missing !== []) {
                self::addAlert($alerts, 'student:missing-fields:' . $applicationId . ':' . substr(hash('sha256', implode(',', $missing)), 0, 12), 'Complete your application details', 'Some required applicant or academic details are still missing.', '/settings/profile?complete_application=1');
            }
            if (!empty($application['academic_year_id']) && !empty($application['academic_program_id'])) {
                $missingDocs = self::missingDocuments($database, $application);
                if ($missingDocs !== []) {
                    $docIds = implode(',', array_column($missingDocs, 'id'));
                    self::addAlert($alerts, 'student:missing-documents:' . $applicationId . ':' . substr(hash('sha256', $docIds), 0, 12), 'Required documents missing', count($missingDocs) . ' required document' . (count($missingDocs) === 1 ? ' is' : 's are') . ' not uploaded.', '/application?application_id=' . $applicationId . '&step=2');
                }
            }
            $applicationDeadline = $database->prepare('SELECT label, application_deadline FROM academic_years WHERE id = :year_id AND is_open = 1');
            $applicationDeadline->execute(['year_id' => $application['academic_year_id']]);
            $openIntake = $applicationDeadline->fetch();
            if (is_array($openIntake) && !empty($openIntake['application_deadline']) && $openIntake['application_deadline'] >= gmdate('Y-m-d') && $openIntake['application_deadline'] <= gmdate('Y-m-d', time() + 14 * 86400)) {
                self::addAlert($alerts, 'student:application-deadline:' . $applicationId . ':' . $openIntake['application_deadline'], 'Application deadline approaching', 'The configured deadline for your open intake is within 14 days.', '/application?application_id=' . $applicationId);
            }
        }
        $needsCorrection = $database->prepare('SELECT d.id FROM application_documents d WHERE d.application_id = :application_id AND d.is_current = 1 AND d.review_status IN (\'rejected\', \'action_required\') ORDER BY d.id');
        $needsCorrection->execute(['application_id' => $applicationId]);
        $documentIds = array_map('intval', $needsCorrection->fetchAll(PDO::FETCH_COLUMN));
        if ($documentIds !== []) {
            self::addAlert($alerts, 'student:document-corrections:' . $applicationId . ':' . substr(hash('sha256', implode(',', $documentIds)), 0, 12), 'Document update required', 'One or more documents need correction or replacement.', '/portal?application_id=' . $applicationId . '#required-documents-title');
        }
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
        $interviews = $database->prepare('SELECT i.id, i.starts_at_utc FROM interviews i WHERE i.application_id = :application_id AND i.status = \'scheduled\' AND i.starts_at_utc >= :now AND i.starts_at_utc < :future ORDER BY i.starts_at_utc LIMIT 3');
        $interviews->execute(['application_id' => $applicationId, 'now' => $now, 'future' => $future]);
        foreach ($interviews->fetchAll() as $interview) {
            self::addAlert($alerts, 'student:interview:' . (int) $interview['id'], 'Upcoming interview', 'You have an interview scheduled within the next 7 days.', '/portal?application_id=' . $applicationId . '#student-interviews-title', (string) $interview['starts_at_utc']);
        }
        if ($application['status'] === 'accepted') {
            $enrollment = $database->prepare('SELECT status FROM enrollment_records WHERE application_id = :application_id LIMIT 1');
            $enrollment->execute(['application_id' => $applicationId]);
            $enrollmentStatus = $enrollment->fetchColumn();
            if ($enrollmentStatus !== false && in_array($enrollmentStatus, ['pending', 'in_progress'], true)) {
                self::addAlert($alerts, 'student:enrollment:' . $applicationId . ':' . $enrollmentStatus, 'Enrollment preparation in progress', 'Your accepted application has enrollment requirements still being prepared.', '/portal?application_id=' . $applicationId . '#application-status-title');
            }
        }

        return $alerts;
    }

    private static function reviewerAlerts(PDO $database, array $user): array
    {
        $alerts = [];
        $scope = '(NOT EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.unassigned_at IS NULL) OR EXISTS (SELECT 1 FROM reviewer_assignments mine WHERE mine.application_id = a.id AND mine.reviewer_id = :reviewer_scope AND mine.unassigned_at IS NULL))';
        $assigned = 'EXISTS (SELECT 1 FROM reviewer_assignments mine WHERE mine.application_id = a.id AND mine.reviewer_id = :reviewer_assigned AND mine.unassigned_at IS NULL)';
        $reviewerParameters = ['reviewer_scope' => $user['id']];
        $count = static function (string $predicate, array $parameters) use ($database, $scope, $reviewerParameters): int {
            $statement = $database->prepare('SELECT COUNT(*) FROM applications a WHERE a.deleted_at IS NULL AND a.status = \'submitted\' AND ' . $scope . ' AND ' . $predicate);
            $statement->execute($reviewerParameters + $parameters);
            return (int) $statement->fetchColumn();
        };
        $assignedCount = $count($assigned, ['reviewer_assigned' => $user['id']]);
        $unassignedCount = $count('NOT ' . $assigned, ['reviewer_assigned' => $user['id']]);
        if ($assignedCount > 0) {
            self::addAlert($alerts, 'reviewer:assigned-submitted:' . (int) $user['id'], 'Assigned applications awaiting review', $assignedCount . ' assigned submitted application' . ($assignedCount === 1 ? ' is' : 's are') . ' awaiting review.', '/staff/applications?status=submitted');
        }
        if ($unassignedCount > 0) {
            self::addAlert($alerts, 'reviewer:unassigned-submitted:' . (int) $user['id'], 'Unassigned applications awaiting review', $unassignedCount . ' unassigned submitted application' . ($unassignedCount === 1 ? ' is' : 's are') . ' available in your queue.', '/staff/applications?status=submitted');
        }
        $pending = $database->prepare('SELECT COUNT(*) FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND d.is_current = 1 AND d.review_status = \'pending\' AND ' . $scope);
        $pending->execute($reviewerParameters);
        $pendingCount = (int) $pending->fetchColumn();
        if ($pendingCount > 0) {
            self::addAlert($alerts, 'reviewer:documents-pending:' . (int) $user['id'], 'Documents awaiting review', $pendingCount . ' uploaded document' . ($pendingCount === 1 ? ' is' : 's are') . ' waiting for review.', '/staff/applications?documents=incomplete');
        }
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 7 * 86400);
        $interviews = $database->prepare('SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id WHERE a.deleted_at IS NULL AND a.status = \'interview_scheduled\' AND i.status = \'scheduled\' AND i.starts_at_utc >= :now AND i.starts_at_utc < :future AND ' . $scope);
        $interviews->execute(['now' => $now, 'future' => $future] + $reviewerParameters);
        $interviewCount = (int) $interviews->fetchColumn();
        if ($interviewCount > 0) {
            self::addAlert($alerts, 'reviewer:upcoming-interviews:' . (int) $user['id'], 'Upcoming interviews', $interviewCount . ' interview' . ($interviewCount === 1 ? ' is' : 's are') . ' scheduled within the next 7 days.', '/staff/applications?status=interview_scheduled');
        }
        $deadline = $database->prepare('SELECT COUNT(DISTINCT a.id) FROM applications a JOIN academic_years y ON y.id = a.academic_year_id WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND y.application_deadline >= :today AND y.application_deadline <= :soon AND ' . $scope);
        $deadline->execute(['today' => gmdate('Y-m-d'), 'soon' => gmdate('Y-m-d', time() + 7 * 86400)] + $reviewerParameters);
        if ((int) $deadline->fetchColumn() > 0) {
            self::addAlert($alerts, 'reviewer:deadlines:' . (int) $user['id'], 'Application deadlines approaching', (int) $deadline->fetchColumn() . ' visible application' . ((int) $deadline->fetchColumn() === 1 ? ' has' : 's have') . ' an intake deadline within 7 days.', '/staff/applications');
        }

        return $alerts;
    }

    private static function adminAlerts(PDO $database): array
    {
        $alerts = [];
        $attention = (int) $database->query("SELECT COUNT(*) FROM applications WHERE deleted_at IS NULL AND status = 'documents_incomplete'")->fetchColumn();
        if ($attention > 0) {
            self::addAlert($alerts, 'admin:applications-attention', 'Applications requiring attention', $attention . ' application' . ($attention === 1 ? ' needs' : 's need') . ' document follow-up.', '/admin/applications?status=documents_incomplete');
        }
        $enrollment = (int) $database->query("SELECT COUNT(*) FROM applications a JOIN enrollment_records er ON er.application_id = a.id WHERE a.deleted_at IS NULL AND a.status = 'accepted' AND er.status IN ('pending', 'in_progress')")->fetchColumn();
        if ($enrollment > 0) {
            self::addAlert($alerts, 'admin:enrollment-tasks', 'Enrollment tasks require action', $enrollment . ' accepted application' . ($enrollment === 1 ? ' has' : 's have') . ' enrollment preparation in progress.', '/admin/applications?status=accepted');
        }
        $deadline = $database->prepare('SELECT id, label, application_deadline FROM academic_years WHERE is_open = 1 AND application_deadline >= :today AND application_deadline <= :soon ORDER BY application_deadline LIMIT 1');
        $deadline->execute(['today' => gmdate('Y-m-d'), 'soon' => gmdate('Y-m-d', time() + 14 * 86400)]);
        $intake = $deadline->fetch();
        if (is_array($intake)) {
            self::addAlert($alerts, 'admin:intake-deadline:' . (int) $intake['id'] . ':' . $intake['application_deadline'], 'Admission deadline approaching', $intake['label'] . ' has a configured application deadline within 14 days.', '/staff/academic/years', $intake['application_deadline'] . ' 00:00:00');
        }

        return $alerts;
    }

    private static function missingDocuments(PDO $database, array $application): array
    {
        $statement = $database->prepare('SELECT r.id, r.name FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = :application_id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status <> \'rejected\') ORDER BY r.id');
        $statement->execute(['program_id' => $application['academic_program_id'], 'year_id' => $application['academic_year_id'], 'application_id' => $application['id']]);
        return $statement->fetchAll();
    }

    private static function addAlert(array &$alerts, string $key, string $title, string $message, string $href, ?string $createdAt = null): void
    {
        $alerts[] = [
            'id' => null,
            'key' => $key,
            'title' => $title,
            'message' => $message,
            'created_at' => $createdAt ?? gmdate('Y-m-d H:i:s'),
            'read_at' => null,
            'derived' => true,
            'href' => $href,
        ];
    }
}
