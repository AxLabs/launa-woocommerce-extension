<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * One-time move from Ax402 option keys, order meta, and cron to Launa.
 *
 * The ciphertext prefix in Launa_WC_Crypto stays ax402v1 so stored API keys
 * still decrypt. This class only renames WordPress identifiers.
 */
final class Launa_WC_Upgrade
{
    public const FLAG = 'launa_wc_migrated_from_ax402';

    public static function maybe_run(): void
    {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        if (get_option(self::FLAG) === '1') {
            return;
        }

        self::copy_options();
        self::migrate_payment_methods();
        self::rename_meta_keys();
        self::migrate_cron();

        update_option(self::FLAG, '1', false);
    }

    private static function copy_options(): void
    {
        $pairs = [
            'woocommerce_ax402_settings' => 'woocommerce_launa_settings',
            'ax402_wc_settings' => 'launa_wc_settings',
            'ax402_wc_platform_cache' => 'launa_wc_platform_cache',
            'ax402_wc_cors_origins' => 'launa_wc_cors_origins',
            'ax402_wc_cors_synced_at' => 'launa_wc_cors_synced_at',
            'ax402_wc_cors_error' => 'launa_wc_cors_error',
            'ax402_wc_ucp_rewrite' => 'launa_wc_ucp_rewrite',
        ];
        foreach ($pairs as $from => $to) {
            self::copy_option($from, $to);
        }
    }

    private static function copy_option(string $from, string $to): void
    {
        $existing = get_option($to, null);
        $has_new = $existing !== null && $existing !== false && $existing !== '' && $existing !== [];
        if ($has_new) {
            delete_option($from);
            return;
        }

        $old = get_option($from, null);
        if ($old === null || $old === false) {
            return;
        }

        update_option($to, $old);
        delete_option($from);
    }

    private static function migrate_payment_methods(): void
    {
        if (!function_exists('wc_get_orders')) {
            return;
        }

        $statuses = ['any', 'trash'];
        if (function_exists('wc_get_order_statuses')) {
            $statuses = array_merge(array_keys(wc_get_order_statuses()), ['trash']);
        }

        $guard = 0;
        do {
            $orders = wc_get_orders([
                'payment_method' => 'ax402',
                'limit' => 50,
                'status' => $statuses,
                'return' => 'objects',
            ]);
            if (!is_array($orders) || $orders === []) {
                break;
            }
            foreach ($orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }
                $order->set_payment_method('launa');
                $title = (string) $order->get_payment_method_title();
                if ($title === '' || stripos($title, 'ax402') !== false) {
                    $order->set_payment_method_title('Pay with Launa');
                }
                $order->save();
            }
            $guard++;
        } while ($guard < 200);
    }

    private static function rename_meta_keys(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !$wpdb instanceof wpdb) {
            return;
        }

        $tables = [$wpdb->postmeta];
        $orders_meta = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta));
        if ($found === $orders_meta) {
            $tables[] = $orders_meta;
        }

        foreach ($tables as $table) {
            $like = $wpdb->esc_like('_ax402_') . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET meta_key = REPLACE(meta_key, %s, %s) WHERE meta_key LIKE %s",
                '_ax402_',
                '_launa_',
                $like
            ));
        }
    }

    private static function migrate_cron(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('ax402_wc_prune_endpoints');
        }
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        if (!wp_next_scheduled('launa_wc_prune_endpoints') && defined('HOUR_IN_SECONDS')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'launa_wc_prune_endpoints');
        }
    }
}
