<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Config;

/**
 * Sends the 6-digit login code by email through the configured SMTP relay.
 *
 * Delivery chain: SMTP relay → PHP mail() fallback → storage/mail.log.
 * The code is never exposed to the client unless app.debug is enabled.
 */
final class Mailer
{
    public static function sendLoginCode(string $email, string $code): void
    {
        $subject = 'Walkie — tu código de acceso';
        $body = "Tu código de acceso a Walkie es: {$code}\n\n"
              . "Caduca en 5 minutos. Si no lo has pedido, ignora este correo.\n";

        if ((bool) Config::get('mail.log_only', false)) {
            self::log($email, $code);
            return;
        }

        $from     = (string) Config::get('mail.from', 'no-reply@localhost');
        $fromName = (string) Config::get('mail.from_name', 'Walkie');
        $host     = (string) Config::get('mail.smtp.host', '');

        if ($host !== '') {
            try {
                (new SmtpClient(
                    $host,
                    (int) Config::get('mail.smtp.port', 587),
                    (string) Config::get('mail.smtp.security', 'tls'),
                    (string) Config::get('mail.smtp.username', ''),
                    (string) Config::get('mail.smtp.password', ''),
                ))->send($from, $fromName, $email, $subject, $body);
                return;
            } catch (\Throwable $e) {
                self::log($email, $code, 'smtp: ' . $e->getMessage());
            }
        }

        $headers = implode("\r\n", [
            "From: {$fromName} <{$from}>",
            'Content-Type: text/plain; charset=utf-8',
            'MIME-Version: 1.0',
        ]);
        if (!@mail($email, $subject, $body, $headers)) {
            self::log($email, $code, 'mail() failed');
        }
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
