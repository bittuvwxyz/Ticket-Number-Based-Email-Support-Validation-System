-- =============================================================================
-- Ticket Number-Based Email Support & Validation System
-- Master Database Schema
-- Compatible with MySQL 8.0+ and MariaDB 10.4+
-- Charset: utf8mb4 | Collation: utf8mb4_unicode_ci | Engine: InnoDB
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- -----------------------------------------------------------------------------
-- 0. Database Migration Tracking
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(191) NOT NULL UNIQUE,
    `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 1. Users Table
-- Supports customer registration, administrators, agents, email verification,
-- password reset tokens, and session auditing.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role` ENUM('admin', 'agent', 'customer') NOT NULL DEFAULT 'customer',
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
    `verification_token` VARCHAR(100) DEFAULT NULL,
    `verification_expires_at` DATETIME DEFAULT NULL,
    `reset_token_hash` VARCHAR(64) DEFAULT NULL,
    `reset_token_expires_at` DATETIME DEFAULT NULL,
    `status` ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Tickets Table
-- Human-readable ticket number (e.g. REQ-2026-10001), customer association,
-- lifecycle status, priorities, and expiration boundaries.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tickets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_number` VARCHAR(32) NOT NULL UNIQUE,
    `customer_id` BIGINT UNSIGNED NOT NULL,
    `assigned_to` BIGINT UNSIGNED DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `status` ENUM('open', 'pending', 'closed', 'expired', 'reopened') NOT NULL DEFAULT 'open',
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `category` VARCHAR(100) NOT NULL DEFAULT 'General',
    `last_reply_at` DATETIME DEFAULT NULL,
    `closed_at` DATETIME DEFAULT NULL,
    `closed_by` BIGINT UNSIGNED DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tickets_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tickets_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_tickets_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_tickets_customer` (`customer_id`),
    INDEX `idx_tickets_assigned` (`assigned_to`),
    INDEX `idx_tickets_status` (`status`),
    INDEX `idx_tickets_priority` (`priority`),
    INDEX `idx_tickets_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Email Tokens Table
-- Cryptographic token hashes for email reply verification.
-- Stores only SHA-256 hashes to prevent plaintext token exposure.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_tokens` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL UNIQUE,
    `token_prefix` VARCHAR(8) NOT NULL,
    `is_revoked` TINYINT(1) NOT NULL DEFAULT 0,
    `revoked_at` DATETIME DEFAULT NULL,
    `revoked_reason` VARCHAR(255) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tokens_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_tokens_ticket` (`ticket_id`),
    INDEX `idx_tokens_user` (`user_id`),
    INDEX `idx_tokens_expires_at` (`expires_at`),
    INDEX `idx_tokens_prefix` (`token_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. Authorized Ticket Emails Table
