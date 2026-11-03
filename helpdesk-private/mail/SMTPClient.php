<?php
/**
 * SMTP Mail Client
 * 
 * Production-ready SMTP transmission client powered by PHPMailer.
 * Handles TLS/SSL encryption, authentication, custom headers, and tokenized reply addresses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class SMTPClient
{
    /**
     * Sends an email via SMTP using configured credentials.
     * 
     * @return array [ 'success' => bool, 'response' => string, 'error' => ?string ]
     */
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyPlain,
        ?string $bodyHtml = null,
        ?string $replyToAddress = null,
        ?array $customHeaders = null,
        ?array $attachments = null
    ): array {
        $mail = new PHPMailer(true);

        try {
            $smtpConfig = config('mail.smtp', []);

            // Server settings
            $mail->isSMTP();
            $mail->Host       = $smtpConfig['host'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth   = (bool)($smtpConfig['auth'] ?? true);
            $mail->Username   = $smtpConfig['username'] ?? '';
            $mail->Password   = $smtpConfig['password'] ?? '';
            $mail->Port       = (int)($smtpConfig['port'] ?? 587);

            $encryption = strtolower($smtpConfig['encryption'] ?? 'tls');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            // Sender
            $fromEmail = $smtpConfig['from_email'] ?? 'support@example.com';
            $fromName  = $smtpConfig['from_name'] ?? 'Support Desk';
            $mail->setFrom($fromEmail, $fromName);

            // Recipient
            $mail->addAddress($toEmail, $toName);

            // Tokenized Reply-To Address
            if (!empty($replyToAddress)) {
                $mail->addReplyTo($replyToAddress, $fromName);
            }

            // Custom Headers (RFC-compliant)
            if (!empty($customHeaders)) {
                foreach ($customHeaders as $headerKey => $headerVal) {
                    $mail->addCustomHeader($headerKey, (string)$headerVal);
                }
            }

            // Attachments
            if (!empty($attachments)) {
                foreach ($attachments as $att) {
                    if (is_string($att) && file_exists($att)) {
                        $mail->addAttachment($att);
                    } elseif (is_array($att) && !empty($att['path']) && file_exists($att['path'])) {
                        $mail->addAttachment($att['path'], $att['name'] ?? '');
                    }
                }
            }

            // Content
            $mail->CharSet = 'UTF-8';
            if (!empty($bodyHtml)) {
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $bodyHtml;
                $mail->AltBody = $bodyPlain;
            } else {
                $mail->isHTML(false);
                $mail->Subject = $subject;
                $mail->Body    = $bodyPlain;
            }

            $mail->send();

            return [
                'success'  => true,
                'response' => '250 Message accepted for delivery',
                'error'    => null
            ];
        } catch (Exception $e) {
            return [
                'success'  => false,
                'response' => '',
                'error'    => $mail->ErrorInfo ?: $e->getMessage()
            ];
        }
    }
}
