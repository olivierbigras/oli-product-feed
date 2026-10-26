<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * One-time copy of identifiers stored under the pre-0.3.5 `oli_pf_` prefix.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Copies old options, transients, post type and meta, then deletes the old keys.
 */
final class PrefixMigrate {

	const FLAG     = 'oliprfe_prefix_migrated';
	const OLD      = 'oli_pf_';
	const NEW      = 'oliprfe_';
	const OLD_META = '_oli_pf_';
	const NEW_META = '_oliprfe_';
	const OLD_CPT  = 'oli_pf_feed';
	const NEW_CPT  = 'oliprfe_feed';

	/**
	 * Runs once after an update (or on activation). Safe when there is nothing to copy.
	 */
	public static function maybe_run() {
		if ( get_option( self::FLAG ) ) {
			return;
		}
		self::run();
		update_option( self::FLAG, OLIPRFE_VERSION, false );
	}

	/**
	 * Maps an old stored name to the current prefix. Unchanged when the name is not old.
	 *
	 * @param string $name Option, transient, meta key or post type.
	 * @return string
	 */
	public static function rename( $name ) {
		$name = (string) $name;
		if ( self::OLD_CPT === $name ) {
			return self::NEW_CPT;
		}
		if ( 0 === strpos( $name, self::OLD_META ) ) {
			return self::NEW_META . substr( $name, strlen( self::OLD_META ) );
		}
		if ( 0 === strpos( $name, self::OLD ) ) {
			return self::NEW . substr( $name, strlen( self::OLD ) );
		}
		return $name;
	}

	/**
	 * Copies then deletes.
	 */
	private static function run() {
		self::options();
		self::transients();
		self::posts();
		self::meta();
		self::unschedule_old();
	}

	/**
	 * Options: settings, output-dir registry, endpoint index, product-change stamp, generation states.
	 */
	private static function options() {
		foreach ( array( 'oli_pf_settings', 'oli_pf_output_dirs', 'oli_pf_endpoint_index', 'oli_pf_products_changed' ) as $old ) {
			self::copy_option( $old, self::rename( $old ) );
		}
		foreach ( self::option_names_like( self::OLD . 'state_' ) as $old ) {
			self::copy_option( $old, self::rename( $old ) );
		}
	}

	/**
	 * Transients: generation locks, form errors, cached meta keys.
	 */
	private static function transients() {
		foreach ( self::option_names_like( '_transient_' . self::OLD ) as $stored ) {
			$old = substr( $stored, strlen( '_transient_' ) );
			$new = self::rename( $old );
			if ( $new === $old || false !== get_transient( $new ) ) {
				delete_transient( $old );
				continue;
			}
			$value = get_transient( $old );
			if ( false !== $value ) {
				$ttl = self::transient_ttl( $old );
				set_transient( $new, $value, $ttl > 0 ? $ttl : HOUR_IN_SECONDS );
			}
			delete_transient( $old );
		}
	}

	/**
	 * Feed posts stored as `oli_pf_feed`.
	 */
	private static function posts() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time prefix copy.
		$wpdb->update( $wpdb->posts, array( 'post_type' => self::NEW_CPT ), array( 'post_type' => self::OLD_CPT ) );
	}

	/**
	 * Feed configuration, log and last-run meta.
	 */
	private static function meta() {
		global $wpdb;
		foreach ( array( '_oli_pf_config', '_oli_pf_log', '_oli_pf_last_run' ) as $old ) {
			$new = self::rename( $old );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time prefix copy.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", $old ) );
			foreach ( $ids as $post_id ) {
				$post_id = (int) $post_id;
				if ( $post_id && '' === get_post_meta( $post_id, $new, true ) ) {
					update_post_meta( $post_id, $new, get_post_meta( $post_id, $old, true ) );
				}
				delete_post_meta( $post_id, $old );
			}
		}
	}

	/**
	 * Old scheduler hooks (0.3.4 and earlier). The current tick recreates itself.
	 */
	private static function unschedule_old() {
		foreach ( array( 'oli_pf_tick', 'oli_pf_run_batch' ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Copies one option when the new name is still empty, then deletes the old name.
	 *
	 * @param string $old Old option name.
	 * @param string $new New option name.
	 */
	private static function copy_option( $old, $new ) {
		if ( ! self::option_exists( $old ) ) {
			return;
		}
		if ( ! self::option_exists( $new ) ) {
			add_option( $new, get_option( $old ), '', false );
		}
		delete_option( $old );
	}

	/**
	 * Whether an option row exists (so a stored `false` or `0` is not treated as missing).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function option_exists( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time existence check.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	}

	/**
	 * Option names that start with a prefix.
	 *
	 * @param string $prefix Prefix (as stored in option_name).
	 * @return string[]
	 */
	private static function option_names_like( $prefix ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time prefix scan.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}

	/**
	 * Remaining TTL of a transient, in seconds (0 when unknown or expired).
	 *
	 * @param string $name Transient name without the `_transient_` prefix.
	 * @return int
	 */
	private static function transient_ttl( $name ) {
		$timeout = get_option( '_transient_timeout_' . $name );
		if ( ! is_numeric( $timeout ) ) {
			return 0;
		}
		$left = (int) $timeout - time();
		return $left > 0 ? $left : 0;
	}
}
