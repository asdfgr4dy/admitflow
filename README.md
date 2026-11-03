# admitflow

Online Student Admission Management System for the Admission Office. PHP 8.2+, PDO, and MySQL/MariaDB; server-rendered pages with Bootstrap 5 and Bootstrap Icons.

## What's implemented

- Public homepage with the supplied logo, active programs, configured requirements, announcements, and current open-intake details.
- Student registration with server validation, privacy acknowledgement, password hashing, and audit events.
- Student and staff login with server-generated arithmetic CAPTCHA, failed-attempt limits, session regeneration, inactivity timeout, secure cookie settings, and logout.
- Password reset email through PHPMailer SMTP, with responsive HTML/plain-text templates, cryptographically random hashed tokens, 30-minute expiry, single-use checks, account-enumeration-safe responses, and delivery-failure invalidation.
- Authenticated password change requiring the current password; successful changes invalidate reset tokens, record an audit event, and regenerate the session.
- Student portal with owned application status, checklist, and announcements.
- Audience-scoped public/student/staff announcement lists and full details; Admin-only management is available from `/staff/announcements`, with derived publication states and audited deletion. The current schema has no announcement priority or per-user read/acknowledgement state, so announcements are not marked urgent and no unread-announcement count is shown.
- Recipient-scoped notification inbox and mark-as-read action.
- Four-step application form with draft saving, final submission, reference generation, status history, private document uploads, MIME/extension/size validation, and owner-checked downloads.
- Normalized database schema, fictional program/intake/requirement reference data, CSRF tokens, escaped view output, and PDO prepared statements.
- Reviewer queue, filters, operational counts, application review detail, document verification/rejection feedback, staff-only notes, status transitions, notifications, audit events, and admin reviewer assignment.
- Filtered Staff/Admin report summary and CSV export with shared A4 print styling; print output excludes the authenticated application shell while preserving paginated report tables.
- Shared Bootstrap toast feedback reuses the one-shot session flash, with escaped messages, accessible success/warning/error states, and response-checked notification-action feedback. Shared server-side pagination covers application queues, on-screen reports, account categories, audit events, announcement lists, notification feeds, sign-in history, and application-detail histories; CSV exports keep their existing limits and behavior.
- Student-owned printable application summaries, acceptance letters only for accepted/enrolled applications, and enrollment confirmations only for enrolled applications with a recorded completed enrollment and completion date.
- Admin-managed program and academic-year/deadline records; database-backed six-chart dashboard for monthly submissions, status distribution, program demand, submitted versus first-reviewed activity, open-intake status counts, and monthly average review time. Chart series use UTC dates and reviewer-scoped application records where applicable, with empty-data states and no invented targets.
- Replacement upload for a rejected or action-required document, preserving prior versions; rejected review automatically moves the application to the incomplete-documents state.
- Admin user list, staff/reviewer account creation with emailed one-time setup invitations, safe resend, role and account-status management, audit logging, and final-active-admin protection.
- Admin CRUD screens for scoped admission requirements and audience/schedule-based announcements.
- Interview scheduling with future-time and interviewer-conflict validation, Asia/Manila input, UTC storage, application history update, and student notification.
- Interview outcome recording and an administrator-controlled enrollment readiness checklist; accepted applications cannot transition to enrolled until the checklist is complete.
- Separate login-failure limits: 5 failures per email identifier and 30 failures per IP within 15 minutes. Requests already over a limit and invalid CAPTCHA submissions do not add failures, preventing trivial lockout extension.
- Application drafts now validate name, contact number, birth date, address, and bounded text fields before writes; program and academic-year identifiers must be valid active/open database records. Per-student locking serializes draft creation and submission, and final submission remains conditional on draft status to prevent duplicate retries.
- Document replacement eligibility is checked inside the transaction with application/document row locks on MySQL/MariaDB. Failed replacement operations roll back file/database mutations, remove any newly moved private file, and best-effort record a failure audit event. Prior versions and their review feedback remain accessible to authorized staff; student downloads are limited to current versions.
- Document review enforces the staff role in the service, records feedback and notifications consistently with audit events, and returns an incomplete application to screening once all required current documents are verified. Status changes use the shared transition policy; acceptance requires verified required documents and enrollment requires an admin plus a completed checklist.
- Reviewer visibility is explicitly preserved as assigned-to-the-reviewer or unassigned applications; admins see all applications. Direct detail/document access follows the same scope, and reviewer assignment changes remain admin-only.
- Status choices in the staff detail view now come from `StatusTransitionPolicy`, including the permitted `submitted` to `documents_incomplete` path used when a reviewer rejects a document before moving the application into screening. Document review locks the parent application before its document, matching status and replacement lock ordering.
- Interview management supports scheduling, conflict-safe rescheduling, completion/no-show/cancellation outcomes, applicant notifications, audit events, and student-owned interview history in Asia/Manila time. Interview outcome writes require the parent application to remain in the active interview workflow. Cancelling the last scheduled interview returns an `interview_scheduled` application to `in_screening` through the shared transition policy; an application with another scheduled interview remains scheduled.
- Enrollment preparation remains administrator-only. The service validates the checklist keys and rechecks accepted status and required-document verification under an application lock. Its unique application key and portable upsert prevent duplicate enrollment records while audit failures roll back checklist changes.
- Staff dashboard metrics include assigned-or-unassigned upcoming interviews and enrollment readiness counts, respecting reviewer scope. Report filters now reject malformed dates, status/IDs, reversed date ranges, inactive reviewers, and unsupported document filters; report summaries cover all authorized matching records, including full-set document totals and average review time. CSV remains capped at 5,000 rows and now includes an explicit truncation notice when the filtered set exceeds that cap.

