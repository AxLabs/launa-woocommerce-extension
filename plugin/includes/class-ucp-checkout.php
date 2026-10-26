<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP checkout-session create / get / update / cancel.
 *
 * Launa prepare() runs only when the session is ready_for_complete so shipping
 * and tax are in the charged total.
 */
final class Launa_WC_Ucp_Checkout
{
    /**
     * @param array<string, mixed> $body
     */
    public function create(array $body): WP_REST_Response|WP_Error
    {
        if (get_woocommerce_currency() !== 'USD') {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'unsupported_currency',
                    'Store currency must be USD for Launa UCP checkout.',
                    'unrecoverable'
                )]
            );
        }

        $cart_id = trim((string) ($body['cart_id'] ?? ''));
        if ($cart_id !== '') {
            return $this->create_from_cart($cart_id);
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Launa_WC_Ucp_Response::rest_error(
                400,
                [Launa_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')]
            );
        }

        $order = wc_create_order();
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return new WP_Error('launa_ucp_create', 'Could not create checkout session', ['status' => 500]);
        }

        try {
            Launa_WC_Ucp_Lines::replace($order, $line_items);
            Launa_WC_Ucp_Lines::apply_buyer($order, is_array($body['buyer'] ?? null) ? $body['buyer'] : []);
            if (isset($body['fulfillment']) && is_array($body['fulfillment'])) {
                Launa_WC_Ucp_Fulfillment::apply_request($order, $body['fulfillment']);
            }

            $order->set_payment_method(Launa_WC_Gateway_Launa::GATEWAY_ID);
            $order->set_payment_method_title('Launa');
            $order->update_meta_data(Launa_WC_Ucp_Mapper::META_UCP, '1');
            Launa_WC_Ucp_Kind::set($order, Launa_WC_Ucp_Kind::CHECKOUT);
            Launa_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $order->update_status('pending', __('UCP checkout session awaiting Launa payment.', 'ax402-for-woocommerce'));

            $this->maybe_prepare($order);
            $this->apply_payment_preference($order, $body);
            $order->save();
        } catch (InvalidArgumentException $e) {
            $order->delete(true);
            $code = str_contains($e->getMessage(), 'not available')
                ? 'payment_method_not_available'
                : 'invalid';
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', $code, $e->getMessage(), $code === 'invalid' ? 'unrecoverable' : 'recoverable')]
            );
        } catch (Throwable $e) {
            $order->delete(true);
            self::log_failure('create', $e);
            return Launa_WC_Ucp_Response::rest_error(
                500,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not create the checkout session.',
                    'unrecoverable'
                )]
            );
        }

        return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 201);
    }

    public function get(string $session_id): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }

        Launa_WC_Settlement_Reconcile::reconcile_order($order, null, true);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(string $session_id, array $body): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }
        if ($order->is_paid()) {
            return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 200);
        }
        if ($order->has_status(['cancelled', 'canceled'])) {
            return Launa_WC_Ucp_Response::rest_error(
                409,
                [Launa_WC_Ucp_Response::message('error', 'canceled', 'Checkout session is canceled.', 'unrecoverable')],
                'dev.ucp.shopping.checkout',
                ['id' => $session_id]
            );
        }

        try {
            if (isset($body['line_items']) && is_array($body['line_items']) && $body['line_items'] !== []) {
                Launa_WC_Ucp_Lines::replace($order, $body['line_items']);
            }
            if (isset($body['buyer']) && is_array($body['buyer'])) {
                Launa_WC_Ucp_Lines::apply_buyer($order, $body['buyer']);
            }
            if (isset($body['fulfillment']) && is_array($body['fulfillment'])) {
                Launa_WC_Ucp_Fulfillment::apply_request($order, $body['fulfillment']);
            }
            Launa_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $this->maybe_prepare($order);
            $this->apply_payment_preference($order, $body);
            $order->save();
        } catch (InvalidArgumentException $e) {
            $code = str_contains($e->getMessage(), 'not available')
                ? 'payment_method_not_available'
                : 'invalid';
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', $code, $e->getMessage(), 'recoverable')]
            );
        } catch (Throwable $e) {
            self::log_failure('update', $e);
            return Launa_WC_Ucp_Response::rest_error(
                500,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not update the checkout session.',
                    'unrecoverable'
                )]
            );
        }

        return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 200);
    }

    public function cancel(string $session_id): WP_REST_Response|WP_Error
    {
        $order = self::order_from_session($session_id);
        if (!$order instanceof WC_Order) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Checkout session not found.', 'unrecoverable')]
            );
        }
        if ($order->is_paid()) {
            return Launa_WC_Ucp_Response::rest_error(
                409,
                [Launa_WC_Ucp_Response::message('error', 'conflict', 'Paid sessions cannot be canceled.', 'unrecoverable')]
            );
        }
        if (!$order->has_status(['cancelled', 'canceled'])) {
            $order->update_status('cancelled', __('UCP checkout session canceled by agent.', 'ax402-for-woocommerce'));
        }

        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), 200);
    }

    public static function order_from_key(string $session_id): ?WC_Order
    {
        $order_id = wc_get_order_id_by_order_key($session_id);
        if (!$order_id) {
            return null;
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ($order->get_payment_method() !== Launa_WC_Gateway_Launa::GATEWAY_ID) {
            return null;
        }

        return $order;
    }

    public static function order_from_session(string $session_id): ?WC_Order
    {
        $order = self::order_from_key($session_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if (Launa_WC_Ucp_Kind::is_cart($order)) {
            return null;
        }

        return $order;
    }

    /**
     * Convert (or resume) a cart. Overlapping checkout fields are ignored.
     */
    public function create_from_cart(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = Launa_WC_Ucp_Cart::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')]
            );
        }

        $was_cart = Launa_WC_Ucp_Kind::is_cart($order);
        if ($was_cart && !$order->is_paid()) {
            Launa_WC_Ucp_Kind::set($order, Launa_WC_Ucp_Kind::CHECKOUT);
            $this->maybe_prepare($order);
            $order->save();
        }

        Launa_WC_Settlement_Reconcile::reconcile_order($order, null, true);
        $fresh = wc_get_order($order->get_id());
        if ($fresh instanceof WC_Order) {
            $order = $fresh;
        }

        return new WP_REST_Response(Launa_WC_Ucp_Mapper::session($order), $was_cart ? 201 : 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function apply_payment_preference(WC_Order $order, array $body): void
    {
        if (!array_key_exists('payment', $body) || !is_array($body['payment'])) {
            return;
        }

        $instruments = $body['payment']['instruments'] ?? null;
        if (!is_array($instruments) || $instruments === []) {
            return;
        }

        $instrument = Launa_WC_Ucp_Asset_Match::preferred_instrument($instruments);
        if (!Launa_WC_Ucp_Asset_Match::has_preference($instrument)) {
            return;
        }

        $options = Launa_WC_Order_Payment::settlement_options_from_order($order);
        if ($options === []) {
            $identity = trim((string) ($instrument['id'] ?? $instrument['token_id'] ?? $instrument['tokenId'] ?? ''));
            if ($identity !== '') {
                Launa_WC_Order_Payment::remember_preferred_token($order, $identity);
            }

            return;
        }

        $matched = Launa_WC_Ucp_Asset_Match::match($options, $instrument);
        if ($matched === null) {
            $pairs = Launa_WC_Ucp_Asset_Match::available_pairs($options);
            throw new InvalidArgumentException(
                'Requested network/asset is not available. Available: ' . wp_json_encode($pairs)
            );
        }

        Launa_WC_Order_Payment::remember_preferred_token(
            $order,
            (string) ($matched['tokenId'] ?? '')
        );
    }

    /**
     * Prepare Launa endpoints only for a final, ready total.
     */
    public function maybe_prepare(WC_Order $order): void
    {
        $computed = Launa_WC_Ucp_Mapper::status_for_order($order);
        if ($computed['status'] !== Launa_WC_Ucp_Status::READY) {
            return;
        }

        $current = Launa_WC_Money::normalize_order_total($order->get_total());
        $prepared = (string) $order->get_meta(Launa_WC_Ucp_Mapper::META_PREPARED_TOTAL);
        $options = Launa_WC_Order_Payment::settlement_options_from_order($order);
        if ($prepared === $current && $options !== []) {
            return;
        }

        Launa_WC_Order_Payment::prepare($order);
        $order->update_meta_data(Launa_WC_Ucp_Mapper::META_PREPARED_TOTAL, $current);
        $order->save();
    }

    private static function log_failure(string $op, Throwable $e): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                'UCP checkout ' . $op . ': ' . $e->getMessage(),
                ['source' => 'launa-ucp']
            );
        }
    }
}
