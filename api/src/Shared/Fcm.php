<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Config;

/**
 * Firebase Cloud Messaging sender (HTTP v1). Authenticates with a Google
 * service account (RS256 JWT → OAuth2 access token) and delivers a push to a
 * device token. No third-party libraries; disabled and inert until a
 * service-account JSON is configured (fcm.credentials).
 *
 * Privacy: notifications carry only the sender's name — never message content
 * (which stays encrypted at rest). The client fetches the actual message.
 */
final class Fcm
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public static function enabled(): bool
    {
        $path = (string) Config::get('fcm.credentials', '');
        return $path !== '' && is_file($path);
    }

    /** Best-effort send. Returns true on HTTP 200 from FCM. */
    public static function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        $creds = self::credentials();
        if ($creds === null) {
            return false;
        }
        $accessToken = self::accessToken($creds);
        if ($accessToken === null) {
            return false;
        }

        $payload = [
            'message' => [
                'token'        => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => ['priority' => 'HIGH'],
            ],
        ];

        [$code, $resp] = self::http(
            'POST',
            "https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send",
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']
        );

        // Prune tokens the device unregistered / that are invalid.
        if ($code === 404 || ($code === 400 && str_contains((string) $resp, 'INVALID_ARGUMENT'))) {
            self::forget($deviceToken);
        }
        return $code === 200;
    }

    /** @return array{client_email:string, private_key:string, project_id:string, token_uri:string}|null */
    private static function credentials(): ?array
    {
        $path = (string) Config::get('fcm.credentials', '');
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            return null;
        }
        return [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'project_id'   => (string) ($json['project_id'] ?? Config::get('fcm.project_id', '')),
            'token_uri'    => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }

    /** OAuth2 access token via service-account JWT, cached on disk until expiry. */
    private static function accessToken(array $creds): ?string
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/fcm_token.json';
        $cached = @json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 60 && !empty($cached['token'])) {
            return (string) $cached['token'];
        }

        $now = time();
        $jwt = self::signRs256([
            'iss'   => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $creds['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $creds['private_key']);
        if ($jwt === null) {
            return null;
        }

        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);
        [$code, $resp] = self::http('POST', $creds['token_uri'], $body, ['Content-Type: application/x-www-form-urlencoded']);
        if ($code !== 200) {
            return null;
        }
        $data = json_decode((string) $resp, true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        if (!$token) {
            return null;
        }
        $exp = $now + (int) ($data['expires_in'] ?? 3600);
        @file_put_contents($cacheFile, json_encode(['token' => $token, 'exp' => $exp]), LOCK_EX);
        return (string) $token;
    }

    private static function signRs256(array $claims, string $privateKey): ?string
    {
        $segments = [
            self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $signingInput = implode('.', $segments);
        $signature = '';
        if (!@openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return $signingInput . '.' . self::b64($signature);
    }

    private static function forget(string $token): void
    {
        try {
            Database::pdo()->prepare('DELETE FROM devices WHERE token_hash = ?')
                ->execute([Crypto::hash($token)]);
        } catch (\Throwable $e) {
            // best effort
        }
    }

    /** @return array{0:int,1:string} [httpCode, body] */
    private static function http(string $method, string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, is_string($resp) ? $resp : ''];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