Not implemented yet: report exports beyond 5,000 rows. Intake progress is a count of current non-draft applications by workflow status for the most recently started open academic year; the schema has no target/capacity field, so the chart does not represent completion percentage. Reset and staff invitation email use PHPMailer over explicitly configured SMTP. An optional reset-only development mailbox is disabled by default and available only when `ADMITFLOW_APP_ENV=local`, SMTP is disabled, and `ADMITFLOW_MAIL_LOCAL_FALLBACK=1`; it stores `.eml` messages under ignored `storage/mailbox/`, not logs, and is not email or a replacement for SMTP. Staff invitations never use that fallback. Reset requests and invitation resends are limited to 3 per account per 15 minutes. Failed delivery invalidates the generated token.

## Local discovery and environment

- PHP CLI: 8.2.33 with PDO MySQL. Composer is available; PHPMailer is used for SMTP delivery.
- Database host, port, database, and credentials can be supplied through `ADMITFLOW_DB_*` environment variables or a private `config/database.local.php` file.
- XAMPP Apache bundles PHP 8.0.30, below the requested version. Use the PHP 8.2 CLI server below unless Apache is separately configured to use PHP 8.2.
- Git was not found on PATH.
- The original `logo.png` is preserved; a byte-identical copy is served at `public/assets/logo.png`.
- Product identity follows the master brief: Admission Office is the only office branding; no university name or physical location is displayed.

## Setup

1. Start MariaDB in XAMPP.
2. Create a local database named `admitflow` with `utf8mb4` if it does not already exist. The current machine's database already has the schema.
3. Copy `config/database.example.php` to `config/database.local.php` and set local connection values. This local file is excluded by `.gitignore`. The example uses XAMPP MariaDB port 3307; set the actual port for your instance. Never copy example credentials into production. For deployment, create a least-privilege database user and use protected environment variables or a private config file.
4. If setting up a clean database, import `database/schema.sql`, then `database/seeds/001_reference_data.sql`. These are setup operations; do not re-import over useful data.
5. In PowerShell, set `$env:ADMITFLOW_APP_ENV = 'local'` for local XAMPP CLI commands, then run `php scripts/check-database.php`. It verifies connectivity and expected table names using read-only queries. Non-local connections reject the MySQL `root` account and blank passwords.
6. Install the mail dependency from the project directory:

   ```powershell
   composer install
   ```

