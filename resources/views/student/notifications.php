<?php $notificationFilters = $notificationFilters ?? ['q' => '', 'status' => '']; $notificationPageSize = (int) ($pagination['page_size'] ?? 20); ?>
<section class="portal-wrap container-xl student-notifications">
    <div class="portal-heading"><div><p class="eyebrow">Account updates</p><h1>Your notifications.</h1><p>Application and admissions updates relevant to your account.</p></div><a href="<?= $user['role'] === 'student' ? '/portal' : ($user['role'] === 'admin' ? '/admin' : '/staff') ?>" class="btn btn-outline-navy"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to workspace</a></div>
    <form method="get" action="/notifications" class="filter-bar" data-auto-filter-form data-filter-target="notification-results">
        <input type="hidden" name="per_page" value="<?= $notificationPageSize ?>">
        <div class="filter-field search-field"><label for="notification_q">Search notifications</label><input class="form-control" id="notification_q" name="q" value="<?= e($notificationFilters['q']) ?>" maxlength="180" placeholder="Title or message" data-filter-search></div>
        <div class="filter-field"><label for="notification_status">Read status</label><select class="form-select" id="notification_status" name="status" data-filter-change><option value="">All notifications</option><option value="unread" <?= $notificationFilters['status'] === 'unread' ? 'selected' : '' ?>>Unread</option><option value="read" <?= $notificationFilters['status'] === 'read' ? 'selected' : '' ?>>Read or dismissed</option></select></div>
        <?php
        $activeFilterChips = [];
        if ($notificationFilters['q'] !== '') $activeFilterChips[] = ['key' => 'q', 'label' => 'Search: ' . $notificationFilters['q']];
        if ($notificationFilters['status'] !== '') $activeFilterChips[] = ['key' => 'status', 'label' => 'Status: ' . ($notificationFilters['status'] === 'unread' ? 'Unread' : 'Read or dismissed')];
        $filterResetUrl = '/notifications?per_page=' . $notificationPageSize . '#notification-results';
        require dirname(__DIR__) . '/partials/active-filter-chips.php';
        ?>
        <span class="visually-hidden" role="status" aria-live="polite" data-filter-status></span>
    </form>
    <?php if ($notifications === []): ?>
        <div class="empty-state" id="notification-results"><span class="empty-icon"><i class="bi bi-bell" aria-hidden="true"></i></span><div><p class="eyebrow">No matching updates</p><h2>No notifications match these filters.</h2><p>Change or reset the filters to see other account updates.</p></div></div>
    <?php else: ?>
        <div class="notification-list" id="notification-results" data-pagination-target>
            <?php foreach ($notifications as $notification): ?>
                <?php $notificationTime = (new DateTimeImmutable($notification['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila')); ?>
                <article class="notification-row <?= $notification['read_at'] ? '' : 'is-unread' ?>"><span class="notification-icon"><i class="bi <?= $notification['read_at'] ? 'bi-bell' : 'bi-bell-fill' ?>" aria-hidden="true"></i></span><div class="notification-content"><div class="notification-title-row"><h2><a href="<?= e($notification['href']) ?>" data-notification-link <?= !empty($notification['derived']) ? 'data-derived-key="' . e($notification['key']) . '"' : 'data-notification-id="' . (int) $notification['id'] . '"' ?>><?= e($notification['title']) ?></a></h2><time datetime="<?= e($notificationTime->format(DateTimeInterface::ATOM)) ?>"><?= e($notificationTime->format('M j, Y g:i A')) ?> Asia/Manila</time></div><p><?= e($notification['message']) ?></p></div><?php if (!$notification['read_at']): ?><form method="post" action="<?= !empty($notification['derived']) ? '/notifications/dismiss-derived' : '/notifications/read' ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><?php if (!empty($notification['derived'])): ?><input type="hidden" name="key" value="<?= e($notification['key']) ?>"><?php else: ?><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><?php endif; ?><button type="submit" class="icon-link" aria-label="Mark <?= e($notification['title']) ?> as read" title="Mark as read"><i class="bi bi-check2" aria-hidden="true"></i></button></form><?php endif; ?></article>
            <?php endforeach; ?>
        </div>
        <?php $pagination = $pagination + ['base_url' => '/notifications', 'query' => $notificationFilters, 'target' => 'notification-results']; require dirname(__DIR__) . '/partials/pagination.php'; ?>
    <?php endif; ?>
</section>