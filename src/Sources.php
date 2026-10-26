<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Value sources used by the attribute mapping.
 *
 * A source spec is an array: { type: field|meta|taxonomy|attribute|static|none, key, value, mode, parent, prefix, suffix,
 * translate, fallback, map }. "none" disables a default mapping of the channel; "map" rewrites values (from => to);
 * "fallback" is used when the value is empty.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves mapping sources for a product.
 */
final class Sources {

	/**
	 * True when the last resolve() skipped several values (option "single").
	 *
	 * @var bool
	 */
	public static $multiple = false;

	/**
	 * Product fields available as "field" sources.
	 *
	 * @return array<string,string>
	 */
	public static function fields() {
		return array(
			'id'                => __( 'ID', 'oli-product-feed' ),
			'parent_id'         => __( 'Parent ID', 'oli-product-feed' ),
			'sku'               => __( 'SKU', 'oli-product-feed' ),
			'parent_sku'        => __( 'Parent SKU', 'oli-product-feed' ),
			'title'             => __( 'Title', 'oli-product-feed' ),
			'description'       => __( 'Description', 'oli-product-feed' ),
			'short_description' => __( 'Short description', 'oli-product-feed' ),
			'link'              => __( 'Product URL', 'oli-product-feed' ),
			'gtin'              => __( 'GTIN, UPC, EAN or ISBN (WooCommerce)', 'oli-product-feed' ),
			'weight'            => __( 'Weight', 'oli-product-feed' ),
			'length'            => __( 'Length', 'oli-product-feed' ),
			'width'             => __( 'Width', 'oli-product-feed' ),
			'height'            => __( 'Height', 'oli-product-feed' ),
			'stock_quantity'    => __( 'Stock quantity', 'oli-product-feed' ),
			'type'              => __( 'Product type (simple, variation…)', 'oli-product-feed' ),
		);
	}

	/**
	 * Sanitizes a source spec.
	 *
	 * @param mixed $spec Raw spec.
	 * @return array<string,mixed>|null
	 */
	public static function sanitize_spec( $spec ) {
		if ( ! is_array( $spec ) || empty( $spec['type'] ) ) {
			return null;
		}
		$type = sanitize_key( $spec['type'] );
		if ( ! in_array( $type, array( 'field', 'meta', 'taxonomy', 'attribute', 'static', 'item', 'none' ), true ) ) {
			return null;
		}
		$mode = isset( $spec['mode'] ) ? sanitize_key( $spec['mode'] ) : 'first';
		$map  = array();
		if ( isset( $spec['map'] ) && is_array( $spec['map'] ) ) {
			foreach ( $spec['map'] as $from => $to ) {
				$map[ sanitize_text_field( (string) $from ) ] = sanitize_text_field( (string) $to );
			}
		}
		return array(
			'type'      => $type,
			'key'       => isset( $spec['key'] ) ? sanitize_text_field( (string) $spec['key'] ) : '',
			'value'     => isset( $spec['value'] ) ? sanitize_text_field( (string) $spec['value'] ) : '',
			'mode'      => in_array( $mode, array( 'first', 'all', 'path', 'top' ), true ) ? $mode : 'first',
			'parent'    => ! empty( $spec['parent'] ),
			'prefix'    => isset( $spec['prefix'] ) ? sanitize_text_field( (string) $spec['prefix'] ) : '',
			'suffix'    => isset( $spec['suffix'] ) ? sanitize_text_field( (string) $spec['suffix'] ) : '',
			'translate' => ! empty( $spec['translate'] ),
			'fallback'  => isset( $spec['fallback'] ) ? sanitize_text_field( (string) $spec['fallback'] ) : '',
			'map'       => $map,
			'single'    => ! empty( $spec['single'] ),
		);
	}

