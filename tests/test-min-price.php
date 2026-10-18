<?php
/**
 * Unit tests for the per-feed minimum price (0.5.0).
 *
 * Covers empty/0 (off), displayed sale vs regular, feed-currency comparison
 * without converting the threshold, Price Based on Country-style prices, and
 * the oliprfe_min_price filter. Run: php tests/test-min-price.php
 *
 * phpcs:ignoreFile
 *
 * @package Oliprfe
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}

	$GLOBALS['oliprfe_test_filters'] = array();

	if ( ! function_exists( 'apply_filters' ) ) {
		/**
		 * @param string $tag   Hook.
		 * @param mixed  $value Value.
		 * @param mixed  ...$args Extra.
		 * @return mixed
		 */
		function apply_filters( $tag, $value, ...$args ) {
			if ( empty( $GLOBALS['oliprfe_test_filters'][ $tag ] ) ) {
				return $value;
			}
			foreach ( $GLOBALS['oliprfe_test_filters'][ $tag ] as $callback ) {
				$value = call_user_func( $callback, $value, ...$args );
			}
			return $value;
		}
	}

	if ( ! function_exists( 'add_filter' ) ) {
		/**
		 * @param string   $tag      Hook.
		 * @param callable $callback Callback.
		 */
		function add_filter( $tag, $callback ) {
			$GLOBALS['oliprfe_test_filters'][ $tag ][] = $callback;
		}
	}

	if ( ! function_exists( 'remove_all_filters' ) ) {
		/**
		 * @param string $tag Hook.
		 */
		function remove_all_filters( $tag ) {
			unset( $GLOBALS['oliprfe_test_filters'][ $tag ] );
		}
	}

	if ( ! function_exists( 'wp_parse_args' ) ) {
		/**
		 * @param array $args     Args.
		 * @param array $defaults Defaults.
		 * @return array
		 */
		function wp_parse_args( $args, $defaults = array() ) {
			return array_merge( $defaults, $args );
		}
	}

	require_once dirname( __DIR__ ) . '/src/Pricing.php';
	require_once dirname( __DIR__ ) . '/src/Feeds.php';
}

namespace Oliprfe {

	$oliprfe_failed = 0;
	$oliprfe_passed = 0;

