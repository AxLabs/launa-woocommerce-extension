<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Cleanup Launa endpoints when orders are cancelled/failed or via cron.
 */
final class Launa_WC_Endpoint_Cleanup
{
    public function register(): void
    {
        add_action('woocommerce_order_status_cancelled', [$this, 'on_terminal_status'], 10, 1);
        add_action('woocommerce_order_status_failed', [$this, 'on_terminal_status'], 10, 1);
        add_action('woocommerce_order_status_refunded', [$this, 'on_terminal_status'], 10, 1);
        add_action('launa_wc_prune_endpoints', [$this, 'prune_stale']);
    }

    public function on_terminal_status(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if ($order instanceof WC_Order) {
            Launa_WC_Order_Payment::delete_endpoint_for_order($order);
        }
    }

    /**
     * Delete endpoints for unpaid Launa orders older than 48 hours.
     */
    public function prune_stale(): void
    {
        $orders = wc_get_orders([
            'limit' => 50,
            'status' => ['pending', 'on-hold'],
            'payment_method' => Launa_WC_Gateway_Launa::GATEWAY_ID,
            'date_created' => '<' . (time() - 2 * DAY_IN_SECONDS),
            'meta_key' => Launa_WC_Order_Payment::META_ENDPOINT_ID,
            'meta_compare' => 'EXISTS',
        ]);

        foreach ($orders as $order) {
            if ($order instanceof WC_Order) {
                Launa_WC_Order_Payment::delete_endpoint_for_order($order);
            }
        }
    }
}
