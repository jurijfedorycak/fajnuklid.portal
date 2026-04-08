-- Fajnuklid Portal Database Schema
-- MySQL 8.0+
-- Generated from migration: 20260331000000_initial_schema.php

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ===========================
-- Independent tables (no FK dependencies)
-- ===========================

-- ----------------------------
-- Table: clients
-- ----------------------------
DROP TABLE IF EXISTS `clients`;
CREATE TABLE `clients` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` VARCHAR(50) NOT NULL COMMENT 'External client identifier',
    `display_name` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL COMMENT 'Soft delete timestamp',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_client_id` (`client_id`),
    KEY `idx_deleted_at` (`deleted_at`)
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
    `personal_id` VARCHAR(50) NULL COMMENT 'External personal identifier (time-tracking software link)',
    `position` VARCHAR(100) NULL,
    `photo_url` VARCHAR(500) NULL,
    `tenure_text` VARCHAR(100) NULL COMMENT 'Custom tenure display text',
    `bio` TEXT NULL COMMENT 'Employee biography',
    `hobbies` TEXT NULL COMMENT 'Employee hobbies',
    `contract_file` VARCHAR(500) NULL COMMENT 'Path to contract file',
    `show_name` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: allow showing name to clients',
    `show_photo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: allow showing photo to clients',
    `show_phone` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: allow showing phone to clients',
    `show_email` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: allow showing email to clients',
    `show_in_portal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: show employee in client portal',
    `show_role` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: show position/role in portal',
    `show_hobbies` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: show hobbies in portal',
    `show_tenure` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'GDPR: show tenure in portal',
    `show_bio` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'GDPR: show biography in portal',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL COMMENT 'Soft delete timestamp',
    PRIMARY KEY (`id`),
    KEY `idx_deleted_at` (`deleted_at`),
    KEY `idx_name` (`last_name`, `first_name`),
    KEY `idx_personal_id` (`personal_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: staff_contacts (Fajnuklid support/sales contacts)
-- ----------------------------
DROP TABLE IF EXISTS `staff_contacts`;
CREATE TABLE `staff_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `position` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `photo_url` VARCHAR(500) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL COMMENT 'Soft delete timestamp',
    PRIMARY KEY (`id`),
    KEY `idx_deleted_sort` (`deleted_at`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================
-- Tables with FK to clients
-- ===========================

-- ----------------------------
-- Table: login_accounts
-- ----------------------------
DROP TABLE IF EXISTS `login_accounts`;
CREATE TABLE `login_accounts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `portal_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_portal_enabled` (`portal_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: companies (client's registered companies with IČO)
-- ----------------------------
DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `registration_number` VARCHAR(8) NOT NULL COMMENT 'Czech IČO - company identification number',
    `name` VARCHAR(255) NOT NULL COMMENT 'Company name',
    `address` VARCHAR(500) NULL,
    `contract_start_date` DATE NULL,
    `contract_end_date` DATE NULL,
    `contract_pdf_path` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registration_number` (`registration_number`),
    KEY `idx_client_id` (`client_id`),
    CONSTRAINT `fk_companies_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: client_contacts (contact persons, linked to companies via junction table)
-- ----------------------------
DROP TABLE IF EXISTS `client_contacts`;
CREATE TABLE `client_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `position` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================
-- Tables with FK to companies
-- ===========================

-- ----------------------------
-- Table: locations (cleaning sites/objects)
-- ----------------------------
DROP TABLE IF EXISTS `locations`;
CREATE TABLE `locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `address` VARCHAR(500) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_company_id` (`company_id`),
    CONSTRAINT `fk_locations_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================
-- Tables with FK to login_accounts
-- ===========================

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
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_expires_at` (`expires_at`),
    CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `login_accounts` (`id`) ON DELETE CASCADE
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

-- ===========================
-- Junction tables
-- ===========================

-- ----------------------------
-- Table: employee_locations (assigns employees to specific locations - optional granular assignment)
-- ----------------------------
DROP TABLE IF EXISTS `employee_locations`;
CREATE TABLE `employee_locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id` INT UNSIGNED NOT NULL,
    `location_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_employee_location` (`employee_id`, `location_id`),
    KEY `idx_location_id` (`location_id`),
    CONSTRAINT `fk_employee_locations_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_employee_locations_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: client_employees (M:N junction between clients and employees - staff assignment)
-- ----------------------------
DROP TABLE IF EXISTS `client_employees`;
CREATE TABLE `client_employees` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `employee_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_client_employee` (`client_id`, `employee_id`),
    KEY `idx_employee_id` (`employee_id`),
    CONSTRAINT `fk_client_employees_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_client_employees_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: company_contacts (M:N junction between companies and client_contacts)
-- ----------------------------
DROP TABLE IF EXISTS `company_contacts`;
CREATE TABLE `company_contacts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `contact_id` INT UNSIGNED NOT NULL,
    `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Primary contact for this company',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_contact` (`company_id`, `contact_id`),
    KEY `idx_contact_id` (`contact_id`),
    CONSTRAINT `fk_company_contacts_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_company_contacts_contact` FOREIGN KEY (`contact_id`) REFERENCES `client_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: company_users (M:N junction between companies and login_accounts)
-- ----------------------------
DROP TABLE IF EXISTS `company_users`;
CREATE TABLE `company_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_company_user` (`company_id`, `user_id`),
    KEY `idx_user_id` (`user_id`),
    CONSTRAINT `fk_company_users_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_company_users_user` FOREIGN KEY (`user_id`) REFERENCES `login_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===========================
-- iDoklad Integration tables
-- ===========================

-- ----------------------------
-- Table: idoklad_tokens (OAuth2 token storage)
-- ----------------------------
DROP TABLE IF EXISTS `idoklad_tokens`;
CREATE TABLE `idoklad_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `access_token` TEXT NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: invoices (cache from iDoklad)
-- ----------------------------
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `idoklad_id` INT UNSIGNED NOT NULL COMMENT 'iDoklad invoice ID',
    `company_id` INT UNSIGNED NOT NULL,
    `document_number` VARCHAR(50) NOT NULL,
    `variable_symbol` VARCHAR(20) NULL,
    `date_issued` DATE NOT NULL,
    `date_due` DATE NOT NULL,
    `date_paid` DATE NULL,
    `total_amount` DECIMAL(12,2) NOT NULL,
    `currency_code` VARCHAR(3) NOT NULL DEFAULT 'CZK',
    `is_paid` TINYINT(1) NOT NULL DEFAULT 0,
    `payment_status` ENUM('unpaid','paid','partial','overdue') NOT NULL DEFAULT 'unpaid',
    `description` VARCHAR(500) NULL,
    `synced_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_idoklad_id` (`idoklad_id`),
    KEY `idx_company_id` (`company_id`),
    KEY `idx_payment_status` (`payment_status`),
    CONSTRAINT `fk_invoices_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: maintenance_requests
-- ----------------------------
DROP TABLE IF EXISTS `maintenance_requests`;
CREATE TABLE `maintenance_requests` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` INT UNSIGNED NOT NULL,
    `company_id` INT UNSIGNED NULL,
    `created_by_user_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `category` ENUM('elektro','voda','klima','uklid','pristupy','jine') NOT NULL,
    `location_type` ENUM('office','common','custom') NOT NULL,
    `location_value` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `status` ENUM('prijato','resi_se','ceka_na_potvrzeni','vyreseno','zablokovano') NOT NULL DEFAULT 'prijato',
    `due_date` DATE NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `idx_client_status` (`client_id`, `status`),
    KEY `idx_company_id` (`company_id`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_maintenance_requests_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_maintenance_requests_company` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_maintenance_requests_user` FOREIGN KEY (`created_by_user_id`) REFERENCES `login_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Table: maintenance_request_activity
-- ----------------------------
DROP TABLE IF EXISTS `maintenance_request_activity`;
CREATE TABLE `maintenance_request_activity` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `author_type` ENUM('client','admin','system') NOT NULL,
    `author_name` VARCHAR(255) NULL,
    `message` TEXT NULL,
    `status_change` VARCHAR(40) NULL,
    `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_request_id` (`request_id`),
    CONSTRAINT `fk_request_activity_request` FOREIGN KEY (`request_id`) REFERENCES `maintenance_requests` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_request_activity_user` FOREIGN KEY (`user_id`) REFERENCES `login_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
