<?php
declare(strict_types=1);

/**
 * Walkie API — front controller.
 * All /api/* requests are routed here (see .htaccess).
 */

use Walkie\Core\ApiException;
use Walkie\Core\Autoloader;
use Walkie\Core\Config;
use Walkie\Core\Request;
use Walkie\Core\Response;
use Walkie\Core\Router;
use Walkie\Controllers\AuthController;
use Walkie\Controllers\LinkController;
use Walkie\Controllers\MeController;
use Walkie\Controllers\MessageController;
use Walkie\Security\RateLimiter;
use Walkie\Services\Cleanup;

require __DIR__ . '/src/Core/Autoloader.php';
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
header_remove('X-Powered-By');

if ($webOrigin !== '' && $origin === $webOrigin) {
    header("Access-Control-Allow-Origin: {$webOrigin}");
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

// Pre-flight.
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
    // DB not reachable etc. — fail closed but readable.
    sendFatal($e);
}

/* ------------------------------------------------------------------ *
 *  Routes
 * ------------------------------------------------------------------ */
$router = new Router();

$router->get('/health', fn() => Response::json(['status' => 'ok', 'service' => 'walkie']));

$router->post('/auth/request-code', [AuthController::class, 'requestCode']);
$router->post('/auth/verify',       [AuthController::class, 'verify']);
$router->post('/auth/logout',       [AuthController::class, 'logout']);

$router->get('/me',   [MeController::class, 'show']);
$router->patch('/me', [MeController::class, 'update']);

$router->post('/link/qr',    [LinkController::class, 'createQr']);
$router->post('/link/claim', [LinkController::class, 'claim']);
$router->get('/links',       [LinkController::class, 'index']);
$router->delete('/links/{id}', [LinkController::class, 'destroy']);

$router->get('/links/{id}/messages',            [MessageController::class, 'index']);
$router->post('/links/{id}/messages',           [MessageController::class, 'store']);
$router->delete('/links/{id}/messages/{msgId}', [MessageController::class, 'destroy']);

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
    $debug = (bool) Config::get('app.debug', false);
    $body = ['error' => 'server_error', 'message' => 'Internal error'];
    if ($debug) {
        $body['detail'] = $e->getMessage();
        $body['trace'] = explode("\n", $e->getTraceAsString());
    }
    Response::json($body, 500);
    exit;
}
