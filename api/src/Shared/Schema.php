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
        self::ensureDeliveredAt();
        self::ensureOauthTable();
    }

    private static function ensureDeliveredAt(): void
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

    /** Create the OAuth refresh-token store on first request after deploy. */
    private static function ensureOauthTable(): void
    {
        $marker = dirname(__DIR__, 2) . '/storage/.schema_oauth';
        if (is_file($marker)) {
            return;
        }
        try {
            Database::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id BIGINT UNSIGNED NOT NULL,
                    token_hash CHAR(64) NOT NULL,
                    created_at DATETIME NOT NULL,
                    expires_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_oauth_refresh_hash (token_hash),
                    KEY idx_oauth_refresh_user (user_id),
                    KEY idx_oauth_refresh_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            @file_put_contents($marker, gmdate('c') . "\n");
        } catch (\Throwable $e) {
            // No marker written → retried on the next request.
        }
    }
}
