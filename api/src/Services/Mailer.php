<?php
declare(strict_types=1);

namespace Walkie\Services;

use Walkie\Core\Config;

/**
 * Sends the 6-digit login code by email.
 *
 * Uses PHP mail(). On hosts where mail() is unavailable (or when
 * mail.log_only is enabled) the code is written to storage/mail.log so the
 * flow can still be exercised. The code is NEVER returned to the client
 * unless app.debug is also on (development only).
 */
final class Mailer
{
    public static function sendLoginCode(string $email, string $code): void
    {
        $from     = (string) Config::get('mail.from', 'no-reply@localhost');
        $fromName = (string) Config::get('mail.from_name', 'Walkie');
        $logOnly  = (bool) Config::get('mail.log_only', false);

        $subject = 'Walkie — your access code';
        $body = "Your Walkie access code is: {$code}\n\n"
              . "It expires in 5 minutes. If you didn't request it, ignore this email.\n";

        $headers = implode("\r\n", [
            'From: ' . self::encodeHeader($fromName) . " <{$from}>",
            'Reply-To: ' . $from,
            'Content-Type: text/plain; charset=utf-8',
            'MIME-Version: 1.0',
            'X-Mailer: Walkie',
        ]);

        if ($logOnly) {
            self::log($email, $code);
            return;
        }

        $ok = @mail($email, self::encodeHeader($subject), $body, $headers);
        if (!$ok) {
            // Fallback so the account is never permanently locked out.
            self::log($email, $code, 'mail() failed');
        }
    }

    private static function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function log(string $email, string $code, string $note = ''): void
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = sprintf("[%s] %s code=%s %s\n", gmdate('c'), $email, $code, $note);
        @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
    }
}
