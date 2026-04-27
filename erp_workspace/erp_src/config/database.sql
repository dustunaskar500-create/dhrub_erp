-- Dhrub Foundation ERP - Database Schema
-- Import this file in phpMyAdmin

CREATE DATABASE IF NOT EXISTS `u135884328_dhrub_erp` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `u135884328_dhrub_erp`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+05:30";

-- ROLES
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_name` VARCHAR(50) NOT NULL UNIQUE,
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `roles` (`id`, `role_name`, `description`) VALUES
(1, 'super_admin', 'Full system access'),
(2, 'admin', 'Administrative access'),
(3, 'manager', 'Manage operations'),
(4, 'accountant', 'Financial operations'),
(5, 'hr', 'Human resources'),
(6, 'editor', 'Content editing'),
(7, 'viewer', 'Read-only access');

-- USERS
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `profile_picture` VARCHAR(500),
  `role_id` INT NOT NULL DEFAULT 7,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login` DATETIME,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Admin password: admin123 (Using bcrypt cost=10 for compatibility)
-- Hash generated with password_hash('admin123', PASSWORD_DEFAULT)
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `role_id`) VALUES
(1, 'admin', 'admin@dhrubfoundation.org', '$2y$10$ODb5oJ8t9ZVFrrneU.164Oyg.8Ew6AYFYRCPv8OzGRLLuAGsprb6i', 'System Administrator', 1)
ON DUPLICATE KEY UPDATE password = '$2y$10$ODb5oJ8t9ZVFrrneU.164Oyg.8Ew6AYFYRCPv8OzGRLLuAGsprb6i', full_name = 'System Administrator';

-- Test users for each role (password: test123 for all)
INSERT INTO `users` (`id`, `username`, `email`, `password`, `full_name`, `role_id`) VALUES
(2, 'manager', 'manager@dhrubfoundation.org', '$2y$10$Xh9xS.D5a3ZvE8uYC0wqfuKQmJJ5EVpWvD6xdqKhXe0LKa3nKpnMK', 'Test Manager', 3),
(3, 'accountant', 'accountant@dhrubfoundation.org', '$2y$10$Xh9xS.D5a3ZvE8uYC0wqfuKQmJJ5EVpWvD6xdqKhXe0LKa3nKpnMK', 'Test Accountant', 4),
(4, 'hr', 'hr@dhrubfoundation.org', '$2y$10$Xh9xS.D5a3ZvE8uYC0wqfuKQmJJ5EVpWvD6xdqKhXe0LKa3nKpnMK', 'Test HR', 5),
(5, 'editor', 'editor@dhrubfoundation.org', '$2y$10$Xh9xS.D5a3ZvE8uYC0wqfuKQmJJ5EVpWvD6xdqKhXe0LKa3nKpnMK', 'Test Editor', 6),
(6, 'viewer', 'viewer@dhrubfoundation.org', '$2y$10$Xh9xS.D5a3ZvE8uYC0wqfuKQmJJ5EVpWvD6xdqKhXe0LKa3nKpnMK', 'Test Viewer', 7)
ON DUPLICATE KEY UPDATE full_name = VALUES(full_name);

-- DONORS
CREATE TABLE IF NOT EXISTS `donors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `donor_type` ENUM('individual', 'corporate', 'trust', 'other') DEFAULT 'individual',
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `pan` VARCHAR(20),
  `address` TEXT,
  `city` VARCHAR(100),
  `state` VARCHAR(100),
  `country` VARCHAR(100) DEFAULT 'India',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PROGRAMS
CREATE TABLE IF NOT EXISTS `programs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `program_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `start_date` DATE,
  `end_date` DATE,
  `budget` DECIMAL(15,2) DEFAULT 0,
  `status` ENUM('active', 'inactive', 'completed', 'paused') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PROJECTS
CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `program_id` INT,
  `project_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `budget` DECIMAL(15,2) DEFAULT 0,
  `status` ENUM('active', 'inactive', 'completed', 'paused') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DONATIONS
