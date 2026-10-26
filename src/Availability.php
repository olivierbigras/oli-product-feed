<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Backorder / preorder availability date (Google, Microsoft, Meta).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Lead time, per-product override and ISO 8601 date.
 */
final class Availability {

	const META = '_oliprfe_availability';

	/**
	 * Per-product override (variation first, then parent). Empty when none.
	 *
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent  Parent.
	 * @return string Days ("21") or a date ("2026-12-01").
	 */
	public static function override( $product, $parent = null ) {
		$value = self::sanitize_override( get_post_meta( $product->get_id(), self::META, true ) );
		if ( '' === $value && $parent ) {
			$value = self::sanitize_override( get_post_meta( $parent->get_id(), self::META, true ) );
		}
		return $value;
	}

	/**
	 * Days (0–3650) or a calendar / ISO date. Empty when the value is not usable.
	 *
	 * @param mixed $value Raw meta or form value.
	 * @return string
	 */
	public static function sanitize_override( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{1,4}$/', $value ) ) {
			$days = (int) $value;
			return ( $days >= 0 && $days <= 3650 ) ? (string) $days : '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value ) ) {
			return $value;
		}
		return '';
	}

	/**
	 * ISO 8601 datetime in the site time zone (e.g. 2026-11-06T00:00:00-04:00).
	 *
	 * A whole number is a number of days from today (midnight). A date without a
	 * time is midnight on that day. Recalculated at each call (each feed generation).
	 *
	 * @param string $value Days or a date.
	 * @return string Empty when the value cannot be parsed.
	 */
	public static function datetime( $value ) {
		$value = self::sanitize_override( $value );
		if ( '' === $value ) {
			return '';
		}
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		if ( preg_match( '/^\d+$/', $value ) ) {
			$dt = new \DateTimeImmutable( 'now', $tz );
			$dt = $dt->setTime( 0, 0, 0 )->modify( '+' . (int) $value . ' days' );
			return $dt->format( 'Y-m-d\TH:i:sP' );
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $tz );
			return $dt ? $dt->format( 'Y-m-d\TH:i:sP' ) : '';
		}
		try {
			$dt = new \DateTimeImmutable( $value, $tz );
		} catch ( \Exception $e ) {
			return '';
		}
		return $dt->format( 'Y-m-d\TH:i:sP' );
	}

	/**
	 * Should this item get an automatic availability date?
	 *
	 * @param array<string,mixed> $feed         Feed configuration.
	 * @param string              $override     Per-product override.
	 * @param string              $availability Normalized availability.
	 * @return bool
	 */
	public static function wants_date( array $feed, $override, $availability ) {
		if ( ! in_array( $availability, array( 'backorder', 'preorder' ), true ) ) {
			return false;
		}
		return ! empty( $feed['backorder_lead_enabled'] ) || '' !== $override;
	}
}
