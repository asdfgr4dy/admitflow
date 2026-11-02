<?php
$configuration = [
    'programs' => ['title' => 'Programs', 'singular' => 'program', 'path' => '/staff/academic/programs', 'rows' => $programs],
    'years' => ['title' => 'Academic Years', 'singular' => 'academic year', 'path' => '/staff/academic/years', 'rows' => $years],
    'requirements' => ['title' => 'Requirements', 'singular' => 'requirement', 'path' => '/staff/academic/requirements', 'rows' => $requirements],
];
$page = $configuration[$managementType] ?? $configuration['programs'];
$pageFilters = $filters ?? [];
$rowTotal = count($page['rows']);
$pagination = $pagination ?? ['page' => 1, 'page_size' => 20, 'total' => $rowTotal, 'total_pages' => 1, 'from' => $rowTotal > 0 ? 1 : 0, 'to' => $rowTotal, 'page_key' => 'page', 'size_key' => 'per_page'];
$createFormValues = is_array($createFormValues ?? null) ? $createFormValues : [];
$createFormError = (string) ($createFormError ?? '');
$createFormType = (string) ($createFormType ?? '');
$totalRecordCount = (int) ($totalRecordCount ?? $pagination['total']);
$sort = (string) ($sort ?? 'name');
$direction = (string) ($direction ?? 'asc');
$programNames = array_column($programOptions, 'name', 'id');
$yearLabels = array_column($yearOptions, 'label', 'id');
$formIds = ['programs' => 'program_id', 'years' => 'year_id', 'requirements' => 'requirement_id'];
$formId = $formIds[$managementType];
$createModal = ['programs' => 'program', 'years' => 'year', 'requirements' => 'requirement'][$managementType];
$saveAction = ['programs' => '/staff/program/save', 'years' => '/staff/year/save', 'requirements' => '/staff/requirement/save'][$managementType];
$recordCount = (int) ($pagination['total'] ?? 0);
$isEditing = (int) ($createFormValues[$formId] ?? 0) > 0;
$updatedId = (int) ($_GET['updated_id'] ?? 0);
$activeFilterChips = [];
if (($pageFilters['program_q'] ?? '') !== '') $activeFilterChips[] = ['key' => 'program_q', 'label' => 'Search: ' . $pageFilters['program_q']];
if (($pageFilters['year_q'] ?? '') !== '') $activeFilterChips[] = ['key' => 'year_q', 'label' => 'Search: ' . $pageFilters['year_q']];
if (($pageFilters['requirement_q'] ?? '') !== '') $activeFilterChips[] = ['key' => 'requirement_q', 'label' => 'Search: ' . $pageFilters['requirement_q']];
if (($pageFilters['program_state'] ?? '') !== '') $activeFilterChips[] = ['key' => 'program_state', 'label' => 'Status: ' . ucfirst($pageFilters['program_state'])];
if (($pageFilters['year_state'] ?? '') !== '') $activeFilterChips[] = ['key' => 'year_state', 'label' => 'Admission status: ' . ucfirst($pageFilters['year_state'])];
if (($pageFilters['required'] ?? '') !== '') $activeFilterChips[] = ['key' => 'required', 'label' => 'Type: ' . ucfirst($pageFilters['required'])];
if (($pageFilters['requirement_state'] ?? '') !== '') $activeFilterChips[] = ['key' => 'requirement_state', 'label' => 'Status: ' . ucfirst($pageFilters['requirement_state'])];
if (($pageFilters['requirement_program'] ?? '') !== '') $activeFilterChips[] = ['key' => 'requirement_program', 'label' => 'Program: ' . ($programNames[$pageFilters['requirement_program']] ?? 'Selected program')];
if (($pageFilters['requirement_year'] ?? '') !== '') $activeFilterChips[] = ['key' => 'requirement_year', 'label' => 'Academic year: ' . ($yearLabels[$pageFilters['requirement_year']] ?? 'Selected year')];
$filterResetUrl = $page['path'] . '?' . http_build_query(['per_page' => (int) ($_GET['per_page'] ?? 20), 'sort' => $sort, 'direction' => $direction]);
$sortKeys = match ($managementType) {
    'programs' => ['name', 'applications', 'status', 'updated'],
    'years' => ['label', 'start', 'end', 'applications', 'status', 'updated'],
    default => ['name', 'type', 'program', 'year', 'status', 'updated'],
};
$sortUrl = static function (string $key) use ($page, $pageFilters, $sort, $direction): string {
    $params = array_filter($pageFilters, static fn (mixed $value): bool => $value !== '');
    $params['per_page'] = (string) ($_GET['per_page'] ?? 20);
    $params['sort'] = $key;
    $params['direction'] = $sort === $key && $direction === 'asc' ? 'desc' : 'asc';
    unset($params['page']);
    return $page['path'] . '?' . http_build_query($params);
};
$relativeDate = static function (?string $value): string {
    if ($value === null || $value === '') return 'Not recorded';
    try { $date = new DateTimeImmutable($value, new DateTimeZone('UTC')); } catch (Throwable) { return 'Not recorded'; }
    $seconds = max(0, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp() - $date->getTimestamp());
    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return (int) floor($seconds / 60) . ' min ago';
    if ($seconds < 86400) return (int) floor($seconds / 3600) . ' hr ago';
    if ($seconds < 2592000) return (int) floor($seconds / 86400) . ' days ago';
    if ($seconds < 31536000) return (int) floor($seconds / 2592000) . ' mo ago';
    return (int) floor($seconds / 31536000) . ' yr ago';
};
$errorFor = static function (string $field) use ($createFormError): string {
    $needles = match ($field) {
        'code' => ['code'], 'name' => ['name', 'label'], 'description' => ['description'],
        'starts_on' => ['date range'], 'ends_on' => ['date range'], 'allowed_extensions' => ['file type'],
        'max_file_size_bytes' => ['size limit'], default => [],
    };
    foreach ($needles as $needle) if ($createFormError !== '' && stripos($createFormError, $needle) !== false) return $createFormError;
    return '';
};
?>
<section class="staff-wrap container-xl admin-settings-page academic-management-page" data-management-type="<?= e($managementType) ?>">
    <div class="staff-heading academic-page-heading">
        <div><p class="eyebrow">Academic configuration</p><h1><?= e($page['title']) ?></h1><p><?= $managementType === 'programs' ? 'Manage the academic programs offered by the institution.' : ($managementType === 'years' ? 'Manage the academic years and admission periods of the institution.' : 'Manage the documents and other requirements for student admission.') ?></p></div>
        <a href="/admin" class="btn btn-outline-navy">Back to Dashboard</a>
    </div>

    <section class="academic-management-card" id="academic-records" aria-labelledby="academic-records-title">
        <div class="academic-toolbar-heading">
            <div><p class="eyebrow">Records</p><h2 id="academic-records-title"><?= number_format($recordCount) ?> of <?= number_format($totalRecordCount) ?> <?= e(strtolower($page['title'])) ?></h2></div>
            <button class="btn btn-amber" type="button" data-academic-add><i class="bi bi-plus-lg" aria-hidden="true"></i> Add <?= e($page['singular']) ?></button>
        </div>
        <form method="get" action="<?= e($page['path']) ?>" class="application-filters" data-auto-filter-form data-filter-target="academic-records">
            <input type="hidden" name="per_page" value="<?= (int) ($_GET['per_page'] ?? 20) ?>"><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="direction" value="<?= e($direction) ?>">
            <div class="application-primary-filters">
                <?php if ($managementType === 'programs'): ?>
                    <div class="application-filter search-field"><label for="program_q">Search programs</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="program_q" name="program_q" value="<?= e($pageFilters['program_q'] ?? '') ?>" placeholder="Program name or code" maxlength="160" autocomplete="off" data-filter-search></div></div>
                    <div class="application-filter"><label for="program_state">Status</label><select class="form-select" id="program_state" name="program_state" data-filter-change><option value="">All statuses</option><option value="active" <?= ($pageFilters['program_state'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($pageFilters['program_state'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                <?php elseif ($managementType === 'years'): ?>
                    <div class="application-filter search-field"><label for="year_q">Search academic years</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="year_q" name="year_q" value="<?= e($pageFilters['year_q'] ?? '') ?>" placeholder="Academic year" maxlength="160" autocomplete="off" data-filter-search></div></div>
                    <div class="application-filter"><label for="year_state">Admission status</label><select class="form-select" id="year_state" name="year_state" data-filter-change><option value="">All statuses</option><option value="open" <?= ($pageFilters['year_state'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option><option value="closed" <?= ($pageFilters['year_state'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
                <?php else: ?>
                    <div class="application-filter search-field"><label for="requirement_q">Search requirements</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="requirement_q" name="requirement_q" value="<?= e($pageFilters['requirement_q'] ?? '') ?>" placeholder="Name, code, or description" maxlength="160" autocomplete="off" data-filter-search></div></div>
                    <div class="application-filter"><label for="required">Type</label><select class="form-select" id="required" name="required" data-filter-change><option value="">Required and optional</option><option value="required" <?= ($pageFilters['required'] ?? '') === 'required' ? 'selected' : '' ?>>Required</option><option value="optional" <?= ($pageFilters['required'] ?? '') === 'optional' ? 'selected' : '' ?>>Optional</option></select></div>
                    <div class="application-filter"><label for="requirement_state">Status</label><select class="form-select" id="requirement_state" name="requirement_state" data-filter-change><option value="">All statuses</option><option value="active" <?= ($pageFilters['requirement_state'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= ($pageFilters['requirement_state'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div>
                    <div class="application-filter"><label for="requirement_program">Program</label><select class="form-select" id="requirement_program" name="requirement_program" data-filter-change><option value="">All programs</option><?php foreach ($programOptions as $option): ?><option value="<?= (int) $option['id'] ?>" <?= ($pageFilters['requirement_program'] ?? '') === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="application-filter"><label for="requirement_year">Academic year</label><select class="form-select" id="requirement_year" name="requirement_year" data-filter-change><option value="">All years</option><?php foreach ($yearOptions as $option): ?><option value="<?= (int) $option['id'] ?>" <?= ($pageFilters['requirement_year'] ?? '') === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
            </div>
            <?php require dirname(__DIR__) . '/partials/active-filter-chips.php'; ?>
            <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
        </form>

        <?php if ($page['rows'] === []): ?>
            <div class="academic-empty-state"><i class="bi bi-inbox" aria-hidden="true"></i><h3><?= $totalRecordCount > 0 ? 'No records match your filters' : 'No ' . e(strtolower($page['title'])) . ' yet' ?></h3><p><?= $totalRecordCount > 0 ? 'Clear filters to see all records.' : 'Add the first record to configure this part of admissions.' ?></p><?php if ($totalRecordCount === 0): ?><button class="btn btn-amber" type="button" data-academic-add>Add <?= e($page['singular']) ?></button><?php endif; ?></div>
        <?php else: ?>
            <div class="table-responsive staff-table-wrap academic-table-wrap">
                <table class="table staff-table academic-table"><thead><tr>
                    <?php if ($managementType === 'programs'): ?>
                        <th aria-sort="<?= $sort === 'name' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('name')) ?>">Program <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th>Description</th><th aria-sort="<?= $sort === 'applications' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('applications')) ?>">Applications <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'status' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('status')) ?>">Status <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'updated' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('updated')) ?>">Updated <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th>
                    <?php elseif ($managementType === 'years'): ?>
                        <th aria-sort="<?= $sort === 'label' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('label')) ?>">Academic Year <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'start' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('start')) ?>">Start Date <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'end' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('end')) ?>">End Date <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'applications' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('applications')) ?>">Applications <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th>Admission Status</th><th>Current Year</th><th aria-sort="<?= $sort === 'updated' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('updated')) ?>">Updated <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th>
                    <?php else: ?>
                        <th aria-sort="<?= $sort === 'name' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('name')) ?>">Requirement <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th>Description</th><th aria-sort="<?= $sort === 'type' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('type')) ?>">Type <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'program' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('program')) ?>">Program <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'year' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('year')) ?>">Academic Year <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th>File Rules</th><th aria-sort="<?= $sort === 'status' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('status')) ?>">Status <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th><th aria-sort="<?= $sort === 'updated' ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a href="<?= e($sortUrl('updated')) ?>">Updated <i class="bi bi-arrow-down-up" aria-hidden="true"></i></a></th>
                    <?php endif; ?><th>Actions</th>
                </tr></thead><tbody>
                <?php foreach ($page['rows'] as $row): $rowId = (int) $row['id']; $rowValues = $managementType === 'programs' ? ['program_id' => $rowId, 'code' => $row['code'], 'name' => $row['name'], 'description' => $row['description'] ?? '', 'is_active' => (int) $row['is_active'], 'created_at' => $row['created_at'] ?? '', 'updated_at' => $row['updated_at'] ?? ''] : ($managementType === 'years' ? ['year_id' => $rowId, 'label' => $row['label'], 'starts_on' => $row['starts_on'], 'ends_on' => $row['ends_on'], 'application_deadline' => $row['application_deadline'] ?? '', 'is_open' => (int) $row['is_open'], 'created_at' => $row['created_at'] ?? '', 'updated_at' => $row['updated_at'] ?? ''] : ['requirement_id' => $rowId, 'code' => $row['code'], 'name' => $row['name'], 'instructions' => $row['instructions'] ?? '', 'academic_program_id' => $row['academic_program_id'] ?? '', 'academic_year_id' => $row['academic_year_id'] ?? '', 'allowed_extensions' => $row['allowed_extensions'], 'max_file_size_bytes' => (int) $row['max_file_size_bytes'], 'is_required' => (int) $row['is_required'], 'is_active' => (int) $row['is_active'], 'created_at' => $row['created_at'] ?? '', 'updated_at' => $row['updated_at'] ?? '']); $recordJson = e(json_encode($rowValues, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?: '{}'); $updatedAt = (string) ($row['updated_at'] ?? $row['created_at'] ?? ''); ?>
                    <tr class="<?= $updatedId === $rowId ? 'is-recently-updated' : '' ?>">
                        <?php if ($managementType === 'programs'): ?>
                            <td><strong><?= e($row['name']) ?></strong><small><?= e($row['code']) ?></small></td><td><span class="academic-truncated" title="<?= e($row['description'] ?? '') ?>"><?= e(($row['description'] ?? '') !== '' ? $row['description'] : '—') ?></span></td><td><a href="/admin/applications?program_id=<?= $rowId ?>"><?= number_format((int) $row['application_count']) ?></a></td><td><span class="status-pill <?= $row['is_active'] ? 'status-accepted' : 'academic-status-inactive' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span></td><td><time title="<?= e($updatedAt ? date('M j, Y g:i A', strtotime($updatedAt . ' UTC')) . ' UTC' : '') ?>"><?= e($relativeDate($updatedAt)) ?></time></td>
                        <?php elseif ($managementType === 'years'): ?>
                            <td><strong><?= e($row['label']) ?></strong></td><td><time title="<?= e(date('M j, Y', strtotime($row['starts_on']))) ?>"><?= e(date('M j, Y', strtotime($row['starts_on']))) ?></time></td><td><time title="<?= e(date('M j, Y', strtotime($row['ends_on']))) ?>"><?= e(date('M j, Y', strtotime($row['ends_on']))) ?></time></td><td><a href="/admin/applications?year_id=<?= $rowId ?>"><?= number_format((int) $row['application_count']) ?></a></td><td><span class="status-pill <?= $row['is_open'] ? 'status-accepted' : 'academic-status-inactive' ?>"><?= $row['is_open'] ? 'Open' : 'Closed' ?></span></td><td><?= $row['is_open'] ? '<span class="status-pill status-accepted">Current</span>' : '<span class="text-muted">—</span>' ?></td><td><time title="<?= e($updatedAt ? date('M j, Y g:i A', strtotime($updatedAt . ' UTC')) . ' UTC' : '') ?>"><?= e($relativeDate($updatedAt)) ?></time></td>
                        <?php else: ?>
                            <td><strong><?= e($row['name']) ?></strong><small><?= e($row['code']) ?></small></td><td><span class="academic-truncated" title="<?= e($row['instructions'] ?? '') ?>"><?= e(($row['instructions'] ?? '') !== '' ? $row['instructions'] : '—') ?></span></td><td><span class="status-pill <?= $row['is_required'] ? 'status-submitted' : 'status-documents_incomplete' ?>"><?= $row['is_required'] ? 'Required' : 'Optional' ?></span></td><td><?= e($row['program_name'] ?? 'All programs') ?></td><td><?= e($row['year_label'] ?? 'All years') ?></td><td><?= e(strtoupper($row['allowed_extensions'])) ?><small><?= e(number_format((int) $row['max_file_size_bytes'] / 1048576, 1)) ?> MB max</small></td><td><span class="status-pill <?= $row['is_active'] ? 'status-accepted' : 'academic-status-inactive' ?>"><?= $row['is_active'] ? 'Active' : 'Inactive' ?></span><?php if ((int) $row['submitted_document_count'] > 0): ?><small><?= number_format((int) $row['submitted_document_count']) ?> submitted docs</small><?php endif; ?></td><td><time title="<?= e($updatedAt ? date('M j, Y g:i A', strtotime($updatedAt . ' UTC')) . ' UTC' : '') ?>"><?= e($relativeDate($updatedAt)) ?></time></td>
                        <?php endif; ?>
                        <td><div class="dropdown academic-row-actions"><button class="btn btn-sm btn-outline-navy" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions for <?= e($row['name'] ?? $row['label']) ?>"><i class="bi bi-three-dots" aria-hidden="true"></i></button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item" type="button" data-academic-edit data-record="<?= $recordJson ?>">Edit</button></li><?php if ($managementType === 'programs'): ?><li><button class="dropdown-item" type="button" data-academic-status data-record="<?= $recordJson ?>" data-status-field="is_active" data-status-value="<?= $row['is_active'] ? '0' : '1' ?>" data-confirm="<?= (int) $row['is_active'] && (int) $row['application_count'] > 0 ? e('This program has ' . number_format((int) $row['application_count']) . ' applications. Deactivating it hides it from new applications but keeps existing records. Continue? Select OK to Deactivate program.') : e(($row['is_active'] ? 'Deactivate' : 'Activate') . ' this program?') ?>"><?= $row['is_active'] ? 'Deactivate' : 'Activate' ?></button></li><li><a class="dropdown-item" href="/admin/applications?program_id=<?= $rowId ?>">View applications</a></li><?php elseif ($managementType === 'years'): ?><?php if ($row['is_open']): ?><li><button class="dropdown-item" type="button" data-academic-status data-record="<?= $recordJson ?>" data-status-field="is_open" data-status-value="0" data-confirm="<?= (int) $row['application_count'] > 0 ? e('This academic year has ' . number_format((int) $row['application_count']) . ' assigned applications. Closing it prevents new applications for this intake but preserves existing records. Continue?') : 'Close applications for this year?' ?>">Close admission period</button></li><?php else: ?><li><button class="dropdown-item" type="button" data-academic-status data-record="<?= $recordJson ?>" data-status-field="is_open" data-status-value="1" data-confirm="Set this academic year as the current intake? Existing applications keep their assigned year.">Set as current year</button></li><?php endif; ?><li><a class="dropdown-item" href="/admin/applications?year_id=<?= $rowId ?>">View applications</a></li><?php else: ?><li><button class="dropdown-item" type="button" data-academic-status data-record="<?= $recordJson ?>" data-status-field="is_active" data-status-value="<?= $row['is_active'] ? '0' : '1' ?>" data-confirm="<?= (int) $row['is_active'] && (int) $row['submitted_document_count'] > 0 ? e('This requirement has ' . number_format((int) $row['submitted_document_count']) . ' submitted documents. Deactivating it will not delete files or review history. Continue? Select OK to Deactivate requirement.') : e(($row['is_active'] ? 'Deactivate' : 'Activate') . ' this requirement?') ?>"><?= $row['is_active'] ? 'Deactivate' : 'Activate' ?></button></li><?php endif; ?></ul></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </div>
            <?php $pagination = array_merge($pagination, ['base_url' => $page['path'], 'query' => array_filter($pageFilters, static fn (mixed $value): bool => $value !== ''), 'page_key' => 'page', 'size_key' => 'per_page']); require dirname(__DIR__) . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
</section>

<?php ob_start(); ?>
            <?php if ($createFormError !== ''): ?><div class="alert alert-danger" role="alert" data-server-error><?= e($createFormError) ?></div><?php endif; ?>
            <?php if ($managementType === 'programs'): ?>
                <div class="academic-drawer-field"><label for="drawer_program_code">Program code</label><input class="form-control <?= $errorFor('code') !== '' ? 'is-invalid' : '' ?>" id="drawer_program_code" name="code" value="<?= e($createFormValues['code'] ?? '') ?>" maxlength="12" required data-uppercase><div class="invalid-feedback"><?= e($errorFor('code')) ?></div></div>
                <div class="academic-drawer-field"><label for="drawer_program_name">Program name</label><input class="form-control <?= $errorFor('name') !== '' ? 'is-invalid' : '' ?>" id="drawer_program_name" name="name" value="<?= e($createFormValues['name'] ?? '') ?>" maxlength="100" required><div class="invalid-feedback"><?= e($errorFor('name')) ?></div></div>
                <div class="academic-drawer-field"><label for="drawer_program_description">Description <span data-character-count="description">0 / 500</span></label><textarea class="form-control" id="drawer_program_description" name="description" maxlength="500" rows="4" data-character-input><?= e($createFormValues['description'] ?? '') ?></textarea><div class="invalid-feedback"><?= e($errorFor('description')) ?></div></div>
                <label class="settings-toggle academic-status-toggle"><input type="checkbox" name="is_active" value="1" <?= ($createFormValues['is_active'] ?? '1') === '1' ? 'checked' : '' ?>> Active</label>
            <?php elseif ($managementType === 'years'): ?>
                <div class="academic-drawer-field"><label for="drawer_year_label">Academic year name</label><input class="form-control <?= $errorFor('name') !== '' ? 'is-invalid' : '' ?>" id="drawer_year_label" name="label" value="<?= e($createFormValues['label'] ?? '') ?>" maxlength="20" placeholder="AY 2027-2028" required><div class="invalid-feedback"><?= e($errorFor('name')) ?></div></div>
                <div class="row g-3"><div class="col-6 academic-drawer-field"><label for="drawer_year_start">Start date</label><input class="form-control <?= $errorFor('starts_on') !== '' ? 'is-invalid' : '' ?>" id="drawer_year_start" name="starts_on" type="date" value="<?= e($createFormValues['starts_on'] ?? '') ?>" required><div class="invalid-feedback"><?= e($errorFor('starts_on')) ?></div></div><div class="col-6 academic-drawer-field"><label for="drawer_year_end">End date</label><input class="form-control <?= $errorFor('ends_on') !== '' ? 'is-invalid' : '' ?>" id="drawer_year_end" name="ends_on" type="date" value="<?= e($createFormValues['ends_on'] ?? '') ?>" required><div class="invalid-feedback"><?= e($errorFor('ends_on')) ?></div></div></div>
                <div class="academic-drawer-field"><label for="drawer_year_deadline">Application deadline <span class="text-muted fw-normal">Optional</span></label><input class="form-control" id="drawer_year_deadline" name="application_deadline" type="date" value="<?= e($createFormValues['application_deadline'] ?? '') ?>"></div>
                <label class="settings-toggle academic-status-toggle"><input type="checkbox" name="is_open" value="1" <?= ($createFormValues['is_open'] ?? '') === '1' ? 'checked' : '' ?>> Open applications · set as current intake</label>
            <?php else: ?>
                <div class="academic-drawer-field"><label for="drawer_requirement_code">Requirement code</label><input class="form-control <?= $errorFor('code') !== '' ? 'is-invalid' : '' ?>" id="drawer_requirement_code" name="code" value="<?= e($createFormValues['code'] ?? '') ?>" maxlength="40" required data-uppercase><div class="invalid-feedback"><?= e($errorFor('code')) ?></div></div>
                <div class="academic-drawer-field"><label for="drawer_requirement_name">Requirement name</label><input class="form-control <?= $errorFor('name') !== '' ? 'is-invalid' : '' ?>" id="drawer_requirement_name" name="name" value="<?= e($createFormValues['name'] ?? '') ?>" maxlength="160" required><div class="invalid-feedback"><?= e($errorFor('name')) ?></div></div>
                <div class="academic-drawer-field"><label for="drawer_requirement_description">Description <span data-character-count="instructions">0 / 1000</span></label><textarea class="form-control" id="drawer_requirement_description" name="instructions" maxlength="1000" rows="4" data-character-input><?= e($createFormValues['instructions'] ?? '') ?></textarea></div>
                <div class="row g-3"><div class="col-6 academic-drawer-field"><label for="drawer_requirement_program">Applicable program</label><select class="form-select" id="drawer_requirement_program" name="academic_program_id"><option value="">All programs</option><?php foreach ($programOptions as $option): ?><option value="<?= (int) $option['id'] ?>" data-available="<?= $option['is_active'] ? 'true' : 'false' ?>" <?= (string) ($createFormValues['academic_program_id'] ?? '') === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['name']) ?><?= $option['is_active'] ? '' : ' · Inactive' ?></option><?php endforeach; ?></select></div><div class="col-6 academic-drawer-field"><label for="drawer_requirement_year">Academic year</label><select class="form-select" id="drawer_requirement_year" name="academic_year_id"><option value="">All years</option><?php foreach ($yearOptions as $option): ?><option value="<?= (int) $option['id'] ?>" data-available="<?= $option['is_open'] ? 'true' : 'false' ?>" <?= (string) ($createFormValues['academic_year_id'] ?? '') === (string) $option['id'] ? 'selected' : '' ?>><?= e($option['label']) ?><?= $option['is_open'] ? '' : ' · Closed' ?></option><?php endforeach; ?></select></div></div>
                <div class="row g-3"><div class="col-6 academic-drawer-field"><label for="drawer_requirement_extensions">Accepted file types</label><input class="form-control <?= $errorFor('allowed_extensions') !== '' ? 'is-invalid' : '' ?>" id="drawer_requirement_extensions" name="allowed_extensions" value="<?= e($createFormValues['allowed_extensions'] ?? 'pdf,jpg,jpeg,png') ?>" required aria-describedby="accepted-types-hint"><small id="accepted-types-hint">Allowed: PDF, JPG, JPEG, PNG</small><div class="invalid-feedback"><?= e($errorFor('allowed_extensions')) ?></div></div><div class="col-6 academic-drawer-field"><label for="drawer_requirement_size">Maximum size (bytes)</label><input class="form-control <?= $errorFor('max_file_size_bytes') !== '' ? 'is-invalid' : '' ?>" id="drawer_requirement_size" name="max_file_size_bytes" type="number" min="1024" max="52428800" value="<?= e($createFormValues['max_file_size_bytes'] ?? '10485760') ?>" required><div class="invalid-feedback"><?= e($errorFor('max_file_size_bytes')) ?></div></div></div>
                <div class="d-flex flex-wrap gap-4"><label class="settings-toggle academic-status-toggle"><input type="checkbox" name="is_required" value="1" <?= ($createFormValues['is_required'] ?? '1') === '1' ? 'checked' : '' ?>> Required</label><label class="settings-toggle academic-status-toggle"><input type="checkbox" name="is_active" value="1" <?= ($createFormValues['is_active'] ?? '1') === '1' ? 'checked' : '' ?>> Active</label></div>
            <?php endif; ?>
            <div class="academic-record-metadata" data-drawer-metadata <?= $isEditing ? '' : 'hidden' ?>><span data-created-metadata><?= $isEditing && !empty($createFormValues['created_at']) ? 'Created ' . e($createFormValues['created_at']) : '' ?></span><span data-updated-metadata><?= $isEditing && !empty($createFormValues['updated_at']) ? 'Updated ' . e($createFormValues['updated_at']) : '' ?></span></div>
<?php
$drawerContent = (string) ob_get_clean();
$drawerConfig = [
    'id' => 'academicRecordDrawer',
    'title_id' => 'academicRecordDrawerTitle',
    'record_label' => $page['singular'],
    'header_label' => 'Academic configuration',
    'title' => ($isEditing ? 'Edit ' : 'Add ') . $page['singular'],
    'subtitle' => (string) ($isEditing ? ($createFormValues['name'] ?? $createFormValues['label'] ?? 'Update record details') : 'Enter the record details below.'),
    'action' => $saveAction,
    'open' => $createFormType === $createModal,
    'editing' => $isEditing,
    'id_field' => $formId,
    'record_id' => (int) ($createFormValues[$formId] ?? 0),
    'save_label' => $isEditing ? 'Save changes' : 'Add ' . $page['singular'],
    'class' => 'academic-drawer',
];
require dirname(__DIR__) . '/components/form-drawer.php';