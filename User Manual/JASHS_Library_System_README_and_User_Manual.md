# JOSE ABAD SANTOS HIGH SCHOOL
# LIBRARY MANAGEMENT SYSTEM
## System README and User Manual

Version: Current Build
System Type: PHP + MySQL Web-Based Library Management System
Recommended Environment: XAMPP on Windows

---

## 1. SYSTEM OVERVIEW

The Jose Abad Santos High School Library Management System is a web-based system designed to support the daily operations of the school library.

The system combines two user experiences:

1. Chief Librarian/Admin Management
2. Student Library Portal

The system is designed to manage the library's students, books, borrowing transactions, returns, reservations, QR codes, notifications, reports, backups, audit records, and account security from one centralized database.

The current build includes the latest inventory, borrowing, reservation, QR, notification, security, backup, and catalog management improvements.

---

## 2. MAIN SYSTEM FUNCTIONS

### 2.1 Chief Librarian/Admin Functions

The Chief Librarian can use the management side of the system to:

- Manage student records
- Register and maintain library users
- Manage the book catalog
- Add books and book copies
- Edit book information
- View detailed book information
- Remove available book copies, including reducing the available stock to zero
- Archive and restore book records
- Process borrowing and returning transactions
- Process QR-assisted transactions
- Manage student reservations
- Configure borrowing and reservation rules
- View notifications and system alerts
- Review audit logs
- Create and download database backups
- Restore a database backup
- View reports and export report data
- Import student and book records through CSV/XLSX templates

### 2.2 Student Functions

Students can use the Student Portal to:

- Log in using their student credentials
- Use QR/manual student identification during login
- Complete the CAPTCHA security check
- View their student profile
- View borrowing history
- Search the book catalog
- Filter books by available catalog information
- View book information before borrowing
- Borrow available books
- Reserve unavailable books
- Receive reservation and borrowing notifications
- Download their student QR code
- Download a selected book QR code

---

## 3. SYSTEM ACCESS

For a local XAMPP installation, the project is normally placed in:

C:\xampp\htdocs\LibraryBorrowingSystem

The application can then be opened through the XAMPP Apache server.

The database must be imported before using the system.

IMPORTANT:

Do not hard-code a local development address into application messages or documentation intended for deployment. Use the address appropriate for the actual environment where the system is installed.

---

## 4. INSTALLATION AND INITIAL SETUP

### Step 1 - Install XAMPP

Install XAMPP with:

- Apache
- MySQL

Start both services from the XAMPP Control Panel.

### Step 2 - Copy the Project

Place the system folder in:

C:\xampp\htdocs\LibraryBorrowingSystem

### Step 3 - Create the Database

Open phpMyAdmin from the XAMPP environment and create/import the database included with the project.

Use the project's current database SQL file for a fresh installation.

### Step 4 - Check Database Configuration

Open:

db.php

Confirm that the database host, database name, username, and password match the local MySQL configuration.

### Step 5 - Open the System

Open the Library Management System from the browser using the appropriate Apache URL for the installed environment.

---

## 5. REQUIRED PHP/XAMPP SETTINGS

### XLSX Import

For Excel-based imports, PHP must have the Zip extension enabled.

In php.ini, make sure the PHP Zip extension is enabled.

After changing php.ini, restart Apache.

CSV importing does not require ZipArchive.

### Internet Connection

An internet connection may be required when the system generates QR images through the external QR service or when email verification is configured.

---

## 6. LOGIN AND ACCOUNT SECURITY

The system uses separate Admin and Student access while keeping the login experience centralized.

### Admin Login

Admin users sign in using their configured administrator credentials.

Security controls include:

- CAPTCHA
- Password visibility controls
- Session protection
- CSRF protection
- Login rate limiting
- Session ID regeneration
- Session fingerprint checks
- Idle and absolute session expiration

### Student Login

Students use the student login process with their student identification and password.

The student login also uses a server-side math CAPTCHA.

Email verification is not required every time a student logs in.

QR/manual student identification is used for student identification, while the account password remains an additional credential.

---

## 7. PASSWORD POLICY

New passwords created by the current system must:

- Contain at least 10 characters
- Contain at least one uppercase letter
- Contain at least one lowercase letter
- Contain at least one number
- Contain at least one special character
- Avoid commonly used weak passwords

These rules apply when passwords are newly created or reset.

Existing password values are not automatically replaced by the security update.

Passwords are stored using PHP password hashing for newly created accounts.

---

## 8. FORGOT PASSWORD AND STUDENT RECOVERY

The Student Portal includes password recovery functionality.

Students should use the Forgot Password option when they cannot access their account.

