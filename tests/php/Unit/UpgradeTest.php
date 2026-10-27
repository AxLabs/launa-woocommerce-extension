<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!function_exists('wc_get_orders')) {
    /**
     * @param array<string, mixed> $args
     * @return array<int, mixed>
     */
    function wc_get_orders($args = [])
    {
        unset($args);

        return [];
    }
}

final class UpgradeTest extends TestCase
{
    public function test_order_queries_wait_until_the_order_factory_exists(): void
    {
        $this->assertFalse(Launa_WC_Upgrade::order_factory_is_ready(null));
        $this->assertTrue(Launa_WC_Upgrade::order_factory_is_ready(new stdClass()));
    }
}
