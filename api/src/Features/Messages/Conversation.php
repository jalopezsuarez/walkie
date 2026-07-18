<?php
declare(strict_types=1);

namespace Walkie\Features\Messages;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Validator;

/**
 * Conversation access-control helper owned by the Messages slice:
 * resolves a link the user participates in, or throws 404.
 */
final class Conversation
{
    /**
     * @return array{id:int, secret:string, other_id:int, other_name:string}
     * @throws ApiException 404 when the user is not part of the link
     */
    public static function require(array $params, int $userId): array
    {
        $linkId = Validator::positiveInt($params['id'] ?? null, 'link id');

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, secret, user_low, user_high
               FROM links
              WHERE id = ? AND (user_low = ? OR user_high = ?)
              LIMIT 1'
        );
        $stmt->execute([$linkId, $userId, $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw ApiException::notFound('Conversation not found');
        }

        $otherId = (int) $row['user_low'] === $userId ? (int) $row['user_high'] : (int) $row['user_low'];
        $name = $pdo->prepare('SELECT display_name FROM users WHERE id = ?');
        $name->execute([$otherId]);

        return [
            'id'         => (int) $row['id'],
            'secret'     => $row['secret'],
            'other_id'   => $otherId,
            'other_name' => (string) $name->fetchColumn(),
        ];
    }
}
