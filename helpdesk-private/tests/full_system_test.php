<?php
/**
 * Master Full System End-to-End Test Suite
 * 
 * Verifies all services, models, workflows, endpoints, security checks, and token lifecycle.
 */

declare(strict_types=1);

define('HELPDESK_TEST_MODE', true);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../services/SecurityService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/TicketService.php';
require_once __DIR__ . '/../services/MailQueueService.php';
require_once __DIR__ . '/../services/EmailValidationService.php';
require_once __DIR__ . '/../mail/MIMEParser.php';
require_once __DIR__ . '/../mail/SMTPClient.php';
require_once __DIR__ . '/../mail/IMAPClient.php';

echo "============================================================\n";
echo " Master Full System End-to-End Comprehensive Test Suite\n";
echo " Domain: " . config('mail.reply_routing.domain') . " | Admin: " . config('app.admin_email') . "\n";
echo "============================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $condition, ?string $detail = null): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  [PASS] {$desc}\n";
    } else {
        $failed++;
        echo "  [FAIL] {$desc}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// -------------------------------------------------------------
// Suite 1: Database & Configuration
// -------------------------------------------------------------
echo "--- Section 1: Configuration & Environment Integrity ---\n";
assertCheck("Admin email is mail@websitesting.shop", config('app.admin_email') === 'mail@websitesting.shop');
assertCheck("SMTP Host is websitesting.shop", config('mail.smtp.host') === 'websitesting.shop');
assertCheck("SMTP Port is 465", (int)config('mail.smtp.port') === 465);
assertCheck("SMTP Encryption is ssl", strtolower((string)config('mail.smtp.encryption')) === 'ssl');
assertCheck("IMAP Host is websitesting.shop", config('mail.imap.host') === 'websitesting.shop');
assertCheck("IMAP Port is 993", (int)config('mail.imap.port') === 993);
assertCheck("Reply Domain is websitesting.shop", config('mail.reply_routing.domain') === 'websitesting.shop');
assertCheck("Reply Prefix is reply+", config('mail.reply_routing.prefix') === 'reply+');
assertCheck("Rate limit decay is 1 minute", (int)config('app.auth.rate_limit_decay_minutes') === 1);

// -------------------------------------------------------------
// Suite 2: Security & Password Hashing
// -------------------------------------------------------------
echo "\n--- Section 2: Security & CSRF Defense ---\n";
AuthService::startSecureSession();
$csrf1 = SecurityService::generateCsrfToken();
assertCheck("CSRF token generated (64 hex chars)", strlen($csrf1) === 64);
assertCheck("CSRF token validation succeeds for matching token", SecurityService::validateCsrfToken($csrf1));
assertCheck("CSRF token validation fails for tampered token", !SecurityService::validateCsrfToken('tampered_token_value'));
assertCheck("IP rate-limiting returns false for fresh IP", !SecurityService::isIpRateLimited('198.51.100.77', 5, 1));

// -------------------------------------------------------------
// Suite 3: User Authentication Lifecycle
// -------------------------------------------------------------
echo "\n--- Section 3: Authentication & Verification Lifecycle ---\n";
$customerEmail = 'fulltest.customer.test@websitesting.shop';
Database::query("DELETE FROM `users` WHERE `email` = :email", ['email' => $customerEmail]);

$regResult = AuthService::register([
    'first_name'       => 'Master',
    'last_name'        => 'Tester',
    'email'            => $customerEmail,
    'password'         => 'ComplexPassword123!',
    'password_confirm' => 'ComplexPassword123!',
    'phone'            => '1234567890'
]);
assertCheck("User registration succeeds", $regResult['success']);
$customerUser = Database::fetchOne("SELECT * FROM `users` WHERE `email` = :email", ['email' => $customerEmail]);
assertCheck("Customer record created", $customerUser !== null);
$customerId = (int)$customerUser['id'];

// Verify email
$verifyToken = $customerUser['verification_token'];
$verifyRes = AuthService::verifyEmail($verifyToken);
assertCheck("Email verification succeeds", $verifyRes['success']);
$verifiedCheck = Database::fetchOne("SELECT is_verified FROM `users` WHERE `id` = :id", ['id' => $customerId]);
assertCheck("User is verified in database", (int)$verifiedCheck['is_verified'] === 1);

// Login
$loginRes = AuthService::login($customerEmail, 'ComplexPassword123!');
assertCheck("Customer login succeeds", $loginRes['success']);
$wrongLogin = AuthService::login($customerEmail, 'WrongPassword!');
assertCheck("Customer login fails on bad credentials", !$wrongLogin['success']);

// Password Reset
$resetInit = AuthService::initiatePasswordReset($customerEmail);
assertCheck("Password reset initiated", $resetInit['success']);
$resetUser = Database::fetchOne("SELECT reset_token_hash FROM `users` WHERE `id` = :id", ['id' => $customerId]);
assertCheck("Reset token hash stored", !empty($resetUser['reset_token_hash']));

// -------------------------------------------------------------
// Suite 4: Ticket Creation & Token Cryptography
// -------------------------------------------------------------
echo "\n--- Section 4: Ticket Creation, Numbering & Reply Routing ---\n";
$ticketRes = TicketService::createTicket($customerId, 'Master Test Problem', 'Detailed issue description for full test', 'high', 'Technical');
assertCheck("Ticket creation succeeds", $ticketRes['success']);
$ticketId = $ticketRes['ticket_id'];
$ticketNum = $ticketRes['ticket_number'];
$rawToken = $ticketRes['raw_token'];
$replyAddress = $ticketRes['reply_address'];

assertCheck("Ticket number matches REQ-YYYY-XXXXX format", (bool)preg_match('/^REQ-\d{4}-\d{5}$/', $ticketNum));
assertCheck("Reply address has prefix 'reply+' and domain 'websitesting.shop'", str_starts_with($replyAddress, 'reply+') && str_ends_with($replyAddress, '@websitesting.shop'));
assertCheck("Local-part length of reply address <= 64 characters (RFC 5321)", strlen(explode('@', $replyAddress)[0]) <= 64);

// Token persistence
$tokenRecord = Database::fetchOne("SELECT * FROM `email_tokens` WHERE `ticket_id` = :tid", ['tid' => $ticketId]);
assertCheck("Email token stored in database", $tokenRecord !== null);
assertCheck("Token stored as SHA-256 hash (64 hex characters)", strlen($tokenRecord['token_hash']) === 64);
assertCheck("Stored hash matches sha256(raw_token)", $tokenRecord['token_hash'] === hash('sha256', $rawToken));

// Authorized sender whitelist
$authRecord = Database::fetchOne("SELECT * FROM `authorized_ticket_emails` WHERE `ticket_id` = :tid AND `email` = :email", ['tid' => $ticketId, 'email' => $customerEmail]);
assertCheck("Customer added to authorized_ticket_emails", $authRecord !== null);
assertCheck("Authorized role is 'owner'", ($authRecord['role'] ?? '') === 'owner');

// -------------------------------------------------------------
// Suite 5: Status Management & Reopening
// -------------------------------------------------------------
echo "\n--- Section 5: Ticket Status Transitions & History ---\n";
Database::query("UPDATE `tickets` SET `status` = 'pending' WHERE `id` = :id", ['id' => $ticketId]);
$checkPending = Database::fetchOne("SELECT `status` FROM `tickets` WHERE `id` = :id", ['id' => $ticketId]);
assertCheck("Ticket status updated to pending", $checkPending['status'] === 'pending');

Database::query("UPDATE `tickets` SET `status` = 'closed' WHERE `id` = :id", ['id' => $ticketId]);
$checkClosed = Database::fetchOne("SELECT `status` FROM `tickets` WHERE `id` = :id", ['id' => $ticketId]);
assertCheck("Ticket status updated to closed", $checkClosed['status'] === 'closed');

// Customer adds a message
$newMsgId = TicketService::addMessage($ticketId, $customerId, 'customer', $customerEmail, 'Master Tester', 'Followup query on closed ticket');
assertCheck("Followup message added to ticket", $newMsgId > 0);

// Restore ticket status to 'open' for subsequent reply acceptance tests
Database::query("UPDATE `tickets` SET `status` = 'open' WHERE `id` = :id", ['id' => $ticketId]);

// -------------------------------------------------------------
// Suite 6: Email Validation Engine (Inbound Rules)
// -------------------------------------------------------------
echo "\n--- Section 6: Inbound Email Validation Engine ---\n";
$tokenInfo = TicketService::generateReplyTokenForTicket($ticketId, $customerId);
$activeRawToken = $tokenInfo['raw_token'];
$activeReplyAddr = $tokenInfo['reply_address'];

// Case 1: Valid reply
$validMsg = [
    'from_email'        => $customerEmail,
    'to_email'          => $activeReplyAddr,
    'subject'           => "Re: [{$ticketNum}] Master Test Problem",
    'message_id_header' => '<fulltest-valid-01@websitesting.shop>',
    'body_plain'        => 'Here is my update.'
];
$val1 = EmailValidationService::validateInboundMessage($validMsg);
assertCheck("Valid reply accepted", $val1['result'] === EmailValidationService::RESULT_ACCEPTED);

// Case 2: Unauthorized sender
$unauthMsg = [
    'from_email'        => 'imposter.hacker@badactor.com',
    'to_email'          => $activeReplyAddr,
    'subject'           => "Re: [{$ticketNum}] Master Test Problem",
    'message_id_header' => '<fulltest-unauth-02@websitesting.shop>',
    'body_plain'        => 'Trying to inject reply.'
];
$val2 = EmailValidationService::validateInboundMessage($unauthMsg);
assertCheck("Unauthorized sender rejected", $val2['result'] === EmailValidationService::RESULT_REJECTED);

// Case 3: Tampered token
$tamperedMsg = [
    'from_email'        => $customerEmail,
    'to_email'          => "reply+00000000000000000000000000000000@websitesting.shop",
    'subject'           => "Re: [{$ticketNum}] Master Test Problem",
    'message_id_header' => '<fulltest-tamper-03@websitesting.shop>',
    'body_plain'        => 'Tampered token test.'
];
$val3 = EmailValidationService::validateInboundMessage($tamperedMsg);
assertCheck("Non-existent token rejected", $val3['result'] === EmailValidationService::RESULT_REJECTED);

// Case 4: Duplicate Message-ID
Database::query(
    "INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `sender_type`, `sender_email`, `body_plain`, `source`, `message_id_header`, `created_at`) 
     VALUES (:tid, :uid, 'customer', :email, 'Existing msg', 'email', 'dup-fulltest-msg-99', NOW())",
    ['tid' => $ticketId, 'uid' => $customerId, 'email' => $customerEmail]
);
$dupMsg = [
    'from_email'        => $customerEmail,
    'to_email'          => $activeReplyAddr,
    'subject'           => "Re: [{$ticketNum}] Master Test Problem",
    'message_id_header' => 'dup-fulltest-msg-99',
    'body_plain'        => 'Duplicate attempt.'
];
$val4 = EmailValidationService::validateInboundMessage($dupMsg);
assertCheck("Duplicate Message-ID ignored", $val4['result'] === EmailValidationService::RESULT_IGNORED_DUPLICATE);

// -------------------------------------------------------------
// Suite 7: MIME Parsing & Quote Stripping
// -------------------------------------------------------------
echo "\n--- Section 7: MIME Parser & Quoting Stripper ---\n";
$rawQuoteEmail = "Fresh customer response text.\n\n"
               . "On Mon, Nov 2, 2026 at 4:30 PM Support Desk <mail@websitesting.shop> wrote:\n"
               . "> Previous support agent reply line 1\n"
               . "> Previous support agent reply line 2";
$stripped = MIMEParser::stripQuotes($rawQuoteEmail);
assertCheck("Fresh reply preserved", str_contains($stripped, 'Fresh customer response text.'));
assertCheck("Quoted text stripped", !str_contains($stripped, 'Previous support agent reply'));

// -------------------------------------------------------------
// Suite 8: Mail Queue Dispatching
// -------------------------------------------------------------
echo "\n--- Section 8: Database-First Outbound Queue ---\n";
$qid = MailQueueService::enqueue(
    $customerEmail,
    'Master Tester',
    'Test Queue Dispatch',
    'Test body plain text'
);
assertCheck("Email enqueued in database", $qid > 0);
$qItem = Database::fetchOne("SELECT * FROM `email_queue` WHERE `id` = :id", ['id' => $qid]);
assertCheck("Queue status is 'sent' (via immediate dispatch) or 'pending'", in_array($qItem['status'], ['sent', 'pending'], true));

// -------------------------------------------------------------
// Suite 9: Web Endpoints Verification
// -------------------------------------------------------------
echo "\n--- Section 9: Frontend Web Endpoints Existence ---\n";
$webRoot = dirname(__DIR__, 2) . '/helpdesk';
$requiredEndpoints = [
    'index.php',
    'login.php',
    'register.php',
    'logout.php',
    'dashboard.php',
    'create-ticket.php',
    'tickets.php',
    'ticket-view.php',
    'forgot-password.php',
    'reset-password.php',
    'verify-email.php',
    'includes/header.php',
    'includes/footer.php',
    '.htaccess'
];

foreach ($requiredEndpoints as $ep) {
    assertCheck("Endpoint exists: helpdesk/{$ep}", file_exists($webRoot . '/' . $ep));
}

// -------------------------------------------------------------
// Clean up Test Records
// -------------------------------------------------------------
Database::query("DELETE FROM `tickets` WHERE `id` = :id", ['id' => $ticketId]);
Database::query("DELETE FROM `users` WHERE `id` = :id", ['id' => $customerId]);
Database::query("DELETE FROM `login_logs` WHERE `email` = :email", ['email' => $customerEmail]);
Database::query("DELETE FROM `email_queue` WHERE `recipient_email` = :email", ['email' => $customerEmail]);
Database::query("DELETE FROM `email_logs` WHERE `recipient_email` = :email", ['email' => $customerEmail]);

echo "\n============================================================\n";
echo " Full System Test Results: {$passed} Passed, {$failed} Failed\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
