<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Output folders written by the plugin (option `oliprfe_output_dirs`), so that a previous output folder
 * (setting or `oliprfe_output_dir` filter changed) and every folder at uninstall can be cleaned up without
 * touching anything the plugin did not write.
 *
 * Each entry, keyed by the folder relative to the uploads directory, records whether the plugin created the
 * folder, whether it wrote the index.php that prevents listing, and the feed files it wrote there. Only those
 * files (and their .tmp) are deleted; the index.php is deleted only when nothing else is left; the folder is
 * removed only when the plugin created it and it is empty. Safeguards: the folder must be inside the uploads
 * directory (real path), never the uploads directory itself or one of its parents, and no symbolic link
 * (folder, parent folder or file) is followed or deleted. A folder that cannot be used (see check()) is
 * never cleaned towards, and a feed file is never moved over, or replaced by, a file the plugin did not
 * write.
 *
 * No dependency on WooCommerce or on the other classes of the plugin (used by uninstall.php).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Registry and cleanup of the output folders.
 */
final class OutputDirs {

	const OPTION = 'oliprfe_output_dirs';

	/**
	 * Default output folder (same as Storage::FOLDER).
	 */
	const DEFAULT_FOLDER = 'oli-product-feed';

	/**
	 * Content of the index.php written by the plugin.
	 */
	const INDEX_CONTENT = "<?php\n// Silence is golden.\n";

	/**
	 * Folders recorded so far.
	 *
	 * @return array<string,array{created:bool,index:bool,files:string[]}>
	 */
	public static function all() {
		$dirs = get_option( self::OPTION, array() );
		$out  = array();
		if ( ! is_array( $dirs ) ) {
			return $out;
		}
		foreach ( $dirs as $rel => $entry ) {
			$rel = self::clean_rel( (string) $rel );
			if ( '' === $rel || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $rel ] = array(
				'created' => ! empty( $entry['created'] ),
				'index'   => ! empty( $entry['index'] ),
				'files'   => isset( $entry['files'] ) && is_array( $entry['files'] ) ? array_values( array_filter( array_map( array( __CLASS__, 'clean_name' ), $entry['files'] ) ) ) : array(),
			);
		}
		return $out;
	}

	/**
	 * Saves the registry (an empty registry is kept: it means "nothing to clean", not "never recorded").
	 *
	 * @param array<string,array<string,mixed>> $dirs Registry.
	 */
	private static function save( array $dirs ) {
		if ( get_option( self::OPTION, null ) !== $dirs ) {
			update_option( self::OPTION, $dirs, false );
		}
	}

	/**
	 * Has the registry been created (by this version or a later one)?
	 *
	 * @return bool
	 */
	public static function recorded() {
		return false !== get_option( self::OPTION, false );
	}

