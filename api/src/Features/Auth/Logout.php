<?php
declare(strict_types=1);

namespace Walkie\Features\Auth;

use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Session;

/**
 * POST /auth/logout
 * Invalidates the current session token.
 */
final class Logout
{
    public static function handle(Request $req): void
    {
        Session::requireUser($req);
        Session::destroy($req);
        Response::json(['ok' => true]);
    }
}
