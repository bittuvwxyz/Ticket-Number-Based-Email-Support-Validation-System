<?php
/**
 * Application Core Configuration
 */

$rootPath = dirname(__DIR__);

return [
    'name'     => $_ENV['APP_NAME'] ?? 'Ticket Email Support',
    'env'      => $_ENV['APP_ENV'] ?? 'development',
    'key'      => $_ENV['APP_KEY'] ?? 'secret_app_key_needs_configuration',
    'debug'    => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'         => rtrim($_ENV['APP_URL'] ?? 'http://localhost/helpdesk', '/'),
    'timezone'    => $_ENV['APP_TIMEZONE'] ?? 'UTC',
    'admin_email' => $_ENV['ADMIN_EMAIL'] ?? ($_ENV['MAIL_FROM_ADDRESS'] ?? 'mail@websitesting.shop'),
    'paths'    => [
        'root'        => $rootPath,
        'storage'     => $rootPath . DIRECTORY_SEPARATOR . 'storage',
        'attachments' => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'attachments',
        'backups'     => $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups',
        'logs'        => $rootPath . DIRECTORY_SEPARATOR . 'logs',
        'database'    => $rootPath . DIRECTORY_SEPARATOR . 'database',
        'services'    => $rootPath . DIRECTORY_SEPARATOR . 'services',
        'mail'        => $rootPath . DIRECTORY_SEPARATOR . 'mail',
    ],
    'session'  => [
        'lifetime' => 7200, // 2 hours
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ],
    'auth'     => [
        'rate_limit_attempts'      => (int)($_ENV['AUTH_RATE_LIMIT_ATTEMPTS'] ?? 5),
        'rate_limit_decay_minutes' => (int)($_ENV['AUTH_RATE_LIMIT_DECAY_MINUTES'] ?? 1),
    ],
    'ticket'   => [
        'prefix'               => $_ENV['TICKET_PREFIX'] ?? 'REQ',
        'allow_closed_replies' => filter_var($_ENV['TICKET_ALLOW_CLOSED_REPLIES'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'reopen_window_days'   => (int)($_ENV['TICKET_REOPEN_WINDOW_DAYS'] ?? 5),
        'auto_expire_days'     => (int)($_ENV['TICKET_AUTO_EXPIRE_DAYS'] ?? 30),
    ]
];
