<?php
/**
 * Ticket Service
 * 
 * Manages ticket lifecycle, safe sequence numbering, token generation,
 * authorized sender association, threaded messages, and confirmation queuing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/MailQueueService.php';
require_once __DIR__ . '/SecurityService.php';

class TicketService
{
    /**
     * Generates a safe, human-readable ticket number (e.g. REQ-2026-10001).
     */
    public static function generateTicketNumber(): string
    {
        $prefix = config('app.ticket.prefix', 'REQ');
        $year = date('Y');

        // Look for the highest sequence number for the current year
        $pattern = "{$prefix}-{$year}-%";
        $sql = "SELECT `ticket_number` 
                FROM `tickets` 
                WHERE `ticket_number` LIKE :pattern 
                ORDER BY `id` DESC 
                LIMIT 1";

        $lastTicket = Database::fetchOne($sql, ['pattern' => $pattern]);
        $nextSeq = 10001;

        if ($lastTicket) {
            $parts = explode('-', $lastTicket['ticket_number']);
            $lastNum = (int)end($parts);
            if ($lastNum >= 10001) {
                $nextSeq = $lastNum + 1;
            }
        }

        return sprintf("%s-%s-%05d", $prefix, $year, $nextSeq);
    }

    /**
     * Generates a unique, cryptographically secure email reply address.
     * 
     * Form: {prefix}{token}@{domain}
     */
    public static function generateReplyAddress(string $rawToken): string
    {
        $domain = config('mail.reply_routing.domain', 'example.com');
        $prefix = config('mail.reply_routing.prefix', 'reply+');
        return "{$prefix}{$rawToken}@{$domain}";
    }

    /**
     * Creates a new support ticket inside an atomic transaction.
     */
    public static function createTicket(
        int $customerId,
        string $subject,
        string $description,
        string $priority = 'medium',
        string $category = 'General'
    ): array {
        $subject = SecurityService::sanitize($subject);
        $description = trim($description);
        $priority = in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ? $priority : 'medium';
        $category = SecurityService::sanitize($category) ?: 'General';

        if (empty($subject)) {
            return ['success' => false, 'error' => 'Ticket subject is required.'];
        }

        if (empty($description)) {
            return ['success' => false, 'error' => 'Ticket description is required.'];
        }

        // Customer details
        $customer = Database::fetchOne(
            "SELECT `id`, `first_name`, `last_name`, `email` FROM `users` WHERE `id` = :id AND `status` = 'active'",
            ['id' => $customerId]
        );

        if (!$customer) {
            return ['success' => false, 'error' => 'Valid active customer account required.'];
        }

        // Execute ticket creation inside an atomic transaction
        return Database::transaction(function (PDO $pdo) use ($customer, $customerId, $subject, $description, $priority, $category) {
            $ticketNumber = self::generateTicketNumber();

            // 1. Insert into tickets table
            $ticketSql = "INSERT INTO `tickets` 
                          (`ticket_number`, `customer_id`, `subject`, `description`, `status`, `priority`, `category`, `created_at`) 
                          VALUES 
                          (:number, :customer_id, :subject, :description, 'open', :priority, :category, NOW())";

            $stmt = $pdo->prepare($ticketSql);
            $stmt->execute([
                'number'      => $ticketNumber,
                'customer_id' => $customerId,
                'subject'     => $subject,
                'description' => $description,
                'priority'    => $priority,
                'category'    => $category,
            ]);
            $ticketId = (int)$pdo->lastInsertId();

            // 2. Generate cryptographically secure reply token (32 random bytes -> 64 hex characters)
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $tokenPrefix = substr($rawToken, 0, 8);
            $expiryDays = (int)config('mail.reply_routing.token_expiry_days', 14);
            $tokenExpires = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));

            // Store token hash in email_tokens (never store plaintext)
            $tokenSql = "INSERT INTO `email_tokens` 
                         (`ticket_id`, `user_id`, `token_hash`, `token_prefix`, `is_revoked`, `expires_at`, `created_at`) 
                         VALUES 
                         (:ticket_id, :user_id, :hash, :prefix, 0, :expires, NOW())";
            $tokenStmt = $pdo->prepare($tokenSql);
            $tokenStmt->execute([
                'ticket_id' => $ticketId,
                'user_id'   => $customerId,
                'hash'      => $tokenHash,
                'prefix'    => $tokenPrefix,
                'expires'   => $tokenExpires,
            ]);

            // 3. Register customer as authorized email sender
            $authEmailSql = "INSERT INTO `authorized_ticket_emails` 
                             (`ticket_id`, `email`, `user_id`, `role`, `is_active`, `created_at`) 
                             VALUES 
                             (:ticket_id, :email, :user_id, 'owner', 1, NOW())";
            $authStmt = $pdo->prepare($authEmailSql);
            $authStmt->execute([
                'ticket_id' => $ticketId,
                'email'     => $customer['email'],
                'user_id'   => $customerId,
            ]);

            // 4. Save initial request message
            $msgSql = "INSERT INTO `ticket_messages` 
                       (`ticket_id`, `user_id`, `sender_type`, `sender_email`, `sender_name`, `body_plain`, `source`, `created_at`) 
                       VALUES 
                       (:ticket_id, :user_id, 'customer', :email, :name, :body, 'web', NOW())";
            $msgStmt = $pdo->prepare($msgSql);
            $msgStmt->execute([
                'ticket_id' => $ticketId,
                'user_id'   => $customerId,
                'email'     => $customer['email'],
                'name'      => "{$customer['first_name']} {$customer['last_name']}",
                'body'      => $description,
            ]);

            // 5. Record initial status history
            $histSql = "INSERT INTO `ticket_status_history` 
                        (`ticket_id`, `old_status`, `new_status`, `changed_by`, `reason`, `created_at`) 
                        VALUES 
                        (:ticket_id, NULL, 'open', :changed_by, 'Ticket created by customer', NOW())";
            $histStmt = $pdo->prepare($histSql);
            $histStmt->execute([
                'ticket_id'  => $ticketId,
                'changed_by' => $customerId,
            ]);

            $ticketData = [
                'id'            => $ticketId,
                'ticket_number' => $ticketNumber,
                'subject'       => $subject,
                'description'   => $description,
                'status'        => 'open',
                'priority'      => $priority,
                'category'      => $category,
                'customer_id'   => $customerId,
                'raw_token'     => $rawToken,
            ];

            // 6. Enqueue confirmation email
            $replyAddress = self::generateReplyAddress($rawToken);
            MailQueueService::enqueueTicketConfirmationEmail(
                $ticketData,
                $customer['email'],
                "{$customer['first_name']} {$customer['last_name']}",
                $replyAddress
            );

            return [
                'success'       => true,
                'ticket_id'     => $ticketId,
                'ticket_number' => $ticketNumber,
                'reply_address' => $replyAddress,
                'message'       => "Support ticket #{$ticketNumber} has been created successfully."
            ];
        });
    }

    /**
     * Retrieves tickets for a specific customer.
     */
    public static function getCustomerTickets(int $customerId, ?string $status = null, int $limit = 50, int $offset = 0): array
    {
        $params = ['customer_id' => $customerId];
        $statusClause = '';

        if ($status !== null && in_array($status, ['open', 'pending', 'closed', 'expired', 'reopened'], true)) {
            $statusClause = "AND `status` = :status";
            $params['status'] = $status;
        }

        $sql = "SELECT `id`, `ticket_number`, `subject`, `status`, `priority`, `category`, `created_at`, `updated_at`, `last_reply_at` 
                FROM `tickets` 
                WHERE `customer_id` = :customer_id {$statusClause} 
                ORDER BY `created_at` DESC 
                LIMIT {$limit} OFFSET {$offset}";

        return Database::fetchAll($sql, $params);
    }

    /**
     * Retrieves customer ticket counts grouped by status.
     */
    public static function getCustomerTicketStats(int $customerId): array
    {
        $sql = "SELECT `status`, COUNT(*) AS count 
                FROM `tickets` 
                WHERE `customer_id` = :customer_id 
                GROUP BY `status`";

        $rows = Database::fetchAll($sql, ['customer_id' => $customerId]);
        $stats = ['all' => 0, 'open' => 0, 'pending' => 0, 'closed' => 0, 'reopened' => 0, 'expired' => 0];

        foreach ($rows as $r) {
            $stats[$r['status']] = (int)$r['count'];
            $stats['all'] += (int)$r['count'];
        }

        return $stats;
    }

    /**
     * Retrieves a single ticket by its ID with ownership check.
     */
    public static function getTicketById(int $ticketId, ?int $customerId = null): ?array
    {
        $params = ['id' => $ticketId];
        $customerClause = '';

        if ($customerId !== null) {
            $customerClause = "AND `customer_id` = :customer_id";
            $params['customer_id'] = $customerId;
        }

        $sql = "SELECT t.*, u.first_name, u.last_name, u.email AS customer_email 
                FROM `tickets` t 
                JOIN `users` u ON t.customer_id = u.id 
                WHERE t.id = :id {$customerClause}";

        return Database::fetchOne($sql, $params);
    }

    /**
     * Retrieves conversation messages for a ticket.
     */
    public static function getTicketMessages(int $ticketId, bool $includeInternal = false): array
    {
        $internalClause = $includeInternal ? "" : "AND `is_internal_note` = 0";
        $sql = "SELECT * 
                FROM `ticket_messages` 
                WHERE `ticket_id` = :ticket_id {$internalClause} 
                ORDER BY `created_at` ASC";

        return Database::fetchAll($sql, ['ticket_id' => $ticketId]);
    }

    /**
     * Appends a message to a ticket conversation.
     */
    public static function addMessage(
        int $ticketId,
        ?int $userId,
        string $senderType,
        string $senderEmail,
        string $senderName,
        string $body,
        bool $isInternal = false,
        string $source = 'web'
    ): int {
        $sql = "INSERT INTO `ticket_messages` 
                (`ticket_id`, `user_id`, `sender_type`, `sender_email`, `sender_name`, `body_plain`, `is_internal_note`, `source`, `created_at`) 
                VALUES 
                (:ticket_id, :user_id, :sender_type, :email, :name, :body, :internal, :source, NOW())";

        Database::query($sql, [
            'ticket_id'   => $ticketId,
            'user_id'     => $userId,
            'sender_type' => $senderType,
            'email'       => $senderEmail,
            'name'        => $senderName,
            'body'        => $body,
            'internal'    => $isInternal ? 1 : 0,
            'source'      => $source,
        ]);

        $messageId = (int)Database::lastInsertId();

        // Update ticket last_reply_at
        Database::query(
            "UPDATE `tickets` SET `last_reply_at` = NOW() WHERE `id` = :ticket_id",
            ['ticket_id' => $ticketId]
        );

        return $messageId;
    }
}
