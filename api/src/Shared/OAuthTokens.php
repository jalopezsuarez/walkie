<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Config;
use Walkie\Kernel\Database;

/**
 * OAuth 2.0 token service.
 *
 * Access tokens are self-contained signed JWTs (RFC 7519); refresh tokens are
 * opaque random strings stored only as a SHA-256 hash (RFC 6749 §1.5, §10.4).
 * Refresh tokens rotate on use.
 */
final class OAuthTokens
{
    public const SCOPE = 'walkie';

    /**
     * Issue a fresh access + refresh token pair for a user.
     * @return array{access:string, refresh:string, expires_in:int}
     */
    public static function issue(int $userId): array
    {
        $accessTtl = (int) Config::get('oauth.access_ttl', 3600);          // 1 h
        $refreshTtl = (int) Config::get('oauth.refresh_ttl', 2592000);      // 30 d
        $now = time();

        $access = Jwt::encode([
            'iss'   => (string) Config::get('app.web_origin', 'walkie'),
            'sub'   => (string) $userId,
            'iat'   => $now,
            'exp'   => $now + $accessTtl,
            'scope' => self::SCOPE,
            'jti'   => bin2hex(random_bytes(8)),
        ], self::key());

        $refresh = Crypto::token(32);
        Database::pdo()->prepare(
            'INSERT INTO oauth_refresh_tokens (user_id, token_hash, created_at, expires_at)
             VALUES (?, ?, UTC_TIMESTAMP(), (UTC_TIMESTAMP() + INTERVAL ? SECOND))'
        )->execute([$userId, Crypto::hash($refresh), $refreshTtl]);

        return ['access' => $access, 'refresh' => $refresh, 'expires_in' => $accessTtl];
    }

    /** Resolve the user id from a bearer access token (JWT), or null. */
    public static function userFromAccess(string $jwt): ?int
    {
        $claims = Jwt::decode($jwt, self::key());
        if ($claims === null || !isset($claims['sub']) || !ctype_digit((string) $claims['sub'])) {
            return null;
        }
        return (int) $claims['sub'];
    }

    /**
     * Redeem (and rotate) a refresh token: returns the user id and deletes the
     * token so it cannot be reused. Null if unknown/expired.
     */
    public static function redeemRefresh(string $refresh): ?int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_id FROM oauth_refresh_tokens
              WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
        );
        $stmt->execute([Crypto::hash($refresh)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $pdo->prepare('DELETE FROM oauth_refresh_tokens WHERE id = ?')->execute([$row['id']]);
        return (int) $row['user_id'];
    }

    /** Revoke a refresh token (RFC 7009). No-op if unknown. */
    public static function revoke(string $refresh): void
    {
        Database::pdo()
            ->prepare('DELETE FROM oauth_refresh_tokens WHERE token_hash = ?')
            ->execute([Crypto::hash($refresh)]);
    }

    private static function key(): string
    {
        $k = (string) Config::get('oauth.jwt_key', '');
        return $k !== '' ? $k : (string) Config::get('app.key', 'insecure-dev-key');
    }
}
