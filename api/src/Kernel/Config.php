<?php
declare(strict_types=1);

namespace Walkie\Kernel;

/**
 * Tiny read-only configuration container.
 * Loads config/config.php once and exposes dot-path access.
 */
final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Missing config.php — copy config.sample.php first.');
        }
        /** @var array $cfg */
        $cfg = require $file;
        self::$data = is_array($cfg) ? $cfg : [];
    }

    /** Dot-path getter, e.g. Config::get('db.host'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $node = self::$data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }
        return $node;
    }
}
