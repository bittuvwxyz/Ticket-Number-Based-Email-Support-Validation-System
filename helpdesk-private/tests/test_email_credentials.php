<?php
/**
 * Email Credential & Connectivity Diagnostic Tester
 * 
 * Tests both SMTP and IMAP connections against Gmail using the credentials from email.txt.
 */

declare(strict_types=1);

echo "============================================================\n";
echo " Email Credentials Diagnostic Test\n";
echo "============================================================\n\n";

$emailTxtPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'email.txt';

if (!file_exists($emailTxtPath)) {
    echo "[ERROR] email.txt not found at {$emailTxtPath}\n";
    exit(1);
}

// Parse email.txt
$lines = file($emailTxtPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$config = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (str_contains($line, '=')) {
        [$k, $v] = explode('=', $line, 2);
        $config[trim($k)] = trim($v);
    }
}

$smtpHost = $config['SMTP_HOST'] ?? 'smtp.gmail.com';
$smtpPort = (int)($config['SMTP_PORT'] ?? 587);
$smtpUser = $config['SMTP_USER'] ?? '';
$smtpPass = $config['SMTP_PASS'] ?? '';
$smtpSecure = strtolower($config['SMTP_SECURE'] ?? 'tls');
$fromEmail = $config['MAIL_FROM_EMAIL'] ?? $smtpUser;
$fromName = $config['MAIL_FROM_NAME'] ?? 'Support Desk';

$imapHost = 'imap.gmail.com';
$imapPort = 993;

echo "Loaded Configuration:\n";
echo " - Account Email : {$smtpUser}\n";
echo " - App Password  : " . substr($smtpPass, 0, 4) . "********" . substr($smtpPass, -4) . " (" . strlen($smtpPass) . " chars)\n";
echo " - SMTP Server   : {$smtpHost}:{$smtpPort} ({$smtpSecure})\n";
echo " - IMAP Server   : {$imapHost}:{$imapPort} (SSL)\n\n";

// -------------------------------------------------------------
// 1. SMTP Test via Raw Sockets (RFC 5321 & RFC 4954)
// -------------------------------------------------------------
echo "1. Testing SMTP Connection & Authentication...\n";

