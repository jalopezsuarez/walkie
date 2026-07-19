<?php
declare(strict_types=1);

namespace Walkie\Features\Auth;

use Walkie\Kernel\Config;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\Crypto;
use Walkie\Shared\Mailer;
use Walkie\Shared\RateLimiter;
use Walkie\Shared\UserAccount;

/**
 * POST /auth/request-code  { email }
 * Emails a single-use 6-digit login code (5-minute TTL).
 */
final class RequestLoginCode
{
    public static function handle(Request $req): void
    {
        $email = Validator::email($req->input('email'));

        RateLimiter::enforce('code_per_ip', $req->ip);
        RateLimiter::enforce('code_per_email', $email);

        UserAccount::findOrCreate($email);

        $code = Crypto::numericCode(6);
        $ttl = (int) Config::get('ttl.login_code', 300);

        $pdo = Database::pdo();
        $pdo->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
        $pdo->prepare(
            'INSERT INTO login_codes (email, code_hash, attempts, expires_at, created_at)
             VALUES (?, ?, 0, (UTC_TIMESTAMP() + INTERVAL ? SECOND), UTC_TIMESTAMP())'
        )->execute([$email, Crypto::hash($code), $ttl]);

        Mailer::sendLoginCode($email, $code);

        $payload = ['ok' => true, 'message' => 'Code sent if the address is valid.'];
        if ((bool) Config::get('app.debug', false)) {
            $payload['debug_code'] = $code; // development convenience only
        }
        Response::json($payload);
    }
}
