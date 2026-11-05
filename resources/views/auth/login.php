<section class="auth-shell container-xl">
    <div class="auth-aside"><p class="eyebrow">Admission Office · Secure access</p><h1>Welcome back.</h1><p>Sign in with your AdmitFlow account to continue. Your account permissions are applied automatically.</p><div class="aside-note"><i class="bi bi-lock" aria-hidden="true"></i><span>Sign-in attempts are limited. Your session ends after 30 minutes of inactivity.</span></div></div>
    <div class="auth-form-wrap">
        <p class="eyebrow">Student and staff access</p><h2>Sign in</h2>
        <?php if (!empty($result) && ($result['error'] ?? '') === 'cooldown' && ($cooldownSeconds ?? 0) > 0): ?>
            <div class="alert alert-danger" role="alert" data-login-cooldown="<?= (int) $cooldownSeconds ?>">
                <strong data-cooldown-message><?= e($result['message']) ?></strong>
                <span class="d-block" data-cooldown-countdown aria-live="off">Time remaining: <?= intdiv((int) $cooldownSeconds, 60) ?>:<?= str_pad((string) ((int) $cooldownSeconds % 60), 2, '0', STR_PAD_LEFT) ?></span>
            </div>
        <?php elseif (!empty($result)): ?>
            <div class="alert alert-warning" role="alert"><?= e($result['message']) ?></div>
        <?php endif; ?>
        <?php $successMessage = take_flash(); if ($successMessage !== null): ?><div class="alert alert-success" role="status"><?= e($successMessage['message']) ?></div><?php endif; ?>
        <form method="post" action="/login" class="form-stack" autocomplete="on">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <label class="form-label" for="email">Email address</label>
            <input class="form-control" id="email" name="email" type="email" maxlength="254" autocomplete="username" value="<?= e($email ?? '') ?>" required>
            <label class="form-label" for="password">Password</label>
            <div class="input-group">
                <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
                <button class="btn btn-outline-secondary" type="button" data-password-toggle aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <label class="form-label" for="captcha_answer">Security check <span class="captcha-question"><?= e($captcha) ?> = ?</span></label>
            <input class="form-control captcha-input" id="captcha_answer" name="captcha_answer" inputmode="numeric" pattern="[0-9]*" autocomplete="off" required>
            <button class="btn btn-amber btn-lg w-100" type="submit" data-login-submit <?= ($cooldownSeconds ?? 0) > 0 ? 'disabled' : '' ?>>Sign in <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
        </form>
        <p class="form-footnote"><a href="/forgot-password">Forgot your password?</a></p>
        <p class="form-footnote">New applicant? <a href="/register">Create a student account</a></p>
        <?php
        $demoRoleDetails = [
            'admin' => ['name' => 'Administrator', 'initial' => 'A', 'description' => 'Explore system and queue controls.'],
            'reviewer' => ['name' => 'Admissions reviewer', 'initial' => 'R', 'description' => 'Review queued applications.'],
            'student' => ['name' => 'Student applicant', 'initial' => 'S', 'description' => 'Explore the applicant portal.'],
        ];
        ?>
        <?php if (!empty($demoLoginIdentities)): ?>
            <section class="demo-login-section" aria-label="Local demo access">
                <div class="demo-local-divider"><span>Local demo only</span><span class="demo-local-tag">DEMO</span></div>
                <details class="demo-account-details">
                    <summary class="demo-account-summary"><span>Try a demo account</span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                    <div class="demo-account-list">
                        <?php foreach ($demoLoginIdentities as $key => $identity): if (!isset($demoRoleDetails[$key])) continue; $demoRole = $demoRoleDetails[$key]; ?>
                            <div class="demo-account-row" role="group" aria-label="<?= e($demoRole['name']) ?>">
                                <span class="demo-account-avatar" aria-hidden="true"><?= e($demoRole['initial']) ?></span>
                                <span class="demo-account-copy"><strong><?= e($demoRole['name']) ?></strong><small><?= e($demoRole['description']) ?></small></span>
                                <button class="btn btn-sm btn-outline-navy demo-account-use" type="button" data-demo-login data-demo-email="<?= e($identity['email']) ?>" data-demo-password="<?= e($identity['password']) ?>" aria-label="Use <?= e($demoRole['name']) ?> demo account">Use</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </details>
            </section>
        <?php endif; ?>
    </div>
</section>