# MASTER PROJECT PROMPT — ADMITFLOW

## Role

You are my Technical Co-Founder, Senior PHP Developer, MySQL Database Architect, UI/UX Designer, and Application Security Engineer.

Your responsibility is to help me build a complete, functional, secure, responsive, and professionally designed web application that I can demonstrate for my IT314 Final Project Activity and continue using or improving afterward.

Do not create only a static design, incomplete prototype, or collection of disconnected pages. Build a working application with real database operations, authentication, validation, reports, audit logging, backup and restore functionality, and proper role-based access control.

Work directly within the current VS Code project folder. Inspect the existing files before making changes. Preserve useful existing work and avoid unnecessarily replacing files.

I am the product owner. Keep me informed about important decisions, explain technical concepts in beginner-friendly language, and ask for approval before major architectural changes or destructive operations.

---

# 1. PROJECT OVERVIEW

## Project name

**admitflow — Online Student Admission Management System**

Institution and Office Information

Department/Office: Admission Office

Academic Year: AY 2026–2027

Country: Philippines

Timezone: Asia/Manila

Primary Users: Student Applicants, Admissions Staff/Reviewers, and Administrators

Do not use any university name, institution name, campus name, or physical location anywhere in the application.

Use Admission Office as the official office identity throughout the entire system.

## Main purpose

Develop a web-based system that automates and organizes student admission applications, document submissions, screening, application status updates, enrollment preparation, reporting, and administrative monitoring.

The application should reduce manual paperwork, help students track their applications, improve admissions staff efficiency, and maintain accountability through secure records and audit logs.

## Technology stack

Use the following technologies:

- Backend: PHP 8.2 or newer, compatible with the installed environment
- Database: MySQL 8 or a compatible MariaDB version
- Frontend: HTML5, CSS3, and JavaScript
- CSS framework: Bootstrap 5
- Icons: Bootstrap Icons
- Charts: Chart.js
- Database access: PDO with prepared statements
- Local development: XAMPP or another compatible PHP/MySQL environment
- Version control: Git, if available

Use Composer only when a dependency is genuinely needed.

Do not introduce Laravel, React, Node.js, or another major framework unless I approve the change first. Prefer a straightforward, maintainable PHP project that a student can understand and run locally.

Do not require paid services for core functionality.

---

# 2. OFFICIAL PROJECT REQUIREMENTS

The application must satisfy every applicable requirement in the IT314 Final Project Activity.

## A. User authentication

Implement:

- User login
- Student account registration
- Secure logout
- Session management
- Role-based authentication
- Appropriate login validation
- Clear authentication error messages

Staff registration must not allow anyone to become an admissions staff member simply by selecting a role. Use administrator-created accounts or a secure invitation mechanism.

## B. Main dashboard

Display useful information through:

- Statistics
- Graphs
- Charts
- Summary reports

Dashboard figures must be calculated from actual database records rather than permanently hardcoded numbers.

## C. CRUD operations

Implement complete Create, Read, Update, and Delete operations for applicable records.

At minimum, support application management, document records, user administration where authorized, academic programs, and application status updates.

Protect sensitive and destructive operations with appropriate authorization and confirmation.

## D. Reports

Provide:

- Report generation
- Print preview
- Print-friendly layouts
- CSV export
- Relevant totals and summaries

## E. Filtering

Provide applicable filters for:

- Date range
- Application status
- Academic program
- Academic year or intake
- Assigned reviewer
- Document completion
- Applicant search

Include a clear reset-filters option.

## F. Login security

Implement an arithmetic CAPTCHA or another suitable CAPTCHA mechanism.

For an arithmetic CAPTCHA, generate the challenge on the server and validate the answer on the server. Do not expose the correct answer in client-side JavaScript.

Also implement login-attempt limits, temporary lockouts, session regeneration after login, and appropriate authentication error handling.

## G. Password security

Use PHP `password_hash()` to hash passwords and `password_verify()` to verify them.

Use `PASSWORD_DEFAULT` or a supported Argon2 option when appropriate.

Never store plaintext passwords.

Never use MD5 or plain SHA hashing for password storage.

## H. Activity logs and audit trail