Follow the verification instructions shown by the system.

Never store or share plain-text passwords in public documentation.

---

## 9. STUDENT SELF-REGISTRATION

Students can register through the public Student Registration page.

The registration process includes:

1. Complete the registration form.
2. Enter the required email address and password.
3. The system validates the supplied information.
4. A 6-digit verification code is sent to the registered email.
5. Enter the verification code.
6. The account is created only after successful verification.
7. A student QR code is generated for the new account.

Verification codes have an expiration period, attempt limits, and resend cooldowns.

---

## 10. GMAIL SMTP / EMAIL VERIFICATION SETUP

Email verification uses Gmail SMTP when enabled.

A Gmail App Password is required.

### Setup

1. Use a dedicated school/library Gmail account.
2. Enable 2-Step Verification.
3. Create a Google App Password.
4. Open:

config/mail_config.php

5. Configure the mail settings.

Example:

define('MAIL_ENABLED', true);
define('MAIL_USERNAME', 'yourlibraryaccount@gmail.com');
define('MAIL_APP_PASSWORD', 'your-16-character-app-password');
define('MAIL_FROM_EMAIL', 'yourlibraryaccount@gmail.com');

6. Restart Apache if required.
7. Make sure OpenSSL is enabled.
8. Confirm that the computer has internet access.

Never publish the Gmail App Password.

Do not upload a configured mail_config.php containing secrets to a public repository.

---

# 11. CHIEF LIBRARIAN USER MANUAL

## 11.1 Dashboard

The dashboard provides an overview of the library system.

Use it as the starting point for:

- Viewing system information
- Opening inventory management
- Opening student records
- Opening transactions
- Opening reservations
- Opening reports
- Opening backup and restore tools
- Monitoring notifications and alerts

The dashboard is intended for overview and navigation rather than detailed record editing.

---

## 11.2 Student Records

Use Student Records to manage library student accounts.

Typical actions include:

- Add a student
- Search a student
- Filter student records
- View student details
- Archive a student
- Restore a student
- Review student QR information
- Download a student QR code
- Import students through CSV/XLSX

### Importing Students

Use the supplied import template.

Do not change the required column headers.

The student import template includes:

- Student No
- Name
- Section
- Department / Strand
- Grade Level
- Contact Number
- Validity of Library Access Card
- Email
- Password

For Grade 11 and Grade 12, Department / Strand is used where applicable.

---

## 11.3 Book Inventory

Use Inventory to manage the library catalog.

### Add Book

Enter the required bibliographic and inventory information.

A unique book QR code is generated for the book record.

After saving a new book, the system redirects back to the inventory page so refreshing the page does not repeat the previous submission.

### View Book Details

Click Details to open the book's information.

The Details view is used to review:

- Title
- Author
- Co-authors
- Publication information
- Book number
- Book pages
- Source of funds
- Cost
- Publisher
- Edition
- Volumes
- Class
- Material type
- Location
- Library section
- Copy counts
- Status
- QR code

### Edit Book

Editing is accessed from the Book Details area.

Use Edit to modify catalog information such as title, author, publication details, classification, physical location, and status.

Copy quantities are managed separately.

### Add Copies

Use Add Copies when additional physical copies of an existing title are acquired.

### Remove Copies

Use Remove Copies to remove available physical copies from the inventory.

The current system allows the available copy count to reach zero.

Example:

10 copies -> 5 copies -> 1 copy -> 0 copies

A copy cannot be removed while it is currently borrowed.

The book record itself can remain in the catalog at zero copies.

### Archive and Restore

Archive a book when the title should remain in historical records but should no longer appear as active inventory.

Restore the record when it becomes active again.

---

## 11.4 Transactions

Borrowing and returning transactions can be processed from the circulation area.

QR-assisted transactions can identify the student and book.

The system validates the transaction before changing the database.

Borrowing and returning records remain stored in the transaction history.

---

## 11.5 Borrowing Rules

The system can enforce a maximum active borrowing limit per student.

The default configuration in the current enhancement is:

Maximum active books per student: 3

The limit can be changed from the Reservations/Rules section.

The same borrowing rule is used for normal student borrowing and librarian-assisted QR borrowing.

A student cannot borrow the same book again while an active borrowing record for that book is still open.

---

## 11.6 Reservations

Use Reservations to manage books that are currently unavailable.

Students can reserve unavailable books from the Student Borrow page.

Reservation rules include:

- Duplicate active reservations are prevented.
- A student cannot reserve a book they are already borrowing.
- Reservations are processed in queue order.
- The oldest pending reservation has priority.
- A reservation can become Ready when a copy becomes available.
- A Ready reservation can be fulfilled when the designated student borrows the book.
- Stale reservations are automatically expired according to the configured rule.

