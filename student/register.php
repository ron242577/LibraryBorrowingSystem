<?php
/**
 * Student Registration Page
 * New student accounts are created only after a 6-digit email verification code is confirmed.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/gmail_smtp.php';

// AJAX validation for live registration checks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registration_check'])) {
    header('Content-Type: application/json');
    $type = $_POST['registration_check'];
    $value = trim($_POST['value'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $available = true;
    $message = '';

    if ($type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE email=? UNION SELECT teacher_id FROM teachers WHERE email=? LIMIT 1");
        $stmt->bind_param("ss", $value, $value);
    } elseif ($type === 'contact' && $value !== '') {
        $stmt = $conn->prepare("SELECT student_id FROM students WHERE contact_number=? UNION SELECT teacher_id FROM teachers WHERE contact_number=? LIMIT 1");
        $stmt->bind_param("ss", $value, $value);
    } else {
        echo json_encode(['available'=>true,'message'=>'']);
        exit;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $available = false;
        $message = 'Already registered';
    } else {
        $message = 'Available';
    }
    echo json_encode(['available'=>$available,'message'=>$message]);
    exit;
}


$conn->query("CREATE TABLE IF NOT EXISTS teachers (
    teacher_id INT NOT NULL AUTO_INCREMENT,
    teacher_no VARCHAR(100) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    teaching_grades TEXT NOT NULL,
    teaching_strands TEXT NULL,
    contact_number VARCHAR(20) NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    qr_code VARCHAR(255) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    archived_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (teacher_id),
    UNIQUE KEY uq_teachers_teacher_no (teacher_no),
    UNIQUE KEY uq_teachers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

foreach ([
    'teacher_no' => "ALTER TABLE teachers ADD COLUMN teacher_no VARCHAR(100) NULL AFTER teacher_id",
    'teaching_grades' => "ALTER TABLE teachers ADD COLUMN teaching_grades TEXT NULL AFTER full_name",
    'teaching_strands' => "ALTER TABLE teachers ADD COLUMN teaching_strands TEXT NULL AFTER teaching_grades",
    'contact_number' => "ALTER TABLE teachers ADD COLUMN contact_number VARCHAR(20) NULL AFTER teaching_strands",
    'email' => "ALTER TABLE teachers ADD COLUMN email VARCHAR(255) NULL AFTER teaching_strands",
    'password' => "ALTER TABLE teachers ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '' AFTER email",
    'qr_code' => "ALTER TABLE teachers ADD COLUMN qr_code VARCHAR(255) NULL AFTER password",
    'status' => "ALTER TABLE teachers ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER password",
    'is_archived' => "ALTER TABLE teachers ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
    'archived_at' => "ALTER TABLE teachers ADD COLUMN archived_at DATETIME NULL AFTER is_archived",
    'archived_by' => "ALTER TABLE teachers ADD COLUMN archived_by INT NULL AFTER archived_at",
    'updated_at' => "ALTER TABLE teachers ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
] as $column => $alterSql) {
    $columnCheck = $conn->query("SHOW COLUMNS FROM teachers LIKE '" . $conn->real_escape_string($column) . "'");
    if ($columnCheck && $columnCheck->num_rows === 0) $conn->query($alterSql);
}
$conn->query("UPDATE teachers SET teacher_no = COALESCE(NULLIF(teacher_no, ''), id_number), teaching_grades = COALESCE(NULLIF(teaching_grades, ''), grades), teaching_strands = COALESCE(NULLIF(teaching_strands, ''), strands)");
$existingTeachers = $conn->query("SELECT teacher_id FROM teachers WHERE qr_code IS NULL OR qr_code = ''");
if ($existingTeachers) {
    while ($existingTeacher = $existingTeachers->fetch_assoc()) {
        $teacherQr = 'TCH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $qrUpdate = $conn->prepare('UPDATE teachers SET qr_code=? WHERE teacher_id=?');
        $qrUpdate->bind_param('si', $teacherQr, $existingTeacher['teacher_id']);
        $qrUpdate->execute();
        $qrUpdate->close();
    }
}

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

function generateUniqueTeacherQr(mysqli $conn): string {
    do {
        $qrCode = 'TCH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $stmt = $conn->prepare('SELECT teacher_id FROM teachers WHERE qr_code = ? LIMIT 1');
        if (!$stmt) throw new RuntimeException('Unable to generate a teacher QR code right now.');
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
$new_teacher_no = null;
$new_teacher_qr = null;
$registration_success_role = 'student';
$generated_qr = null;
$verification_pending = false;
$pending_masked_email = '';
$registration_email_sent = false;

if (isset($_GET['completed']) && !empty($_SESSION['registration_success']) && is_array($_SESSION['registration_success'])) {
    $successData = $_SESSION['registration_success'];
    $_SESSION['registration_card_download'] = $successData;
    unset($_SESSION['registration_success']);
    $success = true;
    $registration_success_role = $successData['registration_role'] ?? 'student';
    $new_student_no = $successData['student_id'] ?? null;
    $new_teacher_no = $successData['teacher_no'] ?? null;
    $new_teacher_qr = $successData['qr_code'] ?? null;
    $generated_qr = $successData['qr_code'] ?? null;
    $registration_email_sent = !empty($successData['email_sent']);
    $registration_full_name = trim((string)($successData['full_name'] ?? ''));
    $registration_card_id = trim((string)($registration_success_role === 'teacher' ? ($successData['teacher_no'] ?? '') : ($successData['student_id_number'] ?? '')));
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

            $pendingIdentifier = ($pending['registration_role'] ?? 'student') === 'teacher' ? $pending['teacher_no'] : $pending['student_no'];
            if (($pending['registration_role'] ?? 'student') === 'teacher') {
                $check = $conn->prepare('SELECT teacher_id FROM teachers WHERE teacher_no = ? OR email = ? OR contact_number = ? LIMIT 1');
            } else {
                $check = $conn->prepare('SELECT student_id FROM students WHERE student_no = ? OR email = ? OR contact_number = ? LIMIT 1');
            }
            if (!$check) {
                throw new RuntimeException('Unable to finish registration right now.');
            }
            $check->bind_param('sss', $pendingIdentifier, $pending['email'], $pending['contact_number']);
            $check->execute();
            $duplicate = $check->get_result()->num_rows > 0;
            $check->close();
            if ($duplicate) {
                clearPendingRegistration();
                throw new RuntimeException(($pending['registration_role'] ?? 'student') === 'teacher' ? 'Teacher ID Number or Email is already registered.' : 'Student Number or Email is already registered.');
            }

            $crossCheck = $conn->prepare('SELECT student_id FROM students WHERE email = ? OR contact_number = ? LIMIT 1');
            $crossCheck->bind_param('ss', $pending['email'], $pending['contact_number']);
            $crossCheck->execute();
            if ($crossCheck->get_result()->num_rows > 0) {
                $crossCheck->close();
                clearPendingRegistration();
                throw new RuntimeException('Email or Contact Number is already registered to another user.');
            }
            $crossCheck->close();

            $teacherCrossCheck = $conn->prepare('SELECT teacher_id FROM teachers WHERE email = ? OR contact_number = ? LIMIT 1');
            $teacherCrossCheck->bind_param('ss', $pending['email'], $pending['contact_number']);
            $teacherCrossCheck->execute();
            $teacherDuplicate = $teacherCrossCheck->get_result()->num_rows > 0;
            $teacherCrossCheck->close();
            if ($teacherDuplicate) {
                clearPendingRegistration();
                throw new RuntimeException('Email or Contact Number is already registered to another user.');
            }

            if (($pending['registration_role'] ?? 'student') === 'teacher') {
                $status = 'active';
                $qrCode = generateUniqueTeacherQr($conn);
                $stmt = $conn->prepare(
                    'INSERT INTO teachers (teacher_no, full_name, teaching_grades, teaching_strands, contact_number, email, password, qr_code, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if (!$stmt) {
                    throw new RuntimeException('Unable to create the teacher account right now.');
                }
                $stmt->bind_param('sssssssss', $pending['teacher_no'], $pending['full_name'], $pending['teaching_grades'], $pending['teaching_strands'], $pending['contact_number'], $pending['email'], $pending['password_hash'], $qrCode, $status);
                if (!$stmt->execute()) {
                    $message = $stmt->error;
                    $stmt->close();
                    logError('Teacher registration insert error after verification: ' . $message);
                    throw new RuntimeException('Registration could not be completed. Please try again.');
                }
                $teacherId = (int)$conn->insert_id;
                $stmt->close();

                $registrationEmailSent = false;
                $registrationEmailError = '';
                try {
                    // The verification email is sent first. Only after the user
                    // enters the correct code do we generate and send the access card.
                    $registrationEmailSent = sendLibraryAccessCardEmail(
                        $pending['email'],
                        $pending['full_name'],
                        $pending['teacher_no'],
                        $qrCode,
                        'teacher'
                    );
                } catch (Throwable $mailError) {
                    $registrationEmailError = $mailError->getMessage();
                    logError('Teacher access-card email error after verification: ' . $registrationEmailError);
                }

                $_SESSION['registration_success'] = [
                    'registration_role' => 'teacher',
                    'teacher_id' => $teacherId,
                    'teacher_no' => $pending['teacher_no'],
                    'full_name' => $pending['full_name'],
                    'email' => $pending['email'],
                    'qr_code' => $qrCode,
                    'email_sent' => $registrationEmailSent,
                    'email_error' => $registrationEmailError,
                ];
                clearPendingRegistration();
                echo json_encode([
                    'success' => true,
                    'email_sent' => $registrationEmailSent,
                    'message' => 'Email verified. Your teacher account has been created. Your Library Access Card has been sent to your email address.',
                    'redirect' => '/LibraryBorrowingSystem/student/register.php?completed=1'
                ]);
                exit();
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

            $registrationEmailSent = false;
            $registrationEmailError = '';
            try {
                // The verification code must be accepted before this email is sent.
                $registrationEmailSent = sendLibraryAccessCardEmail(
                    $pending['email'],
                    $pending['full_name'],
                    $pending['student_no'],
                    $qrCode,
                    'student'
                );
            } catch (Throwable $mailError) {
                $registrationEmailError = $mailError->getMessage();
                logError('Student access-card email error after verification: ' . $registrationEmailError);
            }

            $_SESSION['registration_success'] = [
                'student_id' => $studentId,
                'student_id_number' => $pending['student_no'],
                'full_name' => $pending['full_name'],
                'email' => $pending['email'],
                'qr_code' => $qrCode,
                'email_sent' => $registrationEmailSent,
                'email_error' => $registrationEmailError,
            ];
            clearPendingRegistration();

            echo json_encode([
                'success' => true,
                'email_sent' => $registrationEmailSent,
                'message' => 'Email verified. Your student account has been created. Your Library Access Card has been sent to your email address.',
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
            sendStudentRegistrationVerificationEmail($pending['email'], $pending['full_name'], $code, ($pending['registration_role'] ?? 'student'));
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

    $registration_role = ($_POST['registration_role'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
    $full_name = trim($_POST['full_name'] ?? '');
    $student_no = trim($_POST['student_no'] ?? '');
    $student_group = trim($_POST['student_group'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    if ($registration_role === 'teacher') {
        $teacher_no = trim($_POST['teacher_no'] ?? '');
        $teaching_grades = array_values(array_intersect(['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'], (array)($_POST['teaching_grades'] ?? [])));
        $valid_strands = ['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Arts and Design', 'Sports'];
        $teaching_strands = array_values(array_intersect($valid_strands, (array)($_POST['teaching_strands'] ?? [])));
        $teaches_senior_high = (bool)array_intersect($teaching_grades, ['Grade 11', 'Grade 12']);
        if (!$teaches_senior_high) $teaching_strands = [];

        if ($full_name === '' || strlen($full_name) < 3) $errors[] = 'Full name must be at least 3 characters long.';
        if ($teacher_no === '' || !preg_match('/^[A-Za-z0-9\-]+$/', $teacher_no)) $errors[] = 'A valid Teacher ID Number is required.';
        if (empty($teaching_grades)) $errors[] = 'Select at least one grade level.';
        if (array_intersect($teaching_grades, ['Grade 11', 'Grade 12']) && empty($teaching_strands)) $errors[] = 'Select at least one Senior High School strand.';
        if ($contact_number === '') $errors[] = 'Contact Number is required.';
        elseif (!preg_match('/^[0-9\+\-\s\(\)]+$/', $contact_number)) $errors[] = 'Contact Number contains invalid characters.';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please provide a valid email address.';
        if ($password === '') $errors[] = 'Password is required.';
        else {
            $password_errors = passwordPolicyErrors($password, false);
            if (!empty($password_errors)) $errors[] = strongPasswordMessage($password_errors);
        }
        if ($password !== $confirm_password) $errors[] = 'Password confirmation does not match.';

        if (empty($errors)) {
            $check_stmt = $conn->prepare('SELECT teacher_id FROM teachers WHERE teacher_no = ? OR email = ? OR contact_number = ? LIMIT 1');
            $check_stmt->bind_param('sss', $teacher_no, $email, $contact_number);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) $errors[] = 'Teacher ID Number, Email, or Contact Number is already registered. Please use different information.';
            $check_stmt->close();
        }
        if (empty($errors)) {
            $crossCheck = $conn->prepare('SELECT student_id FROM students WHERE email = ? OR contact_number = ? LIMIT 1');
            $crossCheck->bind_param('ss', $email, $contact_number);
            $crossCheck->execute();
            if ($crossCheck->get_result()->num_rows > 0) $errors[] = 'Email or Contact Number is already registered to another user. Please use different information.';
            $crossCheck->close();
        }
        if (empty($errors)) {
            try {
                $code = generateOtpCode();
                sendStudentRegistrationVerificationEmail($email, $full_name, $code, 'teacher');
                clearPendingRegistration();
                $_SESSION['registration_pending_data'] = [
                    'registration_role' => 'teacher', 'full_name' => $full_name, 'teacher_no' => $teacher_no,
                    'teaching_grades' => json_encode($teaching_grades), 'teaching_strands' => json_encode($teaching_strands),
                    'contact_number' => $contact_number,
                    'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)
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
                $errors[] = 'Unable to send the registration verification code. Please try again.';
                logError('Teacher registration email verification error: ' . $e->getMessage());
            }
        }
    }

    if ($registration_role !== 'teacher') {

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
        $password_errors = passwordPolicyErrors($password, false);
        if (!empty($password_errors)) {
            $errors[] = strongPasswordMessage($password_errors);
        }
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Password confirmation does not match.';
    }

    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare('SELECT student_id FROM students WHERE student_no = ? OR email = ? OR contact_number = ? LIMIT 1');
            if (!$check_stmt) {
                throw new RuntimeException('Unable to validate the registration right now.');
            }
            $check_stmt->bind_param('sss', $student_no, $email, $contact_number);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $errors[] = 'Student Number, Email, or Contact Number is already registered. Please use different information.';
            }
            $check_stmt->close();
            $crossCheck = $conn->prepare('SELECT teacher_id FROM teachers WHERE email = ? OR contact_number = ? LIMIT 1');
            $crossCheck->bind_param('ss', $email, $contact_number);
            $crossCheck->execute();
            if ($crossCheck->get_result()->num_rows > 0) $errors[] = 'Email or Contact Number is already registered to another user. Please use different information.';
            $crossCheck->close();
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--navy:#141F52;--blue:#52618D;--sky:#91B0E0;--light:#D2E2F6;--mist:#E7EEF7;--yellow:#F4F916;--white:#FEFEF9;--text:#202A44;--muted:#4A5780;--field:#C5D3EA;--serif:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,'Roboto',sans-serif;--sans:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,'Roboto',sans-serif}
        *{margin:0;padding:0;box-sizing:border-box}
        html{-webkit-text-size-adjust:100%}
        body{font-family:var(--sans);background:#0d1533;color:var(--text);min-height:100vh;min-height:100dvh;padding:32px 20px 48px;position:relative}
        .auth-bg{position:fixed;inset:0;z-index:0}
        .auth-bg img{width:100%;height:100%;object-fit:cover;object-position:center 45%;display:block}
        .auth-bg::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(13,21,51,.8),rgba(13,21,51,.66) 50%,rgba(13,21,51,.86))}
        .container{position:relative;z-index:1;max-width:860px;margin:0 auto}

        /* Header */
        .header{display:flex;align-items:center;justify-content:center;gap:14px;color:#fff;margin-bottom:22px;text-shadow:0 1px 12px rgba(8,14,40,.5)}
        .header img{width:52px;height:52px;border-radius:50%;object-fit:contain;background:var(--white);padding:3px;box-shadow:0 4px 14px rgba(0,0,0,.3)}
        .header-text{text-align:left}
        .header h1{font-family:var(--serif);font-size:26px;line-height:1.15;font-weight:700;letter-spacing:-.01em}
        .header p{font-size:13.5px;opacity:.92;margin-top:3px}

        /* Card */
        .registration-card{background:rgba(254,254,249,.96);border:1px solid rgba(255,255,255,.5);border-radius:18px;box-shadow:0 28px 70px rgba(8,14,40,.45),0 2px 8px rgba(8,14,40,.14);overflow:hidden}
        .card-header{background:var(--navy);color:#fff;padding:20px 32px;font-family:var(--serif);font-size:20px;font-weight:600;display:flex;align-items:center;gap:14px;border-bottom:4px solid var(--yellow)}
        .card-header svg{width:24px;height:24px;stroke:var(--yellow);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0}
        .card-body{padding:30px 32px 28px}

        /* Section titles */
        .section-title{display:flex;align-items:center;gap:10px;margin:28px 0 16px;font-family:var(--serif);font-size:16px;font-weight:700;color:var(--navy)}
        .section-title:first-of-type{margin-top:6px}
        .section-title::after{content:"";flex:1;height:1px;background:#DCE5F3}
        .section-title .num{width:24px;height:24px;border-radius:50%;background:var(--navy);color:#fff;font-family:var(--sans);font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}

        /* Alerts */
        .alert{padding:14px 16px;border-radius:12px;margin-bottom:22px;display:flex;align-items:flex-start;gap:12px;animation:slideDown .25s ease;font-size:14px;line-height:1.5;border:1px solid}
        @keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
        .alert-error{background:#FDF0E7;border-color:#EBBE9E;color:#7A3A0E}
        .alert-success{background:#EEF6E0;border-color:#BCD688;color:#344E15}
        .alert ul{margin:6px 0 0 18px;line-height:1.55}
        .alert li{margin-bottom:4px}
        .alert-icon{flex-shrink:0;margin-top:1px;width:20px;height:20px}
        .alert-icon svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;display:block}
        .alert-success{display:block}
        .alert-success .alert-icon{display:none}
        .success-content{text-align:center}
        .success-content h3{font-family:var(--serif);color:#344E15;margin-bottom:12px;font-size:22px}
        .success-content p{margin-bottom:10px;line-height:1.6}
        .success-qr{background:#fff;border:1px solid #DCE5F3;padding:20px;border-radius:14px;margin:20px auto;text-align:center;max-width:300px}
        .success-qr h4{color:var(--navy);margin-bottom:12px;font-size:12.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
        .success-qr img{max-width:180px;height:auto;border-radius:10px;box-shadow:0 4px 12px rgba(20,31,82,.18);background:#fff;padding:8px}
        .success-qr-code{margin-top:12px;font-size:12px;color:var(--navy);font-weight:600;font-family:'Courier New',monospace;letter-spacing:.5px}
        .qr-download-btn{display:inline-block;margin-top:14px;padding:10px 18px;background:var(--navy);color:#fff;text-decoration:none;border-radius:9px;font-size:13px;font-weight:700}
        .qr-download-btn:hover{background:var(--blue)}
        .success-actions{display:flex;gap:12px;margin-top:22px;justify-content:center;flex-wrap:wrap}
        .success-actions a{padding:13px 28px;background:linear-gradient(180deg,#23316E,var(--navy));color:#fff;text-decoration:none;border-radius:10px;font-size:14.5px;font-weight:700;box-shadow:0 8px 20px rgba(20,31,82,.3)}

        /* Role picker (segmented) */
        .registration-type-picker{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:6px;padding:10px 12px 10px 16px;background:#EEF3FA;border-radius:12px;color:var(--text);font-size:14px}
        .registration-type-picker strong{font-weight:600;color:var(--muted)}
        .seg{display:inline-flex;background:#fff;border:1.5px solid var(--field);border-radius:10px;padding:3px;gap:3px;margin-left:auto}
        .registration-type-btn{padding:8px 22px;border:0;border-radius:7px;background:transparent;color:var(--muted);font:inherit;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s,color .15s}
        .registration-type-btn:hover{color:var(--navy)}
        .registration-type-btn.active{background:var(--navy);color:#fff;box-shadow:0 2px 6px rgba(20,31,82,.3)}
        .registration-type-btn:focus-visible{outline:3px solid var(--sky);outline-offset:2px}

        /* Form */
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:18px 20px;margin-bottom:6px}
        .form-group{display:flex;flex-direction:column}
        .form-group label{display:block;font-size:13.5px;font-weight:600;color:var(--text);margin-bottom:7px}
        .required{color:#B4501A;margin-left:2px}
        .form-group input,.form-group select,.form-group textarea{width:100%;height:48px;padding:12px 14px;border:1.5px solid var(--field);border-radius:10px;font-size:16px;font-family:inherit;color:var(--text);background:#fff;transition:border-color .15s,box-shadow .15s}
        .form-group select{appearance:none;-webkit-appearance:none;padding-right:40px;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2352618D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}
        .form-group input::placeholder{color:#7783A6}
        .form-group input:hover,.form-group select:hover{border-color:var(--blue)}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:var(--navy);box-shadow:0 0 0 4px rgba(20,31,82,.16)}
        .form-group input:disabled{background:#EEF2F9;color:#5E6B92;cursor:not-allowed}
        .helper-text{font-size:12.5px;color:var(--muted);margin-top:6px;line-height:1.5}
        .live-check{display:block;width:100%;margin-top:6px;font-size:12.5px;line-height:1.4;font-weight:600}
        .live-check.ok{color:#2F6B1F}
        .live-check.bad{color:#9C3A0B}

        .checkbox-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;padding:12px;background:#fff;border:1.5px solid var(--field);border-radius:10px}
        .checkbox-grid label{margin:0;display:flex;align-items:center;gap:8px;padding:6px 8px;border-radius:7px;font-size:14px;font-weight:500;color:var(--text);cursor:pointer}
        .checkbox-grid label:hover{background:#F2F6FC}
        .checkbox-grid input{width:16px;height:16px;accent-color:var(--navy);margin:0;flex-shrink:0}
        .checkbox-group{grid-column:1/-1;margin-bottom:18px}

        /* Passwords */
        .password-field{position:relative;width:100%}
        .password-field>input[type="password"],.password-field>input[type="text"]{width:100%;padding-right:78px !important}
        .show-password-btn{position:absolute;top:24px;right:8px;transform:translateY(-50%) !important;min-width:60px !important;width:auto !important;height:34px !important;padding:0 10px !important;border:1px solid rgba(20,31,82,.2) !important;border-radius:7px !important;background:#F2F6FC !important;color:var(--navy) !important;box-shadow:none !important;font:inherit !important;font-size:12px !important;font-weight:600 !important;line-height:1 !important;cursor:pointer;z-index:2}
        .show-password-btn:hover{background:var(--mist) !important}
        .show-password-btn:focus-visible{outline:3px solid var(--sky);outline-offset:1px}
        .password-requirements{margin-top:10px;padding:10px 12px;border-radius:10px;background:#F2F6FC;font-size:12.5px;display:grid;grid-template-columns:1fr 1fr;gap:6px 12px}
        .password-requirements div{display:flex;align-items:center;gap:7px;color:#7A4A2A}
        .password-requirements div::before{content:"";width:14px;height:14px;border-radius:50%;border:2px solid currentColor;flex-shrink:0;opacity:.7}
        .password-requirements div.valid{color:#2F6B1F}
        .password-requirements div.valid::before{background:#2F6B1F url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12l5 5L20 7'/%3E%3C/svg%3E") center/80% no-repeat;border-color:#2F6B1F;opacity:1}

        /* Actions */
        .form-actions{display:flex;gap:12px;margin-top:28px;justify-content:flex-end;flex-wrap:wrap}
        .btn-submit{padding:0 32px;height:50px;background:linear-gradient(180deg,#23316E,var(--navy));color:#fff;border:0;border-radius:10px;font:inherit;font-size:15.5px;font-weight:700;cursor:pointer;box-shadow:0 8px 20px rgba(20,31,82,.32),inset 0 1px 0 rgba(255,255,255,.16);transition:background .15s,box-shadow .15s,transform .12s}
        .btn-submit:hover{background:linear-gradient(180deg,#2B3A80,#1B2864);box-shadow:0 12px 26px rgba(20,31,82,.4)}
        .btn-submit:active{transform:translateY(1px)}
        .btn-submit:focus-visible,.btn-back:focus-visible{outline:3px solid var(--sky);outline-offset:3px}
        .btn-back{padding:0 28px;height:50px;display:inline-flex;align-items:center;justify-content:center;background:transparent;color:var(--navy);border:1.5px solid rgba(20,31,82,.4);border-radius:10px;font:inherit;font-size:15.5px;font-weight:700;text-decoration:none;transition:background .15s,border-color .15s}
        .btn-back:hover{background:rgba(20,31,82,.06);border-color:var(--navy)}
        .form-actions .btn-back{order:-1}

        .form-footer{text-align:center;margin-top:24px;padding-top:18px;border-top:1px solid #DCE5F3;color:var(--muted);font-size:14px}
        .form-footer a{color:var(--navy);text-decoration:none;font-weight:700}
        .form-footer a:hover{text-decoration:underline}

        /* Verification modal */
        .verification-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(8,14,40,.7);backdrop-filter:blur(3px);z-index:3000}
        .verification-modal.show{display:flex}
        .verification-card{width:100%;max-width:440px;background:var(--white);border-radius:18px;overflow:hidden;box-shadow:0 28px 70px rgba(0,0,0,.4)}
        .verification-header{background:var(--navy);color:#fff;padding:20px 24px;border-bottom:4px solid var(--yellow)}
        .verification-header h3{margin:0;font-family:var(--serif);font-size:21px}
        .verification-body{padding:24px}
        .verification-info{background:#EEF3FA;color:var(--muted);border-radius:10px;padding:12px 14px;margin-bottom:18px;font-size:13.5px;line-height:1.55}
        .verification-info strong{color:var(--navy)}
        .verification-error{display:none;background:#FDF0E7;border:1px solid #EBBE9E;color:#7A3A0E;border-radius:10px;padding:11px 13px;margin-bottom:14px;font-size:13.5px}
        .verification-error.show{display:block}
        #registrationVerificationCode{height:60px;font-size:28px;font-weight:700;letter-spacing:12px;text-align:center;padding-left:26px}
        .verification-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
        .verification-actions button{height:48px;padding:0 16px;border:0;border-radius:10px;font:inherit;font-weight:700;font-size:14.5px;cursor:pointer}
        .verification-cancel{background:transparent;color:var(--navy);border:1.5px solid rgba(20,31,82,.4) !important}
        .verification-cancel:hover{background:rgba(20,31,82,.06)}
        .verification-submit{background:linear-gradient(180deg,#23316E,var(--navy));color:#fff;box-shadow:0 6px 16px rgba(20,31,82,.3)}
        .verification-submit:disabled{background:var(--blue);cursor:progress}
        .verification-resend{display:block;margin:16px auto 0;border:0;background:transparent;color:var(--navy);font:inherit;font-size:13.5px;font-weight:700;text-decoration:underline;cursor:pointer}
        .verification-resend:disabled{opacity:.55;cursor:not-allowed}

        @media(max-width:768px){.card-body{padding:24px 20px}.card-header{padding:18px 20px}.form-grid{grid-template-columns:1fr;gap:16px}}
        @media(max-width:520px){
            body{padding:20px 14px 36px}
            .header h1{font-size:21px}.header p{font-size:12.5px}.header img{width:44px;height:44px}
            .registration-type-picker{flex-direction:column;align-items:stretch;gap:10px}
            .seg{margin-left:0;display:grid;grid-template-columns:1fr 1fr}
            .form-actions{flex-direction:column}.form-actions .btn-back{order:0}
            .btn-submit,.btn-back{width:100%}
            .password-requirements{grid-template-columns:1fr}
            .verification-actions{grid-template-columns:1fr}
            #registrationVerificationCode{font-size:24px;letter-spacing:9px}
            .show-password-btn{min-width:56px !important;right:6px}
        }
        @media(prefers-reduced-motion:reduce){*{animation-duration:.001ms !important;transition-duration:.001ms !important}}
</style>
    <?php require_once __DIR__ . '/../includes/responsive.php'; ?>
</head>
<body class="student-register-page">
    <?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
    <?php if ($success && ($new_student_no || $new_teacher_no)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                showToast(<?php echo json_encode(($registration_success_role === 'teacher' ? 'Teacher' : 'Student') . ' registration completed. Your account is ready. Your Library Access Card was sent to your registered email address.'); ?>, 'success', 4200, 'Registration Successful');
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
    <div class="auth-bg"><img src="/LibraryBorrowingSystem/Img/library1.png" alt="" decoding="async"></div>
    <div class="container">
        <div class="header">
            <img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School logo">
            <div class="header-text">
                <h1>Create an account</h1>
                <p>Jose Abad Santos High School Library. Register as a student or teacher.</p>
            </div>
        </div>

        <div class="registration-card">
            <div class="card-header">
                <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/></svg>
                <span><?php if ($success && $registration_success_role === 'teacher'): ?>Teacher Registration Complete<?php else: ?>Create Your <span id="accountTypeLabel"><?php echo $registration_success_role === 'teacher' ? 'Teacher' : 'Student'; ?></span> Account<?php endif; ?></span>
            </div>

            <div class="card-body">
                <?php if ($success && ($new_student_no || $new_teacher_no)): ?>
                    <!-- Success Message -->
                    <div class="alert alert-success<?php echo $registration_success_role === 'teacher' ? ' teacher-success' : ''; ?>">
                        <div class="alert-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></svg></div>
                        <div class="success-content">
                            <h3>Registration Successful!</h3>
                            <?php if ($registration_success_role === 'teacher'): ?>
                                <p>Your teacher account has been created.</p>
                                <p><strong>Teacher ID Number:</strong> <?php echo htmlspecialchars($new_teacher_no); ?></p>
                            <?php else: ?>
                                <p>Your student account has been created. You can now access the student portal.</p>
                                <p><strong>Student ID:</strong> <?php echo str_pad($new_student_no, 4, '0', STR_PAD_LEFT); ?></p>
                            <?php endif; ?>
                            
                            <?php if ($registration_success_role === 'student'): ?><div class="success-qr">
                                <h4>Your Student QR Code</h4>
                                <button type="button" class="qr-zoomable" data-qr-zoom data-qr-code="<?php echo htmlspecialchars($generated_qr, ENT_QUOTES, 'UTF-8'); ?>" data-qr-title="My Library ID" data-qr-sub="<?php echo htmlspecialchars($registration_full_name ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-qr-download="/LibraryBorrowingSystem/student/download_registration_card.php?format=jpg" data-qr-print="/LibraryBorrowingSystem/print_library_card.php?type=student" data-qr-card="1" data-qr-card-name="<?php echo htmlspecialchars($registration_full_name ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-qr-card-id="<?php echo htmlspecialchars($registration_card_id ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-qr-card-image="/LibraryBorrowingSystem/qr_codes/<?php echo rawurlencode($generated_qr); ?>.png" data-qr-card-logo="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" aria-label="Enlarge your student QR code"><img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=<?php echo urlencode($generated_qr); ?>" alt="Student QR Code"><span class="qr-zoom-hint" aria-hidden="true">⤢</span></button>
                                <div class="success-qr-code"><?php echo htmlspecialchars($generated_qr); ?></div>
                            </div><?php endif; ?>
                            <?php if ($registration_success_role === 'teacher'): ?><div class="success-qr">
                                <h4>Your Teacher QR Code</h4>
                                <button type="button" class="qr-zoomable" data-qr-zoom data-qr-code="<?php echo htmlspecialchars($new_teacher_qr, ENT_QUOTES, 'UTF-8'); ?>" data-qr-title="My Library ID" data-qr-sub="Teacher account" data-qr-download="/LibraryBorrowingSystem/student/download_registration_card.php?format=jpg" data-qr-print="/LibraryBorrowingSystem/print_library_card.php?type=teacher" data-qr-card="1" data-qr-card-name="<?php echo htmlspecialchars($registration_full_name ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-qr-card-id="<?php echo htmlspecialchars($registration_card_id ?? '', ENT_QUOTES, 'UTF-8'); ?>" data-qr-card-image="/LibraryBorrowingSystem/qr_codes/<?php echo rawurlencode($new_teacher_qr); ?>.png" data-qr-card-logo="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" aria-label="Enlarge your teacher QR code"><img src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=<?php echo urlencode($new_teacher_qr); ?>" alt="Teacher QR Code"><span class="qr-zoom-hint" aria-hidden="true">⤢</span></button>
                                <div class="success-qr-code"><?php echo htmlspecialchars($new_teacher_qr); ?></div>
                            </div><?php endif; ?>

                            <div style="max-width:620px;margin:18px auto 0;padding:16px 18px;border:1px solid <?php echo $registration_email_sent ? '#BCD688' : '#E3B38F'; ?>;border-radius:12px;background:<?php echo $registration_email_sent ? '#F7FBEF' : '#FFF6EF'; ?>;color:<?php echo $registration_email_sent ? '#344E15' : '#7A3A0E'; ?>;text-align:left;">
                                <strong style="display:block;margin-bottom:6px;">
                                    <?php echo $registration_email_sent ? 'Library Access Card sent successfully' : 'Account created, but the Library Access Card could not be emailed'; ?>
                                </strong>
                                <span style="font-size:13px;line-height:1.55;">
                                    <?php if ($registration_email_sent): ?>
                                        Your Library Access Card was sent to <strong><?php echo htmlspecialchars($successData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong>. Check your Gmail inbox and Spam/Junk folder.
                                    <?php else: ?>
                                        Your account was created after successful email verification, but the card email could not be delivered. Please check the Gmail configuration.
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="success-actions">
                                <a href="/LibraryBorrowingSystem/student/portal.php">Go to Login</a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg></div>
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
                        <div class="registration-type-picker">
                            <strong>I am registering as a</strong>
                            <div class="seg" role="group" aria-label="Account type">
                                <button type="button" class="registration-type-btn active" data-registration-role="student">Student</button>
                                <button type="button" class="registration-type-btn" data-registration-role="teacher">Teacher</button>
                            </div>
                        </div>
                        <input type="hidden" name="registration_role" id="registration_role" value="<?php echo htmlspecialchars($_POST['registration_role'] ?? 'student'); ?>">
                        <div id="studentFields">
                        <!-- Personal Information Section -->
                        <h3 class="section-title"><span class="num">1</span>Personal Information</h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="full_name" placeholder="Enter your full name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                                <div class="helper-text">Your complete legal name</div>
                            </div>

                            <div class="form-group">
                                <label>Student Number <span class="required">*</span></label>
                                <input type="text" name="student_no" placeholder="e.g., 136412345678" 
                                       value="<?php echo htmlspecialchars($_POST['student_no'] ?? ''); ?>" required>
                                <div class="helper-text">Your institutional student number</div>
                            </div>

                            <div class="form-group">
                                <label>Section <span class="required">*</span></label>
                                <input type="text" name="student_group" placeholder="e.g., 10 - Rizal" 
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

                        </div>

                        <div id="teacherFields" style="display:none;">
                            <h3 class="section-title"><span class="num">1</span>Teacher Personal Information</h3>
                            <div class="form-grid">
                                <div class="form-group"><label>Full Name <span class="required">*</span></label><input type="text" name="full_name" id="teacher_full_name" placeholder="Enter your full name" value="<?php echo htmlspecialchars(($_POST['registration_role'] ?? '') === 'teacher' ? ($_POST['full_name'] ?? '') : ''); ?>"></div>
                                <div class="form-group"><label>Teacher ID Number <span class="required">*</span></label><input type="text" name="teacher_no" id="teacher_no" placeholder="e.g., 136412345678" value="<?php echo htmlspecialchars($_POST['teacher_no'] ?? ''); ?>"></div>
                            </div>
                            <div class="form-group checkbox-group"><label>Grade Levels Teaching <span class="required">*</span></label><div class="checkbox-grid">
                                <?php foreach (['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'] as $grade): ?><label><input type="checkbox" name="teaching_grades[]" value="<?php echo $grade; ?>" <?php echo in_array($grade, (array)($_POST['teaching_grades'] ?? []), true) ? 'checked' : ''; ?>> <?php echo $grade; ?></label><?php endforeach; ?>
                            </div></div>
                            <div class="form-group checkbox-group" id="teacherStrandsGroup"><label>Senior High School Strand(s)</label><div class="checkbox-grid">
                                <?php foreach (['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL', 'Arts and Design', 'Sports'] as $strand): ?><label><input type="checkbox" name="teaching_strands[]" value="<?php echo $strand; ?>" <?php echo in_array($strand, (array)($_POST['teaching_strands'] ?? []), true) ? 'checked' : ''; ?>> <?php echo $strand; ?></label><?php endforeach; ?>
                            </div><div class="helper-text">Select strands only when teaching Grade 11 or Grade 12.</div></div>
                        </div>

                        <!-- Contact Information Section -->
                        <h3 class="section-title"><span class="num">2</span>Contact Information</h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Contact Number</label>
                                <input type="tel" name="contact_number" placeholder="e.g., +63 945 735 2866" 
                                       value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" >
                                <div class="live-check" id="contactLiveCheck"></div>                                <div class="helper-text">Your mobile or phone number</div>
                            </div>

                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" placeholder="e.g., student@example.com" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                <div class="live-check" id="emailLiveCheck"></div>                                <div class="helper-text">A 6-digit verification code will be sent here. After verification, your Library Access Card will be emailed here.</div>
                            </div>

                            <div class="form-group">
                                <label>Library Card Valid Until</label>
                                <input type="text" disabled placeholder="Automatically set to 1 year from today" 
                                       value="<?php echo date('F d, Y', strtotime('+1 year')); ?>">
                                <div class="helper-text">Your library card validity will be set to 1 year from today</div>
                            </div>
                        </div>

                        <h3 class="section-title"><span class="num">3</span>Account Security</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Password <span class="required">*</span></label>
                                <div class="password-field">
                                    <input type="password" name="password" maxlength="128" autocomplete="new-password" required>
                                    <button type="button" class="show-password-btn" aria-pressed="false" aria-label="Show password">Show</button>
                                </div>
                                <div class="password-requirements" id="passwordRequirements">
                                    <div class="invalid" data-rule="length">Minimum 10 characters</div>
                                    <div class="invalid" data-rule="upper">Uppercase letter (A-Z)</div>
                                    <div class="invalid" data-rule="lower">Lowercase letter (a-z)</div>
                                    <div class="invalid" data-rule="number">Number (0-9)</div>
                                </div>
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
                            Already have an account? <a href="/LibraryBorrowingSystem/student/portal.php">Go back to Login.</a>
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
        const roleInput = document.getElementById('registration_role');
        const studentFields = document.getElementById('studentFields');
        const teacherFields = document.getElementById('teacherFields');
        const accountTypeLabel = document.getElementById('accountTypeLabel');
        const roleButtons = document.querySelectorAll('[data-registration-role]');

        function setFieldState(container, enabled) {
            if (!container) return;
            container.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !enabled;
                if (field.dataset.originalRequired === undefined) field.dataset.originalRequired = field.required ? '1' : '0';
                field.required = enabled && field.dataset.originalRequired === '1';
            });
        }

        function toggleRegistrationRole(role) {
            const isTeacher = role === 'teacher';
            if (roleInput) roleInput.value = isTeacher ? 'teacher' : 'student';
            if (studentFields) studentFields.style.display = isTeacher ? 'none' : 'block';
            if (teacherFields) teacherFields.style.display = isTeacher ? 'block' : 'none';
            if (accountTypeLabel) accountTypeLabel.textContent = isTeacher ? 'Teacher' : 'Student';
            setFieldState(studentFields, !isTeacher);
            setFieldState(teacherFields, isTeacher);
            const contactNumber = document.querySelector('[name="contact_number"]');
            if (contactNumber) {
                contactNumber.disabled = false;
                contactNumber.required = true;
            }
            roleButtons.forEach(function (button) { button.classList.toggle('active', button.dataset.registrationRole === (isTeacher ? 'teacher' : 'student')); });
        }

        roleButtons.forEach(function (button) { button.addEventListener('click', function () { toggleRegistrationRole(button.dataset.registrationRole); }); });
        toggleRegistrationRole(roleInput && roleInput.value === 'teacher' ? 'teacher' : 'student');

        const gradeSelect = document.getElementById('year_level');
        const departmentGroup = document.getElementById('department_group');
        const departmentSelect = document.getElementById('department');
        const teacherStrandsGroup = document.getElementById('teacherStrandsGroup');
        const teacherGradeCheckboxes = document.querySelectorAll('input[name="teaching_grades[]"]');
        const teacherStrandCheckboxes = document.querySelectorAll('input[name="teaching_strands[]"]');

        function toggleTeacherStrands() {
            const teachesSeniorHigh = Array.from(teacherGradeCheckboxes).some(function (checkbox) {
                return checkbox.checked && (checkbox.value === 'Grade 11' || checkbox.value === 'Grade 12');
            });
            if (teacherStrandsGroup) teacherStrandsGroup.style.display = teachesSeniorHigh ? 'flex' : 'none';
            teacherStrandCheckboxes.forEach(function (checkbox) {
                checkbox.disabled = !teachesSeniorHigh;
                if (!teachesSeniorHigh) checkbox.checked = false;
            });
        }

        teacherGradeCheckboxes.forEach(function (checkbox) { checkbox.addEventListener('change', toggleTeacherStrands); });
        toggleTeacherStrands();

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


<script>
document.addEventListener('DOMContentLoaded', function(){
    function addStatus(input, ok, msg){
        // Always reuse one validation message per field. Prevent duplicate loops.
        let container = input.closest('.form-group') || input.parentElement;
        let el = container.querySelector(':scope > .live-check');

        if(!el){
            el=document.createElement('small');
            el.className='live-check';
            container.appendChild(el);
        }

        el.textContent = msg;
        el.classList.toggle('ok', !!ok);
        el.classList.toggle('bad', !ok);
    }
    async function check(type,input){
        let v=input.value.trim();
        if(!v) return;
        let fd=new FormData();
        fd.append('registration_check',type);
        fd.append('value',v);
        let r=await fetch(location.href,{method:'POST',body:fd});
        let d=await r.json();
        addStatus(input,d.available,d.available?d.message:d.message);
        input.dataset.available=d.available?'1':'0';
    }
    let email=document.querySelector('[name="email"]');
    let contact=document.querySelector('[name="contact_number"]');
    if(email) email.addEventListener('input',()=>check('email',email));
    if(contact) contact.addEventListener('input',()=>check('contact',contact));

    let pass=document.querySelector('[name="password"]');
    let confirm=document.querySelector('[name="confirm_password"]');
    if(pass){
      function validate(){
        let p=pass.value;
        let checks={
          length:p.length>=10,
          upper:/[A-Z]/.test(p),
          lower:/[a-z]/.test(p),
          number:/[0-9]/.test(p)
        };
        Object.keys(checks).forEach(function(key){
          let item=document.querySelector('#passwordRequirements [data-rule="'+key+'"]');
          if(item){
            item.className=checks[key]?'valid':'invalid';
          }
        });
        pass.dataset.valid=Object.values(checks).every(Boolean)?'1':'0';
      }
      pass.addEventListener('input',validate);
      if(confirm) confirm.addEventListener('input',function(){
        addStatus(confirm,confirm.value===pass.value && confirm.value!=='',confirm.value===pass.value?'Passwords match':'Passwords do not match');
      });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/qr_lightbox.php'; ?>
</body>
</html>