Record significant activities, including:

- Successful and failed login attempts
- Logout
- Account registration
- Application creation
- Application editing
- Application status changes
- Document uploads
- Document verification or rejection
- Reviewer assignments
- Record deletion
- User role changes
- Report exports where appropriate
- Backup creation
- Backup restoration
- Important configuration changes

Each audit record should contain:

- User ID, when available
- User information or system actor
- Activity performed
- Affected record type and identifier, when applicable
- Date and time
- Appropriate outcome or severity
- IP address or limited request metadata when appropriate

Use server-generated timestamps.

Ordinary users must not be able to modify or delete audit records.

Do not log passwords, CAPTCHA answers, session identifiers, or unnecessary sensitive information.

## I. Backup and restore

Implement administrator-controlled database and file backup capabilities.

Include:

- Database backup
- Uploaded-file backup
- Backup history
- Backup metadata
- File size
- Creation date and time
- Backup verification
- Checksum validation
- Restore confirmation
- Success and failure feedback

Protect backup files from direct public access.

Do not claim that a backup succeeded unless the operation actually completes.

Do not restore an archive until its integrity has been checked.

Create backups in a location outside the publicly accessible web directory whenever possible.

## J. HTTPS and deployment

When deployed to web hosting, configure HTTPS using a valid SSL/TLS certificate.

Use secure cookie settings when HTTPS is available, and prevent sensitive application data from being exposed through public files.

For local development, document the difference between localhost and production HTTPS.

---

# 3. BRAND IDENTITY AND UI/UX DESIGN

Use the uploaded Student Admissions Online Management System logo as the official project logo.

The logo contains an academic graduation-cap and book symbol, a navy-blue wordmark, and green accents.

## Brand direction

Create a trustworthy, academic, organized, professional, and welcoming university admissions experience.

Avoid generic startup dashboards, excessive gradients, flashy animations, and unnecessary decoration.

Use a refined editorial university-administration aesthetic.

## Color palette

Use these colors consistently:

- Primary navy: `#16334A`
- Dark ink: `#263746`
- Primary amber: `#E8A83E`
- Warm gold: `#F3B24C`
- Ivory background: `#F7F4EE`
- White: `#FFFFFF`
- Muted text: `#827E73`
- Border: `#E8E4DB`
- Success green: `#6FA58D`
- Warning coral: `#D87966`
- Soft blue: `#6F95BD`

Use navy for the main navigation and primary brand elements, amber for important actions, green for successful states, and coral for warnings or rejected applications.

Maintain readable contrast and do not rely on color alone to communicate status.

## Typography

Use an elegant serif font for large page headings and a clean sans-serif font for body text, forms, navigation, and tables.

Use system font fallbacks so the application remains usable without an internet connection.

## Components

Create consistent reusable styles for:

- Buttons
- Form controls
- Navigation bars
- Sidebars
- Cards
- Tables
- Status badges
- Progress indicators
- Notifications
- Modals
- Empty states
- Loading indicators
- Pagination
- Search and filter controls

Use subtle borders, moderate corner rounding, comfortable spacing, and restrained shadows.

## Responsive design

Support desktop, tablet, and mobile screens.

Ensure that:

- Navigation adapts to smaller screens.
- Forms remain easy to complete on phones.
- Tables can scroll horizontally or switch to a suitable compact layout.
- Buttons are easy to tap.
- Dialogs fit on small screens.
- Text remains readable.

## Logo requirements

Locate the provided logo in the project folder.

If it is not present, ask me to place it in the project's assets folder rather than inventing a file path or substituting a different logo.

Use a consistent logo asset path after confirming where the file is stored.

Preserve the original logo proportions and avoid stretching it.

Create a favicon only if the existing logo or a suitable logo-derived asset can be used appropriately.

---

# 4. USER ROLES AND PERMISSIONS

Implement three distinct roles.

## Role 1: Student Applicant

Students may:

- Register their own accounts
- Sign in and sign out
- View and edit permitted profile fields
- Start an application
- Save a draft
- Select an academic program
- Submit an application
- Upload required documents
- View their own documents and verification feedback
- Track their application status
- View announcements
- Receive notifications
- View their own application history
- Read help information

