<article class="prd-page container-xl technical-requirements-page" aria-labelledby="technical-title">
    <header class="prd-heading">
        <p class="prd-kicker"><i class="bi bi-cpu" aria-hidden="true"></i> AdmitFlow · Engineering documentation</p>
        <h1 id="technical-title">Technical Requirements Document</h1>
        <p class="prd-lede">The supported application stack, runtime configuration, deployment boundaries, security controls, and verification requirements for operating AdmitFlow.</p>
        <dl class="prd-meta">
            <div><dt>Product</dt><dd>AdmitFlow</dd></div>
            <div><dt>Owner</dt><dd>Admission Office</dd></div>
            <div><dt>Baseline</dt><dd>PHP 8.2+ · MySQL/MariaDB</dd></div>
            <div><dt>Updated</dt><dd>November 2026</dd></div>
        </dl>
    </header>

    <div class="prd-layout">
        <nav class="prd-toc" aria-label="Document sections">
            <p class="prd-toc-title">On this page</p>
            <a href="#runtime">Runtime and platform</a>
            <a href="#architecture">Application architecture</a>
            <a href="#dependencies">Dependencies</a>
            <a href="#configuration">Configuration</a>
            <a href="#data-storage">Database and storage</a>
            <a href="#security">Security requirements</a>
            <a href="#operations">Operations and testing</a>
            <a href="#acceptance">Deployment acceptance</a>
        </nav>

        <div class="prd-document">
            <section class="prd-section" id="runtime">
                <p class="prd-section-label">01 · Supported environment</p>
                <h2>Runtime and platform</h2>
                <dl class="prd-role-list">
                    <div><dt>PHP</dt><dd>PHP 8.2 or newer. The project has been developed against PHP 8.2; the XAMPP Apache bundle documented for the local machine is PHP 8.0 and is below this baseline.</dd></div>
                    <div><dt>PHP extensions</dt><dd>Production requires PDO with the MySQL driver, OpenSSL, fileinfo, mbstring, and PHP sessions. PDO SQLite is required to run the isolated security test suite, but is not the application database driver.</dd></div>
                    <div><dt>Database</dt><dd>MySQL or MariaDB using InnoDB-compatible transactional behavior and the supplied UTF-8 schema. Local verification has used MariaDB 10.4.32; production version compatibility must be confirmed against the target host before release.</dd></div>
                    <div><dt>Web server</dt><dd>Apache configured with the project rewrite rules and the document root set to the repository's <code>public/</code> directory. The PHP built-in server is for local development only and must not be exposed publicly.</dd></div>
                    <div><dt>Browser</dt><dd>A modern browser with JavaScript enabled for navigation, charts, interactive controls, and application workflows. Core pages are server-rendered HTML.</dd></div>
                </dl>
                <p>Server and database clocks should use UTC for stored event timestamps; the application presents user-facing interview and scheduling times in Asia/Manila.</p>
            </section>

            <section class="prd-section" id="architecture">
                <p class="prd-section-label">02 · Request and data flow</p>
                <h2>Application architecture</h2>
                <ul>
                    <li>PHP server-rendered application with a front controller in <code>public/index.php</code>, local development routing through <code>public/router.php</code>, and shared templates under <code>resources/views/</code>.</li>
                    <li>Application services under <code>app/</code> own authentication, applications, staff workflows, notifications, reporting, uploads, mail, and account settings.</li>
                    <li>PDO prepared statements connect to MySQL/MariaDB. The isolated test suite uses in-memory SQLite fixtures for supported logic.</li>
                    <li>Browser assets are delivered from <code>public/assets/</code>; the interface uses Bootstrap styling, Bootstrap Icons, Chart.js, and project JavaScript.</li>
                    <li>Uploaded documents and avatars are stored as private files outside the public document root. The database stores their metadata and ownership relationships.</li>
                </ul>
                <p>AdmitFlow is not a single-page application and does not expose a general public JSON API. Requests use the existing server-rendered routes and role-scoped authorization.</p>
            </section>

            <section class="prd-section" id="dependencies">
                <p class="prd-section-label">03 · Runtime packages</p>
                <h2>Dependencies</h2>
                <dl class="prd-role-list">
                    <div><dt>PHP packages</dt><dd>Install from <code>composer.json</code> with Composer. Dompdf 3.1.6 provides PDF report generation; PHPMailer 6.9 or compatible 6.x provides SMTP delivery.</dd></div>
                    <div><dt>Browser libraries</dt><dd>Bootstrap 5.3.3, Bootstrap Icons 1.11.3, and Chart.js 4.4.7 are currently referenced from jsDelivr. The content security policy permits those CDN origins.</dd></div>
                    <div><dt>Package lifecycle</dt><dd>Keep Composer's lock file and installed packages aligned with the release. Review and test dependency updates before deployment; do not install packages from user-controlled input.</dd></div>
                </dl>
                <p>Production must allow the configured asset CDN or serve equivalent reviewed assets locally. If asset hosting changes, update the content security policy and verify all styles, icons, scripts, and charts still load.</p>
            </section>

            <section class="prd-section" id="configuration">
                <p class="prd-section-label">04 · Environment contract</p>
                <h2>Configuration</h2>
                <ul>
                    <li><strong>Application mode:</strong> set <code>ADMITFLOW_APP_ENV</code> to the intended environment. Local-only exceptions must not be enabled in production.</li>
                    <li><strong>Database:</strong> provide <code>ADMITFLOW_DB_HOST</code>, <code>ADMITFLOW_DB_PORT</code>, <code>ADMITFLOW_DB_DATABASE</code>, <code>ADMITFLOW_DB_USERNAME</code>, <code>ADMITFLOW_DB_PASSWORD</code>, and <code>ADMITFLOW_DB_CHARSET=utf8mb4</code>, or use a private ignored <code>config/database.local.php</code>.</li>
                    <li><strong>Application URL:</strong> set <code>ADMITFLOW_APP_URL</code> to the canonical HTTPS URL outside local mode.</li>
                    <li><strong>Email:</strong> configure the required <code>ADMITFLOW_MAIL_*</code> values when SMTP is enabled. Use port 587 with STARTTLS or port 465 with implicit SSL; do not fall back to plaintext transport.</li>
                    <li><strong>Reverse proxy:</strong> set <code>ADMITFLOW_TRUST_PROXY=1</code> only when a trusted proxy overwrites the forwarded protocol header and direct requests cannot bypass it.</li>
                </ul>
                <p>Environment variables override the private database and mail config files. The application does not load <code>.env</code> files automatically. Keep credentials and demo-account secrets in protected deployment settings or a secret manager, never in tracked source or public assets.</p>
            </section>

            <section class="prd-section" id="data-storage">
                <p class="prd-section-label">05 · Persistence boundaries</p>
                <h2>Database and file storage</h2>
                <ul>
                    <li>The current schema is <code>database/schema.sql</code> and contains 20 MySQL/MariaDB tables. Use <code>database/seeds/001_reference_data.sql</code> only for a new environment that needs the sample reference data.</li>
                    <li>Apply reviewed migrations in order when upgrading an existing deployment. Never re-import the base schema or seed over production records.</li>
                    <li>Use a dedicated non-root database account with a non-empty password in every non-local environment. Grant only the database permissions required by the app and its approved migrations.</li>
                    <li>Keep the project root, <code>config/</code>, and <code>storage/</code> inaccessible from the web. Apache's document root must be <code>public/</code>.</li>
                    <li>Ensure the PHP process can write to the required private upload, avatar, log, and local mailbox directories while preventing public or unrelated users from reading private files.</li>
                    <li>Set upload limits deliberately. The documented local server uses <code>upload_max_filesize=10M</code>, <code>post_max_size=60M</code>, and <code>max_file_uploads=10</code>; production values must accommodate approved per-requirement limits and host policy.</li>
                </ul>
                <p>Administrators can create and restore verified full backups from <code>/admin/backups</code>. Archives contain a MySQL/MariaDB dump, application source/schema/scripts and dependency manifests, private uploads, avatars, a manifest, and SHA-256 checksums. Local credentials, installed dependencies, logs, and prior backup archives are excluded. The default private destination is <code>storage/backups/</code>; it must remain outside the public web root, with the PHP process allowed to write there.</p>
                <ul>
                    <li>Configure <code>config/backup.local.php</code> or protected environment settings for the backup directory and <code>tar</code>, <code>mysqldump</code>, and <code>mysql</code> executable paths. The hosting environment must permit the PHP process to start these commands.</li>
                    <li>Schedule <code>php scripts/backup.php</code> weekly with Windows Task Scheduler, cPanel Cron Jobs, or the host's equivalent. The application provides the runner but does not create operating-system schedules. Eight verified full backups are retained by default.</li>
                    <li>Restore is administrator-only, requires a verified archive and explicit confirmation, enables maintenance mode, and creates a fresh safety backup before replacing the database and uploaded-file directories. A failed operation attempts to restore the safety backup; if rollback fails, maintenance mode remains enabled for operator recovery.</li>
                    <li>The restore operation does not overwrite the currently deployed PHP source. Deploy the matching application release separately. Keep an off-server backup copy to recover from hosting-server loss; the local schedule alone is not off-site disaster protection.</li>
                </ul>
            </section>

            <section class="prd-section" id="security">
                <p class="prd-section-label">06 · Production safeguards</p>
                <h2>Security requirements</h2>
                <ul>
                    <li>Serve production traffic over HTTPS. Keep session cookies secure, HttpOnly, SameSite=Lax, and use the trusted-proxy option only for a correctly controlled proxy.</li>
                    <li>Use the application's password hashing, CSRF defenses, request validation, session regeneration and timeout, login rate limits, output escaping, security headers, and prepared database statements.</li>
                    <li>Enforce role, application-scope, and record-ownership checks on the server for every protected route and file action.</li>
                    <li>Keep uploads private; validate extension, detected MIME type, size, ownership, and checksum as applicable. Never serve files by guessing their storage name.</li>
                    <li>Use expiring, single-use, hashed password-reset and staff-invitation tokens. Do not log or disclose passwords, tokens, session identifiers, or SMTP/database secrets.</li>
                    <li>Record security-relevant workflow and administrative events in the audit log while limiting displayed/exported personal information.</li>
                </ul>
            </section>

            <section class="prd-section" id="operations">
                <p class="prd-section-label">07 · Verification and service limits</p>
                <h2>Operations and testing</h2>
                <ul>
                    <li>Run <code>php scripts/check-database.php</code> in the intended environment to verify connectivity and required tables. The command performs read-only checks.</li>
                    <li>Run <code>php tests/run-security-tests.php</code> for isolated policy and workflow coverage; it uses in-memory SQLite and does not connect to the configured live database.</li>
                    <li>Run focused browser and HTTP tests for routes, authorization, file delivery, mail configuration, responsive behavior, and production web-server rewrites before release.</li>
                    <li>Keep error display disabled in production. Review application and web-server logs for actionable failures without recording secrets or unnecessary personal data.</li>
                    <li>CSV report export is limited to 5,000 rows and indicates when results exceed the limit.</li>
                </ul>
                <p>No production availability SLA, performance target, recovery time objective, or recovery point objective is defined by the current project. These must be agreed and tested with the deployment owner before operational use.</p>
            </section>

            <section class="prd-section" id="acceptance">
                <p class="prd-section-label">08 · Release gate</p>
                <h2>Deployment acceptance</h2>
                <ul class="prd-checklist">
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>PHP meets the supported baseline and required production extensions are enabled; Composer dependencies install successfully from the release lock file.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>The database checker reports the expected database and tables, and no setup schema or seed is applied over existing data.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>The web root is restricted to <code>public/</code>; private configuration, uploads, logs, and backups cannot be fetched over HTTP.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>HTTPS, secure session behavior, least-privilege database access, SMTP TLS, secret storage, weekly backup scheduling, private/off-server backup storage, and a tested restore procedure are verified.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Automated tests pass, and authorized/unauthorized role workflows and responsive core pages have been checked in the target environment.</span></li>
                </ul>
                <p class="prd-document-note">Local port numbers, XAMPP paths, credentials, PHP upload overrides, and example SMTP values are environment-specific. Confirm actual production values with the hosting provider; do not copy local settings into production.</p>
            </section>
        </div>
    </div>
</article>