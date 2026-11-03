<?php
/**
 * Phase 2 Automated Integration Test Suite
 * 
 * Verifies:
 * - Customer registration & validation
 * - Duplicate registration prevention
 * - Email verification lifecycle
 * - Brute-force rate limiting & login logs
 * - Password reset lifecycle
 * - Ticket creation & safe sequence numbering
 * - Token generation & SHA-256 hashing
 * - Authorized sender association
 * - Threaded ticket message handling
 * - Outbound confirmation queuing
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../services/SecurityService.php';
require_once __DIR__ . '/../services/MailQueueService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TicketService.php';

echo "============================================================\n";
echo " Phase 2: User Authentication & Ticket Creation Test Suite\n";
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

// Clean up any test users from previous runs
$testEmail = 'john.doe.test@websitesting.shop';
Database::query("DELETE FROM `users` WHERE `email` = :email", ['email' => $testEmail]);
Database::query("DELETE FROM `login_logs` WHERE `email` = :email", ['email' => $testEmail]);

// -----------------------------------------------------------------------------
// Test 1: User Registration
// -----------------------------------------------------------------------------
echo "Test 1: User Registration & Password Hashing\n";
$regData = [
    'first_name'       => 'John',
    'last_name'        => 'Doe',
    'email'            => $testEmail,
    'password'         => 'SecurePassword123!',
    'password_confirm' => 'SecurePassword123!',
    'phone'            => '+1234567890'
];

$regResult = AuthService::register($regData);
assertTest("Registration succeeds", $regResult['success']);
$testUser = Database::fetchOne("SELECT * FROM `users` WHERE `email` = :email", ['email' => $testEmail]);
assertTest("User persisted in database", $testUser !== null);
assertTest("Role is customer", ($testUser['role'] ?? '') === 'customer');
assertTest("Status is active", ($testUser['status'] ?? '') === 'active');
assertTest("Password verified with bcrypt", password_verify('SecurePassword123!', $testUser['password_hash']));

// -----------------------------------------------------------------------------
// Test 2: Duplicate Registration Blocked
// -----------------------------------------------------------------------------
echo "\nTest 2: Duplicate Registration Prevention\n";
$dupResult = AuthService::register($regData);
assertTest("Duplicate email registration rejected", !$dupResult['success']);

// -----------------------------------------------------------------------------
// Test 3: Email Verification Lifecycle
// -----------------------------------------------------------------------------
echo "\nTest 3: Email Verification Lifecycle\n";
$verifyToken = $testUser['verification_token'];
assertTest("Verification token generated (64 hex chars)", strlen($verifyToken ?? '') === 64);

$verifyResult = AuthService::verifyEmail($verifyToken);
assertTest("Email verification succeeds", $verifyResult['success']);

$verifiedUser = Database::fetchOne("SELECT `is_verified`, `verification_token` FROM `users` WHERE `id` = :id", ['id' => $testUser['id']]);
assertTest("is_verified flag updated to 1", (int)$verifiedUser['is_verified'] === 1);
assertTest("Verification token cleared after redemption", empty($verifiedUser['verification_token']));

// -----------------------------------------------------------------------------
// Test 4: Brute-Force Rate Limiting
// -----------------------------------------------------------------------------
echo "\nTest 4: Brute-Force Defense & Login Logs\n";
$dummyEmail = 'attacker.test@websitesting.shop';
$dummyIp = '203.0.113.199'; // Test IP address

for ($i = 1; $i <= 5; $i++) {
    SecurityService::recordLoginAttempt($dummyEmail, $dummyIp, false, 'Invalid password');
}

$isLimited = SecurityService::isIpRateLimited($dummyIp, 5, 15);
assertTest("IP rate-limiting triggered after 5 failed attempts", $isLimited);

$isEmailLimited = SecurityService::isEmailRateLimited($dummyEmail, 5, 15);
assertTest("Target email rate-limiting triggered after 5 failed attempts", $isEmailLimited);

// -----------------------------------------------------------------------------
// Test 5: Successful Authentication & Session Rotation
// -----------------------------------------------------------------------------
echo "\nTest 5: Authentication Execution\n";
$loginResult = AuthService::login($testEmail, 'SecurePassword123!');
assertTest("Login succeeds with valid credentials", $loginResult['success']);

$postLoginUser = Database::fetchOne("SELECT `last_login_at`, `last_login_ip` FROM `users` WHERE `id` = :id", ['id' => $testUser['id']]);
assertTest("last_login_at updated on successful login", !empty($postLoginUser['last_login_at']));

$successLog = Database::fetchOne(
    "SELECT * FROM `login_logs` WHERE `email` = :email AND `status` = 'success' ORDER BY `id` DESC LIMIT 1",
    ['email' => $testEmail]
);
assertTest("Login success audit recorded in login_logs", $successLog !== null);

// -----------------------------------------------------------------------------
// Test 6: Password Reset Lifecycle
// -----------------------------------------------------------------------------
echo "\nTest 6: Password Reset Lifecycle\n";
$resetInit = AuthService::initiatePasswordReset($testEmail);
assertTest("Password reset initiated", $resetInit['success']);

$userWithReset = Database::fetchOne("SELECT `reset_token_hash`, `reset_token_expires_at` FROM `users` WHERE `id` = :id", ['id' => $testUser['id']]);
assertTest("Reset token hash stored (SHA-256)", strlen($userWithReset['reset_token_hash'] ?? '') === 64);

// Find queued reset email to extract token for test execution
$queuedReset = Database::fetchOne(
    "SELECT `body_plain` FROM `email_queue` WHERE `recipient_email` = :email AND `subject` LIKE '%Password Reset%' ORDER BY `id` DESC LIMIT 1",
    ['email' => $testEmail]
);
assertTest("Password reset email enqueued", $queuedReset !== null);

preg_match('/token=([a-f0-9]{64})/', $queuedReset['body_plain'] ?? '', $matches);
$extractedToken = $matches[1] ?? '';
assertTest("Token extracted from queued notification", !empty($extractedToken));

$resetExec = AuthService::resetPassword($extractedToken, 'NewBrandSecurePass999!');
assertTest("Password reset execution succeeds", $resetExec['success']);

$reloginOld = AuthService::login($testEmail, 'SecurePassword123!');
assertTest("Old password rejected after reset", !$reloginOld['success']);

$reloginNew = AuthService::login($testEmail, 'NewBrandSecurePass999!');
assertTest("New password accepted after reset", $reloginNew['success']);

// -----------------------------------------------------------------------------
// Test 7: Ticket Creation & Sequence Numbering
// -----------------------------------------------------------------------------
echo "\nTest 7: Ticket Creation & Security Credentials\n";
$ticketResult = TicketService::createTicket(
    (int)$testUser['id'],
    'Cannot access server via SFTP',
    'Connection times out on port 22 after 30 seconds. Please check firewall rules.',
    'high',
    'Technical'
);

assertTest("Ticket created successfully", $ticketResult['success']);
$ticketId = $ticketResult['ticket_id'];
$ticketNum = $ticketResult['ticket_number'];
$replyAddress = $ticketResult['reply_address'];

assertTest("Human-readable ticket number generated (REQ-YYYY-XXXXX)", (bool)preg_match('/^REQ-\d{4}-\d{5}$/', $ticketNum), "Ticket Number: {$ticketNum}");
assertTest("Reply address matches configured format", str_contains($replyAddress, config('mail.reply_routing.prefix', 'reply+')));

$dbTicket = Database::fetchOne("SELECT * FROM `tickets` WHERE `id` = :id", ['id' => $ticketId]);
assertTest("Ticket stored in database with status 'open'", ($dbTicket['status'] ?? '') === 'open');
assertTest("Priority stored as 'high'", ($dbTicket['priority'] ?? '') === 'high');

// -----------------------------------------------------------------------------
// Test 8: Secure Reply Token Storage
// -----------------------------------------------------------------------------
echo "\nTest 8: Secure Reply Token Generation & Hashing\n";
$dbToken = Database::fetchOne("SELECT * FROM `email_tokens` WHERE `ticket_id` = :ticket_id", ['ticket_id' => $ticketId]);
assertTest("Token record exists in email_tokens table", $dbToken !== null);
assertTest("Token stored strictly as SHA-256 hash (64 chars)", strlen($dbToken['token_hash'] ?? '') === 64);
assertTest("Token prefix stored (8 chars)", strlen($dbToken['token_prefix'] ?? '') === 8);
assertTest("Token is active (not revoked)", (int)$dbToken['is_revoked'] === 0);

// -----------------------------------------------------------------------------
// Test 9: Authorized Sender & Messages
// -----------------------------------------------------------------------------
echo "\nTest 9: Authorized Sender Association & Message Threading\n";
$authEmail = Database::fetchOne("SELECT * FROM `authorized_ticket_emails` WHERE `ticket_id` = :ticket_id", ['ticket_id' => $ticketId]);
assertTest("Customer registered in authorized_ticket_emails", ($authEmail['email'] ?? '') === $testEmail);
assertTest("Authorized role is 'owner'", ($authEmail['role'] ?? '') === 'owner');

$messages = TicketService::getTicketMessages($ticketId);
assertTest("Initial request message recorded", count($messages) === 1);
assertTest("Initial message sender_type is 'customer'", ($messages[0]['sender_type'] ?? '') === 'customer');

// Append a customer reply
$newMsgId = TicketService::addMessage(
    $ticketId,
    (int)$testUser['id'],
    'customer',
    $testEmail,
    'John Doe',
    'Follow up: I tried from a different IP and got connection refused.',
    false,
    'web'
);
assertTest("Customer reply added to ticket", $newMsgId > 0);

$messagesAfter = TicketService::getTicketMessages($ticketId);
assertTest("Ticket messages count is now 2", count($messagesAfter) === 2);

// -----------------------------------------------------------------------------
// Test 10: Confirmation Email Queued
// -----------------------------------------------------------------------------
echo "\nTest 10: Confirmation Outgoing Email Queue\n";
$queuedConfirm = Database::fetchOne(
    "SELECT * FROM `email_queue` WHERE `ticket_id` = :ticket_id AND `recipient_email` = :email",
    ['ticket_id' => $ticketId, 'email' => $testEmail]
);
assertTest("Ticket confirmation email present in email_queue", $queuedConfirm !== null);
assertTest("Queue status is 'pending'", ($queuedConfirm['status'] ?? '') === 'pending');
assertTest("Queue contains custom ticket header", str_contains($queuedConfirm['custom_headers'] ?? '', $ticketNum));

echo "\n============================================================\n";
echo " Test Results: {$passed} Passed, {$failed} Failed\n";
echo "============================================================\n";

// Clean up test records
Database::query("DELETE FROM `tickets` WHERE `id` = :id", ['id' => $ticketId]);
Database::query("DELETE FROM `users` WHERE `id` = :id", ['id' => $testUser['id']]);
Database::query("DELETE FROM `login_logs` WHERE `email` = :email", ['email' => $testEmail]);
Database::query("DELETE FROM `email_queue` WHERE `recipient_email` = :email", ['email' => $testEmail]);
Database::query("DELETE FROM `email_logs` WHERE `recipient_email` = :email", ['email' => $testEmail]);

if ($failed > 0) {
    exit(1);
}
