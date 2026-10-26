<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Language layer: TranslatePress adapter (strings and URLs). Without a multilingual plugin, values are returned untouched.
 *
 * Feed generation runs in cron, Action Scheduler, WP-CLI or admin. TranslatePress keys translations and
 * permalinks on the current language ($TRP_LANGUAGE). That must be switched to the feed language or
 * titles, descriptions and /fr/ links stay in the default language.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Multilingual helpers.
 */
final class Lang {

	/**
	 * Per-request cache of translated strings.
	 *
	 * @var array<string,string>
	 */
	private static $cache = array();

	/**
	 * Nested switch_language() snapshots (so restore_language() is balanced).
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private static $stack = array();

	/**
	 * Is TranslatePress active?
	 *
	 * @return bool
	 */
	public static function has_translatepress() {
		return class_exists( 'TRP_Translate_Press' ) && function_exists( 'trp_translate' );
	}

	/**
	 * Available languages (code => name).
	 *
	 * @return array<string,string>
	 */
	public static function languages() {
		$langs = array();
		if ( self::has_translatepress() ) {
			$settings = get_option( 'trp_settings', array() );
			$codes    = isset( $settings['publish-languages'] ) && is_array( $settings['publish-languages'] ) ? $settings['publish-languages'] : array();
			$names    = function_exists( 'trp_get_languages' ) ? trp_get_languages() : array();
			foreach ( $codes as $code ) {
				$langs[ $code ] = isset( $names[ $code ] ) ? $names[ $code ] : $code;
			}
		}
		return apply_filters( 'oliprfe_languages', $langs );
	}

	/**
	 * Default language code ('' without a multilingual plugin).
	 *
	 * @return string
	 */
	public static function default_language() {
		if ( self::has_translatepress() ) {
			$settings = get_option( 'trp_settings', array() );
			return isset( $settings['default-language'] ) ? (string) $settings['default-language'] : '';
		}
		return '';
	}

	/**
	 * Does this feed language need translation?
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public static function needs_translation( $language ) {
		return '' !== $language && self::has_translatepress() && self::default_language() !== $language;
	}

	/**
	 * Sets TranslatePress and the WordPress locale to a feed language for the rest of the request
	 * (or until restore_language()). Safe to call when TranslatePress is inactive.
	 *
	 * @param string $language Language code (e.g. fr_CA).
	 */
	public static function switch_language( $language ) {
		$language = (string) $language;
		$snapshot = array(
			'used_trp'    => false,
			'used_locale' => false,
			'trp_prev'    => null,
		);
		if ( '' !== $language && self::has_translatepress() && function_exists( 'trp_switch_language' ) ) {
			trp_switch_language( $language );
			$snapshot['used_trp'] = true;
		} elseif ( '' !== $language && self::has_translatepress() ) {
			global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress current-language global.
			$snapshot['trp_prev'] = isset( $TRP_LANGUAGE ) ? $TRP_LANGUAGE : null; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress current-language global.
			$TRP_LANGUAGE         = $language; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress current-language global.
			$snapshot['used_trp'] = 'manual';
			if ( function_exists( 'switch_to_locale' ) && switch_to_locale( $language ) ) {
				$snapshot['used_locale'] = true;
			}
		} elseif ( '' !== $language && function_exists( 'switch_to_locale' ) && switch_to_locale( $language ) ) {
			$snapshot['used_locale'] = true;
		}
		if ( empty( self::$stack ) ) {
			add_filter( 'trp_force_admin_translate', array( __CLASS__, 'yes' ) );
			add_filter( 'trp_stop_translating_page', array( __CLASS__, 'no' ) );
		}
		self::$stack[] = $snapshot;
	}

	/**
	 * Restores the language set by the matching switch_language().
	 */
	public static function restore_language() {
		$snapshot = array_pop( self::$stack );
		if ( ! is_array( $snapshot ) ) {
			return;
		}
		if ( true === $snapshot['used_trp'] && function_exists( 'trp_restore_language' ) ) {
			trp_restore_language();
		} elseif ( 'manual' === $snapshot['used_trp'] ) {
			global $TRP_LANGUAGE; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress current-language global.
			$TRP_LANGUAGE = $snapshot['trp_prev']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- TranslatePress current-language global.
			if ( $snapshot['used_locale'] && function_exists( 'restore_previous_locale' ) ) {
				restore_previous_locale();
			}
		} elseif ( $snapshot['used_locale'] && function_exists( 'restore_previous_locale' ) ) {
			restore_previous_locale();
		}
		if ( empty( self::$stack ) ) {
			remove_filter( 'trp_force_admin_translate', array( __CLASS__, 'yes' ) );
			remove_filter( 'trp_stop_translating_page', array( __CLASS__, 'no' ) );
		}
	}

