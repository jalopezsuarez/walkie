<?php
declare(strict_types=1);

namespace Walkie\Kernel;

/**
 * Exception carrying an HTTP status + machine-readable error code.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = '',
        public readonly array $extra = []
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public static function badRequest(string $message, string $code = 'bad_request'): self
    {
        return new self(400, $code, $message);
    }

    public static function unauthorized(string $message = 'Authentication required'): self
    {
        return new self(401, 'unauthorized', $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, 'forbidden', $message);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, 'not_found', $message);
    }

    public static function tooMany(string $message, int $retryAfter): self
    {
        return new self(429, 'rate_limited', $message, ['retry_after' => $retryAfter]);
    }
}
