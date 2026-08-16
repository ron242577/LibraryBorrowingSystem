<?php
require_once __DIR__ . '/../db.php';
startSecureSession();

if (!isset($_SESSION['student_id'])) {
    header('Location: /LibraryBorrowingSystem/student/portal.php');
    exit();
}

$now = time();
$idleTimeout = 30 * 60;
$absoluteTimeout = 4 * 60 * 60;

$fingerprint = createSessionFingerprint();
if (empty($_SESSION['student_session_fingerprint']) || !hash_equals($_SESSION['student_session_fingerprint'], $fingerprint)) {
    unset($_SESSION['student_id'], $_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_qr'], $_SESSION['student_login_time'], $_SESSION['student_login_started_at'], $_SESSION['student_session_fingerprint']);
    header('Location: /LibraryBorrowingSystem/student/portal.php?expired=1');
    exit();
}

$idleExpired = isset($_SESSION['student_login_time']) && ($now - (int)$_SESSION['student_login_time']) > $idleTimeout;
$absoluteExpired = isset($_SESSION['student_login_started_at']) && ($now - (int)$_SESSION['student_login_started_at']) > $absoluteTimeout;
if ($idleExpired || $absoluteExpired) {
    unset($_SESSION['student_id'], $_SESSION['student_no'], $_SESSION['student_name'], $_SESSION['student_qr'], $_SESSION['student_login_time'], $_SESSION['student_login_started_at'], $_SESSION['student_session_fingerprint']);
    header('Location: /LibraryBorrowingSystem/student/portal.php?expired=1');
    exit();
}

$_SESSION['student_login_time'] = $now;
