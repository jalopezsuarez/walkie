<?php
declare(strict_types=1);

/**
 * Walkie one-shot migration — adds messages.delivered_at when missing.
 * Key-protected and self-deleting, same as install.php.
 *
 *   https://<host>/api/migrate.php?key=<app.install_key>
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
    exit("Missing config.php\n");
}

$expected = (string) Config::get('app.install_key', '');
$given = (string) ($_GET['key'] ?? ($_SERVER['argv'][1] ?? ''));
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

try {
    $pdo = Database::pdo();
    $has = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'delivered_at'"
    )->fetchColumn();

    if ($has === 0) {
        $pdo->exec(
            "ALTER TABLE messages
                ADD COLUMN delivered_at DATETIME NULL AFTER duration_ms,
                ADD KEY idx_messages_delivered (delivered_at)"
        );
        echo "OK — added messages.delivered_at\n";
    } else {
        echo "OK — messages.delivered_at already present\n";
    }
    @unlink(__FILE__);
    echo "Migration done. Installer removed itself.\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
