<?php

defined( 'ABSPATH' ) || exit;

/**
 * This class controls initialization, activation and deactivation of the plugin.
 */
class WC_Legacy_REST_API_Plugin
{
    /**
     * Holds the path of the main plugin file.
     */
    private static $plugin_filename;

    /**
     * Plugin initialization, to be invoked inside the woocommerce_init hook.
     */
    private static function init() {
        require_once __DIR__ . '/legacy/class-wc-legacy-api.php';
        require_once __DIR__ . '/class-wc-api.php';

        WC()->api = new WC_API();
        WC()->api->init();
        WC()->api->add_endpoint();
    }

    /**
     * Register the proper hook handlers.
     * 
     * @param string $plugin_filename The path to the main plugin file.
     */
    public static function register_hook_handlers( $plugin_filename ) {
        self::$plugin_filename = $plugin_filename;

        register_activation_hook( $plugin_filename, self::class . '::on_plugin_activated' );
        register_deactivation_hook( $plugin_filename, self::class . '::on_plugin_deactivated' );
        register_uninstall_hook( $plugin_filename, self::class . '::on_plugin_deactivated' );

        add_action( 'before_woocommerce_init', self::class . '::on_before_woocommerce_init' );
        add_action( 'woocommerce_init', self::class . '::on_woocommerce_init' );

        // 1717192800 = June 1st, 2024
        if( time() < 1717192800 ) {
            add_action( 'all_plugins', self::class . '::on_all_plugins' );
        }
    }

    /**
     * Act on plugin activation.
     */
    public static function on_plugin_activated() {
        if( ! self::legacy_api_still_in_woocommerce() ) {
            require_once __DIR__ . '/legacy/class-wc-legacy-api.php';
            require_once __DIR__ . '/class-wc-api.php';

            update_option( 'woocommerce_api_enabled', 'yes' );
            WC_API::add_endpoint();
        }
    }

    /**
     * Act on plugin deactivation/uninstall.
     */
    public static function on_plugin_deactivated() {
        if( ! self::legacy_api_still_in_woocommerce() ) {
            update_option( 'woocommerce_api_enabled', 'no' );
            flush_rewrite_rules();
        }
    }

    /**
     * Handler for the before_woocommerce_init hook, needed to declare HPOS incompatibility.
     */
    public static function on_before_woocommerce_init() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', self::$plugin_filename, false );
        }
    }

     /**
     * Handler for the before_woocommerce_init hook, needed to initialize the plugin.
     */
    public static function on_woocommerce_init() {
        if( ! self::legacy_api_still_in_woocommerce() ) {
            self::init();
        }
    }

    /**
     * Checks if the legacy REST API is still present in the current WooCommerce install.
     */
    private static function legacy_api_still_in_woocommerce() {
        return class_exists( 'WC_API' ) && ! property_exists( 'WC_API', 'legacy_api_is_in_separate_plugin' );
    }

    /**
     * Handler for the all_plugins hook, used to change the description of the plugin if it's seen before June 2024.
     */
    public static function on_all_plugins( $all_plugins ) {
        $plugin_relative_path = str_replace( WP_PLUGIN_DIR . '/', '', self::$plugin_filename );
        $all_plugins[ $plugin_relative_path ][ 'Description' ] = 'The legacy WooCommerce REST API, which is now part of WooCommerce itself but will be removed in WooCommerce 9.0.';
        return $all_plugins;
    }
}