<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * WP-CLI commands: wp oli-feed list|generate|export|import|status.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the product feeds.
 */
final class Cli {

	/**
	 * Lists the feeds.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json, csv or ids.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function list( $args, $assoc_args ) {
		$rows = array();
		foreach ( Feeds::all_ids() as $id ) {
			$feed   = Feeds::get( $id );
			$last   = Feeds::last_run( $id );
			$rows[] = array(
				'id'        => $id,
				'name'      => $feed['name'],
				'status'    => $feed['status'],
				'channel'   => $feed['channel'],
				'format'    => $feed['format'],
				'language'  => $feed['language'],
				'country'   => $feed['country'],
				'currency'  => $feed['currency'],
				'frequency' => $feed['frequency'],
				'items'     => isset( $last['items'] ) ? $last['items'] : '',
				'last_run'  => isset( $last['time'] ) ? wp_date( 'Y-m-d H:i', $last['time'] ) : '',
				'url'       => Storage::url_for( $id ),
			);
		}
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', wp_list_pluck( $rows, 'id' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, array_keys( $rows ? $rows[0] : array( 'id' => 1 ) ) );
	}

	/**
	 * Generates feeds now, in this process.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Feed IDs.
	 *
	 * [--all]
	 * : Every published feed.
	 *
	 * [--force]
	 * : Ignore a running generation lock.
	 *
	 * @param array $args       Feed IDs.
	 * @param array $assoc_args Options.
	 */
	public function generate( $args, $assoc_args ) {
		$ids = array_map( 'absint', $args );
		if ( ! empty( $assoc_args['all'] ) ) {
			$ids = array_filter(
				Feeds::all_ids(),
				static function ( $id ) {
					return 'publish' === get_post_status( $id );
				}
			);
		}
		if ( empty( $ids ) ) {
			\WP_CLI::error( 'Give one or more feed IDs, or --all.' );
		}
		foreach ( $ids as $id ) {
			$result = Generator::run_now( $id, ! empty( $assoc_args['force'] ) );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( '#%d: %s', $id, $result->get_error_message() ) );
				continue;
			}
			$excluded = array();
			foreach ( $result['excluded'] as $reason => $info ) {
				$excluded[] = $reason . '=' . $info['count'];
			}
			$warnings = array();
			foreach ( $result['warnings'] as $code => $list ) {
				$warnings[] = $code . '=' . count( $list );
			}
			\WP_CLI::success(
				sprintf(
					'#%d: %d items / %d candidates in %ss, %s. Excluded: %s. Warnings: %s',
					$id,
					$result['items'],
					$result['candidates'],
					$result['duration'],
					$result['url'],
					$excluded ? implode( ', ', $excluded ) : 'none',
					$warnings ? implode( ', ', $warnings ) : 'none'
				)
			);
		}
	}

	/**
	 * Generates the feeds that are due now, in this process. For a server cron when WP-Cron is disabled.
	 *
	 * ## EXAMPLES
	 *
	 *     wp oli-feed run-due
	 *
	 * @subcommand run-due
	 */
	public function run_due() {
		$delay = Scheduler::tick_delay();
		if ( $delay > HOUR_IN_SECONDS ) {
			\WP_CLI::warning( sprintf( 'The scheduled tick is %d minutes late: WP-Cron (or the Action Scheduler queue runner) does not run on this site.', (int) round( $delay / 60 ) ) );
		}
		$done = Scheduler::run_due();
		\WP_CLI::success( $done ? 'Generated: #' . implode( ', #', $done ) : 'No feed is due.' );
	}

	/**
	 * Shows the last log entry of a feed as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Feed ID.
	 *
	 * @param array $args Feed ID.
	 */
	public function status( $args ) {
		\WP_CLI::line( (string) wp_json_encode( Feeds::last_run( absint( $args[0] ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Exports feeds as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Feed IDs (default: all).
	 *
	 * @param array $args Feed IDs.
	 */
	public function export( $args ) {
		$ids = $args ? array_map( 'absint', $args ) : Feeds::all_ids();
		$out = array();
		foreach ( $ids as $id ) {
			$feed = Feeds::get( $id );
			if ( $feed ) {
				$feed['active'] = 'publish' === $feed['status'];
				unset( $feed['id'], $feed['status'] );
				$out[] = $feed;
			}
		}
		\WP_CLI::line( (string) wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Imports feeds from a JSON file (a list of feed configurations). A feed with the same file_slug is updated.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file.
	 *
	 * @param array $args File.
	 */
	public function import( $args ) {
		$json = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$list = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $list ) ) {
			\WP_CLI::error( 'Invalid JSON.' );
		}
		if ( isset( $list['channel'] ) ) {
			$list = array( $list );
		}
		$by_slug = array();
		foreach ( Feeds::all_ids() as $id ) {
			$feed                          = Feeds::get( $id );
			$by_slug[ $feed['file_slug'] ] = $id;
		}
		foreach ( $list as $config ) {
			$slug   = isset( $config['file_slug'] ) ? sanitize_title( $config['file_slug'] ) : '';
			$exists = '' !== $slug && isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : 0;
			$result = Feeds::save( $config, $exists );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( $result->get_error_message() );
				continue;
			}
			\WP_CLI::success( sprintf( '%s #%d (%s)', $exists ? 'Updated' : 'Created', $result, Storage::url_for( $result ) ) );
		}
	}

	/**
	 * Shows or sets a global setting (output_dir, delete_on_uninstall).
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Setting key.
	 *
	 * [<value>]
	 * : New value.
	 *
	 * @param array $args Key and value.
	 */
	public function settings( $args ) {
		if ( count( $args ) >= 2 ) {
			$saved = Settings::save( array( $args[0] => $args[1] ) );
			if ( is_wp_error( $saved ) ) {
				/* translators: %s: folder, e.g. "oli-product-feed". */
				\WP_CLI::error( $saved->get_error_message() . ' ' . sprintf( __( 'The feeds stay in wp-content/uploads/%s.', 'oli-product-feed' ), Storage::folder() ) );
			}
		}
		\WP_CLI::line( (string) wp_json_encode( Settings::all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
