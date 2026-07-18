<?php
declare(strict_types=1);

namespace Walkie\Shared;

use Walkie\Kernel\Database;

/**
 * Lightweight self-healing schema check.
 *
 * Runs at most once per deploy: a marker file in storage/ gates it, so the
 * (slightly costly) information_schema lookup + ALTER only happen on the first
 * request after new columns are introduced. Keeps the app deployable without a
 * separate migration step on hosts where we can't run SQL directly.
 */
final class Schema
{
    public static function ensure(): void
    {
        $marker = dirname(__DIR__, 2) . '/storage/.schema_delivered';
        if (is_file($marker)) {
            return;
        }
        try {
            $pdo = Database::pdo();
            $has = (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'messages'
                    AND COLUMN_NAME = 'delivered_at'"
            )->fetchColumn();

            if ($has === 0) {
                $pdo->exec(
                    "ALTER TABLE messages
                        ADD COLUMN delivered_at DATETIME NULL AFTER duration_ms,
                        ADD KEY idx_messages_delivered (delivered_at)"
                );
            }
            @file_put_contents($marker, gmdate('c') . "\n");
        } catch (\Throwable $e) {
            // No marker written → retried on the next request.
        }
    }
}
