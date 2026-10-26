<?php
declare(strict_types=1);

namespace Launa\WC\Tests\Unit;

use Launa_WC_Ucp_Gateway_Http;
use Launa_WC_Ucp_Leak;
use PHPUnit\Framework\TestCase;

final class UcpLeakAndGatewayHttpTest extends TestCase
{
    public function test_scan_finds_gateway_host_and_meta_keys(): void
    {
        $settings = [
            'gateway_host' => 'shop.dev.x.launa.io',
            'api_id' => 'api_abc123',
        ];
        $hits = Launa_WC_Ucp_Leak::scan(
            [
                'payment_url' => 'https://shop.dev.x.launa.io/pay',
                'endpointId' => 'ep1',
                'api' => 'api_abc123',
            ],
            Launa_WC_Ucp_Leak::needles($settings)
        );
        $this->assertContains('shop.dev.x.launa.io', $hits);
        $this->assertContains('payment_url', $hits);
        $this->assertContains('endpointId', $hits);
        $this->assertContains('api_abc123', $hits);
    }

    public function test_clean_profile_shape_has_no_hits(): void
    {
        $hits = Launa_WC_Ucp_Leak::scan(
            [
                'ucp' => [
                    'services' => [
                        'dev.ucp.shopping' => [[
                            'endpoint' => 'https://shop.example/wp-json/ucp/v1',
                        ]],
                    ],
                ],
            ],
            Launa_WC_Ucp_Leak::needles([
                'gateway_host' => 'shop.dev.x.launa.io',
                'api_id' => 'api_abc123',
            ])
        );
        $this->assertSame([], $hits);
    }

    public function test_host_allowlist(): void
    {
        $this->assertTrue(Launa_WC_Ucp_Gateway_Http::host_is_allowed(
            'shop.dev.x.launa.io',
            ['shop.dev.x.launa.io']
        ));
        $this->assertTrue(Launa_WC_Ucp_Gateway_Http::host_is_allowed(
            'other.launa.io',
            []
        ));
        $this->assertFalse(Launa_WC_Ucp_Gateway_Http::host_is_allowed(
            'evil.example',
            ['shop.dev.x.launa.io']
        ));
    }

    public function test_url_path_must_match(): void
    {
        $ok = Launa_WC_Ucp_Gateway_Http::url_is_allowed(
            'https://shop.dev.x.launa.io/wp-json/launa/v1/fulfill/key/tok/slug',
            ['shop.dev.x.launa.io'],
            ['/wp-json/launa/v1/fulfill/key/tok/slug']
        );
        $this->assertTrue($ok);

        $bad = Launa_WC_Ucp_Gateway_Http::url_is_allowed(
            'https://shop.dev.x.launa.io/wp-json/launa/v1/fulfill/other',
            ['shop.dev.x.launa.io'],
            ['/wp-json/launa/v1/fulfill/key/tok/slug']
        );
        $this->assertFalse($bad);
    }

    public function test_http_transport_override(): void
    {
        $http = new Launa_WC_Ucp_Gateway_Http(
            static function (string $url, string $method, array $headers, string $body): array {
                unset($headers, $body);
                return [
                    'status' => 402,
                    'headers' => ['PAYMENT-REQUIRED' => 'abc'],
                    'body' => '',
                    'url' => $url,
                    'method' => $method,
                ];
            }
        );
        $result = $http->request('https://shop.dev.x.launa.io/x', 'GET');
        $this->assertSame(402, $result['status']);
        $this->assertSame('abc', $result['headers']['payment-required']);
    }

    public function test_redact_facilitator_urls_in_receipt(): void
    {
        $redacted = Launa_WC_Ucp_Leak::redact_facilitator_urls([
            'transaction' => '0xabc',
            'resourceUrl' => 'https://shop.dev.x.launa.io/wp-json/launa/v1/fulfill/k/t',
            'nested' => ['url' => 'https://shop.dev.x.launa.io/x'],
        ]);
        $this->assertSame('0xabc', $redacted['transaction']);
        $this->assertSame('[redacted]', $redacted['resourceUrl']);
        $this->assertSame('[redacted]', $redacted['nested']['url']);
    }
}
