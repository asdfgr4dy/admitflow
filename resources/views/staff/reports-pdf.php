<?php
$generatedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$filterLabels = ['q' => 'Search', 'status' => 'Status', 'program_id' => 'Program', 'year_id' => 'Academic year', 'reviewer_id' => 'Reviewer', 'documents' => 'Documents', 'date_range' => 'Date range', 'start_date' => 'Start date', 'end_date' => 'End date'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>AdmitFlow Reports and Print Center</title>
<style>
@page { size: A4 landscape; margin: 14mm; }
body { color: #172d3a; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
h1 { margin: 0 0 4px; font-size: 22px; color: #16334a; }
h2 { margin: 0 0 8px; font-size: 14px; color: #16334a; }
p { margin: 3px 0 8px; }
.meta { color: #526671; border-bottom: 1px solid #b9c9c2; padding-bottom: 8px; }
.filters { margin: 10px 0; color: #405966; }
.metrics { width: 100%; margin: 12px 0; border-collapse: separate; border-spacing: 6px; }
.metrics td { width: 25%; padding: 10px; border: 1px solid #cbd8d2; background: #f5f8f6; vertical-align: top; }
.metrics strong, .metrics span { display: block; }
.metrics strong { margin-top: 4px; font-size: 17px; color: #16334a; }
.metrics small { color: #526671; }
.columns { width: 100%; border-collapse: separate; border-spacing: 7px; }
.columns > tbody > tr > td { width: 60%; padding: 10px; border: 1px solid #cbd8d2; vertical-align: top; }
.columns > tbody > tr > td + td { width: 40%; }
.funnel, .attention, .applications { width: 100%; border-collapse: collapse; }
.funnel th, .funnel td, .attention th, .attention td, .applications th, .applications td { padding: 5px; border-bottom: 1px solid #d5dfda; text-align: left; vertical-align: top; }
.funnel th, .attention th, .applications th { color: #16334a; background: #edf2ef; }
.applications { margin-top: 10px; font-size: 8px; }
.applications td { overflow-wrap: anywhere; }
.note { color: #526671; font-size: 8px; }
</style>
</head>
<body>
<h1>Reports and print center.</h1>
<p class="meta">Admission Office · Generated <?= e($generatedAt->format('F j, Y g:i A')) ?> Asia/Manila · <?= e($dateRangeLabels[$report['filters']['date_range'] ?? ''] ?? 'Any date') ?> · <?= number_format($report['total']) ?> matching applications<?php if ($report['truncated']): ?> · Table limited to the first 5,000 matching records<?php endif; ?></p>
<p class="filters"><strong>Applied filters:</strong> <?php $applied = []; foreach ($filterLabels as $key => $label): if (($displayFilters[$key] ?? '') !== '') $applied[] = $label . ': ' . (string) $displayFilters[$key]; endforeach; ?><?= e($applied === [] ? 'None' : implode(' · ', $applied)) ?></p>
<table class="metrics"><tr><td><span>Applications</span><strong><?= number_format($report['total']) ?></strong></td><td><span>Reviewed</span><strong><?= number_format($report['summary']['reviewed']) ?></strong><small><?= $report['total'] > 0 ? number_format($report['summary']['reviewed'] / $report['total'] * 100, 1) : '0.0' ?>% of matching applications</small></td><td><span>Average review time</span><strong><?= $report['summary']['average_review_hours'] === null ? 'Not available' : number_format((float) $report['summary']['average_review_hours'], 1) . ' hours' ?></strong><small>Submission to first recorded screening or document-review event</small></td><td><span>Enrollment rate</span><strong><?= $report['total'] > 0 ? number_format(($report['summary']['statuses']['enrolled'] ?? 0) / $report['total'] * 100, 1) : '0.0' ?>%</strong><small><?= number_format($report['summary']['statuses']['enrolled'] ?? 0) ?> enrolled</small></td></tr></table>
<table class="columns"><tr><td><h2>Applicant funnel</h2><table class="funnel"><thead><tr><th>Stage</th><th>Count</th><th>Share</th><th>Conversion</th></tr></thead><tbody><?php foreach ($report['summary']['funnel'] as $index => $stage): ?><tr><td><?= e($stage['label']) ?></td><td><?= number_format($stage['count']) ?></td><td><?= number_format($stage['percentage'], 1) ?>%</td><td><?= $index === 0 || $stage['conversion'] === null ? '—' : number_format($stage['conversion'], 1) . '% advanced' ?></td></tr><?php endforeach; ?></tbody></table><p class="note">Cumulative counts use current status and recorded status history; legacy transitions are not inferred. Declined: <?= number_format($report['summary']['side_states']['declined']) ?> · Documents Incomplete: <?= number_format($report['summary']['side_states']['documents_incomplete']) ?>.</p></td><td><h2>Needs Attention</h2><table class="attention"><tbody><?php foreach (['unassigned' => 'Unassigned applications', 'interviews_week' => 'Interviews scheduled this week', 'idle' => 'Idle more than 7 days', 'incomplete_documents' => 'Incomplete documents'] as $key => $label): ?><tr><th><?= e($label) ?></th><td><?= number_format($report['summary']['attention'][$key]) ?></td></tr><?php endforeach; ?></tbody></table><p><strong>Document readiness:</strong> <?= number_format($report['summary']['documents_verified']) ?> / <?= number_format($report['summary']['documents_required']) ?> verified required documents.</p><p class="note">Uploaded but unverified files do not count as ready.</p></td></tr></table>
<h2>Applications (<?= number_format($report['table_total']) ?>)</h2>
<table class="applications"><thead><tr><th>Reference</th><th>Applicant</th><th>Program</th><th>Academic Year</th><th>Submitted</th><th>Documents</th><th>Reviewer</th><th>Status</th></tr></thead><tbody><?php foreach ($report['rows'] as $row): ?><tr><td><?= e($row['reference_number']) ?></td><td><?= e($row['full_name']) ?></td><td><?= e($row['program_name'] ?? 'Not selected') ?></td><td><?= e($row['year_label'] ?? '—') ?></td><td><?= e($row['submitted_at'] ?? '—') ?></td><td><?= (int) $row['verified_documents'] ?> / <?= (int) $row['required_documents'] ?> verified</td><td><?= e($row['reviewer_name'] ?? 'Unassigned') ?><?= !empty($row['reviewer_is_historical']) ? ' (historical)' : '' ?></td><td><?= e(ucwords(str_replace('_', ' ', $row['status']))) ?></td></tr><?php endforeach; ?></tbody></table>
</body>
</html>
