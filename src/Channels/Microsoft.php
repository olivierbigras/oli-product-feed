<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Microsoft Merchant Center channel (Microsoft Advertising "Product attributes": tab-delimited TXT file,
 * or a Google-formatted RSS XML file).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Microsoft (Bing) Merchant Center.
 */
class Microsoft extends Channel {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'microsoft';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return __( 'Microsoft Merchant Center (Bing)', 'oli-product-feed' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function help() {
		return __( 'Microsoft Merchant Center: your store > Catalog management > Feeds > Create feed > Scheduled download > paste this URL.', 'oli-product-feed' );
	}

	/**
	 * Tab-delimited text (.txt) first; XML only in the Google format.
	 *
	 * @return string[]
	 */
	public function formats() {
		return array( 'txt', 'xml' );
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
			'availability'              => array(
				'label' => __( 'Availability', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
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
			'condition'                 => array(
				'label' => __( 'Condition', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
			),
			'brand'                     => array(
				'label' => __( 'Brand', 'oli-product-feed' ),
				'level' => self::RECOMMENDED,
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
			'google_product_category'   => array(
				'label' => __( 'Product category (Google taxonomy ID)', 'oli-product-feed' ),
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
			'shipping'                  => array(
				'label'       => __( 'Shipping (country:service:price)', 'oli-product-feed' ),
				'level'       => self::OPTIONAL,
				'mapped_only' => true,
			),
			'seller_name'               => array(
				'label'       => __( 'Seller name (aggregators)', 'oli-product-feed' ),
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
	 * Text files use the Microsoft column name product_category (Google taxonomy IDs are accepted).
	 *
	 * @param string $field  Field.
	 * @param string $format csv|tsv|txt.
	 * @return string
	 */
	public function column_name( $field, $format ) {
		return 'google_product_category' === $field ? 'product_category' : $field;
	}

	/**
	 * {@inheritDoc}
	 */
	public function limits() {
		return array(
			'id'          => 50,
			'title'       => 150,
			'description' => 10000,
			'brand'       => 70,
		);
	}

	/**
	 * Microsoft values: in stock, out of stock, preorder. A backorder is still orderable: "preorder".
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
	 * Text files: identifier_exists is TRUE/FALSE and sale_price is a number without currency.
	 *
	 * @param array<string,mixed> $item Normalized item.
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return array<string,mixed>
	 */
	public function normalize( array $item, array $feed ) {
		$row = parent::normalize( $item, $feed );
		if ( 'xml' !== $feed['format'] ) {
			if ( isset( $row['identifier_exists'] ) ) {
				$row['identifier_exists'] = in_array( strtolower( (string) $row['identifier_exists'] ), array( 'no', 'false', '0' ), true ) ? 'FALSE' : 'TRUE';
			}
			if ( isset( $row['sale_price'] ) && null !== $item['sale_price'] ) {
				$row['sale_price'] = \Oliprfe\Util::money( $item['sale_price'] );
			}
		}
		return $row;
	}
}
