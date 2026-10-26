<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * REST namespaces. launa/v1 is canonical. ax402/v1 stays registered for one
 * release so existing gateway fulfill URLs and agent clients keep working.
 */
final class Launa_WC_Rest
{
    public const NS = 'launa/v1';

    public const LEGACY_NS = 'ax402/v1';

    /**
     * @return list<string>
     */
    public static function namespaces(): array
    {
        return [self::NS, self::LEGACY_NS];
    }
}
