<article class="document-print-page">
    <header class="document-print-header">
        <p>AdmitFlow</p>
        <p>Admission Office</p>
        <h1><?= e($documentTitle) ?></h1>
    </header>
    <p class="document-print-meta">Generated <?= e($generatedAt->format('F j, Y g:i A')) ?> Asia/Manila</p>

    <?php if ($documentType === 'summary'): ?>
        <?php if (($application['status'] ?? '') === 'draft'): ?><p class="document-draft-notice"><strong>Draft application summary.</strong> This application has not been submitted and does not represent an admission decision.</p><?php endif; ?>
        <section class="document-print-section">
            <h2>Applicant information</h2>
            <dl class="document-print-details">
                <div><dt>Applicant name</dt><dd><?= e($applicant['full_name'] ?? '') ?></dd></div>
                <div><dt>Email</dt><dd><?= e($applicant['email'] ?? '') ?></dd></div>
                <div><dt>Mobile number</dt><dd><?= e($applicant['mobile_number'] ?? 'Not provided') ?></dd></div>
                <div><dt>Application reference</dt><dd><?= e($application['reference_number'] ?: 'Not assigned') ?></dd></div>
                <div class="document-print-detail-wide"><dt>Residential address</dt><dd><?= e(implode(', ', array_filter([$applicant['address_line'] ?? '', $applicant['municipality'] ?? '', $applicant['province'] ?? ''])) ?: 'Not provided') ?></dd></div>
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
                <table class="document-print-table"><thead><tr><th>Document</th><th>Review status</th><th>Uploaded</th></tr></thead><tbody>
                <?php foreach ($application['documents'] as $document): ?><tr><td><?= e($document['name']) ?></td><td><?= e(ucwords(str_replace('_', ' ', $document['review_status'] ?? 'not uploaded'))) ?></td><td><?= e($dateLabel($document['uploaded_at'] ?? null)) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>
    <?php elseif ($documentType === 'acceptance'): ?>
        <p><?php if (!empty($application['accepted_at'])): ?>Decision date <?= e($dateLabel($application['accepted_at'])) ?><?php else: ?>Generated <?= e($generatedAt->format('F j, Y')) ?><?php endif; ?></p>
        <section class="document-print-section acceptance-letter-copy">
            <p>Dear <?= e($applicant['full_name'] ?? '') ?>,</p>
            <p>We are pleased to confirm that your application has been accepted.</p>
            <dl class="document-print-details">
                <div><dt>Application reference</dt><dd><?= e($application['reference_number'] ?: 'Not assigned') ?></dd></div>
                <div><dt>Approved program</dt><dd><?= e($application['program_name'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Academic year or intake</dt><dd><?= e($application['academic_year_label'] ?? 'Not recorded') ?></dd></div>
            </dl>
            <div class="document-print-signoff"><p>Sincerely,</p><p><strong>Admission Office</strong></p><p>AdmitFlow</p></div>
        </section>
    <?php else: ?>
        <section class="document-print-section">
            <p>This confirms that enrollment for the application below is recorded as complete.</p>
            <dl class="document-print-details">
                <div><dt>Applicant name</dt><dd><?= e($applicant['full_name'] ?? '') ?></dd></div>
                <div><dt>Application reference</dt><dd><?= e($application['reference_number'] ?: 'Not assigned') ?></dd></div>
                <div><dt>Program</dt><dd><?= e($application['program_name'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Academic year or intake</dt><dd><?= e($application['academic_year_label'] ?? 'Not recorded') ?></dd></div>
                <div><dt>Enrollment completed</dt><dd><?= e($dateLabel($application['enrollment']['completed_at'] ?? null)) ?></dd></div>
            </dl>
            <div class="document-print-signoff"><p>Sincerely,</p><p><strong>Admission Office</strong></p><p>AdmitFlow</p></div>
        </section>
    <?php endif; ?>
</article>