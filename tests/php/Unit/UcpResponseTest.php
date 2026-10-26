<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Ucp_Leak;
use Launa_WC_Ucp_Profile_Builder;
use Launa_WC_Ucp_Response;
use Launa_WC_Ucp_Status;
use PHPUnit\Framework\TestCase;

final class UcpResponseTest extends TestCase
{
    public function test_ucp_envelope_includes_schema_and_checkout_handlers(): void
    {
        $ucp = Launa_WC_Ucp_Response::ucp('dev.ucp.shopping.checkout', ['status' => 'error']);
        $this->assertSame('2026-08-25', $ucp['version']);
        $this->assertSame('error', $ucp['status']);
        $cap = $ucp['capabilities']['dev.ucp.shopping.checkout'][0];
        $this->assertSame('2026-08-25', $cap['version']);
        $this->assertStringContainsString('/specification/shopping/checkout', (string) $cap['spec']);
        $this->assertStringContainsString('/schemas/shopping/checkout.json', (string) $cap['schema']);
        $this->assertSame('org.x402.payment', $ucp['payment_handlers']['org.x402.payment'][0]['id']);
    }

    public function test_catalog_envelope_omits_payment_handlers(): void
    {
        $ucp = Launa_WC_Ucp_Response::ucp('dev.ucp.shopping.catalog.search');
        $this->assertArrayNotHasKey('payment_handlers', $ucp);
        $cap = $ucp['capabilities']['dev.ucp.shopping.catalog.search'][0];
        $this->assertArrayHasKey('schema', $cap);
    }

    public function test_rest_error_checkout_includes_payment_handlers(): void
    {
        $response = Launa_WC_Ucp_Response::rest_error(
            200,
            [Launa_WC_Ucp_Response::message('error', 'invalid', 'missing key', 'recoverable')]
        );
        $data = $response->get_data();
        $this->assertSame(200, $response->get_status());
        $this->assertSame('error', $data['ucp']['status']);
        $this->assertArrayHasKey('org.x402.payment', $data['ucp']['payment_handlers']);
        $this->assertSame('invalid', $data['messages'][0]['code']);
    }

    public function test_payment_challenge_action_on_ready_only(): void
    {
        $ready = Launa_WC_Ucp_Response::payment_challenge_actions(Launa_WC_Ucp_Status::READY);
        $this->assertArrayHasKey(Launa_WC_Ucp_Response::ACTION_PAYMENT_CHALLENGE, $ready);
        $config = $ready[Launa_WC_Ucp_Response::ACTION_PAYMENT_CHALLENGE][0]['config'];
        $this->assertSame(Launa_WC_Ucp_Response::PAYMENT_CHALLENGE_INSTRUCTIONS, $config['instructions']);
        $this->assertSame([], Launa_WC_Ucp_Response::payment_challenge_actions(Launa_WC_Ucp_Status::COMPLETED));
        $this->assertSame([], Launa_WC_Ucp_Response::payment_challenge_actions(Launa_WC_Ucp_Status::CANCELED));
    }

    public function test_payment_challenge_action_has_no_gateway_leak(): void
    {
        $actions = Launa_WC_Ucp_Response::payment_challenge_actions(Launa_WC_Ucp_Status::READY);
        $hits = Launa_WC_Ucp_Leak::scan(
            $actions,
            Launa_WC_Ucp_Leak::needles([
                'gateway_host' => 'shop.dev.x.launa.io',
                'api_id' => 'api_should_not_leak',
            ])
        );
        $this->assertSame([], $hits);
        $json = (string) json_encode($actions);
        $this->assertStringNotContainsString('http', $json);
        $this->assertStringNotContainsString('0x', $json);
    }

    public function test_handler_schema_constant_is_x402_org(): void
    {
        $this->assertSame(
            'https://x402.org/schemas/ucp-payment-handler.json',
            Launa_WC_Ucp_Profile_Builder::HANDLER_SCHEMA
        );
    }
}