	/**
	 * Resolves a source spec to a string.
	 *
	 * @param array<string,mixed> $spec    Sanitized spec.
	 * @param \WC_Product         $product Product or variation.
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @return string
	 */
	public static function resolve( array $spec, $product, array $feed ) {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$value  = '';

		self::$multiple = false;
		if ( ! empty( $spec['single'] ) && self::count_values( $spec, $product, $parent ) > 1 ) {
			// One value expected (e.g. size): several values are not sent.
			self::$multiple = true;
			return '';
		}

		switch ( $spec['type'] ) {
			case 'none':
			case 'item':
				// "item" copies another final feed value: see from_item(), applied once the item is built.
				return '';
			case 'static':
				$value = $spec['value'];
				break;
			case 'field':
				$value = self::field( $spec['key'], $product, $parent );
				break;
			case 'meta':
				$value = self::scalar( get_post_meta( $product->get_id(), $spec['key'], true ) );
				if ( '' === $value && $spec['parent'] && $parent ) {
					$value = self::scalar( get_post_meta( $parent->get_id(), $spec['key'], true ) );
				}
				break;
			case 'taxonomy':
				$value = self::taxonomy( $spec['key'], $spec['mode'], $parent ? $parent : $product, $feed, $spec['translate'] );
				break;
			case 'attribute':
				$value = self::attribute( $spec['key'], $product, $parent );
				break;
		}

		$value = trim( $value );
		if ( ! empty( $spec['map'] ) && isset( $spec['map'][ $value ] ) ) {
			$value = $spec['map'][ $value ];
		}
		if ( '' === $value && ! empty( $spec['fallback'] ) ) {
			$value = $spec['fallback'];
		}
		if ( '' !== $value && $spec['translate'] && 'taxonomy' !== $spec['type'] ) {
			$value = Lang::translate( $value, $feed['language'] );
		}
		if ( '' !== $value ) {
			$value = $spec['prefix'] . $value . $spec['suffix'];
		}
		return $value;
	}

	/**
	 * Value of an "item" source: another field of the built item (final value, after the brand
	 * detection and the field rules), with the rewrite, fallback, prefix and suffix options.
	 *
	 * @param array<string,mixed> $spec Sanitized spec.
	 * @param array<string,mixed> $item Built item.
	 * @return string
	 */
	public static function from_item( array $spec, array $item ) {
		$value = isset( $item[ $spec['key'] ] ) && is_scalar( $item[ $spec['key'] ] ) ? trim( (string) $item[ $spec['key'] ] ) : '';
		if ( ! empty( $spec['map'] ) && isset( $spec['map'][ $value ] ) ) {
			$value = $spec['map'][ $value ];
		}
		if ( '' === $value && ! empty( $spec['fallback'] ) ) {
			$value = $spec['fallback'];
		}
		return '' !== $value ? $spec['prefix'] . $value . $spec['suffix'] : '';
	}

	/**
	 * Number of values of a taxonomy or attribute source (variation value counts as one).
	 *
	 * @param array<string,mixed> $spec    Spec.
	 * @param \WC_Product         $product Product or variation.
	 * @param \WC_Product|null    $parent  Parent.
	 * @return int
	 */
	private static function count_values( array $spec, $product, $parent ) {
		if ( 'taxonomy' === $spec['type'] ) {
			$terms = taxonomy_exists( $spec['key'] ) ? get_the_terms( ( $parent ? $parent : $product )->get_id(), $spec['key'] ) : array();
			return is_array( $terms ) ? count( $terms ) : 0;
		}
		if ( 'attribute' === $spec['type'] ) {
			$spec['key'] = self::attribute_key( $spec['key'], $product, $parent );
			if ( '' === $spec['key'] ) {
				return 0;
			}
			if ( $product->is_type( 'variation' ) && '' !== $product->get_attribute( $spec['key'] ) ) {
				return 1;
			}
			$holder = $parent ? $parent : $product;
			foreach ( $holder->get_attributes() as $name => $attribute ) {
				if ( is_object( $attribute ) && ( $spec['key'] === $name || sanitize_title( $spec['key'] ) === $name || $spec['key'] === $attribute->get_name() ) ) {
					return count( $attribute->get_options() );
				}
			}
		}
		return 0;
	}

