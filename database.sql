-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 05, 2026 at 05:46 PM
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
  `co_authors` text DEFAULT NULL,
  `place_of_publication` varchar(255) DEFAULT NULL,
  `publication_date` date DEFAULT NULL,
  `call_number` varchar(100) DEFAULT NULL,
  `accession_barcode_number` varchar(100) DEFAULT NULL,
  `type_of_material` varchar(100) DEFAULT NULL,
  `location_collection` varchar(255) DEFAULT NULL,
  `qr_code` varchar(255) NOT NULL,
  `book_status` enum('available','out_of_stock','damaged','lost') NOT NULL DEFAULT 'available',
  `total_copies` int(11) NOT NULL DEFAULT 1 COMMENT 'Total number of copies of this book in the library',
  `available_copies` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of copies currently available for borrowing',
  `borrowed_copies` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of copies currently borrowed',
  `lost_copies` int(11) NOT NULL DEFAULT 0 COMMENT 'Number of copies that are lost or damaged',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`book_id`, `title`, `author`, `co_authors`, `place_of_publication`, `publication_date`, `call_number`, `accession_barcode_number`, `type_of_material`, `location_collection`, `qr_code`, `book_status`, `total_copies`, `available_copies`, `borrowed_copies`, `lost_copies`, `created_at`, `updated_at`) VALUES
(1, 'Noli Me Tangere', 'Jose Rizal', '', 'Berlin', '1887-03-21', 'FIL 899.211 RIZ 1887', 'ACC-0001', 'Book', 'Filipiniana Section / Shelf A1', 'BOOK-20260505-P5ADS', 'available', 1, 1, 0, 0, '2026-05-05 15:25:48', '2026-05-05 15:28:15');

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
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `student_no`, `full_name`, `student_group`, `department`, `year_level`, `contact_number`, `card_valid_until`, `email`, `qr_code`, `status`, `created_at`, `updated_at`) VALUES
(1, '23-01446', 'Marcus Dominique Muico', '3A', 'College of Computer Studies', '3rd Year', '09457352866', '2026-12-05', 'marcusmuico70@gmail.com', 'STU-20260505-Q1ESG', 'active', '2026-05-05 15:18:57', '2026-05-05 15:46:51');

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

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'SHA-256 hashed password',
  `role` enum('super_admin','librarian') NOT NULL DEFAULT 'librarian',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Super Administrator', 'admin', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'super_admin', 'active', '2026-05-05 14:33:43', '2026-05-05 14:33:43'),
(2, 'Librarian User', 'librarian', 'ab8e89c55367f55a2f933b8dc8a9994d61f997df2b402274eb943fa22d77394a', 'librarian', 'active', '2026-05-05 14:33:43', '2026-05-05 14:33:43');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`book_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD UNIQUE KEY `accession_barcode_number` (`accession_barcode_number`),
  ADD KEY `idx_book_qr_code` (`qr_code`),
  ADD KEY `idx_book_status` (`book_status`),
  ADD KEY `idx_title` (`title`),
  ADD KEY `idx_author` (`author`),
  ADD KEY `idx_call_number` (`call_number`),
  ADD KEY `idx_type_of_material` (`type_of_material`),
  ADD KEY `idx_location_collection` (`location_collection`),
  ADD KEY `idx_available_copies` (`available_copies`),
  ADD KEY `idx_borrowed_copies` (`borrowed_copies`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `qr_code` (`qr_code`),
  ADD UNIQUE KEY `student_no` (`student_no`),
  ADD KEY `idx_qr_code` (`qr_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_department` (`department`),
  ADD KEY `idx_year_level` (`year_level`),
  ADD KEY `idx_card_valid_until` (`card_valid_until`);

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
  MODIFY `book_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;

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
