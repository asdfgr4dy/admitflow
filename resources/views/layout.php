<?php
$flashMessage = take_flash();
$signedInUser = current_user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$sidebarName = trim((string) ($signedInUser['full_name'] ?? 'Account'));
$signedInAvatarAvailable = $signedInUser !== null && class_exists(AvatarService::class) && AvatarService::currentPath((int) $signedInUser['id']) !== null;
$notificationFeed = is_array($notificationFeed ?? null) ? $notificationFeed : ['items' => [], 'unread_count' => 0];
$toastType = match ($flashMessage['type'] ?? '') {
    'success' => 'success',
    'warning' => 'warning',
    'error', 'danger' => 'danger',
    default => 'info',
};
$toastCopy = [
    'success' => ['label' => 'Success', 'icon' => 'bi-check-circle-fill'],
    'warning' => ['label' => 'Warning', 'icon' => 'bi-exclamation-triangle-fill'],
    'danger' => ['label' => 'Error', 'icon' => 'bi-x-octagon-fill'],
    'info' => ['label' => 'Information', 'icon' => 'bi-info-circle-fill'],
][$toastType];
$toastRole = $toastType === 'danger' ? 'alert' : 'status';
$toastDelay = in_array($toastType, ['danger', 'warning'], true) ? 12000 : 5500;
$assetVersion = static function (string $path): string {
    $filePath = dirname(__DIR__, 2) . '/public' . $path;
    return is_file($filePath) ? substr(hash_file('sha256', $filePath), 0, 12) : '1';
};
$portalLinks = [];
if ($signedInUser !== null) {
    $portalLinks = match ($signedInUser['role']) {
        'student' => [
            ['label' => 'Dashboard', 'href' => '/portal', 'icon' => 'bi-grid-1x2'],
            ['label' => 'Application', 'href' => '/application', 'icon' => 'bi-file-earmark-text'],
            ['label' => 'Notifications', 'href' => '/notifications', 'icon' => 'bi-bell'],
            ['label' => 'Settings', 'href' => '/settings', 'icon' => 'bi-gear'],
        ],
        'reviewer' => [
            ['label' => 'Dashboard', 'href' => '/staff', 'icon' => 'bi-grid-1x2'],
            ['label' => 'Applications', 'href' => '/staff/applications', 'icon' => 'bi-folder2-open'],
            ['label' => 'Reports', 'href' => '/staff/reports', 'icon' => 'bi-bar-chart-line'],
            ['label' => 'Settings', 'href' => '/settings', 'icon' => 'bi-gear'],
        ],
        'admin' => [
            ['label' => 'Overview', 'href' => '/admin', 'icon' => 'bi-grid-1x2'],
            ['label' => 'Applications', 'href' => '/admin/applications', 'icon' => 'bi-folder2-open'],
            ['label' => 'Reports', 'href' => '/staff/reports', 'icon' => 'bi-bar-chart-line'],
            ['label' => 'Announcement Center', 'href' => '/staff/announcements', 'icon' => 'bi-megaphone'],
            ['label' => 'User administration', 'href' => '/staff/users', 'icon' => 'bi-people'],
            ['label' => 'Academic settings', 'href' => '/staff/settings', 'icon' => 'bi-sliders'],
            ['label' => 'Audit log', 'href' => '/staff/audit', 'icon' => 'bi-journal-text'],
            ['label' => 'Backups & restore', 'href' => '/staff/backups', 'icon' => 'bi-archive'],
            ['label' => 'Settings', 'href' => '/settings', 'icon' => 'bi-gear'],
        ],
        default => [],
    };
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F5F8F6">
    <title><?= e($title ?? 'Admissions') ?> | admitflow</title>
    <link rel="icon" type="image/png" href="/assets/js/enhanced-logo.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/app.css?v=<?= e($assetVersion('/assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="<?= $signedInUser !== null ? 'authenticated-layout' : 'public-layout' ?>">
<?php if ($flashMessage !== null): ?>
    <div class="toast-container position-fixed top-0 end-0 p-3 admitflow-toast-container" data-toast-container aria-live="<?= $toastRole === 'alert' ? 'assertive' : 'polite' ?>" aria-atomic="true">
        <div class="toast admitflow-toast toast-<?= e($toastType) ?>" role="<?= $toastRole ?>" data-toast data-bs-autohide="<?= $toastType === 'danger' ? 'false' : 'true' ?>" data-bs-delay="<?= $toastDelay ?>">
            <div class="toast-header"><i class="bi <?= e($toastCopy['icon']) ?> toast-type-icon" aria-hidden="true"></i><strong class="me-auto"><?= e($toastCopy['label']) ?></strong><button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Dismiss notification"></button></div>
            <div class="toast-body"><?= e($flashMessage['message']) ?></div>
        </div>
    </div>
<?php endif; ?>
<?php if ($signedInUser !== null): ?>
    <div class="app-shell">
        <aside class="app-sidebar" id="app-sidebar" aria-label="Workspace navigation">
            <a class="app-sidebar-brand" href="<?= e(portal_path_for_user($signedInUser)) ?>" aria-label="AdmitFlow dashboard">
                <img src="/assets/js/enhanced-logo.png" alt="" class="app-sidebar-logo">
                <span class="app-sidebar-brand-copy"><strong>admitflow</strong><small>Admission Office</small></span>
            </a>
            <div class="app-sidebar-section-label">Workspace</div>
            <nav class="app-sidebar-nav" aria-label="Workspace">
                <?php foreach ($portalLinks as $link): ?>
                    <?php
                    $linkPath = parse_url($link['href'], PHP_URL_PATH) ?: '/';
                    $isActive = !str_contains($link['href'], '#') && $currentPath === $linkPath;
                    if ($link['label'] === 'Settings' && (str_starts_with($currentPath, '/settings') || $currentPath === '/account/password')) {
                        $isActive = true;
                    }
                    if ($link['label'] === 'Applications' && in_array($signedInUser['role'], ['reviewer', 'admin'], true) && (str_starts_with($currentPath, '/staff/application/') || in_array($currentPath, ['/admin/applications', '/staff/applications'], true))) {
                        $isActive = true;
                    }
                    ?>
                    <a class="app-sidebar-link<?= $isActive ? ' is-active' : '' ?>" href="<?= e($link['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?> data-sidebar-current="<?= $isActive ? 'true' : 'false' ?>" data-sidebar-link="<?= e($link['label']) ?>">
                        <i class="bi <?= e($link['icon']) ?>" aria-hidden="true"></i><span><?= e($link['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="app-sidebar-bottom">
                <div class="app-sidebar-account">
                    <?php if ($signedInAvatarAvailable): ?><img class="app-avatar app-avatar-image" src="/settings/avatar" alt=""><?php else: ?><span class="app-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($sidebarName, 0, 1))) ?></span><?php endif; ?>
                    <span class="app-account-copy"><strong><?= e($sidebarName) ?></strong><small><?= e(ucfirst((string) ($signedInUser['role'] ?? 'account'))) ?></small></span>
                    <form method="post" action="/logout">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <button class="app-logout-button" type="submit" aria-label="Sign out" title="Sign out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        </aside>
        <button class="app-sidebar-backdrop" type="button" aria-label="Close navigation" tabindex="-1" hidden></button>
        <div class="app-main">
            <header class="app-topbar">
                <button class="app-sidebar-toggle" type="button" aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation" data-sidebar-toggle>
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <div class="app-breadcrumb"><span>Admission Office</span><i class="bi bi-chevron-right" aria-hidden="true"></i><strong><?= e($title ?? 'Dashboard') ?></strong></div>
                <span class="app-role-label"><?= e(ucfirst($signedInUser['role'])) ?> workspace</span>
                <div class="notification-menu" data-notification-menu data-csrf="<?= e(csrf_token()) ?>">
                    <button class="notification-bell" type="button" aria-label="Notifications<?= $notificationFeed['unread_count'] > 0 ? ', ' . (int) $notificationFeed['unread_count'] . ' unread' : '' ?>" aria-expanded="false" aria-controls="notification-panel" data-notification-toggle>
                        <i class="bi bi-bell" aria-hidden="true"></i><?php if ($notificationFeed['unread_count'] > 0): ?><span class="notification-badge"><?= (int) $notificationFeed['unread_count'] > 99 ? '99+' : (int) $notificationFeed['unread_count'] ?></span><?php endif; ?>
                    </button>
                    <section class="notification-dropdown" id="notification-panel" aria-label="Notifications" data-notification-panel hidden>
                        <div class="notification-dropdown-heading"><div><p class="eyebrow">Account updates</p><h2>Notifications</h2></div><span><?= (int) $notificationFeed['unread_count'] ?> unread</span></div>
                        <?php if ($notificationFeed['items'] !== []): ?>
                            <div class="notification-dropdown-list">
                                <?php foreach ($notificationFeed['items'] as $notice): ?>
                                    <?php $noticeTime = (new DateTimeImmutable($notice['created_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila')); ?>
                                    <article class="notification-dropdown-item <?= $notice['read_at'] ? '' : 'is-unread' ?>">
                                        <a href="<?= e($notice['href']) ?>" data-notification-link <?= !empty($notice['derived']) ? 'data-derived-key="' . e($notice['key']) . '"' : 'data-notification-id="' . (int) $notice['id'] . '"' ?>>
                                            <span class="notification-dropdown-title"><?= e($notice['title']) ?></span>
                                            <span class="notification-dropdown-message"><?= e($notice['message']) ?></span>
                                            <time datetime="<?= e($noticeTime->format(DateTimeInterface::ATOM)) ?>"><?= e($noticeTime->format('M j, g:i A')) ?> Asia/Manila</time>
                                        </a>
                                        <?php if (!$notice['read_at']): ?><button type="button" class="notification-mark-read" aria-label="Mark <?= e($notice['title']) ?> as read" data-notification-mark <?= !empty($notice['derived']) ? 'data-derived-key="' . e($notice['key']) . '"' : 'data-notification-id="' . (int) $notice['id'] . '"' ?>><i class="bi bi-check2" aria-hidden="true"></i></button><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <div class="notification-dropdown-footer"><button type="button" class="notification-mark-all" data-notification-mark-all>Mark all as read</button></div>
                        <?php else: ?><div class="notification-dropdown-empty"><i class="bi bi-bell" aria-hidden="true"></i><strong>You’re all caught up</strong><span>New updates will appear here.</span></div><?php endif; ?>
                    </section>
                </div>
                <div class="profile-menu" data-profile-menu>
                    <button class="profile-menu-toggle" type="button" aria-label="Account menu for <?= e($sidebarName) ?>" aria-expanded="false" aria-controls="profile-menu-panel" data-profile-toggle>
                        <?php if ($signedInAvatarAvailable): ?><img class="profile-menu-avatar profile-menu-avatar-image" src="/settings/avatar" alt=""><?php else: ?><span class="profile-menu-avatar" aria-hidden="true"><?= e(strtoupper(mb_substr($sidebarName, 0, 1))) ?></span><?php endif; ?>
                        <span class="profile-menu-copy"><strong><?= e($sidebarName) ?></strong><small><?= e(ucfirst($signedInUser['role'])) ?></small></span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <nav class="profile-menu-panel" id="profile-menu-panel" aria-label="Account settings" data-profile-panel hidden>
                        <a href="/settings/profile"><i class="bi bi-person" aria-hidden="true"></i> My Profile</a>
                        <a href="/account/password"><i class="bi bi-shield-lock" aria-hidden="true"></i> Security</a>
                        <a href="/notifications"><i class="bi bi-bell" aria-hidden="true"></i> Notifications</a>
                        <?php if ($signedInUser['role'] === 'admin'): ?><a href="/settings/system"><i class="bi bi-sliders" aria-hidden="true"></i> System Settings</a><?php endif; ?>
                        <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button type="submit"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Sign Out</button></form>
                    </nav>
                </div>
            </header>
            <main id="main-content" class="app-content">
                <?php require $contentView; ?>
            </main>
            <footer class="app-footer"><span>admitflow · Admission Office</span></footer>
        </div>
    </div>
<?php else: ?>
    <header class="site-header">
        <nav class="navbar navbar-expand-lg navbar-light container-xl" aria-label="Main navigation">
            <a class="navbar-brand" href="/" aria-label="admitflow home"><img src="/assets/js/enhanced-student-admissions-logo-v2.png" alt="AdmitFlow Online Admission Management" class="brand-logo brand-logo-wordmark"></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="mainNavigation">
                <div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
                    <a class="nav-link" href="/#programs">Programs</a>
                    <a class="nav-link" href="/#process">Admission process</a>
                    <a class="nav-link" href="/#requirements">Requirements</a>
                    <a class="btn btn-sm btn-amber ms-lg-2" href="/login"><i class="bi bi-person-circle" aria-hidden="true"></i> Sign in</a>
                    <a class="nav-link" href="/register">Register</a>
                </div>
            </div>
        </nav>
    </header>
    <main id="main-content">
        <?php require $contentView; ?>
    </main>
    <footer class="site-footer">
        <div class="container-xl d-flex flex-column flex-md-row justify-content-between gap-3">
            <div><strong>Admission Office</strong><div class="small text-white-50">admitflow · AY 2026–2027 sample configuration</div></div>
            <div class="small text-white-50">Contact and office hours will be configured by the Admission Office.</div>
            <a href="/#privacy" class="small">Privacy and terms</a>
        </div>
    </footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js" defer></script>
<script src="/assets/js/app.js?v=<?= e($assetVersion('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
