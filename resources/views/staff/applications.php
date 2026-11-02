<?php
$statusLabels = ['submitted' => 'Submitted', 'in_screening' => 'In screening', 'documents_incomplete' => 'Documents incomplete', 'interview_scheduled' => 'Interview scheduled', 'accepted' => 'Accepted', 'declined' => 'Declined', 'enrolled' => 'Enrolled'];
$applicationQueuePath = $user['role'] === 'admin' ? '/admin/applications' : '/staff/applications';
$overviewPath = $user['role'] === 'admin' ? '/admin' : '/staff';
?>
<section class="staff-wrap container-xl admin-application-page">
    <div class="staff-heading academic-page-heading"><div><p class="eyebrow">Admissions Intake</p><h1>Application Queue</h1><p>Search, filter, and manage submitted applications.</p></div><a href="<?= e($overviewPath) ?>" class="btn btn-outline-navy"><i class="bi bi-arrow-left" aria-hidden="true"></i> Overview</a></div>
    <section class="staff-section academic-management-card" id="applications" data-pagination-target aria-label="Application queue">
        <div class="section-heading"><span class="section-count"><?= number_format((int) ($applications['total'] ?? 0)) ?> records</span></div>
        <?php require __DIR__ . '/application-queue.php'; ?>
    </section>
</section>