<?php
require_once __DIR__ . '/../db.php';
startSecureSession();

if (!isset($_SESSION['teacher_id'])) {
    header('Location: /LibraryBorrowingSystem/teacher/portal.php');
    exit();
}

$now = time();
$idleTimeout = 30 * 60;
$absoluteTimeout = 4 * 60 * 60;

$fingerprint = createSessionFingerprint();
if (empty($_SESSION['teacher_session_fingerprint']) || !hash_equals($_SESSION['teacher_session_fingerprint'], $fingerprint)) {
    unset($_SESSION['teacher_id'], $_SESSION['teacher_no'], $_SESSION['teacher_name'], $_SESSION['teacher_qr'], $_SESSION['teacher_login_time'], $_SESSION['teacher_login_started_at'], $_SESSION['teacher_session_fingerprint']);
    header('Location: /LibraryBorrowingSystem/teacher/portal.php?expired=1');
    exit();
}

$idleExpired = isset($_SESSION['teacher_login_time']) && ($now - (int)$_SESSION['teacher_login_time']) > $idleTimeout;
$absoluteExpired = isset($_SESSION['teacher_login_started_at']) && ($now - (int)$_SESSION['teacher_login_started_at']) > $absoluteTimeout;
if ($idleExpired || $absoluteExpired) {
    unset($_SESSION['teacher_id'], $_SESSION['teacher_no'], $_SESSION['teacher_name'], $_SESSION['teacher_qr'], $_SESSION['teacher_login_time'], $_SESSION['teacher_login_started_at'], $_SESSION['teacher_session_fingerprint']);
    header('Location: /LibraryBorrowingSystem/teacher/portal.php?expired=1');
    exit();
}

$_SESSION['teacher_login_time'] = $now;
