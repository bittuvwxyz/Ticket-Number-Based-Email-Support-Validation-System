<?php
/**
 * IMAP Inbound Email Ingestion Client
 * 
 * Connects to the support mailbox via IMAP SSL, retrieves incoming email replies,
 * parses MIME headers and bodies, validates through EmailValidationService,
 * and threads authorized messages directly into tickets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../database/Database.php';
require_once __DIR__ . '/../services/EmailValidationService.php';
require_once __DIR__ . '/MIMEParser.php';

class IMAPClient
{
    private mixed $stream = null;

    /**
     * Opens connection to IMAP mailbox.
     */
    public function connect(): bool
    {
        $host = config('mail.imap.host');
        $port = (int)config('mail.imap.port', 993);
        $user = config('mail.imap.username');
        $pass = config('mail.imap.password');
        $validateCert = (bool)config('mail.imap.validate_cert', false);

        $certFlag = $validateCert ? '/validate-cert' : '/novalidate-cert';
        $mailbox = "{" . $host . ":" . $port . "/imap/ssl" . $certFlag . "}INBOX";

        $this->stream = @imap_open(
            $mailbox,
            $user,
            $pass,
            0,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
        );

        return $this->stream !== false;
    }

    /**
     * Closes active IMAP connection.
     */
    public function disconnect(): void
    {
        if ($this->stream) {
            @imap_close($this->stream, CL_EXPUNGE);
            $this->stream = null;
        }
    }

    /**
     * Retrieves the last IMAP error message.
     */
    public function getLastError(): string
    {
        return imap_last_error() ?: 'Unknown IMAP error';
    }

    /**
     * Ingests and processes incoming unread email messages.
     * 
     * @param int $batchLimit Maximum messages to parse in one execution
     * @return array [ 'processed' => int, 'accepted' => int, 'rejected' => int, 'errors' => array ]
     */
    public function processInbound(int $batchLimit = 30): array
    {
        if (!$this->stream) {
            if (!$this->connect()) {
                return [
                    'processed' => 0,
                    'accepted'  => 0,
                    'rejected'  => 0,
                    'errors'    => ['IMAP connection failed: ' . $this->getLastError()]
                ];
            }
        }

        // Search for unseen messages
        $msgNumbers = imap_search($this->stream, 'UNSEEN');
        if (empty($msgNumbers)) {
            return [
                'processed' => 0,
                'accepted'  => 0,
                'rejected'  => 0,
                'message'   => 'No new incoming messages in mailbox.'
            ];
        }

        $processed = 0;
        $accepted = 0;
        $rejected = 0;
        $errors = [];

        // Limit to batch size
        $batch = array_slice($msgNumbers, 0, $batchLimit);

        foreach ($batch as $msgNum) {
            $processed++;

            try {
                $header = imap_headerinfo($this->stream, $msgNum);

                // Extract sender
                $fromEmail = strtolower($header->from[0]->mailbox . '@' . $header->from[0]->host);
                $fromName = isset($header->from[0]->personal) ? MIMEParser::decodeHeader($header->from[0]->personal) : $fromEmail;

                // Extract recipient
                $toEmail = '';
                if (!empty($header->to)) {
                    $toEmail = strtolower($header->to[0]->mailbox . '@' . $header->to[0]->host);
                }

                $subject = isset($header->subject) ? MIMEParser::decodeHeader($header->subject) : '';
                $messageId = isset($header->message_id) ? trim($header->message_id, '<>') : null;
                $inReplyTo = isset($header->in_reply_to) ? trim($header->in_reply_to, '<>') : null;
                $references = isset($header->references) ? trim($header->references) : null;

                // Extract body text
                $bodies = MIMEParser::getMessageBody($this->stream, $msgNum);
                $plainBody = $bodies['plain'];
                $htmlBody = $bodies['html'];

                $emailData = [
                    'from_email'        => $fromEmail,
                    'to_email'          => $toEmail,
                    'subject'           => $subject,
                    'message_id_header' => $messageId,
                    'raw_headers'       => imap_fetchheader($this->stream, $msgNum),
                    'body_plain'        => $plainBody,
                ];

                // Validate message against Ticket & Token Validation Rules
                $validation = EmailValidationService::validateInboundMessage($emailData);

                // Log the inbound audit record
                EmailValidationService::logInboundProcessing(
                    $fromEmail,
                    $toEmail,
                    $subject,
                    $validation['ticket_number'] ?? null,
                    $validation['token_prefix'] ?? null,
                    $validation['result'],
                    $validation['reason'] ?? null,
                    $messageId,
                    $emailData['raw_headers']
                );

                if ($validation['result'] === EmailValidationService::RESULT_ACCEPTED) {
                    $accepted++;
                    $ticketId = $validation['ticket_id'];
                    $userId = $validation['user_id'] ?? null;

                    // Append message to ticket conversation
                    Database::query(
                        "INSERT INTO `ticket_messages` 
                         (`ticket_id`, `user_id`, `sender_type`, `sender_email`, `sender_name`, `body_plain`, `body_html`, `source`, `message_id_header`, `in_reply_to_header`, `references_header`, `created_at`) 
                         VALUES 
                         (:ticket_id, :user_id, 'customer', :email, :name, :body_plain, :body_html, 'email', :msg_id, :in_reply_to, :references, NOW())",
                        [
                            'ticket_id'   => $ticketId,
                            'user_id'     => $userId,
                            'email'       => $fromEmail,
                            'name'        => $fromName,
                            'body_plain'  => $plainBody,
                            'body_html'   => $htmlBody,
                            'msg_id'      => $messageId,
                            'in_reply_to' => $inReplyTo,
                            'references'  => $references
                        ]
                    );

                    // Update ticket last_reply_at
                    Database::query(
                        "UPDATE `tickets` SET `last_reply_at` = NOW(), `updated_at` = NOW() WHERE `id` = :id",
                        ['id' => $ticketId]
                    );
                } else {
                    $rejected++;
                }

                // Mark message as seen / read on IMAP server
                imap_setflag_full($this->stream, (string)$msgNum, "\\Seen");

            } catch (Throwable $e) {
                $errors[] = "Error on msg #{$msgNum}: " . $e->getMessage();
            }
        }

        return [
            'processed' => $processed,
            'accepted'  => $accepted,
            'rejected'  => $rejected,
            'errors'    => $errors
        ];
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
