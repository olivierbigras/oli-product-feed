<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Generic channel: CSV, TSV or simple XML with free columns (any platform or tool that reads a product file).
 *
 * Columns come from the feed setting "columns" (list of [ name, field ]): the column name in the file and the
 * item field it takes (a standard field below, or any attribute added in the mapping). Without columns, every
 * field is written under its own name. Prices are numbers, with the currency in its own column.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Generic CSV/XML.
 */
class Generic extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'generic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Generic CSV / XML', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'Give this URL to the platform or tool that reads the file. Choose the columns in the Columns setting.', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function formats() {
		return array( 'csv', 'xml', 'tsv' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_mapping() {
		return parent::default_mapping() + array(
			'sku' => array(
				'type' => 'field',
				'key'  => 'sku',
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function fields() {
		return array(
			'id'                        => array(
				'label' => __( 'ID', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'item_group_id'             => array(
				'label' => __( 'Item group ID', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'sku'                       => array(
				'label' => __( 'SKU', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'title'                     => array(
				'label' => __( 'Title', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'description'               => array(
				'label' => __( 'Description', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'link'                      => array(
				'label' => __( 'Link', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'image_link'                => array(
				'label' => __( 'Image link', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'additional_image_link'     => array(
				'label' => __( 'Additional image links', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'availability'              => array(
				'label' => __( 'Availability', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'price'                     => array(
				'label' => __( 'Price', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'sale_price'                => array(
				'label' => __( 'Sale price', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'sale_price_effective_date' => array(
				'label' => __( 'Sale price effective date', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'currency'                  => array(
				'label' => __( 'Currency', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'brand'                     => array(
				'label' => __( 'Brand', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'gtin'                      => array(
				'label' => __( 'GTIN', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'mpn'                       => array(
				'label' => __( 'MPN', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'condition'                 => array(
				'label' => __( 'Condition', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'google_product_category'   => array(
				'label' => __( 'Google product category', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'product_type'              => array(
				'label' => __( 'Product type', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'color'                     => array(
				'label' => __( 'Color', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'size'                      => array(
				'label' => __( 'Size', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'gender'                    => array(
				'label' => __( 'Gender', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'age_group'                 => array(
				'label' => __( 'Age group', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'material'                  => array(
				'label' => __( 'Material', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'pattern'                   => array(
				'label' => __( 'Pattern', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'shipping_weight'           => array(
				'label' => __( 'Shipping weight', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function limits() {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_additional_images() {
		return 10;
	}

	/**
	 * Readable availability (in stock, out of stock, backorder, preorder).
	 *
	 * @param string $availability Normalized availability.
	 * @return string
	 */
	public function availability( $availability ) {
		return str_replace( '_', ' ', (string) $availability );
	}

	/**
	 * Prices are plain numbers.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency ISO 4217 code (in the currency column).
	 * @return string
	 */
	public function price( $amount, $currency ) {
		return \Oliprfe\Util::money( $amount );
	}

	/**
	 * Adds the currency column.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public function normalize( array $item, array $feed ) {
		$item['currency'] = $feed['currency'];
		return parent::normalize( $item, $feed );
	}

	/**
	 * Configured columns: [ [ name, field ], … ] (every field under its own name when none are set).
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<int,array<int,string>>
	 */
	public function column_map( array $feed ) {
		$map = array();
		if ( ! empty( $feed['columns'] ) ) {
			foreach ( $feed['columns'] as $column ) {
				$map[] = array( $column['name'], $column['field'] );
			}
			return $map;
		}
		foreach ( parent::columns( $feed ) as $field ) {
			$map[] = array( $field, $field );
		}
		return $map;
	}

	/**
	 * Column names.
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return string[]
	 */
	public function columns( array $feed ) {
		return wp_list_pluck( $this->column_map( $feed ), 0 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function header( array $feed, $format ) {
		if ( 'xml' !== $format ) {
			return $this->delimited_line( $this->columns( $feed ), $format );
		}
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<' . self::xml_name( $feed['xml_root'], 'products' ) . ">\n";
	}

	/**
	 * {@inheritDoc}
	 */
	public function item( array $row, array $feed, $format ) {
		$map = $this->column_map( $feed );
		if ( 'xml' !== $format ) {
			$cells = array();
			foreach ( $map as $column ) {
				$value   = isset( $row[ $column[1] ] ) ? $row[ $column[1] ] : '';
				$cells[] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			}
			return $this->delimited_line( $cells, $format );
		}
		$tag = self::xml_name( $feed['xml_item'], 'product' );
		$out = "\t<" . $tag . ">\n";
		foreach ( $map as $column ) {
			if ( ! isset( $row[ $column[1] ] ) ) {
				continue;
			}
			$name = self::xml_name( $column[0], 'field' );
			foreach ( (array) $row[ $column[1] ] as $single ) {
				$single = (string) $single;
				$body   = preg_match( '/[&<>]/', $single ) ? self::cdata( $single ) : self::xml_text( $single );
				$out   .= "\t\t<" . $name . '>' . $body . '</' . $name . ">\n";
			}
		}
		return $out . "\t</" . $tag . ">\n";
	}

	/**
	 * {@inheritDoc}
	 */
	public function footer( array $feed, $format ) {
		return 'xml' === $format ? '</' . self::xml_name( $feed['xml_root'], 'products' ) . ">\n" : '';
	}

	/**
	 * Valid XML element name.
	 *
	 * @param string $name     Wanted name.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function xml_name( $name, $fallback ) {
		$name = preg_replace( '/[^A-Za-z0-9_.\-]/', '_', (string) $name );
		if ( '' === $name || ! preg_match( '/^[A-Za-z_]/', $name ) || 0 === stripos( $name, 'xml' ) ) {
			return $fallback;
		}
		return $name;
	}
}
