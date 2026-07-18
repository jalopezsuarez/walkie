<?php
declare(strict_types=1);

namespace Walkie\Models;

use Walkie\Core\Database;
use Walkie\Security\Crypto;

final class MessageRepo
{
    /**
     * Store an encrypted message.
     * @param string $type 't' | 'a'
     * @return int new message id
     */
    public static function create(
        int $linkId, int $senderId, string $type, string $plaintext,
        string $linkSecret, int $ttlSeconds, ?string $mime = null, ?int $durationMs = null
    ): int {
        $key = Crypto::deriveKey($linkSecret);
        $cipher = Crypto::encrypt($plaintext, $key);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO messages
                (link_id, sender_id, type, body_cipher, mime, duration_ms, created_at, expires_at)
             VALUES
                (:l, :s, :t, :c, :m, :d, UTC_TIMESTAMP(), (UTC_TIMESTAMP() + INTERVAL :ttl SECOND))'
        );
        $stmt->bindValue(':l', $linkId, \PDO::PARAM_INT);
        $stmt->bindValue(':s', $senderId, \PDO::PARAM_INT);
        $stmt->bindValue(':t', $type);
        $stmt->bindValue(':c', $cipher, \PDO::PARAM_LOB);
        $stmt->bindValue(':m', $mime, $mime === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
        $stmt->bindValue(':d', $durationMs, $durationMs === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':ttl', $ttlSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $pdo->lastInsertId();
    }

    /**
     * Return decrypted messages for a link with id > $afterId.
     * @return array<int, array<string, mixed>>
     */
    public static function fetch(int $linkId, string $linkSecret, int $afterId, int $meId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, sender_id, type, body_cipher, mime, duration_ms, read_at, created_at, expires_at
               FROM messages
              WHERE link_id = :l AND id > :after
              ORDER BY id ASC
              LIMIT 200'
        );
        $stmt->execute([':l' => $linkId, ':after' => $afterId]);
        $rows = $stmt->fetchAll();

        $key = Crypto::deriveKey($linkSecret);
        $out = [];
        foreach ($rows as $r) {
            $plain = Crypto::decrypt($r['body_cipher'], $key);
            if ($plain === null) {
                continue; // tampered/corrupt — skip
            }
            $isMine = (int) $r['sender_id'] === $meId;
            $item = [
                'id'          => (int) $r['id'],
                'mine'        => $isMine,
                'type'        => $r['type'] === 'a' ? 'audio' : 'text',
                'read'        => $r['read_at'] !== null,
                'created_at'  => gmdate('c', strtotime($r['created_at'] . ' UTC')),
                'expires_at'  => gmdate('c', strtotime($r['expires_at'] . ' UTC')),
            ];
            if ($r['type'] === 'a') {
                $item['audio']       = base64_encode($plain);
                $item['mime']        = $r['mime'] ?: 'audio/webm';
                $item['duration_ms'] = $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null;
            } else {
                $item['text'] = $plain;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** Mark all messages in a link addressed TO $meId as read. */
    public static function markRead(int $linkId, int $meId): void
    {
        Database::pdo()->prepare(
            'UPDATE messages
                SET read_at = UTC_TIMESTAMP()
              WHERE link_id = :l AND sender_id <> :me AND read_at IS NULL'
        )->execute([':l' => $linkId, ':me' => $meId]);
    }

    /** Sender-only hard delete. */
    public static function deleteOwn(int $messageId, int $linkId, int $senderId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM messages WHERE id = :id AND link_id = :l AND sender_id = :s'
        );
        $stmt->execute([':id' => $messageId, ':l' => $linkId, ':s' => $senderId]);
        return $stmt->rowCount() > 0;
    }
}
