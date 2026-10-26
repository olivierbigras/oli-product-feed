<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Feed file storage (uploads/oli-product-feed/).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Paths and URLs of the generated files.
 */
final class Storage {

	const FOLDER = 'oli-product-feed';

	/**
	 * Last reason why an output folder could not be used (see OutputDirs::check()), '' when none.
	 *
	 * @var string
	 */
	private static $error = '';

	/**
	 * Effective output folder, relative to the uploads directory: the requested folder (global setting
	 * `output_dir`, filter `oliprfe_output_dir`) when it can be used. When it cannot (symbolic link, real path
	 * outside the uploads directory, not writable, cannot be created), the plugin does not switch to it: the
	 * feeds stay in the recorded folder that holds their files (see OutputDirs::fallback()), nothing is moved
	 * or deleted, and the screens of the plugin show a notice (see problem()).
	 *
	 * @return string
	 */
	public static function folder() {
		$requested = self::requested_folder();
		if ( '' === OutputDirs::check( $requested ) ) {
			return $requested;
		}
		$fallback = OutputDirs::fallback( $requested );
		return null !== $fallback ? $fallback : $requested;
	}

	/**
	 * Requested output folder (setting and filter), usable or not.
	 *
	 * @return string
	 */
	public static function requested_folder() {
		return self::resolve( (string) Settings::get( 'output_dir' ) );
	}

	/**
	 * Output folder that a value of the setting gives once the filter is applied.
	 *
	 * @param string $value Value of the setting.
	 * @return string
	 */
	public static function resolve( $value ) {
		/**
		 * Filters the output folder of the feed files, relative to wp-content/uploads.
		 *
		 * @param string $folder Folder (default "oli-product-feed").
		 */
		$folder = (string) apply_filters( 'oliprfe_output_dir', $value );
		$folder = self::sanitize_folder( $folder );
		return '' !== $folder ? $folder : self::FOLDER;
	}

	/**
	 * The requested output folder cannot be used: folder, reason (see OutputDirs::check()) and the folder the
	 * feeds stay in ('' when none). Null when the requested folder can be used.
	 *
	 * @return array{folder:string,reason:string,fallback:string}|null
	 */
	public static function problem() {
		$requested = self::requested_folder();
		$reason    = OutputDirs::check( $requested );
		if ( '' === $reason ) {
			return null;
		}
		$fallback = OutputDirs::fallback( $requested );
		return array(
			'folder'   => $requested,
			'reason'   => $reason,
			'fallback' => null !== $fallback ? $fallback : '',
		);
	}

	/**
	 * Last reason why the output folder could not be used ('' when none).
	 *
	 * @return string
	 */
	public static function last_error() {
		return self::$error;
	}

	/**
	 * Message for an output folder that cannot be used.
	 *
	 * @param string $folder Folder (relative).
	 * @param string $reason Reason (see OutputDirs::check()).
	 * @return string
	 */
	public static function folder_message( $folder, $reason ) {
		$reasons = array(
			'symlink'       => __( 'it is a symbolic link, or one of its parent folders is', 'oli-product-feed' ),
			'outside'       => __( 'its real path is outside the uploads directory', 'oli-product-feed' ),
			'not_writable'  => __( 'it is not writable', 'oli-product-feed' ),
			'cannot_create' => __( 'it cannot be created (parent folder not writable, or a file of that name is in the way)', 'oli-product-feed' ),
			'uploads'       => __( 'the uploads directory is not available', 'oli-product-feed' ),
		);
		return sprintf(
			/* translators: 1: folder, e.g. "merchant-feeds", 2: reason, e.g. "it is not writable". */
			__( 'The output folder wp-content/uploads/%1$s cannot be used: %2$s.', 'oli-product-feed' ),
			$folder,
			isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reasons['cannot_create']
		);
	}

