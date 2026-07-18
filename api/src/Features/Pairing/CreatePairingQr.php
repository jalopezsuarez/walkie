<?php
declare(strict_types=1);

namespace Walkie\Features\Pairing;

use Walkie\Kernel\Config;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Crypto;
use Walkie\Shared\QrCode;
use Walkie\Shared\Session;

/**
 * POST /link/qr
 * Issues a short-lived pairing token and returns it as a scannable QR (SVG).
 */
final class CreatePairingQr
{
    public static function handle(Request $req): void
    {
        $user = Session::requireUser($req);

        $token = Crypto::token(24);
        $ttl = (int) Config::get('ttl.pairing', 300);

        $pdo = Database::pdo();
        // One active pairing token per user.
        $pdo->prepare('DELETE FROM pairing_tokens WHERE user_id = ?')->execute([$user['id']]);
        $pdo->prepare(
            'INSERT INTO pairing_tokens (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, (UTC_TIMESTAMP() + INTERVAL ? SECOND), UTC_TIMESTAMP())'
        )->execute([$user['id'], Crypto::hash($token), $ttl]);

        $webOrigin = rtrim((string) Config::get('app.web_origin', ''), '/');
        $pairUrl = $webOrigin . '/web/#p=' . $token;

        Response::json([
            'token'      => $token,
            'pair_url'   => $pairUrl,
            'qr_svg'     => QrCode::svg($pairUrl, 'M', 8, 4),
            'expires_in' => $ttl,
        ]);
    }
}
