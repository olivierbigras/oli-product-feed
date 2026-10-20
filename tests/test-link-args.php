<?php
/**
 * Unit tests for per-feed "Parameters added to links" (0.5.1).
 *
 * Covers query-string parsing, empty setting (byte-identical URL), simple
 * product links, variation attribute_* preservation, an already-present
 * parameter, mobile_link, and image_link left untouched.
 * Run: php tests/test-link-args.php
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

	if ( ! function_exists( 'sanitize_key' ) ) {
		/**
		 * @param string $key Key.
		 * @return string
		 */
		function sanitize_key( $key ) {
			$key = strtolower( (string) $key );
			return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		/**
		 * @param string $str Value.
		 * @return string
		 */
		function sanitize_text_field( $str ) {
			$str = wp_strip_all_tags( (string) $str );
			$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
			return trim( $str );
		}
	}

	if ( ! function_exists( 'wp_strip_all_tags' ) ) {
		/**
		 * @param string $str Value.
		 * @return string
		 */
		function wp_strip_all_tags( $str ) {
			return trim( strip_tags( (string) $str ) );
		}
	}

	if ( ! function_exists( 'add_query_arg' ) ) {
		/**
		 * Minimal WordPress add_query_arg( array $args, $url ).
		 *
		 * @param array  $args Arguments.
		 * @param string $url  URL.
		 * @return string
		 */
		function add_query_arg( $args, $url ) {
			$frag = '';
			if ( false !== strpos( $url, '#' ) ) {
				$bits = explode( '#', $url, 2 );
				$url  = $bits[0];
				$frag = '#' . $bits[1];
			}
			$qs = array();
			if ( false !== strpos( $url, '?' ) ) {
				$bits = explode( '?', $url, 2 );
				$url  = $bits[0];
				parse_str( $bits[1], $qs );
			}
			foreach ( (array) $args as $k => $v ) {
				$qs[ $k ] = $v;
			}
			$pairs = array();
			foreach ( $qs as $k => $v ) {
				$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
			}
			return $url . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $frag;
		}
	}

	require_once dirname( __DIR__ ) . '/src/Util.php';
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

	$product = new \stdClass();

	/**
	 * Same merge + filter as ItemBuilder (args, product, feed).
	 *
	 * @param array $feed    Feed.
	 * @param mixed $product Product.
	 * @return array
	 */
	$oliprfe_args = static function ( array $feed, $product ) {
		$args = array_merge(
			isset( $feed['link_args'] ) && is_array( $feed['link_args'] ) ? $feed['link_args'] : array(),
			isset( $feed['utm'] ) && is_array( $feed['utm'] ) ? $feed['utm'] : array()
		);
		$args = apply_filters( 'oliprfe_link_args', $args, $product, $feed );
		return is_array( $args ) ? $args : array();
	};

	// --- Parsing: query string, only key=value, sanitize_key / sanitize_text_field.
	$oliprfe_assert( 'empty string is an empty list', array(), Util::query_pairs( '' ) );
	$oliprfe_assert( 'whitespace only is an empty list', array(), Util::query_pairs( '   ' ) );
	$oliprfe_assert(
		'wcpbc-manual-country=US',
		array( 'wcpbc-manual-country' => 'US' ),
		Util::query_pairs( 'wcpbc-manual-country=US' )
	);
	$oliprfe_assert(
		'leading ? is stripped',
		array( 'wcpbc-manual-country' => 'US' ),
		Util::query_pairs( '?wcpbc-manual-country=US' )
	);
	$oliprfe_assert(
		'several pairs with &',
		array(
			'wcpbc-manual-country' => 'US',
			'foo'                  => 'bar',
		),
		Util::query_pairs( 'wcpbc-manual-country=US&foo=bar' )
	);
	$oliprfe_assert(
		'one pair per line still works',
		array(
			'wcpbc-manual-country' => 'US',
			'foo'                  => 'bar',
		),
		Util::query_pairs( "wcpbc-manual-country=US\nfoo=bar" )
	);
	$oliprfe_assert(
		'tokens without = are dropped',
		array( 'wcpbc-manual-country' => 'US' ),
		Util::query_pairs( 'wcpbc-manual-country=US&notapair&foo' )
	);
	$oliprfe_assert(
		'sanitize_key lowercases and strips the key',
		array( 'wcpbc-manual-country' => 'US' ),
		Util::query_pairs( 'WCPBC-Manual-Country=US' )
	);
	$oliprfe_assert(
		'empty key is dropped',
		array(),
		Util::query_pairs( '=US' )
	);
	$oliprfe_assert(
		'array input is re-sanitized',
		array( 'wcpbc-manual-country' => 'US' ),
		Util::query_pairs( array( 'WCPBC-Manual-Country' => 'US' ) )
	);

	// --- Empty setting: URL and item stay identical (byte for byte).
	$simple = 'https://site/produit/slug/';
	$oliprfe_assert( 'add_query_args with no args returns the same URL', $simple, Util::add_query_args( $simple, array() ) );

	$item = array(
		'link'       => $simple,
		'image_link' => 'https://site/wp-content/uploads/p.jpg',
		'mobile_link'=> 'https://site/m/produit/slug/',
	);
	$oliprfe_assert( 'empty args leave the item identical', $item, Util::apply_feed_link_args( $item, array() ) );
	$oliprfe_assert(
		'empty feed link_args + utm produce no args',
		array(),
		$oliprfe_args(
			array(
				'link_args' => array(),
				'utm'       => array(),
			),
			$product
		)
	);

	// --- Simple product: parameter appended.
	$oliprfe_assert(
		'simple product link receives wcpbc-manual-country=US',
		'https://site/produit/slug/?wcpbc-manual-country=US',
		Util::add_query_args( $simple, array( 'wcpbc-manual-country' => 'US' ) )
	);

	// --- Variation: attribute_* is kept.
	$variation = 'https://site/produit/slug/?attribute_pa_color=noir';
	$oliprfe_assert(
		'variation attribute_* is kept',
		'https://site/produit/slug/?attribute_pa_color=noir&wcpbc-manual-country=US',
		Util::add_query_args( $variation, array( 'wcpbc-manual-country' => 'US' ) )
	);

	// --- Link that already has a parameter (lang).
	$with_lang = 'https://site/produit/slug/?lang=fr';
	$oliprfe_assert(
		'existing lang parameter is kept',
		'https://site/produit/slug/?lang=fr&wcpbc-manual-country=US',
		Util::add_query_args( $with_lang, array( 'wcpbc-manual-country' => 'US' ) )
	);

	// --- Item: g:link + mobile_link, not image_link.
	$updated = Util::apply_feed_link_args(
		$item,
		array( 'wcpbc-manual-country' => 'US' )
	);
	$oliprfe_assert(
		'item link receives the query string',
		'https://site/produit/slug/?wcpbc-manual-country=US',
		$updated['link']
	);
	$oliprfe_assert(
		'mobile_link receives the query string',
		'https://site/m/produit/slug/?wcpbc-manual-country=US',
		$updated['mobile_link']
	);
	$oliprfe_assert(
		'image_link is not modified',
		'https://site/wp-content/uploads/p.jpg',
		$updated['image_link']
	);

	$no_mobile = Util::apply_feed_link_args(
		array(
			'link'       => $simple,
			'image_link' => 'https://site/wp-content/uploads/p.jpg',
		),
		array( 'wcpbc-manual-country' => 'US' )
	);
	$oliprfe_assert( 'missing mobile_link is not created', false, isset( $no_mobile['mobile_link'] ) );
	$oliprfe_assert( 'image_link still untouched without mobile_link', 'https://site/wp-content/uploads/p.jpg', $no_mobile['image_link'] );

	// --- oliprfe_link_args filter (array, product, feed).
	add_filter(
		'oliprfe_link_args',
		static function ( $args, $filtered_product, $feed ) use ( $product ) {
			if ( $filtered_product !== $product ) {
				return $args;
			}
			$args['from-filter'] = isset( $feed['country'] ) ? $feed['country'] : '';
			return $args;
		}
	);
	$oliprfe_assert(
		'oliprfe_link_args can add a pair (value, feed, product)',
		array(
			'wcpbc-manual-country' => 'US',
			'from-filter'          => 'US',
		),
		$oliprfe_args(
			array(
				'link_args' => array( 'wcpbc-manual-country' => 'US' ),
				'utm'       => array(),
				'country'   => 'US',
			),
			$product
		)
	);
	remove_all_filters( 'oliprfe_link_args' );

	echo "\n" . $oliprfe_passed . ' passed, ' . $oliprfe_failed . " failed\n";
	exit( $oliprfe_failed > 0 ? 1 : 0 );
}
