<?php
/**
 * Phase 1 Diagnostic and Automated Test Suite
 * 
 * Verifies:
 * - Environment bootstrap
 * - Configuration retrieval
 * - PDO Database connectivity
 * - Character set (utf8mb4) & collation
 * - Foreign key constraints
 * - Transaction commit & rollback
 * - Seeded system settings
 * - Seeded admin account verification
 * - Table schema integrity
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';

echo "============================================================\n";
echo " Phase 1: Database Architecture & Connectivity Test Suite\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $description, bool $condition, ?string $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$description}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$description}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// 1. Config & Environment Test
echo "Test 1: Environment & Configuration Loading\n";
assertTest("App Name configuration resolved", config('app.name') === 'Secure Ticket Support');
assertTest("Database Host resolved", config('database.host') === '127.0.0.1');
assertTest("Database Name resolved", config('database.database') === 'helpdesk_db');

// 2. PDO Connectivity & Charset
echo "\nTest 2: PDO Connectivity & Charset Verification\n";
try {
    $pdo = Database::getConnection();
    assertTest("PDO instance retrieved successfully", $pdo instanceof PDO);

    $charsetStmt = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'");
    $charsetRow = $charsetStmt->fetch(PDO::FETCH_ASSOC);
    assertTest("Character set is utf8mb4", ($charsetRow['Value'] ?? '') === 'utf8mb4', "Actual: " . ($charsetRow['Value'] ?? 'unknown'));
} catch (Throwable $e) {
    assertTest("PDO connection failure", false, $e->getMessage());
}

// 3. Schema Completeness Test
echo "\nTest 3: Required Database Tables Verification (15 tables)\n";
$expectedTables = [
    'users',
    'tickets',
    'ticket_messages',
    'email_tokens',
    'email_queue',
    'email_logs',
    'inbound_email_logs',
    'email_attachments',
    'ticket_status_history',
    'authorized_ticket_emails',
    'login_logs',
    'security_logs',
    'system_settings',
    'blocked_emails',
    'migrations'
];

$existingTables = Database::fetchAll("SHOW TABLES");
$flattenedTables = array_map(fn($r) => array_values($r)[0], $existingTables);

foreach ($expectedTables as $tbl) {
    assertTest("Table `{$tbl}` exists", in_array($tbl, $flattenedTables, true));
}

// 4. Seeded Data Verification
echo "\nTest 4: System Settings & Administrator Seed Verification\n";
$adminUser = Database::fetchOne("SELECT * FROM `users` WHERE `email` = :email", ['email' => 'admin@example.com']);
assertTest("Admin user account exists", $adminUser !== null);
if ($adminUser) {
    assertTest("Admin role is 'admin'", $adminUser['role'] === 'admin');
    assertTest("Admin is verified", (int)$adminUser['is_verified'] === 1);
    assertTest("Admin password verification succeeds", password_verify('Admin@123456', $adminUser['password_hash']));
}

$settingsCount = (int)Database::fetchOne("SELECT COUNT(*) AS total FROM `system_settings`")['total'];
assertTest("System settings populated (> 5 entries)", $settingsCount >= 5, "Count: {$settingsCount}");

// 5. Transaction Rollback Verification
echo "\nTest 5: Transaction Rollback Integrity\n";
try {
    Database::beginTransaction();
    Database::execute("INSERT INTO `blocked_emails` (`pattern`, `reason`) VALUES ('test_rollback@example.com', 'Testing rollback')");
    $existsInTx = Database::fetchOne("SELECT * FROM `blocked_emails` WHERE `pattern` = 'test_rollback@example.com'");
    assertTest("Record inserted inside active transaction", $existsInTx !== null);

    Database::rollBack();
    $existsAfterRollback = Database::fetchOne("SELECT * FROM `blocked_emails` WHERE `pattern` = 'test_rollback@example.com'");
    assertTest("Record eliminated after rollback", $existsAfterRollback === null);
} catch (Throwable $e) {
    assertTest("Transaction rollback failed", false, $e->getMessage());
}

// 6. Transaction Commit Verification
echo "\nTest 6: Transaction Commit Integrity\n";
try {
    Database::transaction(function () {
        Database::execute("INSERT INTO `blocked_emails` (`pattern`, `reason`) VALUES ('test_commit@example.com', 'Testing commit')");
    });
    $existsAfterCommit = Database::fetchOne("SELECT * FROM `blocked_emails` WHERE `pattern` = 'test_commit@example.com'");
    assertTest("Record persisted after committed transaction", $existsAfterCommit !== null);

    // Clean up test record
    Database::execute("DELETE FROM `blocked_emails` WHERE `pattern` = 'test_commit@example.com'");
} catch (Throwable $e) {
    assertTest("Transaction commit failed", false, $e->getMessage());
}

// 7. Foreign Key Constraint Enforcement
echo "\nTest 7: Foreign Key Constraint Enforcement\n";
try {
    // Attempting to insert a ticket with a non-existent customer_id (e.g. 99999999) should trigger a PDO foreign key exception
    $fkFailed = false;
    try {
        Database::execute("INSERT INTO `tickets` (`ticket_number`, `customer_id`, `subject`, `description`) VALUES ('REQ-TEST-99999', 99999999, 'Test', 'Test')");
    } catch (PDOException $ex) {
        $fkFailed = true;
    }
    assertTest("Foreign key constraint blocked orphan ticket row", $fkFailed);
} catch (Throwable $e) {
    assertTest("FK test exception", false, $e->getMessage());
}

// 8. Unique Constraint Enforcement
echo "\nTest 8: Unique Constraint Enforcement\n";
try {
    $uniqueFailed = false;
    try {
        Database::execute("INSERT INTO `users` (`role`, `email`, `password_hash`, `first_name`, `last_name`) VALUES ('customer', 'admin@example.com', 'hash', 'Dup', 'User')");
    } catch (PDOException $ex) {
        $uniqueFailed = true;
    }
    assertTest("Duplicate email registration prevented by UNIQUE index", $uniqueFailed);
} catch (Throwable $e) {
    assertTest("Unique constraint test exception", false, $e->getMessage());
}

echo "\n============================================================\n";
echo " Test Results: {$passed} Passed, {$failed} Failed\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