### Reservation Rule Settings

The reservation page includes system-wide settings for:

Maximum Active Books per Student

Reservation Expiry (Days)

The default reservation expiry is:

3 days

Only the Chief Librarian should modify these settings.

---

## 11.7 Notifications

The notification system stores messages in the database.

Examples include:

- Book borrowing confirmation
- Book return confirmation
- Reservation ready
- Reservation cancellation
- Pending reservation alerts
- Low-stock alerts

The notification bell is placed in the page header.

Unread notifications are displayed using a count badge.

---

## 11.8 QR Code Management

The system uses QR codes for library identification and transactions.

### Book QR

Book QR codes are associated with book records.

The librarian can view and download a book QR from Inventory.

### Student QR

Student QR codes are associated with student records.

The librarian can view/download student QR information from Student Records.

Students can also download their own QR from:

- Student Profile
- Student Borrow page

The system validates QR records before serving downloadable QR files.

---

## 11.9 Reports and Exports

Use Reports & Analytics to review library information and generate reports.

Available exports depend on the current implementation.

Exported data should be handled as confidential school/library information.

---

## 11.10 Audit Logs

The Audit Logs section records important system actions in the database.

Audit entries help identify:

- Who performed an action
- What action occurred
- When it occurred
- Which record was affected
- Whether the operation succeeded or failed

The system uses the Chief Librarian role rather than a separate System Administrator role for the library management account.

---

## 11.11 Backup and Restore

Backup Management is used to protect the library database.

### Create a Backup

1. Open Backup & Restore.
2. Click Create Backup Now.
3. Wait for the backup process to finish.
4. Confirm that the backup appears in Backup History.
5. Download the backup file when necessary.

The current system uses a post/redirect process after the backup request. Refreshing the page should not create another backup.

The Create Backup button is also protected against repeated submissions while the request is processing.

### Restore a Backup

1. Select a valid backup from the list.
2. Review the warning.
3. Confirm the restore.
4. Wait for the restore operation to finish.
5. Verify the system after restoration.

RESTORE IS DESTRUCTIVE.

A restore can replace current database information with information contained in the selected backup.

Always create a fresh backup before restoring another backup.

---

# 12. STUDENT USER MANUAL

## 12.1 Student Registration

Students who do not yet have an account should use Student Registration.

Complete all required fields accurately.

Use an accessible email address because the system sends a verification code to that address.

Create a password that satisfies the current password policy.

---

## 12.2 Student Login

Students log in using the information requested by the Student login form.

The login process uses:

- Student identification
- Password
- CAPTCHA

Students must complete all required security checks.

---

## 12.3 Student Profile

The Student Profile shows:

- Student information
- Grade/section details
- Contact details
- Borrowing history
- Student QR code

The student can download their Student QR code from the profile page.

---

## 12.4 Search Books

Use Search Books to find library titles.

The student can search by available catalog information such as:

- Title
- Author
- Book Number

Filters can be used to narrow the results.

The search and filter interface is designed to update without requiring unnecessary extra search-button clicks.

---

## 12.5 Borrow a Book

1. Search for a book.
2. Select the book.
3. Review the book details and availability.
4. Borrow the book when a copy is available.
5. Wait for the borrowing confirmation.

The system checks the student's active borrowing limit and other borrowing rules before accepting the transaction.

---

## 12.6 Reserve a Book

When a book is unavailable, the Reserve This Book option can appear.

1. Select the unavailable book.
2. Review the reservation message.
3. Click Reserve This Book.
4. Wait for the reservation confirmation.
5. When the book becomes available, the student receives a notification.

A student cannot create duplicate active reservations for the same title.

---

## 12.7 Download QR Codes

The Student Borrow page provides access to:

- Student QR download
- Selected Book QR download

The Profile page also provides Student QR download.

QR files are generated as PNG images.

---

# 13. IMPORT TEMPLATES

The templates folder contains import files for students and books.

Current template files include:

- student_import_template.csv
- book_import_template.csv
- library_import_templates.xlsx

The enhanced workbook also includes instructional content and notes.

### Recommended Import Procedure

1. Open the appropriate template.
2. Keep the header row unchanged.
3. Replace the sample records with actual data.
4. Check dates.
5. Check student numbers/book numbers for duplicates.
6. Check numeric fields.
7. Check email addresses.
8. Check required fields.
9. Save the file.
10. Import it through the corresponding system page.

For XLSX imports, PHP Zip support is required.

---

# 14. SECURITY FEATURES

