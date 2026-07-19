<?php
declare(strict_types=1);

namespace Walkie\Features\OAuth;

use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Shared\OAuthTokens;
use Walkie\Shared\RateLimiter;

/**
 * OAuth 2.0 Token Revocation (RFC 7009). Revokes a refresh token. Per the RFC
 * the server responds 200 even for unknown tokens, to avoid leaking validity.
 */
final class RevokeEndpoint
{
    public static function handle(Request $req): void
    {
        RateLimiter::enforce('verify_per_ip', $req->ip);

        $token = (string) $req->form('token', '');
        if ($token !== '') {
            OAuthTokens::revoke($token);
        }
        Response::json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
    }
}
