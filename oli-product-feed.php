<?php
/**
 * Plugin Name: Oli Product Feed
 * Plugin URI: https://github.com/olivierbigras/oli-product-feed
 * Description: Product feeds for WooCommerce: Google Merchant Center, Pinterest, Meta (Facebook, Instagram), TikTok, Microsoft (Bing) and generic CSV/XML. One feed per language and currency, TranslatePress support, background generation and WP-CLI.
 * Version: 0.5.8
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Olivier Bigras
 * Author URI: https://github.com/olivierbigras
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oli-product-feed
 * Domain Path: /languages
 * WC requires at least: 8.2
 * WC tested up to: 11.1
 *
 * @package Oliprfe
 */

defined( 'ABSPATH' ) || exit;

define( 'OLIPRFE_VERSION', '0.5.8' );
define( 'OLIPRFE_FILE', __FILE__ );
define( 'OLIPRFE_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLIPRFE_URL', plugin_dir_url( __FILE__ ) );
define( 'OLIPRFE_CAP', 'manage_woocommerce' );
define( 'OLIPRFE_DONATE_URL', 'https://www.paypal.com/donate/?hosted_button_id=29SAPKFAHMTNW' );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Oliprfe\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = OLIPRFE_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once OLIPRFE_DIR . 'src/functions.php';

// Feed endpoint: served right now (before the other plugins load) when the request asks for a feed.
if ( isset( $_GET['oliprfe_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only endpoint.
	Oliprfe\Endpoint::serve_early();
}

register_activation_hook( __FILE__, array( 'Oliprfe\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Oliprfe\\Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OLIPRFE_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'Oliprfe\\Plugin', 'boot' ), 20 );
