<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Channel registry (filterable with `oliprfe_channels`).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the channel instances.
 */
final class Registry {

	/**
	 * Channel instances by ID.
	 *
	 * @var Channel[]|null
	 */
	private static $channels = null;

	/**
	 * All channels.
	 *
	 * @return Channel[]
	 */
	public static function all() {
		if ( null === self::$channels ) {
			$built = array( new Google(), new Pinterest(), new Meta(), new TikTok(), new Microsoft(), new Generic() );

			/**
			 * Filters the available channels. Add-ons append instances of
			 * \Oliprfe\Channels\Channel subclasses.
			 *
			 * @param Channel[] $built Channel instances.
			 */
			$built          = apply_filters( 'oliprfe_channels', $built );
			self::$channels = array();
			foreach ( (array) $built as $channel ) {
				if ( $channel instanceof Channel ) {
					self::$channels[ sanitize_key( $channel->id() ) ] = $channel;
				}
			}
		}
		return self::$channels;
	}

	/**
	 * One channel, or null.
	 *
	 * @param string $id Channel ID.
	 * @return Channel|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * File extension of a format.
	 *
	 * @param string $format xml|csv|tsv|txt.
	 * @return string
	 */
	public static function extension( $format ) {
		return in_array( $format, self::formats(), true ) ? $format : 'xml';
	}

	/**
	 * All file formats.
	 *
	 * @return string[]
	 */
	public static function formats() {
		return array( 'xml', 'csv', 'tsv', 'txt' );
	}
}
