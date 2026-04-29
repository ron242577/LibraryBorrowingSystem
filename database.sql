-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 29, 2026 at 06:20 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `library_borrowing_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `book_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `qr_code` varchar(255) NOT NULL,
  `book_status` enum('available','out_of_stock','damaged','lost') NOT NULL DEFAULT 'available',
  `total_copies` int(11) DEFAULT 1 COMMENT 'Total number of copies of this book in the library',
  `available_copies` int(11) DEFAULT 1 COMMENT 'Number of copies currently available for borrowing',
  `borrowed_copies` int(11) DEFAULT 0 COMMENT 'Number of copies currently borrowed',
  `lost_copies` int(11) DEFAULT 0 COMMENT 'Number of copies that are lost or damaged',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`book_id`, `title`, `author`, `qr_code`, `book_status`, `total_copies`, `available_copies`, `borrowed_copies`, `lost_copies`, `created_at`, `updated_at`) VALUES
(3, '1984', 'George Orwell', 'BOOK-QR-003', 'available', 4, 0, 3, 1, '2026-04-24 08:51:11', '2026-04-29 02:57:40'),
(5, 'asdas', 'sir jayr', 'BOOK-20260427-ZHAWV', 'available', 6, 5, 1, 0, '2026-04-27 03:53:14', '2026-04-29 02:35:35'),
(6, 'asdas', 'sir jayr', 'BOOK-20260427-HIJCT', 'available', 1, 1, 0, 0, '2026-04-27 03:53:15', '2026-04-27 03:53:15');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `full_name`, `contact_number`, `qr_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 'John Doe', '9876543210', 'STU-QR-001', 'active', '2026-04-24 08:51:11', '2026-04-24 08:51:11'),
(2, 'Jane Smith', '9876543211', 'STU-QR-002', 'active', '2026-04-24 08:51:11', '2026-04-24 08:51:11'),
(3, 'Michael Johnson', '9876543212', 'STU-QR-003', 'active', '2026-04-24 08:51:11', '2026-04-24 08:51:11'),
(4, 'Marcus Dominique Muico', '09457352866', 'STU-20260424-IPDXQ', 'active', '2026-04-24 10:02:37', '2026-04-24 10:02:37'),
(5, 'Divine Abanador', '09121231234', 'STU-20260428-BAW0U', 'active', '2026-04-28 13:31:46', '2026-04-28 13:31:46'),
(6, 'Tracy Caryll Alamo', '09211231234', 'STU-20260428-ZTPU8', 'active', '2026-04-28 13:32:24', '2026-04-28 13:32:24'),
(7, 'Arron Perlas', '09131231234', 'STU-20260428-YXGZV', 'active', '2026-04-28 13:33:33', '2026-04-28 13:33:33');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `date_borrowed` datetime NOT NULL,
  `due_date` datetime NOT NULL,
  `return_date` datetime DEFAULT NULL,
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `status` enum('borrowed','returned','overdue') NOT NULL DEFAULT 'borrowed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `student_id`, `book_id`, `date_borrowed`, `due_date`, `return_date`, `penalty_amount`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 3, '2026-04-24 16:51:11', '2026-05-08 16:51:11', NULL, 0.00, 'borrowed', '2026-04-24 08:51:11', '2026-04-24 08:51:11'),
(2, 4, 5, '2026-04-29 04:35:35', '2026-05-13 04:35:35', NULL, 0.00, 'borrowed', '2026-04-29 02:35:35', '2026-04-29 02:35:35');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Hashed password',
  `role` enum('super_admin','librarian') NOT NULL DEFAULT 'librarian',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Super Administrator', 'admin', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'super_admin', 'active', '2026-04-24 08:51:11', '2026-04-24 08:51:11'),
(2, 'Librarian User', 'librarian', 'ab8e89c55367f55a2f933b8dc8a9994d61f997df2b402274eb943fa22d77394a', 'librarian', 'active', '2026-04-24 08:51:11', '2026-04-24 08:51:11');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `idx_qr_code` (`qr_code`),
  ADD KEY `idx_book_status` (`book_status`),
  ADD KEY `idx_title` (`title`),
  ADD KEY `idx_available_copies` (`available_copies`),
  ADD KEY `idx_borrowed_copies` (`borrowed_copies`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD KEY `idx_qr_code` (`qr_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_book_id` (`book_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_date_borrowed` (`date_borrowed`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
