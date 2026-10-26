<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Checkout Blocks payment method registration.
 */
final class Launa_WC_Blocks_Support extends AbstractPaymentMethodType
{
    protected $name = Launa_WC_Gateway_Launa::GATEWAY_ID;

    public function initialize(): void
    {
        $this->settings = get_option('woocommerce_launa_settings', []);
    }

    public function is_active(): bool
    {
        $gateway = new Launa_WC_Gateway_Launa();
        return $gateway->is_available();
    }

    /**
     * @return string[]
     */
    public function get_payment_method_script_handles(): array
    {
        $asset_file = LAUNA_WC_PLUGIN_DIR . 'build/blocks.asset.php';
        $asset = is_readable($asset_file)
            ? require $asset_file
            : ['dependencies' => [], 'version' => LAUNA_WC_VERSION];

        // WooCommerce packages are webpack externals and are often omitted from *.asset.php.
        $dependencies = array_values(array_unique(array_merge(
            is_array($asset['dependencies'] ?? null) ? $asset['dependencies'] : [],
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'react',
            ]
        )));

        wp_register_script(
            'launa-wc-blocks',
            LAUNA_WC_PLUGIN_URL . 'build/blocks.js',
            $dependencies,
            (string) ($asset['version'] ?? LAUNA_WC_VERSION),
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('launa-wc-blocks', 'ax402-for-woocommerce');
        }

        return ['launa-wc-blocks'];
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data(): array
    {
        $gateway = new Launa_WC_Gateway_Launa();
        return [
            'title' => $gateway->title,
            'description' => wp_kses(
                (string) $gateway->description,
                ['strong' => []]
            ),
            'supports' => array_filter($gateway->supports, [$gateway, 'supports']),
            'icon' => $gateway->icon,
        ];
    }
}