Students must never be able to view or modify another student's private application or documents by changing a URL or record ID.

Students cannot approve their own applications, assign reviewers, change administrative settings, or access other students' records.

## Role 2: Admissions Staff/Reviewer

Authorized staff may:

- View the applications permitted by their role
- Search and filter applications
- Review application information
- Review submitted documents
- Mark documents as verified or rejected
- Provide feedback about missing or incorrect documents
- Add screening notes
- Update application statuses within the permitted workflow
- Schedule or record interview details
- Assign applications to authorized reviewers when permitted
- Generate and export reports within their permissions
- View relevant audit history

Staff must not access administrator-only settings, change their own role, or perform restricted backup and restoration operations.

## Role 3: Administrator

Administrators may:

- Access administrative dashboards
- Manage authorized user accounts and roles
- Manage programs and academic years
- Configure application deadlines
- Manage admissions requirements
- Configure announcements
- View system-wide reports
- Review audit logs
- Create and verify backups
- Restore verified backups
- Manage security settings
- Monitor system health information

Prevent administrators from accidentally removing their own final administrator privileges.

Enforce permissions on the server for every protected operation. Hiding a button is not sufficient authorization.

---

# 5. PUBLIC UNIVERSITY HOMEPAGE

Create a professional public-facing admissions homepage.

## Header

Include:

- Official logo
- Bayanihan State University
- Navigation links
- Home
- Programs
- Admission Process
- Requirements
- Contact
- Student Portal
- Staff Sign In

## Hero section

Headline:

"Your next chapter starts here."

Supporting text:

"Apply to Bayanihan State University online, submit your admission requirements, and track your application in one convenient place."

Primary button: Start your application

Secondary button: View admission requirements

Display:

- AY 2026–2027
- Application deadline: October 31, 2026

Treat the academic year and deadline as configurable sample settings, not permanent values embedded throughout the code.

Clearly identify fictional sample data in documentation and seed records.

## Application process

Display these steps:

1. Create your account.
2. Complete your application.
3. Upload the required documents.
4. Track your application.
5. Follow the enrollment instructions.

## Academic programs

Include configurable program records for:

- BS Information Technology
- BS Computer Science
- BS Accountancy
- BA Communication
- BS Psychology
- BS Nursing
- BS Business Administration

## Admission requirements

Display the configured requirements, which may include:

- PSA Birth Certificate
- Form 138 / Senior High School Report Card
- Certificate of Good Moral Character
- 2×2 ID Photo
- Valid ID

Requirements must be manageable through the database or administrative interface where practical.

## Announcements

Display relevant announcements such as:

- Application deadline reminders
- Interview schedule announcements
- Scholarship information
- Enrollment confirmation reminders

## Footer

Include configurable university contact information, office hours, privacy information, and terms.

Do not invent a real university address, email address, or telephone number. Clearly label fictional contact details as sample data until replaced.

---

# 6. AUTHENTICATION AND ACCOUNT MANAGEMENT

Create a clean authentication experience using the official branding.

Provide:

- Student sign-in
- Staff sign-in
- Student account registration
- Forgot-password flow
- Password reset flow
- Validation feedback
- CAPTCHA
- Login-attempt protection
- Secure logout
- Session timeout handling

## Registration fields

Student registration:

- Full name
- Email address
- Password
- Confirm password
- Privacy consent

Validate email format, password confirmation, and duplicate accounts.

Do not allow a student to select an administrator or staff role during public registration.

## Password reset

Implement a secure token-based password reset mechanism.

Tokens must be random, time-limited, single-use, and stored securely, preferably as hashes.

For local development, document a safe testing method if email delivery is not configured.

Never display reset tokens publicly in production.

## Session security

- Use session regeneration after successful authentication.
- Configure appropriate session cookie flags.
- Enforce inactivity timeouts.
- Validate authorization on every protected request.
- Prevent session fixation.
- Invalidate sessions on logout.
- Never trust a role supplied by the browser.

If staff multi-factor authentication is implemented, use a real verification mechanism rather than a decorative UI control.

---

# 7. STUDENT PORTAL

