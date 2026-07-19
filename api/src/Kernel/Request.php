<?php
declare(strict_types=1);

namespace Walkie\Kernel;

/**
 * Immutable snapshot of the incoming HTTP request.
 */
final class Request
{
    public readonly string $method;
    public readonly string $path;       // route path, no /api prefix, no query
    public readonly array  $query;
    public readonly string $ip;
    public readonly string $userAgent;
    private ?array $json = null;
    private ?array $form = null;
    private readonly string $rawBody;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query  = $_GET;
        $this->ip     = self::clientIp();
        $this->userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $this->rawBody = (string) file_get_contents('php://input');
        $this->path   = self::resolvePath();
    }

    /**
     * Derive the route path relative to the api/ directory, regardless of
     * whether the host passes PATH_INFO, a ?route= param, or the full URI.
     */
    private static function resolvePath(): string
    {
        // Preferred: the rewrite rule provides ?__route=/foo
        if (isset($_GET['__route']) && is_string($_GET['__route'])) {
            $p = $_GET['__route'];
        } else {
            $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $uri  = explode('?', $uri, 2)[0];
            // Strip everything up to and including the api base directory.
            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
            if ($base !== '' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }
            $p = $uri;
        }
        $p = '/' . trim($p, '/');
        return $p === '/' ? '/' : rtrim($p, '/');
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        $decoded = json_decode($this->rawBody, true);
        return $this->json = is_array($decoded) ? $decoded : [];
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->json();
        return $body[$key] ?? $default;
    }

    /**
     * Read an application/x-www-form-urlencoded body parameter (OAuth 2.0 token
     * and revocation endpoints require form encoding, RFC 6749 §4.5 / §3.2).
     */
    public function form(string $key, mixed $default = null): mixed
    {
        if ($this->form === null) {
            if (!empty($_POST)) {
                $this->form = $_POST;
            } else {
                parse_str($this->rawBody, $parsed);
                $this->form = is_array($parsed) ? $parsed : [];
            }
        }
        return $this->form[$key] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (preg_match('/Bearer\s+(\S+)/i', (string) $header, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function clientIp(): string
    {
        // Only trust REMOTE_ADDR by default; proxy headers are spoofable.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
