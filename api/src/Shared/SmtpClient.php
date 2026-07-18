<?php
declare(strict_types=1);

namespace Walkie\Shared;

/**
 * Minimal RFC 5321 SMTP client — native PHP streams, no dependencies.
 * Supports STARTTLS and AUTH LOGIN, which is what relays like Brevo expect.
 */
final class SmtpClient
{
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $security, // 'tls' (STARTTLS), 'ssl' or 'none'
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout = 12,
    ) {
    }

    /**
     * Send one plain-text message.
     * @throws \RuntimeException on any SMTP failure
     */
    public function send(string $from, string $fromName, string $to, string $subject, string $body): void
    {
        $remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $this->socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['SNI_enabled' => true]])
        );
        if (!$this->socket) {
            throw new \RuntimeException("SMTP connect failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($this->socket, $this->timeout);

        try {
            $this->expect(220);
            $this->command('EHLO ' . gethostname(), 250);

            if ($this->security === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $this->command('EHLO ' . gethostname(), 250);
            }

            $this->command('AUTH LOGIN', 334);
            $this->command(base64_encode($this->username), 334);
            $this->command(base64_encode($this->password), 235);

            $this->command('MAIL FROM:<' . $from . '>', 250);
            $this->command('RCPT TO:<' . $to . '>', 250);
            $this->command('DATA', 354);

            $headers = [
                'Date: ' . gmdate('r'),
                'From: ' . self::encodeHeader($fromName) . " <{$from}>",
                'To: <' . $to . '>',
                'Subject: ' . self::encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: 8bit',
                'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . explode('@', $from)[1] . '>',
            ];
            // Normalise the body to CRLF and dot-stuff (RFC 5321 §4.5.2).
            // Headers are already CRLF-joined, so normalise the body on its
            // own and concatenate — a global \n→\r\n pass would turn the
            // header CRLFs into CR-CR-LF ("554 Header parsing error").
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $body = preg_replace('/^\./m', '..', $body);
            $body = str_replace("\n", "\r\n", $body);

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $this->write($data . "\r\n.");
            $this->expect(250);
            $this->command('QUIT', 221);
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $line, int $expectCode): void
    {
        $this->write($line);
        $this->expect($expectCode);
    }

    private function write(string $line): void
    {
        if (fwrite($this->socket, $line . "\r\n") === false) {
            throw new \RuntimeException('SMTP write failed');
        }
    }

    private function expect(int $code): void
    {
        $reply = '';
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { // last line of multi-line reply
                break;
            }
        }
        if ((int) substr($reply, 0, 3) !== $code) {
            throw new \RuntimeException('SMTP unexpected reply: ' . trim($reply));
        }
    }

    private static function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }
}
