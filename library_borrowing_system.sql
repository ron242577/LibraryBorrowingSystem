-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 16, 2026 at 06:04 PM
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
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `audit_log_id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(120) NOT NULL,
  `module` varchar(120) NOT NULL,
  `description` varchar(500) NOT NULL,
  `status` enum('info','success','failure','attempt') NOT NULL DEFAULT 'info',
  `actor_type` enum('admin','student','guest','system') NOT NULL DEFAULT 'guest',
  `actor_id` int(11) DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `target_type` varchar(80) DEFAULT NULL,
  `target_id` varchar(120) DEFAULT NULL,
  `request_method` varchar(10) NOT NULL,
  `request_uri` varchar(500) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `metadata` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `book_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `co_authors` text DEFAULT NULL,
  `place_of_publication` varchar(255) DEFAULT NULL,
  `publication_date` date DEFAULT NULL,
  `book_number` varchar(100) NOT NULL,
  `book_pages` int(11) NOT NULL,
  `source_of_funds` varchar(255) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `publisher` varchar(255) DEFAULT NULL,
  `edition` varchar(100) DEFAULT NULL,
  `volumes` varchar(100) DEFAULT NULL,
  `class` varchar(100) DEFAULT NULL,
  `type_of_material` varchar(100) DEFAULT NULL,
  `location_collection` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `book_status` enum('available','out_of_stock','damaged','lost') NOT NULL DEFAULT 'available',
  `total_copies` int(11) NOT NULL DEFAULT 1,
  `available_copies` int(11) NOT NULL DEFAULT 1,
  `borrowed_copies` int(11) NOT NULL DEFAULT 0,
  `lost_copies` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`book_id`, `title`, `author`, `co_authors`, `place_of_publication`, `publication_date`, `book_number`, `book_pages`, `source_of_funds`, `cost_price`, `publisher`, `edition`, `volumes`, `class`, `type_of_material`, `location_collection`, `qr_code`, `book_status`, `total_copies`, `available_copies`, `borrowed_copies`, `lost_copies`, `created_at`, `updated_at`) VALUES