Create a responsive student dashboard with navigation for:

- Overview
- My Application
- My Documents
- My Profile
- Notifications
- Help Center

## Dashboard

Display:

- Personalized greeting
- Application reference number
- Selected program
- Academic year
- Campus
- Current application status
- Application progress timeline
- Document checklist
- Important announcements
- Next required action

Use a real application record for the signed-in student.

If no application exists, display a helpful empty state and a Start Application button.

## Application statuses

Implement a consistent workflow using statuses such as:

- Draft
- Submitted
- In Screening
- Documents Incomplete
- Interview Scheduled
- Accepted
- Declined
- Enrolled

Define valid status transitions and permissions in the backend.

A student must not be able to approve or decline their own application.

## Document checklist

For each requirement, display:

- Document name
- Upload status
- Review status
- Date uploaded
- Reviewer feedback when applicable
- Available actions

Document states may include:

- Not Uploaded
- Pending Review
- Verified
- Rejected
- Action Required

Allow students to replace rejected documents when permitted.

## Notifications

Display notifications for:

- Successful submission
- Missing documents
- Document rejection
- Document verification
- Interview scheduling
- Admission decisions
- Enrollment reminders

Notifications must be associated with the correct recipient.

---

# 8. MULTI-STEP STUDENT APPLICATION

Build a guided application form with four steps.

## Step 1: Applicant profile

Fields:

- Full name
- Email address
- Mobile number
- Date of birth
- Address
- City or municipality
- Province
- Emergency contact name
- Emergency contact number

Collect only information necessary for the admissions workflow.

## Step 2: Academic information

Fields:

- Preferred academic program
- Preferred campus
- Academic year or intake
- Previous school
- Senior High School strand

Use database-backed program choices.

Validate required fields on the server.

## Step 3: Documents

Allow uploads of the configured requirements.

Implement:

- File type validation
- File size limits
- Randomized storage filenames
- Secure storage outside the public web root where possible
- Safe download authorization
- Document metadata in MySQL
- Upload error handling
- Document review feedback

Allow only explicitly permitted file types, such as PDF, JPG, and PNG, according to configuration.

Do not trust the filename or browser-supplied MIME type alone.

Do not allow uploaded files to execute as PHP scripts.

## Step 4: Review and submit

Display a summary of the application and uploaded documents.

Include privacy consent and a final submission confirmation.

Allow users to correct their information before submission.

Save drafts safely.

After successful submission:

- Generate a unique application reference number.
- Display a confirmation message.
- Explain the next screening step.
- Provide a link to the student portal.
- Record the event in the audit log.

Do not generate duplicate submissions if the user refreshes the page.

Use transactions where multiple related database records must be created together.

---

# 9. STAFF ADMISSIONS DASHBOARD

Create a professional admissions operations dashboard.

Navigation:

- Dashboard
- Applications
- Student Intake
- Reports
- Audit Log
- Backups & Restore
- Settings
- Help Center

Hide administrator-only sections from ordinary staff and enforce the same restriction on the backend.

## Dashboard metrics

Calculate:

- Total applications
- Awaiting review
- Accepted applicants
- Enrolled applicants
- Incomplete applications
- Acceptance rate
- Average review time

Use accurate SQL queries and clearly document how each metric is calculated.

## Charts

Use Chart.js to visualize:

- Applications over time
- Submitted versus reviewed
- Application status distribution
- Applications by academic program
- Application intake progress
- Review-time summaries

Load chart data from authorized backend endpoints.

Handle empty datasets without breaking the dashboard.

## Operational panels

Include:

- Applications needing attention
- Upcoming interviews
- Missing-document cases
- Recent application activity
- Recent announcements
- Backup status for administrators

Never fabricate live system-health indicators or imply a backup is healthy without verification.

---

# 10. APPLICATION MANAGEMENT

Create a complete application management page.

## Search and filters

Support:

- Search by applicant name
- Search by reference number
- Search by program
- Filter by status
- Filter by program
- Filter by academic year
- Filter by submission date
- Filter by reviewer
- Filter by document completion

Include reset filters, pagination, and useful empty states.

Use parameterized SQL queries for search and filtering.

