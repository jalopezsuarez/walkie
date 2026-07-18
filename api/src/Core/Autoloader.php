<?php
declare(strict_types=1);

namespace Walkie\Core;

/**
 * Minimal PSR-4 autoloader for the Walkie\ namespace → api/src/.
 */
final class Autoloader
{
    public static function register(string $srcDir): void
    {
        spl_autoload_register(static function (string $class) use ($srcDir): void {
            $prefix = 'Walkie\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $relative = substr($class, strlen($prefix));
            $path = $srcDir . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });
    }
}
