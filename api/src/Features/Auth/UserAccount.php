<?php
declare(strict_types=1);

namespace Walkie\Features\Auth;

use Walkie\Kernel\Database;

/**
 * Account lookup/creation owned by the Auth slice.
 */
final class UserAccount
{
    /** Find by email or create a fresh user. Returns the user id. */
    public static function findOrCreate(string $email): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $default = 'Walkie ' . strtoupper(bin2hex(random_bytes(2)));
        $pdo->prepare(
            'INSERT INTO users (email, display_name, created_at, updated_at)
             VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        )->execute([$email, $default]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array{id:int, email:string, display_name:string}|null */
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, display_name FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        return self::hydrate($stmt->fetch());
    }

    /** @return array{id:int, email:string, display_name:string}|null */
    public static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, email, display_name FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        return self::hydrate($stmt->fetch());
    }

    /** @param array<string,mixed>|false $row */
    private static function hydrate(array|false $row): ?array
    {
        return $row ? [
            'id'           => (int) $row['id'],
            'email'        => (string) $row['email'],
            'display_name' => (string) $row['display_name'],
        ] : null;
    }
}
