<?php
declare(strict_types=1);

namespace Walkie\Models;

use Walkie\Core\Database;

final class LinkRepo
{
    /**
     * Create (or return existing) link between two distinct users.
     * @return array{id:int, secret:string}
     */
    public static function pair(int $a, int $b): array
    {
        [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];
        $pdo = Database::pdo();

        $existing = self::find($low, $high);
        if ($existing) {
            return ['id' => (int) $existing['id'], 'secret' => $existing['secret']];
        }

        $secret = random_bytes(32);
        $stmt = $pdo->prepare(
            'INSERT INTO links (user_low, user_high, secret, created_at)
             VALUES (:l, :h, :s, UTC_TIMESTAMP())'
        );
        $stmt->bindValue(':l', $low, \PDO::PARAM_INT);
        $stmt->bindValue(':h', $high, \PDO::PARAM_INT);
        $stmt->bindValue(':s', $secret, \PDO::PARAM_LOB);
        $stmt->execute();

        return ['id' => (int) $pdo->lastInsertId(), 'secret' => $secret];
    }

    private static function find(int $low, int $high): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM links WHERE user_low = :l AND user_high = :h LIMIT 1'
        );
        $stmt->execute([':l' => $low, ':h' => $high]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** List the contacts a user is linked with, most recent first. */
    public static function listForUser(int $userId): array
    {
        // Positional placeholders: MySQL native prepares can't reuse a named one.
        $stmt = Database::pdo()->prepare(
            'SELECT l.id AS link_id,
                    u.id AS user_id,
                    u.display_name,
                    l.created_at,
                    (SELECT COUNT(*) FROM messages m
                       WHERE m.link_id = l.id
                         AND m.sender_id <> ?
                         AND m.read_at IS NULL) AS unread
               FROM links l
               JOIN users u ON u.id = CASE WHEN l.user_low = ? THEN l.user_high ELSE l.user_low END
              WHERE l.user_low = ? OR l.user_high = ?
              ORDER BY l.created_at DESC'
        );
        $stmt->execute([$userId, $userId, $userId, $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch a link the user is a participant of, else null.
     * @return array{id:int, secret:string, other_id:int, other_name:string}|null
     */
    public static function getForUser(int $linkId, int $userId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT l.id, l.secret, l.user_low, l.user_high
               FROM links l
              WHERE l.id = ? AND (l.user_low = ? OR l.user_high = ?)
              LIMIT 1'
        );
        $stmt->execute([$linkId, $userId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $otherId = (int) $row['user_low'] === $userId ? (int) $row['user_high'] : (int) $row['user_low'];
        $name = Database::pdo()->prepare('SELECT display_name FROM users WHERE id = :id');
        $name->execute([':id' => $otherId]);

        return [
            'id'         => (int) $row['id'],
            'secret'     => $row['secret'],
            'other_id'   => $otherId,
            'other_name' => (string) $name->fetchColumn(),
        ];
    }

    /** Delete a link (and, via FK cascade, all its messages). */
    public static function delete(int $linkId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM links
              WHERE id = ? AND (user_low = ? OR user_high = ?)'
        );
        $stmt->execute([$linkId, $userId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