	/**
	 * @param string $name     Case name.
	 * @param mixed  $expected Expected.
	 * @param mixed  $actual   Actual.
	 */
	$oliprfe_assert = static function ( $name, $expected, $actual ) use ( &$oliprfe_failed, &$oliprfe_passed ) {
		if ( $expected === $actual ) {
			++$oliprfe_passed;
			echo 'OK  ' . $name . "\n";
			return;
		}
		++$oliprfe_failed;
		echo 'FAIL ' . $name . "\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	};

	/**
	 * @param mixed $value Raw amount.
	 * @return string
	 */
	$oliprfe_amount = static function ( $value ) {
		$method = new \ReflectionMethod( Feeds::class, 'amount' );
		$method->setAccessible( true );
		return $method->invoke( null, $value );
	};

	$product = new \stdClass();

	// --- Displayed price: sale when a promo is active, otherwise regular.
	$oliprfe_assert(
		'displayed price is the regular price without a sale',
		19.99,
		Pricing::displayed(
			array(
				'regular' => 19.99,
				'sale'    => null,
			)
		)
	);
	$oliprfe_assert(
		'displayed price is the sale price when a promo is active',
		14.5,
		Pricing::displayed(
			array(
				'regular' => 19.99,
				'sale'    => 14.5,
			)
		)
	);

	// --- Empty or 0 disables the threshold (byte-identical feeds).
	$oliprfe_assert( 'empty min_price is 0 (off)', 0.0, Pricing::min_price( array(), $product ) );
	$oliprfe_assert( 'blank min_price is 0 (off)', 0.0, Pricing::min_price( array( 'min_price' => '' ), $product ) );
	$oliprfe_assert( '0 min_price is 0 (off)', 0.0, Pricing::min_price( array( 'min_price' => '0' ), $product ) );
	$oliprfe_assert( '0.00 min_price is 0 (off)', 0.0, Pricing::min_price( array( 'min_price' => '0.00' ), $product ) );
	$oliprfe_assert( 'stored 150 is 150.0', 150.0, Pricing::min_price( array( 'min_price' => '150' ), $product ) );
	$oliprfe_assert( 'stored 150.50 is 150.5', 150.5, Pricing::min_price( array( 'min_price' => '150.50' ), $product ) );

	$cheap = array(
		'regular' => 80.0,
		'sale'    => null,
	);
	$oliprfe_assert( 'no threshold does not exclude', false, Pricing::below_min( $cheap, array(), $product ) );
	$oliprfe_assert( 'empty threshold does not exclude', false, Pricing::below_min( $cheap, array( 'min_price' => '' ), $product ) );
	$oliprfe_assert( 'zero threshold does not exclude', false, Pricing::below_min( $cheap, array( 'min_price' => 0 ), $product ) );

	// --- Comparison is strict "below" the threshold, in the feed currency, no conversion.
	$feed_usd = array(
		'currency'  => 'USD',
		'min_price' => '150',
	);
	$feed_cad = array(
		'currency'  => 'CAD',
		'min_price' => '150',
	);
	$oliprfe_assert(
		'149.99 USD is below 150 USD',
		true,
		Pricing::below_min(
			array(
				'regular' => 149.99,
				'sale'    => null,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'150 USD is kept (not strictly below)',
		false,
		Pricing::below_min(
			array(
				'regular' => 150.0,
				'sale'    => null,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'160 USD is kept',
		false,
		Pricing::below_min(
			array(
				'regular' => 160.0,
				'sale'    => null,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'149.99 CAD is below 150 CAD (threshold is not converted from USD)',
		true,
		Pricing::below_min(
			array(
				'regular' => 149.99,
				'sale'    => null,
			),
			$feed_cad,
			$product
		)
	);
	$oliprfe_assert(
		'150 CAD is kept on a CAD feed with threshold 150',
		false,
		Pricing::below_min(
			array(
				'regular' => 150.0,
				'sale'    => null,
			),
			$feed_cad,
			$product
		)
	);

	// --- Displayed price (sale) is the amount compared — same as g:sale_price.
	$oliprfe_assert(
		'sale 80 with regular 200 is excluded when min is 150',
		true,
		Pricing::below_min(
			array(
				'regular' => 200.0,
				'sale'    => 80.0,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'sale 160 with regular 200 is kept when min is 150',
		false,
		Pricing::below_min(
			array(
				'regular' => 200.0,
				'sale'    => 160.0,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'regular 80 is excluded even without a sale',
		true,
		Pricing::below_min( $cheap, $feed_usd, $product )
	);

	// --- PBOC / converted prices: compare the feed-currency amounts as-is (manual US zone).
	$wcpbc_us = array(
		'regular' => 99.0,
		'sale'    => 79.0,
	);
	$oliprfe_assert(
		'WCPBC US sale 79 is below 150 USD (no CAD conversion)',
		true,
		Pricing::below_min( $wcpbc_us, $feed_usd, $product )
	);
	$oliprfe_assert(
		'WCPBC US sale 79 displayed amount is 79',
		79.0,
		Pricing::displayed( $wcpbc_us )
	);
	$oliprfe_assert(
		'WCPBC US regular 180 is kept on a 150 USD feed',
		false,
		Pricing::below_min(
			array(
				'regular' => 180.0,
				'sale'    => null,
			),
			$feed_usd,
			$product
		)
	);

	// --- oliprfe_min_price filter (value, feed, product).
	add_filter(
		'oliprfe_min_price',
		static function ( $value, $feed, $filtered_product ) use ( $product ) {
			if ( $filtered_product !== $product ) {
				return $value;
			}
			if ( isset( $feed['currency'] ) && 'USD' === $feed['currency'] ) {
				return 200;
			}
			return $value;
		}
	);
	$oliprfe_assert(
		'filter can raise the USD threshold to 200',
		200.0,
		Pricing::min_price( $feed_usd, $product )
	);
	$oliprfe_assert(
		'180 USD is excluded after the filter raises the threshold',
		true,
		Pricing::below_min(
			array(
				'regular' => 180.0,
				'sale'    => null,
			),
			$feed_usd,
			$product
		)
	);
	$oliprfe_assert(
		'filter does not convert a CAD threshold (CAD feed stays 150)',
		150.0,
		Pricing::min_price( $feed_cad, $product )
	);
	remove_all_filters( 'oliprfe_min_price' );

	add_filter(
		'oliprfe_min_price',
		static function () {
			return 0;
		}
	);
	$oliprfe_assert(
		'filter can disable a stored threshold',
		false,
		Pricing::below_min( $cheap, $feed_usd, $product )
	);
	remove_all_filters( 'oliprfe_min_price' );

	add_filter(
		'oliprfe_min_price',
		static function () {
			return '90.25';
		}
	);
	$oliprfe_assert(
		'filter can enable a threshold on a feed that stored none',
		90.25,
		Pricing::min_price( array( 'min_price' => '' ), $product )
	);
	remove_all_filters( 'oliprfe_min_price' );

	// --- Storage sanitization (empty / 0 kept as off; decimals; invalid dropped).
	$oliprfe_assert( 'sanitize empty stays empty', '', $oliprfe_amount( '' ) );
	$oliprfe_assert( 'sanitize 0 stays 0', '0', $oliprfe_amount( '0' ) );
	$oliprfe_assert( 'sanitize 0.00 stays 0', '0', $oliprfe_amount( '0.00' ) );
	$oliprfe_assert( 'sanitize 150.50', '150.5', $oliprfe_amount( '150.50' ) );
	$oliprfe_assert( 'sanitize comma decimal', '12.5', $oliprfe_amount( '12,5' ) );
	$oliprfe_assert( 'sanitize invalid is empty', '', $oliprfe_amount( 'abc' ) );
	$oliprfe_assert( 'sanitize negative is empty', '', $oliprfe_amount( '-10' ) );

	echo "\n" . $oliprfe_passed . ' passed, ' . $oliprfe_failed . " failed\n";
	exit( $oliprfe_failed > 0 ? 1 : 0 );
}
