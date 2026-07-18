<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Config;

/**
 * Authenticated encryption (AES-256-GCM) for data at rest, plus a set of
 * hashing / token helpers used across the app.
 *
 * Message keys are derived per-conversation:
 *   key = HKDF-SHA256(APP_KEY, salt = link.secret, info = "walkie-msg-v1")
 * so a leak of one conversation key never exposes the master key or others.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const INFO   = 'walkie-msg-v1';

    private static function masterKey(): string
    {
        $b64 = (string) Config::get('app.key', '');
        $key = base64_decode($b64, true);
        if ($key === false || strlen($key) < 32) {
            throw new \RuntimeException('APP_KEY missing or too short (need 32 bytes base64).');
        }
        return substr($key, 0, 32);
    }

    /** Derive a 256-bit message key from a per-link secret. */
    public static function deriveKey(string $linkSecret): string
    {
        return hash_hkdf('sha256', self::masterKey(), 32, self::INFO, $linkSecret);
    }

    /**
     * Encrypt bytes with a derived key. Output = iv(12) || tag(16) || ciphertext.
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new \RuntimeException('Encryption failed.');
        }
        return $iv . $tag . $ct;
    }

    /** Reverse of encrypt(). Returns null if authentication fails. */
    public static function decrypt(string $blob, string $key): ?string
    {
        if (strlen($blob) < 28) {
            return null;
        }
        $iv  = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct  = substr($blob, 28);
        $pt = openssl_decrypt($ct, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $pt === false ? null : $pt;
    }

    // --- Tokens & hashing -------------------------------------------------

    /** URL-safe random token (bearer / pairing). */
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /** Deterministic SHA-256 (hex) for token lookups. */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /** Cryptographically strong 6-digit numeric code. */
    public static function numericCode(int $digits = 6): string
    {
        $max = (10 ** $digits) - 1;
        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    public static function equals(string $a, string $b): bool
    {
        return hash_equals($a, $b);
    }
}
