<?php
declare(strict_types=1);

namespace Walkie\Shared;

/**
 * Minimal, dependency-free JSON Web Token (RFC 7519) with HS256 (RFC 7515).
 * Compact, constant-time signature check, no external libraries.
 */
final class Jwt
{
    /** @param array<string,mixed> $claims */
    public static function encode(array $claims, string $key): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signingInput = $header . '.' . $payload;
        $sig = self::b64(hash_hmac('sha256', $signingInput, $key, true));
        return $signingInput . '.' . $sig;
    }

    /**
     * Verify signature + exp/nbf and return the claims, or null if invalid.
     * @return array<string,mixed>|null
     */
    public static function decode(string $jwt, string $key): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $expected = hash_hmac('sha256', $h . '.' . $p, $key, true);
        $actual = self::b64d($s);
        if ($actual === null || !hash_equals($expected, $actual)) {
            return null;
        }

        $header = json_decode((string) self::b64d($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }
        $claims = json_decode((string) self::b64d($p), true);
        if (!is_array($claims)) {
            return null;
        }
        $now = time();
        if (isset($claims['exp']) && $now >= (int) $claims['exp']) {
            return null;
        }
        if (isset($claims['nbf']) && $now < (int) $claims['nbf']) {
            return null;
        }
        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64d(string $data): ?string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
