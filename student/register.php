<?php
/**
 * Student Registration Page
 * New student accounts are created only after a 6-digit email verification code is confirmed.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/gmail_smtp.php';

function clearPendingRegistration(): void {
    unset(
        $_SESSION['registration_pending_data'],
        $_SESSION['registration_pending_expires'],
        $_SESSION['registration_otp_hash'],
        $_SESSION['registration_otp_expires'],
        $_SESSION['registration_otp_attempts'],
        $_SESSION['registration_otp_last_sent']
    );
}

function generateUniqueRegistrationQr(mysqli $conn): string {
    do {
        $qrCode = 'STU-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $conn->prepare('SELECT student_id FROM students WHERE qr_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to generate a student QR code right now.');
        }
        $stmt->bind_param('s', $qrCode);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($exists);

    return $qrCode;
}

$errors = [];
$success = false;
$new_student_no = null;
$generated_qr = null;
$verification_pending = false;
$pending_masked_email = '';

if (isset($_GET['completed']) && !empty($_SESSION['registration_success']) && is_array($_SESSION['registration_success'])) {
    $successData = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
    $success = true;
    $new_student_no = $successData['student_id'] ?? null;
    $generated_qr = $successData['qr_code'] ?? null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api = (string)($_POST['api'] ?? '');

    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');

        if ($api === 'verify_registration_code') {
            $code = preg_replace('/\D+/', '', (string)($_POST['code'] ?? ''));
            $pending = $_SESSION['registration_pending_data'] ?? null;

            if (!is_array($pending) || empty($_SESSION['registration_otp_hash'])) {
                throw new RuntimeException('Your registration verification session has expired. Submit the registration form again.');
            }
            if (time() > (int)($_SESSION['registration_pending_expires'] ?? 0)) {
                clearPendingRegistration();
                throw new RuntimeException('Your registration session expired. Submit the registration form again.');
            }
            if (time() > (int)($_SESSION['registration_otp_expires'] ?? 0)) {
                throw new RuntimeException('The verification code expired. Request a new code.');
            }
            if ((int)($_SESSION['registration_otp_attempts'] ?? 0) >= 5) {
                clearPendingRegistration();
                throw new RuntimeException('Too many incorrect verification attempts. Submit the registration form again.');
            }
            if (!preg_match('/^\d{6}$/', $code)) {
                throw new RuntimeException('Enter the 6-digit verification code.');
            }

            $_SESSION['registration_otp_attempts'] = (int)($_SESSION['registration_otp_attempts'] ?? 0) + 1;
            if (!password_verify($code, $_SESSION['registration_otp_hash'])) {
                throw new RuntimeException('Incorrect verification code.');
            }

            $check = $conn->prepare('SELECT student_id FROM students WHERE student_no = ? OR email = ? LIMIT 1');
            if (!$check) {
                throw new RuntimeException('Unable to finish registration right now.');
            }
            $check->bind_param('ss', $pending['student_no'], $pending['email']);
            $check->execute();
            $duplicate = $check->get_result()->num_rows > 0;
            $check->close();
            if ($duplicate) {
                clearPendingRegistration();
                throw new RuntimeException('Student Number or Email is already registered.');
            }

            $qrCode = generateUniqueRegistrationQr($conn);
            $status = 'active';
            $stmt = $conn->prepare(
                'INSERT INTO students (full_name, student_no, student_group, department, year_level, contact_number, card_valid_until, email, qr_code, password, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to create the student account right now.');
            }

            $stmt->bind_param(
                'sssssssssss',
                $pending['full_name'],
                $pending['student_no'],
                $pending['student_group'],
                $pending['department'],
                $pending['year_level'],
                $pending['contact_number'],
                $pending['card_valid_until'],
                $pending['email'],
                $qrCode,
                $pending['password_hash'],
                $status
            );

            if (!$stmt->execute()) {
                $message = $stmt->error;
                $stmt->close();
                logError('Student registration insert error after verification: ' . $message);
                throw new RuntimeException('Registration could not be completed. Please try again.');
            }

            $studentId = (int)$conn->insert_id;
            $stmt->close();

            $_SESSION['registration_success'] = [
                'student_id' => $studentId,
                'qr_code' => $qrCode,
            ];
            clearPendingRegistration();

            echo json_encode([
                'success' => true,
                'message' => 'Email verified. Your student account has been created.',
                'redirect' => '/LibraryBorrowingSystem/student/register.php?completed=1'
            ]);
            exit();
        }

        if ($api === 'resend_registration_code') {
            $pending = $_SESSION['registration_pending_data'] ?? null;
            if (!is_array($pending) || empty($pending['email']) || time() > (int)($_SESSION['registration_pending_expires'] ?? 0)) {
                clearPendingRegistration();
                throw new RuntimeException('Your registration session expired. Submit the registration form again.');
            }

            $lastSent = (int)($_SESSION['registration_otp_last_sent'] ?? 0);
            $wait = 60 - (time() - $lastSent);
            if ($wait > 0) {
                throw new RuntimeException("Please wait {$wait} second(s) before requesting another code.");
            }

            $code = generateOtpCode();
            sendStudentRegistrationVerificationEmail($pending['email'], $pending['full_name'], $code);
            $_SESSION['registration_otp_hash'] = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['registration_otp_expires'] = time() + 300;
            $_SESSION['registration_otp_attempts'] = 0;
            $_SESSION['registration_otp_last_sent'] = time();

            echo json_encode([
                'success' => true,
                'message' => 'A new 6-digit verification code was sent to ' . maskEmail($pending['email']) . '.'
            ]);
            exit();
        }

        if ($api === 'cancel_registration_verification') {
            clearPendingRegistration();
            echo json_encode(['success' => true]);
            exit();
        }

        throw new RuntimeException('Invalid request.');
    } catch (Throwable $e) {
        logError('Student registration verification error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['api'])) {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    $full_name = trim($_POST['full_name'] ?? '');
    $student_no = trim($_POST['student_no'] ?? '');
    $student_group = trim($_POST['student_group'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    $valid_year_levels = ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
    $senior_high_grades = ['Grade 11', 'Grade 12'];
    $valid_departments = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Arts and Design', 'Sports'];
    $card_valid_until = date('Y-m-d', strtotime('+1 year'));

    if ($full_name === '') {
        $errors[] = 'Name is required.';
    } elseif (strlen($full_name) < 3) {
        $errors[] = 'Name must be at least 3 characters long.';
    }

    if ($student_no === '') {
        $errors[] = 'Student Number is required.';
    } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $student_no)) {
        $errors[] = 'Student Number contains invalid characters.';
    }

    if ($student_group === '') {
        $errors[] = 'Section is required.';
    }

    if (!in_array($year_level, $valid_year_levels, true)) {
        $errors[] = 'Please select a valid grade level.';
    }

    if (in_array($year_level, $senior_high_grades, true)) {
        if (!in_array($department, $valid_departments, true)) {
            $errors[] = 'Please select a valid Senior High School strand.';
        }
    } else {
        $department = '';
    }

    if ($contact_number === '') {
        $errors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9\+\-\s\(\)]+$/', $contact_number)) {
        $errors[] = 'Contact Number contains invalid characters.';
    }

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Password is required.';
    } else {
        $password_errors = passwordPolicyErrors($password);
        if (!empty($password_errors)) {
            $errors[] = strongPasswordMessage($password_errors);
        }
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare('SELECT student_id FROM students WHERE student_no = ? OR email = ? LIMIT 1');
            if (!$check_stmt) {
                throw new RuntimeException('Unable to validate the registration right now.');
            }
            $check_stmt->bind_param('ss', $student_no, $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = 'Student Number or Email already registered.';
            }
            $check_stmt->close();
        } catch (Throwable $e) {
            $errors[] = 'Database error during validation.';
            logError('Student registration validation error: ' . $e->getMessage());
        }
    }

    if (empty($errors)) {
        try {
            $code = generateOtpCode();
            sendStudentRegistrationVerificationEmail($email, $full_name, $code);

            clearPendingRegistration();
            $_SESSION['registration_pending_data'] = [
                'full_name' => $full_name,
                'student_no' => $student_no,
                'student_group' => $student_group,
                'department' => $department,
                'year_level' => $year_level,
                'contact_number' => $contact_number,
                'card_valid_until' => $card_valid_until,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ];
            $_SESSION['registration_pending_expires'] = time() + 900;
            $_SESSION['registration_otp_hash'] = password_hash($code, PASSWORD_DEFAULT);
            $_SESSION['registration_otp_expires'] = time() + 300;
            $_SESSION['registration_otp_attempts'] = 0;
            $_SESSION['registration_otp_last_sent'] = time();

            $verification_pending = true;
            $pending_masked_email = maskEmail($email);
        } catch (Throwable $e) {
            clearPendingRegistration();
            $errors[] = 'Unable to send the registration verification code. Please try again or ask the administrator to check the Gmail setup.';
            logError('Student registration email verification error: ' . $e->getMessage());
        }
    }
}

if (!$verification_pending && !empty($_SESSION['registration_pending_data']) && is_array($_SESSION['registration_pending_data'])) {
    if (time() <= (int)($_SESSION['registration_pending_expires'] ?? 0)) {
        $verification_pending = true;
        $pending_masked_email = maskEmail((string)($_SESSION['registration_pending_data']['email'] ?? ''));
    } else {
        clearPendingRegistration();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: #141F52;
            color: #202A44;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        /* Header Section */
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
            padding-top: 20px;
        }

        .header h1 {
            font-size: 32px;
            margin-bottom: 8px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 15px;
            opacity: 0.95;
            font-weight: 300;
        }

        /* Registration Card */
        .registration-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            border: 1px solid #91B0E0;
            overflow: hidden;
        }

        .card-header {
            background: #141F52;
            color: white;
            padding: 24px 30px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 4px solid #F4F916;
        }

        .card-body {
            padding: 40px 30px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #FBE8DC;
            border-left: 4px solid #BB5716;
            color: #7A3A0E;
        }

        .alert-success {
            background: #EDF5DD;
            border-left: 4px solid #567D1F;
            color: #344E15;
        }

        .alert ul {
            margin-left: 20px;
            line-height: 1.6;
        }

        .alert li {
            margin-bottom: 6px;
        }

        .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Success Message Content */
        .success-content {
            text-align: center;
        }

        .success-content h3 {
            color: #344E15;
            margin-bottom: 16px;
            font-size: 20px;
        }

        .success-content p {
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .success-qr {
            background: #F7F9FC;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: center;
        }

        .success-qr h4 {
            color: #202A44;
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .success-qr img {
            max-width: 180px;
            height: auto;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(20, 31, 82, 0.2);
            background: white;
            padding: 8px;
        }

        .success-qr-code {
            margin-top: 12px;
            font-size: 12px;
            color: #141F52;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }

        .qr-download-btn {
            display: inline-block;
            margin-top: 14px;
            padding: 10px 18px;
            background: #141F52;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
        }

        .qr-download-btn:hover { background: #52618D; }

        .success-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .success-actions a {
            padding: 12px 24px;
            background: #141F52;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(20, 31, 82, 0.3);
        }

        .success-actions a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(20, 31, 82, 0.4);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .required {
            color: #BB5716;
            margin-left: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 14px 16px;
            border: 2px solid #E7EEF7;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #202A44;
            background: #F7F9FC;
            transition: all 0.3s ease;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #91B0E0;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #141F52;
            background: white;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .helper-text {
            font-size: 12px;
            color: #52618D;
            margin-top: 8px;
            line-height: 1.5;
        }

        /* Submit Button */
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-submit {
            padding: 14px 40px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-submit:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(20, 31, 82, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-back {
            padding: 14px 40px;
            background: #E7EEF7;
            color: #202A44;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            background: #D2E2F6;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        /* Footer Links */
        .form-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid #E7EEF7;
            color: #52618D;
            font-size: 14px;
        }

        .form-footer a {
            color: #52618D;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .form-footer a:hover {
            color: #141F52;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }

            .card-body {
                padding: 24px 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-submit,
            .btn-back {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 20px;
            }

            .header p {
                font-size: 13px;
            }

            .card-body {
                padding: 20px 16px;
            }

            .form-group label {
                font-size: 11px;
            }

            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px 14px;
                font-size: 14px;
            }

            .alert {
                font-size: 13px;
            }
        }

        .verification-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(0,0,0,.6);
            z-index: 3000;
        }
        .verification-modal.show { display: flex; }
        .verification-card {
            width: 100%;
            max-width: 430px;
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 55px rgba(0,0,0,.3);
        }
        .verification-header {
            background: #141F52;
            color: white;
            padding: 20px 22px;
            border-bottom: 4px solid #F4F916;
        }
        .verification-header h3 { margin: 0; font-size: 20px; }
        .verification-body { padding: 24px; }
        .verification-info {
            background: #F3F7FC;
            color: #52618D;
            border-radius: 9px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 13px;
            line-height: 1.5;
        }
        .verification-error {
            display: none;
            background: #FBE8DC;
            color: #7A3A0E;
            border-radius: 8px;
            padding: 11px 13px;
            margin-bottom: 14px;
            font-size: 13px;
        }
        .verification-error.show { display: block; }
        .verification-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 16px;
        }
        .verification-actions button {
            padding: 12px 16px;
            border: 0;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
        }
        .verification-cancel { background: #E7EEF7; color: #202A44; }
        .verification-submit { background: #141F52; color: white; }
        .verification-resend {
            display: block;
            margin: 15px auto 0;
            border: 0;
            background: transparent;
            color: #141F52;
            font-weight: 700;
            text-decoration: underline;
            cursor: pointer;
        }
        .verification-resend:disabled { opacity: .55; cursor: not-allowed; }
        @media (max-width: 520px) { .verification-actions { grid-template-columns: 1fr; } }
    
        .password-field {
            position: relative;
            width: 100%;
        }

        .password-field > input[type="password"],
        .password-field > input[type="text"] {
            width: 100%;
            padding-right: 78px !important;
        }

        .show-password-btn {
            position: absolute;
            top: 50%;
            right: 8px;
            transform: translateY(-50%) !important;
            min-width: 62px !important;
            width: auto !important;
            min-height: 34px !important;
            height: 34px !important;
            padding: 5px 9px !important;
            border: 1px solid #D2E2F6 !important;
            border-radius: 6px !important;
            background: #F7F9FC !important;
            color: #52618D !important;
            box-shadow: none !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            line-height: 1 !important;
            cursor: pointer;
            z-index: 2;
        }

        .show-password-btn:hover {
            background: #E7EEF7 !important;
            color: #141F52 !important;
            transform: translateY(-50%) !important;
            box-shadow: none !important;
        }

        .show-password-btn:focus-visible {
            outline: 2px solid #141F52;
            outline-offset: 2px;
        }

        @media (max-width: 480px) {
            .show-password-btn {
                min-width: 58px !important;
                font-size: 10px !important;
                right: 6px;
            }
        }

    </style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="student-register-page">
    <?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
    <?php if ($success && $new_student_no): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast('Student registration completed. QR code created and your password was securely saved.', 'success', 4200, 'Registration Successful');
            });
        </script>
    <?php elseif (!empty($errors)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast(<?php echo json_encode('Registration failed. ' . ($errors[0] ?? 'Please check the form and try again.')); ?>, 'error', 4500, 'Registration Failed');
            });
        </script>
    <?php elseif ($verification_pending): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast('A 6-digit registration verification code was sent to <?php echo htmlspecialchars($pending_masked_email, ENT_QUOTES, 'UTF-8'); ?>.', 'success', 4200, 'Verification Code Sent');
            });
        </script>
    <?php endif; ?>
    <div class="container">
        <div class="header">
            <h1>Student Registration</h1>
            <p>Register to access the library borrowing system</p>
        </div>

        <div class="registration-card">
            <div class="card-header">
                <span>📝</span>
                <span>Create Your Student Account</span>
            </div>

            <div class="card-body">
                <?php if ($success && $new_student_no): ?>
                    <!-- Success Message -->
                    <div class="alert alert-success">
                        <div class="alert-icon">✓</div>
                        <div class="success-content">
                            <h3>Registration Successful!</h3>
                            <p>Your student account has been created. You can now access the student portal.</p>
                            <p><strong>Student ID:</strong> <?php echo str_pad($new_student_no, 4, '0', STR_PAD_LEFT); ?></p>
                            
                            <div class="success-qr">
                                <h4>Your Student QR Code</h4>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($generated_qr); ?>" 
                                     alt="Student QR Code">
                                <div class="success-qr-code"><?php echo htmlspecialchars($generated_qr); ?></div>
                                <a class="qr-download-btn" href="/LibraryBorrowingSystem/download_qr.php?code=<?php echo urlencode($generated_qr); ?>&type=student">Download QR Code</a>
                            </div>

                            <p style="color: #52618D; font-size: 13px; margin-top: 16px;">
                                Save your QR code and keep your password private. Email verification is required only when creating a new student account.
                            </p>

                            <div class="success-actions">
                                <a href="/LibraryBorrowingSystem/student/portal.php">Go to Student Portal</a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon">⚠</div>
                            <div>
                                <strong>Registration Failed</strong>
                                <ul>
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Registration Form -->
                    <form method="POST" novalidate>
                        <?php echo csrfField(); ?>
                        <!-- Personal Information Section -->
                        <h3 style="color: #202A44; margin: 24px 0 16px; font-size: 16px; font-weight: 600; border-bottom: 2px solid #E7EEF7; padding-bottom: 12px;">
                            Personal Information
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="full_name" placeholder="Enter your full name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                                <div class="helper-text">Your complete legal name</div>
                            </div>

                            <div class="form-group">
                                <label>Student Number <span class="required">*</span></label>
                                <input type="text" name="student_no" placeholder="e.g., 23-01446" 
                                       value="<?php echo htmlspecialchars($_POST['student_no'] ?? ''); ?>" required>
                                <div class="helper-text">Your institutional student number</div>
                            </div>

                            <div class="form-group">
                                <label>Section <span class="required">*</span></label>
                                <input type="text" name="student_group" placeholder="e.g., Rizal, 10-A" 
                                       value="<?php echo htmlspecialchars($_POST['student_group'] ?? ''); ?>" required>
                                <div class="helper-text">Your class section</div>
                            </div>

                            <div class="form-group">
                                <label>Grade Level <span class="required">*</span></label>
                                <select name="year_level" id="year_level" required>
                                    <option value="">Select Grade Level</option>
                                    <optgroup label="Junior High School">
                                        <option value="Grade 7" <?php if (($_POST['year_level'] ?? '') === 'Grade 7') echo 'selected'; ?>>Grade 7</option>
                                        <option value="Grade 8" <?php if (($_POST['year_level'] ?? '') === 'Grade 8') echo 'selected'; ?>>Grade 8</option>
                                        <option value="Grade 9" <?php if (($_POST['year_level'] ?? '') === 'Grade 9') echo 'selected'; ?>>Grade 9</option>
                                        <option value="Grade 10" <?php if (($_POST['year_level'] ?? '') === 'Grade 10') echo 'selected'; ?>>Grade 10</option>
                                    </optgroup>
                                    <optgroup label="Senior High School">
                                        <option value="Grade 11" <?php if (($_POST['year_level'] ?? '') === 'Grade 11') echo 'selected'; ?>>Grade 11</option>
                                        <option value="Grade 12" <?php if (($_POST['year_level'] ?? '') === 'Grade 12') echo 'selected'; ?>>Grade 12</option>
                                    </optgroup>
                                </select>
                                <div class="helper-text">Grades 7-10 are Junior High School; Grades 11-12 are Senior High School</div>
                            </div>

                            <div class="form-group" id="department_group" style="display:none;">
                                <label>Department / Strand <span class="required">*</span></label>
                                <select name="department" id="department">
                                    <option value="">Select Senior High School Strand</option>
                                    <?php foreach (['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Arts and Design', 'Sports'] as $strand): ?>
                                        <option value="<?php echo htmlspecialchars($strand); ?>" <?php if (($_POST['department'] ?? '') === $strand) echo 'selected'; ?>><?php echo htmlspecialchars($strand); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="helper-text">Shown only for Grade 11 and Grade 12 students</div>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <h3 style="color: #202A44; margin: 24px 0 16px; font-size: 16px; font-weight: 600; border-bottom: 2px solid #E7EEF7; padding-bottom: 12px;">
                            Contact Information
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Contact Number <span class="required">*</span></label>
                                <input type="tel" name="contact_number" placeholder="e.g., +63 945 735 2866" 
                                       value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" required>
                                <div class="helper-text">Your mobile or phone number</div>
                            </div>

                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" placeholder="e.g., student@example.com" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                <div class="helper-text">A 6-digit verification code will be sent here to finish registration.</div>
                            </div>

                            <div class="form-group">
                                <label>Library Card Valid Until</label>
                                <input type="text" disabled placeholder="Automatically set to 1 year from today" 
                                       value="<?php echo date('F d, Y', strtotime('+1 year')); ?>">
                                <div class="helper-text">Your library card validity will be set to 1 year from today</div>
                            </div>
                        </div>

                        <h3 style="color: #202A44; margin: 24px 0 16px; font-size: 16px; font-weight: 600; border-bottom: 2px solid #E7EEF7; padding-bottom: 12px;">
                            Account Security
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Password <span class="required">*</span></label>
                                <div class="password-field">
                                    <input type="password" name="password" maxlength="128" autocomplete="new-password" required>
                                    <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
                                </div>
                                <div class="helper-text">Use at least 10 characters with uppercase, lowercase, number, and special character.</div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Password <span class="required">*</span></label>
                                <div class="password-field">
                                    <input type="password" name="confirm_password" maxlength="128" autocomplete="new-password" required>
                                    <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
                                </div>
                                <div class="helper-text">Enter the same password again.</div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Send Verification Code</button>
                            <a href="/LibraryBorrowingSystem/student/portal.php" class="btn-back">Cancel</a>
                        </div>

                        <!-- Footer Links -->
                        <div class="form-footer">
                            Already have an account? <a href="/LibraryBorrowingSystem/student/portal.php">Go to Student Portal</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php if (!$success): ?>
<div id="registrationVerificationModal" class="verification-modal<?php echo $verification_pending ? ' show' : ''; ?>" role="dialog" aria-modal="true" aria-labelledby="registrationVerificationTitle">
    <div class="verification-card">
        <div class="verification-header">
            <h3 id="registrationVerificationTitle">Verify Your Email</h3>
        </div>
        <div class="verification-body">
            <div class="verification-info">
                Enter the 6-digit code sent to <strong id="verificationEmail"><?php echo htmlspecialchars($pending_masked_email ?: 'your email', ENT_QUOTES, 'UTF-8'); ?></strong>.<br>
                Your account will not be created until this code is verified. The code expires in 5 minutes.
            </div>
            <div id="verificationError" class="verification-error"></div>
            <form id="registrationVerificationForm">
                <div class="form-group">
                    <label for="registrationVerificationCode">6-Digit Verification Code</label>
                    <input type="text" id="registrationVerificationCode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
                </div>
                <div class="verification-actions">
                    <button type="button" class="verification-cancel" id="cancelRegistrationVerification">Cancel</button>
                    <button type="submit" class="verification-submit" id="verifyRegistrationButton">Verify & Finish Registration</button>
                </div>
            </form>
            <button type="button" class="verification-resend" id="resendRegistrationCode">Resend verification code</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    (function () {
        const gradeSelect = document.getElementById('year_level');
        const departmentGroup = document.getElementById('department_group');
        const departmentSelect = document.getElementById('department');

        function toggleDepartment() {
            if (!gradeSelect || !departmentGroup || !departmentSelect) return;
            const isSeniorHigh = gradeSelect.value === 'Grade 11' || gradeSelect.value === 'Grade 12';
            departmentGroup.style.display = isSeniorHigh ? 'flex' : 'none';
            departmentSelect.required = isSeniorHigh;
            if (!isSeniorHigh) departmentSelect.value = '';
        }

        if (gradeSelect) {
            gradeSelect.addEventListener('change', toggleDepartment);
            toggleDepartment();
        }

        const verificationModal = document.getElementById('registrationVerificationModal');
        const verificationForm = document.getElementById('registrationVerificationForm');
        const verificationCode = document.getElementById('registrationVerificationCode');
        const verificationError = document.getElementById('verificationError');
        const csrfToken = <?php echo json_encode(csrfToken()); ?>;

        async function registrationApi(action, extra = {}) {
            const body = new URLSearchParams();
            body.set('api', action);
            body.set('csrf_token', csrfToken);
            Object.entries(extra).forEach(([key, value]) => body.set(key, value));
            const response = await fetch('/LibraryBorrowingSystem/student/register.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            });
            return await response.json();
        }

        function showVerificationError(message) {
            if (!verificationError) return;
            verificationError.textContent = message;
            verificationError.classList.add('show');
        }

        if (verificationModal && verificationModal.classList.contains('show')) {
            setTimeout(() => verificationCode && verificationCode.focus(), 150);
        }

        if (verificationForm) {
            verificationForm.addEventListener('submit', async function (event) {
                event.preventDefault();
                const button = document.getElementById('verifyRegistrationButton');
                verificationError.classList.remove('show');
                button.disabled = true;
                button.textContent = 'Verifying...';

                try {
                    const data = await registrationApi('verify_registration_code', {code: verificationCode.value});
                    if (!data.success) throw new Error(data.message || 'Verification failed.');
                    showToast(data.message || 'Registration completed.', 'success', 2600, 'Registration Successful');
                    setTimeout(() => window.location.href = data.redirect, 650);
                } catch (error) {
                    showVerificationError(error.message);
                    verificationCode.value = '';
                    verificationCode.focus();
                } finally {
                    button.disabled = false;
                    button.textContent = 'Verify & Finish Registration';
                }
            });
        }

        const resendButton = document.getElementById('resendRegistrationCode');
        if (resendButton) {
            resendButton.addEventListener('click', async function () {
                this.disabled = true;
                verificationError.classList.remove('show');
                try {
                    const data = await registrationApi('resend_registration_code');
                    if (!data.success) throw new Error(data.message || 'Unable to resend the code.');
                    showToast(data.message, 'success', 3500, 'Code Sent');
                } catch (error) {
                    showVerificationError(error.message);
                } finally {
                    setTimeout(() => { this.disabled = false; }, 1500);
                }
            });
        }

        const cancelVerification = document.getElementById('cancelRegistrationVerification');
        if (cancelVerification) {
            cancelVerification.addEventListener('click', async function () {
                try { await registrationApi('cancel_registration_verification'); } catch (error) {}
                verificationModal.classList.remove('show');
                if (verificationCode) verificationCode.value = '';
                showToast('Registration verification cancelled. Your account was not created.', 'info', 3200);
            });
        }
    })();
</script>

<script>
(function () {
    function initPasswordToggles(root) {
        (root || document).querySelectorAll('.password-field').forEach(function (wrapper) {
            var input = wrapper.querySelector('input[type="password"], input[type="text"]');
            var button = wrapper.querySelector('.show-password-btn');
            if (!input || !button || button.dataset.ready === '1') return;

            button.dataset.ready = '1';
            button.addEventListener('click', function () {
                var showing = input.type === 'text';
                input.type = showing ? 'password' : 'text';
                button.textContent = showing ? 'Show' : 'Hide';
                button.setAttribute('aria-pressed', showing ? 'false' : 'true');
                button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                input.focus({preventScroll: true});
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initPasswordToggles();
        });
    } else {
        initPasswordToggles();
    }
})();
</script>

</body>
</html>