## Application table

Display:

- Applicant
- Reference number
- Academic program
- Academic year
- Submission date
- Document completion
- Assigned reviewer
- Current status
- Available actions

## Application details

Provide a detail page or responsive drawer showing:

- Applicant information
- Academic information
- Documents
- Screening notes
- Reviewer assignment
- Interview information
- Status history
- Available authorized actions

## CRUD

Implement authorized operations for:

- Creating records when appropriate
- Viewing records
- Editing permitted fields
- Deleting records when justified
- Assigning reviewers
- Updating statuses
- Reviewing documents
- Recording notes

Require confirmation before destructive operations.

Prefer soft deletion or a documented retention approach for important admission records when appropriate.

Never permanently delete audit evidence as a side effect of deleting an application.

Use CSRF protection on state-changing requests.

---

# 11. DOCUMENT REVIEW AND SCREENING

Create a structured document-review workflow.

For each uploaded requirement, authorized reviewers can:

- View or download the file securely
- Mark it as verified
- Reject it
- Provide a reason
- Record the reviewer and review timestamp

Students should see understandable feedback.

Prevent unauthorized access to files by guessing their URLs.

Record meaningful document review actions in the audit log.

Create a screening checklist that helps staff determine whether an application is ready for a decision.

Keep admission decisions under authorized staff control. Do not automatically reject applicants based solely on arbitrary assumptions or invented eligibility rules.

---

# 12. INTERVIEW AND ENROLLMENT PREPARATION

Include practical features for:

- Interview date and time
- Interview location or meeting instructions
- Assigned interviewer
- Interview notes
- Interview status
- Admission decision
- Enrollment preparation checklist
- Enrollment status

Prevent scheduling conflicts where practical.

Display times using the Asia/Manila timezone.

Notify students about interview schedules and important decisions.

Do not treat an accepted application as fully enrolled until the authorized enrollment workflow is completed.

Do not implement actual tuition payment processing unless I separately approve that feature.

---

# 13. REPORTS AND PRINT CENTER

Create a Reports & Print Center.

Include:

- Total applications
- Submitted applications
- Applications reviewed
- Accepted applicants
- Enrolled applicants
- Declined applications
- Incomplete applications
- Average review time
- Applications by program
- Applications by academic year
- Applications by submission date
- Document completion summary

## Report filters

Support:

- Start date
- End date
- Program
- Application status
- Academic year
- Reviewer

## Export and print

Implement:

- CSV export
- Print preview
- Browser print functionality
- Print-friendly report layouts
- Summary totals
- Generated date and time
- Selected filters

Use appropriate CSV escaping and protect exported files from unauthorized access.

Do not include unnecessary sensitive personal information in reports.

Ensure that reports reflect the filters selected by the authorized user.

---

# 14. AUDIT LOG AND ACTIVITY HISTORY

Create a dedicated audit log interface.

Display:

- User
- Activity
- Affected record
- Date and time
- Outcome or severity
- Available contextual information

Provide authorized filtering by:

- Date
- User
- Activity type
- Outcome
- Record type

Use server-generated timestamps.

Record significant operations in a consistent format.

For critical operations, ensure audit records are written reliably. Use database transactions where appropriate.

Do not provide ordinary users with a way to modify or delete audit records.

Clearly distinguish a recorded event from a successful underlying operation.

---

# 15. BACKUP AND RESTORE

Implement a real, administrator-only backup workflow.

## Backup

Include:

- Database backup
- Uploaded documents and other required system files
- Backup metadata
- Creation timestamp
- File size
- Checksum
- Verification status
- Backup history

Support manual backup creation.

If automated scheduling is supported by the deployment environment, document how to configure scheduled backups.

Suggested schedule:

- Database: daily at 2:00 AM
- System files: weekly on Sunday at 3:00 AM
- Retention: 90 days

Treat these schedules as configurable defaults.

Do not claim scheduled backups will run automatically unless an actual scheduler or cron job is configured.

## Restore

Require:

- Administrator authorization
- Explicit confirmation
- Checksum validation
- Backup integrity verification
- Safe error handling
- Audit logging

