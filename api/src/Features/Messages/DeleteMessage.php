<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\Session;

/**
 * DELETE /links/{id}/messages/{msgId}
 * Hard-deletes a message. Only the sender may delete their own messages.
 */
final class DeleteMessage
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        $link = Conversation::require($params, $user['id']);
        $msgId = Validator::positiveInt($params['msgId'] ?? null, 'message id');

        $stmt = Database::pdo()->prepare(
            'DELETE FROM messages WHERE id = ? AND link_id = ? AND sender_id = ?'
        );
        $stmt->execute([$msgId, $link['id'], $user['id']]);

        if ($stmt->rowCount() === 0) {
            throw ApiException::notFound('Message not found or not yours');
        }
        Response::noContent();
    }
}