	/**
	 * Sanitizes a relative folder ("a/b"): lowercase segments, no dots, no traversal.
	 *
	 * @param string $folder Raw folder.
	 * @return string
	 */
	public static function sanitize_folder( $folder ) {
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $folder ) ) as $part ) {
			$part = sanitize_title( $part );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}
		return implode( '/', $parts );
	}

	/**
	 * Base directory and URL of the output folder (or of another folder of the plugin).
	 *
	 * @param string|null $folder Folder (relative); null: the output folder.
	 * @return array{dir:string,url:string}
	 */
	public static function base( $folder = null ) {
		$folder  = null === $folder ? self::folder() : (string) $folder;
		$uploads = wp_upload_dir( null, false );
		$url     = $uploads['baseurl'] . '/' . $folder;
		if ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$url = set_url_scheme( $url, 'https' );
		}
		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . $folder,
			'url' => $url,
		);
	}

	/**
	 * Creates the folder (with an empty index.php to prevent listing) and records what the plugin created
	 * (option `oliprfe_output_dirs`, see OutputDirs). Refused (false, see last_error()) when the folder is a
	 * symbolic link or inside one, when its real path is outside the uploads directory, or when it is not
	 * writable or cannot be created; the uploads directory itself may be a symbolic link.
	 *
	 * @return bool
	 */
	public static function ensure_dir() {
		self::$error = '';
		$base        = self::base();
		$folder      = self::folder();
		if ( ! OutputDirs::recorded() ) {
			OutputDirs::maybe_migrate( $folder, self::all_filenames() );
		}
		$reason = OutputDirs::check( $folder );
		if ( '' !== $reason ) {
			self::$error = $reason;
			return false;
		}
		$created = OutputDirs::missing( $folder );
		$made    = wp_mkdir_p( $base['dir'] );
		$reason  = $made ? OutputDirs::check( $folder ) : 'cannot_create';
		if ( '' === $reason && null === OutputDirs::safe_dir( $folder ) ) {
			$reason = 'cannot_create';
		}
		if ( '' !== $reason ) {
			OutputDirs::remove_empty( $created ); // Folders created just now, when nothing is in them.
			self::$error = $reason;
			return false;
		}
		$index = $base['dir'] . '/index.php';
		$wrote = false;
		if ( ! file_exists( $index ) && ! is_link( $index ) ) {
			$wrote = false !== file_put_contents( $index, OutputDirs::INDEX_CONTENT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		OutputDirs::register( $folder, $created, $wrote );
		return true;
	}

	/**
	 * Cleans the previous output folders after a change of the setting or of the `oliprfe_output_dir` filter:
	 * the files of the existing feeds are moved to the current folder, the other files of the plugin deleted,
	 * and a previous folder is removed when the plugin created it and it is empty (see OutputDirs).
	 * Nothing is moved or deleted, and the registry is kept, when the requested folder cannot be used or
	 * created (see last_error()). A feed file is not moved over a file of the same name that the plugin did
	 * not write: that file is left untouched and the feed stays in (and is served from) its previous folder.
	 *
	 * @return string[] Folders cleaned (relative).
	 */
	public static function sync_dirs() {
		self::$error = '';
		$folder      = self::folder();
		$reason      = OutputDirs::check( self::requested_folder() );
		if ( '' !== $reason ) {
			self::$error = $reason;
			return array();
		}
		if ( ! OutputDirs::recorded() ) {
			OutputDirs::maybe_migrate( $folder, self::all_filenames() );
		}
		$previous = false;
		$on_disk  = false;
		foreach ( array_keys( OutputDirs::all() ) as $rel ) {
			if ( $rel !== $folder && 0 !== strpos( $folder . '/', $rel . '/' ) ) {
				$previous = true;
				$on_disk  = $on_disk || null !== OutputDirs::safe_dir( $rel );
			}
		}
		if ( ! $previous ) {
			return array();
		}
		$keep = array();
		$busy = array();
		foreach ( Feeds::all_ids() as $feed_id ) {
			$keep[] = self::filename( $feed_id );
			$state  = get_option( Generator::STATE_PREFIX . $feed_id );
			if ( is_array( $state ) && ! empty( $state['tmp'] ) ) {
				$busy[] = (string) $state['tmp'];
			}
		}
		if ( $on_disk && ! self::ensure_dir() ) {
			return array(); // Target of the files that are moved: when it cannot be created, nothing is cleaned.
		}
		$conflicts = array();
		$done      = OutputDirs::sync( $folder, $keep, $busy, $conflicts );
		foreach ( $conflicts as $name => $rel ) {
			Settings::log( 'error', sprintf( 'Feed file %1$s not moved to %2$s: a file of that name that the plugin did not write is there (left untouched). The feed is still served from %3$s.', $name, $folder, $rel ) );
		}
		return $done;
	}

	/**
	 * Feed files that stay in a previous folder because a file of the same name that the plugin did not write
	 * is in the output folder.
	 *
	 * @return array<int,array{name:string,folder:string}> By feed ID.
	 */
	public static function conflicts() {
		$out    = array();
		$folder = self::folder();
		foreach ( Feeds::all_ids() as $feed_id ) {
			$served = self::served_folder( $feed_id );
			$output = self::output_path( $feed_id );
			if ( $served !== $folder && ( file_exists( $output ) || is_link( $output ) ) ) {
				$out[ $feed_id ] = array(
					'name'   => self::filename( $feed_id ),
					'folder' => $served,
				);
			}
		}
		return $out;
	}

	/**
	 * File names of every feed, trash included.
	 *
	 * @return string[]
	 */
	public static function all_filenames() {
		$ids = get_posts(
			array(
				'post_type'      => Feeds::POST_TYPE,
				'post_status'    => array_keys( get_post_stati() ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		return array_map( array( __CLASS__, 'filename' ), array_map( 'intval', $ids ) );
	}

	/**
	 * File name of a feed.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function filename( $feed_id ) {
		$config = Feeds::get( $feed_id );
		$slug   = $config && ! empty( $config['file_slug'] ) ? $config['file_slug'] : 'feed-' . (int) $feed_id;
		$ext    = $config ? Channels\Registry::extension( $config['format'] ) : 'xml';
		return sanitize_file_name( $slug ) . '.' . $ext;
	}

	/**
	 * Folder (relative) of the generated file of a feed: the output folder, or the previous folder that still
	 * holds it (see conflicts()).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function served_folder( $feed_id ) {
		return OutputDirs::owner( self::folder(), self::filename( $feed_id ) );
	}

	/**
	 * Absolute path of the generated file of a feed (the one that is served).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function path_for( $feed_id ) {
		$base = self::base( self::served_folder( $feed_id ) );
		return $base['dir'] . '/' . self::filename( $feed_id );
	}

	/**
	 * Absolute path of a feed file in the output folder (where a generation writes).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function output_path( $feed_id ) {
		$base = self::base();
		return $base['dir'] . '/' . self::filename( $feed_id );
	}

	/**
	 * Public URL of a feed file.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function url_for( $feed_id ) {
		if ( Endpoint::enabled() ) {
			$config = Feeds::get( $feed_id );
			return Endpoint::url( $config && ! empty( $config['file_slug'] ) ? $config['file_slug'] : 'feed-' . (int) $feed_id );
		}
		return self::file_url_for( $feed_id );
	}

	/**
	 * URL of the static file (even when the endpoint is on).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function file_url_for( $feed_id ) {
		$base = self::base( self::served_folder( $feed_id ) );
		return $base['url'] . '/' . self::filename( $feed_id );
	}
}
