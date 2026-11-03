-- Migration: 002_seed_initial_settings
-- Description: Seeds initial system settings and default administrator account

INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('ticket_number_prefix', 'REQ', 'string', 'Prefix for human-readable ticket numbers (e.g. REQ-2026-10001)'),
('token_expiry_days', '14', 'integer', 'Validity period in days for email reply tokens'),
('allow_closed_ticket_replies', '0', 'boolean', 'Whether incoming emails to closed tickets are accepted (0=policy action, 1=auto-reopen)'),
('closed_ticket_policy', 'reject', 'string', 'Action for replies to closed tickets: reject, quarantine, reopen'),
('auto_expire_days', '30', 'integer', 'Inactivity duration in days before tickets are marked as expired'),
('max_attachment_size_mb', '10', 'integer', 'Maximum allowed attachment file size in megabytes'),
('allowed_attachment_extensions', 'jpg,jpeg,png,gif,pdf,txt,doc,docx,zip,csv,xlsx', 'string', 'Comma-separated list of permitted attachment file extensions'),
('mail_reply_domain', 'websitesting.shop', 'string', 'Domain configured for secure tokenized reply-to addresses'),
('mail_reply_prefix', 'reply+', 'string', 'Prefix before token in email reply address (e.g. reply+{token}@domain.com)'),
('email_queue_batch_size', '25', 'integer', 'Maximum outbound emails to process per cron execution'),
('email_inbound_batch_size', '30', 'integer', 'Maximum inbound IMAP messages to parse per cron execution'),
('require_email_verification', '1', 'boolean', 'Require users to verify email before creating tickets')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Default Administrator (mail@websitesting.shop / Admin@123456)
INSERT INTO `users` (`role`, `email`, `password_hash`, `first_name`, `last_name`, `is_verified`, `status`)
VALUES ('admin', 'mail@websitesting.shop', '$2y$10$JUFi3QS4e12mHNo8MKIYCukT6Ys2pvsvIo8hjrx9rVFt2kCpPA3VO', 'System', 'Administrator', 1, 'active')
ON DUPLICATE KEY UPDATE `status` = 'active', `email` = 'mail@websitesting.shop';
