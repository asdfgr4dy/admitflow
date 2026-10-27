<?php
$applicationQueuePath = (string) ($applicationQueuePath ?? '/staff');
$filter = static fn (string $key): string => (string) ($applications['filters'][$key] ?? '');
$datePreset = $filter('date_range');
if (!in_array($datePreset, ['last7', 'last30', 'custom'], true)) {
    $datePreset = $filter('start_date') !== '' || $filter('end_date') !== '' ? 'custom' : '';
}
$programNames = array_column($programs, 'name', 'id');
$yearLabels = array_column($years, 'label', 'id');
$reviewerNames = array_column($reviewers, 'full_name', 'id');
$activeFilters = [];
if ($filter('q') !== '') $activeFilters[] = ['key' => 'q', 'label' => 'Search: ' . $filter('q')];
if ($filter('status') !== '') $activeFilters[] = ['key' => 'status', 'label' => 'Status: ' . ($statusLabels[$filter('status')] ?? $filter('status'))];
if ($filter('program_id') !== '') $activeFilters[] = ['key' => 'program_id', 'label' => 'Program: ' . ($programNames[$filter('program_id')] ?? 'Selected program')];
if ($filter('year_id') !== '') $activeFilters[] = ['key' => 'year_id', 'label' => 'Academic Year: ' . ($yearLabels[$filter('year_id')] ?? 'Selected year')];
if ($datePreset !== '') $activeFilters[] = ['key' => 'date_range', 'label' => 'Date: ' . ['last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'custom' => 'Custom range'][$datePreset]];
if ($filter('reviewer_id') !== '') $activeFilters[] = ['key' => 'reviewer_id', 'label' => 'Reviewer: ' . ($reviewerNames[$filter('reviewer_id')] ?? 'Selected reviewer')];
if ($filter('documents') !== '') $activeFilters[] = ['key' => 'documents', 'label' => 'Documents: ' . ($filter('documents') === 'complete' ? 'All verified' : 'Needs attention')];
$resetUrl = $applicationQueuePath . '?' . http_build_query(array_filter(['per_page' => (string) ($applications['pagination']['page_size'] ?? '')], static fn (string $value): bool => $value !== '')) . '#applications';
?>
<form method="get" action="<?= e($applicationQueuePath) ?>" class="application-filters" data-auto-filter-form data-filter-target="applications">
    <input type="hidden" name="per_page" value="<?= (int) ($applications['pagination']['page_size'] ?? 20) ?>">
    <div class="application-primary-filters">
        <div class="application-filter search-field"><label for="q">Search</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="q" name="q" value="<?= e($filter('q')) ?>" placeholder="Applicant, reference, or program" maxlength="160" autocomplete="off" data-filter-search></div></div>
        <div class="application-filter"><label for="status">Status</label><select class="form-select" id="status" name="status" data-filter-change><option value="">All statuses</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $filter('status') === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="application-filter"><label for="program_id">Program</label><select class="form-select" id="program_id" name="program_id" data-filter-change><option value="">All programs</option><?php foreach ($programs as $program): ?><option value="<?= (int) $program['id'] ?>" <?= $filter('program_id') === (string) $program['id'] ? 'selected' : '' ?>><?= e($program['name']) ?></option><?php endforeach; ?></select></div>
        <div class="application-filter"><label for="year_id">Academic Year</label><select class="form-select" id="year_id" name="year_id" data-filter-change><option value="">All years</option><?php foreach ($years as $year): ?><option value="<?= (int) $year['id'] ?>" <?= $filter('year_id') === (string) $year['id'] ? 'selected' : '' ?>><?= e($year['label']) ?></option><?php endforeach; ?></select></div>
        <div class="application-primary-actions"><button class="btn btn-outline-navy application-more-filters" type="button" aria-expanded="<?= count(array_filter([$datePreset, $filter('reviewer_id'), $filter('documents')])) > 0 ? 'true' : 'false' ?>" aria-controls="applicationAdvancedFilters" data-advanced-toggle><i class="bi bi-sliders" aria-hidden="true"></i> More Filters <span class="application-advanced-count" data-advanced-count><?= count(array_filter([$datePreset, $filter('reviewer_id'), $filter('documents')])) ?: '' ?></span></button></div>
    </div>

    <section class="application-advanced-panel" id="applicationAdvancedFilters" aria-labelledby="advancedFiltersHeading" data-advanced-panel <?= count(array_filter([$datePreset, $filter('reviewer_id'), $filter('documents')])) > 0 ? '' : 'hidden' ?>>
        <div class="application-advanced-heading"><h2 id="advancedFiltersHeading">Advanced Filters</h2><button class="btn btn-sm btn-outline-navy" type="button" aria-label="Close advanced filters" data-advanced-close><i class="bi bi-chevron-up" aria-hidden="true"></i> Close</button></div>
        <div class="application-advanced-grid">
                <div class="application-filter"><label for="date_range">Date Range <span class="application-filter-context">Submitted date</span></label><select class="form-select" id="date_range" name="date_range" data-date-preset><option value="">Any date</option><option value="last7" <?= $datePreset === 'last7' ? 'selected' : '' ?>>Last 7 days</option><option value="last30" <?= $datePreset === 'last30' ? 'selected' : '' ?>>Last 30 days</option><option value="custom" <?= $datePreset === 'custom' ? 'selected' : '' ?>>Custom range</option></select><div class="application-custom-dates" data-custom-range <?= $datePreset === 'custom' ? '' : 'hidden' ?>><div><label class="visually-hidden" for="start_date">Start date</label><input class="form-control" id="start_date" name="start_date" type="date" value="<?= e($filter('start_date')) ?>" aria-label="Start date" data-range-start></div><span aria-hidden="true">to</span><div><label class="visually-hidden" for="end_date">End date</label><input class="form-control" id="end_date" name="end_date" type="date" value="<?= e($filter('end_date')) ?>" aria-label="End date" data-range-end></div><small>Dates use Asia/Manila calendar days; filtering remains on submitted_at.</small></div></div>
            <div class="application-filter"><label for="reviewer_id">Assigned Reviewer</label><select class="form-select" id="reviewer_id" name="reviewer_id" data-filter-change><option value="">All reviewers</option><?php foreach ($reviewers as $reviewer): ?><option value="<?= (int) $reviewer['id'] ?>" <?= $filter('reviewer_id') === (string) $reviewer['id'] ? 'selected' : '' ?>><?= e($reviewer['full_name']) ?></option><?php endforeach; ?></select></div>
            <div class="application-filter"><label for="documents">Document Completion</label><select class="form-select" id="documents" name="documents" data-filter-change><option value="">Any completion</option><option value="complete" <?= $filter('documents') === 'complete' ? 'selected' : '' ?>>All verified</option><option value="incomplete" <?= $filter('documents') === 'incomplete' ? 'selected' : '' ?>>Needs attention</option></select></div>
        </div>
    </section>

    <div class="application-active-filters" aria-label="Active filters" data-active-filters <?= $activeFilters === [] ? 'hidden' : '' ?>>
        <span class="application-active-label">Active filters</span>
        <?php foreach ($activeFilters as $activeFilter): ?><span class="application-filter-chip"><span><?= e($activeFilter['label']) ?></span><button type="button" aria-label="Remove <?= e($activeFilter['label']) ?> filter" data-clear-filter="<?= e($activeFilter['key']) ?>" <?= $activeFilter['key'] === 'date_range' ? 'data-clear-keys="date_range,start_date,end_date"' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></span><?php endforeach; ?>
        <?php if ($activeFilters !== []): ?><span class="shared-filter-actions"><a href="<?= e($resetUrl) ?>" class="btn btn-link application-reset">Reset Filters</a><a href="<?= e($resetUrl) ?>" class="application-clear-all">Clear all</a></span><?php endif; ?>
    </div>
    <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
</form>

<?php if ($applications['rows'] === []): ?>
    <div class="empty-state staff-empty"><span class="empty-icon"><i class="bi bi-inbox" aria-hidden="true"></i></span><div><p class="eyebrow">No matching records</p><h2>No applications match these filters.</h2><p>Change a filter or reset the current selection to see more applications.</p></div></div>
<?php else: ?>
    <div class="table-responsive staff-table-wrap">
        <table class="table staff-table"><caption class="visually-hidden">Admissions application queue</caption><thead><tr><th scope="col">Applicant</th><th scope="col">Reference</th><th scope="col">Program / year</th><th scope="col">Submitted</th><th scope="col">Documents</th><th scope="col">Reviewer</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
        <?php foreach ($applications['rows'] as $row): ?><tr><td><strong><?= e($row['full_name']) ?></strong></td><td><?= e($row['reference_number']) ?></td><td><?= e($row['program_name'] ?? 'Not selected') ?><small><?= e($row['year_label'] ?? 'No intake') ?></small></td><td><?= e($row['submitted_at'] ? date('M j, Y', strtotime($row['submitted_at'] . ' UTC')) : '—') ?></td><td><?= (int) $row['uploaded_documents'] ?> / <?= (int) $row['required_documents'] ?> uploaded</td><td><?= e($row['reviewer_name'] ?? 'Unassigned') ?></td><td><span class="status-pill status-<?= e($row['status']) ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span></td><td><a class="btn btn-sm btn-outline-navy" href="/staff/application/<?= (int) $row['id'] ?>">Review <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></td></tr><?php endforeach; ?>
        </tbody></table>
    </div>
    <?php $pagination = $applications['pagination'] + ['base_url' => $applicationQueuePath, 'query' => $applications['filters'], 'target' => 'applications']; require dirname(__DIR__) . '/partials/pagination.php'; ?>
<?php endif; ?>