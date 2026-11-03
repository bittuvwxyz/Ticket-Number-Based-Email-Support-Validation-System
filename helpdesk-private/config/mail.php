<?php
/**
 * Inbound (IMAP) & Outbound (SMTP) Mail Configuration
 */

return [
    'imap' => [
        'host'          => $_ENV['MAIL_IMAP_HOST'] ?? 'mail.example.com',
        'port'          => (int)($_ENV['MAIL_IMAP_PORT'] ?? 993),
        'encryption'    => $_ENV['MAIL_IMAP_ENCRYPTION'] ?? 'ssl',
        'username'      => $_ENV['MAIL_IMAP_USERNAME'] ?? '',
        'password'      => $_ENV['MAIL_IMAP_PASSWORD'] ?? '',
        'validate_cert' => filter_var($_ENV['MAIL_IMAP_VALIDATE_CERT'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],
    'smtp' => [
        'host'       => $_ENV['MAIL_SMTP_HOST'] ?? 'mail.example.com',
        'port'       => (int)($_ENV['MAIL_SMTP_PORT'] ?? 587),
        'encryption' => $_ENV['MAIL_SMTP_ENCRYPTION'] ?? 'tls',
        'auth'       => filter_var($_ENV['MAIL_SMTP_AUTH'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'username'   => $_ENV['MAIL_SMTP_USERNAME'] ?? '',
        'password'   => $_ENV['MAIL_SMTP_PASSWORD'] ?? '',
        'from_email' => $_ENV['MAIL_FROM_ADDRESS'] ?? 'support@example.com',
        'from_name'  => $_ENV['MAIL_FROM_NAME'] ?? 'Support Desk',
    ],
    'reply_routing' => [
        'domain'             => $_ENV['MAIL_REPLY_DOMAIN'] ?? 'example.com',
        'prefix'             => $_ENV['MAIL_REPLY_PREFIX'] ?? 'reply+',
        'token_expiry_days'  => (int)($_ENV['MAIL_TOKEN_EXPIRY_DAYS'] ?? 14),
        'token_length_bytes' => (int)($_ENV['MAIL_TOKEN_LENGTH_BYTES'] ?? 32),
    ]
];
