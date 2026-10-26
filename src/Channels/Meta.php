<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Meta catalog channel (Facebook and Instagram shops and ads: CSV, TSV or RSS XML with Google attributes).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Meta (Facebook, Instagram) catalogs.
 */
class Meta extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'meta';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Meta Catalog (Facebook, Instagram)', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'Commerce Manager: Catalog > Data sources > Data feed > Scheduled feed > paste this URL.', 'oli-product-feed' );
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
			'availability_date'         => array(
				'label' => __( 'Availability date', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'condition'                 => array(
				'label' => __( 'Condition', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'price'                     => array(
				'label' => __( 'Price', 'oli-product-feed' ),
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
			'brand'                     => array(
				'label' => __( 'Brand', 'oli-product-feed' ),
				'level' => self::REQUIRED,
			),
			'additional_image_link'     => array(
				'label' => __( 'Additional image links', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'sale_price'                => array(
				'label' => __( 'Sale price', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'sale_price_effective_date' => array(
				'label' => __( 'Sale price effective date', 'oli-product-feed' ),
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
			'google_product_category'   => array(
				'label' => __( 'Google product category', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
			),
			'fb_product_category'       => array(
				'label' => __( 'Facebook product category', 'oli-product-feed' ),
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
			'status'                    => array(
				'label' => __( 'Status (active, archived)', 'oli-product-feed' ),
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
			'id'          => 100,
			'title'       => 200,
			'description' => 9999,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function max_additional_images() {
		return 20;
	}

	/**
	 * Meta supports "in stock" and "out of stock". Orderable items (backorder, preorder) are "in stock".
	 *
	 * @param string $availability Normalized availability.
	 * @return string
	 */
	public function availability( $availability ) {
		return in_array( $availability, array( 'in_stock', 'backorder', 'preorder' ), true ) ? 'in stock' : 'out of stock';
	}

	/**
	 * Meta needs a brand, or else a GTIN or an MPN.
	 *
	 * @param array<string,mixed> $row Channel row.
	 * @return string[]
	 */
	public function validate( array $row ) {
		$missing = array();
		foreach ( $this->fields() as $field => $def ) {
			if ( self::REQUIRED === $def['level'] && 'brand' !== $field && empty( $row[ $field ] ) ) {
				$missing[] = $field;
			}
		}
		if ( empty( $row['brand'] ) && empty( $row['gtin'] ) && empty( $row['mpn'] ) ) {
			$missing[] = 'brand|gtin|mpn';
		}
		return $missing;
	}

	/**
	 * Meta has no identifier_exists attribute.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public function normalize( array $item, array $feed ) {
		unset( $item['identifier_exists'] );
		$row = parent::normalize( $item, $feed );
		if ( ! empty( $item['availability_date'] ) && isset( $row['availability'] ) && 'in stock' === $row['availability']
			&& in_array( $item['availability'], array( 'backorder', 'preorder' ), true ) ) {
			$row['availability'] = 'available for order';
		}
		return $row;
	}
}
