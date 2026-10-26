<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Pinterest catalog channel (Pinterest data source specification: CSV, TSV or RSS XML with Google attributes).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Pinterest catalogs.
 */
class Pinterest extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'pinterest';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Pinterest Catalog', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'Pinterest business account: Catalogs > Data sources > Add data source > paste this URL.', 'oli-product-feed' );
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
				'level' => self::RECOMMENDED,
			),
			'title'                     => array(
				'label' => __( 'Title', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'description'               => array(
				'label' => __( 'Description', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'link'                      => array(
				'label' => __( 'Link', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'image_link'                => array(
				'label' => __( 'Image link', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'additional_image_link'     => array(
				'label' => __( 'Additional image links', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'availability'              => array(
				'label' => __( 'Availability', 'oli-product-feed' ),
				'level' => self::REQUIRED,
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
			'brand'                     => array(
				'label' => __( 'Brand', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
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
				'level' => self::RECOMMENDED,
			),
			'google_product_category'   => array(
				'label' => __( 'Google product category', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
			),
			'product_type'              => array(
				'label' => __( 'Product type', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
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
			'custom_label_0'            => array(
				'label' => __( 'Custom label 0', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'custom_label_1'            => array(
				'label' => __( 'Custom label 1', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'custom_label_2'            => array(
				'label' => __( 'Custom label 2', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'custom_label_3'            => array(
				'label' => __( 'Custom label 3', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'custom_label_4'            => array(
				'label' => __( 'Custom label 4', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function limits() {
		return array(
			'id'          => 127,
			'title'       => 500,
			'description' => 10000,
		);
	}

	/**
	 * Pinterest accepts "in stock", "out of stock" and "preorder". A backorder is still orderable: "preorder".
	 *
	 * @param string $availability Normalized availability.
	 * @return string
	 */
	public function availability( $availability ) {
		$map = array(
			'in_stock'     => 'in stock',
			'out_of_stock' => 'out of stock',
			'backorder'    => 'preorder',
			'preorder'     => 'preorder',
		);
		return isset( $map[ $availability ] ) ? $map[ $availability ] : 'out of stock';
	}

	/**
	 * Pinterest has no identifier_exists attribute.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public function normalize( array $item, array $feed ) {
		unset( $item['identifier_exists'] );
		return parent::normalize( $item, $feed );
	}
}
