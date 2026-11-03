<?php
/**
 * Inbound Email Processor (Cron Job & Task Scheduler Script)
 * 
 * Retrieves incoming email replies via IMAP, validates tokens and authorized senders,
 * and threads valid responses directly into tickets.
 * 
 * Usage:
 *   Windows (Task Scheduler / PowerShell / CMD):
 *     php C:\xampp\htdocs\helpdesk-private\cron\process_incoming_emails.php
 * 
 *   Linux / cPanel (Cron Job):
 *     * * * * * /usr/local/bin/php /home/username/helpdesk-private/cron/process_incoming_emails.php >/dev/null 2>&1
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Direct web access prohibited. This script must be executed via CLI, Cron Job, or Task Scheduler.\n");
}

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/database/Database.php';
require_once dirname(__DIR__) . '/mail/IMAPClient.php';

$lockFile = config('app.paths.storage', dirname(__DIR__) . '/storage') . '/cron_incoming.lock';
$lockHandle = fopen($lockFile, 'c');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] Process already running. Lock acquisition failed. Exiting.\n";
    exit(0);
}

echo "============================================================\n";
echo " Inbound Email Processor (IMAP Ingestion)\n";
echo " Time: " . date('Y-m-d H:i:s T') . "\n";
echo "============================================================\n\n";

try {
    $batchSetting = Database::fetchOne(
        "SELECT `setting_value` FROM `system_settings` WHERE `setting_key` = 'email_inbound_batch_size'"
    );
    $batchSize = $batchSetting ? (int)$batchSetting['setting_value'] : 30;

    $imapClient = new IMAPClient();
    $result = $imapClient->processInbound($batchSize);

    echo "Incoming processing summary:\n";
    echo " - Total Processed : " . ($result['processed'] ?? 0) . "\n";
    echo " - Accepted Replies : " . ($result['accepted'] ?? 0) . "\n";
    echo " - Rejected / Ignored: " . ($result['rejected'] ?? 0) . "\n";

    if (!empty($result['errors'])) {
        echo "\nEncountered warnings/errors:\n";
        foreach ($result['errors'] as $err) {
            echo " - {$err}\n";
        }
    }

    if (!empty($result['message'])) {
        echo "Info: {$result['message']}\n";
    }

} catch (Throwable $e) {
    echo "[CRITICAL ERROR] IMAP ingestion failed: " . $e->getMessage() . "\n";
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}
