<?php
declare(strict_types=1);

/**
 * Walkie API — front controller.
 * All /api/* requests are routed here (see .htaccess).
 *
 * Architecture: vertical slices. Each endpoint is a self-contained handler
 * under src/Features/<Slice>/, supported by a thin Kernel (HTTP + config +
 * DB plumbing) and Shared infrastructure (crypto, sessions, rate limiting).
 */

use Walkie\Kernel\ApiException;
use Walkie\Kernel\Autoloader;
use Walkie\Kernel\Config;
use Walkie\Kernel\Request;
use Walkie\Kernel\Response;
use Walkie\Kernel\Router;
use Walkie\Features\Auth\RequestLoginCode;
use Walkie\Features\Contacts\ListContacts;
use Walkie\Features\Contacts\RemoveContact;
use Walkie\Features\Health\GetHealth;
use Walkie\Features\Messages\DeleteMessage;
use Walkie\Features\Messages\ListMessages;
use Walkie\Features\Messages\MarkRead;
use Walkie\Features\Messages\MessageStatuses;
use Walkie\Features\Messages\SendMessage;
use Walkie\Features\OAuth\RevokeEndpoint;
use Walkie\Features\OAuth\TokenEndpoint;
use Walkie\Features\Pairing\ClaimPairing;
use Walkie\Features\Pairing\CreatePairingQr;
use Walkie\Features\Profile\GetProfile;
use Walkie\Features\Profile\UpdateProfile;
use Walkie\Shared\Cleanup;
use Walkie\Shared\RateLimiter;

require __DIR__ . '/src/Kernel/Autoloader.php';
Autoloader::register(__DIR__ . '/src');
Config::load(__DIR__ . '/config/config.php');

/* ------------------------------------------------------------------ *
 *  Security headers + CORS (locked to the configured web origin)
 * ------------------------------------------------------------------ */
$webOrigin = rtrim((string) Config::get('app.web_origin', ''), '/');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

header('Vary: Origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cross-Origin-Resource-Policy: same-site');
// The API only ever returns JSON — forbid it being treated as a document.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
header('X-Permitted-Cross-Domain-Policies: none');
// HSTS: force HTTPS for two years, including subdomains (served over TLS).
header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
header_remove('X-Powered-By');

if ($webOrigin !== '' && $origin === $webOrigin) {
    header("Access-Control-Allow-Origin: {$webOrigin}");
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$request = new Request();

/* ------------------------------------------------------------------ *
 *  Global per-IP rate limit (saturation guard) + opportunistic cleanup
 * ------------------------------------------------------------------ */
try {
    RateLimiter::enforce('api_per_ip', $request->ip);
    Cleanup::maybeRun();
} catch (ApiException $e) {
    sendError($e);
} catch (\Throwable $e) {
    sendFatal($e);
}

/* ------------------------------------------------------------------ *
 *  Routes → feature slices
 * ------------------------------------------------------------------ */
$router = new Router();

$router->get('/health', [GetHealth::class, 'handle']);

// Passwordless auth via OAuth 2.0 (RFC 6749/6750/7519/7009):
// request-code emails a 6-digit code; /oauth/token exchanges it for tokens.
$router->post('/auth/request-code', [RequestLoginCode::class, 'handle']);
$router->post('/oauth/token',       [TokenEndpoint::class, 'handle']);
$router->post('/oauth/revoke',      [RevokeEndpoint::class, 'handle']);

$router->get('/me',   [GetProfile::class, 'handle']);
$router->patch('/me', [UpdateProfile::class, 'handle']);

$router->post('/link/qr',    [CreatePairingQr::class, 'handle']);
$router->post('/link/claim', [ClaimPairing::class, 'handle']);

$router->get('/links',         [ListContacts::class, 'handle']);
$router->delete('/links/{id}', [RemoveContact::class, 'handle']);

$router->get('/links/{id}/messages',            [ListMessages::class, 'handle']);
$router->post('/links/{id}/messages',           [SendMessage::class, 'handle']);
$router->delete('/links/{id}/messages/{msgId}', [DeleteMessage::class, 'handle']);
$router->get('/links/{id}/statuses',            [MessageStatuses::class, 'handle']);
$router->post('/links/{id}/read',               [MarkRead::class, 'handle']);

/* ------------------------------------------------------------------ *
 *  Dispatch
 * ------------------------------------------------------------------ */
try {
    [$handler, $params] = $router->match($request->method, $request->path);
    $handler($request, $params);
} catch (ApiException $e) {
    sendError($e);
} catch (\Throwable $e) {
    sendFatal($e);
}

function sendError(ApiException $e): never
{
    $body = ['error' => $e->errorCode, 'message' => $e->getMessage()];
    $headers = [];
    if ($e->status === 429 && isset($e->extra['retry_after'])) {
        $headers['Retry-After'] = (string) $e->extra['retry_after'];
        $body['retry_after'] = $e->extra['retry_after'];
    }
    Response::json($body, $e->status, $headers);
    exit;
}

function sendFatal(\Throwable $e): never
{
    $body = ['error' => 'server_error', 'message' => 'Internal error'];
    if ((bool) Config::get('app.debug', false)) {
        $body['detail'] = $e->getMessage();
        $body['trace'] = explode("\n", $e->getTraceAsString());
    }
    Response::json($body, 500);
    exit;
}
