<?php
declare(strict_types=1);

namespace Walkie\Features\Pairing;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Crypto;
use Walkie\Shared\Session;

/**
 * POST /link/claim  { token }
 * Consumes a scanned pairing token and links the two users.
 */
final class ClaimPairing
{
    public static function handle(Request $req): void
    {
        $user = Session::requireUser($req);
        $token = $req->input('token');
        if (!is_string($token) || $token === '' || strlen($token) > 128) {
            throw ApiException::badRequest('Missing pairing token', 'invalid_token');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, used_at, expires_at
               FROM pairing_tokens WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([Crypto::hash($token)]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ApiException::badRequest('Pairing code invalid or expired', 'pairing_invalid');
        }

        $ownerId = (int) $row['user_id'];
        if ($ownerId === $user['id']) {
            throw ApiException::badRequest('You cannot pair with yourself', 'self_pair');
        }

        // Consume the token (single use) and create the link atomically.
        $pdo->beginTransaction();
        try {
            $consume = $pdo->prepare(
                'UPDATE pairing_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL'
            );
            $consume->execute([$row['id']]);
            if ($consume->rowCount() === 0) {
                throw ApiException::badRequest('Pairing code already used', 'pairing_used');
            }
            $linkId = self::pair($user['id'], $ownerId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $name = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $name->execute([$ownerId]);

        Response::json([
            'ok'   => true,
            'link' => [
                'link_id'      => $linkId,
                'user_id'      => $ownerId,
                'display_name' => (string) $name->fetchColumn(),
            ],
        ], 201);
    }

    /** Create (or reuse) the link row between two distinct users. */
    private static function pair(int $a, int $b): int
    {
        [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];
        $pdo = Database::pdo();

        $stmt = $pdo->prepare('SELECT id FROM links WHERE user_low = ? AND user_high = ? LIMIT 1');
        $stmt->execute([$low, $high]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $pdo->prepare(
            'INSERT INTO links (user_low, user_high, secret, created_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())'
        );
        $ins->bindValue(1, $low, \PDO::PARAM_INT);
        $ins->bindValue(2, $high, \PDO::PARAM_INT);
        $ins->bindValue(3, random_bytes(32), \PDO::PARAM_LOB);
        $ins->execute();
        return (int) $pdo->lastInsertId();
    }
}
