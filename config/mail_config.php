<?php
/**
 * Gmail SMTP configuration for new student registration verification codes.
 *
 * IMPORTANT:
 * 1. Use a dedicated Gmail account for the library.
 * 2. Turn on 2-Step Verification for that Gmail account.
 * 3. Create a Gmail App Password and place it below (NOT the normal Gmail password).
 * 4. Do not upload this file to a public repository after adding the real App Password.
 */

define('MAIL_ENABLED', true); // Change to true after filling in the Gmail details below.
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'makidom20@gmail.com');
define('MAIL_APP_PASSWORD', 'dnuf dnvn utxl bckk');
define('MAIL_FROM_EMAIL', 'makidom20@gmail.com');
define('MAIL_FROM_NAME', 'Jose Abad Santos High School Library');
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_TIMEOUT', 15);
