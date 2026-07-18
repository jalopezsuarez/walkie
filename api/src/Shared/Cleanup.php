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
 * Deletion rules for messages:
 *   - expires_at reached          → delete  (audio +1h, text +24h caps)
 *   - read by the recipient       → delete  (short grace so the reader's
 *                                             client has received it)
 */
final class Cleanup
{
    private const READ_GRACE_SECONDS = 5;

    public static function run(): void
    {
        $pdo = Database::pdo();

        // Expired or already-read messages.
        $pdo->prepare(
            'DELETE FROM messages
              WHERE expires_at <= UTC_TIMESTAMP()
                 OR (read_at IS NOT NULL AND read_at <= (UTC_TIMESTAMP() - INTERVAL :g SECOND))'
        )->execute([':g' => self::READ_GRACE_SECONDS]);

        // Expired auth artefacts.
        $pdo->exec('DELETE FROM login_codes   WHERE expires_at <= UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM pairing_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        $pdo->exec('DELETE FROM sessions       WHERE expires_at <= UTC_TIMESTAMP()');

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