	/**
	 * True callback for TranslatePress filters.
	 *
	 * @return true
	 */
	public static function yes() {
		return true;
	}

	/**
	 * False callback for TranslatePress filters.
	 *
	 * @return false
	 */
	public static function no() {
		return false;
	}

	/**
	 * Translates a string or an HTML fragment.
	 *
	 * @param string $text     Text or HTML in the default language.
	 * @param string $language Target language.
	 * @return string
	 */
	public static function translate( $text, $language ) {
		$text = (string) $text;
		if ( '' === trim( $text ) || ! self::needs_translation( $language ) ) {
			return $text;
		}
		$key = $language . '|' . md5( $text );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$translated = trp_translate( $text, $language, false );
		$translated = is_string( $translated ) && '' !== trim( $translated ) ? $translated : $text;
		if ( $translated === $text ) {
			$from_db = self::from_dictionary( $text, $language );
			if ( '' !== $from_db && $from_db !== $text ) {
				$translated = $from_db;
			}
		}
		$translated = apply_filters( 'oliprfe_translate', $translated, $text, $language );
		if ( count( self::$cache ) > 5000 ) {
			self::$cache = array();
		}
		self::$cache[ $key ] = $translated;
		return $translated;
	}

	/**
	 * Looks up a string in the TranslatePress dictionary (fallback when trp_translate is skipped in admin/cron).
	 *
	 * @param string $text     Original.
	 * @param string $language Target language.
	 * @return string Original when nothing is found.
	 */
	private static function from_dictionary( $text, $language ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return $text;
		}
		$trp   = \TRP_Translate_Press::get_trp_instance();
		$query = $trp ? $trp->get_component( 'query' ) : null;
		if ( ! $query || ! method_exists( $query, 'get_existing_translations' ) ) {
			return $text;
		}
		$candidates = array( $text );
		$encoded    = htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
		if ( $encoded !== $text ) {
			$candidates[] = $encoded;
		}
		foreach ( $candidates as $original ) {
			$found = $query->get_existing_translations( array( $original ), $language );
			if ( ! is_array( $found ) ) {
				continue;
			}
			$row = null;
			if ( isset( $found[ $original ] ) ) {
				$row = $found[ $original ];
			} elseif ( $found ) {
				$row = reset( $found );
			}
			$translated = '';
			if ( is_object( $row ) && isset( $row->translated ) ) {
				$translated = (string) $row->translated;
			} elseif ( is_array( $row ) && isset( $row['translated'] ) ) {
				$translated = (string) $row['translated'];
			} elseif ( is_string( $row ) ) {
				$translated = $row;
			}
			if ( '' !== trim( $translated ) ) {
				return $translated;
			}
		}
		return $text;
	}

	/**
	 * Translates a product description: rendered like the front end (wpautop, texturize), translated, then returned as HTML.
	 *
	 * @param string $html     Raw description.
	 * @param string $language Target language.
	 * @return string
	 */
	public static function translate_content( $html, $language ) {
		if ( ! self::needs_translation( $language ) ) {
			return (string) $html;
		}
		return self::translate( self::render_content( $html ), $language );
	}

	/**
	 * Post content rendered like the front end (the form TranslatePress stores translations for).
	 *
	 * @param string $html Raw post content.
	 * @return string
	 */
	public static function render_content( $html ) {
		$rendered = wptexturize( wpautop( strip_shortcodes( (string) $html ) ) );
		return convert_chars( convert_smilies( $rendered ) );
	}

	/**
	 * Site home URL in the default language (unfiltered `home` option).
	 *
	 * After trp_switch_language(), home_url() already includes /fr/, so callers
	 * that need a base URL to convert once must use this instead.
	 *
	 * @return string Trailing slash.
	 */
	public static function default_home_url() {
		return trailingslashit( self::abs_home() );
	}

	/**
	 * URL in the feed language, then optionally https when the site runs on https.
	 *
	 * @param string $url          URL (default language or already prefixed).
	 * @param string $language     Target language.
	 * @param bool   $force_https  Apply maybe_https after conversion.
	 * @return string
	 */
	public static function feed_url( $url, $language, $force_https = false ) {
		$url = self::url( $url, $language );
		if ( $force_https ) {
			$url = Util::maybe_https( $url );
		}
		return $url;
	}

	/**
	 * URL in the feed language.
	 *
	 * Idempotent: a URL that already has the target language slug is left as-is
	 * (duplicate slugs collapsed). Otherwise the default-language URL is converted
	 * once. After trp_switch_language(), get_permalink() and home_url() already
	 * return /fr/ URLs; running the TranslatePress converter on those doubled the
	 * slug and dropped https.
	 *
	 * @param string $url      URL in the default language or already in $language.
	 * @param string $language Target language.
	 * @return string
	 */
	public static function url( $url, $language ) {
		$url = (string) $url;
		if ( '' === $url || ! self::needs_translation( $language ) ) {
			return $url;
		}
		$slug = self::url_slug( $language );
		if ( '' !== $slug && self::path_has_slug( $url, $slug ) ) {
			$url = self::dedupe_slug( $url, $slug );
			return (string) apply_filters( 'oliprfe_language_url', $url, $language );
		}
		$base      = self::unprefix_url( $url );
		$converted = self::convert_url( $base, $language );
		if ( is_string( $converted ) && '' !== $converted && $converted !== $base ) {
			$url = $converted;
		} else {
			$prefixed = self::prefix_url( $base, $language );
			$url      = '' !== $prefixed ? $prefixed : $base;
		}
		if ( '' !== $slug ) {
			$url = self::dedupe_slug( $url, $slug );
		}
		return (string) apply_filters( 'oliprfe_language_url', $url, $language );
	}

	/**
	 * TranslatePress URL converter (third argument '' as recommended by their docs).
	 *
	 * @param string $url      URL.
	 * @param string $language Target language.
	 * @return string Original when conversion fails.
	 */
	private static function convert_url( $url, $language ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return $url;
		}
		$trp       = \TRP_Translate_Press::get_trp_instance();
		$converter = $trp ? $trp->get_component( 'url_converter' ) : null;
		if ( $converter && method_exists( $converter, 'get_url_for_language' ) ) {
			$converted = $converter->get_url_for_language( $language, $url, '' );
			if ( is_string( $converted ) && '' !== $converted ) {
				return $converted;
			}
		}
		return $url;
	}

	/**
	 * Adds the TranslatePress language slug (e.g. /fr/) when the converter did not.
	 *
	 * Compares against the default-language home, not home_url() after a language
	 * switch (that path already includes /fr/, so the slug would be added twice).
	 *
	 * @param string $url      URL in the default language.
	 * @param string $language Target language.
	 * @return string
	 */
	private static function prefix_url( $url, $language ) {
		$slug = self::url_slug( $language );
		if ( '' === $slug || ! self::same_host( $url ) ) {
			return $url;
		}
		$parts      = wp_parse_url( self::unprefix_url( $url ) );
		$home_parts = wp_parse_url( self::abs_home() );
		if ( ! is_array( $parts ) || ! is_array( $home_parts ) ) {
			return $url;
		}
		$relative      = self::relative_path( isset( $parts['path'] ) ? $parts['path'] : '/', isset( $home_parts['path'] ) ? $home_parts['path'] : '' );
		$home_path     = isset( $home_parts['path'] ) ? untrailingslashit( $home_parts['path'] ) : '';
		$parts['path'] = ( '' !== $home_path ? $home_path : '' ) . '/' . $slug . ( '/' === $relative ? '/' : $relative );
		if ( empty( $parts['scheme'] ) ) {
			$parts['scheme'] = isset( $home_parts['scheme'] ) ? $home_parts['scheme'] : 'https';
		}
		if ( empty( $parts['host'] ) ) {
			$parts['host'] = isset( $home_parts['host'] ) ? $home_parts['host'] : '';
		}
		return self::build_url( $parts );
	}

	/**
	 * Configured language slug for a language code (e.g. fr_CA → fr).
	 *
	 * @param string $language Language code.
	 * @return string Empty when none.
	 */
	private static function url_slug( $language ) {
		$slugs = self::url_slugs();
		return isset( $slugs[ $language ] ) ? $slugs[ $language ] : '';
	}

	/**
	 * Non-empty TranslatePress URL slugs (code => slug).
	 *
	 * @return array<string,string>
	 */
	private static function url_slugs() {
		$settings = get_option( 'trp_settings', array() );
		$raw      = isset( $settings['url-slugs'] ) && is_array( $settings['url-slugs'] ) ? $settings['url-slugs'] : array();
		$slugs    = array();
		foreach ( $raw as $code => $slug ) {
			$slug = trim( (string) $slug, '/' );
			if ( '' !== $slug ) {
				$slugs[ (string) $code ] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Default-language site origin, ignoring TranslatePress home_url filters.
	 *
	 * @return string No trailing slash.
	 */
	private static function abs_home() {
		$home = get_option( 'home' );
		if ( ! is_string( $home ) || '' === $home ) {
			$home = home_url();
		}
		return untrailingslashit( (string) $home );
	}

	/**
	 * Whether the URL is on the same host as the site.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function same_host( $url ) {
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( self::abs_home() );
		if ( ! is_array( $parts ) || ! is_array( $home ) ) {
			return false;
		}
		if ( ! isset( $parts['host'], $home['host'] ) ) {
			return true;
		}
		return $parts['host'] === $home['host'];
	}

	/**
	 * Path relative to the default-language home path.
	 *
	 * @param string $path      URL path.
	 * @param string $home_path Home path (may be empty).
	 * @return string Always starts with /.
	 */
	private static function relative_path( $path, $home_path ) {
		$path      = '' === (string) $path ? '/' : (string) $path;
		$home_path = untrailingslashit( (string) $home_path );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}
		return $path;
	}

	/**
	 * True when the first path segment after the home path is the language slug.
	 *
	 * @param string $url  URL.
	 * @param string $slug Language slug (e.g. fr).
	 * @return bool
	 */
	private static function path_has_slug( $url, $slug ) {
		if ( '' === $slug || ! self::same_host( $url ) ) {
			return false;
		}
		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( self::abs_home() );
		$path       = self::relative_path( isset( $parts['path'] ) ? $parts['path'] : '/', is_array( $home_parts ) && isset( $home_parts['path'] ) ? $home_parts['path'] : '' );
		return (bool) preg_match( '#^/' . preg_quote( $slug, '#' ) . '(/|$)#', $path );
	}

	/**
	 * Strips a leading TranslatePress language slug so conversion starts from the default language.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function unprefix_url( $url ) {
		if ( ! self::same_host( $url ) ) {
			return $url;
		}
		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( self::abs_home() );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$home_path = is_array( $home_parts ) && isset( $home_parts['path'] ) ? $home_parts['path'] : '';
		$relative  = self::relative_path( isset( $parts['path'] ) ? $parts['path'] : '/', $home_path );
		foreach ( self::url_slugs() as $existing ) {
			if ( ! preg_match( '#^/' . preg_quote( $existing, '#' ) . '(/|$)#', $relative ) ) {
				continue;
			}
			$relative = substr( $relative, strlen( $existing ) + 1 );
			if ( '' === $relative || '/' !== $relative[0] ) {
				$relative = '/' . ltrim( $relative, '/' );
			}
			break;
		}
		$home_path     = untrailingslashit( (string) $home_path );
		$parts['path'] = ( '' !== $home_path ? $home_path : '' ) . ( '/' === $relative ? '/' : $relative );
		return self::build_url( $parts );
	}

	/**
	 * Collapses a repeated language slug (/fr/fr/ → /fr/).
	 *
	 * @param string $url  URL.
	 * @param string $slug Language slug.
	 * @return string
	 */
	private static function dedupe_slug( $url, $slug ) {
		if ( '' === $slug || ! self::same_host( $url ) ) {
			return $url;
		}
		$parts      = wp_parse_url( $url );
		$home_parts = wp_parse_url( self::abs_home() );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$home_path = is_array( $home_parts ) && isset( $home_parts['path'] ) ? $home_parts['path'] : '';
		$relative  = self::relative_path( isset( $parts['path'] ) ? $parts['path'] : '/', $home_path );
		$deduped   = preg_replace( '#^(?:/' . preg_quote( $slug, '#' ) . ')+(/|$)#', '/' . $slug . '$1', $relative );
		if ( ! is_string( $deduped ) || $deduped === $relative ) {
			return $url;
		}
		$home_path     = untrailingslashit( (string) $home_path );
		$parts['path'] = ( '' !== $home_path ? $home_path : '' ) . ( '/' === $deduped ? '/' : $deduped );
		return self::build_url( $parts );
	}

	/**
	 * Rebuilds a URL from parse_url parts.
	 *
	 * @param array<string,mixed> $parts Parts.
	 * @return string
	 */
	private static function build_url( array $parts ) {
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$out    = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$out .= ':' . $parts['port'];
		}
		$out .= isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$out .= '?' . $parts['query'];
		}
		if ( isset( $parts['fragment'] ) && '' !== $parts['fragment'] ) {
			$out .= '#' . $parts['fragment'];
		}
		return $out;
	}
}
