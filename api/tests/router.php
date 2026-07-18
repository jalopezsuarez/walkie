<?php
/**
 * Router for the PHP built-in server used ONLY in local testing:
 *   php -S localhost:8080 api/tests/router.php
 * Emulates the production .htaccess: /api/* -> api/index.php?__route=...
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = dirname(__DIR__, 2);

if (preg_match('#^/api(/.*)?$#', $uri, $m)) {
    $_GET['__route'] = $m[1] ?: '/';
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require __DIR__ . '/../index.php';
    return true;
}

// Frontend at /web (point it at this same origin's API for local testing).
putenv('WALKIE_API_BASE=http://127.0.0.1:8080/api');
if ($uri === '/web' || $uri === '/web/') {
    require $root . '/web/index.php';
    return true;
}
$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // let the built-in server serve real asset files
}
http_response_code(200);
echo '';
return true;
