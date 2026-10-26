<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Order_Payment;
use PHPUnit\Framework\TestCase;

final class FulfillPathTest extends TestCase
{
    public function test_fulfill_path_encoding(): void
    {
        $path = Launa_WC_Order_Payment::fulfill_path('wc_order_abc', 'deadbeef');
        $this->assertSame('/wp-json/launa/v1/fulfill/wc_order_abc/deadbeef', $path);

        $withSlug = Launa_WC_Order_Payment::fulfill_path('wc_order_abc', 'deadbeef', 'a1b2c3d4e5f6');
        $this->assertSame(
            '/wp-json/launa/v1/fulfill/wc_order_abc/deadbeef/a1b2c3d4e5f6',
            $withSlug
        );
        $this->assertSame(12, strlen(Launa_WC_Order_Payment::token_path_slug('eip155:8453:0xabc')));
    }
}
