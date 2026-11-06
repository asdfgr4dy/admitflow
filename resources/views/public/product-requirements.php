<article class="prd-page container-xl" aria-labelledby="prd-title">
    <header class="prd-heading">
        <p class="prd-kicker"><i class="bi bi-journal-bookmark" aria-hidden="true"></i> AdmitFlow · Product documentation</p>
        <h1 id="prd-title">Product Requirements Document</h1>
        <p class="prd-lede">A product-level description of the current AdmitFlow admissions workflow, user needs, system capabilities, and operating constraints.</p>
        <dl class="prd-meta">
            <div><dt>Product</dt><dd>AdmitFlow</dd></div>
            <div><dt>Owner</dt><dd>Admission Office</dd></div>
            <div><dt>Document status</dt><dd>Current product baseline</dd></div>
            <div><dt>Updated</dt><dd>November 2026</dd></div>
        </dl>
    </header>

    <div class="prd-layout">
        <nav class="prd-toc" aria-label="Document sections">
            <p class="prd-toc-title">On this page</p>
            <a href="#overview">Overview</a>
            <a href="#users">Users and access</a>
            <a href="#scope">Product scope</a>
            <a href="#requirements">Requirements</a>
            <a href="#workflows">Core workflows</a>
            <a href="#quality">Quality and security</a>
            <a href="#constraints">Constraints</a>
            <a href="#acceptance">Release acceptance</a>
        </nav>

        <div class="prd-document">
            <section class="prd-section" id="overview">
                <p class="prd-section-label">01 · Product direction</p>
                <h2>Overview</h2>
                <p>AdmitFlow is a web-based student admission management system for the Admission Office. It brings applicant intake, document collection, staff review, interviews, enrollment preparation, notifications, and operational reporting into one role-aware workspace.</p>
                <h3>Problem statement</h3>
                <p>Admission work involves collecting applicant details and documents, coordinating review, communicating decisions, and preparing accepted applicants for enrollment. AdmitFlow provides a shared record and explicit workflow so applicants can follow their own applications and authorized staff can process work with a consistent history.</p>
                <h3>Product goals</h3>
                <ul>
                    <li>Give applicants a clear way to register, complete an application, submit required documents, and follow its status.</li>
                    <li>Help reviewers process assigned or unassigned applications with scoped access and traceable review actions.</li>
                    <li>Give administrators control over intake settings, staff access, announcements, enrollment readiness, and system oversight.</li>
                    <li>Preserve accurate application history and personal information through validation, access controls, and audit records.</li>
                </ul>
            </section>

            <section class="prd-section" id="users">
                <p class="prd-section-label">02 · People and permissions</p>
                <h2>Users and access</h2>
                <dl class="prd-role-list">
                    <div><dt>Applicant</dt><dd>Creates an account, maintains applicant details, saves and submits an application, uploads or replaces eligible documents, views notices and status, and accesses permitted printable records.</dd></div>
                    <div><dt>Admissions Reviewer</dt><dd>Works within the assigned-or-unassigned application scope, reviews documents, records screening notes and decisions, manages interviews, and uses scoped reports.</dd></div>
                    <div><dt>Administrator</dt><dd>Has staff capabilities plus user administration, reviewer assignment, academic settings, announcement management, archive actions, enrollment preparation, audit review, and system settings.</dd></div>
                </dl>
                <p>Public registration creates student accounts only. Staff accounts are created by an administrator and use a time-limited invitation to set a password. Account status and role are checked on routed requests; sensitive actions are restricted by server-side authorization.</p>
            </section>

            <section class="prd-section" id="scope">
                <p class="prd-section-label">03 · Capability map</p>
                <h2>Product scope</h2>
                <div class="prd-capability-list">
                    <div><h3>Applicant experience</h3><p>Registration and sign-in, profile completion, step-by-step draft application, document submission, application status, notifications, interview history, and eligible print documents.</p></div>
                    <div><h3>Admissions operations</h3><p>Scoped application queue and detail, document verification and feedback, status transitions, reviewer assignment, screening notes, interview scheduling and outcomes, and enrollment readiness checklist.</p></div>
                    <div><h3>Administration</h3><p>Programs, academic years, requirements, announcements, account access, application archive, dashboard metrics, reports, audit events, and system configuration visibility.</p></div>
                    <div><h3>Communications and reporting</h3><p>Audience-scoped announcements and notifications, filtered operational reports, CSV export, and print-ready report output.</p></div>
                </div>
            </section>

            <section class="prd-section" id="requirements">
                <p class="prd-section-label">04 · System behavior</p>
                <h2>Product requirements</h2>
                <ol class="prd-requirement-list">
                    <li><strong>Account access.</strong> The system must authenticate applicants and staff, enforce role permissions, protect password changes and resets, limit repeated login failures, and invalidate access for inactive accounts.</li>
                    <li><strong>Applicant profile and application.</strong> Applicants must provide validated profile and application details, save a draft, progress through the application in order, and submit only when required fields and documents are complete.</li>
                    <li><strong>Document handling.</strong> The system must validate allowed file types and sizes, keep files private, associate uploads with the correct application and requirement, retain versions, and allow eligible rejected documents to be replaced.</li>
                    <li><strong>Review workflow.</strong> Authorized staff must be able to review in-scope applications, provide document feedback, record notes, and move applications only through permitted status transitions.</li>
                    <li><strong>Interview management.</strong> Staff must be able to schedule, reschedule, and record interview outcomes with time and conflict validation; applicants must be able to view their interview history.</li>
                    <li><strong>Enrollment preparation.</strong> Administrators must be able to update the readiness checklist for accepted applications; enrollment completion requires the checklist to be complete.</li>
                    <li><strong>Communications.</strong> The system must show announcements only to their intended audience and deliver account-scoped notifications for relevant workflow events.</li>
                    <li><strong>Administration and oversight.</strong> Administrators must manage academic reference settings and user access, and review audit events. Important state changes and sensitive operations must be traceable.</li>
                    <li><strong>Operational visibility.</strong> Staff dashboards and reports must respect reviewer scope, support useful filtering, and avoid presenting unsupported targets or invented capacity measures.</li>
                </ol>
            </section>

            <section class="prd-section" id="workflows">
                <p class="prd-section-label">05 · End-to-end use</p>
                <h2>Core workflows</h2>
                <h3>Applicant journey</h3>
                <ol class="prd-flow">
                    <li><span>01</span><strong>Create account</strong></li>
                    <li><span>02</span><strong>Complete profile</strong></li>
                    <li><span>03</span><strong>Prepare application</strong></li>
                    <li><span>04</span><strong>Submit documents</strong></li>
                    <li><span>05</span><strong>Track progress</strong></li>
                </ol>
                <p>After submission, the Admission Office reviews the application, may request document corrections or schedule an interview, and records the outcome. An accepted applicant may proceed through administrator-controlled enrollment preparation.</p>
                <h3>Staff journey</h3>
                <p>Reviewers open applications in their authorized queue, inspect current documents, record verification feedback and screening notes, update eligible workflow statuses, and manage interviews. Administrators additionally configure the intake, assign reviewers, manage accounts and announcements, and complete enrollment readiness checks.</p>
            </section>

            <section class="prd-section" id="quality">
                <p class="prd-section-label">06 · Trust and operability</p>
                <h2>Quality and security requirements</h2>
                <ul>
                    <li>Personal data and documents must be available only to the account owner and authorized staff within their permitted scope.</li>
                    <li>State-changing forms must use CSRF protection; database access must use prepared statements and transactional safeguards where needed.</li>
                    <li>Passwords must be hashed; reset and staff setup tokens must be random, time-limited, single-use, and stored as hashes.</li>
                    <li>Uploads must remain outside the public web root and be checked for allowed extension, detected MIME type, size, ownership, and integrity where applicable.</li>
                    <li>Important account, review, archive, communication, and export activity must be auditable without exposing credentials or tokens.</li>
                    <li>The application must support a responsive, keyboard-accessible interface, clear validation feedback, and server-side authorization independent of the displayed controls.</li>
                    <li>Production deployment must use HTTPS, protected secrets, a least-privilege database account, supported PHP, and private file storage.</li>
                </ul>
            </section>

            <section class="prd-section" id="constraints">
                <p class="prd-section-label">07 · Boundaries</p>
                <h2>Constraints and known limits</h2>
                <ul>
                    <li>Admission programs, requirements, and intake dates are administrator-configured; sample catalog entries are fictional and must be reviewed before operational use.</li>
                    <li>The current data model has no intake capacity or target, so progress reporting is a count by workflow status, not a completion percentage.</li>
                    <li>CSV report exports are capped at 5,000 rows and identify when results exceed that limit.</li>
                    <li>Password reset and staff invitations require configured SMTP in production. The optional local reset mailbox is development-only; staff invitations do not use it.</li>
                    <li>Institution identity, address, phone, contact email, and office hours require configuration by the Admission Office and are not invented by this document.</li>
                    <li>Formal security certification and broader production end-to-end testing are outside the current product baseline.</li>
                </ul>
            </section>

            <section class="prd-section" id="acceptance">
                <p class="prd-section-label">08 · Definition of done</p>
                <h2>Release acceptance</h2>
                <ul class="prd-checklist">
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Applicants can create and access their own account, complete a valid application, submit required files, and see their own workflow updates.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Reviewers can act only on applications within their scope, and administrators can perform the additional configuration and oversight tasks assigned to their role.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Invalid or unauthorized actions are rejected server-side; valid state changes preserve associated history and produce appropriate notifications or audit records.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Core pages work on desktop and mobile, expose usable keyboard focus, and communicate errors and empty states clearly.</span></li>
                    <li><i class="bi bi-check2" aria-hidden="true"></i><span>Database, mail, HTTPS, storage, and deployment settings are verified for the target environment before live admissions use.</span></li>
                </ul>
                <p class="prd-document-note">This document describes the current AdmitFlow product baseline. Operational policy, eligibility rules, retention periods, and institution-specific admissions criteria remain the responsibility of the Admission Office.</p>
            </section>
        </div>
    </div>
</article>