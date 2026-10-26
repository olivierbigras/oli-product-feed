<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Batch generation of a feed file: IDs → .tmp file → atomic rename.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Feed generator.
 */
final class Generator {

	const STATE_PREFIX = 'oliprfe_state_';
	const LOCK_PREFIX  = 'oliprfe_lock_';
	const LOCK_TTL     = 1800;

	/**
	 * Is a generation running for this feed?
	 *
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	public static function is_running( $feed_id ) {
		return (bool) get_transient( self::LOCK_PREFIX . (int) $feed_id );
	}

	/**
	 * Starts a generation (writes the header, stores the state).
	 *
	 * @param int  $feed_id Feed ID.
	 * @param bool $force   Ignore an existing lock.
	 * @return true|\WP_Error
	 */
	public static function start( $feed_id, $force = false ) {
		$feed_id = (int) $feed_id;
		$feed    = Feeds::get( $feed_id );
		if ( ! $feed || 'trash' === $feed['status'] ) {
			return new \WP_Error( 'oliprfe_no_feed', __( 'Feed not found.', 'oli-product-feed' ) );
		}
		if ( ! $force && self::is_running( $feed_id ) ) {
			return new \WP_Error( 'oliprfe_running', __( 'A generation is already running for this feed.', 'oli-product-feed' ) );
		}
		$channel = Channels\Registry::get( $feed['channel'] );
		if ( ! $channel ) {
			return new \WP_Error( 'oliprfe_no_channel', __( 'Unknown channel.', 'oli-product-feed' ) );
		}
		if ( ! Storage::ensure_dir() ) {
			return self::refuse( $feed_id, 'oliprfe_dir', Storage::folder_message( Storage::folder(), Storage::last_error() ) );
		}
		$folder = Storage::folder();
		$name   = Storage::filename( $feed_id );
		if ( ! OutputDirs::can_write( $folder, $name ) ) {
			// A file of that name that the plugin did not write: never overwritten.
			return self::refuse( $feed_id, 'oliprfe_foreign_file', self::foreign_message( $name ) );
		}
		OutputDirs::add_file( $folder, $name );
		set_transient( self::LOCK_PREFIX . $feed_id, time(), self::LOCK_TTL );

		Lang::switch_language( $feed['language'] );
		try {
			$feed = self::context( $feed );
			$path = Storage::output_path( $feed_id );
			$tmp  = $path . '.tmp';
			$ids  = Query::ids( $feed );

			if ( false === self::write( $tmp, $channel->header( $feed, $feed['format'] ), false ) ) {
				delete_transient( self::LOCK_PREFIX . $feed_id );
				return new \WP_Error( 'oliprfe_write', __( 'The feed file cannot be written.', 'oli-product-feed' ) );
			}
		} finally {
			Lang::restore_language();
		}
		$state = array(
			'ids'      => $ids,
			'offset'   => 0,
			'started'  => microtime( true ),
			'items'    => 0,
			'excluded' => array(),
			'warnings' => array(),
			'tmp'      => $tmp,
			'path'     => $path,
			'existed'  => file_exists( $path ),
		);
		update_option( self::STATE_PREFIX . $feed_id, $state, false );
		return true;
	}

	/**
	 * Processes the next batch.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string|\WP_Error 'more' when batches remain, 'done' when the file is published.
	 */
	public static function run_batch( $feed_id ) {
		$feed_id = (int) $feed_id;
		$state   = get_option( self::STATE_PREFIX . $feed_id );
		$feed    = Feeds::get( $feed_id );
		if ( ! is_array( $state ) || ! $feed ) {
			return new \WP_Error( 'oliprfe_no_state', __( 'No generation in progress.', 'oli-product-feed' ) );
		}
		set_transient( self::LOCK_PREFIX . $feed_id, time(), self::LOCK_TTL );
		Lang::switch_language( $feed['language'] );
		try {
			$feed    = self::context( $feed );
			$channel = Channels\Registry::get( $feed['channel'] );
			$builder = new ItemBuilder( $feed, $channel );
			$slice   = array_slice( $state['ids'], $state['offset'], $feed['batch_size'] );
			$buffer  = '';

			foreach ( $slice as $id ) {
				$result = $builder->build( $id );
				foreach ( $result['warnings'] as $warning ) {
					$code = strtok( $warning, ':' );
					if ( ! isset( $state['warnings'][ $code ] ) ) {
						$state['warnings'][ $code ] = array();
					}
					if ( count( $state['warnings'][ $code ] ) < 50 ) {
						$state['warnings'][ $code ][] = $id;
					}
				}
				if ( null === $result['row'] ) {
					$reason = $result['reason'];
					if ( ! isset( $state['excluded'][ $reason ] ) ) {
						$state['excluded'][ $reason ] = array();
					}
					$state['excluded'][ $reason ][] = $id;
					continue;
				}
				$buffer .= $channel->item( $result['row'], $feed, $feed['format'] );
				++$state['items'];
			}

			if ( '' !== $buffer && false === self::write( $state['tmp'], $buffer, true ) ) {
				self::fail( $feed_id, $state, __( 'The feed file cannot be written.', 'oli-product-feed' ) );
				return new \WP_Error( 'oliprfe_write', __( 'The feed file cannot be written.', 'oli-product-feed' ) );
			}
			$state['offset'] += count( $slice );

			if ( defined( 'WP_CLI' ) && WP_CLI && function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}

			if ( $state['offset'] < count( $state['ids'] ) ) {
				update_option( self::STATE_PREFIX . $feed_id, $state, false );
				return 'more';
			}
			return self::finish( $feed_id, $feed, $channel, $state );
		} finally {
			Lang::restore_language();
		}
	}