CREATE TABLE IF NOT EXISTS `donations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `donation_code` VARCHAR(50) NOT NULL UNIQUE,
  `donor_id` INT NOT NULL,
  `program_id` INT,
  `project_id` INT,
  `amount` DECIMAL(15,2) NOT NULL,
  `donation_type` ENUM('monetary', 'goods', 'bank_transfer') DEFAULT 'monetary',
  `payment_method` ENUM('cash', 'upi', 'cheque', 'card', 'bank_transfer') DEFAULT 'cash',
  `transaction_id` VARCHAR(100),
  `status` ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
  `donation_date` DATE NOT NULL,
  `notes` TEXT,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`donor_id`) REFERENCES `donors`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EXPENSES
CREATE TABLE IF NOT EXISTS `expenses` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `expense_category` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `expense_date` DATE NOT NULL,
  `program_id` INT,
  `description` TEXT,
  `receipt_url` VARCHAR(500),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EMPLOYEES
CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_code` VARCHAR(20) UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `designation` VARCHAR(100),
  `department` VARCHAR(100),
  `basic_salary` DECIMAL(15,2) DEFAULT 0,
  `hra` DECIMAL(15,2) DEFAULT 0,
  `da` DECIMAL(15,2) DEFAULT 0,
  `travel_allowance` DECIMAL(15,2) DEFAULT 0,
  `medical_allowance` DECIMAL(15,2) DEFAULT 0,
  `special_allowance` DECIMAL(15,2) DEFAULT 0,
  `other_allowances` DECIMAL(15,2) DEFAULT 0,
  `pf_deduction` DECIMAL(15,2) DEFAULT 0,
  `esi_deduction` DECIMAL(15,2) DEFAULT 0,
  `tds_deduction` DECIMAL(15,2) DEFAULT 0,
  `professional_tax` DECIMAL(15,2) DEFAULT 0,
  `other_deductions` DECIMAL(15,2) DEFAULT 0,
  `salary` DECIMAL(15,2) DEFAULT 0,
  `net_salary` DECIMAL(15,2) DEFAULT 0,
  `joining_date` DATE,
  `bank_name` VARCHAR(100),
  `bank_account` VARCHAR(50),
  `ifsc_code` VARCHAR(20),
  `pan_number` VARCHAR(20),
  `aadhar_number` VARCHAR(20),
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `address` TEXT,
  `emergency_contact` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample Employees with short codes
INSERT IGNORE INTO `employees` (`employee_code`, `name`, `email`, `phone`, `designation`, `department`, `basic_salary`, `hra`, `da`, `status`) VALUES
('EMP-001', 'Rajesh Kumar', 'rajesh@dhrubfoundation.org', '9876543210', 'Program Manager', 'Operations', 35000, 7000, 3500, 'active'),
('EMP-002', 'Priya Sharma', 'priya@dhrubfoundation.org', '9876543211', 'Accountant', 'Finance', 30000, 6000, 3000, 'active'),
('EMP-003', 'Amit Patel', 'amit@dhrubfoundation.org', '9876543212', 'Field Coordinator', 'Operations', 25000, 5000, 2500, 'active'),
('EMP-004', 'Sunita Devi', 'sunita@dhrubfoundation.org', '9876543213', 'HR Executive', 'HR', 28000, 5600, 2800, 'active'),
('EMP-005', 'Vikram Singh', 'vikram@dhrubfoundation.org', '9876543214', 'IT Support', 'IT', 32000, 6400, 3200, 'active');

-- PAYROLL
CREATE TABLE IF NOT EXISTS `payroll` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employee_id` INT NOT NULL,
  `month` VARCHAR(20) NOT NULL,
  `year` INT NOT NULL,
  `basic_salary` DECIMAL(15,2) DEFAULT 0,
  `hra` DECIMAL(15,2) DEFAULT 0,
  `da` DECIMAL(15,2) DEFAULT 0,
  `travel_allowance` DECIMAL(15,2) DEFAULT 0,
  `medical_allowance` DECIMAL(15,2) DEFAULT 0,
  `special_allowance` DECIMAL(15,2) DEFAULT 0,
  `other_allowances` DECIMAL(15,2) DEFAULT 0,
  `overtime` DECIMAL(15,2) DEFAULT 0,
  `bonus` DECIMAL(15,2) DEFAULT 0,
  `gross_salary` DECIMAL(15,2) DEFAULT 0,
  `pf_deduction` DECIMAL(15,2) DEFAULT 0,
  `esi_deduction` DECIMAL(15,2) DEFAULT 0,
  `tds_deduction` DECIMAL(15,2) DEFAULT 0,
  `professional_tax` DECIMAL(15,2) DEFAULT 0,
  `loan_deduction` DECIMAL(15,2) DEFAULT 0,
  `other_deductions` DECIMAL(15,2) DEFAULT 0,
  `total_deductions` DECIMAL(15,2) DEFAULT 0,
  `salary_paid` DECIMAL(15,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `payment_method` ENUM('cash', 'bank_transfer', 'cheque') DEFAULT 'bank_transfer',
  `transaction_reference` VARCHAR(100),
  `days_worked` INT DEFAULT 0,
  `days_absent` INT DEFAULT 0,
  `notes` TEXT,
  `status` ENUM('pending', 'paid', 'cancelled') DEFAULT 'paid',
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `unique_payroll` (`employee_id`, `month`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- VOLUNTEERS
CREATE TABLE IF NOT EXISTS `volunteers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `volunteer_code` VARCHAR(20) UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `designation` VARCHAR(100),
  `department` VARCHAR(100),
  `skills` TEXT,
  `availability` VARCHAR(100),
  `joined_date` DATE,
  `address` TEXT,
  `emergency_contact` VARCHAR(100),
  `pan_number` VARCHAR(20),
  `aadhar_number` VARCHAR(20),
  `status` ENUM('active', 'inactive') DEFAULT 'active',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sample Volunteers with short codes
