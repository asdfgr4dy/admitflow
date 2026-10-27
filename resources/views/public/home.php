<div class="public-homepage">
    <section class="home-hero" aria-labelledby="home-title">
        <div class="container-xl home-hero-grid">
            <div class="home-hero-copy">
                <p class="home-kicker"><span aria-hidden="true"></span> Online admissions · Admission Office</p>
                <h1 id="home-title">Your next chapter<br><em>starts here.</em></h1>
                <p class="home-lede">Explore available programs, prepare your documents, and manage your admission application online.</p>
                <div class="home-actions">
                    <a class="btn btn-navy btn-lg" href="/register">Apply now <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                    <a class="btn home-login-btn btn-lg" href="/login">Log in <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                </div>
            </div>
            <aside class="home-intake" aria-label="Application period details">
                <div class="home-intake-head"><i class="bi bi-calendar2-check" aria-hidden="true"></i><span>Application period</span></div>
                <div class="home-intake-row"><span>Intake</span><strong><?= e($academicYear['label'] ?? 'Details to be announced') ?></strong></div>
                <div class="home-intake-row"><span>Deadline</span><strong><?= !empty($academicYear['application_deadline']) ? e(date('F j, Y', strtotime($academicYear['application_deadline']))) : 'To be announced' ?></strong></div>
                <a href="#programs">Explore programs <i class="bi bi-arrow-down" aria-hidden="true"></i></a>
            </aside>
        </div>
    </section>

    <?php if ($announcements !== []): ?>
        <section class="home-announcements" aria-label="Admission announcements">
            <div class="container-xl home-announcements-inner">
                <h2><i class="bi bi-megaphone" aria-hidden="true"></i> Office updates</h2>
                <div class="home-announcement-list">
                    <?php foreach ($announcements as $announcement): ?>
                        <article><h3><?= e($announcement['title']) ?></h3><p><?= e(mb_strimwidth($announcement['body'], 0, 220, '...')) ?></p><a href="<?= e('/announcement/' . (int) $announcement['id']) ?>">Read full announcement</a></article>
                    <?php endforeach; ?>
                </div>
                <a class="btn btn-sm btn-outline-navy" href="/announcements">All public announcements</a>
            </div>
        </section>
    <?php endif; ?>

    <section class="home-section home-programs" id="programs" aria-labelledby="programs-title">
        <div class="container-xl">
            <div class="home-section-heading">
                <div><p class="home-kicker">Explore your options</p><h2 id="programs-title">Programs of study</h2></div>
                <p><?= count($programs) ?> active program<?= count($programs) === 1 ? '' : 's' ?></p>
            </div>
            <?php if ($programs === []): ?>
                <p class="home-empty">Program information will be published here when available.</p>
            <?php else: ?>
                <div class="home-program-grid">
                    <?php foreach ($programs as $index => $program): ?>
                        <article class="home-program-card">
                            <span class="home-card-index"><?= sprintf('%02d', $index + 1) ?></span>
                            <div><h3><?= e($program['name']) ?></h3><p><?= e($program['code']) ?></p></div>
                            <a href="/register" aria-label="Apply to <?= e($program['name']) ?>"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="home-section home-process" id="process" aria-labelledby="process-title">
        <div class="container-xl">
            <div class="home-section-heading home-process-heading">
                <div><p class="home-kicker">A clear path forward</p><h2 id="process-title">The application process</h2></div>
                <p>Follow your progress from account creation through the next steps provided by the Admission Office.</p>
            </div>
            <ol class="home-step-grid">
                <?php foreach (['Create your account', 'Complete your application', 'Upload the required documents', 'Track your application', 'Follow enrollment instructions'] as $index => $step): ?>
                    <li><span class="home-step-number"><?= sprintf('%02d', $index + 1) ?></span><h3><?= e($step) ?></h3></li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <section class="home-section home-requirements" id="requirements" aria-labelledby="requirements-title">
        <div class="container-xl">
            <div class="home-section-heading">
                <div><p class="home-kicker">Prepare before you begin</p><h2 id="requirements-title">Admission requirements</h2></div>
                <p>Review the currently configured required documents before starting your application.</p>
            </div>
            <?php if ($requirements === []): ?>
                <p class="home-empty">Requirements will be published when the Admission Office configures the intake.</p>
            <?php else: ?>
                <ul class="home-requirement-grid">
                    <?php foreach ($requirements as $requirement): ?>
                        <li class="home-requirement-card"><i class="bi bi-file-earmark-check" aria-hidden="true"></i><span><strong><?= e($requirement['name']) ?></strong><?php if (!empty($requirement['instructions'])): ?><small><?= e($requirement['instructions']) ?></small><?php endif; ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <section class="home-cta" id="privacy" aria-labelledby="home-cta-title">
        <div class="container-xl home-cta-inner">
            <div><p class="home-kicker">Your application, in one place</p><h2 id="home-cta-title">Ready when you are.</h2><p>Create an account to begin and return to your application as you work.</p></div>
            <a class="btn btn-amber btn-lg" href="/register">Apply now <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
        </div>
    </section>
</div>
