<?php
declare(strict_types=1);

namespace Walkie\Models;

use Walkie\Core\Database;

final class UserRepo
{
    /** Find by email or create a fresh user. Returns the user id. */
    public static function findOrCreate(string $email): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $default = 'Walkie ' . substr(strtoupper(bin2hex(random_bytes(2))), 0, 4);
        $ins = $pdo->prepare(
            'INSERT INTO users (email, display_name, created_at, updated_at)
             VALUES (:e, :n, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $ins->execute([':e' => $email, ':n' => $default]);
        return (int) $pdo->lastInsertId();
    }

    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function emailExists(string $email, int $exceptId): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT 1 FROM users WHERE email = :e AND id <> :id LIMIT 1'
        );
        $stmt->execute([':e' => $email, ':id' => $exceptId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function update(int $id, ?string $displayName, ?string $email): void
    {
        $sets = [];
        $params = [':id' => $id];
        if ($displayName !== null) {
            $sets[] = 'display_name = :n';
            $params[':n'] = $displayName;
        }
        if ($email !== null) {
            $sets[] = 'email = :e';
            $params[':e'] = $email;
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
        Database::pdo()->prepare($sql)->execute($params);
    }
}
