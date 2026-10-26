<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Global settings (option `oliprfe_settings`).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Global plugin settings.
 */
final class Settings {

	const OPTION = 'oliprfe_settings';

	/**
	 * Defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'output_dir'          => Storage::FOLDER,
			'delete_on_uninstall' => false,
			'serve_mode'          => 'file',
			'cache_max_age'       => 3600,
			'log_level'           => 'error',
			'shop_manager_access' => true,
			'regen_on_update'     => false,
		);
	}

	/**
	 * All settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Saves settings (sanitized, unknown keys dropped). A new output folder that cannot be used (symbolic link,
	 * real path outside the uploads directory, not writable, cannot be created) is refused: the previous
	 * folder is kept, nothing is moved or deleted, and the other settings are saved.
	 *
	 * @param array<string,mixed> $in Raw settings.
	 * @return true|\WP_Error Error (data: folder, reason) when the new output folder was refused.
	 */
	public static function save( array $in ) {
		$current                    = self::all();
		$out                        = array(
			'output_dir'          => isset( $in['output_dir'] ) ? Storage::sanitize_folder( (string) $in['output_dir'] ) : $current['output_dir'],
			'delete_on_uninstall' => isset( $in['delete_on_uninstall'] ) ? (bool) $in['delete_on_uninstall'] : (bool) $current['delete_on_uninstall'],
		);
		$mode                       = isset( $in['serve_mode'] ) ? sanitize_key( $in['serve_mode'] ) : $current['serve_mode'];
		$out['serve_mode']          = in_array( $mode, array( 'file', 'endpoint' ), true ) ? $mode : 'file';
		$out['cache_max_age']       = isset( $in['cache_max_age'] ) ? max( 0, min( DAY_IN_SECONDS, (int) $in['cache_max_age'] ) ) : (int) $current['cache_max_age'];
		$level                      = isset( $in['log_level'] ) ? sanitize_key( $in['log_level'] ) : $current['log_level'];
		$out['log_level']           = in_array( $level, array( 'off', 'error', 'info' ), true ) ? $level : 'error';
		$out['shop_manager_access'] = isset( $in['shop_manager_access'] ) ? (bool) $in['shop_manager_access'] : (bool) $current['shop_manager_access'];
		$out['regen_on_update']     = isset( $in['regen_on_update'] ) ? (bool) $in['regen_on_update'] : (bool) $current['regen_on_update'];
		if ( '' === $out['output_dir'] ) {
			$out['output_dir'] = Storage::FOLDER;
		}
		$wanted  = $out['output_dir'];
		$changed = $wanted !== $current['output_dir'];
		$reason  = $changed ? OutputDirs::check( Storage::resolve( $wanted ) ) : '';
		if ( '' !== $reason ) {
			$out['output_dir'] = $current['output_dir'];
		}
		update_option( self::OPTION, $out, false );
		if ( '' === $reason ) {
			// A new output folder: the feed files move there and the previous folders of the plugin are cleaned.
			Storage::sync_dirs();
			if ( $changed && '' !== Storage::last_error() ) {
				// The new folder could not be created when the files were moved: nothing was moved or deleted.
				$reason            = Storage::last_error();
				$out['output_dir'] = $current['output_dir'];
				update_option( self::OPTION, $out, false );
			}
		}
		Endpoint::refresh_index();
		if ( '' !== $reason ) {
			$folder = Storage::resolve( $wanted );
			self::log( 'error', sprintf( 'Output folder %1$s refused (%2$s): the feeds stay in %3$s.', $folder, $reason, Storage::folder() ) );
			return new \WP_Error(
				'oliprfe_output_dir',
				Storage::folder_message( $folder, $reason ),
				array(
					'folder' => $folder,
					'reason' => $reason,
				)
			);
		}
		return true;
	}

	/**
	 * Capability of the plugin screens: OLIPRFE_CAP (manage_woocommerce: administrators and shop managers),
	 * or manage_options when shop managers are not allowed.
	 *
	 * @return string
	 */
	public static function capability() {
		return self::get( 'shop_manager_access' ) ? OLIPRFE_CAP : 'manage_options';
	}

	/**
	 * Writes to the WooCommerce log (WooCommerce > Status > Logs, source oli-product-feed) by level.
	 *
	 * @param string              $level   error|info.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public static function log( $level, $message, array $context = array() ) {
		$setting = self::get( 'log_level' );
		if ( 'off' === $setting || ( 'error' === $setting && 'error' !== $level ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$context['source'] = 'oli-product-feed';
		wc_get_logger()->log( 'error' === $level ? 'error' : 'info', $message, $context );
	}
}
