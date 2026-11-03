<?php
/**
 * Outgoing Email Queue Processor (Cron Job & Task Scheduler Script)
 * 
 * Usage:
 *   Windows (Task Scheduler / PowerShell / CMD):
 *     php C:\xampp\htdocs\helpdesk-private\cron\process_outgoing_queue.php
 * 
 *   Linux / cPanel (Cron Job):
 *     * * * * * /usr/local/bin/php /home/username/helpdesk-private/cron/process_outgoing_queue.php >/dev/null 2>&1
 */

declare(strict_types=1);

// Prevent direct web browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Direct web access prohibited. This script must be executed via CLI, Cron Job, or Task Scheduler.\n");
}

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/database/Database.php';
require_once dirname(__DIR__) . '/mail/SMTPClient.php';

$lockFile = config('app.paths.storage', dirname(__DIR__) . '/storage') . '/cron_outgoing.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Process already running. Lock acquisition failed. Exiting.\n";
    exit(0);
}

echo "============================================================\n";
echo " Outgoing Email Queue Processor\n";
echo " Time: " . date('Y-m-d H:i:s T') . "\n";
echo "============================================================\n\n";

try {
    // 1. Fetch batch limit from system settings
    $batchSetting = Database::fetchOne(
        "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'email_queue_batch_size'"
    );
    $batchSize = $batchSetting ? (int)$batchSetting['setting_value'] : 25;

    // 2. Fetch pending messages ready for transmission
    $sql = "SELECT * 
            FROM `email_queue` 
            WHERE `status` = 'pending' 
              AND `scheduled_at` <= NOW() 
              AND `attempts` < `max_attempts` 
            ORDER BY `scheduled_at` ASC 
            LIMIT {$batchSize}";

    $pendingItems = Database::fetchAll($sql);

    if (empty($pendingItems)) {
        echo "[INFO] No pending emails to dispatch at this time.\n";
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(0);
    }

    echo "Found " . count($pendingItems) . " pending email(s) to process.\n\n";

    $sentCount = 0;
    $failedCount = 0;

    foreach ($pendingItems as $item) {
        $queueId = (int)$item['id'];
        echo "Processing Queue ID #{$queueId} -> To: {$item['recipient_email']} | Subject: '{$item['subject']}'\n";

        // Mark as processing to avoid duplicate delivery across workers
        Database::query("UPDATE `email_queue` SET `status` = 'processing' WHERE `id` = :id", ['id' => $queueId]);

        $customHeaders = !empty($item['custom_headers']) ? json_decode($item['custom_headers'], true) : [];
        $attachments = !empty($item['attachments_json']) ? json_decode($item['attachments_json'], true) : [];

        // Attempt transmission via SMTPClient (PHPMailer)
        $result = SMTPClient::send(
            $item['recipient_email'],
            $item['recipient_name'] ?: $item['recipient_email'],
            $item['subject'],
            $item['body_plain'],
            $item['body_html'],
            $item['reply_to_address'],
            $customHeaders,
            $attachments
        );

        if ($result['success']) {
            $sentCount++;
            echo "  ✔ [SENT] Delivery confirmed by SMTP server.\n";

            // Mark as sent
            Database::query(
                "UPDATE `email_queue` 
                 SET `status` = 'sent', `sent_at` = NOW(), `attempts` = attempts + 1, `last_error` = NULL 
                 WHERE `id` = :id",
                ['id' => $queueId]
            );

            // Record into email_logs
            Database::query(
                "INSERT INTO `email_logs` (`queue_id`, `ticket_id`, `recipient_email`, `subject`, `status`, `smtp_response`, `created_at`) 
                 VALUES (:qid, :ticket_id, :recipient, :subject, 'success', :resp, NOW())",
                [
                    'qid'       => $queueId,
                    'ticket_id' => $item['ticket_id'],
                    'recipient' => $item['recipient_email'],
                    'subject'   => $item['subject'],
                    'resp'      => $result['response']
                ]
            );
        } else {
            $failedCount++;
            $errorMsg = $result['error'] ?? 'Unknown SMTP error';
            $newAttempts = (int)$item['attempts'] + 1;
            $maxAttempts = (int)$item['max_attempts'];

            echo "  ✖ [FAILED] Error: {$errorMsg}\n";

            if ($newAttempts >= $maxAttempts) {
                // Permanent failure
                echo "    -> Max attempt limit reached ({$newAttempts}/{$maxAttempts}). Marking queue status as 'failed'.\n";
                Database::query(
                    "UPDATE `email_queue` 
                     SET `status` = 'failed', `attempts` = :att, `last_error` = :err 
                     WHERE `id` = :id",
                    ['att' => $newAttempts, 'err' => $errorMsg, 'id' => $queueId]
                );

                Database::query(
                    "INSERT INTO `email_logs` (`queue_id`, `ticket_id`, `recipient_email`, `subject`, `status`, `error_message`, `created_at`) 
                     VALUES (:qid, :ticket_id, :recipient, :subject, 'failed', :err, NOW())",
                    [
                        'qid'       => $queueId,
                        'ticket_id' => $item['ticket_id'],
                        'recipient' => $item['recipient_email'],
                        'subject'   => $item['subject'],
                        'err'       => $errorMsg
                    ]
                );
            } else {
                // Temporary failure: reschedule with 5-minute backoff
                $rescheduleTime = date('Y-m-d H:i:s', time() + 300);
                echo "    -> Rescheduling attempt ({$newAttempts}/{$maxAttempts}) for {$rescheduleTime}.\n";

                Database::query(
                    "UPDATE `email_queue` 
                     SET `status` = 'pending', `attempts` = :att, `scheduled_at` = :sched, `last_error` = :err 
                     WHERE `id` = :id",
                    ['att' => $newAttempts, 'sched' => $rescheduleTime, 'err' => $errorMsg, 'id' => $queueId]
                );

                Database::query(
                    "INSERT INTO `email_logs` (`queue_id`, `ticket_id`, `recipient_email`, `subject`, `status`, `error_message`, `created_at`) 
                     VALUES (:qid, :ticket_id, :recipient, :subject, 'retrying', :err, NOW())",
                    [
                        'qid'       => $queueId,
                        'ticket_id' => $item['ticket_id'],
                        'recipient' => $item['recipient_email'],
                        'subject'   => $item['subject'],
                        'err'       => $errorMsg
                    ]
                );
            }
        }
    }

    echo "\nBatch processing complete: {$sentCount} sent, {$failedCount} failed/retrying.\n";

} catch (Throwable $e) {
    echo "[CRITICAL ERROR] Queue processing failed: " . $e->getMessage() . "\n";
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
