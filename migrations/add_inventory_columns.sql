-- Database Migration: Add Inventory Tracking Columns
-- Run this if you have an existing database that needs inventory columns

USE library_borrowing_system;

-- Add inventory columns to books table if they don't exist
ALTER TABLE books 
ADD COLUMN total_copies INT DEFAULT 1 AFTER book_status,
ADD COLUMN available_copies INT DEFAULT 1 AFTER total_copies,
ADD COLUMN borrowed_copies INT DEFAULT 0 AFTER available_copies,
ADD COLUMN lost_copies INT DEFAULT 0 AFTER borrowed_copies;

-- Add indexes for better performance
ALTER TABLE books 
ADD INDEX idx_available_copies (available_copies),
ADD INDEX idx_borrowed_copies (borrowed_copies);

-- Update book_status enum to include 'out_of_stock'
ALTER TABLE books 
MODIFY COLUMN book_status ENUM('available', 'out_of_stock', 'damaged', 'lost') NOT NULL DEFAULT 'available';