	/**
	 * Runs a whole generation in the current process (WP-CLI).
	 *
	 * @param int  $feed_id Feed ID.
	 * @param bool $force   Ignore an existing lock.
	 * @return array<string,mixed>|\WP_Error Log entry.
	 */
	public static function run_now( $feed_id, $force = false ) {
		$started = self::start( $feed_id, $force );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		do {
			$status = self::run_batch( $feed_id );
		} while ( 'more' === $status );
		return is_wp_error( $status ) ? $status : Feeds::last_run( $feed_id );
	}

	/**
	 * Feed configuration plus the values used in the file header.
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public static function context( array $feed ) {
		$home                     = Lang::default_home_url();
		$feed['site_title']       = html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$feed['site_link']        = Lang::feed_url( $home, isset( $feed['language'] ) ? $feed['language'] : '', ! empty( $feed['force_https'] ) );
		$feed['site_description'] = $feed['name'];
		return $feed;
	}

	/**
	 * Sample row of a feed for the admin preview: the first of the first products that goes in the feed.
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @param int                 $max  Products to try.
	 * @return array{id:int,row:array<string,mixed>}|null
	 */
	public static function preview( array $feed, $max = 20 ) {
		$channel = Channels\Registry::get( $feed['channel'] );
		if ( ! $channel ) {
			return null;
		}
		Lang::switch_language( isset( $feed['language'] ) ? $feed['language'] : '' );
		try {
			$feed    = self::context( $feed );
			$builder = new ItemBuilder( $feed, $channel );
			foreach ( array_slice( Query::ids( $feed ), 0, $max ) as $id ) {
				$result = $builder->build( $id );
				if ( null !== $result['row'] ) {
					return array(
						'id'  => (int) $id,
						'row' => $result['row'],
					);
				}
			}
			return null;
		} finally {
			Lang::restore_language();
		}
	}

