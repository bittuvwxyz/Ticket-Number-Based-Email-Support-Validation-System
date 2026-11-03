<?php
/**
 * Safe MIME Parser & Reply Quoting Stripper
 * 
 * Extracts headers, plain/HTML text, attachments, and safely cleans
 * standard email reply quoting chains.
 */

declare(strict_types=1);

class MIMEParser
{
    /**
     * Decodes MIME encoded headers (e.g. =?UTF-8?B?...?=).
     */
    public static function decodeHeader(string $text): string
    {
        $elements = imap_mime_header_decode($text);
        $result = '';
        foreach ($elements as $element) {
            $charset = strtolower($element->charset);
            $textPart = $element->text;
            if ($charset !== 'default' && $charset !== 'utf-8') {
                $converted = @mb_convert_encoding($textPart, 'UTF-8', $charset);
                if ($converted !== false) {
                    $textPart = $converted;
                }
            }
            $result .= $textPart;
        }
        return trim($result);
    }

    /**
     * Strips email reply quotation lines so only the customer's fresh reply is preserved.
     */
    public static function stripQuotes(string $body): string
    {
        // Normalize line breaks
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        $patterns = [
            // Standard "On [date], [user] wrote:"
            '/^On\s+.+wrote:$/im',
            // Outlook style "From: ... Sent: ... To: ..."
            '/^-+\s*Original Message\s*-+$/im',
            '/^_{10,}$/m',
            // Quoted email headers
            '/^From:\s+.+$/im',
        ];

        $lines = explode("\n", $body);
        $cleanLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Stop processing if an email reply quote header is reached
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $trimmed)) {
                    break 2;
                }
            }

            // Skip lines that start with quotation prefix ">"
            if (str_starts_with($trimmed, '>')) {
                continue;
            }

            $cleanLines[] = $line;
        }

        $result = trim(implode("\n", $cleanLines));
        return !empty($result) ? $result : trim($body);
    }

    /**
     * Extracts plain text and HTML from an IMAP email message.
     */
    public static function getMessageBody($stream, int $msgNumber): array
    {
        $structure = imap_fetchstructure($stream, $msgNumber);
        $plain = '';
        $html = '';

        if (empty($structure->parts)) {
            // Single part message
            $body = imap_body($stream, $msgNumber);
            $decoded = self::decodeBody($body, $structure->encoding);
            if ($structure->subtype === 'HTML') {
                $html = $decoded;
                $plain = strip_tags($decoded);
            } else {
                $plain = $decoded;
            }
        } else {
            // Multipart message
            self::extractMultipart($stream, $msgNumber, $structure->parts, '', $plain, $html);
        }

        return [
            'plain' => self::stripQuotes($plain),
            'html'  => $html
        ];
    }

    private static function extractMultipart($stream, int $msgNumber, array $parts, string $prefix, string &$plain, string &$html): void
    {
        foreach ($parts as $index => $part) {
            $partNumber = $prefix ? ($prefix . '.' . ($index + 1)) : (string)($index + 1);

            if ($part->type === 0) { // Text
                $data = imap_fetchbody($stream, $msgNumber, $partNumber);
                $decoded = self::decodeBody($data, $part->encoding);

                if (strtoupper($part->subtype) === 'PLAIN' && empty($plain)) {
                    $plain = $decoded;
                } elseif (strtoupper($part->subtype) === 'HTML' && empty($html)) {
                    $html = $decoded;
                }
            } elseif (!empty($part->parts)) {
                self::extractMultipart($stream, $msgNumber, $part->parts, $partNumber, $plain, $html);
            }
        }
    }

    private static function decodeBody(string $data, int $encoding): string
    {
        switch ($encoding) {
            case 3: // BASE64
                return base64_decode($data);
            case 4: // QUOTED-PRINTABLE
                return quoted_printable_decode($data);
            default:
                return $data;
        }
    }
}
