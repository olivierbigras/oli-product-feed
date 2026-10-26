<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Plugin bootstrap.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component.
 */
final class Plugin {

	/**
	 * Boots the plugin (plugins_loaded).
	 */
	public static function boot() {
		PrefixMigrate::maybe_run();
		add_action( 'init', array( Feeds::class, 'register_post_type' ) );
		add_action( 'wp_trash_post', array( Feeds::class, 'on_trash' ) );
		add_action( 'transition_post_status', array( Feeds::class, 'on_status' ), 10, 3 );
		add_action( 'before_delete_post', array( Feeds::class, 'on_trash' ) );
		add_action( 'untrashed_post', array( Feeds::class, 'on_untrash' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_menu', array( __CLASS__, 'missing_woocommerce_menu' ) );
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}

		Integrations\PriceBasedOnCountry::init();
		Endpoint::init();
		Scheduler::init();

		if ( is_admin() ) {
			Admin\Admin::init();
			Admin\Product::init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'oli-feed', Cli::class );
		}
	}

	/**
	 * Fallback screen when WooCommerce is not active, so the notice stays on a plugin page.
	 */
	public static function missing_woocommerce_menu() {
		add_menu_page(
			__( 'Oli Product Feed', 'oli-product-feed' ),
			__( 'Oli Product Feed', 'oli-product-feed' ),
			'activate_plugins',
			'oli-product-feed',
			array( __CLASS__, 'missing_woocommerce_screen' ),
			'dashicons-rss'
		);
	}

	/**
	 * Empty wrap for the WooCommerce-missing fallback page.
	 */
	public static function missing_woocommerce_screen() {
		echo '<div class="wrap"><h1>' . esc_html__( 'Oli Product Feed', 'oli-product-feed' ) . '</h1></div>';
	}

	/**
	 * Admin notice when WooCommerce is missing (plugin screens only).
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'oli-product-feed' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Oli Product Feed requires WooCommerce to be installed and active.', 'oli-product-feed' ) . '</p></div>';
	}

	/**
	 * Activation: copy any pre-0.3.5 `oli_pf_` options (the output folder is created on the
	 * first generation, so that a custom output folder setting is respected).
	 */
	public static function activate() {
		PrefixMigrate::maybe_run();
	}

	/**
	 * Deactivation: unschedule every background action.
	 */
	public static function deactivate() {
		Scheduler::unschedule_all();
	}
}