The current build includes multiple security controls:

- Secure session cookie configuration
- HttpOnly cookies
- SameSite cookie protection
- Secure cookies automatically when HTTPS is used
- Session ID regeneration
- Session fingerprint checks
- Idle and absolute session expiry
- CSRF protection
- Login rate limiting
- Strong password policy
- CAPTCHA
- Student email verification
- Verification-code expiration
- Verification attempt limits
- Resend cooldowns
- Server-side validation
- Database-backed audit logging
- QR validation
- QR transaction security logging
- Database error logging
- Security response headers
- TLS certificate verification for external QR requests

---

# 15. QR SECURITY NOTES

QR codes are not used as a replacement for password security in normal student login.

The system validates QR information against the database before processing supported QR downloads or transactions.

Do not manually modify QR values in the database unless the application's QR-generation and validation process is also considered.

---

# 16. DATA SAFETY

Library information may include personally identifiable student information.

Protect:

- Student names
- Student numbers
- Contact numbers
- Email addresses
- Passwords
- Borrowing records
- Audit records
- Database backups
- Gmail App Passwords

Do not publish real student records, passwords, or private backup files.

---

# 17. TROUBLESHOOTING

### Apache does not start

Check for port conflicts and confirm that another web server is not using the Apache port.

### MySQL does not start

Check the XAMPP MySQL logs and confirm that the MariaDB/MySQL service is not already running elsewhere.

### XLSX import fails

Check that PHP Zip/ZipArchive is enabled and restart Apache.

### Email verification does not send

Check:

- Gmail App Password
- Gmail 2-Step Verification
- mail_config.php
- OpenSSL
- internet connection
- SMTP configuration

### QR download fails

Check:

- Student/book record exists
- QR value is not empty
- Record is active
- QR image generation service is reachable
- PHP has permission to create/write the qr_codes directory

### Backup keeps repeating

The current backup management implementation uses POST/Redirect/GET and submission locking to prevent repeated creation caused by page refresh or repeated clicks.

If repeated backups still occur, check for multiple forms submitting the same action or browser extensions/scripts replaying the request.

---

# 18. RECOMMENDED ADMIN WORKFLOW

For normal library operations, use this sequence:

1. Maintain Student Records.
2. Maintain Book Inventory.
3. Check Reservations.
4. Process Borrowing/Returning.
5. Review Notifications.
6. Review Audit Logs when needed.
7. Review Reports periodically.
8. Create database backups regularly.

Before major database changes or restoration:

1. Create a fresh backup.
2. Verify the backup appears in Backup History.
3. Download a copy when appropriate.
4. Perform the maintenance/restoration.
5. Verify students, books, transactions, reservations, and system login afterward.

---

# 19. FILE STRUCTURE

Important project components include:

admin/
- dashboard.php
- inventory.php
- student_records.php
- transactions.php
- qr_transaction.php
- reservations.php
- reports.php
- backup_management.php
- audit_logs.php

student/
- portal.php
- profile.php
- borrow.php
- register.php
- forgot_password.php

includes/
- security.php
- student_session.php
- audit_logger.php
- backup_helper.php
- notification_helper.php
- library_rules.php
- qr_security.php

database/
- library_borrowing_system.sql
- enhancement SQL files

templates/
- Student and Book import templates

---

# 20. IMPORTANT ADMIN REMINDERS

- Do not share administrator passwords.
- Do not share Gmail App Passwords.
- Do not delete database backups without checking their purpose.
- Always verify a backup after creating it.
- Create a fresh backup before restoration.
- Do not manually edit imported headers.
- Do not delete active transaction records simply to correct inventory.
- Use Archive/Restore where appropriate.
- Review audit logs when an unexpected system change occurs.
- Keep the system database backed up regularly.

---

# 21. VERSION / CHANGE HISTORY

The current build includes the following major improvements developed for the system:

- Combined Admin + Student login
- CAPTCHA security
- Show-password controls
- Forgot-password workflow
- Student registration email verification
- Password policy improvements
- Session security
- Audit logging
- Backup and restore management
- Inventory management improvements
- Book detail and editing workflow
- Copy removal down to zero available copies
- Student and book QR downloads
- QR transaction security
- Reservation management
- Borrowing limits
- Reservation expiry rules
- Database notifications
- Dashboard alerts
- Automatic search and filtering
- Improved import templates
- Consistent system navigation and header presentation

---

## 22. SUPPORTING FILES

See the project's database, templates, configuration, and include files for the implementation details.

Keep this README with the project so future users and maintainers have a reference for installation, administration, and daily operation.

---

END OF USER MANUAL