function testSmtp(string $host, int $port, string $user, string $pass, string $secure): array
{
    $timeout = 10;
    $errno = 0;
    $errstr = '';

    $target = ($secure === 'ssl') ? "ssl://{$host}" : "tcp://{$host}";
    $socket = @fsockopen($target, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        return ['status' => false, 'step' => 'Socket Connect', 'error' => "Failed to connect to {$host}:{$port} - {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    $read = function () use ($socket): string {
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($response);
    };

    $send = function (string $cmd) use ($socket, $read): string {
        fputs($socket, $cmd . "\r\n");
        return $read();
    };

    // Initial greeting
    $greeting = $read();
    if (!str_starts_with($greeting, '220')) {
        fclose($socket);
        return ['status' => false, 'step' => 'Server Greeting', 'error' => $greeting];
    }

    // EHLO
    $ehlo = $send("EHLO localhost");
    if (!str_starts_with($ehlo, '250')) {
        fclose($socket);
        return ['status' => false, 'step' => 'EHLO', 'error' => $ehlo];
    }

    // STARTTLS if port 587
    if ($secure === 'tls' || $port === 587) {
        $starttls = $send("STARTTLS");
        if (!str_starts_with($starttls, '220')) {
            fclose($socket);
            return ['status' => false, 'step' => 'STARTTLS', 'error' => $starttls];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            return ['status' => false, 'step' => 'TLS Handshake', 'error' => 'Failed to negotiate TLS encryption'];
        }

        // Re-send EHLO after TLS negotiation
        $ehlo2 = $send("EHLO localhost");
        if (!str_starts_with($ehlo2, '250')) {
            fclose($socket);
            return ['status' => false, 'step' => 'Post-TLS EHLO', 'error' => $ehlo2];
        }
    }

    // AUTH LOGIN
    $authResp = $send("AUTH LOGIN");
    if (!str_starts_with($authResp, '334')) {
        fclose($socket);
        return ['status' => false, 'step' => 'AUTH LOGIN Init', 'error' => $authResp];
    }

    // Send base64 username
    $userResp = $send(base64_encode($user));
    if (!str_starts_with($userResp, '334')) {
        fclose($socket);
        return ['status' => false, 'step' => 'Username Submission', 'error' => $userResp];
    }

    // Send base64 password
    $passResp = $send(base64_encode($pass));
    if (!str_starts_with($passResp, '235')) {
        fclose($socket);
        return ['status' => false, 'step' => 'Password Verification', 'error' => $passResp];
    }

    // QUIT
    $send("QUIT");
    fclose($socket);

    return ['status' => true, 'response' => $passResp];
}

$smtpResult = testSmtp($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpSecure);
if ($smtpResult['status']) {
    echo "  [SUCCESS] SMTP Authentication Successful!\n";
    echo "  Server response: " . $smtpResult['response'] . "\n\n";
} else {
    echo "  [FAILED] SMTP Failed at step '{$smtpResult['step']}':\n";
    echo "  Error: " . $smtpResult['error'] . "\n\n";
}

// -------------------------------------------------------------
// 2. IMAP Test
// -------------------------------------------------------------
echo "2. Testing IMAP Connection & Authentication...\n";

if (function_exists('imap_open')) {
    $mailbox = "{" . $imapHost . ":" . $imapPort . "/imap/ssl/novalidate-cert}INBOX";
    // Suppress warnings to catch errors cleanly
    $imapStream = @imap_open($mailbox, $smtpUser, $smtpPass, OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

    if ($imapStream) {
        $check = imap_check($imapStream);
        echo "  [SUCCESS] IMAP Authentication Successful!\n";
        echo "  Connected to INBOX successfully.\n";
        echo "  Total messages in mailbox: " . ($check->Nmsgs ?? 0) . "\n";
        echo "  Recent messages: " . ($check->Rdate ?? 'N/A') . "\n\n";
        imap_close($imapStream);
        $imapOk = true;
    } else {
        $lastError = imap_last_error();
        echo "  [FAILED] IMAP Connection failed.\n";
        echo "  Error: " . ($lastError ?: 'Unknown error') . "\n\n";
        $imapOk = false;
    }
} else {
    echo "  [INFO] imap_open() function not loaded in this PHP process, falling back to raw IMAP SSL socket test...\n";
    // Direct SSL socket authentication to IMAP server
    $imapSocket = @fsockopen("ssl://{$imapHost}", $imapPort, $errno, $errstr, 10);
    if (!$imapSocket) {
        echo "  [FAILED] Cannot connect to ssl://{$imapHost}:{$imapPort}: {$errstr} ({$errno})\n\n";
        $imapOk = false;
    } else {
        $banner = fgets($imapSocket, 512);
        // Send IMAP LOGIN command: A001 LOGIN username password
        fputs($imapSocket, "A001 LOGIN \"{$smtpUser}\" \"{$smtpPass}\"\r\n");
        $loginResponse = '';
        while ($line = fgets($imapSocket, 512)) {
            $loginResponse .= $line;
            if (str_starts_with($line, 'A001 ')) {
                break;
            }
        }
        fputs($imapSocket, "A002 LOGOUT\r\n");
        fclose($imapSocket);

        if (str_contains($loginResponse, 'A001 OK')) {
            echo "  [SUCCESS] IMAP Socket Authentication Successful!\n";
            echo "  Server response: " . trim($loginResponse) . "\n\n";
            $imapOk = true;
        } else {
            echo "  [FAILED] IMAP Login Rejected:\n";
            echo "  Server response: " . trim($loginResponse) . "\n\n";
            $imapOk = false;
        }
    }
}

// -------------------------------------------------------------
// Overall Summary
// -------------------------------------------------------------
echo "============================================================\n";
echo " Diagnostic Summary\n";
echo "============================================================\n";
echo "SMTP Outbound Delivery: " . ($smtpResult['status'] ? "VERIFIED & WORKING" : "FAILED") . "\n";
echo "IMAP Inbound Retrieval: " . ($imapOk ? "VERIFIED & WORKING" : "FAILED") . "\n";
