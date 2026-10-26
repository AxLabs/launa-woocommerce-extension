<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP POST …/complete: 402 challenge relay + settlement reconcile.
 *
 * External-URL path (ucp-x402-binding §3.1): HTTP 402 is on the shop complete
 * URL; PaymentRequired.resource stays the Launa gateway URL. The shop MUST NOT
 * replay PAYMENT-SIGNATURE to that gateway (confused deputy). Agents pay
 * resource.url with standard x402, then POST complete again. This class
 * GETs the gateway without a signature only to copy PAYMENT-REQUIRED.
 * See docs/ucp.md.
 */
final class Launa_WC_Ucp_Complete
{
    private Launa_WC_Ucp_Gateway_Http $http;

    private Launa_WC_Ucp_Checkout $checkout;

    private int $poll_attempts;

    private int $poll_sleep_seconds;

    public function __construct(
        ?Launa_WC_Ucp_Gateway_Http $http = null,
        ?Launa_WC_Ucp_Checkout $checkout = null,
        int $poll_attempts = 10,
        int $poll_sleep_seconds = 1
    ) {
        $this->http = $http ?? new Launa_WC_Ucp_Gateway_Http();
        $this->checkout = $checkout ?? new Launa_WC_Ucp_Checkout();
        $this->poll_attempts = max(0, $poll_attempts);
        $this->poll_sleep_seconds = max(0, $poll_sleep_seconds);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function handle(string $session_id, WP_REST_Request $request, array $body): WP_REST_Response|WP_Error
    {
        $order = Launa_WC_Ucp_Checkout::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }

        $idempotency_key = Launa_WC_Ucp_Complete_Idempotency::from_request($request);
        $replay = Launa_WC_Ucp_Complete_Idempotency::replay($order, $idempotency_key);
        if ($replay instanceof WP_REST_Response) {
            return $replay;
        }

        // Older agents may still send PAYMENT-SIGNATURE; binding §3.1.5 forbids forwarding.
        unset($request);

        $response = $this->complete($order, $body);
        if ($response instanceof WP_Error) {
            return $response;
        }

        return Launa_WC_Ucp_Complete_Idempotency::remember($order, $idempotency_key, $response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function complete(WC_Order $order, array $body): WP_REST_Response|WP_Error
    {
        Launa_WC_Settlement_Reconcile::reconcile_order($order, null, true);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        if ($order->is_paid()) {
            return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 200);
        }

        $computed = Launa_WC_Ucp_Mapper::status_for_order($order);
        if ($computed['status'] !== Launa_WC_Ucp_Status::READY) {
            $session = Launa_WC_Ucp_Mapper::session($order);
            $session['ucp']['status'] = 'error';
            return new WP_REST_Response($session, 200);
        }

        try {
            $this->checkout->maybe_prepare($order);
            $order = wc_get_order($order->get_id()) ?: $order;
        } catch (Throwable $e) {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', 'prepare_failed', $e->getMessage(), 'recoverable')]
            );
        }

        $options = Launa_WC_Order_Payment::settlement_options_from_order($order);
        if ($options === []) {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'payment_method_not_available',
                    'No settlement options are prepared for this session.',
                    'recoverable'
                )]
            );
        }

        $instrument = Launa_WC_Ucp_Asset_Match::preferred_instrument(
            is_array($body['payment']['instruments'] ?? null) ? $body['payment']['instruments'] : []
        );
        $preference_given = Launa_WC_Ucp_Asset_Match::has_preference($instrument);
        if (!$preference_given) {
            $stored = Launa_WC_Order_Payment::preferred_option_from_order($order, $options);
            if ($stored !== null) {
                $instrument = [
                    'id' => (string) ($stored['tokenId'] ?? ''),
                    'network' => (string) ($stored['network'] ?? ''),
                    'asset' => (string) ($stored['asset'] ?? ''),
                ];
                $preference_given = true;
            }
        }
        $matched = Launa_WC_Ucp_Asset_Match::match($options, $instrument);
        if ($preference_given && $matched === null) {
            $pairs = Launa_WC_Ucp_Asset_Match::available_pairs($options);
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'payment_method_not_available',
                    'Requested network/asset is not available. Available: ' . wp_json_encode($pairs),
                    'recoverable'
                )]
            );
        }
        $matched ??= $options[0];
        Launa_WC_Order_Payment::remember_preferred_token(
            $order,
            (string) ($matched['tokenId'] ?? '')
        );

        // Binding §3.1.5–6: ignore any PAYMENT-SIGNATURE on this request.
        // Complete is challenge (unpaid) or reconcile (already handled above).
        return $this->challenge($order, $matched, $options);
    }

    /**
     * @param array<string, mixed> $option selected (or default) settlement option
     * @param list<array<string, mixed>> $options all prepared settlement options
     */
    private function challenge(WC_Order $order, array $option, array $options = []): WP_REST_Response
    {
        $url = (string) ($option['gatewayUrl'] ?? '');
        if (!$this->assert_url($order, $url)) {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', 'invalid', 'Settlement endpoint is not allowed.', 'recoverable')]
            );
        }

        try {
            $upstream = $this->http->request($url, 'GET', ['accept' => 'application/json']);
        } catch (Throwable $e) {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', 'payment_failed', $e->getMessage(), 'recoverable')]
            );
        }

        $required = $upstream['headers']['payment-required'] ?? '';
        if ($upstream['status'] >= 200 && $upstream['status'] < 300 && $required === '') {
            $this->poll_paid($order);
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order && $fresh->is_paid()) {
                return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($fresh), 200);
            }
        }

        $body = [
            'ucp' => Launa_WC_Ucp_Response::ucp('dev.ucp.shopping.checkout', [
                'status' => 'error',
                'payment_handlers' => Launa_WC_Ucp_Response::session_payment_handlers(),
            ]),
            'id' => $order->get_order_key(),
            'status' => Launa_WC_Ucp_Status::READY,
            'messages' => [
                Launa_WC_Ucp_Response::payment_required_message($order->get_order_key()),
            ],
            'links' => [Launa_WC_Ucp_Response::payment_complete_link($order->get_order_key())],
        ];
        $instruments = Launa_WC_Ucp_Asset_Match::checkout_instruments(
            $options !== [] ? $options : [$option],
            $option
        );
        if ($instruments !== []) {
            $body['payment'] = ['instruments' => $instruments];
        }
        $actions = Launa_WC_Ucp_Response::payment_challenge_actions(Launa_WC_Ucp_Status::READY);
        if ($actions !== []) {
            $body['actions'] = $actions;
        }

        $response = new WP_REST_Response(
            $body,
            $upstream['status'] === 402 ? 402 : max($upstream['status'], 402)
        );

        if ($required !== '') {
            $response->header('PAYMENT-REQUIRED', $required);
        }
        $response->header('Cache-Control', 'no-store');
        $response->header('Access-Control-Expose-Headers', 'PAYMENT-REQUIRED, PAYMENT-RESPONSE');

        return $response;
    }

    private function poll_paid(WC_Order $order): bool
    {
        $attempts = max(1, $this->poll_attempts);
        for ($i = 0; $i < $attempts; $i++) {
            Launa_WC_Settlement_Reconcile::reconcile_order($order, null, true);
            $fresh = wc_get_order($order->get_id());
            if ($fresh instanceof WC_Order && $fresh->is_paid()) {
                return true;
            }
            if ($this->poll_sleep_seconds > 0 && $i < $attempts - 1) {
                sleep($this->poll_sleep_seconds);
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $option
     */
    private function assert_url(WC_Order $order, string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $settings = Launa_WC_Settings::all();
        return Launa_WC_Ucp_Gateway_Http::url_is_allowed(
            $url,
            [
                $settings['gateway_host'],
                $settings['hedera_gateway_host'],
            ],
            Launa_WC_Ucp_Gateway_Http::allowed_paths_for_order($order)
        );
    }
}
