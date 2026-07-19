<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Request;

/**
 * Resolves the authenticated user from an OAuth 2.0 Bearer access token
 * (JWT, RFC 6750/7519). There is no server-side session state — the token is
 * self-contained and verified by signature.
 */
final class Session
{
    /**
     * @return array{id:int, email:string, display_name:string}
     * @throws ApiException 401 if the token is missing, invalid or expired.
     */
    public static function requireUser(Request $req): array
    {
        $token = $req->bearerToken();
        if ($token === null || $token === '') {
            throw ApiException::unauthorized();
        }

        $userId = OAuthTokens::userFromAccess($token);
        if ($userId === null) {
            throw ApiException::unauthorized('Invalid or expired token');
        }

        $user = UserAccount::findById($userId);
        if ($user === null) {
            throw ApiException::unauthorized('Invalid or expired token');
        }

        return $user;
    }
}