-- Allows multi-participant support when configured, ensuring strictly
-- authorized senders for ticket replies.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `authorized_ticket_emails` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `email` VARCHAR(191) NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `role` ENUM('owner', 'participant', 'cc') NOT NULL DEFAULT 'participant',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_auth_emails_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_auth_emails_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    UNIQUE KEY `uk_auth_ticket_email` (`ticket_id`, `email`),
    INDEX `idx_auth_emails_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. Ticket Messages Table
-- Stores threaded conversation messages, supports internal agent notes,
-- and preserves RFC Message-ID, In-Reply-To and References headers.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `sender_type` ENUM('customer', 'agent', 'admin', 'system') NOT NULL,
    `sender_email` VARCHAR(191) NOT NULL,
    `sender_name` VARCHAR(150) DEFAULT NULL,
    `body_plain` MEDIUMTEXT NOT NULL,
    `body_html` MEDIUMTEXT DEFAULT NULL,
    `is_internal_note` TINYINT(1) NOT NULL DEFAULT 0,
    `source` ENUM('web', 'email', 'api') NOT NULL DEFAULT 'web',
    `message_id_header` VARCHAR(255) DEFAULT NULL,
    `in_reply_to_header` VARCHAR(255) DEFAULT NULL,
    `references_header` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_messages_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_messages_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_messages_ticket` (`ticket_id`),
    INDEX `idx_messages_sender_email` (`sender_email`),
    INDEX `idx_messages_msgid` (`message_id_header`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. Email Attachments Table
-- Tracks uploaded/received files safely stored outside public web directory.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_attachments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` BIGINT UNSIGNED NOT NULL,
    `original_filename` VARCHAR(255) NOT NULL,
    `stored_filename` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_size` BIGINT UNSIGNED NOT NULL,
    `mime_type` VARCHAR(127) NOT NULL,
    `content_id` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attachments_message` FOREIGN KEY (`message_id`) REFERENCES `ticket_messages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_attachments_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. Ticket Status History Table
-- Audit trail of status transitions (open -> closed -> reopened etc.)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_status_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `old_status` ENUM('open', 'pending', 'closed', 'expired', 'reopened') DEFAULT NULL,
    `new_status` ENUM('open', 'pending', 'closed', 'expired', 'reopened') NOT NULL,
    `changed_by` BIGINT UNSIGNED DEFAULT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_status_hist_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_status_hist_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_status_hist_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 8. Outgoing Email Queue Table
-- Reliable database-first queue with retry mechanics and error capture.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_queue` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED DEFAULT NULL,
    `recipient_email` VARCHAR(191) NOT NULL,
    `recipient_name` VARCHAR(150) DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_plain` MEDIUMTEXT NOT NULL,
    `body_html` MEDIUMTEXT DEFAULT NULL,
    `reply_to_address` VARCHAR(191) DEFAULT NULL,
    `custom_headers` JSON DEFAULT NULL,
    `attachments_json` JSON DEFAULT NULL,
    `status` ENUM('pending', 'processing', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `last_error` TEXT DEFAULT NULL,
    `scheduled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_queue_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_queue_status_sched` (`status`, `scheduled_at`),
    INDEX `idx_queue_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. Outgoing Email Logs Table
-- Detailed delivery audit records for every SMTP transmission attempt.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `queue_id` BIGINT UNSIGNED DEFAULT NULL,
    `ticket_id` BIGINT UNSIGNED DEFAULT NULL,
    `recipient_email` VARCHAR(191) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `status` ENUM('success', 'failed', 'retrying') NOT NULL,
    `smtp_response` TEXT DEFAULT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_elogs_queue` FOREIGN KEY (`queue_id`) REFERENCES `email_queue` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_elogs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_elogs_ticket` (`ticket_id`),
    INDEX `idx_elogs_status` (`status`),
    INDEX `idx_elogs_recipient` (`recipient_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. Inbound Email Logs Table
-- Detailed log of all incoming emails received via IMAP with validation verdicts.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `inbound_email_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id_header` VARCHAR(255) DEFAULT NULL,
    `from_email` VARCHAR(191) NOT NULL,
    `to_email` VARCHAR(191) NOT NULL,
    `subject` VARCHAR(255) DEFAULT NULL,
    `ticket_number` VARCHAR(32) DEFAULT NULL,
    `token_prefix` VARCHAR(8) DEFAULT NULL,
    `processing_result` ENUM('accepted', 'rejected', 'quarantined', 'ignored_duplicate', 'error') NOT NULL,
    `rejection_reason` VARCHAR(255) DEFAULT NULL,
    `quarantine_reason` VARCHAR(255) DEFAULT NULL,
    `raw_headers` MEDIUMTEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inbound_msgid` (`message_id_header`),
    INDEX `idx_inbound_result` (`processing_result`),
    INDEX `idx_inbound_from` (`from_email`),
    INDEX `idx_inbound_ticket` (`ticket_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 11. Login Logs Table
-- Brute-force protection, IP rate limiting, and access history auditing.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(191) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('success', 'failed', 'blocked') NOT NULL,
    `failure_reason` VARCHAR(100) DEFAULT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_login_ip_time` (`ip_address`, `attempted_at`),
    INDEX `idx_login_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 12. Security Logs Table
-- Centralized logging for security events (CSRF, injection, token tamper).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `security_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(50) NOT NULL,
    `user_id` BIGINT UNSIGNED DEFAULT NULL,
    `ip_address` VARCHAR(45) NOT NULL,
    `request_uri` VARCHAR(255) DEFAULT NULL,
    `request_method` VARCHAR(10) DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_seclogs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    INDEX `idx_seclogs_event` (`event_type`),
    INDEX `idx_seclogs_ip` (`ip_address`),
    INDEX `idx_seclogs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 13. System Settings Table
-- Dynamic key-value configuration editable by administrator.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('string', 'integer', 'boolean', 'json') NOT NULL DEFAULT 'string',
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 14. Blocked Emails Table
-- Deny list for spam prevention and malicious actors.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blocked_emails` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pattern` VARCHAR(191) NOT NULL UNIQUE,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_blocked_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