INSERT IGNORE INTO `volunteers` (`volunteer_code`, `name`, `email`, `phone`, `designation`, `skills`, `availability`, `status`) VALUES
('VOL-001', 'Neha Gupta', 'neha.vol@gmail.com', '9123456780', 'Teaching Assistant', 'Teaching, Child Care', 'Weekends', 'active'),
('VOL-002', 'Karan Mehta', 'karan.vol@gmail.com', '9123456781', 'Event Coordinator', 'Event Management', 'Flexible', 'active'),
('VOL-003', 'Anjali Rao', 'anjali.vol@gmail.com', '9123456782', 'Medical Support', 'First Aid, Nursing', 'Weekdays', 'active'),
('VOL-004', 'Rohit Verma', 'rohit.vol@gmail.com', '9123456783', 'Driver', 'Driving, Logistics', 'Full-time', 'active'),
('VOL-005', 'Meera Joshi', 'meera.vol@gmail.com', '9123456784', 'Content Writer', 'Writing, Social Media', 'Remote', 'active');

-- INVENTORY
CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `category` ENUM('food', 'clothing', 'medical', 'educational', 'household', 'equipment', 'other') DEFAULT 'other',
  `quantity` INT DEFAULT 0,
  `unit` VARCHAR(50),
  `min_stock` INT DEFAULT 0,
  `location` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_id` INT NOT NULL,
  `transaction_type` ENUM('in', 'out') NOT NULL,
  `quantity` INT NOT NULL,
  `transaction_date` DATE NOT NULL,
  `reference` VARCHAR(255),
  `notes` TEXT,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- LEDGER
CREATE TABLE IF NOT EXISTS `ledger_entries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `reference_type` VARCHAR(50) NOT NULL,
  `reference_id` INT,
  `debit` DECIMAL(15,2) DEFAULT 0,
  `credit` DECIMAL(15,2) DEFAULT 0,
  `entry_date` DATE NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ACTIVITY LOGS
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `description` TEXT,
  `ip_address` VARCHAR(50),
  `user_agent` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SETTINGS
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('org_name', 'Dhrub Foundation'),
('org_address', 'Dhrub Foundation, India'),
('org_phone', '+91-XXXXXXXXXX'),
('org_email', 'info@dhrubfoundation.org'),
('org_pan', 'AAAAA1234A'),
('org_80g_number', '80G/2024/12345'),
('org_bank_name', 'State Bank of India'),
('org_bank_account', '1234567890123456'),
('org_bank_ifsc', 'SBIN0001234'),
('org_bank_branch', 'Main Branch, City'),
('currency_symbol', '₹'),
('fiscal_year_start', 'April');

-- INVOICES
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
  `invoice_type` ENUM('donation', 'service') NOT NULL DEFAULT 'donation',
  `reference_id` INT,
  `donor_id` INT,
  `donor_name` VARCHAR(255),
  `donor_email` VARCHAR(255),
  `donor_address` TEXT,
  `donor_pan` VARCHAR(20),
  `amount` DECIMAL(15,2) NOT NULL,
  `description` TEXT,
  `status` ENUM('draft', 'sent', 'paid') DEFAULT 'draft',
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`donor_id`) REFERENCES `donors`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MEMBERS
CREATE TABLE IF NOT EXISTS `members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `designation` VARCHAR(100) NOT NULL,
  `role_type` ENUM('founder', 'co_founder', 'trustee', 'board_member', 'advisor', 'patron', 'secretary', 'treasurer', 'director', 'other') NOT NULL DEFAULT 'other',
  `email` VARCHAR(255),
  `phone` VARCHAR(20),
  `bio` TEXT,
  `photo_url` VARCHAR(500),
  `linkedin_url` VARCHAR(500),
  `display_order` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `joined_date` DATE,
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `members` (`id`, `name`, `designation`, `role_type`, `email`, `bio`, `display_order`, `is_active`) VALUES
(1, 'Dr. Subrata Dhrub', 'Founder & Chairman', 'founder', 'founder@dhrubfoundation.org', 'Visionary leader.', 1, 1),
(2, 'Mrs. Anita Dhrub', 'Co-Founder', 'co_founder', 'cofounder@dhrubfoundation.org', 'Women empowerment advocate.', 2, 1);

-- GALLERY
CREATE TABLE IF NOT EXISTS `gallery` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `category` VARCHAR(100),
  `program_id` INT,
  `image_url` VARCHAR(500) NOT NULL,
  `thumbnail_url` VARCHAR(500),
  `created_by` INT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PERMISSIONS (Optional)
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `permission_name` VARCHAR(100) NOT NULL UNIQUE,
  `module` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
