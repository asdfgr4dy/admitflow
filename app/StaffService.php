<?php
declare(strict_types=1);

final class StaffService
{
    public static function dashboard(PDO $database, array $user): array
    {
        self::requireStaff($user);
        $scope = self::applicationScope($user, 'a');
        $query = 'SELECT COUNT(*) AS total, SUM(a.status IN (\'submitted\', \'in_screening\')) AS awaiting, SUM(a.status = \'accepted\') AS accepted, SUM(a.status = \'enrolled\') AS enrolled, SUM(a.status = \'documents_incomplete\') AS incomplete FROM applications a WHERE a.deleted_at IS NULL AND a.status <> \'draft\'' . $scope;
        $statement = $database->prepare($query);
        if ($user['role'] !== 'admin') {
            $statement->execute(['reviewer_id' => $user['id']]);
        } else {
            $statement->execute();
        }
        $metrics = $statement->fetch() ?: [];
        $metrics = array_map(static fn ($value): int => (int) $value, $metrics);
        $metrics['acceptance_rate'] = $metrics['total'] > 0 ? round(($metrics['accepted'] / $metrics['total']) * 100, 1) : 0;
        $reviewScope = self::applicationScope($user, 'a');
        $reviewInterval = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? '(julianday(first_review.reviewed_at) - julianday(a.submitted_at)) * 24'
            : 'TIMESTAMPDIFF(MINUTE, a.submitted_at, first_review.reviewed_at) / 60';
        $reviewTime = $database->prepare('SELECT ROUND(AVG(' . $reviewInterval . '), 1) FROM applications a JOIN (SELECT application_id, MIN(created_at) AS reviewed_at FROM application_status_history WHERE to_status = \'in_screening\' GROUP BY application_id) first_review ON first_review.application_id = a.id WHERE a.deleted_at IS NULL AND a.submitted_at IS NOT NULL' . $reviewScope);
        if ($user['role'] !== 'admin') {
            $reviewTime->execute(['reviewer_id' => $user['id']]);
        } else {
            $reviewTime->execute();
        }
        $metrics['average_review_hours'] = $reviewTime->fetchColumn();

        $interviewScope = self::applicationScope($user, 'a');
        $nowExpression = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'UTC_TIMESTAMP()';
        $interviews = $database->prepare('SELECT COUNT(*) FROM interviews i JOIN applications a ON a.id = i.application_id WHERE a.deleted_at IS NULL AND a.status = \'interview_scheduled\' AND i.status = \'scheduled\' AND i.starts_at_utc >= ' . $nowExpression . $interviewScope);
        $interviews->execute($user['role'] === 'admin' ? [] : ['reviewer_id' => $user['id']]);
        $metrics['upcoming_interviews'] = (int) $interviews->fetchColumn();

        $enrollmentScope = self::applicationScope($user, 'a');
        $enrollment = $database->prepare('SELECT er.status, COUNT(*) AS total FROM enrollment_records er JOIN applications a ON a.id = er.application_id WHERE a.deleted_at IS NULL' . $enrollmentScope . ' GROUP BY er.status');
        $enrollment->execute($user['role'] === 'admin' ? [] : ['reviewer_id' => $user['id']]);
        $metrics['enrollment_pending'] = 0;
        $metrics['enrollment_in_progress'] = 0;
        $metrics['enrollment_complete'] = 0;
        foreach ($enrollment->fetchAll() as $row) {
            if ($row['status'] === 'pending') $metrics['enrollment_pending'] = (int) $row['total'];
            if ($row['status'] === 'in_progress') $metrics['enrollment_in_progress'] = (int) $row['total'];
            if ($row['status'] === 'complete') $metrics['enrollment_complete'] = (int) $row['total'];
        }

        return $metrics;
    }

    public static function charts(PDO $database, array $user): array
    {
        self::requireStaff($user);
        $scope = self::applicationScope($user, 'a');
        $driver = $database->getAttribute(PDO::ATTR_DRIVER_NAME);
        $monthExpression = static fn (string $column): string => $driver === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
        $window = self::chartWindow();
        $parameters = $user['role'] === 'admin' ? [] : ['reviewer_id' => $user['id']];

        $monthly = $database->prepare('SELECT ' . $monthExpression('a.submitted_at') . ' AS month_key, COUNT(*) AS total FROM applications a WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND a.submitted_at >= :period_start AND a.submitted_at < :period_end' . $scope . ' GROUP BY month_key');
        $monthly->execute(['period_start' => $window['start'], 'period_end' => $window['end']] + $parameters);
        $submittedByMonth = array_fill_keys($window['months'], 0);
        foreach ($monthly->fetchAll() as $row) {
            if (array_key_exists($row['month_key'], $submittedByMonth)) {
                $submittedByMonth[$row['month_key']] = (int) $row['total'];
            }
        }
        $monthlyRows = array_map(static fn (string $month): array => ['month_key' => $month, 'total' => $submittedByMonth[$month]], $window['months']);

        $firstReviewJoin = ' JOIN (SELECT application_id, MIN(created_at) AS reviewed_at FROM application_status_history WHERE to_status = \'in_screening\' GROUP BY application_id) first_review ON first_review.application_id = a.id';
        $reviewed = $database->prepare('SELECT ' . $monthExpression('first_review.reviewed_at') . ' AS month_key, COUNT(*) AS total FROM applications a' . $firstReviewJoin . ' WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND first_review.reviewed_at >= :period_start AND first_review.reviewed_at < :period_end' . $scope . ' GROUP BY month_key');
        $reviewed->execute(['period_start' => $window['start'], 'period_end' => $window['end']] + $parameters);
        $reviewedByMonth = array_fill_keys($window['months'], 0);
        foreach ($reviewed->fetchAll() as $row) {
            if (array_key_exists($row['month_key'], $reviewedByMonth)) {
                $reviewedByMonth[$row['month_key']] = (int) $row['total'];
            }
        }
        $submittedReviewedRows = array_map(static fn (string $month): array => [
            'month_key' => $month,
            'submitted' => $submittedByMonth[$month],
            'reviewed' => $reviewedByMonth[$month],
        ], $window['months']);

        $reviewInterval = $driver === 'sqlite'
            ? '(julianday(first_review.reviewed_at) - julianday(a.submitted_at)) * 24'
            : 'TIMESTAMPDIFF(MINUTE, a.submitted_at, first_review.reviewed_at) / 60';
        $reviewTimes = $database->prepare('SELECT ' . $monthExpression('first_review.reviewed_at') . ' AS month_key, ROUND(AVG(' . $reviewInterval . '), 1) AS average_hours FROM applications a' . $firstReviewJoin . ' WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND a.submitted_at IS NOT NULL AND first_review.reviewed_at >= a.submitted_at AND first_review.reviewed_at >= :period_start AND first_review.reviewed_at < :period_end' . $scope . ' GROUP BY month_key');
        $reviewTimes->execute(['period_start' => $window['start'], 'period_end' => $window['end']] + $parameters);
        $reviewHoursByMonth = array_fill_keys($window['months'], null);
        foreach ($reviewTimes->fetchAll() as $row) {
            if (array_key_exists($row['month_key'], $reviewHoursByMonth)) {
                $reviewHoursByMonth[$row['month_key']] = $row['average_hours'] === null ? null : (float) $row['average_hours'];
            }
        }
        $reviewTimeRows = array_map(static fn (string $month): array => [
            'month_key' => $month,
            'average_hours' => $reviewHoursByMonth[$month],
        ], $window['months']);

        $statuses = $database->prepare('SELECT a.status, COUNT(*) AS total FROM applications a WHERE a.deleted_at IS NULL AND a.status <> \'draft\'' . $scope . ' GROUP BY a.status ORDER BY a.status');
        $statuses->execute($parameters);

        $programs = $database->prepare('SELECT COALESCE(p.name, \'Not selected\') AS label, COUNT(*) AS total FROM applications a LEFT JOIN academic_programs p ON p.id = a.academic_program_id WHERE a.deleted_at IS NULL AND a.status <> \'draft\'' . $scope . ' GROUP BY p.id, p.name ORDER BY total DESC LIMIT 8');
        $programs->execute($parameters);

        $intake = $database->query('SELECT id, label, starts_on, ends_on, application_deadline FROM academic_years WHERE is_open = 1 ORDER BY starts_on DESC, id DESC LIMIT 1')->fetch();
        $intakeStages = [];
        if (is_array($intake)) {
            $intakeCounts = $database->prepare('SELECT a.status, COUNT(*) AS total FROM applications a WHERE a.deleted_at IS NULL AND a.status <> \'draft\' AND a.academic_year_id = :academic_year_id' . $scope . ' GROUP BY a.status');
            $intakeCounts->execute(['academic_year_id' => $intake['id']] + $parameters);
            $countsByStatus = [];
            foreach ($intakeCounts->fetchAll() as $row) {
                $countsByStatus[$row['status']] = (int) $row['total'];
            }
            foreach ([
                'Submitted' => ['submitted'],
                'Screening' => ['in_screening', 'documents_incomplete'],
                'Interview' => ['interview_scheduled'],
                'Accepted' => ['accepted'],
                'Declined' => ['declined'],
                'Enrolled' => ['enrolled'],
            ] as $label => $sourceStatuses) {
                $intakeStages[] = [
                    'label' => $label,
                    'total' => array_sum(array_map(static fn (string $status): int => $countsByStatus[$status] ?? 0, $sourceStatuses)),
                ];
            }
            $intake = [
                'label' => $intake['label'],
                'starts_on' => $intake['starts_on'],
                'ends_on' => $intake['ends_on'],
                'application_deadline' => $intake['application_deadline'],
            ];
        } else {
            $intake = null;
        }

        return [
            'monthly' => $monthlyRows,
            'statuses' => $statuses->fetchAll(),
            'programs' => $programs->fetchAll(),
            'submitted_reviewed' => $submittedReviewedRows,
            'intake_progress' => ['intake' => $intake, 'stages' => $intakeStages],
            'review_time' => $reviewTimeRows,
        ];
    }

