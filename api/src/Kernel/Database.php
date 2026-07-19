<?php
declare(strict_types=1);

namespace Walkie\Kernel;

use PDO;

/**
 * PDO singleton with safe defaults (prepared statements, exceptions).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) Config::get('db.host', '127.0.0.1');
        $port = (int) Config::get('db.port', 3306);
        $name = (string) Config::get('db.name', '');
        $user = (string) Config::get('db.user', '');
        $pass = (string) Config::get('db.pass', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        // Always work in UTC so retention math is unambiguous.
        self::$pdo->exec("SET time_zone = '+00:00'");
        // READ COMMITTED so a long-polling request keeps seeing new commits
        // from other connections (REPEATABLE READ would pin a stale snapshot).
        self::$pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");

        return self::$pdo;
    }
}
