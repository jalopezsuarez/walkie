<?php
declare(strict_types=1);

namespace Walkie\Controllers;

use Walkie\Core\ApiException;
use Walkie\Core\Config;
use Walkie\Core\Database;
use Walkie\Core\Request;
use Walkie\Core\Response;
use Walkie\Core\Validator;
use Walkie\Models\LinkRepo;
use Walkie\Security\Auth;
use Walkie\Security\Crypto;
use Walkie\Services\QrCode;

final class LinkController
{
    /**
     * POST /link/qr
     * Create a short-lived pairing token and return it as a scannable QR.
     */
    public static function createQr(Request $req): void
    {
        $user = Auth::requireUser($req);

        $token = Crypto::token(24);
        $ttl = (int) Config::get('ttl.pairing', 300);

        $pdo = Database::pdo();
        // One active pairing token per user keeps things tidy.
        $pdo->prepare('DELETE FROM pairing_tokens WHERE user_id = :u')->execute([':u' => $user['id']]);
        $pdo->prepare(
            'INSERT INTO pairing_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (:u, :h, (UTC_TIMESTAMP() + INTERVAL :ttl SECOND), UTC_TIMESTAMP())'
        )->execute([':u' => $user['id'], ':h' => Crypto::hash($token), ':ttl' => $ttl]);

        $webOrigin = rtrim((string) Config::get('app.web_origin', ''), '/');
        $payload = $webOrigin . '/web/#p=' . $token;

        Response::json([
            'token'      => $token,
            'pair_url'   => $payload,
            'qr_svg'     => QrCode::svg($payload, 'M', 8, 4),
            'expires_in' => $ttl,
        ]);
    }

    /**
     * POST /link/claim  { token }
     * Consume a pairing token and create the two-way link.
     */
    public static function claim(Request $req): void
    {
        $user = Auth::requireUser($req);
        $token = $req->input('token');
        if (!is_string($token) || $token === '' || strlen($token) > 128) {
            throw ApiException::badRequest('Missing pairing token', 'invalid_token');
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, user_id, used_at, expires_at
               FROM pairing_tokens WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute([':h' => Crypto::hash($token)]);
        $row = $stmt->fetch();

        if (!$row || $row['used_at'] !== null || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ApiException::badRequest('Pairing code invalid or expired', 'pairing_invalid');
        }

        $ownerId = (int) $row['user_id'];
        if ($ownerId === $user['id']) {
            throw ApiException::badRequest('You cannot pair with yourself', 'self_pair');
        }

        // Consume the token (single use) and create the link atomically.
        $pdo->beginTransaction();
        try {
            $consume = $pdo->prepare(
                'UPDATE pairing_tokens SET used_at = UTC_TIMESTAMP()
                  WHERE id = :id AND used_at IS NULL'
            );
            $consume->execute([':id' => $row['id']]);
            if ($consume->rowCount() === 0) {
                throw ApiException::badRequest('Pairing code already used', 'pairing_used');
            }
            $link = LinkRepo::pair($user['id'], $ownerId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $contact = LinkRepo::getForUser($link['id'], $user['id']);
        Response::json([
            'ok'   => true,
            'link' => [
                'link_id'      => $link['id'],
                'user_id'      => $contact['other_id'],
                'display_name' => $contact['other_name'],
            ],
        ], 201);
    }

    /** GET /links */
    public static function index(Request $req): void
    {
        $user = Auth::requireUser($req);
        $rows = LinkRepo::listForUser($user['id']);
        $links = array_map(static fn($r) => [
            'link_id'      => (int) $r['link_id'],
            'user_id'      => (int) $r['user_id'],
            'display_name' => $r['display_name'],
            'unread'       => (int) $r['unread'],
            'created_at'   => gmdate('c', strtotime($r['created_at'] . ' UTC')),
        ], $rows);
        Response::json(['links' => $links]);
    }

    /** DELETE /links/{id} */
    public static function destroy(Request $req, array $params): void
    {
        $user = Auth::requireUser($req);
        $linkId = Validator::positiveInt($params['id'] ?? null, 'link id');

        if (!LinkRepo::delete($linkId, $user['id'])) {
            throw ApiException::notFound('Link not found');
        }
        Response::noContent();
    }
}