    private static function chartWindow(): array
    {
        $currentMonth = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('first day of this month')->setTime(0, 0);
        $startMonth = $currentMonth->sub(new DateInterval('P11M'));
        $endMonth = $currentMonth->add(new DateInterval('P1M'));
        $months = [];
        for ($month = $startMonth; $month < $endMonth; $month = $month->add(new DateInterval('P1M'))) {
            $months[] = $month->format('Y-m');
        }

        return [
            'start' => $startMonth->format('Y-m-d H:i:s'),
            'end' => $endMonth->format('Y-m-d H:i:s'),
            'months' => $months,
        ];
    }

    public static function filters(PDO $database, array $user): array
    {
        self::requireStaff($user);
        return [
            'programs' => $database->query('SELECT id, name FROM academic_programs ORDER BY name')->fetchAll(),
            'years' => $database->query('SELECT id, label FROM academic_years ORDER BY starts_on DESC')->fetchAll(),
            'reviewers' => $database->query('SELECT id, full_name FROM users WHERE role = \'reviewer\' AND account_status = \'active\' ORDER BY full_name')->fetchAll(),
        ];
    }

    public static function academicSettings(PDO $database, array $user, array $input = []): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            throw new DomainException('Administrator access is required to view academic settings.');
        }
        $filters = [
            'program_q' => trim((string) ($input['program_q'] ?? '')),
            'program_state' => trim((string) ($input['program_state'] ?? '')),
            'year_q' => trim((string) ($input['year_q'] ?? '')),
            'year_state' => trim((string) ($input['year_state'] ?? '')),
            'requirement_q' => trim((string) ($input['requirement_q'] ?? '')),
            'requirement_state' => trim((string) ($input['requirement_state'] ?? '')),
            'required' => trim((string) ($input['required'] ?? '')),
            'requirement_program' => trim((string) ($input['requirement_program'] ?? '')),
            'requirement_year' => trim((string) ($input['requirement_year'] ?? '')),
        ];
        foreach (['program_q', 'year_q', 'requirement_q'] as $key) {
            if (mb_strlen($filters[$key]) > 160) throw new DomainException('Keep academic settings search under 160 characters.');
        }
        if (!in_array($filters['program_state'], ['', 'active', 'inactive'], true) || !in_array($filters['year_state'], ['', 'open', 'closed'], true) || !in_array($filters['requirement_state'], ['', 'active', 'inactive'], true) || !in_array($filters['required'], ['', 'required', 'optional'], true)) {
            throw new DomainException('Choose a valid academic settings filter.');
        }
        foreach (['requirement_program' => 'academic_programs', 'requirement_year' => 'academic_years'] as $key => $table) {
            if ($filters[$key] !== '' && (!ctype_digit($filters[$key]) || (int) $filters[$key] < 1)) throw new DomainException('Choose a valid requirement scope.');
            if ($filters[$key] !== '') {
                $exists = $database->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id');
                $exists->execute(['id' => (int) $filters[$key]]);
                if ((int) $exists->fetchColumn() === 0) throw new DomainException('Choose a valid requirement scope.');
            }
        }

        $programWhere = ['1 = 1'];
        $programParameters = [];
        if ($filters['program_q'] !== '') {
            $programWhere[] = '(code LIKE :code OR name LIKE :name)';
            $programParameters['code'] = '%' . $filters['program_q'] . '%';
            $programParameters['name'] = '%' . $filters['program_q'] . '%';
        }
        if ($filters['program_state'] !== '') {
            $programWhere[] = 'is_active = :active';
            $programParameters['active'] = $filters['program_state'] === 'active' ? 1 : 0;
        }
        $programs = $database->prepare('SELECT id, code, name, is_active FROM academic_programs WHERE ' . implode(' AND ', $programWhere) . ' ORDER BY name');
        $programs->execute($programParameters);

        $yearWhere = ['1 = 1'];
        $yearParameters = [];
        if ($filters['year_q'] !== '') {
            $yearWhere[] = 'label LIKE :label';
            $yearParameters['label'] = '%' . $filters['year_q'] . '%';
        }
        if ($filters['year_state'] !== '') {
            $yearWhere[] = 'is_open = :is_open';
            $yearParameters['is_open'] = $filters['year_state'] === 'open' ? 1 : 0;
        }
        $years = $database->prepare('SELECT id, label, starts_on, ends_on, application_deadline, is_open FROM academic_years WHERE ' . implode(' AND ', $yearWhere) . ' ORDER BY starts_on DESC');
        $years->execute($yearParameters);

        $requirementWhere = ['1 = 1'];
        $requirementParameters = [];
        if ($filters['requirement_q'] !== '') {
            $requirementWhere[] = '(r.code LIKE :code OR r.name LIKE :name OR COALESCE(r.instructions, \'\') LIKE :instructions)';
            $like = '%' . $filters['requirement_q'] . '%';
            $requirementParameters += ['code' => $like, 'name' => $like, 'instructions' => $like];
        }
        if ($filters['requirement_state'] !== '') {
            $requirementWhere[] = 'r.is_active = :requirement_active';
            $requirementParameters['requirement_active'] = $filters['requirement_state'] === 'active' ? 1 : 0;
        }
        if ($filters['required'] !== '') {
            $requirementWhere[] = 'r.is_required = :is_required';
            $requirementParameters['is_required'] = $filters['required'] === 'required' ? 1 : 0;
        }
        if ($filters['requirement_program'] !== '') {
            $requirementWhere[] = '(r.academic_program_id IS NULL OR r.academic_program_id = :scope_program)';
            $requirementParameters['scope_program'] = (int) $filters['requirement_program'];
        }
        if ($filters['requirement_year'] !== '') {
            $requirementWhere[] = '(r.academic_year_id IS NULL OR r.academic_year_id = :scope_year)';
            $requirementParameters['scope_year'] = (int) $filters['requirement_year'];
        }
        $requirements = $database->prepare('SELECT r.id, r.code, r.name, r.instructions, r.academic_program_id, r.academic_year_id, r.allowed_extensions, r.max_file_size_bytes, r.is_required, r.is_active FROM admission_requirements r WHERE ' . implode(' AND ', $requirementWhere) . ' ORDER BY r.sort_order, r.name');
        $requirements->execute($requirementParameters);

        return [
            'programs' => $programs->fetchAll(),
            'years' => $years->fetchAll(),
            'requirements' => $requirements->fetchAll(),
            'programOptions' => $database->query('SELECT id, code, name FROM academic_programs ORDER BY name')->fetchAll(),
            'yearOptions' => $database->query('SELECT id, label FROM academic_years ORDER BY starts_on DESC')->fetchAll(),
            'filters' => $filters,
        ];
    }

    public static function applications(PDO $database, array $user, array $input, bool $allRows = false, bool $paginateAllRows = false): array
    {
        self::requireStaff($user);
        $where = ["a.deleted_at IS NULL", "a.status <> 'draft'"];
        $parameters = [];
        if ($user['role'] !== 'admin') {
            $where[] = '(active_assignment.reviewer_id = :queue_reviewer_id OR active_assignment.reviewer_id IS NULL)';
            $parameters['queue_reviewer_id'] = $user['id'];
        }
        $search = trim((string) ($input['q'] ?? ''));
        if (mb_strlen($search) > 160) {
            throw new DomainException('Keep the application search under 160 characters.');
        }
        if ($search !== '') {
            $where[] = '(u.full_name LIKE :search_name OR a.reference_number LIKE :search_ref OR p.name LIKE :search_program)';
            $like = '%' . $search . '%';
            $parameters['search_name'] = $like;
            $parameters['search_ref'] = $like;
            $parameters['search_program'] = $like;
        }
        $validStatuses = ['submitted', 'in_screening', 'documents_incomplete', 'interview_scheduled', 'accepted', 'declined', 'enrolled'];
        foreach (['status' => 'a.status', 'program_id' => 'a.academic_program_id', 'year_id' => 'a.academic_year_id'] as $key => $column) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '' && ($key === 'status' ? !in_array($value, $validStatuses, true) : !ctype_digit($value) || (int) $value < 1)) {
                throw new DomainException('One of the application report filters is invalid.');
            }
            if ($value !== '') {
                $where[] = $column . ' = :' . $key;
                $parameters[$key] = $key === 'status' ? $value : (int) $value;
                if ($key === 'program_id' || $key === 'year_id') {
                    $table = $key === 'program_id' ? 'academic_programs' : 'academic_years';
                    $exists = $database->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE id = :id');
                    $exists->execute(['id' => (int) $value]);
                    if ((int) $exists->fetchColumn() === 0) {
                        throw new DomainException('One of the application report filters is invalid.');
                    }
                }
            }
        }
        $dateFilters = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) {
                throw new DomainException('Enter valid report dates using year-month-day format.');
            }
            if ($value !== '') {
                if ($boundary === 'end') {
                    $date = $date->add(new DateInterval('P1D'));
                }
                $parameter = $key . '_utc';
                $where[] = 'a.submitted_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $parameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $dateFilters[$key] = $value;
            }
        }
        if (isset($dateFilters['start_date'], $dateFilters['end_date']) && $dateFilters['start_date'] > $dateFilters['end_date']) {
            throw new DomainException('The report start date must not be later than its end date.');
        }
        $reviewerId = trim((string) ($input['reviewer_id'] ?? ''));
        if ($reviewerId !== '' && (!ctype_digit($reviewerId) || (int) $reviewerId < 1)) {
            throw new DomainException('Choose a valid reviewer filter.');
        }
        if ($reviewerId !== '') {
            $reviewerCheck = $database->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND role = \'reviewer\' AND account_status = \'active\'');
            $reviewerCheck->execute(['id' => (int) $reviewerId]);
            if ((int) $reviewerCheck->fetchColumn() === 0) {
                throw new DomainException('Choose an active reviewer filter.');
            }
            $where[] = 'EXISTS (SELECT 1 FROM reviewer_assignments filter_assignment WHERE filter_assignment.application_id = a.id AND filter_assignment.reviewer_id = :filter_reviewer AND filter_assignment.unassigned_at IS NULL)';
            $parameters['filter_reviewer'] = (int) $reviewerId;
        }
        $completion = (string) ($input['documents'] ?? '');
        if ($completion !== '' && !in_array($completion, ['complete', 'incomplete'], true)) {
            throw new DomainException('Choose a valid document completion filter.');
        }
        if ($completion === 'complete') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = a.academic_program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = a.academic_year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = a.id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status = \'verified\'))';
        } elseif ($completion === 'incomplete') {
            $where[] = 'EXISTS (SELECT 1 FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = a.academic_program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = a.academic_year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = a.id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status = \'verified\'))';
        }

        $whereSql = implode(' AND ', $where);
        $joins = ' FROM applications a JOIN users u ON u.id = a.student_id LEFT JOIN academic_programs p ON p.id = a.academic_program_id LEFT JOIN academic_years y ON y.id = a.academic_year_id LEFT JOIN (SELECT assignment.application_id, assignment.reviewer_id FROM reviewer_assignments assignment JOIN (SELECT application_id, MAX(id) AS id FROM reviewer_assignments WHERE unassigned_at IS NULL GROUP BY application_id) latest ON latest.id = assignment.id) active_assignment ON active_assignment.application_id = a.id LEFT JOIN users reviewer ON reviewer.id = active_assignment.reviewer_id ';
        $countQuery = $database->prepare('SELECT COUNT(*)' . $joins . 'WHERE ' . $whereSql);
        $countQuery->execute($parameters);
        $total = (int) $countQuery->fetchColumn();
        $pagination = Pagination::make($total, $input, 'page', 'per_page', 20);
        if ($allRows && !$paginateAllRows) {
            $pagination = array_merge($pagination, ['page' => 1, 'page_size' => 5000, 'total_pages' => max(1, (int) ceil($total / 5000)), 'offset' => 0, 'from' => $total === 0 ? 0 : 1, 'to' => min($total, 5000)]);
            $pageSize = 5000;
        } else {
            $pageSize = $pagination['page_size'];
        }
        $query = 'SELECT a.id, a.reference_number, a.status, a.submitted_at, u.full_name, p.name AS program_name, y.label AS year_label, (SELECT COUNT(*) FROM application_documents d WHERE d.application_id = a.id AND d.is_current = 1) AS uploaded_documents, (SELECT COUNT(*) FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = a.academic_program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = a.academic_year_id)) AS required_documents, reviewer.full_name AS reviewer_name' . $joins . 'WHERE ' . $whereSql . ' ORDER BY a.submitted_at DESC, a.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $pagination['offset'];
        $statement = $database->prepare($query);
        $statement->execute($parameters);
        $summary = ['statuses' => [], 'programs' => [], 'years' => [], 'reviewed' => 0, 'average_review_hours' => null, 'documents_uploaded' => 0, 'documents_required' => 0];
        if ($allRows) {
            $summaryQuery = $database->prepare('SELECT a.status, COALESCE(p.name, \'Not selected\') AS program_name, COALESCE(y.label, \'Not selected\') AS year_label, COUNT(*) AS total' . $joins . 'WHERE ' . $whereSql . ' GROUP BY a.status, p.name, y.label');
            $summaryQuery->execute($parameters);
            foreach ($summaryQuery->fetchAll() as $row) {
                $summary['statuses'][$row['status']] = ($summary['statuses'][$row['status']] ?? 0) + (int) $row['total'];
                $summary['programs'][$row['program_name']] = ($summary['programs'][$row['program_name']] ?? 0) + (int) $row['total'];
                $summary['years'][$row['year_label']] = ($summary['years'][$row['year_label']] ?? 0) + (int) $row['total'];
            }
            $summary['reviewed'] = $total - ($summary['statuses']['submitted'] ?? 0);
            $documentTotals = $database->prepare('SELECT COALESCE(SUM((SELECT COUNT(*) FROM application_documents d WHERE d.application_id = a.id AND d.is_current = 1)), 0) AS documents_uploaded, COALESCE(SUM((SELECT COUNT(*) FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = a.academic_program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = a.academic_year_id))), 0) AS documents_required' . $joins . 'WHERE ' . $whereSql);
            $documentTotals->execute($parameters);
            $documentTotalsRow = $documentTotals->fetch() ?: [];
            $summary['documents_uploaded'] = (int) ($documentTotalsRow['documents_uploaded'] ?? 0);
            $summary['documents_required'] = (int) ($documentTotalsRow['documents_required'] ?? 0);
            $reviewInterval = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? '(julianday(first_review.reviewed_at) - julianday(a.submitted_at)) * 24'
                : 'TIMESTAMPDIFF(MINUTE, a.submitted_at, first_review.reviewed_at) / 60';
            $reviewQuery = $database->prepare('SELECT ROUND(AVG(' . $reviewInterval . '), 1)' . $joins . 'JOIN (SELECT application_id, MIN(created_at) AS reviewed_at FROM application_status_history WHERE to_status = \'in_screening\' GROUP BY application_id) first_review ON first_review.application_id = a.id WHERE ' . $whereSql . ' AND a.submitted_at IS NOT NULL');
            $reviewQuery->execute($parameters);
            $summary['average_review_hours'] = $reviewQuery->fetchColumn();
        }

        return ['rows' => $statement->fetchAll(), 'total' => $total, 'summary' => $summary, 'truncated' => $allRows && !$paginateAllRows && $total > $pageSize, 'page' => $pagination['page'], 'page_size' => $pageSize, 'pagination' => $pagination, 'filters' => $input];
    }

    public static function auditLog(PDO $database, array $input): array
    {
        $where = ['1 = 1'];
        $parameters = [];
        foreach (['outcome' => 'outcome', 'action' => 'action', 'entity_type' => 'entity_type'] as $key => $column) {
            $value = trim((string) ($input[$key] ?? ''));
            if ($value !== '' && preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $value)) {
                $where[] = $column . ' = :' . $key;
                $parameters[$key] = $value;
            }
        }
        $actor = trim((string) ($input['actor'] ?? ''));
        if ($actor !== '' && mb_strlen($actor) <= 160) {
            $where[] = 'actor_label LIKE :actor';
            $parameters['actor'] = '%' . $actor . '%';
        }
        $search = trim((string) ($input['q'] ?? ''));
        if ($search !== '' && mb_strlen($search) <= 160) {
            $where[] = '(actor_label LIKE :search_actor OR action LIKE :search_action OR COALESCE(entity_type, \'\') LIKE :search_entity)';
            $searchLike = '%' . $search . '%';
            $parameters['search_actor'] = $searchLike;
            $parameters['search_action'] = $searchLike;
            $parameters['search_entity'] = $searchLike;
        }
        $dateFilters = [];
        foreach (['start_date' => 'start', 'end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) {
                throw new DomainException('Enter valid audit dates using year-month-day format.');
            }
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $where[] = 'created_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $parameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $dateFilters[$key] = $value;
            }
        }
        if (isset($dateFilters['start_date'], $dateFilters['end_date']) && $dateFilters['start_date'] > $dateFilters['end_date']) {
            throw new DomainException('The audit start date must not be later than its end date.');
        }
        $whereSql = implode(' AND ', $where);
        $count = $database->prepare('SELECT COUNT(*) FROM audit_logs WHERE ' . $whereSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $pagination = Pagination::make($total, $input, 'page', 'per_page', 20);
        $query = 'SELECT id, user_id, actor_label, action, entity_type, entity_id, outcome, severity, ip_address, created_at FROM audit_logs WHERE ' . $whereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $pagination['page_size'] . ' OFFSET ' . $pagination['offset'];
        $statement = $database->prepare($query);
        $statement->execute($parameters);

        return ['rows' => $statement->fetchAll(), 'pagination' => $pagination, 'filters' => $input];
    }

    public static function recordReportExport(PDO $database, array $user, array $filters, int $rowCount): void
    {
        self::requireStaff($user);
        $database->beginTransaction();
        try {
            $statement = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, outcome, severity, ip_address, details) VALUES (:user_id, :actor, \'report.exported\', \'application_report\', \'success\', \'notice\', :ip, :details)');
            $statement->execute([
                'user_id' => $user['id'],
                'actor' => mb_substr($user['full_name'], 0, 160),
                'ip' => request_ip(),
                'details' => json_encode(['row_count' => $rowCount, 'filters' => array_intersect_key($filters, array_flip(['status', 'program_id', 'year_id', 'start_date', 'end_date', 'reviewer_id', 'documents']))], JSON_THROW_ON_ERROR),
            ]);
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function detail(PDO $database, array $user, int $applicationId, bool $lock = false, array $input = []): ?array
    {
        if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
            return null;
        }
        $scope = $user['role'] === 'admin' ? '' : ' AND (NOT EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.unassigned_at IS NULL) OR EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.reviewer_id = :reviewer_id AND assigned.unassigned_at IS NULL))';
        $statement = $database->prepare(
            'SELECT a.*, u.full_name, u.email, sp.mobile_number, sp.date_of_birth, sp.address_line, sp.municipality, sp.province, sp.emergency_contact_name, sp.emergency_contact_number, p.name AS program_name, y.label AS year_label FROM applications a JOIN users u ON u.id = a.student_id LEFT JOIN student_profiles sp ON sp.user_id = u.id LEFT JOIN academic_programs p ON p.id = a.academic_program_id LEFT JOIN academic_years y ON y.id = a.academic_year_id WHERE a.id = :application_id AND a.deleted_at IS NULL AND a.status <> \'draft\'' . $scope . ' LIMIT 1' . ($lock && $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $parameters = ['application_id' => $applicationId];
        if ($user['role'] !== 'admin') {
            $parameters['reviewer_id'] = $user['id'];
        }
        $statement->execute($parameters);
        $application = $statement->fetch();
        if (!is_array($application)) {
            return null;
        }

        $assignment = $database->prepare('SELECT u.id, u.full_name FROM reviewer_assignments ra JOIN users u ON u.id = ra.reviewer_id WHERE ra.application_id = :application_id AND ra.unassigned_at IS NULL ORDER BY ra.id DESC LIMIT 1');
        $assignment->execute(['application_id' => $applicationId]);
        $application['assigned_reviewer'] = $assignment->fetch() ?: null;

        $documents = $database->prepare('SELECT r.name, d.id AS document_id, d.original_name, d.review_status, d.uploaded_at, latest.feedback FROM admission_requirements r LEFT JOIN application_documents d ON d.requirement_id = r.id AND d.application_id = :application_id AND d.is_current = 1 LEFT JOIN (SELECT document_id, feedback FROM document_reviews WHERE id IN (SELECT MAX(id) FROM document_reviews GROUP BY document_id)) latest ON latest.document_id = d.id WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) ORDER BY r.sort_order, r.name');
        $documents->execute(['application_id' => $applicationId, 'program_id' => $application['academic_program_id'], 'year_id' => $application['academic_year_id']]);
        $application['documents'] = $documents->fetchAll();

        $documentHistoryFilters = ['document_history_q' => trim((string) ($input['document_history_q'] ?? '')), 'document_history_status' => trim((string) ($input['document_history_status'] ?? '')), 'document_history_start_date' => trim((string) ($input['document_history_start_date'] ?? '')), 'document_history_end_date' => trim((string) ($input['document_history_end_date'] ?? '')), 'document_history_date_range' => trim((string) ($input['document_history_date_range'] ?? ''))];
        if (mb_strlen($documentHistoryFilters['document_history_q']) > 180) throw new DomainException('Keep document history search under 180 characters.');
        if ($documentHistoryFilters['document_history_status'] !== '' && !in_array($documentHistoryFilters['document_history_status'], ['pending', 'verified', 'rejected', 'action_required'], true)) throw new DomainException('Choose a valid document history status.');
        $documentHistoryWhere = ['d.application_id = :application_id', 'd.is_current = 0'];
        $documentHistoryParameters = ['application_id' => $applicationId];
        if ($documentHistoryFilters['document_history_q'] !== '') {
            $documentHistoryWhere[] = '(d.original_name LIKE :document_name OR r.name LIKE :requirement_name)';
            $like = '%' . $documentHistoryFilters['document_history_q'] . '%';
            $documentHistoryParameters += ['document_name' => $like, 'requirement_name' => $like];
        }
        if ($documentHistoryFilters['document_history_status'] !== '') {
            $documentHistoryWhere[] = 'd.review_status = :document_history_status';
            $documentHistoryParameters['document_history_status'] = $documentHistoryFilters['document_history_status'];
        }
        $documentHistoryDates = [];
        foreach (['document_history_start_date' => 'start', 'document_history_end_date' => 'end'] as $key => $boundary) {
            $value = $documentHistoryFilters[$key];
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid document history dates.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $documentHistoryWhere[] = 'd.uploaded_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $documentHistoryParameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $documentHistoryDates[$key] = $value;
            }
        }
        if (isset($documentHistoryDates['document_history_start_date'], $documentHistoryDates['document_history_end_date']) && $documentHistoryDates['document_history_start_date'] > $documentHistoryDates['document_history_end_date']) throw new DomainException('The document history start date must not be later than its end date.');
        $documentHistoryWhereSql = implode(' AND ', $documentHistoryWhere);
        $historyDocumentCount = $database->prepare('SELECT COUNT(DISTINCT d.id) FROM application_documents d JOIN admission_requirements r ON r.id = d.requirement_id WHERE ' . $documentHistoryWhereSql);
        $historyDocumentCount->execute($documentHistoryParameters);
        $documentHistoryPagination = Pagination::make((int) $historyDocumentCount->fetchColumn(), $input, 'document_history_page', 'per_page', 20);
        $historyDocuments = $database->prepare('SELECT r.name AS requirement_name, d.id AS document_id, d.version, d.original_name, d.review_status, d.uploaded_at, review.decision, review.feedback, review.reviewed_at, reviewer.full_name AS reviewer_name FROM application_documents d JOIN admission_requirements r ON r.id = d.requirement_id LEFT JOIN document_reviews review ON review.id = (SELECT MAX(latest_review.id) FROM document_reviews latest_review WHERE latest_review.document_id = d.id) LEFT JOIN users reviewer ON reviewer.id = review.reviewer_id WHERE ' . $documentHistoryWhereSql . ' ORDER BY d.requirement_id, d.version DESC, review.reviewed_at DESC, d.id DESC LIMIT ' . $documentHistoryPagination['page_size'] . ' OFFSET ' . $documentHistoryPagination['offset']);
        $historyDocuments->execute($documentHistoryParameters);
        $application['document_history'] = $historyDocuments->fetchAll();
        $application['document_history_pagination'] = $documentHistoryPagination;
        $documentHistoryFilters['document_history_date_range'] = $documentHistoryFilters['document_history_date_range'] ?: (($documentHistoryDates !== []) ? 'custom' : '');
        $documentHistoryFilters['document_history_start_date'] = $documentHistoryDates['document_history_start_date'] ?? '';
        $documentHistoryFilters['document_history_end_date'] = $documentHistoryDates['document_history_end_date'] ?? '';
        $application['document_history_filters'] = $documentHistoryFilters;

        $notesFilters = ['notes_q' => trim((string) ($input['notes_q'] ?? '')), 'notes_start_date' => trim((string) ($input['notes_start_date'] ?? '')), 'notes_end_date' => trim((string) ($input['notes_end_date'] ?? '')), 'notes_date_range' => trim((string) ($input['notes_date_range'] ?? ''))];
        if (mb_strlen($notesFilters['notes_q']) > 180) throw new DomainException('Keep screening notes search under 180 characters.');
        $notesWhere = ['n.application_id = :application_id', 'n.visibility = \'staff_only\''];
        $notesParameters = ['application_id' => $applicationId];
        if ($notesFilters['notes_q'] !== '') {
            $notesWhere[] = '(n.note LIKE :note_search OR u.full_name LIKE :note_author)';
            $like = '%' . $notesFilters['notes_q'] . '%';
            $notesParameters += ['note_search' => $like, 'note_author' => $like];
        }
        $notesDates = [];
        foreach (['notes_start_date' => 'start', 'notes_end_date' => 'end'] as $key => $boundary) {
            $value = $notesFilters[$key];
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid screening note dates.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $notesWhere[] = 'n.created_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $notesParameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $notesDates[$key] = $value;
            }
        }
        if (isset($notesDates['notes_start_date'], $notesDates['notes_end_date']) && $notesDates['notes_start_date'] > $notesDates['notes_end_date']) throw new DomainException('The notes start date must not be later than its end date.');
        $notesWhereSql = implode(' AND ', $notesWhere);
        $noteCount = $database->prepare('SELECT COUNT(*) FROM screening_notes n JOIN users u ON u.id = n.author_id WHERE ' . $notesWhereSql);
        $noteCount->execute($notesParameters);
        $notesPagination = Pagination::make((int) $noteCount->fetchColumn(), $input, 'notes_page', 'per_page', 20);
        $notes = $database->prepare('SELECT n.note, n.created_at, u.full_name FROM screening_notes n JOIN users u ON u.id = n.author_id WHERE ' . $notesWhereSql . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $notesPagination['page_size'] . ' OFFSET ' . $notesPagination['offset']);
        $notes->execute($notesParameters);
        $application['notes'] = $notes->fetchAll();
        $application['notes_pagination'] = $notesPagination;
        $notesFilters['notes_date_range'] = $notesFilters['notes_date_range'] ?: (($notesDates !== []) ? 'custom' : '');
        $notesFilters['notes_start_date'] = $notesDates['notes_start_date'] ?? '';
        $notesFilters['notes_end_date'] = $notesDates['notes_end_date'] ?? '';
        $application['notes_filters'] = $notesFilters;

        $historyWhere = ['application_id = :application_id'];
        $historyParameters = ['application_id' => $applicationId];
        $historySearch = trim((string) ($input['history_q'] ?? ''));
        if (mb_strlen($historySearch) > 180) throw new DomainException('Keep status history search under 180 characters.');
        if ($historySearch !== '') {
            $historyWhere[] = 'COALESCE(note, \'\') LIKE :history_note';
            $historyParameters['history_note'] = '%' . $historySearch . '%';
        }
        $historyStatus = trim((string) ($input['history_status'] ?? ''));
        $validHistoryStatuses = ['submitted', 'in_screening', 'documents_incomplete', 'interview_scheduled', 'accepted', 'declined', 'enrolled'];
        if ($historyStatus !== '' && !in_array($historyStatus, $validHistoryStatuses, true)) throw new DomainException('Choose a valid application history status.');
        if ($historyStatus !== '') {
            $historyWhere[] = 'to_status = :history_status';
            $historyParameters['history_status'] = $historyStatus;
        }
        $historyDates = [];
        foreach (['history_start_date' => 'start', 'history_end_date' => 'end'] as $key => $boundary) {
            $value = trim((string) ($input[$key] ?? ''));
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid history dates using year-month-day format.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $historyWhere[] = 'created_at ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $historyParameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $historyDates[$key] = $value;
            }
        }
        if (isset($historyDates['history_start_date'], $historyDates['history_end_date']) && $historyDates['history_start_date'] > $historyDates['history_end_date']) throw new DomainException('The history start date must not be later than its end date.');
        $historyWhereSql = implode(' AND ', $historyWhere);
        $statusHistoryCount = $database->prepare('SELECT COUNT(*) FROM application_status_history WHERE ' . $historyWhereSql);
        $statusHistoryCount->execute($historyParameters);
        $statusHistoryPagination = Pagination::make((int) $statusHistoryCount->fetchColumn(), $input, 'history_page', 'per_page', 20);
        $history = $database->prepare('SELECT from_status, to_status, note, created_at FROM application_status_history WHERE ' . $historyWhereSql . ' ORDER BY created_at DESC, id DESC LIMIT ' . $statusHistoryPagination['page_size'] . ' OFFSET ' . $statusHistoryPagination['offset']);
        $history->execute($historyParameters);
        $application['history'] = $history->fetchAll();
        $application['history_pagination'] = $statusHistoryPagination;
        $application['history_filters'] = ['history_q' => $historySearch, 'history_status' => $historyStatus, 'history_start_date' => $historyDates['history_start_date'] ?? '', 'history_end_date' => $historyDates['history_end_date'] ?? '', 'history_date_range' => (string) ($input['history_date_range'] ?? '')];

        $interviewFilters = ['interviews_q' => trim((string) ($input['interviews_q'] ?? '')), 'interviews_status' => trim((string) ($input['interviews_status'] ?? '')), 'interviews_start_date' => trim((string) ($input['interviews_start_date'] ?? '')), 'interviews_end_date' => trim((string) ($input['interviews_end_date'] ?? '')), 'interviews_date_range' => trim((string) ($input['interviews_date_range'] ?? ''))];
        if (mb_strlen($interviewFilters['interviews_q']) > 180) throw new DomainException('Keep interview history search under 180 characters.');
        if ($interviewFilters['interviews_status'] !== '' && !in_array($interviewFilters['interviews_status'], ['scheduled', 'completed', 'cancelled', 'no_show'], true)) throw new DomainException('Choose a valid interview status.');
        $interviewWhere = ['i.application_id = :application_id'];
        $interviewParameters = ['application_id' => $applicationId];
        if ($interviewFilters['interviews_q'] !== '') {
            $interviewWhere[] = '(interviewer.full_name LIKE :interviewer_search OR COALESCE(i.instructions, \'\') LIKE :instructions_search OR COALESCE(i.notes, \'\') LIKE :interview_notes_search)';
            $like = '%' . $interviewFilters['interviews_q'] . '%';
            $interviewParameters += ['interviewer_search' => $like, 'instructions_search' => $like, 'interview_notes_search' => $like];
        }
        if ($interviewFilters['interviews_status'] !== '') {
            $interviewWhere[] = 'i.status = :interviews_status';
            $interviewParameters['interviews_status'] = $interviewFilters['interviews_status'];
        }
        $interviewDates = [];
        foreach (['interviews_start_date' => 'start', 'interviews_end_date' => 'end'] as $key => $boundary) {
            $value = $interviewFilters[$key];
            $date = $value === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Manila'));
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($value !== '' && ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value)) throw new DomainException('Enter valid interview dates.');
            if ($value !== '') {
                if ($boundary === 'end') $date = $date->add(new DateInterval('P1D'));
                $parameter = $key . '_utc';
                $interviewWhere[] = 'i.starts_at_utc ' . ($boundary === 'start' ? '>=' : '<') . ' :' . $parameter;
                $interviewParameters[$parameter] = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $interviewDates[$key] = $value;
            }
        }
        if (isset($interviewDates['interviews_start_date'], $interviewDates['interviews_end_date']) && $interviewDates['interviews_start_date'] > $interviewDates['interviews_end_date']) throw new DomainException('The interview start date must not be later than its end date.');
        $interviewWhereSql = implode(' AND ', $interviewWhere);
        $interviewFrom = ' FROM interviews i LEFT JOIN users interviewer ON interviewer.id = i.interviewer_id ';
        $interviewCount = $database->prepare('SELECT COUNT(*)' . $interviewFrom . 'WHERE ' . $interviewWhereSql);
        $interviewCount->execute($interviewParameters);
        $interviewPagination = Pagination::make((int) $interviewCount->fetchColumn(), $input, 'interviews_page', 'per_page', 20);
        $interviews = $database->prepare('SELECT i.id, i.interviewer_id, i.starts_at_utc, i.ends_at_utc, i.instructions, i.status, i.notes, interviewer.full_name AS interviewer_name' . $interviewFrom . 'WHERE ' . $interviewWhereSql . ' ORDER BY i.starts_at_utc DESC, i.id DESC LIMIT ' . $interviewPagination['page_size'] . ' OFFSET ' . $interviewPagination['offset']);
        $interviews->execute($interviewParameters);
        $application['interviews'] = $interviews->fetchAll();
        $application['interviews_pagination'] = $interviewPagination;
        $interviewFilters['interviews_date_range'] = $interviewFilters['interviews_date_range'] ?: (($interviewDates !== []) ? 'custom' : '');
        $interviewFilters['interviews_start_date'] = $interviewDates['interviews_start_date'] ?? '';
        $interviewFilters['interviews_end_date'] = $interviewDates['interviews_end_date'] ?? '';
        $application['interviews_filters'] = $interviewFilters;

        foreach (['document_history' => 'document_history_page', 'notes' => 'notes_page', 'history' => 'history_page', 'interviews' => 'interviews_page'] as $paginationKey => $pageKey) {
            $application[$paginationKey . '_pagination']['page_key'] = $pageKey;
            $application[$paginationKey . '_pagination']['base_url'] = '/staff/application/' . $applicationId;
            $application[$paginationKey . '_pagination']['query'] = array_intersect_key($input, array_flip(['document_history_page', 'notes_page', 'history_page', 'interviews_page', 'per_page', 'history_q', 'history_status', 'history_start_date', 'history_end_date', 'history_date_range', 'document_history_q', 'document_history_status', 'document_history_start_date', 'document_history_end_date', 'document_history_date_range', 'notes_q', 'notes_start_date', 'notes_end_date', 'notes_date_range', 'interviews_q', 'interviews_status', 'interviews_start_date', 'interviews_end_date', 'interviews_date_range']));
        }

        $enrollment = $database->prepare('SELECT id, status, checklist, completed_at FROM enrollment_records WHERE application_id = :application_id LIMIT 1');
        $enrollment->execute(['application_id' => $applicationId]);
        $application['enrollment'] = $enrollment->fetch() ?: null;

        return $application;
    }

    public static function reviewDocument(PDO $database, array $user, int $documentId, string $decision, string $feedback): void
    {
        if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
            throw new DomainException('Admissions staff access is required to review documents.');
        }
        if (!in_array($decision, ['verified', 'rejected', 'action_required'], true)) {
            throw new DomainException('Choose a valid document review outcome.');
        }
        if ($decision !== 'verified' && trim($feedback) === '') {
            throw new DomainException('Add a clear comment when a document needs changes.');
        }
        if (mb_strlen($feedback) > 5000) {
            throw new DomainException('Keep document feedback under 5,000 characters.');
        }

        $database->beginTransaction();
        try {
            $applicationIdQuery = $database->prepare('SELECT application_id FROM application_documents WHERE id = :document_id');
            $applicationIdQuery->execute(['document_id' => $documentId]);
            $applicationId = $applicationIdQuery->fetchColumn();
            if (!$applicationId) {
                throw new DomainException('That document is not available to your account.');
            }
            $lockApplication = $database->prepare('SELECT id FROM applications WHERE id = :application_id AND deleted_at IS NULL AND status <> \'draft\'' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lockApplication->execute(['application_id' => $applicationId]);
            if (!$lockApplication->fetchColumn()) {
                throw new DomainException('That document is not available to your account.');
            }
            $document = self::authorizedDocument($database, $user, $documentId, true);
            if ($document === null) {
                throw new DomainException('That document is not available to your account.');
            }
            $update = $database->prepare('UPDATE application_documents SET review_status = :decision WHERE id = :document_id AND is_current = 1');
            $update->execute(['decision' => $decision, 'document_id' => $documentId]);
            $review = $database->prepare('INSERT INTO document_reviews (document_id, reviewer_id, decision, feedback) VALUES (:document_id, :reviewer_id, :decision, :feedback)');
            $review->execute(['document_id' => $documentId, 'reviewer_id' => $user['id'], 'decision' => $decision, 'feedback' => $feedback ?: null]);
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, :type, :title, :message, \'application_document\', :document_id)');
            $notification->execute([
                'user_id' => $document['student_id'],
                'type' => 'document_' . $decision,
                'title' => $decision === 'verified' ? 'Document verified' : 'Document needs attention',
                'message' => $decision === 'verified' ? 'Your ' . $document['requirement_name'] . ' was verified.' : 'Please review the comment on your ' . $document['requirement_name'] . '.',
                'document_id' => $documentId,
            ]);
            if (in_array($decision, ['rejected', 'action_required'], true)) {
                $status = $database->prepare('SELECT status FROM applications WHERE id = :application_id' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $status->execute(['application_id' => $document['application_id']]);
                $currentStatus = $status->fetchColumn();
                if (in_array($currentStatus, ['submitted', 'in_screening', 'interview_scheduled'], true)) {
                    $move = $database->prepare('UPDATE applications SET status = \'documents_incomplete\' WHERE id = :application_id AND status = :current_status');
                    $move->execute(['application_id' => $document['application_id'], 'current_status' => $currentStatus]);
                    $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, :from_status, \'documents_incomplete\', :user_id, :note)');
                    $history->execute(['application_id' => $document['application_id'], 'from_status' => $currentStatus, 'user_id' => $user['id'], 'note' => 'A required document needs attention.']);
                }
            } else {
                $status = $database->prepare('SELECT status FROM applications WHERE id = :application_id' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $status->execute(['application_id' => $document['application_id']]);
                if ($status->fetchColumn() === 'documents_incomplete') {
                    $unverified = $database->prepare('SELECT COUNT(*) FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = :application_id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status = \'verified\')');
                    $unverified->execute(['program_id' => $document['academic_program_id'], 'year_id' => $document['academic_year_id'], 'application_id' => $document['application_id']]);
                    if ((int) $unverified->fetchColumn() === 0) {
                        $move = $database->prepare('UPDATE applications SET status = \'in_screening\' WHERE id = :application_id AND status = \'documents_incomplete\'');
                        $move->execute(['application_id' => $document['application_id']]);
                        if ($move->rowCount() === 1) {
                            $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, \'documents_incomplete\', \'in_screening\', :user_id, \'All required documents have passed review.\')');
                            $history->execute(['application_id' => $document['application_id'], 'user_id' => $user['id']]);
                            $statusNotification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'application_status\', \'Document review complete\', \'All required documents have passed review. Your application is back in screening.\', \'application\', :application_id)');
                            $statusNotification->execute(['user_id' => $document['student_id'], 'application_id' => $document['application_id']]);
                            self::audit($database, $user, 'application.status_changed', 'application', (int) $document['application_id'], 'success');
                        }
                    }
                }
            }
            self::audit($database, $user, 'document.reviewed', 'application_document', $documentId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }

    public static function changeStatus(PDO $database, array $user, int $applicationId, string $newStatus, string $note = ''): void
    {
        if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
            throw new DomainException('Admissions staff access is required to change application status.');
        }
        if (mb_strlen($note) > 500) {
            throw new DomainException('Keep the status note under 500 characters.');
        }
        if (!StatusTransitionPolicy::isKnownStatus($newStatus)) {
            throw new DomainException('Choose a valid application status.');
        }
        $database->beginTransaction();
        try {
            $application = self::detail($database, $user, $applicationId, true);
            if ($application === null) {
                throw new DomainException('That application is not available to your account.');
            }
            if (!StatusTransitionPolicy::allows($application['status'], $newStatus, $user['role'])) {
                if ($newStatus === 'enrolled' && $user['role'] !== 'admin') {
                    throw new DomainException('Administrator access is required to mark an application enrolled.');
                }
                throw new DomainException('That status change is not allowed from the current status.');
            }
            if ($newStatus === 'accepted') {
                $unverified = $database->prepare('SELECT COUNT(*) FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = :application_id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status = \'verified\')');
                $unverified->execute(['program_id' => $application['academic_program_id'], 'year_id' => $application['academic_year_id'], 'application_id' => $applicationId]);
                if ((int) $unverified->fetchColumn() > 0) {
                    throw new DomainException('Verify all configured required documents before accepting this application.');
                }
            }
            if ($newStatus === 'enrolled') {
                $enrollment = $database->prepare('SELECT status FROM enrollment_records WHERE application_id = :application_id' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
                $enrollment->execute(['application_id' => $applicationId]);
                if ($enrollment->fetchColumn() !== 'complete') {
                    throw new DomainException('Complete the enrollment checklist before marking the applicant enrolled.');
                }
            }

            $update = $database->prepare('UPDATE applications SET status = :status WHERE id = :application_id AND status = :old_status');
            $update->execute(['status' => $newStatus, 'application_id' => $applicationId, 'old_status' => $application['status']]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The application changed while you were reviewing it. Reload and try again.');
            }
            $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, :from_status, :to_status, :user_id, :note)');
            $history->execute(['application_id' => $applicationId, 'from_status' => $application['status'], 'to_status' => $newStatus, 'user_id' => $user['id'], 'note' => $note ?: null]);
            if ($newStatus === 'accepted') {
                $insertEnrollment = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                    ? 'INSERT IGNORE INTO enrollment_records (application_id, status) VALUES (:application_id, \'pending\')'
                    : 'INSERT INTO enrollment_records (application_id, status) VALUES (:application_id, \'pending\') ON CONFLICT(application_id) DO NOTHING';
                $enrollment = $database->prepare($insertEnrollment);
                $enrollment->execute(['application_id' => $applicationId]);
            }
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'application_status\', \'Application status updated\', :message, \'application\', :application_id)');
            $notification->execute(['user_id' => $application['student_id'], 'message' => 'Your application status is now ' . str_replace('_', ' ', $newStatus) . '.', 'application_id' => $applicationId]);
            self::audit($database, $user, 'application.status_changed', 'application', $applicationId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }

    public static function addNote(PDO $database, array $user, int $applicationId, string $note): void
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new DomainException('Enter a note of 1 to 500 characters.');
        }
        if (self::detail($database, $user, $applicationId) === null) {
            throw new DomainException('That application is not available to your account.');
        }
        $database->beginTransaction();
        try {
            $statement = $database->prepare('INSERT INTO screening_notes (application_id, author_id, note, visibility) VALUES (:application_id, :author_id, :note, \'staff_only\')');
            $statement->execute(['application_id' => $applicationId, 'author_id' => $user['id'], 'note' => $note]);
            self::audit($database, $user, 'application.note_added', 'application', $applicationId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function assignReviewer(PDO $database, array $user, int $applicationId, int $reviewerId): void
    {
        if ($user['role'] !== 'admin') {
            throw new DomainException('Administrator access is required to assign reviewers.');
        }
        if (self::detail($database, $user, $applicationId) === null) {
            throw new DomainException('That application is not available.');
        }
        $reviewerQuery = $database->prepare('SELECT id, full_name FROM users WHERE id = :id AND role = \'reviewer\' AND account_status = \'active\'');
        $reviewerQuery->execute(['id' => $reviewerId]);
        $reviewer = $reviewerQuery->fetch();
        if (!is_array($reviewer)) {
            throw new DomainException('Choose an active admissions reviewer.');
        }

        $database->beginTransaction();
        try {
            $unassign = $database->prepare('UPDATE reviewer_assignments SET unassigned_at = UTC_TIMESTAMP() WHERE application_id = :application_id AND unassigned_at IS NULL');
            $unassign->execute(['application_id' => $applicationId]);
            $assign = $database->prepare('INSERT INTO reviewer_assignments (application_id, reviewer_id, assigned_by) VALUES (:application_id, :reviewer_id, :assigned_by)');
            $assign->execute(['application_id' => $applicationId, 'reviewer_id' => $reviewerId, 'assigned_by' => $user['id']]);
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'review_assignment\', \'Application assigned\', :message, \'application\', :application_id)');
            $notification->execute(['user_id' => $reviewerId, 'message' => 'An application has been assigned to you for review.', 'application_id' => $applicationId]);
            self::audit($database, $user, 'application.reviewer_assigned', 'application', $applicationId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $error;
        }
    }

    public static function scheduleInterview(PDO $database, array $user, int $applicationId, int $interviewerId, string $startsLocal, string $endsLocal, string $instructions): void
    {
        self::requireStaff($user);
        $application = self::detail($database, $user, $applicationId);
        if ($application === null) {
            throw new DomainException('That application is not available to your account.');
        }
        if (!in_array($application['status'], ['in_screening', 'documents_incomplete'], true)) {
            throw new DomainException('Interviews can only be scheduled while an application is being screened.');
        }
        $manila = new DateTimeZone('Asia/Manila');
        $starts = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startsLocal, $manila);
        $ends = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $endsLocal, $manila);
        if (!$starts || $starts->format('Y-m-d\TH:i') !== $startsLocal || !$ends || $ends->format('Y-m-d\TH:i') !== $endsLocal || $ends <= $starts || $starts <= new DateTimeImmutable('now', $manila)) {
            throw new DomainException('Choose a valid future interview start and end time.');
        }
        if (mb_strlen($instructions) > 1000) {
            throw new DomainException('Interview instructions must be 1,000 characters or fewer.');
        }
        $reviewer = $database->prepare('SELECT id, full_name FROM users WHERE id = :id AND role IN (\'reviewer\', \'admin\') AND account_status = \'active\'');
        $reviewer->execute(['id' => $interviewerId]);
        $interviewer = $reviewer->fetch();
        if (!is_array($interviewer)) {
            throw new DomainException('Choose an active interviewer.');
        }

        $startsUtc = $starts->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endsUtc = $ends->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $database->beginTransaction();
        try {
            $lockedApplication = self::detail($database, $user, $applicationId, true);
            if ($lockedApplication === null) {
                throw new DomainException('That application is not available to your account.');
            }
            $lockedStatus = $lockedApplication['status'];
            if (!in_array($lockedStatus, ['in_screening', 'documents_incomplete'], true)) {
                throw new DomainException('The application changed and is no longer ready for an interview.');
            }
            if (!StatusTransitionPolicy::allows($lockedStatus, 'interview_scheduled', $user['role'])) {
                throw new DomainException('The application cannot be scheduled for an interview from its current status.');
            }
            $lockInterviewer = $database->prepare('SELECT id FROM users WHERE id = :id AND role IN (\'reviewer\', \'admin\') AND account_status = \'active\'' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $lockInterviewer->execute(['id' => $interviewerId]);
            if (!$lockInterviewer->fetchColumn()) {
                throw new DomainException('The interviewer account is no longer active.');
            }
            $conflictSql = 'SELECT id FROM interviews WHERE interviewer_id = :interviewer_id AND status = \'scheduled\' AND starts_at_utc < :ends_at AND ends_at_utc > :starts_at LIMIT 1' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
            $conflict = $database->prepare($conflictSql);
            $conflict->execute(['interviewer_id' => $interviewerId, 'ends_at' => $endsUtc, 'starts_at' => $startsUtc]);
            if ($conflict->fetchColumn()) {
                throw new DomainException('That interviewer already has an interview during this time. Choose another slot.');
            }

            $insert = $database->prepare('INSERT INTO interviews (application_id, interviewer_id, starts_at_utc, ends_at_utc, instructions, created_by) VALUES (:application_id, :interviewer_id, :starts_at, :ends_at, :instructions, :created_by)');
            $insert->execute(['application_id' => $applicationId, 'interviewer_id' => $interviewerId, 'starts_at' => $startsUtc, 'ends_at' => $endsUtc, 'instructions' => trim($instructions) ?: null, 'created_by' => $user['id']]);
            $interviewId = (int) $database->lastInsertId();

            $update = $database->prepare('UPDATE applications SET status = \'interview_scheduled\' WHERE id = :application_id AND status = :old_status');
            $update->execute(['application_id' => $applicationId, 'old_status' => $lockedStatus]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The application changed while the interview was being scheduled.');
            }
            $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, :from_status, \'interview_scheduled\', :user_id, \'An interview was scheduled.\')');
            $history->execute(['application_id' => $applicationId, 'from_status' => $lockedStatus, 'user_id' => $user['id']]);
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'interview_scheduled\', \'Interview scheduled\', :message, \'application\', :application_id)');
            $notification->execute(['user_id' => $lockedApplication['student_id'], 'message' => 'Your interview is scheduled for ' . $starts->format('F j, Y g:i A') . ' Asia/Manila. ' . trim($instructions), 'application_id' => $applicationId]);
            self::audit($database, $user, 'interview.scheduled', 'interview', $interviewId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function updateInterview(PDO $database, array $user, int $applicationId, int $interviewId, string $status, string $notes): void
    {
        self::requireStaff($user);
        if (!in_array($status, ['completed', 'cancelled', 'no_show'], true) || mb_strlen($notes) > 2000) {
            throw new DomainException('Choose a valid interview outcome and keep notes under 2,000 characters.');
        }
        if (self::detail($database, $user, $applicationId) === null) {
            throw new DomainException('That application is not available to your account.');
        }
        $database->beginTransaction();
        try {
            $application = self::detail($database, $user, $applicationId, true);
            if ($application === null) {
                throw new DomainException('That application is not available to your account.');
            }
            $activeReview = in_array($application['status'], ['interview_scheduled', 'documents_incomplete'], true);
            $validEnrolledInterview = false;
            if ($application['status'] === 'enrolled') {
                $scheduledInReview = $database->prepare('SELECT COUNT(*) FROM interviews i WHERE i.id = :interview_id AND i.application_id = :application_id AND i.status = \'scheduled\' AND EXISTS (SELECT 1 FROM audit_logs al WHERE al.entity_type = \'interview\' AND al.entity_id = i.id AND al.action = \'interview.scheduled\' AND al.outcome = \'success\') AND EXISTS (SELECT 1 FROM application_status_history h WHERE h.application_id = i.application_id AND h.to_status = \'interview_scheduled\' AND h.created_at <= i.created_at)');
                $scheduledInReview->execute(['interview_id' => $interviewId, 'application_id' => $applicationId]);
                $validEnrolledInterview = (int) $scheduledInReview->fetchColumn() === 1;
            }
            if (!$activeReview && !$validEnrolledInterview) {
                throw new DomainException('Interview outcomes can only be recorded while the interview remains part of the active review workflow.');
            }
            $update = $database->prepare('UPDATE interviews SET status = :status, notes = :notes WHERE id = :interview_id AND application_id = :application_id AND status = \'scheduled\'');
            $update->execute(['status' => $status, 'notes' => trim($notes) ?: null, 'interview_id' => $interviewId, 'application_id' => $applicationId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('That interview is no longer scheduled. Refresh the application and try again.');
            }
            $remainingScheduled = $database->prepare('SELECT COUNT(*) FROM interviews WHERE application_id = :application_id AND status = \'scheduled\'');
            $remainingScheduled->execute(['application_id' => $applicationId]);
            if ($status === 'cancelled' && $application['status'] === 'interview_scheduled' && (int) $remainingScheduled->fetchColumn() === 0) {
                if (!StatusTransitionPolicy::allows('interview_scheduled', 'in_screening', $user['role'])) {
                    throw new DomainException('The application cannot return to screening from its current state.');
                }
                $move = $database->prepare('UPDATE applications SET status = \'in_screening\' WHERE id = :application_id AND status = \'interview_scheduled\'');
                $move->execute(['application_id' => $applicationId]);
                if ($move->rowCount() !== 1) {
                    throw new DomainException('The application status changed while cancelling the interview.');
                }
                $history = $database->prepare('INSERT INTO application_status_history (application_id, from_status, to_status, changed_by, note) VALUES (:application_id, \'interview_scheduled\', \'in_screening\', :user_id, \'The scheduled interview was cancelled.\')');
                $history->execute(['application_id' => $applicationId, 'user_id' => $user['id']]);
                self::audit($database, $user, 'application.status_changed', 'application', $applicationId, 'success');
            }
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, :type, :title, :message, \'interview\', :interview_id)');
            $notification->execute([
                'user_id' => $application['student_id'],
                'type' => 'interview_' . $status,
                'title' => $status === 'cancelled' ? 'Interview cancelled' : 'Interview updated',
                'message' => $status === 'cancelled' ? 'Your interview has been cancelled. The Admission Office will update your application.' : 'Your interview status is now ' . str_replace('_', ' ', $status) . '.',
                'interview_id' => $interviewId,
            ]);
            self::audit($database, $user, 'interview.' . $status, 'interview', $interviewId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function rescheduleInterview(PDO $database, array $user, int $applicationId, int $interviewId, int $interviewerId, string $startsLocal, string $endsLocal, string $instructions): void
    {
        self::requireStaff($user);
        if (mb_strlen($instructions) > 1000) {
            throw new DomainException('Interview instructions must be 1,000 characters or fewer.');
        }
        $manila = new DateTimeZone('Asia/Manila');
        $starts = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $startsLocal, $manila);
        $ends = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $endsLocal, $manila);
        if (!$starts || $starts->format('Y-m-d\\TH:i') !== $startsLocal || !$ends || $ends->format('Y-m-d\\TH:i') !== $endsLocal || $ends <= $starts || $starts <= new DateTimeImmutable('now', $manila)) {
            throw new DomainException('Choose a valid future interview start and end time.');
        }
        $startsUtc = $starts->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endsUtc = $ends->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $database->beginTransaction();
        try {
            $application = self::detail($database, $user, $applicationId, true);
            if ($application === null || $application['status'] !== 'interview_scheduled') {
                throw new DomainException('This application is not available for interview changes.');
            }
            $interview = $database->prepare('SELECT id FROM interviews WHERE id = :interview_id AND application_id = :application_id AND status = \'scheduled\'' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $interview->execute(['interview_id' => $interviewId, 'application_id' => $applicationId]);
            if (!$interview->fetchColumn()) {
                throw new DomainException('That interview is no longer scheduled. Refresh and try again.');
            }
            $interviewer = $database->prepare('SELECT id FROM users WHERE id = :id AND role IN (\'reviewer\', \'admin\') AND account_status = \'active\'' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            $interviewer->execute(['id' => $interviewerId]);
            if (!$interviewer->fetchColumn()) {
                throw new DomainException('Choose an active admissions interviewer.');
            }
            $conflictSql = 'SELECT id FROM interviews WHERE interviewer_id = :interviewer_id AND status = \'scheduled\' AND id <> :interview_id AND starts_at_utc < :ends_at AND ends_at_utc > :starts_at LIMIT 1' . ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '');
            $conflict = $database->prepare($conflictSql);
            $conflict->execute(['interviewer_id' => $interviewerId, 'interview_id' => $interviewId, 'ends_at' => $endsUtc, 'starts_at' => $startsUtc]);
            if ($conflict->fetchColumn()) {
                throw new DomainException('That interviewer already has an interview during this time. Choose another slot.');
            }
            $update = $database->prepare('UPDATE interviews SET interviewer_id = :interviewer_id, starts_at_utc = :starts_at, ends_at_utc = :ends_at, instructions = :instructions WHERE id = :interview_id AND application_id = :application_id AND status = \'scheduled\'');
            $update->execute(['interviewer_id' => $interviewerId, 'starts_at' => $startsUtc, 'ends_at' => $endsUtc, 'instructions' => trim($instructions) ?: null, 'interview_id' => $interviewId, 'application_id' => $applicationId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('That interview changed while you were editing it. Refresh and try again.');
            }
            $notification = $database->prepare('INSERT INTO notifications (user_id, type, title, message, related_type, related_id) VALUES (:user_id, \'interview_rescheduled\', \'Interview rescheduled\', :message, \'interview\', :interview_id)');
            $notification->execute(['user_id' => $application['student_id'], 'message' => 'Your interview is rescheduled for ' . $starts->format('F j, Y g:i A') . ' Asia/Manila. ' . trim($instructions), 'interview_id' => $interviewId]);
            self::audit($database, $user, 'interview.rescheduled', 'interview', $interviewId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function updateEnrollmentChecklist(PDO $database, array $user, int $applicationId, array $items): void
    {
        if (($user['role'] ?? '') !== 'admin') {
            throw new DomainException('Administrator access is required to prepare enrollment.');
        }
        $allowedItems = ['offer_confirmed', 'documents_checked', 'instructions_sent', 'registration_ready'];
        foreach ($items as $item) {
            if (!is_string($item) || !in_array($item, $allowedItems, true)) {
                throw new DomainException('The enrollment checklist contains an invalid item.');
            }
        }
        $checklist = [];
        foreach ($allowedItems as $item) {
            $checklist[$item] = in_array($item, $items, true);
        }
        $isComplete = !in_array(false, $checklist, true);

        $database->beginTransaction();
        try {
            $application = self::detail($database, $user, $applicationId, true);
            if ($application === null || $application['status'] !== 'accepted') {
                throw new DomainException('Enrollment preparation is available only for accepted applications.');
            }
            $unverified = $database->prepare('SELECT COUNT(*) FROM admission_requirements r WHERE r.is_active = 1 AND r.is_required = 1 AND (r.academic_program_id IS NULL OR r.academic_program_id = :program_id) AND (r.academic_year_id IS NULL OR r.academic_year_id = :year_id) AND NOT EXISTS (SELECT 1 FROM application_documents d WHERE d.application_id = :application_id AND d.requirement_id = r.id AND d.is_current = 1 AND d.review_status = \'verified\')');
            $unverified->execute(['program_id' => $application['academic_program_id'], 'year_id' => $application['academic_year_id'], 'application_id' => $applicationId]);
            if ((int) $unverified->fetchColumn() > 0) {
                throw new DomainException('Verify all configured required documents before preparing enrollment.');
            }
            $saveSql = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                ? 'INSERT INTO enrollment_records (application_id, status, checklist, prepared_by, completed_at) VALUES (:application_id, :status, :checklist, :prepared_by, :completed_at) ON DUPLICATE KEY UPDATE status = VALUES(status), checklist = VALUES(checklist), prepared_by = VALUES(prepared_by), completed_at = VALUES(completed_at)'
                : 'INSERT INTO enrollment_records (application_id, status, checklist, prepared_by, completed_at) VALUES (:application_id, :status, :checklist, :prepared_by, :completed_at) ON CONFLICT(application_id) DO UPDATE SET status = excluded.status, checklist = excluded.checklist, prepared_by = excluded.prepared_by, completed_at = excluded.completed_at';
            $save = $database->prepare($saveSql);
            $save->execute(['application_id' => $applicationId, 'status' => $isComplete ? 'complete' : 'in_progress', 'checklist' => json_encode($checklist, JSON_THROW_ON_ERROR), 'prepared_by' => $user['id'], 'completed_at' => $isComplete ? gmdate('Y-m-d H:i:s') : null]);
            self::audit($database, $user, 'enrollment.checklist_updated', 'application', $applicationId, 'success');
            $database->commit();
        } catch (Throwable $error) {
            if ($database->inTransaction()) $database->rollBack();
            throw $error;
        }
    }

    public static function authorizedDocument(PDO $database, array $user, int $documentId, bool $lock = false, bool $includeHistorical = false): ?array
    {
        if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
            return null;
        }
        $scope = $user['role'] === 'admin' ? '' : ' AND (NOT EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.unassigned_at IS NULL) OR EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = a.id AND assigned.reviewer_id = :reviewer_id AND assigned.unassigned_at IS NULL))';
        $statement = $database->prepare(
            'SELECT d.id, d.storage_name, d.original_name, d.detected_mime_type, d.file_size_bytes, d.sha256, d.application_id, a.student_id, a.academic_program_id, a.academic_year_id, r.name AS requirement_name FROM application_documents d JOIN applications a ON a.id = d.application_id JOIN admission_requirements r ON r.id = d.requirement_id WHERE d.id = :document_id AND ' . ($includeHistorical ? '1 = 1' : 'd.is_current = 1') . ' AND a.deleted_at IS NULL AND a.status <> \'draft\'' . $scope . ($lock && $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $parameters = ['document_id' => $documentId];
        if ($user['role'] !== 'admin') {
            $parameters['reviewer_id'] = $user['id'];
        }
        $statement->execute($parameters);
        $document = $statement->fetch();

        return is_array($document) ? $document : null;
    }

    public static function recordDocumentPreviewAccess(PDO $database, array $user, int $documentId): bool
    {
        $document = self::authorizedDocument($database, $user, $documentId);
        if ($document === null) {
            return false;
        }
        self::audit($database, $user, 'document.previewed', 'application_document', $documentId, 'success');

        return true;
    }

    public static function documentPreviewContentType(string $storageName, string|false $detectedMime): ?string
    {
        $extension = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => null,
        };

        return $contentType !== null && $contentType === $detectedMime ? $contentType : null;
    }

    public static function documentPreviewIntegrityMatches(int|false $actualSize, int $expectedSize, string|false|null $actualHash, string $expectedHash): bool
    {
        return $actualSize !== false
            && $actualSize > 0
            && $actualSize === $expectedSize
            && is_string($actualHash)
            && preg_match('/^[a-f0-9]{64}$/i', $expectedHash) === 1
            && hash_equals(strtolower($expectedHash), $actualHash);
    }

    public static function documentPreviewContentLooksValid(string $filePath, string $contentType): bool
    {
        if (!is_file($filePath) || !is_readable($filePath) || filesize($filePath) < 1) {
            return false;
        }
        if ($contentType === 'application/pdf') {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                return false;
            }
            $header = fread($handle, min(1024, (int) filesize($filePath)));
            $fileSize = filesize($filePath);
            $tailLength = min(2048, (int) $fileSize);
            fseek($handle, -$tailLength, SEEK_END);
            $tail = fread($handle, $tailLength);
            fclose($handle);

            return is_string($header) && str_contains($header, '%PDF-') && is_string($tail) && str_contains($tail, '%%EOF');
        }
        if (in_array($contentType, ['image/jpeg', 'image/png'], true)) {
            $image = @getimagesize($filePath);
            return is_array($image) && ($image['mime'] ?? null) === $contentType && (int) ($image[0] ?? 0) > 0 && (int) ($image[1] ?? 0) > 0;
        }

        return false;
    }

    private static function audit(PDO $database, array $user, string $action, string $entityType, int $entityId, string $outcome): void
    {
        $statement = $database->prepare('INSERT INTO audit_logs (user_id, actor_label, action, entity_type, entity_id, outcome, ip_address) VALUES (:user_id, :actor, :action, :entity, :entity_id, :outcome, :ip)');
        $statement->execute([
            'user_id' => $user['id'],
            'actor' => mb_substr($user['full_name'], 0, 160),
            'action' => $action,
            'entity' => $entityType,
            'entity_id' => $entityId,
            'outcome' => $outcome,
            'ip' => request_ip(),
        ]);
    }

    private static function requireStaff(array $user): void
    {
        if (!in_array($user['role'] ?? '', ['reviewer', 'admin'], true)) {
            throw new DomainException('Admissions staff access is required.');
        }
    }

    private static function applicationScope(array $user, string $alias): string
    {
        return $user['role'] === 'admin' ? '' : ' AND (NOT EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = ' . $alias . '.id AND assigned.unassigned_at IS NULL) OR EXISTS (SELECT 1 FROM reviewer_assignments assigned WHERE assigned.application_id = ' . $alias . '.id AND assigned.reviewer_id = :reviewer_id AND assigned.unassigned_at IS NULL))';
    }
}