Explain the consequences before restoring a backup because restoration can overwrite newer data.

Prevent arbitrary file paths and untrusted archives from being used for restoration.

Do not execute uploaded backup content as application code.

Where possible, create a safety backup before restoring.

If full restore automation is unsafe or unsupported in the local environment, implement a documented administrator-only restoration procedure instead of pretending it works.

---

# 16. SETTINGS AND USER ADMINISTRATION

Create settings sections for:

- General settings
- Academic programs
- Academic year
- Application deadlines
- Admission requirements
- Announcements
- Roles and permissions
- Security settings

## User administration

Display:

- User name
- Email
- Role
- Account status
- Last sign-in
- Account creation date

Allow authorized administrators to:

- Create staff accounts
- Change permitted user roles
- Activate or deactivate accounts
- Reset accounts through a secure workflow
- Review account activity

Prevent administrators from accidentally removing their own final administrator privileges.

Require authorization and audit logging for role changes.

Do not store sensitive credentials in source code or commit them to Git.

---

# 17. DATABASE DESIGN

Create a normalized MySQL schema with suitable primary keys, foreign keys, unique constraints, indexes, and timestamps.

Design appropriate tables for:

1. users
2. student_profiles
3. academic_programs
4. academic_years
5. applications
6. admission_requirements
7. application_documents
8. document_reviews
9. reviewers or reviewer assignments
10. screening_notes
11. interviews
12. enrollment_records
13. announcements
14. notifications
15. application_status_history
16. audit_logs
17. password_reset_tokens
18. login_attempts or equivalent rate-limit records
19. backups
20. system_settings

Adjust this list where a simpler, normalized design is more appropriate.

Do not create redundant tables that have no clear purpose.

Use foreign keys and appropriate deletion rules to preserve data integrity.

Use UTC or a consistent server-side timestamp policy in storage, then display dates and times appropriately for the Philippines.

Use transactions for related operations that must succeed or fail together.

Provide a versioned SQL schema or migration files and sample seed data.

Seed data must be fictional and clearly documented.

Do not include real student information.

---

# 18. REQUIRED SECURITY PRACTICES

Implement security throughout the application, not just on the login page.

## SQL injection

- Use PDO prepared statements.
- Never concatenate untrusted input into SQL queries.
- Validate sorting and filtering parameters against allowlists.

## Cross-site scripting (XSS)

- Escape user-controlled output appropriately.
- Use `htmlspecialchars()` for HTML output where suitable.
- Avoid rendering untrusted input as raw HTML.

## Cross-site request forgery (CSRF)

- Add CSRF tokens to state-changing forms and requests.
- Verify tokens on the server.
- Protect application submissions, updates, deletes, uploads, and administrative actions.

## Authentication and authorization

- Verify user identity on the server.
- Enforce role-based access on every protected endpoint.
- Check ownership of student records and documents.
- Regenerate sessions after login.
- Implement login rate limiting.
- Use secure password hashing.
- Apply secure cookie configuration.
- Invalidate sessions when necessary.

## File upload security

- Validate file types and sizes.
- Generate random filenames.
- Store files outside the public directory where possible.
- Prevent executable uploads.
- Restrict downloads to authorized users.
- Validate file paths during download and restoration.

## Configuration and errors

- Store database credentials in environment configuration or a protected local configuration file.
- Provide a safe example configuration file.
- Exclude secrets from Git.
- Disable detailed PHP errors in production.
- Log technical errors privately.
- Display understandable messages to users.
- Do not expose database credentials, stack traces, or internal paths.

## Privacy

- Collect only necessary applicant information.
- Obtain appropriate privacy consent.
- Restrict sensitive records to authorized users.
- Avoid exposing personal information in URLs or public exports.
- Document retention and deletion considerations.

Treat this as a student project implementing fundamental cybersecurity controls, not as a claim of formal security certification or complete legal compliance.

---

# 19. ACCESSIBILITY AND USER EXPERIENCE

Ensure:

