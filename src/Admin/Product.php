<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Optional backorder availability override on the product edit screen (and variations).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Availability;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce inventory fields.
 */
final class Product {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	/**
	 * Field on the Inventory tab (simple / parent product).
	 */
	public static function fields() {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		$product_id = get_the_ID();
		$value      = $product_id ? get_post_meta( (int) $product_id, Availability::META, true ) : '';
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => 'oliprfe_availability',
				'label'       => __( 'Backorder availability', 'oli-product-feed' ),
				'description' => __( 'Optional override for product feeds: a number of days (for example 21) or a fixed date (YYYY-MM-DD). Leave empty to use the feed lead time.', 'oli-product-feed' ),
				'desc_tip'    => true,
				'placeholder' => '21',
				'value'       => is_scalar( $value ) ? (string) $value : '',
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the parent / simple product field.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save( $product ) {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		if ( ! isset( $_POST['oliprfe_availability'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product save nonce.
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}
		$value = Availability::sanitize_override( wp_unslash( $_POST['oliprfe_availability'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Availability::sanitize_override().
		if ( '' === $value ) {
			$product->delete_meta_data( Availability::META );
		} else {
			$product->update_meta_data( Availability::META, $value );
		}
	}

	/**
	 * Field on each variation.
	 *
	 * @param int      $loop           Variation index.
	 * @param array    $variation_data Variation data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function variation_fields( $loop, $variation_data, $variation ) {
		unset( $variation_data );
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		$id    = is_object( $variation ) ? (int) $variation->ID : 0;
		$value = $id ? get_post_meta( $id, Availability::META, true ) : '';
		woocommerce_wp_text_input(
			array(
				'id'            => 'oliprfe_availability_var_' . (int) $loop,
				'name'          => 'oliprfe_availability_var[' . (int) $loop . ']',
				'value'         => is_scalar( $value ) ? (string) $value : '',
				'label'         => __( 'Backorder availability', 'oli-product-feed' ),
				'description'   => __( 'Days or a fixed date (YYYY-MM-DD). Empty: use the parent or the feed lead time.', 'oli-product-feed' ),
				'desc_tip'      => true,
				'placeholder'   => '21',
				'wrapper_class' => 'form-row form-row-full',
			)
		);
	}

	/**
	 * Saves a variation field.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Variation index.
	 */
	public static function save_variation( $variation_id, $loop ) {
		$variation_id = (int) $variation_id;
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}
		if ( ! isset( $_POST['oliprfe_availability_var'][ $loop ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variation save nonce.
			return;
		}
		$value = Availability::sanitize_override( wp_unslash( $_POST['oliprfe_availability_var'][ $loop ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Availability::sanitize_override().
		if ( '' === $value ) {
			delete_post_meta( $variation_id, Availability::META );
		} else {
			update_post_meta( $variation_id, Availability::META, $value );
		}
	}
}
