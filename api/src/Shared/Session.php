<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Features\Auth\UserAccount;
use Walkie\Kernel\ApiException;
use Walkie\Kernel\Config;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;

/**
 * Bearer-token session authentication.
 * Tokens are random 256-bit strings; only their SHA-256 hash is stored.
 */
final class Session
{
    /** Create a session for a user and return the plaintext token. */
    public static function create(int $userId, Request $req): string
    {
        $token = Crypto::token(32);
        $ttl = (int) Config::get('ttl.session', 2592000);

        $ipBin = @inet_pton($req->ip) ?: null;

        $stmt = Database::pdo()->prepare(
            'INSERT INTO sessions (user_id, token_hash, ip, user_agent, created_at, last_seen, expires_at)
             VALUES (:u, :h, :ip, :ua, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                     (UTC_TIMESTAMP() + INTERVAL :ttl SECOND))'
        );
        $stmt->bindValue(':u', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':h', Crypto::hash($token));
        $stmt->bindValue(':ip', $ipBin, $ipBin === null ? \PDO::PARAM_NULL : \PDO::PARAM_LOB);
        $stmt->bindValue(':ua', $req->userAgent);
        $stmt->bindValue(':ttl', $ttl, \PDO::PARAM_INT);
        $stmt->execute();

        return $token;
    }

    /**
     * Resolve the authenticated user from the request, or throw 401.
     * @return array{id:int, email:string, display_name:string}
     */
    public static function requireUser(Request $req): array
    {
        $token = $req->bearerToken();
        if ($token === null || $token === '') {
            throw ApiException::unauthorized();
        }

        // OAuth 2.0 access token (JWT, RFC 6750/7519). A JWT has exactly two
        // dots; verify the signature and resolve the subject.
        if (substr_count($token, '.') === 2) {
            $uid = OAuthTokens::userFromAccess($token);
            if ($uid !== null) {
                $user = UserAccount::findById($uid);
                if ($user !== null) {
                    return $user;
                }
                throw ApiException::unauthorized('Session expired or invalid');
            }
            // Not a valid JWT — fall through to the legacy opaque-token path.
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT s.id AS sid, u.id, u.email, u.display_name
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = :h
                AND s.expires_at > UTC_TIMESTAMP()
              LIMIT 1'
        );
        $stmt->execute([':h' => Crypto::hash($token)]);
        $row = $stmt->fetch();

        if (!$row) {
            throw ApiException::unauthorized('Session expired or invalid');
        }

        // Touch last_seen (best effort).
        $pdo->prepare('UPDATE sessions SET last_seen = UTC_TIMESTAMP() WHERE id = :id')
            ->execute([':id' => $row['sid']]);

        return [
            'id'           => (int) $row['id'],
            'email'        => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
        ];
    }

    /** Invalidate the current session (logout). */
    public static function destroy(Request $req): void
    {
        $token = $req->bearerToken();
        if ($token) {
            Database::pdo()
                ->prepare('DELETE FROM sessions WHERE token_hash = :h')
                ->execute([':h' => Crypto::hash($token)]);
        }
    }
}
