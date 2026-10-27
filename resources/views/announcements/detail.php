<?php
$backPath = match ($user['role'] ?? '') {
    'student' => '/portal',
    'admin' => '/admin',
    'reviewer' => '/staff',
    default => '/',
};
$formatNoticeDate = static fn (?string $value): string => $value === null || $value === '' ? 'Not scheduled' : (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y g:i A') . ' Asia/Manila';
?>
<article class="announcement-detail container-xl">
    <div class="announcement-detail-heading"><p class="eyebrow"><i class="bi bi-megaphone" aria-hidden="true"></i> AdmitFlow · Admission Office</p><h1><?= e($announcement['title']) ?></h1></div>
    <dl class="announcement-detail-meta"><div><dt>Publication</dt><dd>Published</dd></div><div><dt>Audience</dt><dd><?= e(ucfirst($announcement['audience'])) ?></dd></div><div><dt>Starts</dt><dd><?= e($formatNoticeDate($announcement['starts_at'])) ?></dd></div><div><dt>Ends</dt><dd><?= e($formatNoticeDate($announcement['ends_at'])) ?></dd></div></dl>
    <div class="announcement-detail-body"><?= nl2br(e($announcement['body'])) ?></div>
    <div class="announcement-detail-actions"><a class="btn btn-outline-navy" href="/announcements"><i class="bi bi-list-ul" aria-hidden="true"></i> All announcements</a><a class="btn btn-navy" href="<?= e($backPath) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a></div>
</article>