	/**
	 * First use (update from 0.3.2 or older, nothing recorded yet): records the current output folder with the
	 * files of the feeds that exist in it. Its index.php counts as the plugin's when its content is the one the
	 * plugin writes, and the folder as created by the plugin when it is the default folder (rules of 0.3.2).
	 *
	 * @param string   $folder Current output folder (relative).
	 * @param string[] $names  File names of every feed (any status, trash included).
	 */
	public static function maybe_migrate( $folder, array $names ) {
		if ( self::recorded() ) {
			return;
		}
		$dirs   = array();
		$folder = self::clean_rel( $folder );
		$abs    = '' !== $folder ? self::safe_dir( $folder ) : null;
		if ( $abs ) {
			$files = array();
			foreach ( $names as $name ) {
				$name = self::clean_name( $name );
				if ( '' !== $name && ( self::is_plain_file( $abs . '/' . $name ) || self::is_plain_file( $abs . '/' . $name . '.tmp' ) ) ) {
					$files[] = $name;
				}
			}
			$index           = $abs . '/index.php';
			$dirs[ $folder ] = array(
				'created' => self::DEFAULT_FOLDER === $folder,
				'index'   => self::is_plain_file( $index ) && self::INDEX_CONTENT === file_get_contents( $index ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
				'files'   => array_values( array_unique( $files ) ),
			);
		}
		update_option( self::OPTION, $dirs, false );
	}

	/**
	 * Folders (relative, top-down) of $folder that do not exist yet: the ones the plugin is about to create.
	 *
	 * @param string $folder Folder (relative).
	 * @return string[]
	 */
	public static function missing( $folder ) {
		$base = self::base_real();
		$out  = array();
		if ( ! $base ) {
			return $out;
		}
		$path = '';
		foreach ( explode( '/', self::clean_rel( $folder ) ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$path = '' === $path ? $part : $path . '/' . $part;
			if ( ! file_exists( $base . '/' . $path ) && ! is_link( $base . '/' . $path ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Records a folder of the plugin after Storage::ensure_dir().
	 *
	 * @param string   $folder       Folder (relative).
	 * @param string[] $created      Folders created just now (see missing()).
	 * @param bool     $wrote_index  The plugin wrote the index.php just now.
	 */
	public static function register( $folder, array $created, $wrote_index ) {
		$folder = self::clean_rel( $folder );
		if ( '' === $folder ) {
			return;
		}
		$dirs = self::all();
		foreach ( $created as $rel ) {
			$rel = self::clean_rel( $rel );
			if ( '' === $rel ) {
				continue;
			}
			if ( ! isset( $dirs[ $rel ] ) ) {
				$dirs[ $rel ] = array(
					'created' => true,
					'index'   => false,
					'files'   => array(),
				);
			}
			$dirs[ $rel ]['created'] = true;
		}
		if ( ! isset( $dirs[ $folder ] ) ) {
			$dirs[ $folder ] = array(
				'created' => false,
				'index'   => false,
				'files'   => array(),
			);
		}
		if ( $wrote_index ) {
			$dirs[ $folder ]['index'] = true;
		}
		self::save( $dirs );
	}

	/**
	 * Records a feed file written by the plugin (the .tmp goes with it).
	 *
	 * @param string $folder Folder (relative).
	 * @param string $name   File name.
	 */
	public static function add_file( $folder, $name ) {
		$folder = self::clean_rel( $folder );
		$name   = self::clean_name( $name );
		if ( '' === $folder || '' === $name ) {
			return;
		}
		$dirs = self::all();
		if ( ! isset( $dirs[ $folder ] ) ) {
			$dirs[ $folder ] = array(
				'created' => false,
				'index'   => false,
				'files'   => array(),
			);
		}
		if ( ! in_array( $name, $dirs[ $folder ]['files'], true ) ) {
			$dirs[ $folder ]['files'][] = $name;
		}
		self::save( $dirs );
	}

	/**
	 * Forgets a feed file deleted by the plugin.
	 *
	 * @param string $folder Folder (relative).
	 * @param string $name   File name.
	 */
	public static function forget_file( $folder, $name ) {
		$folder = self::clean_rel( $folder );
		$name   = self::clean_name( $name );
		$dirs   = self::all();
		if ( '' === $folder || '' === $name || ! isset( $dirs[ $folder ] ) || ! in_array( $name, $dirs[ $folder ]['files'], true ) ) {
			return;
		}
		$dirs[ $folder ]['files'] = array_values( array_diff( $dirs[ $folder ]['files'], array( $name ) ) );
		self::save( $dirs );
	}

	/**
	 * Cleans the previous output folders: every recorded folder other than the current one and its parents.
	 * The files of the existing feeds are first moved to the current folder, so that the feeds stay online;
	 * the other files of the plugin are deleted. Nothing is done when the current folder is not usable (see
	 * safe_dir()). A feed file is never moved over a file the plugin did not write: it stays in its previous
	 * folder with that folder's entry (reported in $conflicts, moved by a later call once the way is free),
	 * and that file is not touched. A folder that holds the temporary file of a running generation is left
	 * for later (cleaned when that generation ends).
	 *
	 * @param string                $current   Current output folder (relative).
	 * @param string[]              $keep      File names of the existing feeds (moved rather than deleted).
	 * @param string[]              $busy      Absolute paths of the temporary files of the running generations.
	 * @param array<string,string>  $conflicts Filled with the feed files not moved: name => previous folder.
	 * @return string[] Folders cleaned (relative).
	 */
	public static function sync( $current, array $keep = array(), array $busy = array(), array &$conflicts = array() ) {
		$current = self::clean_rel( $current );
		$dirs    = self::all();
		$done    = array();
		$changed = false;
		$target  = '' !== $current ? self::safe_dir( $current ) : null;
		if ( ! $target ) {
			return $done; // The new folder cannot be used: nothing is moved or deleted, the registry is kept.
		}
		if ( ! isset( $dirs[ $current ] ) ) {
			$dirs[ $current ] = array(
				'created' => false,
				'index'   => false,
				'files'   => array(),
			);
		}
		foreach ( self::deepest_first( array_keys( $dirs ) ) as $rel ) {
			if ( $rel === $current || 0 === strpos( $current . '/', $rel . '/' ) ) {
				continue; // The current folder or one of its parents.
			}
			$abs = self::safe_dir( $rel );
			if ( $abs ) {
				$in_use = false;
				foreach ( $busy as $tmp ) {
					if ( realpath( dirname( (string) $tmp ) ) === $abs ) {
						$in_use = true;
					}
				}
				if ( $in_use ) {
					continue;
				}
				$stay = array();
				foreach ( $dirs[ $rel ]['files'] as $name ) {
					$from = $abs . '/' . $name;
					if ( ! in_array( $name, $keep, true ) || ! self::is_plain_file( $from ) ) {
						continue; // Deleted below.
					}
					$to = $target . '/' . $name;
					if ( ! file_exists( $to ) && ! is_link( $to ) ) {
						if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Moves a feed file of the plugin to the new output folder.
							$dirs[ $current ]['files'][] = $name;
						} else {
							$stay[] = $name; // Not moved: the only copy is kept.
						}
					} elseif ( ! in_array( $name, $dirs[ $current ]['files'], true ) || ! self::is_plain_file( $to ) ) {
						$stay[]             = $name; // A file the plugin did not write is there: never overwritten.
						$conflicts[ $name ] = $rel;
					}
					// Else the current folder already has the plugin's newer copy: this one is deleted below.
				}
				if ( $stay ) {
					$entry          = $dirs[ $rel ];
					$entry['files'] = array_values( array_diff( $entry['files'], $stay ) );
					self::clean( $abs, $entry ); // The folder still holds the files that stay: it is kept.
					$dirs[ $rel ]['files'] = array_values( $stay );
					$changed               = true;
					continue;
				}
				self::clean( $abs, $dirs[ $rel ] );
			}
			unset( $dirs[ $rel ] );
			$done[] = $rel;
		}
		$dirs[ $current ]['files'] = array_values( array_unique( $dirs[ $current ]['files'] ) );
		if ( $done || $changed ) {
			self::save( $dirs );
		}
		return $done;
	}

	/**
	 * Recorded folder that holds the plugin's copy of a feed file: the current folder when the file there was
	 * written by the plugin (or when no copy is recorded anywhere), else a previous folder that still holds it
	 * (see sync()).
	 *
	 * @param string $current Current output folder (relative).
	 * @param string $name    File name.
	 * @return string
	 */
	public static function owner( $current, $name ) {
		$dirs = self::all();
		if ( isset( $dirs[ $current ] ) && in_array( $name, $dirs[ $current ]['files'], true ) ) {
			return $current;
		}
		foreach ( $dirs as $rel => $entry ) {
			if ( $rel !== $current && in_array( $name, $entry['files'], true ) ) {
				$abs = self::safe_dir( $rel );
				if ( $abs && self::is_plain_file( $abs . '/' . $name ) ) {
					return $rel;
				}
			}
		}
		return $current;
	}

	/**
	 * Was this file written by the plugin in this folder (recorded)?
	 *
	 * @param string $folder Folder (relative).
	 * @param string $name   File name.
	 * @return bool
	 */
	public static function owns( $folder, $name ) {
		$dirs   = self::all();
		$folder = self::clean_rel( $folder );
		return isset( $dirs[ $folder ] ) && in_array( self::clean_name( $name ), $dirs[ $folder ]['files'], true );
	}

	/**
	 * Can a generation write this feed file (and its .tmp) in this folder? Not when a file or a symbolic link
	 * of that name is there that the plugin did not write.
	 *
	 * @param string $folder Folder (relative).
	 * @param string $name   File name.
	 * @return bool
	 */
	public static function can_write( $folder, $name ) {
		$abs = self::safe_dir( $folder );
		if ( ! $abs ) {
			return false;
		}
		$own = self::owns( $folder, $name );
		foreach ( array( $name, $name . '.tmp' ) as $file ) {
			if ( is_link( $abs . '/' . $file ) || ( ! $own && file_exists( $abs . '/' . $file ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Recorded folder (other than $requested) that holds the most feed files of the plugin: where the feeds stay
	 * when the requested folder cannot be used. Null when none.
	 *
	 * @param string $requested Requested output folder (relative).
	 * @return string|null
	 */
	public static function fallback( $requested ) {
		$best  = null;
		$count = 0;
		foreach ( self::all() as $rel => $entry ) {
			$abs = $rel !== $requested && $entry['files'] ? self::safe_dir( $rel ) : null;
			if ( ! $abs ) {
				continue;
			}
			$found = 0;
			foreach ( $entry['files'] as $name ) {
				if ( self::is_plain_file( $abs . '/' . $name ) ) {
					++$found;
				}
			}
			if ( $found > $count ) {
				$best  = $rel;
				$count = $found;
			}
		}
		return $best;
	}

	/**
	 * Can this folder be used as the output folder? '' when it can (existing, or to be created in a writable
	 * folder), else the reason: "symlink" (the folder or one of its parents below the uploads directory is a
	 * symbolic link), "outside" (real path not strictly inside the real path of the uploads directory),
	 * "not_writable", "cannot_create" (parent not writable, or a file of that name is in the way) or "uploads"
	 * (no uploads directory). The uploads directory itself may be a symbolic link: paths are compared to its
	 * real path.
	 *
	 * @param string $rel Folder (relative).
	 * @return string
	 */
	public static function check( $rel ) {
		$rel  = self::clean_rel( $rel );
		$base = self::base_real();
		if ( ! $base ) {
			return 'uploads';
		}
		if ( '' === $rel ) {
			return 'outside';
		}
		$path   = $base;
		$parent = $base;
		foreach ( explode( '/', $rel ) as $part ) {
			$path .= '/' . $part;
			if ( is_link( $path ) ) {
				return 'symlink';
			}
			if ( ! file_exists( $path ) ) {
				return wp_is_writable( $parent ) ? '' : 'cannot_create';
			}
			if ( ! is_dir( $path ) ) {
				return 'cannot_create';
			}
			$parent = $path;
		}
		$real = realpath( $path );
		if ( false === $real || untrailingslashit( str_replace( '\\', '/', $real ) ) !== $path || 0 !== strpos( $path, $base . '/' ) ) {
			return 'outside';
		}
		return wp_is_writable( $path ) ? '' : 'not_writable';
	}

	/**
	 * Removes folders created just now when nothing is in them (failed creation of a nested folder).
	 *
	 * @param string[] $created Folders (relative) created by the plugin.
	 */
	public static function remove_empty( array $created ) {
		foreach ( self::deepest_first( $created ) as $rel ) {
			$abs = self::safe_dir( $rel );
			if ( $abs && array() === self::entries( $abs ) ) {
				@rmdir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder created by the plugin just now.
			}
		}
	}

	/**
	 * Uninstall: cleans every recorded folder (deepest first) and forgets them.
	 *
	 * @return string[] Folders cleaned (relative).
	 */
	public static function clean_all() {
		$dirs = self::all();
		$done = array();
		foreach ( self::deepest_first( array_keys( $dirs ) ) as $rel ) {
			$abs = self::safe_dir( $rel );
			if ( $abs ) {
				self::clean( $abs, $dirs[ $rel ] );
			}
			$done[] = $rel;
		}
		delete_option( self::OPTION );
		return $done;
	}

	/**
	 * Deletes the files of the plugin in a folder, then its index.php when nothing else is left, then the
	 * folder when the plugin created it and it is empty.
	 *
	 * @param string                                  $abs   Folder (absolute, checked by safe_dir()).
	 * @param array{created:bool,index:bool,files:string[]} $entry Registry entry.
	 */
	private static function clean( $abs, array $entry ) {
		foreach ( $entry['files'] as $name ) {
			foreach ( array( $name, $name . '.tmp' ) as $file ) {
				if ( self::is_plain_file( $abs . '/' . $file ) ) {
					wp_delete_file( $abs . '/' . $file );
				}
			}
		}
		$left = self::entries( $abs );
		if ( $entry['index'] && array( 'index.php' ) === $left && self::is_plain_file( $abs . '/index.php' ) && self::INDEX_CONTENT === file_get_contents( $abs . '/index.php' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			wp_delete_file( $abs . '/index.php' );
			$left = self::entries( $abs );
		}
		if ( $entry['created'] && array() === $left ) {
			@rmdir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty folder created by the plugin.
		}
	}

	/**
	 * Absolute path of a recorded folder when it is safe to clean, else null: an existing folder strictly inside
	 * the uploads directory (real path), with no symbolic link on the way.
	 *
	 * @param string $rel Folder (relative).
	 * @return string|null
	 */
	public static function safe_dir( $rel ) {
		$rel  = self::clean_rel( $rel );
		$base = self::base_real();
		if ( '' === $rel || ! $base ) {
			return null;
		}
		$path = $base;
		foreach ( explode( '/', $rel ) as $part ) {
			$path .= '/' . $part;
			if ( is_link( $path ) ) {
				return null;
			}
		}
		if ( ! is_dir( $path ) || realpath( $path ) !== $path || 0 !== strpos( $path, $base . '/' ) ) {
			return null;
		}
		return $path;
	}

	/**
	 * Real path of the uploads directory (no trailing slash), or null.
	 *
	 * @return string|null
	 */
	private static function base_real() {
		$uploads = wp_upload_dir( null, false );
		$base    = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		return $base && '/' !== $base ? untrailingslashit( str_replace( '\\', '/', $base ) ) : null;
	}

	/**
	 * Relative folder reduced to safe segments ("a/b"): lowercase letters, digits, "-" and "_", no dots.
	 *
	 * @param string $rel Raw folder.
	 * @return string
	 */
	private static function clean_rel( $rel ) {
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', (string) $rel ) ) as $part ) {
			if ( '' !== $part && preg_match( '/^[a-z0-9_-]+$/', $part ) ) {
				$parts[] = $part;
			} elseif ( '' !== $part ) {
				return '';
			}
		}
		return implode( '/', $parts );
	}

	/**
	 * Feed file name ("slug.ext"), or '' when it is not one.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private static function clean_name( $name ) {
		$name = (string) $name;
		return preg_match( '/^[a-z0-9_][a-z0-9_-]*\.(xml|csv|tsv|txt)$/', $name ) ? $name : '';
	}

	/**
	 * Regular file that is not a symbolic link.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_plain_file( $path ) {
		return ! is_link( $path ) && is_file( $path );
	}

	/**
	 * Names in a folder (without . and ..).
	 *
	 * @param string $abs Folder.
	 * @return string[]|null Null when unreadable.
	 */
	private static function entries( $abs ) {
		$list = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable folder: nothing removed.
		return is_array( $list ) ? array_values( array_diff( $list, array( '.', '..' ) ) ) : null;
	}

	/**
	 * Folders sorted deepest first.
	 *
	 * @param string[] $rels Folders (relative).
	 * @return string[]
	 */
	private static function deepest_first( array $rels ) {
		usort(
			$rels,
			static function ( $a, $b ) {
				return substr_count( $b, '/' ) - substr_count( $a, '/' );
			}
		);
		return $rels;
	}
}
