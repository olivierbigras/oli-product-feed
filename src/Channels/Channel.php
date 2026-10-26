<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Base class of a destination channel (Google, Pinterest, Meta…).
 *
 * A channel receives the normalized item built by the core (see ItemBuilder), applies its value rules
 * (normalize), checks it (validate) and serializes it (header / item / footer) in one of its formats.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract channel.
 */
abstract class Channel {

	const REQUIRED    = 'required';
	const RECOMMENDED = 'recommended';
	const OPTIONAL    = 'optional';

	/**
	 * Unique channel ID (sanitize_key compatible).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Field definitions, in output order: field => [ 'label' => string, 'level' => required|recommended|optional,
	 * 'mapped_only' => bool (no automatic source: a column only when the feed maps it) ].
	 *
	 * @return array<string,array<string,string>>
	 */
	abstract public function fields();

	/**
	 * Header name of a column in a delimited file.
	 *
	 * @param string $field  Field.
	 * @param string $format csv|tsv|txt.
	 * @return string
	 */
	public function column_name( $field, $format ) {
		return $field;
	}

	/**
	 * Where to submit the feed URL on the platform (one short sentence), or ''.
	 *
	 * @return string
	 */
	public function help() {
		return '';
	}

	/**
	 * Supported formats, first one is the default.
	 *
	 * @return string[]
	 */
	public function formats() {
		return array( 'xml', 'csv', 'tsv' );
	}

	/**
	 * Default source of the mappable fields (field => source spec). The feed mapping overrides it.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function default_mapping() {
		return array(
			'id'    => array(
				'type' => 'field',
				'key'  => 'id',
			),
			'brand' => array(
				'type'   => 'taxonomy',
				'key'    => 'product_brand',
				'mode'   => 'first',
				'parent' => true,
			),
			'gtin'  => array(
				'type' => 'field',
				'key'  => 'gtin',
			),
			'mpn'   => array(
				'type' => 'field',
				'key'  => 'sku',
			),
			// Variant attributes, detected automatically (first attribute found; one value only).
			'color' => array(
				'type'      => 'attribute',
				'key'       => 'pa_color|pa_colour|color|colour',
				'translate' => true,
				'single'    => true,
			),
			'size'  => array(
				'type'      => 'attribute',
				'key'       => 'pa_size|size',
				'translate' => true,
				'single'    => true,
			),
		);
	}

	/**
	 * Does the channel use a product category taxonomy of its own (Google taxonomy)?
	 *
	 * @return bool
	 */
	public function uses_google_categories() {
		return true;
	}

	/**
	 * Maximum lengths (field => characters).
	 *
	 * @return array<string,int>
	 */
	public function limits() {
		return array(
			'id'          => 50,
			'title'       => 150,
			'description' => 5000,
		);
	}

	/**
	 * Maximum number of additional images.
	 *
	 * @return int
	 */
	public function max_additional_images() {
		return 10;
	}

	/**
	 * Availability value of the channel for a normalized availability.
	 *
	 * @param string $availability in_stock|out_of_stock|backorder|preorder.
	 * @return string
	 */
	public function availability( $availability ) {
		return $availability;
	}

	/**
	 * Price string of the channel.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 code.
	 * @return string
	 */
	public function price( $amount, $currency ) {
		return \Oliprfe\Util::money( $amount ) . ' ' . $currency;
	}

	/**
	 * Sale period string (ISO 8601 interval) or ''.
	 *
	 * @param int|null $from Timestamp.
	 * @param int|null $to   Timestamp.
	 * @return string
	 */
	public function sale_period( $from, $to ) {
		if ( ! $from && ! $to ) {
			return '';
		}
		$from = $from ? $from : time();
		$to   = $to ? $to : $from + 10 * YEAR_IN_SECONDS;
		return wp_date( 'Y-m-d\TH:iO', $from ) . '/' . wp_date( 'Y-m-d\TH:iO', $to );
	}

