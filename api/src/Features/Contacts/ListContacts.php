<?php
declare(strict_types=1);

namespace Walkie\Features\Contacts;

use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Session;

/**
 * GET /links
 * Lists the user's linked contacts with unread counts, most recent first.
 */
final class ListContacts
{
    public static function handle(Request $req): void
    {
        $user = Session::requireUser($req);

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
        $stmt->execute([$user['id'], $user['id'], $user['id'], $user['id']]);

        $links = array_map(static fn(array $r) => [
            'link_id'      => (int) $r['link_id'],
            'user_id'      => (int) $r['user_id'],
            'display_name' => (string) $r['display_name'],
            'unread'       => (int) $r['unread'],
            'created_at'   => gmdate('c', strtotime($r['created_at'] . ' UTC')),
        ], $stmt->fetchAll());

        Response::json(['links' => $links]);
    }
}
