<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Ucp_Catalog;
use Launa_WC_Ucp_Fulfillment;
use PHPUnit\Framework\TestCase;

final class UcpFulfillmentAndDescriptionTest extends TestCase
{
    public function test_expectation_method_type_uses_digital_only_on_orders(): void
    {
        $this->assertSame('digital', Launa_WC_Ucp_Fulfillment::expectation_method_type(false, false));
        $this->assertSame('digital', Launa_WC_Ucp_Fulfillment::expectation_method_type(false, true));
        $this->assertSame('shipping', Launa_WC_Ucp_Fulfillment::expectation_method_type(true, false));
        $this->assertSame('pickup', Launa_WC_Ucp_Fulfillment::expectation_method_type(true, true));
    }

    public function test_pickup_method_ids(): void
    {
        $this->assertTrue(Launa_WC_Ucp_Fulfillment::is_pickup_method_id('local_pickup'));
        $this->assertTrue(Launa_WC_Ucp_Fulfillment::is_pickup_method_id('pickup_location'));
        $this->assertFalse(Launa_WC_Ucp_Fulfillment::is_pickup_method_id('flat_rate'));
    }

    public function test_variant_description_falls_back_to_title(): void
    {
        $desc = Launa_WC_Ucp_Catalog::description_object('', '', 'Micropay');
        $this->assertSame('Micropay', $desc['plain']);
        $this->assertArrayNotHasKey('html', $desc);
    }

    public function test_variant_description_strips_html_and_keeps_html_field(): void
    {
        $desc = Launa_WC_Ucp_Catalog::description_object('<p>Hello <b>there</b></p>', '', 'x');
        $this->assertSame('Hello there', $desc['plain']);
        $this->assertSame('<p>Hello <b>there</b></p>', $desc['html']);
    }
}
