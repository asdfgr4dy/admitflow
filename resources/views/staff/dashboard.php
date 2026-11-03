<?php
?>
<section class="staff-wrap container-xl staff-dashboard-page <?= $user['role'] === 'admin' ? 'admin-dashboard-page' : '' ?>">
    <div class="staff-heading"><div><p class="eyebrow">Admission Office · Admissions operations</p><h1><?= !empty($adminDashboard) ? 'Administrator dashboard.' : 'Review workspace.' ?></h1><p>Welcome, <?= e($user['full_name']) ?>. Applications are shown according to your access.</p></div><a href="/staff/reports" class="btn btn-outline-navy"><i class="bi bi-printer" aria-hidden="true"></i> Reports</a></div>
    <nav class="staff-nav" aria-label="Staff workspace navigation"><a class="is-active" href="<?= $user['role'] === 'admin' ? '/admin' : '/staff' ?>"><i class="bi bi-grid-1x2" aria-hidden="true"></i> Dashboard</a><a href="<?= $user['role'] === 'admin' ? '/admin/applications' : '/staff/applications' ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i> Applications</a><?php if ($user['role'] === 'admin'): ?><a href="/staff/announcements"><i class="bi bi-megaphone" aria-hidden="true"></i> Announcement Center</a><a href="/staff/users/administrators"><i class="bi bi-people" aria-hidden="true"></i> Administrator Accounts</a><a href="/staff/academic/programs"><i class="bi bi-sliders" aria-hidden="true"></i> Programs</a><a href="/staff/audit"><i class="bi bi-journal-text" aria-hidden="true"></i> Audit log</a><?php endif; ?><a href="/announcements"><i class="bi bi-megaphone" aria-hidden="true"></i> Announcements</a><a href="/#process"><i class="bi bi-question-circle" aria-hidden="true"></i> Help center</a></nav>

    <?php if (($announcements ?? []) !== []): $featuredAnnouncement = $announcements[0]; ?>
        <aside class="announcement-banner announcement-banner-staff" aria-label="Latest staff announcement">
            <i class="bi bi-megaphone announcement-banner-icon" aria-hidden="true"></i><div><p class="eyebrow">From the Admission Office</p><h2><?= e($featuredAnnouncement['title']) ?></h2><p><?= e(mb_strimwidth($featuredAnnouncement['body'], 0, 220, '...')) ?></p></div><a class="btn btn-sm btn-outline-navy" href="<?= e('/announcement/' . (int) $featuredAnnouncement['id']) ?>">Read full announcement</a>
        </aside>
    <?php endif; ?>

    <section class="metric-grid" aria-label="Application summary">
        <article><span>Total applications</span><strong><?= number_format($metrics['total'] ?? 0) ?></strong><small>Submitted, non-deleted records</small></article>
        <article><span>Awaiting review</span><strong><?= number_format($metrics['awaiting'] ?? 0) ?></strong><small>Submitted or in screening</small></article>
        <article><span>Accepted</span><strong><?= number_format($metrics['accepted'] ?? 0) ?></strong><small>Human-reviewed decisions</small></article>
        <article><span>Enrolled</span><strong><?= number_format($metrics['enrolled'] ?? 0) ?></strong><small>Enrollment marked complete</small></article>
        <article><span>Incomplete documents</span><strong><?= number_format($metrics['incomplete'] ?? 0) ?></strong><small>Marked by the reviewer</small></article>
        <article><span>Acceptance rate</span><strong><?= number_format($metrics['acceptance_rate'] ?? 0, 1) ?>%</strong><small>Accepted / submitted records</small></article>
        <article><span>Average review time</span><strong><?= $metrics['average_review_hours'] === null ? '—' : number_format((float) $metrics['average_review_hours'], 1) . 'h' ?></strong><small>Submission to first screening status</small></article>
        <article><span>Upcoming interviews</span><strong><?= number_format($metrics['upcoming_interviews'] ?? 0) ?></strong><small>Scheduled, future interviews in your queue</small></article>
        <article><span>Enrollment in progress</span><strong><?= number_format($metrics['enrollment_in_progress'] ?? 0) ?></strong><small>Accepted applications with active preparation</small></article>
        <?php if ($user['role'] === 'admin'): ?><article><span>Enrollment ready</span><strong><?= number_format($metrics['enrollment_complete'] ?? 0) ?></strong><small>Readiness checklist complete</small></article><?php endif; ?>
    </section>

    <?php require __DIR__ . '/dashboard-charts.php'; ?>
</section>
