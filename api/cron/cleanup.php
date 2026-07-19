<?php
declare(strict_types=1);

/**
 * Walkie retention cleanup — run from cron (CLI), e.g. every 5 minutes:
 *
 *   * /5 * * * *  php /path/to/api/cron/cleanup.php
 *
 * Safe to run as often as you like. Cleanup also happens opportunistically
 * during normal API traffic, so this cron is a belt-and-braces measure for
 * quiet periods.
 */

use Walkie\Kernel\Autoloader;
use Walkie\Kernel\Config;
use Walkie\Shared\Cleanup;

if (PHP_SAPI !== 'cli' && !isset($_GET['__cron_key'])) {
    http_response_code(403);
    exit("CLI only\n");
}

require __DIR__ . '/../src/Kernel/Autoloader.php';
Autoloader::register(__DIR__ . '/../src');
Config::load(__DIR__ . '/../config/config.php');

Cleanup::run();
fwrite(STDOUT, '[' . gmdate('c') . "] cleanup done\n");
