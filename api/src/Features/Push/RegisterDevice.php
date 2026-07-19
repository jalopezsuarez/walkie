<?php
declare(strict_types=1);

namespace Walkie\Features\Push;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Crypto;
use Walkie\Shared\Session;

/**
 * POST /devices  { token, platform? }
 * Registers (or re-points) this device's push token to the current user, so
 * the server can deliver notifications. Idempotent upsert keyed by token hash.
 */
final class RegisterDevice
{
    public static function handle(Request $req): void
    {
        $user = Session::requireUser($req);

        $token = $req->input('token');
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            throw ApiException::badRequest('Missing or invalid token', 'invalid_token');
        }
        $platform = $req->input('platform');
        $platform = in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android';

        Database::pdo()->prepare(
            'INSERT INTO devices (user_id, token, token_hash, platform, created_at, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                token = VALUES(token),
                platform = VALUES(platform),
                updated_at = UTC_TIMESTAMP()'
        )->execute([$user['id'], $token, Crypto::hash($token), $platform]);

        Response::json(['ok' => true]);
    }
}
