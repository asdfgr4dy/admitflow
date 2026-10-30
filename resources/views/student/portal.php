<?php
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
$printableApplication = is_array($printableApplication ?? null) ? $printableApplication : null;
$verifiedDocuments = count(array_filter($documents, static fn (array $document): bool => ($document['review_status'] ?? '') === 'verified'));
$portalNotifications = is_array($notificationFeed['items'] ?? null) ? $notificationFeed['items'] : [];
$nextPortalNotification = null;
foreach ($portalNotifications as $portalNotification) {
    if (!empty($portalNotification['derived']) && empty($portalNotification['read_at'])) {
        $nextPortalNotification = $portalNotification;
        break;
    }
}
if ($nextPortalNotification === null) {
    foreach ($portalNotifications as $portalNotification) {
        if (empty($portalNotification['read_at'])) {
            $nextPortalNotification = $portalNotification;
            break;
        }
    }
}
$progressStages = ['draft', 'submitted', 'in_screening', 'interview_scheduled', 'accepted', 'enrolled'];
$currentStage = match ($application['status'] ?? '') {
    'draft' => 0,
    'submitted' => 1,
    'in_screening', 'documents_incomplete' => 2,
    'interview_scheduled' => 3,
    'accepted' => 4,
    'enrolled' => 5,
    default => -1,
};
$progressPercent = $currentStage < 0 ? 0 : (int) round(($currentStage / (count($progressStages) - 1)) * 100);
$firstName = explode(' ', trim($user['full_name']))[0] ?? 'Student';
?>
<section class="portal-wrap container-xl student-portal">
    <div class="portal-heading student-welcome">
        <div>
            <p class="eyebrow"><span class="eyebrow-rule"></span> Student workspace</p>
            <h1>Welcome, <?= e($firstName) ?>.</h1>
            <p>Your application progress, documents, and updates are gathered here.</p>
        </div>
        <a href="/application" class="btn btn-navy student-primary-action">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <?= $application === null ? 'Start application' : ($application['status'] === 'draft' ? 'Continue application' : 'View application') ?>
        </a>
    </div>

    <?php if (($announcements ?? []) !== []): $featuredAnnouncement = $announcements[0]; ?>
        <aside class="announcement-banner announcement-banner-student" aria-label="Latest student announcement">
            <i class="bi bi-megaphone announcement-banner-icon" aria-hidden="true"></i><div><p class="eyebrow">Admission Office update</p><h2><?= e($featuredAnnouncement['title']) ?></h2><p><?= e(mb_strimwidth($featuredAnnouncement['body'], 0, 220, '...')) ?></p><a class="btn btn-sm btn-navy" href="<?= e('/announcement/' . (int) $featuredAnnouncement['id']) ?>">Read full announcement <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div><a class="announcement-view-all" href="/announcements">Announcement Center <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </aside>
    <?php endif; ?>

    <?php if ($interviews !== []): ?>
        <section class="student-card student-interviews" aria-labelledby="student-interviews-title">
            <div class="student-card-heading"><div><p class="eyebrow">Schedule and history</p><h2 id="student-interviews-title">Your interviews</h2></div><span class="document-count"><?= count($interviews) ?> recent</span></div>
            <div class="student-interview-list">
                <?php foreach ($interviews as $interview): ?>
                    <article class="student-interview-row">
                        <span class="student-interview-icon" aria-hidden="true"><i class="bi bi-calendar-event"></i></span>
                        <div class="student-interview-main"><div class="student-interview-title"><h3><?= e(ucwords(str_replace('_', ' ', $interview['status']))) ?></h3><span class="status-pill status-<?= e($interview['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $interview['status']))) ?></span></div><p><?= e(date('l, F j, Y', strtotime($interview['starts_at_utc'] . ' UTC'))) ?> · <?= e(date('g:i A', strtotime($interview['starts_at_utc'] . ' UTC'))) ?><?php if (!empty($interview['ends_at_utc'])): ?>–<?= e(date('g:i A', strtotime($interview['ends_at_utc'] . ' UTC'))) ?><?php endif; ?> · Asia/Manila</p><?php if (!empty($interview['interviewer_name'])): ?><p>Interviewer: <?= e($interview['interviewer_name']) ?></p><?php endif; ?><?php if (!empty($interview['instructions'])): ?><p class="student-interview-instructions"><?= nl2br(e($interview['instructions'])) ?></p><?php endif; ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($application === null): ?>
        <section class="student-empty-state" aria-labelledby="student-empty-title">
            <div class="student-empty-icon"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i></div>
            <div>
                <p class="eyebrow">Your application starts here</p>
                <h2 id="student-empty-title">No application yet</h2>
                <p>Create a draft, add your academic details, and upload the required documents when you are ready.</p>
                <a href="/application" class="btn btn-navy">Start your application <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
        </section>
    <?php else: ?>
        <section class="student-status-panel" aria-labelledby="application-status-title">
            <div class="student-status-heading">
                <div>
                    <p class="eyebrow">Application overview</p>
                    <h2 id="application-status-title">Your application</h2>
                </div>
                <span class="status-pill status-<?= e($application['status']) ?>"><span class="status-dot" aria-hidden="true"></span><?= e($statusLabels[$application['status']] ?? ucwords(str_replace('_', ' ', $application['status']))) ?></span>
            </div>
            <div class="student-detail-grid">
                <div><span>Reference</span><strong><?= e($application['reference_number'] ?: 'Draft not yet submitted') ?></strong></div>
                <div><span>Program</span><strong><?= e($application['program_name'] ?? 'Not selected') ?></strong></div>
                <div><span>Intake</span><strong><?= e($application['academic_year_label'] ?? 'Not selected') ?></strong></div>
                <div><span>Submitted</span><strong><?= !empty($application['submitted_at']) ? e(date('M j, Y', strtotime($application['submitted_at'] . ' UTC'))) : 'Not submitted' ?></strong></div>
            </div>
            <div class="student-print-actions no-print">
                <?php if ($printableApplication !== null): ?><button class="btn btn-outline-navy" type="button" data-bs-toggle="modal" data-bs-target="#applicationSummaryModal"><i class="bi bi-printer" aria-hidden="true"></i> Application summary</button><?php endif; ?>
                <?php if ($printableApplication !== null && ApplicationService::canPrintAcceptance($printableApplication)): ?><button class="btn btn-outline-navy" type="button" data-bs-toggle="modal" data-bs-target="#acceptanceLetterModal"><i class="bi bi-file-earmark-check" aria-hidden="true"></i> Acceptance letter</button><?php else: ?><span class="student-print-unavailable">Acceptance letter is available after acceptance.</span><?php endif; ?>
                <?php if ($printableApplication !== null && ApplicationService::canPrintEnrollment($printableApplication)): ?><button class="btn btn-outline-navy" type="button" data-bs-toggle="modal" data-bs-target="#enrollmentConfirmationModal"><i class="bi bi-card-checklist" aria-hidden="true"></i> Enrollment confirmation</button><?php else: ?><span class="student-print-unavailable">Enrollment confirmation is available after enrollment is complete.</span><?php endif; ?>
            </div>
            <?php if ($currentStage >= 0): ?>
                <div class="student-progress-wrap">
                    <div class="student-progress-label"><span>Application progress</span><strong><?= $progressPercent ?>%</strong></div>
                    <div class="progress student-progress" role="progressbar" aria-label="Application progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?>"><div class="progress-bar" style="width: <?= $progressPercent ?>%"></div></div>
                    <ol class="student-progress-stages" aria-label="Application stages">
                        <?php foreach (['Draft', 'Submitted', 'Review', 'Interview', 'Accepted', 'Enrolled'] as $index => $stage): ?>
                            <li class="<?= $index < $currentStage ? 'is-complete' : ($index === $currentStage ? 'is-current' : '') ?>"><?= e($stage) ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php elseif ($application['status'] === 'declined'): ?>
                <p class="student-status-note">The application status has been updated. Review any Admission Office notifications for details.</p>
            <?php endif; ?>
        </section>

        <?php if ($application['status'] === 'documents_incomplete'): ?>
            <div class="alert alert-warning student-action-alert" role="status"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><strong>Action required.</strong> Review the document feedback below and upload replacements for items marked for attention.</span></div>
        <?php endif; ?>

        <div class="student-dashboard-grid">
            <section class="student-card student-documents" aria-labelledby="required-documents-title">
                <div class="student-card-heading">
                    <div><p class="eyebrow">Your checklist</p><h2 id="required-documents-title">Required documents</h2></div>
                    <span class="document-count"><?= $verifiedDocuments ?> / <?= count($documents) ?> verified</span>
                </div>
                <?php if ($documents === []): ?>
                    <p class="student-inline-empty">Document requirements will appear after you start your application.</p>
                <?php else: ?>
                    <ul class="student-document-list">
                        <?php foreach ($documents as $document): ?>
                            <?php $docStatus = $document['review_status'] ?? 'not_uploaded'; $needsAction = in_array($docStatus, ['rejected', 'action_required'], true); ?>
                            <li class="student-document-item <?= $needsAction ? 'needs-action' : ($docStatus === 'verified' ? 'is-verified' : '') ?>">
                                <span class="student-document-icon" aria-hidden="true"><i class="bi <?= $docStatus === 'verified' ? 'bi-check-circle-fill' : ($needsAction ? 'bi-exclamation-circle-fill' : 'bi-file-earmark') ?>"></i></span>
                                <div class="student-document-copy">
                                    <div class="student-document-title"><strong><?= e($document['name']) ?></strong><span class="document-state <?= $needsAction ? 'state-action' : ($docStatus === 'verified' ? 'state-done' : 'state-pending') ?>"><?= e(ucwords(str_replace('_', ' ', $docStatus))) ?></span></div>
                                    <?php if (!empty($document['uploaded_at'])): ?><small>Uploaded <?= e(date('M j, Y', strtotime($document['uploaded_at'] . ' UTC'))) ?></small><?php endif; ?>
                                    <?php if (!empty($document['feedback'])): ?><p class="student-document-feedback"><strong>Review feedback:</strong> <?= e($document['feedback']) ?></p><?php endif; ?>
                                    <?php if ($application['status'] === 'documents_incomplete' && $needsAction): ?>
                                        <form method="post" action="/document/replace" enctype="multipart/form-data" class="student-replacement-form">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="application_id" value="<?= (int) $application['id'] ?>">
                                            <input type="hidden" name="requirement_id" value="<?= (int) $document['requirement_id'] ?>">
                                            <label for="replace_<?= (int) $document['requirement_id'] ?>">Choose a replacement file</label>
                                            <input class="form-control" type="file" id="replace_<?= (int) $document['requirement_id'] ?>" name="document" accept=".pdf,.jpg,.jpeg,.png" required>
                                            <button class="btn btn-sm btn-outline-navy" type="submit">Upload replacement</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($document['document_id'])): ?><div class="student-document-actions"><button type="button" class="btn btn-sm btn-outline-navy" data-quick-view data-preview-url="/document/<?= (int) $document['document_id'] ?>/preview" data-download-url="/document/<?= (int) $document['document_id'] ?>" data-document-name="<?= e($document['original_name'] ?? $document['name']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> Quick View</button><a class="student-download-link" href="/document/<?= (int) $document['document_id'] ?>" aria-label="Download <?= e($document['name']) ?>"><i class="bi bi-download" aria-hidden="true"></i></a></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="student-card-footnote"><i class="bi bi-lock" aria-hidden="true"></i> Your documents are available only to you and authorized admissions staff.</p>
            </section>

            <dialog class="document-preview-dialog" data-document-preview aria-labelledby="student-document-preview-title"><div class="document-preview-header"><div><p class="eyebrow">Your document preview</p><h2 id="student-document-preview-title" data-preview-title>Document preview</h2></div><button type="button" class="btn btn-sm btn-outline-navy" data-preview-close aria-label="Close document preview"><i class="bi bi-x-lg" aria-hidden="true"></i> Close</button></div><div class="document-preview-content" data-preview-content aria-live="polite"><p class="text-muted">Choose Quick View to preview a document.</p></div></dialog>

            <aside class="student-card student-next-step" aria-labelledby="next-step-title">
                <p class="eyebrow">Next step</p>
                <?php if ($application['status'] === 'draft'): ?>
                    <h2 id="next-step-title">Finish your application.</h2>
                    <p>Complete the applicant and academic sections, then add each required document.</p>
                <?php elseif ($application['status'] === 'documents_incomplete'): ?>
                    <h2 id="next-step-title">Review document feedback.</h2>
                    <p>Upload replacements for documents marked for correction, then check back for review updates.</p>
                <?php elseif ($nextPortalNotification !== null): ?>
                    <h2 id="next-step-title"><?= e($nextPortalNotification['title']) ?></h2>
                    <p><?= e($nextPortalNotification['message']) ?></p>
                    <a class="btn btn-outline-navy" href="<?= e($nextPortalNotification['href']) ?>" data-notification-link <?= !empty($nextPortalNotification['derived']) ? 'data-derived-key="' . e($nextPortalNotification['key']) . '"' : 'data-notification-id="' . (int) $nextPortalNotification['id'] . '"' ?>>View update <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <?php else: ?>
                    <h2 id="next-step-title"><?= $application['status'] === 'enrolled' ? 'Enrollment complete.' : 'We’ll keep you updated.' ?></h2>
                    <p><?= $application['status'] === 'enrolled' ? 'Your application is marked enrolled. Check your notifications for any new updates.' : 'Check back here for application decisions and any next steps.' ?></p>
                <?php endif; ?>
                <?php if ($application['status'] === 'draft'): ?><a href="/application" class="btn btn-navy">Continue application <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php elseif ($application['status'] === 'documents_incomplete'): ?><a href="#required-documents-title" class="student-text-link">Go to required documents <i class="bi bi-arrow-down" aria-hidden="true"></i></a><?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>

    <?php if (count($announcements ?? []) > 1): ?>
        <section class="student-card student-announcements" aria-labelledby="student-announcements-title">
            <div class="student-card-heading"><div><p class="eyebrow">From the Admission Office</p><h2 id="student-announcements-title">Earlier updates</h2></div><div class="d-flex flex-wrap gap-3"><a class="student-text-link" href="/announcements">All announcements <i class="bi bi-arrow-right" aria-hidden="true"></i></a><a class="student-text-link" href="/notifications">View notifications <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div></div>
            <?php foreach (array_slice($announcements, 1) as $announcement): ?><article class="student-update-row"><h3><?= e($announcement['title']) ?></h3><p><?= e($announcement['body']) ?></p><a class="student-text-link" href="<?= e('/announcement/' . (int) $announcement['id']) ?>">Read full announcement</a></article><?php endforeach; ?>
        </section>
    <?php endif; ?>