	/**
	 * Turns a normalized item into the channel row (field => string|string[]), in field order.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public function normalize( array $item, array $feed ) {
		$row = array();
		foreach ( $item as $key => $value ) {
			if ( in_array( $key, array( 'price', 'sale_price', 'sale_from', 'sale_to', 'availability' ), true ) ) {
				continue;
			}
			$row[ $key ] = $value;
		}
		$row['availability'] = $this->availability( $item['availability'] );
		$row['price']        = $this->price( $item['price'], $feed['currency'] );
		if ( null !== $item['sale_price'] ) {
			$row['sale_price'] = $this->price( $item['sale_price'], $feed['currency'] );
			$period            = $this->sale_period( $item['sale_from'], $item['sale_to'] );
			if ( '' !== $period ) {
				$row['sale_price_effective_date'] = $period;
			}
		}
		foreach ( $this->limits() as $field => $max ) {
			if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && mb_strlen( $row[ $field ] ) > $max ) {
				$row[ $field ] = rtrim( mb_substr( $row[ $field ], 0, $max ) );
			}
		}
		if ( isset( $row['additional_image_link'] ) && is_array( $row['additional_image_link'] ) ) {
			$row['additional_image_link'] = array_slice( $row['additional_image_link'], 0, $this->max_additional_images() );
		}
		$row = array_filter(
			$row,
			static function ( $v ) {
				return is_array( $v ) ? ! empty( $v ) : ( null !== $v && '' !== $v );
			}
		);
		return $this->order( $row );
	}

	/**
	 * Orders a row like fields(), unknown fields last.
	 *
	 * @param array<string,mixed> $row Row.
	 * @return array<string,mixed>
	 */
	protected function order( array $row ) {
		$ordered = array();
		foreach ( array_keys( $this->fields() ) as $field ) {
			if ( isset( $row[ $field ] ) ) {
				$ordered[ $field ] = $row[ $field ];
				unset( $row[ $field ] );
			}
		}
		return $ordered + $row;
	}