	/**
	 * Product field value.
	 *
	 * @param string           $key     Field key.
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent product for variations.
	 * @return string
	 */
	private static function field( $key, $product, $parent ) {
		switch ( $key ) {
			case 'id':
				return (string) $product->get_id();
			case 'parent_id':
				return $parent ? (string) $parent->get_id() : '';
			case 'sku':
				return (string) $product->get_sku( 'edit' );
			case 'parent_sku':
				return $parent ? (string) $parent->get_sku( 'edit' ) : '';
			case 'title':
				return (string) $product->get_name( 'edit' );
			case 'description':
				return (string) $product->get_description( 'edit' );
			case 'short_description':
				return (string) ( $parent ? $parent->get_short_description( 'edit' ) : $product->get_short_description( 'edit' ) );
			case 'link':
				return (string) $product->get_permalink();
			case 'gtin':
				return method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id( 'edit' ) : '';
			case 'weight':
				return (string) $product->get_weight();
			case 'length':
				return (string) $product->get_length();
			case 'width':
				return (string) $product->get_width();
			case 'height':
				return (string) $product->get_height();
			case 'stock_quantity':
				$qty = $product->get_stock_quantity();
				return null === $qty ? '' : (string) $qty;
			case 'type':
				return (string) $product->get_type();
		}
		return '';
	}

	/**
	 * Taxonomy terms.
	 *
	 * @param string              $taxonomy  Taxonomy.
	 * @param string              $mode      first|all|path.
	 * @param \WC_Product         $product   Product holding the terms (parent for variations).
	 * @param array<string,mixed> $feed      Feed configuration.
	 * @param bool                $translate Translate term names.
	 * @return string
	 */
	public static function taxonomy( $taxonomy, $mode, $product, array $feed, $translate = false ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$terms = get_the_terms( $product->get_id(), $taxonomy );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return '';
		}
		$name = static function ( $term ) use ( $translate, $feed ) {
			// Translate the stored name (TranslatePress keys its dictionary on the encoded string), then decode.
			$label = $translate ? Lang::translate( $term->name, $feed['language'] ) : $term->name;
			return html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		};

		if ( 'path' === $mode || 'top' === $mode ) {
			$deepest = Util::primary_term( $product->get_id(), $terms, $taxonomy );
			$chain   = array_reverse( get_ancestors( $deepest->term_id, $taxonomy, 'taxonomy' ) );
			$chain[] = $deepest->term_id;
			$parts   = array();
			foreach ( $chain as $term_id ) {
				$term = get_term( $term_id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$parts[] = $name( $term );
				}
				if ( 'top' === $mode ) {
					break;
				}
			}
			return implode( ' > ', $parts );
		}
		if ( 'all' === $mode ) {
			return implode( ', ', array_map( $name, $terms ) );
		}
		return $name( reset( $terms ) );
	}

	/**
	 * Product attribute value (variation attribute first, then the product's attribute).
	 *
	 * @param string           $key     Attribute name (pa_color or custom name).
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent product.
	 * @return string
	 */
	private static function attribute( $key, $product, $parent ) {
		$key = self::attribute_key( $key, $product, $parent );
		if ( '' === $key ) {
			return '';
		}
		if ( $product->is_type( 'variation' ) ) {
			$value = $product->get_attribute( $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$holder = $parent ? $parent : $product;
		return (string) $holder->get_attribute( $key );
	}

	/**
	 * First attribute of a "a|b|c" list that the product (or its variation) has; the key itself otherwise.
	 *
	 * @param string           $key     Attribute name, or alternatives separated by |.
	 * @param \WC_Product      $product Product or variation.
	 * @param \WC_Product|null $parent  Parent.
	 * @return string
	 */
	private static function attribute_key( $key, $product, $parent ) {
		if ( false === strpos( $key, '|' ) ) {
			return $key;
		}
		$holder = $parent ? $parent : $product;
		$names  = array();
		foreach ( $holder->get_attributes() as $name => $attribute ) {
			$names[] = strtolower( (string) $name );
			if ( is_object( $attribute ) ) {
				$names[] = strtolower( (string) $attribute->get_name() );
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( '|', $key ) ) ) as $candidate ) {
			if ( in_array( strtolower( $candidate ), $names, true ) || in_array( sanitize_title( $candidate ), $names, true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Converts a meta value to a string.
	 *
	 * @param mixed $value Meta value.
	 * @return string
	 */
	private static function scalar( $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}
}
