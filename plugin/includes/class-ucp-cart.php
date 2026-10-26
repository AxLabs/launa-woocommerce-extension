<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * UCP cart: lightweight basket before checkout. Same Woo order as checkout
 * after cart_id conversion (idempotent).
 */
final class Launa_WC_Ucp_Cart
{
    public const CAP = 'dev.ucp.shopping.cart';
    public const META_ORIGIN = '_launa_ucp_origin_cart';

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
                    'Store currency must be USD for Launa UCP cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Launa_WC_Ucp_Response::rest_error(
                400,
                [Launa_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')],
                self::CAP
            );
        }

        $order = wc_create_order();
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return new WP_Error('launa_ucp_cart', 'Could not create cart', ['status' => 500]);
        }

        try {
            Launa_WC_Ucp_Lines::replace($order, $line_items);
            Launa_WC_Ucp_Lines::apply_buyer($order, is_array($body['buyer'] ?? null) ? $body['buyer'] : []);
            if (isset($body['context']) && is_array($body['context'])) {
                Launa_WC_Ucp_Lines::apply_context($order, $body['context']);
            }
            $order->set_payment_method(Launa_WC_Gateway_Launa::GATEWAY_ID);
            $order->set_payment_method_title('Launa');
            $order->update_meta_data(Launa_WC_Ucp_Mapper::META_UCP, '1');
            $order->update_meta_data(self::META_ORIGIN, '1');
            Launa_WC_Ucp_Kind::set($order, Launa_WC_Ucp_Kind::CART);
            Launa_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            $order->update_status('pending', __('UCP cart session.', 'ax402-for-woocommerce'));
            $order->save();
        } catch (InvalidArgumentException $e) {
            $order->delete(true);
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'unrecoverable')],
                self::CAP
            );
        } catch (Throwable $e) {
            $order->delete(true);
            self::log_failure('create', $e);
            return Launa_WC_Ucp_Response::rest_error(
                500,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not create the cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 201);
    }

    public function get(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(string $cart_id, array $body): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order || $order->has_status(['cancelled', 'canceled', 'refunded', 'failed'])) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }
        if ($order->is_paid()) {
            return Launa_WC_Ucp_Response::rest_error(
                409,
                [Launa_WC_Ucp_Response::message('error', 'conflict', 'Paid carts cannot be updated.', 'unrecoverable')],
                self::CAP,
                ['id' => $cart_id]
            );
        }

        $line_items = $body['line_items'] ?? null;
        if (!is_array($line_items) || $line_items === []) {
            return Launa_WC_Ucp_Response::rest_error(
                400,
                [Launa_WC_Ucp_Response::message('error', 'invalid', 'line_items is required', 'unrecoverable')],
                self::CAP
            );
        }

        try {
            Launa_WC_Ucp_Lines::replace($order, $line_items);
            if (isset($body['buyer']) && is_array($body['buyer'])) {
                Launa_WC_Ucp_Lines::apply_buyer($order, $body['buyer']);
            }
            if (isset($body['context']) && is_array($body['context'])) {
                Launa_WC_Ucp_Lines::apply_context($order, $body['context']);
            }
            Launa_WC_Ucp_Fulfillment::sync_shipping($order);
            $order->calculate_totals();
            if (Launa_WC_Ucp_Kind::get($order) === Launa_WC_Ucp_Kind::CHECKOUT) {
                (new Launa_WC_Ucp_Checkout())->maybe_prepare($order);
            }
            $order->save();
        } catch (InvalidArgumentException $e) {
            return Launa_WC_Ucp_Response::rest_error(
                200,
                [Launa_WC_Ucp_Response::message('error', 'invalid', $e->getMessage(), 'recoverable')],
                self::CAP
            );
        } catch (Throwable $e) {
            self::log_failure('update', $e);
            return Launa_WC_Ucp_Response::rest_error(
                500,
                [Launa_WC_Ucp_Response::message(
                    'error',
                    'processing_error',
                    'Could not update the cart.',
                    'unrecoverable'
                )],
                self::CAP
            );
        }

        return new WP_REST_Response(self::payload($order), 200);
    }

    public function cancel(string $cart_id): WP_REST_Response|WP_Error
    {
        $order = self::from_id($cart_id);
        if (!$order instanceof WC_Order) {
            return Launa_WC_Ucp_Response::rest_error(
                404,
                [Launa_WC_Ucp_Response::message('error', 'not_found', 'Cart not found.', 'unrecoverable')],
                self::CAP
            );
        }
        if ($order->is_paid()) {
            return Launa_WC_Ucp_Response::rest_error(
                409,
                [Launa_WC_Ucp_Response::message('error', 'conflict', 'Paid carts cannot be canceled.', 'unrecoverable')],
                self::CAP
            );
        }

        $payload = self::payload($order);
        if (!$order->has_status(['cancelled', 'canceled'])) {
            $order->update_status('cancelled', __('UCP cart canceled by agent.', 'ax402-for-woocommerce'));
        }

        return new WP_REST_Response($payload, 200);
    }

    public static function from_id(string $cart_id): ?WC_Order
    {
        $order = Launa_WC_Ucp_Checkout::order_from_key($cart_id);
        if (!$order instanceof WC_Order) {
            return null;
        }
        if ((string) $order->get_meta(self::META_ORIGIN) !== '1') {
            return null;
        }

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(WC_Order $order): array
    {
        $payload = [
            'ucp' => Launa_WC_Ucp_Response::ucp(self::CAP),
            'id' => $order->get_order_key(),
            'currency' => Launa_WC_Ucp_Money::CURRENCY,
            'line_items' => Launa_WC_Ucp_Mapper::line_items($order),
            'totals' => Launa_WC_Ucp_Mapper::totals($order),
            'links' => Launa_WC_Ucp_Mapper::links(),
            'continue_url' => self::continue_url(),
        ];

        $buyer = Launa_WC_Ucp_Mapper::buyer($order);
        if ($buyer !== []) {
            $payload['buyer'] = $buyer;
        }

        $created = $order->get_date_created();
        if ($created instanceof \DateTimeInterface) {
            $payload['expires_at'] = gmdate('c', $created->getTimestamp() + 6 * 3600);
        }

        Launa_WC_Ucp_Leak::assert_clean($payload, Launa_WC_Settings::all());

        return $payload;
    }

    private static function log_failure(string $op, Throwable $e): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->error(
                'UCP cart ' . $op . ': ' . $e->getMessage(),
                ['source' => 'launa-ucp']
            );
        }
    }

    private static function continue_url(): string
    {
        if (function_exists('wc_get_page_permalink')) {
            $shop = wc_get_page_permalink('shop');
            if (is_string($shop) && $shop !== '') {
                return $shop;
            }
        }

        return home_url('/');
    }
}
