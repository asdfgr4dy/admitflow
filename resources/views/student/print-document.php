<?php
$documentTitle = match ($documentType) {
    'acceptance' => 'Acceptance letter',
    'enrollment' => 'Enrollment confirmation',
    default => 'Application summary',
};
$statusLabels = [
    'draft' => 'Draft',
    'submitted' => 'Submitted',
    'in_screening' => 'In screening',
    'documents_incomplete' => 'Documents need attention',
    'interview_scheduled' => 'Interview scheduled',
    'accepted' => 'Accepted',
    'declined' => 'Declined',
    'enrolled' => 'Enrolled',
];
$applicant = is_array($application['applicant'] ?? null) ? $application['applicant'] : [];
$dateLabel = static fn (?string $date): string => $date === null || $date === '' ? 'Not recorded' : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y');
?>
<section class="student-print-document container-xl">
    <div class="no-print document-print-actions">
        <a class="btn btn-outline-navy" href="/portal"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to portal</a>
        <?php if ($available): ?><button type="button" class="btn btn-navy" data-print><i class="bi bi-printer" aria-hidden="true"></i> Print <?= e($documentTitle) ?></button><?php endif; ?>
    </div>
    <?php if (!$available): ?>
        <section class="document-unavailable" role="status">
            <p class="eyebrow">Admission Office</p>
            <h1><?= e($documentTitle) ?> unavailable</h1>
            <p><?php if ($application === null): ?>No application summary is available because no application has been started.<?php elseif ($documentType === 'acceptance'): ?>An acceptance letter is available only after the application status is accepted.<?php else: ?>An enrollment confirmation is available only after the application is enrolled and its completion record is saved.<?php endif; ?></p>
        </section>
    <?php else: ?>
        <?php require __DIR__ . '/print-document-content.php'; ?>
    <?php endif; ?>
</section>