	/**
	 * Missing required fields of a row.
	 *
	 * @param array<string,mixed> $row Channel row.
	 * @return string[] Missing field names (empty = valid).
	 */
	public function validate( array $row ) {
		$missing = array();
		foreach ( $this->fields() as $field => $def ) {
			if ( self::REQUIRED === $def['level'] && empty( $row[ $field ] ) ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	/**
	 * Columns of a delimited file (fields of the channel, then extra mapped fields).
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return string[]
	 */
	public function columns( array $feed ) {
		$columns = array();
		foreach ( $this->fields() as $field => $def ) {
			// Fields without an automatic source are columns only when the feed maps them.
			if ( empty( $def['mapped_only'] ) || isset( $feed['mapping'][ $field ] ) ) {
				$columns[] = $field;
			}
		}
		foreach ( array_keys( $feed['mapping'] ) as $field ) {
			if ( ! in_array( $field, $columns, true ) ) {
				$columns[] = $field;
			}
		}
		return $columns;
	}

	/*
	 * Serialization. The default XML is RSS 2.0 with the Google namespace, accepted by Google, Pinterest,
	 * Meta, TikTok and Microsoft. Channels override these methods when they need another structure.
	 */

	/**
	 * XML namespace prefix of the item fields ('' = none).
	 *
	 * @return string
	 */
	protected function xml_prefix() {
		return 'g';
	}

	/**
	 * XML namespace URI.
	 *
	 * @return string
	 */
	protected function xml_namespace() {
		return 'http://base.google.com/ns/1.0';
	}

	/**
	 * Fields written without the namespace prefix in XML.
	 *
	 * @return string[]
	 */
	protected function xml_plain_fields() {
		return array( 'title', 'link', 'description' );
	}

	/**
	 * Fields written in CDATA in XML (free text).
	 *
	 * @return string[]
	 */
	protected function xml_cdata_fields() {
		return array( 'title', 'description', 'product_type', 'brand' );
	}

	/**
	 * File header.
	 *
	 * @param array<string,mixed> $feed Feed configuration (with 'site_title', 'site_link').
	 * @param string              $format xml|csv|tsv.
	 * @return string
	 */
	public function header( array $feed, $format ) {
		if ( 'xml' !== $format ) {
			$names = array();
			foreach ( $this->columns( $feed ) as $column ) {
				$names[] = $this->column_name( $column, $format );
			}
			return $this->delimited_line( $names, $format );
		}
		$ns = $this->xml_prefix() ? ' xmlns:' . $this->xml_prefix() . '="' . esc_attr( $this->xml_namespace() ) . '"' : '';
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0"' . $ns . ">\n<channel>\n"
			. "\t<title>" . self::xml_text( $feed['site_title'] ) . "</title>\n"
			. "\t<link>" . self::xml_text( $feed['site_link'] ) . "</link>\n"
			. "\t<description>" . self::xml_text( $feed['site_description'] ) . "</description>\n";
	}

	/**
	 * One serialized item.
	 *
	 * @param array<string,mixed> $row    Channel row.
	 * @param array<string,mixed> $feed   Feed configuration.
	 * @param string              $format xml|csv|tsv.
	 * @return string
	 */
	public function item( array $row, array $feed, $format ) {
		if ( 'xml' !== $format ) {
			$cells = array();
			foreach ( $this->columns( $feed ) as $column ) {
				$value   = isset( $row[ $column ] ) ? $row[ $column ] : '';
				$cells[] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			}
			return $this->delimited_line( $cells, $format );
		}
		$out    = "\t<item>\n";
		$prefix = $this->xml_prefix();
		foreach ( $row as $field => $value ) {
			$tag = ( '' === $prefix || in_array( $field, $this->xml_plain_fields(), true ) ) ? $field : $prefix . ':' . $field;
			foreach ( (array) $value as $single ) {
				$single = (string) $single;
				// CDATA for free text, and for any value holding markup characters (no "&amp;" in the file).
				$body = ( in_array( $field, $this->xml_cdata_fields(), true ) || preg_match( '/[&<>]/', $single ) ) ? self::cdata( $single ) : self::xml_text( $single );
				$out .= "\t\t<" . $tag . '>' . $body . '</' . $tag . ">\n";
			}
		}
		return $out . "\t</item>\n";
	}

	/**
	 * File footer.
	 *
	 * @param array<string,mixed> $feed   Feed configuration.
	 * @param string              $format xml|csv|tsv.
	 * @return string
	 */
	public function footer( array $feed, $format ) {
		return 'xml' === $format ? "</channel>\n</rss>\n" : '';
	}

	/**
	 * Removes characters that are not allowed in XML 1.0.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function xml_clean( $text ) {
		$text = (string) $text;
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		return (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
	}

	/**
	 * Escaped XML text node.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function xml_text( $text ) {
		return htmlspecialchars( self::xml_clean( $text ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * CDATA section (a "]]>" inside the text is split across two sections).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function cdata( $text ) {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', self::xml_clean( $text ) ) . ']]>';
	}

	/**
	 * CSV (RFC 4180) or tab-delimited line (tsv, txt).
	 *
	 * @param string[] $cells  Cells.
	 * @param string   $format csv|tsv|txt.
	 * @return string
	 */
	protected function delimited_line( array $cells, $format ) {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell = self::xml_clean( (string) $cell );
			if ( 'csv' !== $format ) {
				$out[] = str_replace( array( "\t", "\r", "\n" ), ' ', $cell );
			} else {
				$cell  = str_replace( array( "\r", "\n" ), ' ', $cell );
				$out[] = ( preg_match( '/[",]/', $cell ) || ( '' !== $cell && ( ' ' === $cell[0] || ' ' === substr( $cell, -1 ) ) ) ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
			}
		}
		return implode( 'csv' !== $format ? "\t" : ',', $out ) . "\n";
	}
}
