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
$isPrintPreview = !empty($printDocumentPreviewView);
$dateLabel = static fn (?string $date): string => $date === null || $date === '' ? 'Not recorded' : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y');
?>
<section class="student-print-document container-xl">
    <?php if (!$isPrintPreview): ?><div class="no-print document-print-actions">
        <a class="btn btn-outline-navy" href="/portal"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to portal</a>
        <?php if ($available): ?><button type="button" class="btn btn-outline-navy" data-print-preview-trigger data-bs-toggle="modal" data-bs-target="#printPreviewModal" data-preview-url="<?= e($documentPreviewUrl) ?>" data-preview-title="<?= e($documentTitle) ?> print preview"><i class="bi bi-eye" aria-hidden="true"></i> Print preview</button><?php endif; ?>
    </div><?php endif; ?>
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
<?php if (!$isPrintPreview && $available): ?>
    <?php $studentDocumentPreviewModal = true; ?>
    <?php require dirname(__DIR__) . '/partials/print-preview-modal.php'; ?>
<?php endif; ?>