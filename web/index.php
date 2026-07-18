<?php
/**
 * Walkie frontend shell.
 *
 * Pure PHP + vanilla JS/CSS, no build step and no dependencies. This file
 * only bootstraps the single-page app and injects the API base URL; all
 * behaviour lives in /assets/js.
 */
$config = require __DIR__ . '/config.php';
// Allow the host to override the API base (handy for staging / testing).
$apiBase = getenv('WALKIE_API_BASE') ?: $config['api_base'];
$appName = $config['app_name'];

// Security headers for the frontend.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
// script-src stays strictly 'self' (the real XSS vector). Inline style
// attributes are allowed ('unsafe-inline' for styles only) — they cannot
// execute code, and the app uses a few one-off layout styles.
header(
    "Content-Security-Policy: default-src 'self'; "
    . "img-src 'self' data:; media-src 'self' blob: data:; "
    . "style-src 'self' 'unsafe-inline'; script-src 'self'; "
    . "connect-src 'self' " . htmlspecialchars($apiBase, ENT_QUOTES) . "; "
    . "base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
);
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#e2dcfb">
    <title><?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=26">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%23111'/><circle cx='50' cy='38' r='14' fill='%23fff'/><rect x='44' y='38' width='12' height='34' rx='6' fill='%23fff'/></svg>">
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/icons/icon-180.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Walkie">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
</head>
<body data-api="<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>">
    <div id="app" class="app" aria-live="polite"></div>

    <!-- Full-screen QR overlay (own code + scanner) -->
    <div id="qr-overlay" class="overlay hidden" role="dialog" aria-modal="true"></div>

    <div id="toast" class="toast hidden" role="status"></div>

    <script src="assets/js/api.js?v=26"></script>
    <script src="assets/js/core.js?v=26"></script>
    <script src="assets/js/qr.js?v=26"></script>
    <script src="assets/js/audio.js?v=26"></script>
    <script src="assets/js/notify.js?v=26"></script>
    <script src="assets/js/names.js?v=26"></script>
    <script src="assets/js/features/auth.js?v=26"></script>
    <script src="assets/js/features/contacts.js?v=26"></script>
    <script src="assets/js/features/conversation.js?v=26"></script>
    <script src="assets/js/features/pairing.js?v=26"></script>
    <script src="assets/js/features/settings.js?v=26"></script>
    <script src="assets/js/app.js?v=26"></script>
</body>
</html>
