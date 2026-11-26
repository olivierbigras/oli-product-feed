<?php
/**
 * Google product taxonomy: optional local list provided by the site (no network call, nothing bundled).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Lookup and search in a local copy of Google's taxonomy-with-ids file ("ID - Path > Sub").
 *
 * The plugin does not ship the file: Google publishes it without a redistribution license. A site can
 * point the oliprfe_google_taxonomy_file filter to its own copy (for example taxonomy-with-ids.fr-FR.txt);
 * without it, the category fields take a numeric ID, as in 0.5.7.
 */
final class GoogleTaxonomy {

	/**
	 * ID => path, loaded once per request.
	 *
	 * @var array<int,string>|null
	 */
	private static $paths = null;

	/**
	 * All categories.
	 *
	 * @return array<int,string>
	 */
	public static function all() {
		if ( null === self::$paths ) {
			self::$paths = array();
			/**
			 * Local copy of Google's taxonomy-with-ids file ('' : none, the fields take an ID).
			 *
			 * @param string $file   Absolute path.
			 * @param string $locale Admin locale (for example fr_FR or en_US).
			 */
			$file        = (string) apply_filters( 'oliprfe_google_taxonomy_file', '', determine_locale() );
			$lines       = '' !== $file && is_readable( $file ) ? file( $file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ) : array();
			foreach ( (array) $lines as $line ) {
				if ( preg_match( '/^(\d+) - (.+)$/', $line, $m ) ) {
					self::$paths[ (int) $m[1] ] = $m[2];
				}
			}
		}
		return self::$paths;
	}

	/**
	 * Is a local list available?
	 *
	 * @return bool
	 */
	public static function available() {
		return (bool) self::all();
	}

	/**
	 * Path of a category ID, or ''.
	 *
	 * @param int|string $id Category ID.
	 * @return string
	 */
	public static function path( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : '';
	}

	/**
	 * Categories matching every word of the query (or the start of an ID), best first.
	 *
	 * @param string $query Search text.
	 * @param int    $max   Results at most.
	 * @return array<int,string> ID => path.
	 */
	public static function search( $query, $max = 20 ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}
		$words = preg_split( '/\s+/', function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query ) );
		$found = array();
		foreach ( self::all() as $id => $path ) {
			$low = function_exists( 'mb_strtolower' ) ? mb_strtolower( $path ) : strtolower( $path );
			if ( ctype_digit( $query ) ) {
				if ( 0 !== strpos( (string) $id, $query ) ) {
					continue;
				}
			} else {
				foreach ( $words as $word ) {
					if ( false === strpos( $low, $word ) ) {
						continue 2;
					}
				}
			}
			$pos          = strrpos( $low, '>' );
			$leaf         = false === $pos ? $low : substr( $low, $pos + 1 );
			$found[ $id ] = ( false !== strpos( $leaf, $words[0] ) ? 0 : 1000 ) + substr_count( $path, '>' );
		}
		asort( $found );
		$out = array();
		foreach ( array_slice( array_keys( $found ), 0, $max ) as $id ) {
			$out[ $id ] = self::$paths[ $id ];
		}
		return $out;
	}
}
