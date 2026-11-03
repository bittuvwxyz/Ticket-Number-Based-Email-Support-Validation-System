<?php
/**
 * Outbound Mail Queue Service
 * 
 * Implements database-first asynchronous email dispatching with retry handling,
 * customized headers (Message-ID, References), and tokenized reply addresses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';

class MailQueueService
{
    /**
     * Enqueues an outgoing email into MySQL email_queue.
     */
    public static function enqueue(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $bodyPlain,
        ?string $bodyHtml = null,
        ?int $ticketId = null,
        ?string $replyToAddress = null,
        ?array $customHeaders = null
    ): int {
        $sql = "INSERT INTO `email_queue` 
                (`ticket_id`, `recipient_email`, `recipient_name`, `subject`, `body_plain`, `body_html`, `reply_to_address`, `custom_headers`, `status`, `attempts`, `scheduled_at`, `created_at`) 
                VALUES 
                (:ticket_id, :recipient_email, :recipient_name, :subject, :body_plain, :body_html, :reply_to, :custom_headers, 'pending', 0, NOW(), NOW())";

        Database::query($sql, [
            'ticket_id'       => $ticketId,
            'recipient_email' => $recipientEmail,
            'recipient_name'  => $recipientName,
            'subject'         => $subject,
            'body_plain'      => $bodyPlain,
            'body_html'       => $bodyHtml,
            'reply_to'        => $replyToAddress,
            'custom_headers'  => $customHeaders ? json_encode($customHeaders, JSON_UNESCAPED_SLASHES) : null,
        ]);

        $queueId = (int)Database::lastInsertId();

        // Database-First: Attempt opportunistic immediate dispatch
        // If successful, marks 'sent' immediately so users don't wait for cron
        if (!Database::inTransaction()) {
            self::attemptImmediateDispatch($queueId, $recipientEmail, $recipientName, $subject, $bodyPlain, $bodyHtml, $replyToAddress, $customHeaders);
        }

        return $queueId;
    }

    /**
     * Attempts immediate delivery of a newly enqueued message.
     * Non-blocking: failures remain 'pending' for the background cron worker.
     */
    public static function attemptImmediateDispatch(
        int $queueId,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyPlain,
        ?string $bodyHtml = null,
        ?string $replyTo = null,
        ?array $customHeaders = null
    ): void {
        try {
            require_once dirname(__DIR__) . '/mail/SMTPClient.php';
            $result = SMTPClient::send($toEmail, $toName, $subject, $bodyPlain, $bodyHtml, $replyTo, $customHeaders);
            if ($result['success']) {
                Database::query(
                    "UPDATE `email_queue` SET `status` = 'sent', `sent_at` = NOW(), `attempts` = 1 WHERE `id` = :id",
                    ['id' => $queueId]
                );
                Database::query(
                    "INSERT INTO `email_logs` (`queue_id`, `recipient_email`, `subject`, `status`, `smtp_response`, `created_at`) 
                     VALUES (:qid, :recipient, :subject, 'success', :resp, NOW())",
                    [
                        'qid'       => $queueId,
                        'recipient' => $toEmail,
                        'subject'   => $subject,
                        'resp'      => $result['response']
                    ]
                );
            }
        } catch (Throwable $e) {
            // Non-blocking: remains pending for cron
        }
    }

    /**
     * Dispatches a specific queue item by its ID.
     */
    public static function dispatchQueueItem(int $queueId): bool
    {
        $item = Database::fetchOne(
            "SELECT * FROM `email_queue` WHERE `id` = :id AND `status` = 'pending'",
            ['id' => $queueId]
        );

        if (!$item) {
            return false;
        }

        $customHeaders = !empty($item['custom_headers']) ? json_decode($item['custom_headers'], true) : [];
        self::attemptImmediateDispatch(
            $queueId,
            $item['recipient_email'],
            $item['recipient_name'] ?: $item['recipient_email'],
            $item['subject'],
            $item['body_plain'],
            $item['body_html'],
            $item['reply_to_address'],
            $customHeaders
        );

        $check = Database::fetchOne("SELECT `status` FROM `email_queue` WHERE `id` = :id", ['id' => $queueId]);
        return ($check['status'] ?? '') === 'sent';
    }

    /**
     * Enqueues a notification email when a new message/reply is posted to a ticket.
     */
    public static function enqueueTicketReplyEmail(
        array $ticket,
        string $senderName,
        string $senderType,
        string $messageBody,
        string $recipientEmail,
        string $recipientName,
        string $replyAddress
    ): int {
        $appName = config('app.name', 'Secure Support Desk');
        $ticketNum = $ticket['ticket_number'];
        $subject = "Re: [{$ticketNum}] {$ticket['subject']}";

        $bodyPlain = "Hello {$recipientName},\n\n"
                   . "A new reply has been posted to ticket {$ticketNum} by {$senderName}:\n\n"
                   . "--------------------------------------------------\n"
                   . "{$messageBody}\n"
                   . "--------------------------------------------------\n\n"
                   . "You can reply directly to this email to add additional details to this ticket.\n\n"
                   . "Regards,\n{$appName} Team";

        $bodyHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <div style='background-color: #f8f9fa; padding: 12px 16px; border-radius: 6px; margin-bottom: 20px;'>
                <span style='font-size: 11px; font-weight: bold; color: #6c757d; text-transform: uppercase;'>Ticket Update</span>
                <h4 style='margin: 4px 0 0 0; color: #0d6efd;'>{$ticketNum}: " . htmlspecialchars($ticket['subject']) . "</h4>
            </div>
            <p>Hello <strong>" . htmlspecialchars($recipientName) . "</strong>,</p>
            <p><strong>" . htmlspecialchars($senderName) . "</strong> (" . ucfirst(htmlspecialchars($senderType)) . ") has posted an update:</p>
            
            <div style='background-color: #f8fafc; border-left: 4px solid #0d6efd; padding: 14px 18px; margin: 20px 0; font-size: 14px; line-height: 1.6;'>
                " . nl2br(htmlspecialchars($messageBody)) . "
            </div>

            <p style='background-color: #e8f4fd; padding: 12px; border-radius: 6px; color: #0c5460; font-size: 13px;'>
                <i style='font-weight: bold;'>Reply via Email:</i> Simply click <strong>Reply</strong> in your email client to respond directly to this ticket.
            </p>

            <hr style='border: none; border-top: 1px solid #eee; margin: 25px 0;'>
            <p style='color: #888; font-size: 12px;'>{$appName} - Automated Notification System</p>
        </div>";

        $customHeaders = [
            'X-Ticket-ID' => $ticketNum,
            'In-Reply-To' => "<ticket-{$ticket['id']}@" . config('mail.reply_routing.domain') . ">",
            'References'  => "<ticket-{$ticket['id']}@" . config('mail.reply_routing.domain') . ">",
        ];

        return self::enqueue(
            $recipientEmail,
            $recipientName,
            $subject,
            $bodyPlain,
            $bodyHtml,
            (int)$ticket['id'],
            $replyAddress,
            $customHeaders
        );
    }

    /**
     * Enqueues an account email verification message.
     */
    public static function enqueueVerificationEmail(string $email, string $name, string $token): int
    {
        $appUrl = config('app.url', 'http://localhost/helpdesk');
        $verifyUrl = "{$appUrl}/verify-email.php?token={$token}";
        $appName = config('app.name', 'Secure Support Desk');

        $subject = "Verify your email address - {$appName}";

        $bodyPlain = "Hello {$name},\n\n"
                   . "Thank you for creating an account with {$appName}.\n\n"
                   . "Please verify your email address by clicking the link below:\n"
                   . "{$verifyUrl}\n\n"
                   . "This verification link will expire in 24 hours.\n\n"
                   . "If you did not register for an account, please ignore this email.\n\n"
                   . "Regards,\n{$appName} Team";

        $bodyHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <h2 style='color: #0d6efd;'>{$appName}</h2>
            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>Thank you for creating an account. Please verify your email address to complete your registration:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$verifyUrl}' style='background-color: #0d6efd; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Verify Email Address</a>
            </p>
            <p style='color: #666; font-size: 13px;'>If the button above does not work, copy and paste this URL into your browser:<br><a href='{$verifyUrl}'>{$verifyUrl}</a></p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 25px 0;'>
            <p style='color: #888; font-size: 12px;'>This link is valid for 24 hours. If you did not create this account, no further action is required.</p>
        </div>";

        return self::enqueue($email, $name, $subject, $bodyPlain, $bodyHtml);
    }

    /**
     * Enqueues a password reset instruction email.
     */
    public static function enqueuePasswordResetEmail(string $email, string $name, string $token): int
    {
        $appUrl = config('app.url', 'http://localhost/helpdesk');
        $resetUrl = "{$appUrl}/reset-password.php?token={$token}";
        $appName = config('app.name', 'Secure Support Desk');

        $subject = "Password Reset Request - {$appName}";

        $bodyPlain = "Hello {$name},\n\n"
                   . "We received a request to reset your password for {$appName}.\n\n"
                   . "To choose a new password, click the link below:\n"
                   . "{$resetUrl}\n\n"
                   . "This link is valid for 1 hour.\n\n"
                   . "If you did not request a password reset, please ignore this email or contact support if you suspect unauthorized access.\n\n"
                   . "Regards,\n{$appName} Team";

        $bodyHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <h2 style='color: #0d6efd;'>{$appName}</h2>
            <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
            <p>We received a request to reset your account password. Click the button below to proceed:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$resetUrl}' style='background-color: #dc3545; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Reset Password</a>
            </p>
            <p style='color: #666; font-size: 13px;'>Direct link: <a href='{$resetUrl}'>{$resetUrl}</a></p>
            <hr style='border: none; border-top: 1px solid #eee; margin: 25px 0;'>
            <p style='color: #888; font-size: 12px;'>This link expires in 1 hour. If you did not initiate this request, you can safely ignore this email.</p>
        </div>";

        return self::enqueue($email, $name, $subject, $bodyPlain, $bodyHtml);
    }

    /**
     * Enqueues ticket creation confirmation email containing the ticket reference
     * and secure tokenized reply-to address.
     */
    public static function enqueueTicketConfirmationEmail(
        array $ticket,
        string $customerEmail,
        string $customerName,
        string $replyAddress
    ): int {
        $appName = config('app.name', 'Secure Support Desk');
        $ticketNum = $ticket['ticket_number'];
        $subject = "[{$ticketNum}] Support Request Received: {$ticket['subject']}";

        $bodyPlain = "Hello {$customerName},\n\n"
                   . "Your support request has been registered successfully.\n\n"
                   . "Ticket Number: {$ticketNum}\n"
                   . "Subject: {$ticket['subject']}\n"
                   . "Priority: " . ucfirst($ticket['priority']) . "\n"
                   . "Status: " . ucfirst($ticket['status']) . "\n\n"
                   . "--- Original Description ---\n"
                   . "{$ticket['description']}\n\n"
                   . "You can reply directly to this email to add additional information or updates.\n"
                   . "Please do not modify the subject line or reply address, as they contain your secure validation credentials.\n\n"
                   . "Regards,\n{$appName} Team";

        $bodyHtml = "
        <div style='font-family: Arial, sans-serif; max-width: 650px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
            <div style='background-color: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 20px;'>
                <span style='font-size: 12px; font-weight: bold; color: #6c757d; text-transform: uppercase;'>Ticket Confirmation</span>
                <h3 style='margin: 5px 0 0 0; color: #0d6efd;'>{$ticketNum}</h3>
            </div>
            <p>Hello <strong>" . htmlspecialchars($customerName) . "</strong>,</p>
            <p>Your support request has been logged and assigned to our team. Here are the details:</p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; width: 140px; font-weight: bold;'>Subject:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($ticket['subject']) . "</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>Category:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($ticket['category']) . "</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>Priority:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . ucfirst(htmlspecialchars($ticket['priority'])) . "</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>Status:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . ucfirst(htmlspecialchars($ticket['status'])) . "</td></tr>
            </table>

            <div style='background-color: #fbfbfb; border-left: 4px solid #0d6efd; padding: 12px 16px; margin: 20px 0; font-style: italic;'>
                " . nl2br(htmlspecialchars($ticket['description'])) . "
            </div>

            <p style='background-color: #e8f4fd; padding: 12px; border-radius: 6px; color: #0c5460; font-size: 13px;'>
                <i style='font-weight: bold;'>How to reply:</i> You may simply hit <strong>Reply</strong> in your email client to send further details directly into this ticket. Do not remove the ticket reference or alter the reply address.
            </p>

            <hr style='border: none; border-top: 1px solid #eee; margin: 25px 0;'>
            <p style='color: #888; font-size: 12px;'>{$appName} - Automated Notification System</p>
        </div>";

        $customHeaders = [
            'X-Ticket-ID' => $ticketNum,
            'X-Helpdesk-Auto-Response' => 'true'
        ];

        return self::enqueue(
            $customerEmail,
            $customerName,
            $subject,
            $bodyPlain,
            $bodyHtml,
            (int)$ticket['id'],
            $replyAddress,
            $customHeaders
        );
    }
}
