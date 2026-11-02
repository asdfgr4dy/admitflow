<?php
$statusLabels = ['submitted' => 'Submitted', 'in_screening' => 'In Screening', 'documents_incomplete' => 'Documents Incomplete', 'interview_scheduled' => 'Interview Scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
$dateLabels = ['' => 'Any date', 'last7' => 'Last 7 days', 'last30' => 'Last 30 days', 'term' => 'This Term', 'custom' => 'Custom range'];
$drilldownLabels = ['stage:submitted' => 'Submitted stage', 'stage:screening' => 'Screening stage', 'stage:interview' => 'Interview stage', 'stage:accepted' => 'Accepted stage', 'stage:enrolled' => 'Enrolled stage', 'attention:unassigned' => 'Unassigned applications', 'attention:interviews_week' => 'Interviews scheduled this week', 'attention:idle' => 'Applications idle more than 7 days', 'attention:incomplete_documents' => 'Incomplete documents', 'status:declined' => 'Declined applications', 'status:documents_incomplete' => 'Documents incomplete'];
$generatedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$filters = $report['filters'];
$appliedFilters = [];
if (($filters['q'] ?? '') !== '') $appliedFilters[] = 'Search: ' . $filters['q'];
if (($filters['status'] ?? '') !== '') $appliedFilters[] = 'Application status: ' . ($statusLabels[$filters['status']] ?? $filters['status']);
$programLabels = array_column($programs, 'name', 'id');
$yearLabels = array_column($years, 'label', 'id');
$reviewerLabels = array_column($reviewers, 'full_name', 'id');
if (($filters['program_id'] ?? '') !== '') $appliedFilters[] = 'Program: ' . ($programLabels[$filters['program_id']] ?? 'Selected program');
if (($filters['year_id'] ?? '') !== '') $appliedFilters[] = 'Academic year: ' . ($yearLabels[$filters['year_id']] ?? 'Selected year');
if (($filters['date_range'] ?? '') !== '') $appliedFilters[] = 'Date range: ' . ($dateLabels[$filters['date_range']] ?? 'Selected range');
if (($filters['start_date'] ?? '') !== '' || ($filters['end_date'] ?? '') !== '') $appliedFilters[] = 'Submitted: ' . ($filters['start_date'] ?: 'Beginning') . ' to ' . ($filters['end_date'] ?: 'Today');
if (($filters['reviewer_id'] ?? '') !== '') $appliedFilters[] = 'Assigned reviewer: ' . ($reviewerLabels[$filters['reviewer_id']] ?? 'Selected reviewer');
if (($filters['documents'] ?? '') !== '') $appliedFilters[] = 'Document completion: ' . ($filters['documents'] === 'complete' ? 'All verified' : 'Needs attention');
if (($filters['drilldown'] ?? '') !== '' && isset($drilldownLabels[$filters['drilldown']])) $appliedFilters[] = 'Table view: ' . $drilldownLabels[$filters['drilldown']];
$reviewedPercent = $report['total'] > 0 ? round($report['summary']['reviewed'] / $report['total'] * 100, 1) : 0;
$enrolledCount = (int) ($report['summary']['statuses']['enrolled'] ?? 0);
$enrolledPercent = $report['total'] > 0 ? round($enrolledCount / $report['total'] * 100, 1) : 0;
?>
<section class="report-preview-page" data-print-preview-sheet>
    <header class="report-preview-header">
        <div><p class="eyebrow">Admission Office · Reporting</p><h1>Reports and print center.</h1><p>Generated <?= e($generatedAt->format('F j, Y g:i A')) ?> Asia/Manila · <?= number_format($report['total']) ?> matching applications</p></div>
    </header>
    <?php if ($report['truncated']): ?><p class="report-preview-limit" role="note">This preview includes the first 5,000 matching applications. Narrow the filters to preview a smaller complete result set.</p><?php endif; ?>
    <section class="report-preview-filters" aria-labelledby="report-preview-filters-heading"><h2 id="report-preview-filters-heading">Applied filters</h2><p><?= e($appliedFilters === [] ? 'None' : implode(' · ', $appliedFilters)) ?></p></section>
    <section class="report-summary report-preview-summary" aria-label="Application summary">
        <article><span>Applications</span><strong><?= number_format($report['total']) ?></strong></article>
        <article><span>Reviewed</span><strong><?= number_format($report['summary']['reviewed']) ?></strong><small><?= number_format($reviewedPercent, 1) ?>% of matching applications</small></article>
        <article><span>Average review time</span><strong><?= $report['summary']['average_review_hours'] === null ? '—' : number_format((float) $report['summary']['average_review_hours'], 1) . 'h' ?></strong><small>Submission to first recorded screening or document-review event</small></article>
        <article><span>Enrollment rate</span><strong><?= number_format($enrolledPercent, 1) ?>%</strong><small><?= number_format($enrolledCount) ?> enrolled of <?= number_format($report['total']) ?> applications</small></article>
    </section>
    <div class="report-preview-analysis">
        <section class="report-preview-funnel" aria-labelledby="report-preview-funnel-heading"><h2 id="report-preview-funnel-heading">Applicant funnel</h2>
            <?php if ($report['total'] === 0): ?><p>No funnel data is available because no applications match these filters.</p><?php else: ?>
                <?php foreach ($report['summary']['funnel'] as $stage): ?><div class="report-preview-stage"><div><strong><?= e($stage['label']) ?></strong><span><?= number_format($stage['count']) ?> · <?= number_format($stage['percentage'], 1) ?>%</span></div><progress class="report-funnel-progress" max="100" value="<?= e((string) min(100, max(0, $stage['percentage']))) ?>" aria-label="<?= e($stage['label']) ?>: <?= number_format($stage['count']) ?> applications, <?= number_format($stage['percentage'], 1) ?> percent"><?= e((string) $stage['percentage']) ?>%</progress></div><?php endforeach; ?>
            <?php endif; ?>
            <p class="report-preview-side-stats">Declined: <?= number_format($report['summary']['side_states']['declined']) ?> · Documents incomplete: <?= number_format($report['summary']['side_states']['documents_incomplete']) ?></p>
        </section>
        <section class="report-preview-attention" aria-labelledby="report-preview-attention-heading"><h2 id="report-preview-attention-heading">Needs attention</h2><dl><div><dt>Unassigned applications</dt><dd><?= number_format($report['summary']['attention']['unassigned']) ?></dd></div><div><dt>Interviews this week</dt><dd><?= number_format($report['summary']['attention']['interviews_week']) ?></dd></div><div><dt>Idle more than 7 days</dt><dd><?= number_format($report['summary']['attention']['idle']) ?></dd></div><div><dt>Incomplete documents</dt><dd><?= number_format($report['summary']['attention']['incomplete_documents']) ?></dd></div><div><dt>Document readiness</dt><dd><?= number_format($report['summary']['documents_verified']) ?> / <?= number_format($report['summary']['documents_required']) ?></dd></div></dl></section>
    </div>
    <section class="report-preview-table-section" aria-labelledby="report-preview-table-heading"><h2 id="report-preview-table-heading">Applications (<?= number_format($report['table_total']) ?>)</h2>
        <?php if ($report['rows'] === []): ?><p>No applications match the selected report filters.</p><?php else: ?><div class="report-preview-table-wrap"><table class="table staff-table report-preview-table"><caption class="visually-hidden">Matching applications in print preview</caption><thead><tr><th scope="col">Reference</th><th scope="col">Applicant</th><th scope="col">Program</th><th scope="col">Academic Year</th><th scope="col">Submitted</th><th scope="col">Documents</th><th scope="col">Reviewer</th><th scope="col">Status</th></tr></thead><tbody><?php foreach ($report['rows'] as $row): ?><tr><td><?= e($row['reference_number']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['program_name'] ?? 'Not selected') ?></td><td><?= e($row['year_label'] ?? '—') ?></td><td><?= e($row['submitted_at'] ? date('M j, Y', strtotime($row['submitted_at'] . ' UTC')) : '—') ?></td><td><?= (int) $row['verified_documents'] ?> / <?= (int) $row['required_documents'] ?> verified</td><td><?php if (empty($row['reviewer_name'])): ?><span class="report-unassigned">Unassigned</span><?php else: ?><?= e($row['reviewer_name']) ?><?= !empty($row['reviewer_is_historical']) ? ' (historical)' : '' ?><?php endif; ?></td><td><span class="status-pill status-<?= e($row['status']) ?>"><?= e($statusLabels[$row['status']] ?? $row['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <p class="report-preview-privacy">This report excludes residential addresses, dates of birth, and authentication information.</p>
</section>
