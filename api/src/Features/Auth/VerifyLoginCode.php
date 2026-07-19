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

        // Verification logic is shared with the OAuth 2.0 token endpoint.
        $userId = LoginCode::consume($email, $code);
        $token = Session::create($userId, $req);

        Response::json([
            'token' => $token,
            'user'  => UserAccount::findByEmail($email),
        ]);
    }
}
