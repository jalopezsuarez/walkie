<?php
declare(strict_types=1);

namespace Walkie\Features\OAuth;

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Validator;
use Walkie\Shared\LoginCode;
use Walkie\Shared\OAuthTokens;
use Walkie\Shared\RateLimiter;

/**
 * OAuth 2.0 Token Endpoint (RFC 6749 §3.2). Accepts an
 * application/x-www-form-urlencoded body and issues Bearer access tokens
 * (JWT) plus refresh tokens.
 *
 * Supported grants:
 *   - urn:walkie:params:oauth:grant-type:email-code  (extension grant, §4.5)
 *       params: email, code
 *   - refresh_token  (§6)
 *       params: refresh_token
 */
final class TokenEndpoint
{
    public const GRANT_EMAIL_CODE = 'urn:walkie:params:oauth:grant-type:email-code';

    public static function handle(Request $req): void
    {
        $grant = (string) $req->form('grant_type', '');

        switch ($grant) {
            case self::GRANT_EMAIL_CODE:
                self::emailCodeGrant($req);
                break;
            case 'refresh_token':
                self::refreshGrant($req);
                break;
            case '':
                self::error('invalid_request', 'Missing grant_type', 400);
                break;
            default:
                self::error('unsupported_grant_type', "Unsupported grant_type: {$grant}", 400);
        }
    }

    private static function emailCodeGrant(Request $req): void
    {
        RateLimiter::enforce('verify_per_ip', $req->ip);

        try {
            $email = Validator::email($req->form('email'));
            $code  = Validator::code($req->form('code'));
        } catch (ApiException $e) {
            self::error('invalid_request', $e->getMessage(), 400);
            return;
        }

        try {
            $userId = LoginCode::consume($email, $code);
        } catch (ApiException $e) {
            // Wrong / expired / exhausted code → standard OAuth invalid_grant.
            $status = $e->status === 429 ? 429 : 400;
            self::error('invalid_grant', $e->getMessage(), $status);
            return;
        }

        self::issueFor($userId);
    }

    private static function refreshGrant(Request $req): void
    {
        RateLimiter::enforce('verify_per_ip', $req->ip);

        $refresh = (string) $req->form('refresh_token', '');
        if ($refresh === '') {
            self::error('invalid_request', 'Missing refresh_token', 400);
            return;
        }
        $userId = OAuthTokens::redeemRefresh($refresh);
        if ($userId === null) {
            self::error('invalid_grant', 'Invalid or expired refresh token', 400);
            return;
        }
        self::issueFor($userId);
    }

    private static function issueFor(int $userId): void
    {
        $t = OAuthTokens::issue($userId);
        // RFC 6749 §5.1 — tokens must not be cached.
        Response::json([
            'access_token'  => $t['access'],
            'token_type'    => 'Bearer',
            'expires_in'    => $t['expires_in'],
            'refresh_token' => $t['refresh'],
            'scope'         => OAuthTokens::SCOPE,
        ], 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
    }

    /** RFC 6749 §5.2 error response. */
    private static function error(string $code, string $description, int $status): void
    {
        Response::json(
            ['error' => $code, 'error_description' => $description],
            $status,
            ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']
        );
    }
}
