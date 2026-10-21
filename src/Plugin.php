<?php
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
	 * Prefer a bundled catalog; fall back from fr_CA to fr_FR.
	 *
	 * WordPress does not fall back from a regional locale to the parent
	 * language. A site in fr_CA would otherwise stay in English when only
	 * oli-product-feed-fr_FR.mo is present. Uses load_textdomain_mofile
	 * (WordPress.org handbook) and does not call load_plugin_textdomain().
	 *
	 * @param string $mofile Path to the .mo file WordPress wants to load.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public static function load_textdomain_mofile( $mofile, $domain ) {
		if ( 'oli-product-feed' !== $domain ) {
			return $mofile;
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_user_locale();
		$locale = (string) apply_filters( 'plugin_locale', $locale, $domain );

		$bundled = OLIPRFE_DIR . 'languages/oli-product-feed-' . $locale . '.mo';
		if ( is_readable( $bundled ) ) {
			return $bundled;
		}

		if ( 'fr_CA' === $locale ) {
			$fallback = OLIPRFE_DIR . 'languages/oli-product-feed-fr_FR.mo';
			if ( is_readable( $fallback ) ) {
				return $fallback;
			}
		}

		return $mofile;
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public static function missing_woocommerce_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
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
