<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Crypto;
use Walkie\Shared\Session;

/**
 * GET /links/{id}/messages?after=N
 * Returns decrypted messages newer than `after` and marks incoming as read
 * (which schedules their hard deletion).
 */
final class ListMessages
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        $link = Conversation::require($params, $user['id']);

        $after = 0;
        if (isset($req->query['after']) && ctype_digit((string) $req->query['after'])) {
            $after = (int) $req->query['after'];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, sender_id, type, body_cipher, mime, duration_ms, read_at, created_at, expires_at
               FROM messages
              WHERE link_id = ? AND id > ?
              ORDER BY id ASC
              LIMIT 200'
        );
        $stmt->execute([$link['id'], $after]);

        $key = Crypto::deriveKey($link['secret']);
        $messages = [];
        foreach ($stmt->fetchAll() as $r) {
            $plain = Crypto::decrypt($r['body_cipher'], $key);
            if ($plain === null) {
                continue; // tampered/corrupt — skip
            }
            if ($r['type'] !== 'a' && $r['type'] !== 't') {
                continue; // only text and audio are supported
            }
            $item = [
                'id'         => (int) $r['id'],
                'mine'       => (int) $r['sender_id'] === $user['id'],
                'type'       => $r['type'] === 'a' ? 'audio' : 'text',
                'read'       => $r['read_at'] !== null,
                'created_at' => gmdate('c', strtotime($r['created_at'] . ' UTC')),
                'expires_at' => gmdate('c', strtotime($r['expires_at'] . ' UTC')),
            ];
            if ($r['type'] === 'a') {
                $item['audio']       = base64_encode($plain);
                $item['mime']        = $r['mime'] ?: 'audio/webm';
                $item['duration_ms'] = $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null;
            } else {
                $item['text'] = $plain;
            }
            $messages[] = $item;
        }

        // Mark everything addressed to the caller as read.
        Database::pdo()->prepare(
            'UPDATE messages SET read_at = UTC_TIMESTAMP()
              WHERE link_id = ? AND sender_id <> ? AND read_at IS NULL'
        )->execute([$link['id'], $user['id']]);

        Response::json([
            'contact'  => ['user_id' => $link['other_id'], 'display_name' => $link['other_name']],
            'messages' => $messages,
        ]);
    }
}
