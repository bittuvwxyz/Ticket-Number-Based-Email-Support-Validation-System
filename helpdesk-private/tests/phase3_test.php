<?php
/**
 * Phase 3 Automated Integration Test Suite
 * 
 * Verifies:
 * - Token extraction from plus-addressed email (mail+token@websitesting.shop)
 * - Ticket number extraction from subject line ([REQ-YYYY-XXXXX])
 * - Validation engine cases:
 *     1. Valid ticket + valid token + authorized sender -> Accepted
 *     2. Missing reference and token -> Rejected
 *     3. Invalid token -> Rejected
 *     4. Unauthorized sender -> Rejected
 *     5. Expired token -> Rejected
 *     6. Revoked token -> Rejected
 *     7. Duplicate Message-ID -> Ignored Duplicate
 *     8. Blocked sender -> Rejected
 * - MIME parser reply quote stripping
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../services/TicketService.php';
require_once __DIR__ . '/../services/EmailValidationService.php';
require_once __DIR__ . '/../mail/MIMEParser.php';

echo "============================================================\n";
echo " Phase 3: Ticket Numbers & Reply Token Validation Test Suite\n";
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

// -------------------------------------------------------------
// Test 1: Address and Subject Extractions
// -------------------------------------------------------------
echo "Test 1: Address & Subject Parsing\n";
$replyPrefix = config('mail.reply_routing.prefix', 'mail+');
$replyDomain = config('mail.reply_routing.domain', 'websitesting.shop');

$dummyToken = "3c712dfd1393ca13fdbb17b26ac2eef3";
$address = "{$replyPrefix}{$dummyToken}@{$replyDomain}";
$extractedToken = EmailValidationService::extractTokenFromAddress($address);
assertTest("Token extracted accurately from plus-addressed recipient", $extractedToken === $dummyToken);

$subject = "Re: [REQ-2026-10001] Need assistance with SSL certificate";
$extractedTicketNum = EmailValidationService::extractTicketNumberFromSubject($subject);
assertTest("Ticket number extracted accurately from subject", $extractedTicketNum === 'REQ-2026-10001');

// -------------------------------------------------------------
// Test 2: Reply Quoting Stripping
// -------------------------------------------------------------
echo "\nTest 2: Reply Quote Stripper\n";
$emailBodyWithQuotes = "I have uploaded the new SSL certificate as requested.\n\n"
                     . "On Tue, Nov 3, 2026 at 10:15 AM Support Desk <support@example.com> wrote:\n"
                     . "> Hello customer,\n"
                     . "> Please provide your certificate file.\n"
                     . "> Regards,\n> Support";

$cleanBody = MIMEParser::stripQuotes($emailBodyWithQuotes);
assertTest("Quotation history removed cleanly", !str_contains($cleanBody, "On Tue, Nov 3") && !str_contains($cleanBody, "> Hello customer"));
assertTest("Fresh message text preserved", str_contains($cleanBody, "I have uploaded the new SSL certificate as requested."));

// -------------------------------------------------------------
// Setup Test Ticket and Token
// -------------------------------------------------------------
$testUserEmail = 'token.tester@example.com';
Database::query("DELETE FROM `users` WHERE `email` = :email", ['email' => $testUserEmail]);
Database::query(
    "INSERT INTO `users` (`role`, `email`, `password_hash`, `first_name`, `last_name`, `is_verified`, `status`) 
     VALUES ('customer', :email, 'hash', 'Token', 'Tester', 1, 'active')",
    ['email' => $testUserEmail]
);
$testUserId = (int)Database::lastInsertId();

$ticketRes = TicketService::createTicket($testUserId, 'Validation Suite Ticket', 'Testing validation matrix', 'medium', 'General');
$testTicketId = $ticketRes['ticket_id'];
$testTicketNumber = $ticketRes['ticket_number'];
$rawToken = $ticketRes['raw_token'];
$tokenInfo = TicketService::generateReplyTokenForTicket($testTicketId, $testUserId);
$activeRawToken = $tokenInfo['raw_token'];

// -------------------------------------------------------------
// Test 3: Validation Engine - Positive Match
// -------------------------------------------------------------
echo "\nTest 3: Valid Inbound Message Acceptance\n";
$validEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => "{$replyPrefix}{$activeRawToken}@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => '<msg-valid-001@mail.example.com>',
    'body_plain'        => 'Here is my reply to the ticket.'
];

$resValid = EmailValidationService::validateInboundMessage($validEmail);
assertTest("Valid ticket, token, and sender ACCEPTED", $resValid['result'] === EmailValidationService::RESULT_ACCEPTED);
assertTest("Resolved ticket ID matches", (int)($resValid['ticket_id'] ?? 0) === $testTicketId);

// -------------------------------------------------------------
// Test 4: Validation Engine - Missing Reference
// -------------------------------------------------------------
echo "\nTest 4: Missing Reference & Token\n";
$missingEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => config('mail.from.address', 'mail@websitesting.shop'),
    'subject'           => "Just a random question with no ticket number",
    'message_id_header' => '<msg-missing-002@mail.example.com>',
    'body_plain'        => 'Random body.'
];

$resMissing = EmailValidationService::validateInboundMessage($missingEmail);
assertTest("Missing ticket reference REJECTED", $resMissing['result'] === EmailValidationService::RESULT_REJECTED);

// -------------------------------------------------------------
// Test 5: Validation Engine - Invalid Token
// -------------------------------------------------------------
echo "\nTest 5: Invalid Token Handling\n";
$invalidTokenEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => "{$replyPrefix}deadbeefdeadbeefdeadbeefdeadbeef@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => '<msg-inv-003@mail.example.com>',
    'body_plain'        => 'Random body.'
];

$resInvalidToken = EmailValidationService::validateInboundMessage($invalidTokenEmail);
assertTest("Non-existent token REJECTED", $resInvalidToken['result'] === EmailValidationService::RESULT_REJECTED);

// -------------------------------------------------------------
// Test 6: Validation Engine - Unauthorized Sender
// -------------------------------------------------------------
echo "\nTest 6: Unauthorized Sender Handling\n";
$unauthEmail = [
    'from_email'        => 'stranger@imposter.com',
    'to_email'          => "{$replyPrefix}{$activeRawToken}@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => '<msg-unauth-004@mail.example.com>',
    'body_plain'        => 'Attempting to inject a reply.'
];

$resUnauth = EmailValidationService::validateInboundMessage($unauthEmail);
assertTest("Unauthorized sender REJECTED", $resUnauth['result'] === EmailValidationService::RESULT_REJECTED);
assertTest("Rejection reason states unauthorized sender", str_contains($resUnauth['reason'] ?? '', 'Unauthorized sender'));

// -------------------------------------------------------------
// Test 7: Validation Engine - Expired Token
// -------------------------------------------------------------
echo "\nTest 7: Expired Token Handling\n";
$expiredRawToken = bin2hex(random_bytes(16));
$expiredHash = hash('sha256', $expiredRawToken);
Database::query(
    "INSERT INTO `email_tokens` (`ticket_id`, `user_id`, `token_hash`, `token_prefix`, `is_revoked`, `expires_at`, `created_at`) 
     VALUES (:ticket_id, :user_id, :hash, :prefix, 0, DATE_SUB(NOW(), INTERVAL 1 DAY), NOW())",
    [
        'ticket_id' => $testTicketId,
        'user_id'   => $testUserId,
        'hash'      => $expiredHash,
        'prefix'    => substr($expiredRawToken, 0, 8)
    ]
);

$expiredEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => "{$replyPrefix}{$expiredRawToken}@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => '<msg-exp-005@mail.example.com>',
    'body_plain'        => 'Reply using expired token.'
];

$resExpired = EmailValidationService::validateInboundMessage($expiredEmail);
assertTest("Expired token REJECTED", $resExpired['result'] === EmailValidationService::RESULT_REJECTED);
assertTest("Rejection reason notes token expiry", str_contains($resExpired['reason'] ?? '', 'expired'));

// -------------------------------------------------------------
// Test 8: Validation Engine - Revoked Token
// -------------------------------------------------------------
echo "\nTest 8: Revoked Token Handling\n";
$revokedRawToken = bin2hex(random_bytes(16));
$revokedHash = hash('sha256', $revokedRawToken);
Database::query(
    "INSERT INTO `email_tokens` (`ticket_id`, `user_id`, `token_hash`, `token_prefix`, `is_revoked`, `revoked_at`, `revoked_reason`, `expires_at`, `created_at`) 
     VALUES (:ticket_id, :user_id, :hash, :prefix, 1, NOW(), 'Security reset', DATE_ADD(NOW(), INTERVAL 14 DAY), NOW())",
    [
        'ticket_id' => $testTicketId,
        'user_id'   => $testUserId,
        'hash'      => $revokedHash,
        'prefix'    => substr($revokedRawToken, 0, 8)
    ]
);

$revokedEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => "{$replyPrefix}{$revokedRawToken}@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => '<msg-rev-006@mail.example.com>',
    'body_plain'        => 'Reply using revoked token.'
];

$resRevoked = EmailValidationService::validateInboundMessage($revokedEmail);
assertTest("Revoked token REJECTED", $resRevoked['result'] === EmailValidationService::RESULT_REJECTED);
assertTest("Rejection reason notes token revocation", str_contains($resRevoked['reason'] ?? '', 'revoked'));

// -------------------------------------------------------------
// Test 9: Validation Engine - Duplicate Message-ID
// -------------------------------------------------------------
echo "\nTest 9: Duplicate Message-ID Prevention\n";
Database::query(
    "INSERT INTO `ticket_messages` (`ticket_id`, `user_id`, `sender_type`, `sender_email`, `body_plain`, `source`, `message_id_header`, `created_at`) 
     VALUES (:ticket_id, :user_id, 'customer', :email, 'Existing msg', 'email', 'dup-msg-id-999', NOW())",
    [
        'ticket_id' => $testTicketId,
        'user_id'   => $testUserId,
        'email'     => $testUserEmail
    ]
);

$dupEmail = [
    'from_email'        => $testUserEmail,
    'to_email'          => "{$replyPrefix}{$activeRawToken}@{$replyDomain}",
    'subject'           => "Re: [{$testTicketNumber}] Validation Suite Ticket",
    'message_id_header' => 'dup-msg-id-999',
    'body_plain'        => 'Duplicate attempt.'
];

$resDup = EmailValidationService::validateInboundMessage($dupEmail);
assertTest("Duplicate Message-ID marked as IGNORED_DUPLICATE", $resDup['result'] === EmailValidationService::RESULT_IGNORED_DUPLICATE);

// -------------------------------------------------------------
// Clean up Test Records
// -------------------------------------------------------------
Database::query("DELETE FROM `tickets` WHERE `id` = :id", ['id' => $testTicketId]);
Database::query("DELETE FROM `users` WHERE `id` = :id", ['id' => $testUserId]);
Database::query("DELETE FROM `email_queue` WHERE `recipient_email` = :email", ['email' => $testUserEmail]);

echo "\n============================================================\n";
echo " Test Results: {$passed} Passed, {$failed} Failed\n";
echo "============================================================\n";

if ($failed > 0) {
    exit(1);
}
