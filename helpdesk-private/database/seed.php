<?php
/**
 * Database Seeder & User Reset Script
 * 
 * Usage:
 *   php helpdesk-private/database/seed.php
 *   php helpdesk-private/database/seed.php --fresh
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/Database.php';

echo "============================================================\n";
echo " Helpdesk Database Seeder & Admin Credential Setup\n";
echo "============================================================\n\n";

try {
    $connTest = Database::testConnection();
    if ($connTest['status'] !== 'success') {
        echo "[ERROR] Database connection failed: " . $connTest['message'] . "\n";
        exit(1);
    }

    echo "[OK] Connected to: " . $connTest['database'] . "\n";

    $isFresh = in_array('--fresh', $argv, true);

    $pdo = Database::getConnection();

    if ($isFresh) {
        echo "[INFO] --fresh flag detected. Resetting all users...\n";
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        $pdo->exec("TRUNCATE TABLE users;");
        $pdo->exec("TRUNCATE TABLE login_logs;");
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        echo "[OK] Users and login logs cleared.\n";
    }

    // Default Administrator Credentials
    $adminEmail = $_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: 'mail@websitesting.shop';
    $adminPassword = 'Admin@123456';
    $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

    // Check if admin already exists
    $existing = Database::fetchOne("SELECT id, email FROM users WHERE role = 'admin' LIMIT 1");

    if ($existing) {
        // Update password and active status
        Database::query(
            "UPDATE users SET email = :email, password_hash = :hash, is_verified = 1, status = 'active' WHERE id = :id",
            [
                'email' => $adminEmail,
                'hash' => $passwordHash,
                'id' => $existing['id']
            ]
        );
        echo "[OK] Existing administrator account updated (User ID: {$existing['id']}).\n";
    } else {
        // Insert administrator
        Database::query(
            "INSERT INTO users (role, email, password_hash, first_name, last_name, is_verified, status)
             VALUES ('admin', :email, :hash, 'System', 'Administrator', 1, 'active')",
            [
                'email' => $adminEmail,
                'hash' => $passwordHash
            ]
        );
        $newId = Database::lastInsertId();
        echo "[OK] New administrator account created (User ID: {$newId}).\n";
    }

    // Clear any rate-limit locks or failed login logs
    Database::execute("DELETE FROM login_logs WHERE ip_address IN ('127.0.0.1', '::1') OR email = :email", ['email' => $adminEmail]);
    echo "[OK] Login rate limit logs cleared for local access.\n\n";

    echo "============================================================\n";
    echo " ADMIN LOGIN CREDENTIALS READY\n";
    echo "============================================================\n";
    echo " Login URL:  http://localhost/helpdesk/login.php\n";
    echo " Email:      {$adminEmail}\n";
    echo " Password:   {$adminPassword}\n";
    echo " Role:       admin\n";
    echo " Status:     active (Verified)\n";
    echo "============================================================\n";
    exit(0);

} catch (Throwable $e) {
    echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
    exit(1);
}