7. Configure mail using protected environment variables or copy `config/mail.example.php` to the ignored `config/mail.local.php`, replace its placeholders, and set `'enabled' => true`. Environment values override the private file. Never put real credentials in tracked files, browser code, logs, or chat. Alternatively, set the values in the same PowerShell terminal before starting the server:

   ```powershell
   $env:ADMITFLOW_APP_ENV = 'local'
   $env:ADMITFLOW_MAIL_ENABLED = '1'
   $env:ADMITFLOW_MAIL_LOCAL_FALLBACK = '0'
   $env:ADMITFLOW_MAIL_HOST = 'smtp.gmail.com'
   $env:ADMITFLOW_MAIL_PORT = '587'
   $env:ADMITFLOW_MAIL_USERNAME = 'your-gmail-address@gmail.com'
   $env:ADMITFLOW_MAIL_PASSWORD = 'paste-your-google-app-password-here'
   $env:ADMITFLOW_MAIL_ENCRYPTION = 'tls'
   $env:ADMITFLOW_MAIL_FROM_ADDRESS = 'your-gmail-address@gmail.com'
   $env:ADMITFLOW_MAIL_FROM_NAME = 'AdmitFlow Admissions'
   $env:ADMITFLOW_MAIL_REPLY_TO = 'help@example.test'
   $env:ADMITFLOW_APP_URL = 'http://127.0.0.1:8000'
   ```

   `ADMITFLOW_MAIL_REPLY_TO` is optional; the remaining mail settings are required when mail is enabled. `ADMITFLOW_APP_URL` must be an absolute HTTPS base URL outside local mode; local HTTP is restricted to localhost/loopback. The app does not load `.env` files automatically. Keep environment secrets in the process manager or hosting provider's secret store.

   Provider examples:

   - Gmail: use `smtp.gmail.com`, port `587`, encryption `tls`, and a Google App Password for an account with 2-Step Verification where App Passwords are available. Never use the normal Google account password. Use a permitted sender address.
   - SMTP testing service: use the provider's assigned host, port, username, and password, and restrict recipients to test inboxes. Providers such as Mailtrap offer non-production SMTP inboxes; values are account-specific and must not be copied into source.
   - Hosting provider: use the host's verified SMTP endpoint and credentials from its protected secret manager. Confirm outbound SMTP is allowed and the sender domain/address is verified.

   STARTTLS on port `587` with `ADMITFLOW_MAIL_ENCRYPTION=tls` is the recommended setup. For implicit SSL use port `465` and `ssl`. The Admin System Settings page shows whether configuration is present and includes a CSRF-protected SMTP test action with an explicitly entered recipient. The result means only that the SMTP provider accepted the message, not that it reached an inbox. Automated tests use mocked transport and never send email.

The optional local password-reset mailbox is not a substitute for SMTP. To explicitly enable it for local reset-link development only, set `$env:ADMITFLOW_MAIL_ENABLED = '0'` and `$env:ADMITFLOW_MAIL_LOCAL_FALLBACK = '1'` while `ADMITFLOW_APP_ENV=local`, then open the private `.eml` message under `storage/mailbox/`. It is disabled by default, never runs outside local mode, and is never used when SMTP is enabled but misconfigured or unavailable. Staff invitation delivery does not use this fallback. The public Forgot Password response remains generic.

Password reset requests keep the same generic public response for known and unknown addresses. Password reset and staff setup links use the existing `/reset-password` route and password policy, expire after 30 minutes, store only SHA-256 token hashes, and are single-use. Staff accounts stay created if SMTP fails; the admin sees that delivery failed and can resend from the Staff Accounts list after fixing configuration. Resending invalidates any earlier unused link, and invitation requests share a 3-per-account/15-minute rate limit. No password is emailed. No database migration is required because invitations reuse the existing password reset token table and setup route.

