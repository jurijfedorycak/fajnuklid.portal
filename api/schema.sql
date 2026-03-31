-- Fajnuklid Portal Database Schema
-- MySQL 8.0+

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table: clients
-- ----------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(50) NOT NULL COMMENT 'External client identifier',
    `display_name` VARCHAR(255) NOT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_client_id` (`client_id`),
    KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: login_accounts
-- ----------------------------
DROP TABLE IF EXISTS `login_accounts`;
CREATE TABLE `login_accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `client_id` INT UNSIGNED NULL,
    `portal_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_client_id` (`client_id`),
    KEY `idx_portal_enabled` (`portal_enabled`),
    CONSTRAINT `fk_login_accounts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: icos
-- ----------------------------
DROP TABLE IF EXISTS `icos`;
CREATE TABLE `icos` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `ico` VARCHAR(8) NOT NULL COMMENT 'Czech company identification number',
    `name` VARCHAR(255) NOT NULL COMMENT 'Company name',
    `address` VARCHAR(500) NULL,
    `contract_start_date` DATE NULL,
    `contract_end_date` DATE NULL,
    `contract_pdf_path` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ico` (`ico`),
    KEY `idx_client_id` (`client_id`),
    CONSTRAINT `fk_icos_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: objects
-- ----------------------------
DROP TABLE IF EXISTS `objects`;
CREATE TABLE `objects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ico_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(500) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ico_id` (`ico_id`),
    CONSTRAINT `fk_objects_ico` FOREIGN KEY (`ico_id`) REFERENCES `icos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: employees
-- ----------------------------
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(20) NULL,
    `position` VARCHAR(100) NULL,
    `photo_url` VARCHAR(500) NULL,
    `show_name` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: allow showing name to clients',
    `show_photo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: allow showing photo to clients',
    `show_phone` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: allow showing phone to clients',
    `show_email` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: allow showing email to clients',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`active`),
    KEY `idx_name` (`last_name`, `first_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: employee_object_assignments
-- ----------------------------
DROP TABLE IF EXISTS `employee_object_assignments`;
CREATE TABLE `employee_object_assignments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id` INT UNSIGNED NOT NULL,
    `object_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_employee_object` (`employee_id`, `object_id`),
    KEY `idx_object_id` (`object_id`),
    CONSTRAINT `fk_assignments_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_assignments_object` FOREIGN KEY (`object_id`) REFERENCES `objects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: password_reset_tokens
-- ----------------------------
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(64) NOT NULL COMMENT 'SHA-256 hash of the token',
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_token` (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `login_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: contact_persons
-- ----------------------------
DROP TABLE IF EXISTS `contact_persons`;
CREATE TABLE `contact_persons` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `position` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_client_id` (`client_id`),
    CONSTRAINT `fk_contact_persons_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: company_info (Fajnuklid company details)
-- ----------------------------
DROP TABLE IF EXISTS `company_info`;
CREATE TABLE `company_info` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `ico` VARCHAR(8) NOT NULL,
    `dic` VARCHAR(12) NULL,
    `address` VARCHAR(500) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `website` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: fajnuklid_contacts (Support/sales contacts)
-- ----------------------------
DROP TABLE IF EXISTS `fajnuklid_contacts`;
CREATE TABLE `fajnuklid_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `position` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `photo_url` VARCHAR(500) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active_sort` (`active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: user_settings
-- ----------------------------
DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE `user_settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `notification_email` TINYINT(1) NOT NULL DEFAULT 1,
    `notification_invoice` TINYINT(1) NOT NULL DEFAULT 1,
    `notification_attendance` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_id` (`user_id`),
    CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `login_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------
-- Sample data for testing
-- ----------------------------

-- Insert Fajnuklid company info
INSERT INTO `company_info` (`name`, `ico`, `dic`, `address`, `phone`, `email`, `website`) VALUES
('Fajnuklid s.r.o.', '12345678', 'CZ12345678', 'Příkladná 123, 110 00 Praha 1', '+420 123 456 789', 'info@fajnuklid.cz', 'https://www.fajnuklid.cz');

-- Insert sample Fajnuklid contacts
INSERT INTO `fajnuklid_contacts` (`name`, `position`, `phone`, `email`, `sort_order`, `active`) VALUES
('Jan Novák', 'Obchodní ředitel', '+420 777 123 456', 'novak@fajnuklid.cz', 1, 1),
('Marie Svobodová', 'Zákaznická podpora', '+420 777 234 567', 'podpora@fajnuklid.cz', 2, 1);

-- Insert sample client
INSERT INTO `clients` (`client_id`, `display_name`, `active`) VALUES
('TEST001', 'Testovací klient s.r.o.', 1);

-- Insert sample login account (password: test123)
INSERT INTO `login_accounts` (`email`, `password_hash`, `client_id`, `portal_enabled`) VALUES
('test@test.cz', '$2y$12$LQv3c1yqBWVHxkd0LHAkCOYz6TtxMQJqhN8/X4p.aOCGn3xSGKqGm', 1, 1);

-- Insert sample IČO for the test client
INSERT INTO `icos` (`client_id`, `ico`, `name`, `address`, `contract_start_date`) VALUES
(1, '87654321', 'Testovací firma a.s.', 'Testovací 456, 120 00 Praha 2', '2024-01-01');

-- Insert sample object
INSERT INTO `objects` (`ico_id`, `name`, `address`, `latitude`, `longitude`) VALUES
(1, 'Hlavní budova', 'Testovací 456, 120 00 Praha 2', 50.0755, 14.4378);

-- Insert sample employee
INSERT INTO `employees` (`first_name`, `last_name`, `email`, `phone`, `position`, `show_name`, `show_photo`, `show_phone`, `show_email`, `active`) VALUES
('Petr', 'Procházka', 'prochazka@fajnuklid.cz', '+420 777 345 678', 'Vedoucí směny', 1, 1, 1, 0, 1);

-- Assign employee to object
INSERT INTO `employee_object_assignments` (`employee_id`, `object_id`) VALUES
(1, 1);