</section>

<?php if ($printableApplication !== null): ?>
    <?php foreach ([
        ['type' => 'summary', 'id' => 'applicationSummaryModal', 'title' => 'Application summary', 'available' => true],
        ['type' => 'acceptance', 'id' => 'acceptanceLetterModal', 'title' => 'Acceptance letter', 'available' => ApplicationService::canPrintAcceptance($printableApplication)],
        ['type' => 'enrollment', 'id' => 'enrollmentConfirmationModal', 'title' => 'Enrollment confirmation', 'available' => ApplicationService::canPrintEnrollment($printableApplication)],
    ] as $documentModal): ?>
        <?php if ($documentModal['available']): $documentType = $documentModal['type']; $documentTitle = $documentModal['title']; $application = $printableApplication; $applicant = $printableApplication['applicant'] ?? []; $dateLabel = static fn (?string $date): string => $date === null || $date === '' ? 'Not recorded' : (new DateTimeImmutable($date, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y'); ?>
            <div class="modal fade document-preview-modal" id="<?= e($documentModal['id']) ?>" tabindex="-1" aria-labelledby="<?= e($documentModal['id']) ?>Title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">
                    <div class="modal-header"><div><p class="eyebrow">Student portal · Generated document</p><h2 class="modal-title" id="<?= e($documentModal['id']) ?>Title"><?= e($documentTitle) ?></h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close <?= e(strtolower($documentTitle)) ?> preview"></button></div>
                    <div class="modal-body"><?php require __DIR__ . '/print-document-content.php'; ?></div>
                    <div class="modal-footer"><button type="button" class="btn btn-navy" data-print><i class="bi bi-printer" aria-hidden="true"></i> Print <?= e($documentTitle) ?></button><button type="button" class="btn btn-outline-navy" data-bs-dismiss="modal">Close</button></div>
                </div></div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
