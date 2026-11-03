<?php
/**
 * Security Service
 * 
 * Provides CSRF protection, IP/Email rate-limiting against brute force,
 * security audit logging, and input/output sanitation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';

class SecurityService
{
    /**
     * Resolves client IP address with proxy awareness.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Standard reverse proxy
            'HTTP_X_REAL_IP',        // Nginx / cPanel reverse proxy
            'REMOTE_ADDR'            // Standard direct connection
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Generates or retrieves a cryptographically secure CSRF token.
     */
    public static function generateCsrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time'] > 7200)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validates submitted CSRF token using hash_equals to prevent timing attacks.
     */
    public static function validateCsrfToken(?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            self::logSecurityEvent('csrf_failure', null, self::getClientIp(), 'Missing token in session or POST');
            return false;
        }

        $isValid = hash_equals($_SESSION['csrf_token'], $token);
        if (!$isValid) {
            self::logSecurityEvent('csrf_failure', null, self::getClientIp(), 'Token mismatch');
        }

        return $isValid;
    }

    /**
     * Checks if IP address is currently rate-limited (brute-force defense).
     */
    public static function isIpRateLimited(string $ip, ?int $maxAttempts = null, ?int $decayMinutes = null): bool
    {
        $maxAttempts = $maxAttempts ?? (int)config('app.auth.rate_limit_attempts', 5);
        $decayMinutes = $decayMinutes ?? (int)config('app.auth.rate_limit_decay_minutes', 1);

        $since = date('Y-m-d H:i:s', time() - ($decayMinutes * 60));
        $sql = "SELECT COUNT(*) AS failed_count 
                FROM `login_logs` 
                WHERE `ip_address` = :ip 
                  AND `status` = 'failed' 
                  AND `attempted_at` >= :since";

        $row = Database::fetchOne($sql, ['ip' => $ip, 'since' => $since]);
        $count = (int)($row['failed_count'] ?? 0);

        return $count >= $maxAttempts;
    }

    /**
     * Checks if a target email address is currently rate-limited.
     */
    public static function isEmailRateLimited(string $email, ?int $maxAttempts = null, ?int $decayMinutes = null): bool
    {
        $maxAttempts = $maxAttempts ?? (int)config('app.auth.rate_limit_attempts', 5);
        $decayMinutes = $decayMinutes ?? (int)config('app.auth.rate_limit_decay_minutes', 1);

        $since = date('Y-m-d H:i:s', time() - ($decayMinutes * 60));
        $sql = "SELECT COUNT(*) AS failed_count 
                FROM `login_logs` 
                WHERE `email` = :email 
                  AND `status` = 'failed' 
                  AND `attempted_at` >= :since";

        $row = Database::fetchOne($sql, ['email' => $email, 'since' => $since]);
        $count = (int)($row['failed_count'] ?? 0);

        return $count >= $maxAttempts;
    }

    /**
     * Records a login attempt into login_logs.
     */
    public static function recordLoginAttempt(string $email, string $ip, bool $success, ?string $reason = null): void
    {
        $status = $success ? 'success' : 'failed';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $sql = "INSERT INTO `login_logs` (`email`, `ip_address`, `user_agent`, `status`, `failure_reason`, `attempted_at`) 
                VALUES (:email, :ip, :ua, :status, :reason, NOW())";

        Database::query($sql, [
            'email'  => $email,
            'ip'     => $ip,
            'ua'     => $userAgent,
            'status' => $status,
            'reason' => $reason
        ]);
    }

    /**
     * Logs security events into security_logs.
     */
    public static function logSecurityEvent(string $eventType, ?int $userId, string $ip, ?string $details = null): void
    {
        $sql = "INSERT INTO `security_logs` (`event_type`, `user_id`, `ip_address`, `request_uri`, `request_method`, `details`, `created_at`) 
                VALUES (:event, :user_id, :ip, :uri, :method, :details, NOW())";

        Database::query($sql, [
            'event'   => substr($eventType, 0, 50),
            'user_id' => $userId,
            'ip'      => $ip,
            'uri'     => substr($_SERVER['REQUEST_URI'] ?? '', 0, 255),
            'method'  => substr($_SERVER['REQUEST_METHOD'] ?? 'CLI', 0, 10),
            'details' => $details
        ]);
    }

    /**
     * Sanitizes plain text input.
     */
    public static function sanitize(string $input): string
    {
        return trim(strip_tags($input));
    }

    /**
     * HTML entity escaping for safe template output.
     */
    public static function escape(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// Global shorthand escape function
if (!function_exists('e')) {
    function e(string $string): string
    {
        return SecurityService::escape($string);
    }
}
