<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Optional integration with "Price Based on Country for WooCommerce".
 *
 * Active only when that plugin is loaded AND the feed price source is "wcpbc". The prices of the
 * pricing zone of the feed country are read through the zone API (manual zone prices or exchange
 * rate prices, as the shop shows them to a visitor of that country). Nothing is written.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Price Based on Country integration.
 */
final class PriceBasedOnCountry {

	const SOURCE = 'wcpbc';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! self::available() ) {
			return;
		}
		add_filter( 'oliprfe_price_sources', array( __CLASS__, 'add_source' ) );
		add_filter( 'oliprfe_price', array( __CLASS__, 'price' ), 10, 3 );
		add_filter( 'oliprfe_link_args', array( __CLASS__, 'link_args' ), 10, 3 );
	}

	/**
	 * Is the plugin loaded?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wcpbc_get_zone_by_country' );
	}

	/**
	 * Adds the price source.
	 *
	 * @param array<string,string> $sources Sources.
	 * @return array<string,string>
	 */
	public static function add_source( $sources ) {
		$sources[ self::SOURCE ] = __( 'Price Based on Country (zone of the feed country)', 'oli-product-feed' );
		return $sources;
	}

	/**
	 * Pricing zone of a feed, or null (base prices).
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return object|null
	 */
	public static function zone( array $feed ) {
		$zone_id = isset( $feed['price_source_options']['zone'] ) ? (string) $feed['price_source_options']['zone'] : '';
		if ( '' !== $zone_id && class_exists( 'WCPBC_Pricing_Zones' ) && method_exists( 'WCPBC_Pricing_Zones', 'get_zone_by_id' ) ) {
			$zone = \WCPBC_Pricing_Zones::get_zone_by_id( $zone_id );
			if ( $zone ) {
				return $zone;
			}
		}
		if ( '' === $feed['country'] ) {
			return null;
		}
		$zone = wcpbc_get_zone_by_country( $feed['country'] );
		return $zone ? $zone : null;
	}

	/**
	 * Replaces the prices with the zone prices.
	 *
	 * @param array|null          $prices  Prices.
	 * @param \WC_Product         $product Product.
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @return array|null
	 */
	public static function price( $prices, $product, $feed ) {
		if ( self::SOURCE !== $feed['price_source'] ) {
			return $prices;
		}
		$zone = self::zone( $feed );
		if ( ! $zone || ! method_exists( $zone, 'get_post_price' ) ) {
			return $prices; // Country without a zone: base prices.
		}
		if ( method_exists( $zone, 'get_currency' ) && strtoupper( (string) $zone->get_currency() ) !== $feed['currency'] ) {
			return null; // Zone currency differs from the feed currency: never mix currencies.
		}
		$id      = $product->get_id();
		$regular = $zone->get_post_price( $id, '_regular_price' );
		$sale    = $zone->get_post_price( $id, '_sale_price' );
		if ( '' === $regular || null === $regular || ! is_numeric( $regular ) || (float) $regular <= 0 ) {
			return null;
		}
		$out     = array(
			'regular'   => (float) $regular,
			'sale'      => is_numeric( $sale ) && (float) $sale > 0 ? (float) $sale : null,
			'sale_from' => is_array( $prices ) && isset( $prices['sale_from'] ) ? $prices['sale_from'] : null,
			'sale_to'   => is_array( $prices ) && isset( $prices['sale_to'] ) ? $prices['sale_to'] : null,
		);
		$zone_id = method_exists( $zone, 'get_id' ) ? (string) $zone->get_id() : '';
		if ( '' !== $zone_id && 'manual' === get_post_meta( $id, '_' . $zone_id . '_price_method', true )
			&& 'manual' === get_post_meta( $id, '_' . $zone_id . '_sale_price_dates', true ) ) {
			$from             = get_post_meta( $id, '_' . $zone_id . '_sale_price_dates_from', true );
			$to               = get_post_meta( $id, '_' . $zone_id . '_sale_price_dates_to', true );
			$out['sale_from'] = is_numeric( $from ) ? (int) $from : null;
			$out['sale_to']   = is_numeric( $to ) ? (int) $to : null;
			if ( $out['sale_from'] && $out['sale_from'] > time() ) {
				$out['sale'] = null;
			}
		}
		return $out;
	}

	/**
	 * Adds `wcpbc-manual-country=<feed country>` to the links when the feed option is on.
	 *
	 * @param array<string,string> $args    Query arguments.
	 * @param \WC_Product          $product Product.
	 * @param array<string,mixed>  $feed    Feed configuration.
	 * @return array<string,string>
	 */
	public static function link_args( $args, $product, $feed ) {
		if ( self::SOURCE === $feed['price_source'] && ! empty( $feed['price_source_options']['country_param'] ) && '' !== $feed['country'] ) {
			$args['wcpbc-manual-country'] = $feed['country'];
		}
		return $args;
	}
}
