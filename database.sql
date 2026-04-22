-- Create Database for Multi-User QR Library Borrowing System
CREATE DATABASE IF NOT EXISTS library_borrowing_system;
USE library_borrowing_system;

-- Drop existing tables if they exist (in reverse order of dependencies)
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS books;
DROP TABLE IF EXISTS students;
DROP TABLE IF EXISTS users;

-- 1. Users Table (for login - super_admin and librarian)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL COMMENT 'Hashed password',
    role ENUM('super_admin', 'librarian') NOT NULL DEFAULT 'librarian',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Students Table
CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    contact_number VARCHAR(20),
    qr_code VARCHAR(255) UNIQUE NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qr_code (qr_code),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Books Table
CREATE TABLE books (
    book_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    qr_code VARCHAR(255) UNIQUE NOT NULL,
    book_status ENUM('available', 'out_of_stock', 'damaged', 'lost') NOT NULL DEFAULT 'available',
    total_copies INT DEFAULT 1 COMMENT 'Total number of copies of this book in the library',
    available_copies INT DEFAULT 1 COMMENT 'Number of copies currently available for borrowing',
    borrowed_copies INT DEFAULT 0 COMMENT 'Number of copies currently borrowed',
    lost_copies INT DEFAULT 0 COMMENT 'Number of copies that are lost or damaged',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qr_code (qr_code),
    INDEX idx_book_status (book_status),
    INDEX idx_title (title),
    INDEX idx_available_copies (available_copies),
    INDEX idx_borrowed_copies (borrowed_copies)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Transactions Table
CREATE TABLE transactions (
    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    book_id INT NOT NULL,
    date_borrowed DATETIME NOT NULL,
    due_date DATETIME NOT NULL,
    return_date DATETIME,
    penalty_amount DECIMAL(10, 2) DEFAULT 0.00,
    status ENUM('borrowed', 'returned', 'overdue') NOT NULL DEFAULT 'borrowed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(student_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    FOREIGN KEY (book_id) REFERENCES books(book_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX idx_student_id (student_id),
    INDEX idx_book_id (book_id),
    INDEX idx_status (status),
    INDEX idx_date_borrowed (date_borrowed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create sample data (optional - remove if not needed)
-- Insert sample users
INSERT INTO users (full_name, username, password, role, status) VALUES
('Super Administrator', 'admin', SHA2('admin123', 256), 'super_admin', 'active'),
('Librarian User', 'librarian', SHA2('librarian123', 256), 'librarian', 'active');

-- Insert sample students
INSERT INTO students (full_name, contact_number, qr_code, status) VALUES
('John Doe', '9876543210', 'STU-QR-001', 'active'),
('Jane Smith', '9876543211', 'STU-QR-002', 'active'),
('Michael Johnson', '9876543212', 'STU-QR-003', 'active');

-- Insert sample books with inventory
INSERT INTO books (title, author, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies) VALUES
('The Great Gatsby', 'F. Scott Fitzgerald', 'BOOK-QR-001', 'available', 3, 2, 1, 0),
('To Kill a Mockingbird', 'Harper Lee', 'BOOK-QR-002', 'available', 2, 2, 0, 0),
('1984', 'George Orwell', 'BOOK-QR-003', 'out_of_stock', 4, 0, 3, 1),
('Pride and Prejudice', 'Jane Austen', 'BOOK-QR-004', 'available', 5, 4, 1, 0);

-- Insert sample transaction
INSERT INTO transactions (student_id, book_id, date_borrowed, due_date, status) VALUES
(1, 3, NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 'borrowed');
