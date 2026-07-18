<?php
declare(strict_types=1);

namespace Walkie\Features\Contacts;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\Session;

/**
 * DELETE /links/{id}
 * Unlinks a contact for BOTH users; messages cascade-delete with the link.
 * Talking again requires a fresh QR pairing.
 */
final class RemoveContact
{
    public static function handle(Request $req, array $params): void
    {
        $user = Session::requireUser($req);
        $linkId = Validator::positiveInt($params['id'] ?? null, 'link id');

        $stmt = Database::pdo()->prepare(
            'DELETE FROM links WHERE id = ? AND (user_low = ? OR user_high = ?)'
        );
        $stmt->execute([$linkId, $user['id'], $user['id']]);

        if ($stmt->rowCount() === 0) {
            throw ApiException::notFound('Link not found');
        }
        Response::noContent();
    }
}