Troubleshooting: connection timeouts usually indicate an incorrect host/port or blocked outbound SMTP; authentication failures often mean the provider requires an app password or verified sender; TLS negotiation failures usually mean the encryption mode and port do not match. Do not enable plaintext SMTP or weaken TLS to work around provider errors. Production must keep credentials in protected environment variables or a secret manager, use HTTPS for `ADMITFLOW_APP_URL`, and never deploy `config/mail.local.php` from a developer machine.

8. Start the PHP 8.2 development server from the project directory:

   ```powershell
   $env:ADMITFLOW_APP_ENV='local'; $env:ADMITFLOW_APP_URL='http://127.0.0.1:8000'; php -d upload_max_filesize=10M -d post_max_size=60M -d max_file_uploads=10 -S 127.0.0.1:8000 -t public public/router.php
   ```

9. Visit `http://127.0.0.1:8000`. Do not expose the development server to a public network.

With `$env:ADMITFLOW_APP_ENV = 'local'` set in PowerShell, create a reviewer or the first administrator from a terminal with `php scripts/create-staff-user.php "Full name" person@example.test admin`. Create a local student fixture with `php scripts/create-student-user.php "Full name" student@example.test`. These CLI-only commands generate a random one-time password and display it once. Store it securely. The staff command refuses to create another administrator when an active one already exists.

Apache must point its document root to the project's `public/` directory and allow the included rewrite rules. Never make the project root, `config/`, or `storage/` publicly accessible. Production requires HTTPS, a supported PHP runtime, a least-privilege DB account, private file storage, protected secrets, and reviewed Apache/PHP settings.

## HTTPS in production

In production, point Apache at `public/` and allow `.htaccess` rewrites; non-loopback HTTP requests are redirected to HTTPS. If TLS terminates at a trusted reverse proxy, configure it to overwrite `X-Forwarded-Proto` and set `ADMITFLOW_TRUST_PROXY=1` for PHP so session cookies are marked Secure. Leave this setting at `0` unless requests can only reach the app through that trusted proxy. HSTS is sent only for effective HTTPS requests outside local mode and on non-loopback hosts. Local development at `http://127.0.0.1:8000` remains HTTP and receives no HSTS.

## Database and records

`database/schema.sql` is the current 20-table MySQL/MariaDB schema. It includes users/profiles, academic years/programs/requirements, applications, versioned documents/reviews, assignments, notes, interviews/enrollment, announcements/notifications, status history, audit events, password tokens, login attempts, retained legacy backup metadata, and settings. The legacy `backups` table is intentionally left in place; removing it requires a separately reviewed migration.

Generated unique keys enforce program/year-scoped requirement codes and a single current version for a requirement on an application. Application history and documents use restrictive foreign keys; application soft-delete support is provided with `deleted_at`.

`database/seeds/001_reference_data.sql` contains fictional sample lookup values only. It creates seven academic programs, five requirements, a sample AY 2026–2027 intake, and general office settings. It creates no accounts. Replace and review sample values before real use.

User-provided institution identity, address, phone, contact email, and office hours have not been invented. Public contact configuration still needs an admin settings feature.

## Security notes

- Passwords use `password_hash()` and `password_verify()`.
- Login CAPTCHA answers are stored only in the server session.
- Login failures are rate-limited by email/IP for a rolling 15-minute window.
- Rate limiting uses independent thresholds: 5 failed, CAPTCHA-completed attempts per normalized account identifier and 30 per source IP in 15 minutes. It is temporary, not a permanent lockout; blocked and CAPTCHA-invalid submissions do not increment counts. Shared IP traffic below its own ceiling does not lock other account identifiers.
- Password-reset tokens are stored as SHA-256 hashes and expire after 30 minutes. Local delivery is a private development mailbox file, not email; production mail is disabled unless explicitly configured.
- Password changes require the current password and invalidate outstanding reset tokens. Password-change support is available at `/account/password` for signed-in users.
- Session cookie flags, strict mode, regeneration, inactivity timeout, CSRF validation, security response headers, and output escaping are implemented.
- Active account status and role are reloaded from the database on every routed request, so role changes/deactivation take effect without waiting for session expiry.
- Student application reads and document downloads include owner checks. Uploads use private `storage/uploads`, randomized storage names, server-side MIME detection, configured size/extension checks, and SHA-256 file checksums.
- Reports use role-scoped application filters, CSV formula-prefix escaping, minimal personal data, and a 5,000-record export cap. Audit events are displayed without edit/delete operations.
- This is a student project, not a formal security certification. Broader end-to-end security testing remains outstanding.