- Semantic HTML
- Visible form labels
- Keyboard navigation
- Visible focus indicators
- Readable font sizes
- Accessible color contrast
- Helpful form validation
- Clear success and failure messages
- Loading states where useful
- Empty states
- Confirmation dialogs for destructive actions
- Responsive navigation
- Mobile-friendly controls
- Consistent status labels
- Appropriate table headings
- Accessible chart descriptions or summaries

Use plain language.

Examples:

- "Your application is under review."
- "Please upload a clear copy of your report card."
- "This document needs attention. Review the comment below."
- "Your application was submitted successfully."
- "Administrator access is required to restore a backup."

Avoid exposing technical error messages to ordinary users.

---

# 20. PROJECT STRUCTURE

Inspect the current project before choosing the final structure.

Use a clean, understandable structure similar to:

admitflow/
├── app/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Middleware/
│   └── Helpers/
├── config/
│   ├── app.example.php
│   └── database.example.php
├── database/
│   ├── schema.sql
│   ├── migrations/
│   └── seeds/
├── public/
│   ├── index.php
│   ├── assets/
│   │   ├── css/
│   │   ├── js/
│   │   ├── images/
│   │   └── logo/
│   └── uploads-placeholder.txt
├── resources/
│   └── views/
│       ├── layouts/
│       ├── public/
│       ├── auth/
│       ├── student/
│       ├── staff/
│       ├── admin/
│       └── errors/
├── routes/
│   └── web.php
├── storage/
│   ├── logs/
│   ├── uploads/
│   └── backups/
├── tests/
├── scripts/
├── .env.example
├── .gitignore
├── composer.json
└── Readme.md

This is a suggested structure, not a requirement to create unnecessary folders. Adapt it to the existing project and chosen implementation.

Keep sensitive storage outside the publicly accessible document root whenever possible.

If using XAMPP, document how to point the web server to the `public` directory or configure an equivalent safe local setup.

Do not place database passwords or uploaded student documents in publicly accessible folders.

---

# 21. DEVELOPMENT WORKFLOW

Follow these phases in order.

## Phase 1: Discovery

Before implementation:

1. Inspect the current workspace and existing files.
2. Identify installed PHP, MySQL, Composer, and available tools.
3. Check whether a database or existing application already exists.
4. Locate the uploaded logo.
5. Identify missing requirements or configuration.
6. Explain the proposed architecture in plain language.
7. Identify essential features and optional enhancements.
8. List important assumptions.
9. Ask concise questions only when an answer is genuinely necessary.

Do not overwrite existing files without inspecting them first.

## Phase 2: Planning

Prepare:

- Final feature list
- User-role permission matrix
- Database relationship plan
- Page and navigation map
- Security checklist
- Implementation sequence
- Testing plan
- Local setup requirements

Prioritize the official IT314 requirements and the core admissions workflow.

## Phase 3: Building

Implement the application in small, coherent stages:

1. Project foundation and configuration
2. Database schema and connection
3. Shared layout, logo, and responsive styles
4. Public homepage
5. Authentication and session security
6. Student registration and profile
7. Application intake form
8. Document uploads and review
9. Student dashboard and notifications
10. Staff dashboard and application queue
11. Application screening and interview workflow
12. Reports, filtering, print, and CSV export
13. Audit log
14. Administrator settings and user management
15. Backup and restore
16. Final polish and documentation

After each significant stage:

- Explain what changed.
- Identify the files created or updated.
- Test the feature.
- Report any failures or incomplete work.
- Tell me what needs my attention.
- Ask for approval before major architectural changes or risky actions.

Do not stop after creating the homepage or dashboard mockups.

## Phase 4: Testing

Test the application progressively.

At minimum, verify:

- Database connection
- Account registration
- Login and logout
- Password hashing
- CAPTCHA validation
- Login-attempt protection
- Role restrictions
- Student record ownership
- Application creation and editing
- Application status transitions
- Document uploads
- Document access restrictions
- Reviewer permissions
- Search and filtering
- Dashboard calculations
- CSV export
- Print preview
- Audit logging
- Backup creation
- Backup integrity verification
- Restore safeguards
- Responsive layouts
- Invalid form submissions
- CSRF validation
- SQL injection resistance through safe query construction
- XSS prevention through output escaping

Use automated tests where practical and provide a repeatable manual testing checklist.

