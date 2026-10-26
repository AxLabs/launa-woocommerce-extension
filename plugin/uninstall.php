<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package Launa_For_WooCommerce
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$launa_wc_option_keys = [
    'launa_wc_settings',
    'launa_wc_platform_cache',
    'launa_wc_cors_origins',
    'launa_wc_cors_synced_at',
    'launa_wc_cors_error',
    'launa_wc_ucp_rewrite',
    'woocommerce_launa_settings',
    'launa_wc_migrated_from_ax402',
    'ax402_wc_settings',
    'ax402_wc_platform_cache',
    'ax402_wc_cors_origins',
    'ax402_wc_cors_synced_at',
    'ax402_wc_cors_error',
    'ax402_wc_ucp_rewrite',
    'woocommerce_ax402_settings',
];

foreach ($launa_wc_option_keys as $launa_wc_key) {
    delete_option($launa_wc_key);
}

delete_transient('launa_wc_chainlist_v1');
delete_transient('ax402_wc_chainlist_v1');

global $wpdb;
if (isset($wpdb) && $wpdb instanceof wpdb) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_launa_wc_') . '%',
            $wpdb->esc_like('_transient_timeout_launa_wc_') . '%',
            $wpdb->esc_like('_site_transient_launa_wc_') . '%',
            $wpdb->esc_like('_site_transient_timeout_launa_wc_') . '%',
            $wpdb->esc_like('_transient_ax402_wc_') . '%',
            $wpdb->esc_like('_transient_timeout_ax402_wc_') . '%'
        )
    );
}

wp_clear_scheduled_hook('launa_wc_prune_endpoints');
wp_clear_scheduled_hook('ax402_wc_prune_endpoints');