The Backup and Restore interface, routes, services, and scheduler have been removed. The legacy `backups` metadata table and existing private backup files are intentionally retained. Any future database-table cleanup requires a separately reviewed migration.

## Security tests

Run the dependency-free isolated suite with:

```powershell
php tests/run-security-tests.php
```

It requires PHP 8.2 with PDO SQLite, OpenSSL, and fileinfo. It creates an in-memory SQLite database and temporary SQL fixtures only; it does not load `config/database.local.php`, connect to MariaDB, or modify application records. It covers CSRF/role/ownership policies, separate rate limits and recovery windows, reset token hashing/expiry/replay/delivery failure, password change/current-password verification/reset invalidation/audit rollback, application draft/submission validation and rollback, duplicate submissions, reviewer queue/direct-ID authorization, document review and audit/notification consistency, interview scheduling/rescheduling/conflicts/cancellation/outcomes/ownership/rollback, enrollment eligibility/checklist upsert/rollback, dashboard scope, report filters/summaries/export auditing, status transitions, upload policy. MySQL-specific concurrency, browser accessibility, actual mail transport, and CSV download completion require separate verification.

## Manual smoke test

1. Open the public homepage and verify programs and requirements load from MariaDB.
2. Register a new student and verify it redirects to login with a success message.
3. Sign in with the new account; solve the displayed arithmetic challenge.
4. Confirm the portal displays an empty-state application prompt.
5. Start an application, enter applicant and academic details, and save a draft.
6. Return to the portal and confirm the draft is associated with the signed-in account.
7. Try a second account and verify it cannot access the first account's portal or documents.
8. Submit a completed application with all configured sample requirement files; confirm reference and submitted state.
9. Verify invalid CAPTCHA/credentials fail and repeated failures are throttled.
10. Verify upload type/size errors are shown and files are not reachable by guessed public URLs.

Browser smoke tests previously run: homepage/database content and static asset delivery; student registration, CAPTCHA login, draft save, and two-account portal isolation; a complete five-document test submission; reviewer login, application queue, document verification, and status transition; admin report, CSV response, settings/audit views; and active-account session invalidation. The account `qa-replace@example.test` remains active by explicit preservation request; latest read-only check found zero applications and zero documents for it. Current local DB also contains the intentionally created `admin@example.test`, `reviewer@example.test`, and `student@example.test` demonstration accounts; preserve these unless the owner requests otherwise.

The XAMPP MariaDB error log previously contained InnoDB assertion failures. Database connectivity is currently responding, but do not delete data files, reset the database, or re-import the schema as a workaround. Follow an approved recovery plan before database maintenance.

Verified by isolated tests in Phase 1: 45 assertions passed for reset expiration/reuse/delivery failure, password change/current-password verification/reset invalidation/audit rollback, rate-limit boundaries/shared-IP/account behavior/window recovery/lockout non-extension, CSRF/role/ownership policies, status transition policy, file MIME/extension/size policy. Phase 2 verification extended this to 226 assertions for application, replacement, authorization, and transition behavior. Phase 3 extended the suite to 262 passing assertions for interview, enrollment, dashboard, report, and audit behavior. Phase 4 extends this to 272 assertions, including HTTPS reset URL validation, per-account reset throttling, successful-login session rotation, logout session destruction when audit fails, and the PHP router static-file path containment guard. The suite uses in-memory SQLite and temporary fixtures; it does not change live records.

