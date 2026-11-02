<?php
$documentReference = trim((string) ($application['reference_number'] ?? '')) ?: 'Not assigned';
$isDraftApplication = $documentType === 'summary' && ($application['status'] ?? '') === 'draft';
$generatedLabel = $generatedAt->format('F j, Y g:i A');
$programLabel = (string) ($application['program_name'] ?? 'Not recorded');
$academicYearLabel = (string) ($application['academic_year_label'] ?? 'Not recorded');
?>
<article class="document-print-page document-student-sheet<?= $isDraftApplication ? ' is-draft' : '' ?>" data-print-preview-sheet data-document-reference="<?= e($documentReference) ?>">
    <?php if ($isDraftApplication): ?><p class="document-draft-notice"><strong>DRAFT, not submitted.</strong> Draft application summary. This application has not been submitted and does not represent an admission decision.</p><?php endif; ?>
    <header class="document-print-header">
        <div class="document-print-wordmark"><span>AdmitFlow</span><small>Admission Office</small></div>
        <h1><?= e($documentTitle) ?></h1>
    </header>
    <dl class="document-reference-strip">
        <div class="document-print-reference"><dt>Application reference</dt><dd class="document-print-reference-value"><?= e($documentReference) ?></dd></div>
        <div><dt>Date generated</dt><dd><?= e($generatedLabel) ?> Asia/Manila</dd></div>
        <?php if ($documentType === 'acceptance' && !empty($application['accepted_at'])): ?><div><dt>Date of decision</dt><dd><?= e($dateLabel($application['accepted_at'])) ?></dd></div><?php endif; ?>
    </dl>

    <?php if ($documentType === 'summary'): ?>
        <section class="document-print-section">
            <h2>Applicant information</h2>
            <dl class="document-print-details">
                <div><dt>Applicant name</dt><dd><?= e($applicant['full_name'] ?? '') ?></dd></div>
                <div><dt>Email</dt><dd><?= e($applicant['email'] ?? '') ?></dd></div>
                <div><dt>Mobile number</dt><dd><?= e($applicant['mobile_number'] ?? 'Not provided') ?></dd></div>
                <div><dt>Residential address</dt><dd><?= e(implode(', ', array_filter([$applicant['address_line'] ?? '', $applicant['municipality'] ?? '', $applicant['province'] ?? ''])) ?: 'Not provided') ?></dd></div>
            </dl>
        </section>
        <section class="document-print-section">
            <h2>Academic and application details</h2>
            <dl class="document-print-details">
                <div><dt>Program</dt><dd><?= e($application['program_name'] ?? 'Not selected') ?></dd></div>
                <div><dt>Academic year or intake</dt><dd><?= e($application['academic_year_label'] ?? 'Not selected') ?></dd></div>
                <div><dt>Preferred campus</dt><dd><?= e($application['preferred_campus'] ?? 'Not provided') ?></dd></div>
                <div><dt>Previous school</dt><dd><?= e($application['previous_school'] ?? 'Not provided') ?></dd></div>
                <div><dt>Senior High School strand</dt><dd><?= e($application['senior_high_strand'] ?? 'Not provided') ?></dd></div>
                <div><dt>Current status</dt><dd><?= e($statusLabels[$application['status']] ?? ucwords(str_replace('_', ' ', $application['status']))) ?></dd></div>
                <div><dt>Submitted</dt><dd><?= e($dateLabel($application['submitted_at'] ?? null)) ?></dd></div>
            </dl>
        </section>
        <section class="document-print-section">
            <h2>Required documents</h2>
            <?php if (($application['documents'] ?? []) === []): ?><p>No required documents are listed for this application.</p><?php else: ?>
                <table class="document-print-table"><thead><tr><th scope="col">Document</th><th scope="col">Review status</th><th scope="col">Uploaded</th></tr></thead><tbody>
                <?php foreach ($application['documents'] as $document): ?><tr><td><?= e($document['name']) ?></td><td><?= e(ucwords(str_replace('_', ' ', $document['review_status'] ?? 'not uploaded'))) ?></td><td><?= e($dateLabel($document['uploaded_at'] ?? null)) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>
    <?php elseif ($documentType === 'acceptance'): ?>
        <section class="document-letter-copy acceptance-letter-copy">
            <p class="document-letter-date"><?php if (!empty($application['accepted_at'])): ?><?= e($dateLabel($application['accepted_at'])) ?><?php else: ?><?= e($generatedAt->format('F j, Y')) ?><?php endif; ?></p>
            <p class="document-letter-salutation">Dear <?= e($applicant['full_name'] ?? '') ?>,</p>
            <p class="document-letter-subject"><strong>Subject: Acceptance to <?= e($programLabel) ?>, <?= e($academicYearLabel) ?></strong></p>
            <p>We are pleased to confirm that your application has been accepted.</p>
            <table class="document-letter-details"><tbody>
                <tr><th scope="row">Reference</th><td><?= e($documentReference) ?></td></tr>
                <tr><th scope="row">Program</th><td><?= e($programLabel) ?></td></tr>
                <tr><th scope="row">Academic year</th><td><?= e($academicYearLabel) ?></td></tr>
            </tbody></table>
            <div class="document-print-signoff"><p>Sincerely,</p><div class="document-signature-line" aria-hidden="true"></div><p><strong>Admission Office, AdmitFlow</strong></p></div>
        </section>
    <?php else: ?>
        <section class="document-letter-copy">
            <p class="document-letter-date"><?= e($dateLabel($application['enrollment']['completed_at'] ?? null)) ?></p>
            <p class="document-letter-salutation">Dear <?= e($applicant['full_name'] ?? '') ?>,</p>
            <p class="document-letter-subject"><strong>Subject: Enrollment confirmation</strong></p>
            <p>This confirms that enrollment for the application below is recorded as complete.</p>
            <table class="document-letter-details"><tbody>
                <tr><th scope="row">Reference</th><td><?= e($documentReference) ?></td></tr>
                <tr><th scope="row">Program</th><td><?= e($programLabel) ?></td></tr>
                <tr><th scope="row">Academic year</th><td><?= e($academicYearLabel) ?></td></tr>
            </tbody></table>
            <p class="document-enrollment-date">Enrollment completed: <?= e($dateLabel($application['enrollment']['completed_at'] ?? null)) ?></p>
            <div class="document-print-signoff"><p>Sincerely,</p><div class="document-signature-line" aria-hidden="true"></div><p><strong>Admission Office, AdmitFlow</strong></p></div>
        </section>
    <?php endif; ?>
    <footer class="document-running-footer"><p>AdmitFlow · Admission Office · <?= e($documentReference) ?><?= $isDraftApplication ? ' · Draft' : '' ?></p></footer>
</article>
