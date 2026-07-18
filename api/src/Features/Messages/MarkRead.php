<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Session;

/**
 * POST /links/{id}/read  { ids: [int, ...] }
 * Marks incoming messages as actually read (double check): an audio that was
 * played, or a text that scrolled into view. Only the recipient can mark
 * read, so it never touches the caller's own messages.
 */
final class MarkRead
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        $link = Conversation::require($params, $user['id']);

        $ids = $req->input('ids');
        if (!is_array($ids)) {
            throw ApiException::badRequest('ids must be an array', 'invalid_ids');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : 0,
            $ids
        ))));
        if (!$ids) {
            Response::json(['ok' => true, 'updated' => 0]);
            return;
        }
        $ids = array_slice($ids, 0, 200);

        $place = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::pdo()->prepare(
            "UPDATE messages SET read_at = UTC_TIMESTAMP()
              WHERE link_id = ? AND sender_id <> ? AND read_at IS NULL AND id IN ($place)"
        );
        $stmt->execute(array_merge([$link['id'], $user['id']], $ids));

        Response::json(['ok' => true, 'updated' => $stmt->rowCount()]);
    }
}
