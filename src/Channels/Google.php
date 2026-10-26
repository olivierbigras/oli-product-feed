<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Google Merchant Center channel (product data specification, RSS 2.0 / TSV / CSV).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Google Merchant Center.
 */
class Google extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'google';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Google Merchant Center', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'Merchant Center: Products > Data sources > Add product source > Add products from a file > enter this URL with a daily fetch.', 'oli-product-feed' );
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
			'availability_date'         => array(
				'label' => __( 'Availability date', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'min_handling_time'         => array(
				'label' => __( 'Min handling time (days)', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'max_handling_time'         => array(
				'label' => __( 'Max handling time (days)', 'oli-product-feed' ),
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
			'identifier_exists'         => array(
				'label' => __( 'Identifier exists', 'oli-product-feed' ),
				'level' => self::OPTIONAL,
			),
			'condition'                 => array(
				'label' => __( 'Condition', 'oli-product-feed' ),
				'level' => self::REQUIRED,
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
	public function validate( array $row ) {
		$missing = array();
		foreach ( $this->fields() as $field => $def ) {
			if ( self::REQUIRED === $def['level'] && empty( $row[ $field ] ) ) {
				$missing[] = $field;
			}
		}
		// Unique product identifiers: a GTIN, or an MPN, or identifier_exists = no.
		if ( empty( $row['gtin'] ) && empty( $row['mpn'] ) && ( empty( $row['identifier_exists'] ) || 'no' !== $row['identifier_exists'] ) ) {
			$missing[] = 'gtin|mpn';
		}
		return $missing;
	}
}
