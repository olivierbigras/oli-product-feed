<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Optional serving of the feed files through WordPress (?oliprfe_feed=<file>): short Cache-Control,
 * X-Robots-Tag noindex, Content-Type, ETag and Last-Modified, whatever the host or CDN does with static files.
 * The pre-generated file is streamed; the feed is never built during the request. Served as early as possible:
 * while the plugins load (plugin file), else on init (priority 1).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Feed endpoint.
 */
final class Endpoint {

	const QUERY_VAR = 'oliprfe_feed';

	/**
	 * Autoloaded index read by the early path: [ 'mode' => file|endpoint, 'feeds' => [ slug => [ path, format, max_age ] ] ].
	 */
	const INDEX_OPTION = 'oliprfe_endpoint_index';

	/**
	 * Hooks.
	 */
	public static function init() {
		// Fallback path (the early path runs when the plugin file is loaded): before TranslatePress
		// (language redirects, output buffer), redirect_canonical and any template.
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 1 );
		$index = get_option( self::INDEX_OPTION );
		if ( ! is_array( $index ) || ! isset( $index['version'] ) || OLIPRFE_VERSION !== $index['version'] ) {
			// First load after an update: build the early index once the feed post type exists.
			add_action( 'init', array( __CLASS__, 'refresh_index' ), 20 );
		}
	}

	/**
	 * Earliest path: serves a feed while the plugins are still loading (plugin file),
	 * from the autoloaded index only: no WooCommerce, TranslatePress, query or theme.
	 * Unknown slugs fall through to the init path (full lookup, 404).
	 */
	public static function serve_early() {
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! function_exists( 'get_option' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$index = get_option( self::INDEX_OPTION );
		if ( ! is_array( $index ) || empty( $index['feeds'] ) || ! isset( $index['mode'] ) || 'endpoint' !== $index['mode'] ) {
			return;
		}
		// Index of another plugin version (rebuilt on init): the init path decides.
		if ( defined( 'OLIPRFE_VERSION' ) && ( ! isset( $index['version'] ) || OLIPRFE_VERSION !== $index['version'] ) ) {
			return;
		}
		$slug = sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $slug || ! isset( $index['feeds'][ $slug ] ) ) {
			return;
		}
		$entry = $index['feeds'][ $slug ];
		// A feed trashed or deleted since the index was built is never served (one primary key lookup).
		if ( empty( $entry['id'] ) || ! in_array( get_post_status( (int) $entry['id'] ), array( 'publish', 'draft' ), true ) ) {
			return;
		}
		$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';
		$dir  = isset( $index['dir'] ) ? (string) $index['dir'] : '';
		$real = '' !== $path ? realpath( $path ) : false;
		$base = '' !== $dir ? realpath( $dir ) : false;
		// Only a readable file of the plugin's output folder.
		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $real ) ) {
			return;
		}
		self::send( $real, isset( $entry['format'] ) ? (string) $entry['format'] : 'xml', isset( $entry['max_age'] ) ? (int) $entry['max_age'] : 0 );
	}

	/**
	 * Rebuilds the early index (after a save, a deletion, a generation or a settings change).
	 */
	public static function refresh_index() {
		$previous = get_option( self::INDEX_OPTION );
		if ( ! is_array( $previous ) || ! isset( $previous['version'] ) || OLIPRFE_VERSION !== $previous['version'] ) {
			// First rebuild by this version: remove the files of feeds trashed by an older version, record the
			// output folder and clean the previous ones (filter changed while the plugin was updated).
			Feeds::delete_trashed_files();
			Storage::sync_dirs();
		}
		$feeds = array();
		foreach ( Feeds::all_ids() as $feed_id ) {
			$feed = Feeds::get( $feed_id );
			// Every feed with a file (active or not), like maybe_serve().
			if ( ! $feed || ! is_readable( Storage::path_for( $feed_id ) ) ) {
				continue;
			}
			$feeds[ $feed['file_slug'] ] = array(
				'id'      => (int) $feed_id,
				'path'    => Storage::path_for( $feed_id ),
				'format'  => $feed['format'],
				'max_age' => self::max_age( $feed ),
			);
		}
		$base  = Storage::base();
		$index = array(
			'version' => OLIPRFE_VERSION,
			'mode'    => self::enabled() ? 'endpoint' : 'file',
			'dir'     => $base['dir'],
			'feeds'   => $feeds,
		);
		if ( $previous !== $index ) {
			update_option( self::INDEX_OPTION, $index, true );
		}
	}

	/**
	 * Is the endpoint enabled?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return 'endpoint' === Settings::get( 'serve_mode' );
	}

	/**
	 * Public endpoint URL of a feed.
	 *
	 * @param string $file_slug File slug.
	 * @return string
	 */
	public static function url( $file_slug ) {
		$home = home_url( '/' );
		if ( 'https' === wp_parse_url( $home, PHP_URL_SCHEME ) || is_ssl() ) {
			$home = set_url_scheme( $home, 'https' );
		}
		return add_query_arg( self::QUERY_VAR, rawurlencode( $file_slug ), $home );
	}

	/**
	 * Serves the requested feed file and exits.
	 */
	public static function maybe_serve() {
		// Public, read-only endpoint: no nonce. The value is reduced to a file slug.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! self::enabled() ) {
			// Static file mode: the feeds are not served here.
			self::not_found();
		}
		$slug    = sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$feed_id = '' !== $slug ? Feeds::find_by_slug( $slug ) : 0;
		// Active or inactive: "inactive" only stops the scheduled generation. A generated file is served,
		// as the web server does in static file mode.
		if ( ! $feed_id || ! in_array( get_post_status( $feed_id ), array( 'publish', 'draft' ), true ) ) {
			self::not_found();
		}
		$feed = Feeds::get( $feed_id );
		$path = Storage::path_for( $feed_id );
		if ( ! $feed || ! is_readable( $path ) ) {
			self::not_found();
		}
		// The early path did not know this feed: refresh its index for the next requests.
		self::refresh_index();
		self::send( $path, $feed['format'], self::max_age( $feed ) );
	}

	/**
	 * Cache lifetime of a feed: the setting, at most half of the regeneration interval.
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return int Seconds.
	 */
	public static function max_age( array $feed ) {
		$max_age  = max( 0, (int) Settings::get( 'cache_max_age' ) );
		$interval = Scheduler::interval( isset( $feed['frequency'] ) ? $feed['frequency'] : 'manual' );
		if ( $interval > 0 ) {
			$max_age = min( $max_age, (int) floor( $interval / 2 ) );
		}
		return $max_age;
	}

	/**
	 * Does an If-None-Match header match the ETag? (weak comparison: a CDN may add "W/" when it compresses).
	 *
	 * @param string $header If-None-Match value.
	 * @param string $etag   Current ETag.
	 * @return bool
	 */
	public static function etag_matches( $header, $etag ) {
		if ( '*' === trim( $header ) ) {
			return true;
		}
		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( 0 === strpos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}
			// A compressing proxy may also append a suffix inside the quotes ("abc-gzip").
			if ( $candidate === $etag || preg_replace( '/-(gzip|br|zstd)"$/', '"', $candidate ) === $etag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sends the pre-generated file with cache validators. The feed is never built here.
	 *
	 * @param string $path    File path.
	 * @param string $format  xml|csv|tsv.
	 * @param int    $max_age Cache lifetime in seconds (0: no-cache).
	 */
	private static function send( $path, $format, $max_age ) {
		$types = array(
			'xml' => 'application/xml; charset=UTF-8',
			'csv' => 'text/csv; charset=UTF-8',
			'tsv' => 'text/tab-separated-values; charset=UTF-8',
			'txt' => 'text/plain; charset=UTF-8',
		);
		clearstatcache( true, $path );
		$mtime = (int) filemtime( $path );
		$size  = (int) filesize( $path );
		$etag  = '"' . md5( $mtime . '-' . $size ) . '"';

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard constant read by page cache plugins.
		}
		// Nothing else may reach the body (output buffers of other plugins), and no cookie (cacheable response).
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			header_remove( 'Set-Cookie' );
			header_remove( 'Link' );
		}
		status_header( 200 );
		header( 'Content-Type: ' . ( isset( $types[ $format ] ) ? $types[ $format ] : $types['xml'] ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'ETag: ' . $etag );
		header( 'Vary: Accept-Encoding' );
		header( $max_age > 0 ? 'Cache-Control: public, max-age=' . $max_age . ', s-maxage=' . $max_age : 'Cache-Control: no-cache, max-age=0, must-revalidate' );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) ) : '';
		$if_modified   = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? strtotime( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) ) : false;
		// If-None-Match wins over If-Modified-Since (RFC 9110).
		if ( '' !== $if_none_match ? self::etag_matches( $if_none_match, $etag ) : ( $if_modified && $if_modified >= $mtime ) ) {
			status_header( 304 );
			exit;
		}
		header( 'Content-Length: ' . $size );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'HEAD' !== $method ) {
			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams the pre-generated file.
		}
		exit;
	}

	/**
	 * 404 without a theme template.
	 */
	private static function not_found() {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo esc_html__( 'Feed not found.', 'oli-product-feed' );
		exit;
	}
}
