<?php
declare(strict_types=1);

/**
 * Walkie one-shot installer — creates the database schema.
 *
 * Usage: upload everything, then open
 *     https://<host>/api/install.php?key=<app.install_key>
 * once. The schema is idempotent (CREATE TABLE IF NOT EXISTS), the endpoint
 * refuses to run without the correct key, and it tells you to delete this
 * file when done.
 */

use Walkie\Kernel\Autoloader;
use Walkie\Kernel\Config;
use Walkie\Kernel\Database;

require __DIR__ . '/src/Kernel/Autoloader.php';
Autoloader::register(__DIR__ . '/src');

header('Content-Type: text/plain; charset=utf-8');

try {
    Config::load(__DIR__ . '/config/config.php');
} catch (\Throwable $e) {
    http_response_code(500);
    exit("Missing api/config/config.php — create it from config.sample.php first.\n");
}

$expected = (string) Config::get('app.install_key', '');
$given = (string) ($_GET['key'] ?? ($_SERVER['argv'][1] ?? ''));
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "Forbidden: pass ?key=<app.install_key from config.php>\n";
    exit(1);
}

try {
    $pdo = Database::pdo();
    $sql = (string) file_get_contents(__DIR__ . '/migrations/schema.sql');
    // Strip `-- …` comment lines, then run statement by statement
    // (some hosts reject multi-statement exec).
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    echo "OK — schema installed. Tables: " . implode(', ', $tables) . "\n";
    echo "Now DELETE api/install.php from the server.\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Install failed: " . $e->getMessage() . "\n";
    exit(1);
}