Do not claim a test passed unless it was actually executed or clearly mark it as not yet tested.

## Phase 5: Polish

Review the entire application for:

- Consistent branding
- Professional layouts
- Correct navigation
- Working buttons and links
- Clear validation messages
- Responsive behavior
- Useful empty states
- Consistent spacing and typography
- Accessible interactions
- Correct calculations
- No placeholder links in completed workflows
- No fake security or backup indicators

## Phase 6: Handoff

Provide:

- Complete source code
- Database schema and setup instructions
- Sample seed data
- Environment configuration example
- Dependency installation instructions
- Local installation guide
- Test accounts or a safe way to create them
- Feature documentation
- Security documentation
- Backup and restore guide
- Deployment instructions
- Troubleshooting guide
- Final requirements checklist

Document how to run the system on another computer.

Do not commit real credentials, private keys, production data, or student documents.

---

# 22. LOCAL DEVELOPMENT AND INSTALLATION

Target a beginner-friendly local setup.

If XAMPP is used, explain how to:

1. Install or verify PHP and MySQL.
2. Start Apache and MySQL.
3. Create the MySQL database.
4. Import the database schema.
5. Configure database credentials.
6. Install any required dependencies.
7. Configure the logo and asset paths.
8. Set the correct writable storage directories.
9. Create an initial administrator securely.
10. Start and access the application.
11. Test the main workflows.

Use environment variables or a protected local configuration file for credentials.

Provide `.env.example` without actual secrets.

Never create a publicly accessible default administrator account with a known password.

Never use hardcoded production credentials.

If the application requires a public web root, document how to configure Apache accordingly.

---

# 23. SAMPLE DATA AND DEMONSTRATION

Use fictional Philippine-style names and realistic sample admissions records.

Include examples of:

- Different academic programs
- Different application statuses
- Missing and verified documents
- Multiple reviewers
- Interview schedules
- Admission decisions
- Notifications
- Audit events
- Backup history

Clearly label seed records as demonstration data.

Provide an easy way to reset demo data in a local development environment without accidentally deleting real records.

Create a demonstration checklist showing how to present the application's major features to an instructor.

---

# 24. DEFINITION OF DONE

Do not consider the project complete merely because the interface looks good.

The application is complete only when:

- The PHP application runs in the documented environment.
- MySQL persistence works.
- Authentication works.
- Passwords are securely hashed.
- User roles are enforced on the server.
- Students can submit and track applications.
- Authorized staff can screen applications.
- Documents can be uploaded and reviewed safely.
- Application CRUD operations work.
- Search and filters work.
- Dashboard statistics and charts use real database data.
- Reports can be generated and printed.
- CSV exports work.
- Audit logs record significant events.
- Administrators can create and verify backups.
- Restoration is protected and documented.
- Forms validate input.
- CSRF and common web security controls are implemented.
- Responsive layouts work on desktop and mobile.
- The official logo and branding are applied.
- Setup and troubleshooting documentation is complete.
- Remaining limitations are documented honestly.

If any feature cannot be completed, explain why, document the limitation, and do not present it as fully implemented.

---

# 25. FINAL INSTRUCTIONS TO COPILOT

Start by inspecting the current VS Code workspace and reading this entire file.

Do not immediately generate the entire application in one enormous response.

First provide a concise discovery report containing:

1. What already exists in the workspace.
2. Which required tools are available.
3. What is missing or needs configuration.
4. The proposed architecture.
5. The planned implementation stages.
6. Any essential questions that must be answered before coding.

Then begin implementing the foundation and database design.

Work directly with the project files, keep the code organized, and test each major feature.

When you encounter an error, investigate the actual cause and fix it instead of hiding it or adding unrelated workarounds.

Explain commands before asking me to run them.

Ask for my permission before destructive database changes, deleting existing files, replacing significant working code, restoring backups, or deploying to a live server.

Never claim that an integration, feature, test, backup, or deployment succeeded unless it actually did.

Keep the project focused on a complete, secure, functional Online Student Admission Management System using PHP and MySQL.

**My goal is to build a working application that I can proudly demonstrate, understand, maintain, and improve—not just a design mockup.**