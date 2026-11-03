<?php
/**
 * User Authentication & Session Security Service
 * 
 * Handles user registration, bcrypt password hashing, login brute-force checks,
 * email verification, password reset, and secure session management.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/SecurityService.php';
require_once __DIR__ . '/MailQueueService.php';

class AuthService
{
    /**
     * Initializes secure session with security flags.
     */
    public static function startSecureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionConfig = config('app.session', []);
        $lifetime = (int)($sessionConfig['lifetime'] ?? 7200);
        $secure = (bool)($sessionConfig['secure'] ?? false);
        $httponly = (bool)($sessionConfig['httponly'] ?? true);
        $samesite = $sessionConfig['samesite'] ?? 'Lax';

        if (!headers_sent() && php_sapi_name() !== 'cli') {
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => $httponly,
                'samesite' => $samesite
            ]);

            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string)$lifetime);
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        } elseif (session_status() !== PHP_SESSION_ACTIVE && php_sapi_name() === 'cli') {
            @session_start();
        }

        // Enforce session expiration window
        if (!empty($_SESSION['auth_last_activity']) && (time() - $_SESSION['auth_last_activity'] > $lifetime)) {
            self::logout();
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                session_start();
            }
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['auth_last_activity'] = time();
        }
    }

    /**
     * Registers a new customer user.
     */
    public static function register(array $data): array
    {
        $firstName = trim($data['first_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $email = strtolower(trim($data['email'] ?? ''));
        $password = $data['password'] ?? '';
        $confirmPassword = $data['password_confirm'] ?? '';
        $phone = trim($data['phone'] ?? '');

        // Validation
        if (empty($firstName) || empty($lastName)) {
            return ['success' => false, 'error' => 'First and last name are required.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
        }

        if ($password !== $confirmPassword) {
            return ['success' => false, 'error' => 'Password confirmation does not match.'];
        }

        // Check for duplicate email
        $existing = Database::fetchOne("SELECT `id` FROM `users` WHERE `email` = :email", ['email' => $email]);
        if ($existing) {
            return ['success' => false, 'error' => 'An account with this email address already exists.'];
        }

        // Password hash using bcrypt
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        // Check if email verification is globally required
        $reqVerifySetting = Database::fetchOne(
            "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'require_email_verification'"
        );
        $requireVerification = $reqVerifySetting ? filter_var($reqVerifySetting['setting_value'], FILTER_VALIDATE_BOOLEAN) : true;

        $verificationToken = bin2hex(random_bytes(32));
        $verificationExpires = date('Y-m-d H:i:s', time() + 86400); // 24 hours
        $isVerified = $requireVerification ? 0 : 1;

        $sql = "INSERT INTO `users` 
                (`role`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `is_verified`, `verification_token`, `verification_expires_at`, `status`, `created_at`) 
                VALUES 
                ('customer', :email, :password_hash, :first_name, :last_name, :phone, :is_verified, :token, :expires, 'active', NOW())";

        Database::query($sql, [
            'email'         => $email,
            'password_hash' => $passwordHash,
            'first_name'    => $firstName,
            'last_name'     => $lastName,
            'phone'         => $phone ?: null,
            'is_verified'   => $isVerified,
            'token'         => $requireVerification ? $verificationToken : null,
            'expires'       => $requireVerification ? $verificationExpires : null,
        ]);

        $userId = (int)Database::lastInsertId();

        // Enqueue verification email
        if ($requireVerification) {
            MailQueueService::enqueueVerificationEmail($email, "{$firstName} {$lastName}", $verificationToken);
        }

        SecurityService::logSecurityEvent('user_registered', $userId, SecurityService::getClientIp(), "Email: {$email}");

        return [
            'success'              => true,
            'user_id'              => $userId,
            'require_verification' => $requireVerification,
            'message'              => $requireVerification 
                ? 'Registration successful! A verification email has been queued for your inbox.' 
                : 'Registration successful! You may now log in.'
        ];
    }

    /**
     * Authenticates a user with brute-force rate-limiting and session rotation.
     */
    public static function login(string $email, string $password): array
    {
        self::startSecureSession();
        $ip = SecurityService::getClientIp();
        $email = strtolower(trim($email));

        // 1. Rate limiting check
        if (SecurityService::isIpRateLimited($ip, 5, 15) || SecurityService::isEmailRateLimited($email, 5, 15)) {
            SecurityService::recordLoginAttempt($email, $ip, false, 'Rate limit exceeded');
            SecurityService::logSecurityEvent('login_blocked_rate_limit', null, $ip, "Target email: {$email}");
            return [
                'success' => false,
                'error'   => 'Too many failed login attempts. For security reasons, please try again in 15 minutes.'
            ];
        }

        // 2. Fetch user
        $user = Database::fetchOne("SELECT * FROM `users` WHERE `email` = :email", ['email' => $email]);
        if (!$user) {
            SecurityService::recordLoginAttempt($email, $ip, false, 'User not found');
            return ['success' => false, 'error' => 'Invalid email or password credentials.'];
        }

        // 3. Password verification
        if (!password_verify($password, $user['password_hash'])) {
            SecurityService::recordLoginAttempt($email, $ip, false, 'Invalid password');
            return ['success' => false, 'error' => 'Invalid email or password credentials.'];
        }

        // 4. Account status verification
        if ($user['status'] === 'suspended') {
            SecurityService::recordLoginAttempt($email, $ip, false, 'Account suspended');
            return ['success' => false, 'error' => 'Your account has been suspended. Please contact support.'];
        }

        if ($user['status'] === 'inactive') {
            SecurityService::recordLoginAttempt($email, $ip, false, 'Account inactive');
            return ['success' => false, 'error' => 'Your account is inactive. Please contact an administrator.'];
        }

        // 5. Email verification check
        $reqVerifySetting = Database::fetchOne(
            "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'require_email_verification'"
        );
        $requireVerification = $reqVerifySetting ? filter_var($reqVerifySetting['setting_value'], FILTER_VALIDATE_BOOLEAN) : true;

        if ($requireVerification && (int)$user['is_verified'] !== 1) {
            SecurityService::recordLoginAttempt($email, $ip, false, 'Email not verified');
            return [
                'success' => false,
                'error'   => 'Your email address is not verified yet. Please check your inbox for the verification link.'
            ];
        }

        // 6. Successful authentication: rotate session ID to prevent session fixation
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = "{$user['first_name']} {$user['last_name']}";
        $_SESSION['auth_time'] = time();

        // Update login audit timestamp and IP
        Database::query(
            "UPDATE `users` SET `last_login_at` = NOW(), `last_login_ip` = :ip WHERE `id` = :id",
            ['ip' => $ip, 'id' => $user['id']]
        );

        SecurityService::recordLoginAttempt($email, $ip, true, 'Authenticated successfully');

        return [
            'success' => true,
            'user'    => [
                'id'         => (int)$user['id'],
                'role'       => $user['role'],
                'email'      => $user['email'],
                'first_name' => $user['first_name'],
                'last_name'  => $user['last_name']
            ]
        ];
    }

    /**
     * Terminates user session securely.
     */
    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        if ($userId) {
            SecurityService::logSecurityEvent('user_logout', (int)$userId, SecurityService::getClientIp());
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }

    /**
     * Retrieves currently authenticated user session data or null.
     */
    public static function getCurrentUser(): ?array
    {
        self::startSecureSession();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = Database::fetchOne(
            "SELECT `id`, `role`, `email`, `first_name`, `last_name`, `phone`, `is_verified`, `status`, `created_at` 
             FROM `users` 
             WHERE `id` = :id AND `status` = 'active'",
            ['id' => (int)$_SESSION['user_id']]
        );

        return $user ?: null;
    }

    /**
     * Enforces authentication; redirects to login if unauthenticated.
     */
    public static function requireAuth(?string $role = null): array
    {
        $user = self::getCurrentUser();
        if (!$user) {
            $appUrl = config('app.url', 'http://localhost/helpdesk');
            header("Location: {$appUrl}/login.php");
            exit;
        }

        if ($role !== null && $user['role'] !== $role && $user['role'] !== 'admin') {
            http_response_code(403);
            die("Access Denied: You do not possess the required permissions.");
        }

        return $user;
    }

    /**
     * Verifies an account using the verification token.
     */
    public static function verifyEmail(string $token): array
    {
        if (empty($token) || strlen($token) !== 64) {
            return ['success' => false, 'error' => 'Invalid or malformed verification token.'];
        }

        $user = Database::fetchOne(
            "SELECT `id`, `email`, `is_verified`, `verification_expires_at` 
             FROM `users` 
             WHERE `verification_token` = :token",
            ['token' => $token]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Verification token not found or already redeemed.'];
        }

        if ($user['is_verified'] == 1) {
            return ['success' => true, 'message' => 'Your email is already verified. You may log in.'];
        }

        if (!empty($user['verification_expires_at']) && strtotime($user['verification_expires_at']) < time()) {
            return ['success' => false, 'error' => 'Verification token has expired. Please request a new verification email.'];
        }

        Database::query(
            "UPDATE `users` SET `is_verified` = 1, `verification_token` = NULL, `verification_expires_at` = NULL WHERE `id` = :id",
            ['id' => $user['id']]
        );

        SecurityService::logSecurityEvent('email_verified', (int)$user['id'], SecurityService::getClientIp());

        return ['success' => true, 'message' => 'Email verified successfully! You may now log in to your account.'];
    }

    /**
     * Initiates password reset by creating a token and enqueuing an email.
     */
    public static function initiatePasswordReset(string $email): array
    {
        $email = strtolower(trim($email));
        $user = Database::fetchOne("SELECT `id`, `first_name`, `last_name`, `email` FROM `users` WHERE `email` = :email", ['email' => $email]);

        // Constant response message to prevent email enumeration
        $genericSuccess = [
            'success' => true,
            'message' => 'If an account exists with that email address, password reset instructions have been dispatched.'
        ];

        if (!$user) {
            return $genericSuccess;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

        Database::query(
            "UPDATE `users` SET `reset_token_hash` = :hash, `reset_token_expires_at` = :expires WHERE `id` = :id",
            [
                'hash'    => $tokenHash,
                'expires' => $expires,
                'id'      => $user['id']
            ]
        );

        MailQueueService::enqueuePasswordResetEmail(
            $user['email'],
            "{$user['first_name']} {$user['last_name']}",
            $token
        );

        SecurityService::logSecurityEvent('password_reset_requested', (int)$user['id'], SecurityService::getClientIp());

        return $genericSuccess;
    }

    /**
     * Resets a user's password using the submitted token.
     */
    public static function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters long.'];
        }

        $tokenHash = hash('sha256', $token);
        $user = Database::fetchOne(
            "SELECT `id`, `email`, `reset_token_expires_at` 
             FROM `users` 
             WHERE `reset_token_hash` = :hash",
            ['hash' => $tokenHash]
        );

        if (!$user) {
            return ['success' => false, 'error' => 'Invalid or expired password reset link.'];
        }

        if (strtotime($user['reset_token_expires_at']) < time()) {
            return ['success' => false, 'error' => 'This password reset link has expired. Please request a new one.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::query(
            "UPDATE `users` SET `password_hash` = :hash, `reset_token_hash` = NULL, `reset_token_expires_at` = NULL WHERE `id` = :id",
            ['hash' => $newHash, 'id' => $user['id']]
        );

        SecurityService::logSecurityEvent('password_reset_completed', (int)$user['id'], SecurityService::getClientIp());

        return ['success' => true, 'message' => 'Password has been updated successfully! You can now log in.'];
    }
}
