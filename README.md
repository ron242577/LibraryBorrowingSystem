# Jose Abad Santos High School Library Borrowing System

This build contains the current combined Admin + Student Library Borrowing System, including the latest inventory changes, same-day borrowing/return process, downloadable QR codes, updated book/student fields, and the security update.

## Security update

Existing password values in `database.sql` are **not reset or changed** by this update. The application still accepts the password hashes already stored in the database. New passwords created from this version use PHP `password_hash()` and must meet the stronger password policy.

Security additions include:

- Secure session cookie settings (`HttpOnly`, `SameSite=Lax`, `Secure` automatically when HTTPS is used).
- Session ID regeneration after login.
- Session fingerprint checks and idle/absolute session expiry.
- CSRF protection on state-changing forms and login requests.
- Login rate limiting after repeated failed Admin/Student password attempts.
- Strong password rules for newly created/reset Admin passwords and newly created/imported Student accounts.
- Student login uses QR/manual code + password + a server-side math CAPTCHA.
- New self-registering students must verify a 6-digit email code before their account is created. Registration codes expire after 5 minutes, have attempt limits, and resend cooldowns.
- Database/server error details are logged instead of intentionally shown to end users.
- HTTPS/TLS certificate verification is enabled for external QR downloads.
- Security response headers are applied centrally.

## Gmail verification setup

Email verification is used only when a student creates a new account through the public Student Registration page. The code is delivered through Gmail SMTP. It requires a Gmail **App Password**. Do not use the normal Gmail password.

1. Create or choose a Gmail account for the school library.
2. Enable **2-Step Verification** on that Google account.
3. Create a **Google App Password** for the library system.
4. Open:

   `config/mail_config.php`

5. Set:

```php
define('MAIL_ENABLED', true);
define('MAIL_USERNAME', 'yourlibraryaccount@gmail.com');
define('MAIL_APP_PASSWORD', 'your-16-character-app-password');
define('MAIL_FROM_EMAIL', 'yourlibraryaccount@gmail.com');
```

6. Restart Apache after saving if necessary.
7. Make sure XAMPP/PHP has the OpenSSL extension enabled and the computer has internet access.

The App Password should be treated as a secret. Do not upload the configured `mail_config.php` to GitHub or share it publicly.

## Student login flow

1. Student scans the QR code or enters the QR code manually.
2. The login modal asks for the student password and a server-side math CAPTCHA.
3. If both are correct, the Student Profile opens immediately.

Email codes are **not** required for normal student login.

## New student self-registration verification

1. The student completes the registration form, including email and a strong password.
2. The system validates the details but does **not** create the account yet.
3. A 6-digit code is sent to the email address entered in the registration form.
4. The student enters the code in the verification modal.
5. Only after the code is correct does the system create the student account and QR code.

## Password rules for new/reset passwords

New passwords must:

- Have at least 10 characters.
- Contain at least one uppercase letter.
- Contain at least one lowercase letter.
- Contain at least one number.
- Contain at least one special character.
- Not be a commonly used weak password.

These rules apply only when creating or resetting passwords. The update does not automatically replace passwords already stored in the database.

## Database

Use the single `database.sql` included in this project for a fresh installation. It includes the security rate-limit table as well as the current students, books, and transactions.

## Import templates

The updated student import template now includes a required `Password` column. Imported student passwords must follow the strong password rules. Email verification applies only to students who create their own account through the public registration page.

The book import templates retain the current book fields: Book Number, Book Pages, Source of Funds, Cost Price, Publisher, Edition, Volumes, Class, and the other existing inventory fields.

## XAMPP installation

Place the project at:

`C:\xampp\htdocs\LibraryBorrowingSystem`

Then import `database.sql` using phpMyAdmin and open:

`http://localhost/LibraryBorrowingSystem/`

For `.xlsx` imports, enable the PHP Zip extension (`extension=zip`) in `php.ini`; CSV imports work without ZipArchive.
