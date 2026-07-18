<?php
declare(strict_types=1);

namespace Walkie\Features\Profile;

use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\Session;

/**
 * GET /me
 * Returns the authenticated user's profile.
 */
final class GetProfile
{
    public static function handle(Request $req): void
    {
        Response::json(['user' => Session::requireUser($req)]);
    }
}