	/**
	 * Footer, atomic rename, log.
	 *
	 * @param int                     $feed_id Feed ID.
	 * @param array<string,mixed>     $feed    Feed configuration.
	 * @param Channels\Channel        $channel Channel.
	 * @param array<string,mixed>     $state   State.
	 * @return string|\WP_Error
	 */
	private static function finish( $feed_id, array $feed, $channel, array $state ) {
		if ( false === self::write( $state['tmp'], $channel->footer( $feed, $feed['format'] ), true ) ) {
			self::fail( $feed_id, $state, __( 'The feed file cannot be written.', 'oli-product-feed' ) );
			return new \WP_Error( 'oliprfe_write', __( 'The feed file cannot be written.', 'oli-product-feed' ) );
		}
		if ( is_link( $state['path'] ) || ( isset( $state['existed'] ) && ! $state['existed'] && file_exists( $state['path'] ) ) ) {
			// A file appeared during the generation (not written by the plugin): never overwritten.
			self::fail( $feed_id, $state, self::foreign_message( basename( $state['path'] ) ) );
			return new \WP_Error( 'oliprfe_foreign_file', self::foreign_message( basename( $state['path'] ) ) );
		}
		if ( ! @rename( $state['tmp'], $state['path'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			self::fail( $feed_id, $state, __( 'The feed file cannot be published.', 'oli-product-feed' ) );
			return new \WP_Error( 'oliprfe_rename', __( 'The feed file cannot be published.', 'oli-product-feed' ) );
		}
		$excluded = array();
		foreach ( $state['excluded'] as $reason => $ids ) {
			$excluded[ $reason ] = array(
				'count' => count( $ids ),
				'ids'   => array_slice( $ids, 0, 100 ),
			);
		}
		$entry = array(
			'status'     => 'success',
			'time'       => time(),
			'duration'   => round( microtime( true ) - $state['started'], 1 ),
			'candidates' => count( $state['ids'] ),
			'items'      => $state['items'],
			'excluded'   => $excluded,
			'warnings'   => $state['warnings'],
			'bytes'      => (int) filesize( $state['path'] ),
			'url'        => Storage::url_for( $feed_id ),
		);
		Feeds::add_log( $feed_id, $entry );
		Settings::log(
			'info',
			sprintf( 'Feed %1$d (%2$s) generated: %3$d items, %4$d excluded, %5$ss.', $feed_id, $feed['file_slug'], $entry['items'], $entry['candidates'] - $entry['items'], $entry['duration'] ),
			array( 'feed_id' => $feed_id )
		);
		delete_option( self::STATE_PREFIX . $feed_id );
		delete_transient( self::LOCK_PREFIX . $feed_id );

		Storage::sync_dirs(); // Previous output folders, if the setting or the filter changed.
		Endpoint::refresh_index();

		/**
		 * Fires after a feed file was published.
		 *
		 * @param int   $feed_id Feed ID.
		 * @param array $entry   Log entry.
		 */
		do_action( 'oliprfe_feed_generated', $feed_id, $entry );
		return 'done';
	}

	/**
	 * Message for a file of the feed's name that the plugin did not write.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function foreign_message( $name ) {
		return sprintf(
			/* translators: %s: file name, e.g. "google-en.xml". */
			__( 'The output folder has a file named %s that the plugin did not write: it is left untouched and the feed was not generated. Remove or rename that file, or change the file name of the feed.', 'oli-product-feed' ),
			$name
		);
	}

	/**
	 * A generation that cannot start (output folder, file of another origin): logged on the feed and in the
	 * WooCommerce log, so that the feed list shows it.
	 *
	 * @param int    $feed_id Feed ID.
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function refuse( $feed_id, $code, $message ) {
		Feeds::add_log(
			$feed_id,
			array(
				'status'  => 'error',
				'time'    => time(),
				'message' => $message,
			)
		);
		Settings::log( 'error', sprintf( 'Feed %1$d: %2$s', $feed_id, $message ), array( 'feed_id' => $feed_id ) );
		return new \WP_Error( $code, $message );
	}

	/**
	 * Records a failure and cleans up.
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $state   State.
	 * @param string              $message Message.
	 */
	private static function fail( $feed_id, array $state, $message ) {
		if ( ! empty( $state['tmp'] ) && file_exists( $state['tmp'] ) ) {
			wp_delete_file( $state['tmp'] );
		}
		Feeds::add_log(
			$feed_id,
			array(
				'status'  => 'error',
				'time'    => time(),
				'message' => $message,
			)
		);
		Settings::log( 'error', sprintf( 'Feed %1$d: %2$s', $feed_id, $message ), array( 'feed_id' => $feed_id ) );
		delete_option( self::STATE_PREFIX . $feed_id );
		delete_transient( self::LOCK_PREFIX . $feed_id );
	}

	/**
	 * Cancels a running generation.
	 *
	 * @param int $feed_id Feed ID.
	 */
	public static function cancel( $feed_id ) {
		$state = get_option( self::STATE_PREFIX . (int) $feed_id );
		if ( is_array( $state ) && ! empty( $state['tmp'] ) && file_exists( $state['tmp'] ) ) {
			wp_delete_file( $state['tmp'] );
		}
		delete_option( self::STATE_PREFIX . (int) $feed_id );
		delete_transient( self::LOCK_PREFIX . (int) $feed_id );
	}

	/**
	 * Writes to a file.
	 *
	 * @param string $path   Path.
	 * @param string $data   Data.
	 * @param bool   $append Append.
	 * @return int|false
	 */
	private static function write( $path, $data, $append ) {
		// Direct file access: the feed is a static file streamed in batches; WP_Filesystem has no append mode.
		return file_put_contents( $path, $data, $append ? FILE_APPEND | LOCK_EX : LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
