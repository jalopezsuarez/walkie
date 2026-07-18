<?php
declare(strict_types=1);

namespace Walkie\Security;

use Walkie\Core\ApiException;
use Walkie\Core\Config;
use Walkie\Core\Database;

/**
 * Fixed-window rate limiter backed by the rate_limits table.
 *
 * Each bucket is a string like "code_ip:203.0.113.4". The window length and
 * cap come from config('limits.*'). Exceeding the cap throws a 429 with a
 * Retry-After hint — this is the "block the API when it saturates" defence.
 */
final class RateLimiter
{
    /**
     * @param string $rule  key under config('limits'), e.g. 'api_per_ip'
     * @param string $id    identity within the rule (ip, email, user id…)
     * @throws ApiException 429 when the limit is exceeded
     */
    public static function enforce(string $rule, string $id): void
    {
        $conf = Config::get("limits.$rule");
        if (!is_array($conf) || count($conf) !== 2) {
            return; // unknown rule => no limit
        }
        [$max, $window] = $conf;
        $max = (int) $max;
        $window = (int) $window;

        $now = time();
        $windowStart = $now - ($now % $window);
        $bucket = $rule . ':' . $id . ':' . $windowStart;

        $pdo = Database::pdo();
        // Atomic upsert-and-increment.
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (bucket, window_start, counter)
             VALUES (:b, :w, 1)
             ON DUPLICATE KEY UPDATE counter = counter + 1'
        );
        $stmt->execute([
            ':b' => substr($bucket, 0, 191),
            ':w' => gmdate('Y-m-d H:i:s', $windowStart),
        ]);

        $current = (int) $pdo->query(
            'SELECT counter FROM rate_limits WHERE bucket = ' . $pdo->quote(substr($bucket, 0, 191))
        )->fetchColumn();

        if ($current > $max) {
            $retry = ($windowStart + $window) - $now;
            throw ApiException::tooMany('Too many requests, slow down.', max(1, $retry));
        }
    }

    /** Opportunistic cleanup of stale counters (called from Cleanup). */
    public static function purgeOld(): void
    {
        Database::pdo()->exec(
            "DELETE FROM rate_limits WHERE window_start < (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        );
    }
}
