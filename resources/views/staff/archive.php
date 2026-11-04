<?php
$statusLabels = ['draft' => 'Draft', 'submitted' => 'Submitted', 'in_screening' => 'In screening', 'documents_incomplete' => 'Documents incomplete', 'interview_scheduled' => 'Interview scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
$search = (string) ($archiveApplications['filters']['q'] ?? '');
$status = (string) ($archiveApplications['filters']['status'] ?? '');
$hasArchiveFilters = $search !== '' || $status !== '';
$archiveCount = (int) ($archiveApplications['total'] ?? 0);
$activeFilterChips = [];
if ($search !== '') $activeFilterChips[] = ['key' => 'q', 'label' => 'Search: ' . $search];
if ($status !== '') $activeFilterChips[] = ['key' => 'status', 'label' => 'Previous status: ' . ($statusLabels[$status] ?? $status)];
$filterResetUrl = '/admin/archive?' . http_build_query(['per_page' => (int) ($archiveApplications['pagination']['page_size'] ?? 20)]) . '#archive-records';
?>
<section class="staff-wrap container-xl admin-application-page">
    <div class="staff-heading academic-page-heading">
        <div><p class="eyebrow">Application records</p><h1>Archive</h1><p>Archived applications remain stored with their documents, review history, and audit records.</p></div>
        <a href="/admin/applications" class="btn btn-outline-navy"><i class="bi bi-arrow-left" aria-hidden="true"></i> Applications</a>
    </div>
    <section class="staff-section academic-management-card" id="archive-records" data-pagination-target aria-label="Archived applications">
        <div class="section-heading"><span class="section-count"><?= number_format($archiveCount) ?> archived <?= $archiveCount === 1 ? 'application' : 'applications' ?></span></div>
        <form method="get" action="/admin/archive" class="application-filters" data-auto-filter-form data-filter-target="archive-records">
            <div class="application-primary-filters">
                <div class="application-filter search-field"><label for="archive-search">Search archive</label><div class="input-group"><span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control" id="archive-search" name="q" value="<?= e($search) ?>" placeholder="Applicant, reference, or program" maxlength="160" autocomplete="off" data-filter-search></div></div>
                <div class="application-filter"><label for="archive-status">Previous status</label><select class="form-select" id="archive-status" name="status" data-filter-change><option value="">All statuses</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            </div>
            <input type="hidden" name="per_page" value="<?= (int) ($archiveApplications['pagination']['page_size'] ?? 20) ?>">
            <?php require dirname(__DIR__) . '/partials/active-filter-chips.php'; ?>
            <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
        </form>
        <?php if ($archiveApplications['rows'] === []): ?>
            <div class="empty-state staff-empty"><span class="empty-icon"><i class="bi bi-archive" aria-hidden="true"></i></span><div><p class="eyebrow">No archived records</p><h2><?= $hasArchiveFilters ? 'No archived applications match these filters.' : 'The archive is empty.' ?></h2><?php if ($hasArchiveFilters): ?><p>Try a different search or previous status.</p><?php endif; ?></div></div>
        <?php else: ?>
            <div class="table-responsive staff-table-wrap academic-table-wrap">
                <table class="table staff-table academic-table"><caption class="visually-hidden">Archived applications</caption><thead><tr><th scope="col">Applicant</th><th scope="col">Reference</th><th scope="col">Program</th><th scope="col">Previous status</th><th scope="col">Archive date</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
                <?php foreach ($archiveApplications['rows'] as $row): ?><tr><td><strong><?= e($row['full_name']) ?></strong></td><td><a href="/staff/application/<?= (int) $row['id'] ?>?archived=1"><?= e($row['reference_number']) ?></a></td><td><?= e($row['program_name'] ?? 'Not selected') ?></td><td><span class="status-pill status-<?= e($row['previous_status']) ?>"><?= e($statusLabels[$row['previous_status']] ?? $row['previous_status']) ?></span></td><td><?= e(date('M j, Y g:i A', strtotime($row['archived_at'] . ' UTC'))) ?> Asia/Manila</td><td><form method="post" action="/admin/archive/restore" data-submit-once><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="application_id" value="<?= (int) $row['id'] ?>"><button class="btn btn-sm btn-outline-navy" type="submit" data-confirm="Restore this application to active queues, reports, dashboards, and the student portal?">Restore</button></form></td></tr><?php endforeach; ?>
                </tbody></table>
            </div>
            <?php $pagination = $archiveApplications['pagination']; require dirname(__DIR__) . '/partials/pagination.php'; ?>
        <?php endif; ?>
    </section>
</section>
