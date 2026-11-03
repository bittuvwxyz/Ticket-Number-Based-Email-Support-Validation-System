<?php
/**
 * Email Validation Engine & Token Verifier
 * 
 * Centralized service to validate incoming emails according to:
 * - Reply token validity and cryptographic SHA-256 match
 * - Expiration and revocation checks
 * - Sender authorization against ticket whitelist
 * - Ticket status and closed-ticket policies
 * - RFC Message-ID duplicate prevention
 * - RFC header and ticket number extraction
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/SecurityService.php';

class EmailValidationService
{
    public const RESULT_ACCEPTED          = 'accepted';
    public const RESULT_REJECTED          = 'rejected';
    public const RESULT_QUARANTINED       = 'quarantined';
    public const RESULT_IGNORED_DUPLICATE = 'ignored_duplicate';
    public const RESULT_ERROR             = 'error';

    /**
     * Extracts token from recipient email address.
     * Example: mail+3c712dfd1393ca13fdbb17b26ac2eef3@websitesting.shop -> 3c712dfd1393ca13fdbb17b26ac2eef3
     */
    public static function extractTokenFromAddress(string $toAddress): ?string
    {
        if (preg_match('/\+([a-f0-9]{32,64})@/i', $toAddress, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    /**
     * Extracts ticket reference number from subject line.
     * Example: "Re: [REQ-2026-10001] Server issue" -> "REQ-2026-10001"
     */
    public static function extractTicketNumberFromSubject(string $subject): ?string
    {
        $prefix = config('app.ticket.prefix', 'REQ');
        $pattern = '/\b(' . preg_quote($prefix, '/') . '-\d{4}-\d{5})\b/i';
        if (preg_match($pattern, $subject, $matches)) {
            return strtoupper($matches[1]);
        }
        return null;
    }

    /**
     * Centralized validation decision engine for an incoming message.
     * 
     * @param array $emailData [
     *     'from_email'        => string,
     *     'to_email'          => string,
     *     'subject'           => string,
     *     'message_id_header' => ?string,
     *     'raw_headers'       => ?string,
     *     'body_plain'        => string
     * ]
     * @return array [
     *     'result'    => string (accepted|rejected|quarantined|ignored_duplicate),
     *     'reason'    => ?string,
     *     'ticket_id' => ?int,
     *     'ticket'    => ?array,
     *     'user_id'   => ?int
     * ]
     */
    public static function validateInboundMessage(array $emailData): array
    {
        $fromEmail = strtolower(trim($emailData['from_email'] ?? ''));
        $toEmail = strtolower(trim($emailData['to_email'] ?? ''));
        $subject = trim($emailData['subject'] ?? '');
        $messageId = trim($emailData['message_id_header'] ?? '');
        $token = self::extractTokenFromAddress($toEmail);
        $ticketNumber = self::extractTicketNumberFromSubject($subject);

        // 1. Check for blocked/spam senders
        $isBlocked = Database::fetchOne(
            "SELECT `id`, `reason` FROM `blocked_emails` WHERE `pattern` = :email1 OR :email2 LIKE REPLACE(`pattern`, '*', '%')",
            ['email1' => $fromEmail, 'email2' => $fromEmail]
        );
        if ($isBlocked) {
            return [
                'result'        => self::RESULT_REJECTED,
                'reason'        => 'Sender is in blocked emails list: ' . ($isBlocked['reason'] ?? 'Spam'),
                'ticket_id'     => null,
                'ticket_number' => $ticketNumber,
                'token_prefix'  => $token ? substr($token, 0, 8) : null
            ];
        }

        // 2. Check for duplicate Message-ID
        if (!empty($messageId)) {
            $duplicate = Database::fetchOne(
                "SELECT `id`, `ticket_id` FROM `ticket_messages` WHERE `message_id_header` = :msg_id",
                ['msg_id' => $messageId]
            );
            if ($duplicate) {
                return [
                    'result'        => self::RESULT_IGNORED_DUPLICATE,
                    'reason'        => 'Duplicate Message-ID already processed',
                    'ticket_id'     => (int)$duplicate['ticket_id'],
                    'ticket_number' => $ticketNumber,
                    'token_prefix'  => $token ? substr($token, 0, 8) : null
                ];
            }
        }

        // 3. Token & Ticket Reference Resolution
        $tokenRow = null;
        if (!empty($token)) {
            $tokenHash = hash('sha256', $token);
            $tokenRow = Database::fetchOne(
                "SELECT * FROM `email_tokens` WHERE `token_hash` = :hash",
                ['hash' => $tokenHash]
            );
        }

        // Fallback: Check ticket number if token is missing
        $ticket = null;
        if ($tokenRow) {
            $ticket = Database::fetchOne("SELECT * FROM `tickets` WHERE `id` = :id", ['id' => $tokenRow['ticket_id']]);
        } elseif (!empty($ticketNumber)) {
            $ticket = Database::fetchOne("SELECT * FROM `tickets` WHERE `ticket_number` = :num", ['num' => $ticketNumber]);
        }

        // If ticket cannot be identified
        if (!$ticket) {
            if (empty($ticketNumber) && empty($token)) {
                return [
                    'result'        => self::RESULT_REJECTED,
                    'reason'        => 'Missing ticket reference and reply token',
                    'ticket_id'     => null,
                    'ticket_number' => null,
                    'token_prefix'  => null
                ];
            }
            return [
                'result'        => self::RESULT_REJECTED,
                'reason'        => 'Ticket reference not found in database',
                'ticket_id'     => null,
                'ticket_number' => $ticketNumber,
                'token_prefix'  => $token ? substr($token, 0, 8) : null
            ];
        }

        $ticketId = (int)$ticket['id'];
        $resolvedTicketNumber = $ticket['ticket_number'];

        // 4. Token validation (if token provided)
        if ($token && !$tokenRow) {
            return [
                'result'        => self::RESULT_REJECTED,
                'reason'        => 'Invalid or non-existent reply token',
                'ticket_id'     => $ticketId,
                'ticket_number' => $resolvedTicketNumber,
                'token_prefix'  => substr($token, 0, 8)
            ];
        }

        if ($tokenRow) {
            if ((int)$tokenRow['is_revoked'] === 1) {
                return [
                    'result'        => self::RESULT_REJECTED,
                    'reason'        => 'Reply token has been revoked: ' . ($tokenRow['revoked_reason'] ?? 'Revoked by admin'),
                    'ticket_id'     => $ticketId,
                    'ticket_number' => $resolvedTicketNumber,
                    'token_prefix'  => $tokenRow['token_prefix']
                ];
            }

            if (strtotime($tokenRow['expires_at']) < time()) {
                return [
                    'result'        => self::RESULT_REJECTED,
                    'reason'        => 'Reply token has expired',
                    'ticket_id'     => $ticketId,
                    'ticket_number' => $resolvedTicketNumber,
                    'token_prefix'  => $tokenRow['token_prefix']
                ];
            }
        }

        // 5. Sender Authorization Check
        $authSender = Database::fetchOne(
            "SELECT * FROM `authorized_ticket_emails` 
             WHERE `ticket_id` = :ticket_id 
               AND `email` = :email 
               AND `is_active` = 1",
            ['ticket_id' => $ticketId, 'email' => $fromEmail]
        );

        if (!$authSender) {
            return [
                'result'        => self::RESULT_REJECTED,
                'reason'        => "Unauthorized sender address ({$fromEmail}) for ticket {$resolvedTicketNumber}",
                'ticket_id'     => $ticketId,
                'ticket_number' => $resolvedTicketNumber,
                'token_prefix'  => $token ? substr($token, 0, 8) : null
            ];
        }

        // 6. Ticket Status & Closed Policy Check
        if ($ticket['status'] === 'closed') {
            $policySetting = Database::fetchOne(
                "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'closed_ticket_policy'"
            );
            $policy = $policySetting['setting_value'] ?? 'reject';

            if ($policy === 'reopen') {
                // Auto-reopen ticket
                Database::query(
                    "UPDATE `tickets` SET `status` = 'reopened', `updated_at` = NOW() WHERE `id` = :id",
                    ['id' => $ticketId]
                );
                Database::query(
                    "INSERT INTO `ticket_status_history` (`ticket_id`, `old_status`, `new_status`, `reason`, `created_at`) 
                     VALUES (:id, 'closed', 'reopened', 'Automatically reopened via authorized email reply', NOW())",
                    ['id' => $ticketId]
                );
            } else {
                return [
                    'result'        => self::RESULT_REJECTED,
                    'reason'        => 'Ticket is closed. Reply rejected according to closed-ticket policy.',
                    'ticket_id'     => $ticketId,
                    'ticket_number' => $resolvedTicketNumber,
                    'token_prefix'  => $token ? substr($token, 0, 8) : null
                ];
            }
        } elseif ($ticket['status'] === 'expired') {
            return [
                'result'        => self::RESULT_REJECTED,
                'reason'        => 'Ticket has expired due to inactivity. Please open a new request.',
                'ticket_id'     => $ticketId,
                'ticket_number' => $resolvedTicketNumber,
                'token_prefix'  => $token ? substr($token, 0, 8) : null
            ];
        }

        // All checks passed!
        return [
            'result'        => self::RESULT_ACCEPTED,
            'reason'        => 'Validated successfully',
            'ticket_id'     => $ticketId,
            'ticket'        => $ticket,
            'ticket_number' => $resolvedTicketNumber,
            'token_prefix'  => $token ? substr($token, 0, 8) : null,
            'user_id'       => $authSender['user_id'] ? (int)$authSender['user_id'] : null
        ];
    }

    /**
     * Records the validation verdict into inbound_email_logs.
     */
    public static function logInboundProcessing(
        string $fromEmail,
        string $toEmail,
        ?string $subject,
        ?string $ticketNumber,
        ?string $tokenPrefix,
        string $result,
        ?string $rejectionReason = null,
        ?string $messageId = null,
        ?string $rawHeaders = null
    ): int {
        $sql = "INSERT INTO `inbound_email_logs` 
                (`message_id_header`, `from_email`, `to_email`, `subject`, `ticket_number`, `token_prefix`, `processing_result`, `rejection_reason`, `raw_headers`, `created_at`) 
                VALUES 
                (:msg_id, :from, :to, :subject, :num, :prefix, :result, :reason, :headers, NOW())";

        Database::query($sql, [
            'msg_id'  => $messageId,
            'from'    => $fromEmail,
            'to'      => $toEmail,
            'subject' => $subject,
            'num'     => $ticketNumber,
            'prefix'  => $tokenPrefix,
            'result'  => $result,
            'reason'  => $rejectionReason,
            'headers' => $rawHeaders ? substr($rawHeaders, 0, 5000) : null
        ]);

        return (int)Database::lastInsertId();
    }
}
