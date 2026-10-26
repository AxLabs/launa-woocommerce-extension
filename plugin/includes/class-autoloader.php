<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * PSR-4-ish autoloader for Launa_WC_* classes in includes/.
 */
final class Launa_WC_Autoloader
{
    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    public static function autoload(string $class): void
    {
        if (!str_starts_with($class, 'Launa_WC_')) {
            return;
        }

        $relative = strtolower(str_replace('_', '-', substr($class, strlen('Launa_WC_'))));
        $path = LAUNA_WC_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