(1, 'Noli Me Tangere', 'Jose Rizal', '', 'Manila', '1887-01-01', 'BOOK-0001', 480, 'School MOOE', 350.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Filipino Literature', 'Book', 'Filipiniana Section', 'BOOK-20260505-P5ADS', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(2, 'El Filibusterismo', 'Jose Rizal', '', 'Ghent', '1891-01-01', 'BOOK-0002', 368, 'School MOOE', 325.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Filipino Literature', 'Book', 'Filipiniana Section', 'BOOK-20260816-B0002', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 15:52:43'),
(3, 'Ibong Adarna', 'Anonymous', '', 'Manila', '1941-01-01', 'BOOK-0003', 240, 'School MOOE', 195.00, 'Sample Educational Publisher', 'Student Edition', '1', 'Filipino', 'Book', 'Filipino Section', 'BOOK-20260816-B0003', 'available', 8, 8, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(4, 'Florante at Laura', 'Francisco Balagtas', '', 'Manila', '1838-01-01', 'BOOK-0004', 192, 'School MOOE', 180.00, 'Sample Educational Publisher', 'Student Edition', '1', 'Filipino Literature', 'Book', 'Filipino Section', 'BOOK-20260816-B0004', 'available', 8, 8, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(5, 'Philippine History and Government', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0005', 352, 'DepEd Learning Resources Fund', 420.00, 'Sample Educational Publisher', '2024 Edition', '1', 'Araling Panlipunan', 'Textbook', 'AP Section', 'BOOK-20260816-B0005', 'available', 6, 6, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(6, 'Mathematics 7', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0006', 320, 'DepEd Learning Resources Fund', 480.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Mathematics', 'Textbook', 'Mathematics Section', 'BOOK-20260816-B0006', 'available', 12, 12, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(7, 'Mathematics 8', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0007', 336, 'DepEd Learning Resources Fund', 495.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Mathematics', 'Textbook', 'Mathematics Section', 'BOOK-20260816-B0007', 'available', 12, 12, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(8, 'Science 9', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0008', 344, 'DepEd Learning Resources Fund', 525.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Science', 'Textbook', 'Science Section', 'BOOK-20260816-B0008', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(9, 'Science 10', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0009', 360, 'DepEd Learning Resources Fund', 540.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'Science', 'Textbook', 'Science Section', 'BOOK-20260816-B0009', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(10, 'General Mathematics', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0010', 320, 'School MOOE', 550.00, 'Sample Educational Publisher', '2nd Edition', '1', 'Senior High School Mathematics', 'Textbook', 'Senior High School Section', 'BOOK-20260816-B0010', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(11, 'Earth and Life Science', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0011', 340, 'School MOOE', 625.00, 'Sample Educational Publisher', '2nd Edition', '1', 'STEM', 'Textbook', 'STEM Section', 'BOOK-20260816-B0011', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(12, 'Practical Research 1', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0012', 290, 'School MOOE', 525.00, 'Sample Educational Publisher', '2nd Edition', '1', 'Research', 'Textbook', 'Senior High School Section', 'BOOK-20260816-B0012', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(13, 'Practical Research 2', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0013', 304, 'School MOOE', 535.00, 'Sample Educational Publisher', '2nd Edition', '1', 'Research', 'Textbook', 'Senior High School Section', 'BOOK-20260816-B0013', 'available', 10, 10, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(14, 'Understanding Culture, Society and Politics', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0014', 320, 'Library Fund', 575.00, 'Sample Educational Publisher', 'Revised Edition', '1', 'HUMSS', 'Textbook', 'HUMSS Section', 'BOOK-20260816-B0014', 'available', 8, 8, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00'),
(15, 'Fundamentals of Accountancy, Business and Management 1', 'Various Authors', '', 'Quezon City', '2024-01-01', 'BOOK-0015', 350, 'Library Fund', 645.00, 'Sample Educational Publisher', '2nd Edition', '1', 'ABM', 'Textbook', 'ABM Section', 'BOOK-20260816-B0015', 'available', 8, 8, 0, 0, '2026-08-16 14:23:00', '2026-08-16 14:23:00');

-- --------------------------------------------------------

--
-- Table structure for table `login_security`
--

CREATE TABLE `login_security` (
  `login_security_id` bigint(20) UNSIGNED NOT NULL,
  `context` varchar(30) NOT NULL,
  `identifier_hash` char(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `first_failed_at` datetime NOT NULL,
  `last_failed_at` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `student_no` varchar(50) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `student_group` varchar(100) DEFAULT NULL,
  `department` varchar(150) DEFAULT NULL,
  `year_level` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `card_valid_until` date DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `student_no`, `full_name`, `student_group`, `department`, `year_level`, `contact_number`, `card_valid_until`, `email`, `qr_code`, `password`, `status`, `created_at`, `updated_at`) VALUES
(1, '23-01446', 'Marcus Dominique Muico', '10-A', NULL, 'Grade 10', '09457352866', '2026-12-05', 'marcusmuico70@gmail.com', 'STU-20260505-Q1ESG', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-05 07:18:57', '2026-05-11 08:08:09'),
(4, '23-01557', 'Tracy Caryll Alamo', '11-A', 'STEM', 'Grade 11', '09123456789', '2026-12-11', 'tracy@gmail.com', 'STU-20260511-LZN4R', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-10 17:12:07', '2026-05-10 17:12:07'),
(5, '23-12345', 'Divine Abanador', '12-A', 'HUMSS', 'Grade 12', '09131231234', '2027-05-11', 'divine@gmail.com', 'STU-20260511-2E615', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-11 06:29:05', '2026-05-11 06:29:05'),
(6, '23-04321', 'Ma. Dhanicka Daniela Arevalo', '9-A', NULL, 'Grade 9', '09211231234', '2027-05-11', 'makimuico@gmail.com', 'STU-20260511-08ED0', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-11 06:37:40', '2026-05-11 06:37:40'),
(7, '23-57463', 'Ysabel Anika Muico', '11-B', 'ABM', 'Grade 11', '09123456781', '2027-05-11', 'anikamuico@gmail.com', 'STU-20260511-FBCE6', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-11 06:39:49', '2026-05-11 06:39:49'),
(8, '23-63463', 'Lianne Grace Muico', '7-A', NULL, 'Grade 7', '09876543211', '2027-05-11', 'lianneanikamuico@gmail.com', 'STU-20260511-6B428', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-11 06:42:41', '2026-05-11 06:42:41'),
(9, '23-63421', 'Mico Borca', '12-B', 'TVL', 'Grade 12', '09211231245', '2027-05-11', 'micoborca8@gmail.com', 'STU-20260511-E255F', '$2y$12$qW3IOzsJxZ9dC/UpHfUrFe22FsygsbKmGcUOJrR4OA51UORDHBoc2', 'active', '2026-05-11 06:43:36', '2026-05-11 06:43:36'),
(10, '23-32674', 'Joel Malupiton', 'Rizal', '', 'Grade 7', '09211232333', '2027-08-16', 'burgersayo@gmail.com', 'STU-20260816-EE8626', '$2y$10$f5GO3HWs7vNZ5H.QqJ5x1.5xkUMpGB3tH8DCksUZsvyB1bK6f2VMO', 'active', '2026-08-16 15:40:15', '2026-08-16 15:40:15');

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
  `status` enum('borrowed','returned') NOT NULL DEFAULT 'borrowed',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `student_id`, `book_id`, `date_borrowed`, `due_date`, `return_date`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-05-11 04:57:32', '2026-05-11 23:59:59', '2026-05-11 04:58:08', 'returned', '2026-05-10 18:57:32', '2026-05-10 18:58:08'),
(2, 1, 1, '2026-05-11 05:33:24', '2026-05-11 23:59:59', '2026-05-11 05:33:53', 'returned', '2026-05-10 19:33:24', '2026-05-10 19:33:53'),
(3, 10, 2, '2026-08-16 17:48:45', '2026-08-16 23:59:59', '2026-08-16 17:52:43', 'returned', '2026-08-16 15:48:45', '2026-08-16 15:52:43');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin') NOT NULL DEFAULT 'admin',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'System Administrator', 'admin', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin', 'active', '2026-05-05 06:33:43', '2026-05-05 06:33:43'),
(2, 'Library Staff', 'librarian', 'ab8e89c55367f55a2f933b8dc8a9994d61f997df2b402274eb943fa22d77394a', 'admin', 'active', '2026-05-05 06:33:43', '2026-05-05 19:30:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`audit_log_id`),
  ADD KEY `idx_audit_created_at` (`created_at`),
  ADD KEY `idx_audit_actor` (`actor_type`,`actor_id`),
  ADD KEY `idx_audit_event_type` (`event_type`),
  ADD KEY `idx_audit_module` (`module`),
  ADD KEY `idx_audit_status` (`status`);

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `uq_books_qr_code` (`qr_code`),
  ADD UNIQUE KEY `uq_books_book_number` (`book_number`),
  ADD KEY `idx_books_status` (`book_status`),
  ADD KEY `idx_books_title` (`title`),
  ADD KEY `idx_books_author` (`author`);

--
-- Indexes for table `login_security`
--
ALTER TABLE `login_security`
  ADD PRIMARY KEY (`login_security_id`),
  ADD UNIQUE KEY `uq_login_security` (`context`,`identifier_hash`,`ip_address`),
  ADD KEY `idx_login_locked_until` (`locked_until`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `uq_students_qr_code` (`qr_code`),
  ADD UNIQUE KEY `uq_students_student_no` (`student_no`),
  ADD KEY `idx_students_status` (`status`),
  ADD KEY `idx_students_email` (`email`),
  ADD KEY `idx_students_department` (`department`),
  ADD KEY `idx_students_year_level` (`year_level`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_transactions_student_id` (`student_id`),
  ADD KEY `idx_transactions_book_id` (`book_id`),
  ADD KEY `idx_transactions_status` (`status`),
  ADD KEY `idx_transactions_date_borrowed` (`date_borrowed`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD KEY `idx_users_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `audit_log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `login_security`
--
ALTER TABLE `login_security`
  MODIFY `login_security_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

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
  ADD CONSTRAINT `transactions_book_fk` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `transactions_student_fk` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
