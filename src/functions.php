<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Public helper functions.
 *
 * @package Oliprfe
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the configuration of a feed (defaults merged).
 *
 * @param int $feed_id Feed post ID.
 * @return array<string,mixed>|null
 */
function oliprfe_get_feed( $feed_id ) {
	return \Oliprfe\Feeds::get( (int) $feed_id );
}

/**
 * Returns the public URL of a feed file.
 *
 * @param int $feed_id Feed post ID.
 * @return string
 */
function oliprfe_get_feed_url( $feed_id ) {
	return \Oliprfe\Storage::url_for( (int) $feed_id );
}
