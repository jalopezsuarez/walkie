<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Session;

/**
 * GET /links/{id}/statuses
 * Lightweight delivered/read flags for every current message in the
 * conversation, so both sides can keep their check marks up to date without
 * refetching message bodies.
 */
final class MessageStatuses
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        $link = Conversation::require($params, $user['id']);

        $stmt = Database::pdo()->prepare(
            'SELECT id, delivered_at, read_at FROM messages WHERE link_id = ? ORDER BY id ASC LIMIT 500'
        );
        $stmt->execute([$link['id']]);

        $statuses = array_map(static fn(array $r) => [
            'id'        => (int) $r['id'],
            'delivered' => $r['delivered_at'] !== null,
            'read'      => $r['read_at'] !== null,
        ], $stmt->fetchAll());

        Response::json(['statuses' => $statuses]);
    }
}
