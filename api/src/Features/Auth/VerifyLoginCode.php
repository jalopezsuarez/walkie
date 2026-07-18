<?php
declare(strict_types=1);

namespace Walkie\Features\Auth;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Config;
use Walkie\Kernel\Database;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\Crypto;
use Walkie\Shared\RateLimiter;
use Walkie\Shared\Session;

/**
 * POST /auth/verify  { email, code }
 * Exchanges a valid login code for a bearer session token.
 */
final class VerifyLoginCode
{
    public static function handle(Request $req): void
    {
        RateLimiter::enforce('verify_per_ip', $req->ip);

        $email = Validator::email($req->input('email'));
        $code  = Validator::code($req->input('code'));

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash, attempts, expires_at
               FROM login_codes WHERE email = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        if (!$row || strtotime($row['expires_at'] . ' UTC') < time()) {
            throw ApiException::badRequest('Code expired or not found', 'code_invalid');
        }

        $maxTries = (int) Config::get('limits.max_code_tries', 5);
        if ((int) $row['attempts'] >= $maxTries) {
            $pdo->prepare('DELETE FROM login_codes WHERE id = ?')->execute([$row['id']]);
            throw ApiException::tooMany('Too many attempts. Request a new code.', 60);
        }

        if (!Crypto::equals($row['code_hash'], Crypto::hash($code))) {
            $pdo->prepare('UPDATE login_codes SET attempts = attempts + 1 WHERE id = ?')
                ->execute([$row['id']]);
            throw ApiException::badRequest('Incorrect code', 'code_invalid');
        }

        // Success — the code is single use.
        $pdo->prepare('DELETE FROM login_codes WHERE email = ?')->execute([$email]);
        $userId = UserAccount::findOrCreate($email);
        $token = Session::create($userId, $req);

        Response::json([
            'token' => $token,
            'user'  => UserAccount::findByEmail($email),
        ]);
    }
}