Verified with an isolated MariaDB 10.4.32 schema and a separate local HTTP server on port 8001: a disposable student submitted five synthetic documents; a reviewer rejected one with feedback; the student saw that feedback and the replacement control; a disallowed extension and a PDF exceeding the configured maximum were rejected without changing version 1; a genuine multipart replacement became version 2; the reviewer downloaded version 1 with its review history and verified version 2; the remaining four synthetic documents were verified and the application returned to screening. Student access to staff document routes returned 403, a second student’s request for the owner’s document returned 404, malformed/path-traversal and missing document references returned 404, and a POST without CSRF returned 419. Audit/notification rows matched the successful and failed test operations. All test records were in the disposable schema, which was removed after verification; only files created by this run were removed from shared private upload storage.

Still outstanding: concurrent MySQL/MariaDB document replacement-versus-review and status-update races; browser password-change flow, local reset mailbox/token consumption, database-backed login rate-limit concurrency, admin/settings writes, and responsive/accessibility browser testing. Application submission, interviewer conflict, and checklist/enrollment interleaving were exercised against a disposable MariaDB schema, but this does not prove correctness under every isolation level or production load.

Phase 2 final verification on 2026-10-24: `php tests/run-security-tests.php` passed with 226 assertions and 0 failures; `php -l` passed for all 41 PHP files; `php scripts/check-database.php` reported MariaDB 10.4.32 and all expected tables present. The HTTP replacement cycle was executed against a fresh disposable MariaDB schema with synthetic data, not against `admitflow`.

Phase 3 verification on 2026-10-24: `php tests/run-security-tests.php` passed with 262 assertions and 0 failures; all 41 PHP files passed `php -l`; the read-only database checker reported MariaDB 10.4.32 and all expected tables present. An isolated MariaDB schema and port-8001 browser session exercised interview scheduling, conflict rejection, successful rescheduling, cancellation/status recovery, student-owned interview display, admin checklist completion, enrollment, report summaries, and invalid-date rejection. The CSV download did not complete in the browser automation, so it is not claimed as verified. No schema change or migration was made. No MySQL concurrency testing was performed.

Phase 4 audit on 2026-10-24: `php tests/run-security-tests.php` passed with 272 assertions and 0 failures; all 42 PHP files passed `php -l`; the read-only checker confirmed MariaDB 10.4.32 and expected tables. A disposable MariaDB/browser workflow covered synthetic registration/login, multipart submission, document rejection/replacement/re-review, acceptance, checklist, and enrollment. Cross-student document access returned 404 and a CSRF-less registration POST returned 419. Authenticated HTTP CSV response verified status 200, CSV and attachment headers, expected row contents, and formula-safe cells; a 5,005-match export returned exactly 5,000 data rows plus header and truncation notice. A clean concurrency-only MariaDB schema run reported interviewer scheduling `scheduled=1`, `conflicts=1`, `matching_rows=1`; simultaneous submission for a fresh student `submitted=1`, `rejected=1`, `student_applications=1`, `submitted_history=1`; and the checklist/enrollment race ended with `application=accepted`, `checklist=complete`, and the competing enrollment request blocked. The harness refuses to run unless the active schema begins `admitflow_phase4_concurrency_`. Encoded traversal attempts for `.env.example` and `config/database.local.php` returned 404. Security fixes require HTTPS reset URLs outside local mode, limit reset requests to 3 per account per 15 minutes, destroy the session even when audit persistence fails, constrain built-in server static-file passthrough to resolved paths inside `public/`, and avoid logging raw unexpected exception messages. Non-local DB connections reject root/blank-password credentials; `ADMITFLOW_FORCE_SECURE_COOKIES=1` enables secure cookies behind TLS termination. No schema change was made. Remaining risks include replacement-versus-review and status-update concurrency, production mail and reverse-proxy configuration, and broader browser accessibility. Live records, QA user, and existing uploads were preserved.

## Remaining work

Phase 4 security audit and isolated tests are complete. Remaining work is concurrent replacement/review and status-update testing, password-reset/password-change browser verification, deployment-specific mail/proxy validation, and broader responsive/accessibility testing.
