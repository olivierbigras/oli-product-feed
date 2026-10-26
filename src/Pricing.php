<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Prices of a product for a feed.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Price resolution. Integrations replace the WooCommerce prices through the `oliprfe_price` filter.
 */
final class Pricing {

	/**
	 * Price sources (id => label). Integrations add theirs with `oliprfe_price_sources`.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return apply_filters(
			'oliprfe_price_sources',
			array( 'woocommerce' => __( 'WooCommerce prices', 'oli-product-feed' ) )
		);
	}

	/**
	 * Prices of a product in the feed currency.
	 *
	 * Returns null when the product has no usable price (it is then excluded).
	 * Keys: regular (float), sale (float|null), sale_from (int|null timestamp), sale_to (int|null timestamp).
	 *
	 * @param \WC_Product         $product Product or variation.
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @return array<string,mixed>|null
	 */
	public static function get( $product, array $feed ) {
		$prices = self::woocommerce( $product );

		/**
		 * Filters the prices of a product for a feed. Return null to exclude the product.
		 *
		 * @param array|null  $prices  { regular, sale, sale_from, sale_to }.
		 * @param \WC_Product $product Product.
		 * @param array       $feed    Feed configuration.
		 */
		$prices = apply_filters( 'oliprfe_price', $prices, $product, $feed );

		if ( ! is_array( $prices ) || ! isset( $prices['regular'] ) || ! is_numeric( $prices['regular'] ) || (float) $prices['regular'] <= 0 ) {
			return null;
		}
		$prices = wp_parse_args(
			$prices,
			array(
				'sale'      => null,
				'sale_from' => null,
				'sale_to'   => null,
			)
		);
		$now    = time();
		// A sale that is over, or that is not lower than the regular price, is ignored.
		if ( null !== $prices['sale'] && ( ! is_numeric( $prices['sale'] ) || (float) $prices['sale'] <= 0 || (float) $prices['sale'] >= (float) $prices['regular'] || ( $prices['sale_to'] && (int) $prices['sale_to'] < $now ) ) ) {
			$prices['sale']      = null;
			$prices['sale_from'] = null;
			$prices['sale_to']   = null;
		}
		$prices['regular'] = (float) $prices['regular'];
		$prices['sale']    = null === $prices['sale'] ? null : (float) $prices['sale'];
		return $prices;
	}

	/**
	 * Price written for the product in this feed: sale price when a promotion is active, otherwise the regular price.
	 * Same amount as `<g:sale_price>` or `<g:price>` (feed currency, after `oliprfe_price`).
	 *
	 * @param array<string,mixed> $prices Prices from get().
	 * @return float
	 */
	public static function displayed( array $prices ) {
		return null !== $prices['sale'] ? (float) $prices['sale'] : (float) $prices['regular'];
	}

	/**
	 * Effective minimum displayed price of a feed (0 = off). Compared in the feed currency, never converted.
	 *
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @param \WC_Product         $product Product or variation.
	 * @return float
	 */
	public static function min_price( array $feed, $product ) {
		$value = isset( $feed['min_price'] ) ? $feed['min_price'] : '';

		/**
		 * Filters the minimum displayed price of a feed. Empty or 0 disables the threshold.
		 * The value is in the feed currency (150 for a 150 USD or 150 CAD feed); it is not converted.
		 *
		 * @param mixed       $value   Stored threshold (string amount, or empty).
		 * @param array       $feed    Feed configuration.
		 * @param \WC_Product $product Product or variation.
		 */
		$value = apply_filters( 'oliprfe_min_price', $value, $feed, $product );
		if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
			return 0.0;
		}
		return (float) $value;
	}

	/**
	 * Is the displayed feed price below the feed minimum?
	 *
	 * @param array<string,mixed> $prices  Prices from get().
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @param \WC_Product         $product Product or variation.
	 * @return bool
	 */
	public static function below_min( array $prices, array $feed, $product ) {
		$min = self::min_price( $feed, $product );
		return $min > 0 && self::displayed( $prices ) < $min;
	}

	/**
	 * Raw WooCommerce prices ('edit' context, so that no front-end price filter changes them).
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>|null
	 */
	public static function woocommerce( $product ) {
		if ( $product->is_type( 'variable' ) ) {
			$regular = $product->get_variation_regular_price( 'min', false );
			$sale    = $product->get_variation_sale_price( 'min', false );
			return array(
				'regular'   => '' === $regular ? null : (float) $regular,
				'sale'      => ( '' === $sale || (float) $sale >= (float) $regular ) ? null : (float) $sale,
				'sale_from' => null,
				'sale_to'   => null,
			);
		}
		$regular = $product->get_regular_price( 'edit' );
		$sale    = $product->get_sale_price( 'edit' );
		$from    = $product->get_date_on_sale_from( 'edit' );
		$to      = $product->get_date_on_sale_to( 'edit' );
		if ( '' === $regular || null === $regular ) {
			return null;
		}
		return array(
			'regular'   => (float) $regular,
			'sale'      => '' === $sale || null === $sale ? null : (float) $sale,
			'sale_from' => $from ? $from->getTimestamp() : null,
			'sale_to'   => $to ? $to->getTimestamp() : null,
		);
	}
}
