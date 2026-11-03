<?php
/**
 * Email Credential & Connectivity Diagnostic Tester
 * 
 * Tests both SMTP and IMAP connections against the configured mail server in .env.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

echo "============================================================\n";
echo " Email Credentials Diagnostic Test\n";
echo "============================================================\n\n";

// Load configuration dynamically from .env / config
$smtpHost   = config('mail.smtp.host', 'websitesting.shop');
$smtpPort   = (int)config('mail.smtp.port', 465);
$smtpUser   = config('mail.smtp.username', 'mail@websitesting.shop');
$smtpPass   = config('mail.smtp.password', '');
$smtpSecure = strtolower(config('mail.smtp.encryption', 'ssl'));
$fromEmail  = config('mail.from.address', $smtpUser);
$fromName   = config('mail.from.name', 'Support Desk');

$imapHost   = config('mail.imap.host', 'websitesting.shop');
$imapPort   = (int)config('mail.imap.port', 993);
$imapUser   = config('mail.imap.username', 'mail@websitesting.shop');
$imapPass   = config('mail.imap.password', '');
$imapSecure = strtolower(config('mail.imap.encryption', 'ssl'));

echo "Loaded Configuration (from .env):\n";
echo " - Account Email : {$smtpUser}\n";
echo " - Password Set  : " . (!empty($smtpPass) && $smtpPass !== 'secure_email_password' ? 'YES (' . strlen($smtpPass) . ' chars)' : 'NO / PLACEHOLDER') . "\n";
echo " - SMTP Server   : {$smtpHost}:{$smtpPort} ({$smtpSecure})\n";
echo " - IMAP Server   : {$imapHost}:{$imapPort} ({$imapSecure})\n\n";

if (empty($smtpPass) || $smtpPass === 'secure_email_password') {
    echo "[WARNING] MAIL_SMTP_PASSWORD / MAIL_IMAP_PASSWORD in helpdesk-private/.env is still set to the placeholder 'secure_email_password'!\n";
    echo "Please set your real email account password in helpdesk-private/.env to test live authentication.\n\n";
}

// -------------------------------------------------------------
// 1. SMTP Test via Raw Sockets (RFC 5321 & RFC 4954)
// -------------------------------------------------------------
echo "1. Testing SMTP Connection & Authentication...\n";

function testSmtp(string $host, int $port, string $user, string $pass, string $secure): array
{
    $timeout = 5;
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
    echo "  Error: " . $smtpResult['error'] . "\n";
    if (str_contains($smtpResult['error'], 'Failed to connect') || str_contains($smtpResult['error'], 'timed out')) {
        echo "  [TIP] If {$smtpHost} is behind Cloudflare, Cloudflare's proxy blocks mail ports (465, 587, 993).\n";
        echo "        Use your direct cPanel server IP or an unproxied 'DNS only' subdomain (e.g. mail.{$smtpHost}) in .env.\n";
    }
    echo "\n";
}

// -------------------------------------------------------------
// 2. IMAP Test
// -------------------------------------------------------------
echo "2. Testing IMAP Connection & Authentication...\n";

if (function_exists('imap_open')) {
    imap_timeout(IMAP_OPENTIMEOUT, 5);
    $mailbox = "{" . $imapHost . ":" . $imapPort . "/imap/ssl/novalidate-cert}INBOX";
    $imapStream = @imap_open($mailbox, $imapUser, $imapPass, OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);

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
        echo "  Error: " . ($lastError ?: 'Unknown error') . "\n";
        if (str_contains($lastError ?: '', 'Certificate failure') || str_contains($lastError ?: '', 'Host not found') || str_contains($lastError ?: '', 'Connection refused') || empty($lastError)) {
            echo "  [TIP] If {$imapHost} is behind Cloudflare, create an unproxied DNS-only A record (e.g. mail.{$imapHost}) or use the cPanel server IP in .env.\n";
        }
        echo "\n";
        $imapOk = false;
    }
} else {
    echo "  [INFO] imap_open() function not loaded in this PHP process, falling back to raw IMAP SSL socket test...\n";
    $imapSocket = @fsockopen("ssl://{$imapHost}", $imapPort, $errno, $errstr, 10);
    if (!$imapSocket) {
        echo "  [FAILED] Cannot connect to ssl://{$imapHost}:{$imapPort}: {$errstr} ({$errno})\n";
        echo "  [TIP] Ensure port {$imapPort} is open and not blocked by Cloudflare proxy or hosting firewall.\n\n";
        $imapOk = false;
    } else {
        $banner = fgets($imapSocket, 512);
        fputs($imapSocket, "A001 LOGIN \"{$imapUser}\" \"{$imapPass}\"\r\n");
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
