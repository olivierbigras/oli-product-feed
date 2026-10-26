<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * TikTok catalog channel (TikTok Ads Manager catalog product parameters: CSV/TSV with a sku_id column,
 * or RSS XML with Google attributes).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * TikTok catalogs.
 */
class TikTok extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'tiktok';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'TikTok Catalog', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'TikTok Ads Manager: Assets > Catalogs > your catalog > Add products > Data feed > paste this URL with a daily schedule.', 'oli-product-feed' );
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
			'availability'              => array(
				'label' => __( 'Availability', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'condition'                 => array(
				'label' => __( 'Condition', 'oli-product-feed' ),
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
			'brand'                     => array(
				'label' => __( 'Brand', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'gtin'                      => array(
				'label' => __( 'GTIN', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
			),
			'mpn'                       => array(
				'label' => __( 'MPN', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
			),
			'google_product_category'   => array(
				'label' => __( 'Google product category', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
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
			'video_link'                => array(
				'label'       => __( 'Video link', 'oli-product-feed' ),
				'level'       => self::OPTIONAL,
				'mapped_only' => true,
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
	 * The product ID column is named sku_id in delimited files (g:id in XML).
	 *
	 * @param string $field  Field.
	 * @param string $format csv|tsv|txt.
	 * @return string
	 */
	public function column_name( $field, $format ) {
		return 'id' === $field ? 'sku_id' : $field;
	}

	/**
	 * {@inheritDoc}
	 */
	public function limits() {
		return array(
			'id'          => 100,
			'title'       => 150,
			'description' => 10000,
		);
	}

	/**
	 * TikTok values: in stock, available for order, preorder, out of stock, discontinued.
	 * A backorder can still be ordered: "available for order".
	 *
	 * @param string $availability Normalized availability.
	 * @return string
	 */
	public function availability( $availability ) {
		$map = array(
			'in_stock'     => 'in stock',
			'out_of_stock' => 'out of stock',
			'backorder'    => 'available for order',
			'preorder'     => 'preorder',
		);
		return isset( $map[ $availability ] ) ? $map[ $availability ] : 'out of stock';
	}

	/**
	 * TikTok has no identifier_exists attribute.
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
