<?php
/**
 * Student Portal compatibility redirect.
 * Student and Admin now use the same unified login page.
 */
if (isset($_GET['logout'])) {
    header('Location: /LibraryBorrowingSystem/logout.php');
    exit();
}
if (isset($_GET['expired'])) {
    header('Location: /LibraryBorrowingSystem/login.php?expired=1');
    exit();
}
header('Location: /LibraryBorrowingSystem/login.php');
exit();
