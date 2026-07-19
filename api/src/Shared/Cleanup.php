<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Database;

/**
 * Hard-deletes expired / read data so nothing lingers on the server.
 *
 * Runs both from cron (cron/cleanup.php) and opportunistically on a small
 * fraction of API requests, so retention holds even without a scheduler.
 *
 * Deletion rule for messages — a single fixed criterion, time only:
 *   - expires_at reached → delete  (audio +1h, text +24h from creation)
 * Reading a message never deletes it; it only flips its read flag.
 */
final class Cleanup
{
    public static function run(): void
    {
        $pdo = Database::pdo();

        // Only time-expired messages are removed.
        $pdo->exec('DELETE FROM messages WHERE expires_at <= UTC_TIMESTAMP()');

        // Expired auth artefacts.
        $pdo->exec('DELETE FROM login_codes          WHERE expires_at <= UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM pairing_tokens       WHERE expires_at <= UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM oauth_refresh_tokens WHERE expires_at <= UTC_TIMESTAMP()');

        RateLimiter::purgeOld();
    }

    /** Run cleanup on ~5% of requests to keep the DB tidy without cron. */
    public static function maybeRun(): void
    {
        if (random_int(1, 20) === 1) {
            try {
                self::run();
            } catch (\Throwable) {
                // never let cleanup break a request
            }
        }
    }
}
