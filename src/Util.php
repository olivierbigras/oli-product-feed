<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Small helpers (text cleaning, GTIN check, terms).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers.
 */
final class Util {

	/**
	 * Plain text: no shortcodes, no HTML, entities decoded, whitespace collapsed.
	 *
	 * @param string $text  Raw text or HTML.
	 * @param int    $limit Max length (0 = none).
	 * @return string
	 */
	public static function clean_text( $text, $limit = 0 ) {
		$text = strip_shortcodes( (string) $text );
		$text = preg_replace( '/<(br|\/p|\/li|\/h[1-6]|\/div)[^>]*>/i', '$0 ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Entities that were double encoded (&amp;#8211;).
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		if ( $limit > 0 && mb_strlen( $text ) > $limit ) {
			$cut  = mb_substr( $text, 0, $limit );
			$last = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $last && $last > $limit * 0.8 ? mb_substr( $cut, 0, $last ) : $cut );
		}
		return $text;
	}

	/**
	 * Full regular expression for a configured pattern (case-insensitive, Unicode).
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return string
	 */
	public static function regex( $pattern ) {
		return '~' . str_replace( '~', '\\~', (string) $pattern ) . '~iu';
	}

	/**
	 * Valid GTIN-8/12/13/14 with a correct check digit.
	 *
	 * @param string $gtin Candidate.
	 * @return bool
	 */
	public static function is_valid_gtin( $gtin ) {
		$gtin = (string) $gtin;
		if ( ! preg_match( '/^\d{8}$|^\d{12,14}$/', $gtin ) ) {
			return false;
		}
		$digits = str_split( str_pad( $gtin, 14, '0', STR_PAD_LEFT ) );
		$check  = (int) array_pop( $digits );
		$sum    = 0;
		foreach ( $digits as $i => $digit ) {
			$sum += (int) $digit * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( ( 10 - ( $sum % 10 ) ) % 10 ) === $check;
	}

	/**
	 * Deepest term of a list (most ancestors).
	 *
	 * @param \WP_Term[] $terms    Terms.
	 * @param string     $taxonomy Taxonomy.
	 * @return \WP_Term
	 */
	public static function deepest_term( array $terms, $taxonomy ) {
		$best  = reset( $terms );
		$depth = -1;
		foreach ( $terms as $term ) {
			$d = count( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
			if ( $d > $depth || ( $d === $depth && $term->term_id < $best->term_id ) ) {
				$best  = $term;
				$depth = $d;
			}
		}
		return $best;
	}

	/**
	 * Primary term of a product: SEO plugin primary category (Rank Math, Yoast) when it is one of the
	 * product terms, else the deepest term.
	 *
	 * @param int        $product_id Product ID.
	 * @param \WP_Term[] $terms      Product terms.
	 * @param string     $taxonomy   Taxonomy.
	 * @return \WP_Term
	 */
	public static function primary_term( $product_id, array $terms, $taxonomy ) {
		$keys = apply_filters( 'oliprfe_primary_term_meta_keys', array( 'rank_math_primary_' . $taxonomy, '_yoast_wpseo_primary_' . $taxonomy ), $taxonomy );
		foreach ( $keys as $key ) {
			$primary = (int) get_post_meta( $product_id, $key, true );
			if ( $primary ) {
				foreach ( $terms as $term ) {
					if ( (int) $term->term_id === $primary ) {
						return $term;
					}
				}
			}
		}
		return self::deepest_term( $terms, $taxonomy );
	}

	/**
	 * Price with two decimals.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function money( $amount ) {
		return number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * Sanitized key=value pairs from a query string, one-per-line text, or an array.
	 * Only pairs with `=` are kept. Keys go through sanitize_key() (lowercased,
	 * characters outside [a-z0-9_-] stripped). Values go through sanitize_text_field().
	 *
	 * @param mixed $raw Query string (`wcpbc-manual-country=US`), lines, or array.
	 * @return array<string,string>
	 */
	public static function query_pairs( $raw ) {
		if ( is_array( $raw ) ) {
			$pairs = $raw;
		} else {
			$raw = ltrim( trim( (string) $raw ), '?' );
			if ( '' === $raw ) {
				return array();
			}
			$raw   = (string) preg_replace( '/[\r\n]+/', '&', $raw );
			$pairs = array();
			foreach ( explode( '&', $raw ) as $chunk ) {
				$chunk = trim( $chunk );
				if ( '' === $chunk || false === strpos( $chunk, '=' ) ) {
					continue;
				}
				$parts         = explode( '=', $chunk, 2 );
				$key           = rawurldecode( trim( $parts[0] ) );
				$pairs[ $key ] = rawurldecode( trim( $parts[1] ) );
			}
		}
		$out = array();
		foreach ( $pairs as $k => $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			// Parameter keys are lowercased (and stripped of characters outside [a-z0-9_-]) by sanitize_key().
			$k = sanitize_key( (string) $k );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = sanitize_text_field( (string) $v );
		}
		return $out;
	}

	/**
	 * Adds query arguments to a URL with add_query_arg(), keeping those already present
	 * (variation attribute_*, lang, …). Empty $args leaves the URL unchanged.
	 * Values are rawurlencoded before add_query_arg() so `&`, `#` and spaces stay
	 * one valid query value (WordPress add_query_arg does not encode newly added values).
	 *
	 * @param string               $url  URL.
	 * @param array<string,string> $args Arguments.
	 * @return string
	 */
	public static function add_query_args( $url, array $args ) {
		if ( '' === (string) $url || empty( $args ) ) {
			return $url;
		}
		$encoded = array();
		foreach ( $args as $k => $v ) {
			$encoded[ $k ] = rawurlencode( (string) $v );
		}
		return add_query_arg( $encoded, $url );
	}

	/**
	 * Adds feed link arguments to g:link and mobile_link. image_link is left untouched.
	 * Empty $args returns the item unchanged (byte-identical feeds).
	 *
	 * @param array<string,mixed>  $item Item.
	 * @param array<string,string> $args Arguments.
	 * @return array<string,mixed>
	 */
	public static function apply_feed_link_args( array $item, array $args ) {
		if ( empty( $args ) ) {
			return $item;
		}
		foreach ( array( 'link', 'mobile_link' ) as $field ) {
			if ( ! empty( $item[ $field ] ) && is_string( $item[ $field ] ) ) {
				$item[ $field ] = self::encode_url( self::add_query_args( $item[ $field ], $args ) );
			}
		}
		return $item;
	}

	/**
	 * Percent-encodes the characters of a URL that are not allowed raw (non-ASCII, spaces), keeping the rest.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function encode_url( $url ) {
		return (string) preg_replace_callback(
			'/[^\x21-\x7E]+/',
			static function ( $m ) {
				return rawurlencode( $m[0] );
			},
			(string) $url
		);
	}

	/**
	 * Converts a URL to https when the site runs on https and the host is the site host.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function maybe_https( $url ) {
		static $home_host = null;
		if ( null === $home_host ) {
			// The unfiltered home option: after trp_switch_language(), home_url() is
			// http://site/fr/ even when the site itself is https://site.
			$raw = get_option( 'home' );
			if ( ! is_string( $raw ) || '' === $raw ) {
				$raw = home_url();
			}
			$home_host = 'https' === wp_parse_url( $raw, PHP_URL_SCHEME ) ? wp_parse_url( $raw, PHP_URL_HOST ) : '';
		}
		if ( '' !== $home_host && 0 === strpos( $url, 'http://' ) && wp_parse_url( $url, PHP_URL_HOST ) === $home_host ) {
			$url = 'https://' . substr( $url, 7 );
		}
		return $url;
	}
}
