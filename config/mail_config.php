<?php
/**
 * Gmail SMTP configuration for registration verification, password resets,
 * and Library Access Card delivery.
 *
 * Gmail does NOT accept the normal Google account password for SMTP login.
 * Use a 16-character Google App Password instead.
 *
 * Setup:
 * 1. Turn on 2-Step Verification for the Gmail account.
 * 2. Create an App Password at https://myaccount.google.com/apppasswords
 * 3. Paste that 16-character App Password below (spaces are optional).
 */

define('MAIL_ENABLED', true);
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);              // STARTTLS
define('MAIL_USERNAME', 'makidom20@gmail.com');
define('MAIL_APP_PASSWORD', 'jutz ytmz bjnb bgho');
define('MAIL_FROM_EMAIL', 'makidom20@gmail.com');
define('MAIL_FROM_NAME', 'Jose Abad Santos High School Library');
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_TIMEOUT', 20);

// Keep TLS certificate verification enabled for secure Gmail delivery.
define('MAIL_TLS_VERIFY_PEER', true);
// Localhost development safety valve: if Windows/Laragon still rejects Gmail's
// certificate, the SMTP connection may retry without certificate verification.
// This fallback is ONLY used for localhost/.test/.local hosts. Production stays verified.
define('MAIL_ALLOW_INSECURE_TLS_FALLBACK', true);
