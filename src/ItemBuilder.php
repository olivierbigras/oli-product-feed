<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Builds the normalized item of a product for a feed, then hands it to the channel.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

use Oliprfe\Channels\Channel;

defined( 'ABSPATH' ) || exit;

/**
 * Product → channel row, or an exclusion reason.
 */
final class ItemBuilder {

	/**
	 * Feed configuration.
	 *
	 * @var array<string,mixed>
	 */
	private $feed;

	/**
	 * Channel.
	 *
	 * @var Channel
	 */
	private $channel;

	/**
	 * Effective mapping (channel defaults + feed mapping).
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $mapping;

	/**
	 * Names of every brand term (cache).
	 *
	 * @var string[]|null
	 */
	private $brand_names = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $feed    Feed configuration.
	 * @param Channel             $channel Channel.
	 */
	public function __construct( array $feed, Channel $channel ) {
		$this->feed    = $feed;
		$this->channel = $channel;
		$mapping       = array();
		foreach ( $channel->default_mapping() as $field => $spec ) {
			$spec = Sources::sanitize_spec( $spec );
			if ( $spec ) {
				$mapping[ $field ] = $spec;
			}
		}
		$this->mapping = array_merge( $mapping, $feed['mapping'] );
	}

	/**
	 * Builds one row.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array{row:array<string,mixed>|null,reason:string,warnings:string[]}
	 */
	public function build( $product_id ) {
		$warnings = array();
		$product  = wc_get_product( $product_id );
		if ( ! $product ) {
			return self::excluded( 'invalid_product' );
		}
		$parent = null;
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'publish' !== $parent->get_status( 'edit' ) ) {
				return self::excluded( 'parent_not_published' );
			}
		}
		$holder = $parent ? $parent : $product;
		$feed   = $this->feed;

		if ( '' !== get_post_field( 'post_password', $holder->get_id() ) ) {
			return self::excluded( 'password_protected' );
		}
		foreach ( $feed['exclude_rules'] as $rule ) {
			if ( $this->meta_matches( $product, $parent, $rule['meta'], $rule['value'] ) ) {
				return self::excluded( 'rule:' . $rule['meta'] );
			}
		}
		$stock_status = $product->get_stock_status( 'edit' );
		if ( ! in_array( $stock_status, $feed['filters']['stock_statuses'], true ) ) {
			return self::excluded( 'stock_status' );
		}
		$raw_title = $holder->get_name( 'edit' );
		if ( '' !== $feed['filters']['title_contains'] && false === mb_stripos( $raw_title, $feed['filters']['title_contains'] ) ) {
			return self::excluded( 'title_filter' );
		}
		if ( '' !== $feed['filters']['title_not_contains'] && false !== mb_stripos( $raw_title, $feed['filters']['title_not_contains'] ) ) {
			return self::excluded( 'title_filter' );
		}

		/**
		 * Filters whether a product goes in a feed.
		 *
		 * @param bool        $include Include the product.
		 * @param \WC_Product $product Product or variation.
		 * @param array       $feed    Feed configuration.
		 */
		if ( ! apply_filters( 'oliprfe_include_product', true, $product, $feed ) ) {
			return self::excluded( 'filtered' );
		}

		$prices = Pricing::get( $product, $feed );
		if ( null === $prices ) {
			return self::excluded( 'no_price' );
		}
		// Price range on the price shown to customers (sale price included).
		$shown = Pricing::displayed( $prices );
		if ( ( '' !== $feed['filters']['price_min'] && $shown < (float) $feed['filters']['price_min'] ) || ( '' !== $feed['filters']['price_max'] && $shown > (float) $feed['filters']['price_max'] ) ) {
			return self::excluded( 'price_range' );
		}
		if ( Pricing::below_min( $prices, $feed, $product ) ) {
			return self::excluded( 'min_price' );
		}

		$item = array(
			'id'            => '',
			'item_group_id' => '',
		);

		// Mapped fields first (id, brand, identifiers and every extra field of the feed).
		foreach ( $this->mapping as $field => $spec ) {
			$value          = Sources::resolve( $spec, $product, $feed );
			$item[ $field ] = in_array( $field, array( 'link', 'image_link' ), true ) ? $value : Util::clean_text( $value );
			if ( Sources::$multiple ) {
				$warnings[] = 'multiple_values_' . $field;
			}
		}
		if ( isset( $this->mapping['brand'] ) && 'taxonomy' === $this->mapping['brand']['type'] ) {
			$brand = $this->brand( $holder, $item['brand'], $warnings );
			if ( null !== $brand ) {
				$item['brand'] = $brand;
			}
		}
		if ( $parent && isset( $this->mapping['id'] ) ) {
			$item['item_group_id'] = Util::clean_text( Sources::resolve( $this->mapping['id'], $parent, $feed ) );
		}
		if ( $parent && '' === $item['item_group_id'] ) {
			// The parent has no value for the ID source (e.g. no SKU): its post ID keeps the group stable.
			$item['item_group_id'] = (string) $parent->get_id();
		}

		if ( empty( $item['title'] ) ) {
			$item['title'] = $this->title( $product, $parent, isset( $item['brand'] ) ? $item['brand'] : '' );
		}
		if ( empty( $item['description'] ) ) {
			$item['description'] = $this->description( $product, $parent );
		}
		if ( empty( $item['link'] ) ) {
			$item['link'] = $this->link( $product );
		}

		$images = $this->images( $product, $parent );
		if ( empty( $item['image_link'] ) ) {
			$item['image_link'] = $images['main'];
		}
		$item['additional_image_link'] = $images['additional'];

		$item['availability'] = $this->availability( $stock_status, $product, $parent );
		$this->apply_availability_extras( $item, $product, $parent );
		$item['price']      = $prices['regular'];
		$item['sale_price'] = $prices['sale'];
		$item['sale_from']  = $prices['sale_from'];
		$item['sale_to']    = $prices['sale_to'];

		if ( empty( $item['condition'] ) ) {
			$item['condition'] = 'new';
		}
		if ( empty( $item['product_type'] ) ) {
			$item['product_type'] = Sources::taxonomy( 'product_cat', 'path', $holder, $feed, true );
		}
		if ( $this->channel->uses_google_categories() && empty( $item['google_product_category'] ) ) {
			$item['google_product_category'] = $this->google_category( $product, $holder );
		}
		foreach ( $feed['field_rules'] as $rule ) {
			if ( ! empty( $rule['active'] ) && empty( $item[ $rule['field'] ] ) && $this->rule_applies( $rule, $holder, $item ) ) {
				$item[ $rule['field'] ] = $this->rule_value( $rule, $product, $holder );
			}
		}
		$item = $this->apply_overrides( $item, $product, $parent );
		// Fields copied from another final value (e.g. custom_label_0 = final brand).
		foreach ( $this->mapping as $field => $spec ) {
			if ( 'item' === $spec['type'] && $field !== $spec['key'] ) {
				$item[ $field ] = Util::clean_text( Sources::from_item( $spec, $item ) );
			}
		}

		if ( ! empty( $item['gtin'] ) ) {
			$gtin = preg_replace( '/[\s\-]/', '', $item['gtin'] );
			if ( Util::is_valid_gtin( $gtin ) ) {
				$item['gtin'] = $gtin;
			} else {
				$warnings[]   = 'invalid_gtin:' . $item['gtin'];
				$item['gtin'] = '';
			}
		}
		if ( ! empty( $item['mpn'] ) ) {
			$brands = $feed['mpn_brands'];
			if ( ! empty( $brands ) && ! in_array( mb_strtolower( isset( $item['brand'] ) ? $item['brand'] : '' ), array_map( 'mb_strtolower', $brands ), true ) ) {
				$item['mpn'] = '';
			} elseif ( ! empty( $feed['mpn_only_without_gtin'] ) && ! empty( $item['gtin'] ) ) {
				$item['mpn'] = '';
			}
		}
		if ( empty( $item['gtin'] ) && empty( $item['mpn'] ) && empty( $item['identifier_exists'] ) ) {
			$item['identifier_exists'] = 'no';
		}
		if ( empty( $item['brand'] ) ) {
			$warnings[] = 'no_brand';
		}

		foreach ( $feed['filters']['require_fields'] as $field ) {
			if ( empty( $item[ $field ] ) ) {
				return self::excluded( 'empty:' . $field, $warnings );
			}
		}

		/**
		 * Filters the normalized item before the channel rules. Return null to exclude it.
		 *
		 * @param array|null  $item    Normalized item.
		 * @param \WC_Product $product Product or variation.
		 * @param array       $feed    Feed configuration.
		 */
		$item = apply_filters( 'oliprfe_item', $item, $product, $feed );
		if ( ! is_array( $item ) ) {
			return self::excluded( 'filtered', $warnings );
		}

		$args = array_merge(
			isset( $feed['link_args'] ) && is_array( $feed['link_args'] ) ? $feed['link_args'] : array(),
			isset( $feed['utm'] ) && is_array( $feed['utm'] ) ? $feed['utm'] : array()
		);
		/**
		 * Filters the query arguments added to each item link (g:link) and to mobile_link.
		 * Return an empty array to add nothing. Not applied to image_link.
		 *
		 * @param array       $args    Arguments (key => value).
		 * @param \WC_Product $product Product or variation.
		 * @param array       $feed    Feed configuration.
		 */
		$args = apply_filters( 'oliprfe_link_args', $args, $product, $feed );
		$item = Util::apply_feed_link_args( $item, is_array( $args ) ? $args : array() );

		$row     = $this->channel->normalize( $item, $feed );
		$missing = $this->channel->validate( $row );
		if ( ! empty( $missing ) ) {
			return self::excluded( 'missing:' . implode( ',', $missing ), $warnings );
		}
		return array(
			'row'      => $row,
			'reason'   => '',
			'warnings' => $warnings,
		);
	}

	/**
	 * Per-product values set in the feed (item_overrides), product ID or parent ID.
	 *
	 * @param array<string,mixed> $item    Item.
	 * @param \WC_Product         $product Product or variation.
	 * @param \WC_Product|null    $parent  Parent.
	 * @return array<string,mixed>
	 */
	private function apply_overrides( array $item, $product, $parent ) {
		if ( empty( $this->feed['item_overrides'] ) ) {
			return $item;
		}
		foreach ( $this->feed['item_overrides'] as $override ) {
			$id = (int) $override['product'];
			if ( $id === $product->get_id() || ( $parent && $id === $parent->get_id() ) ) {
				$item[ $override['field'] ] = $override['value'];
			}
		}
		return $item;
	}

	/**
	 * Exclusion result.
	 *
	 * @param string   $reason   Reason code.
	 * @param string[] $warnings Warnings.
	 * @return array{row:null,reason:string,warnings:string[]}
	 */
	private static function excluded( $reason, array $warnings = array() ) {
		return array(
			'row'      => null,
			'reason'   => $reason,
			'warnings' => $warnings,
		);
	}

	/**
	 * Does a meta of the product (or its parent) equal a value?
	 *
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent.
	 * @param string           $key     Meta key.
	 * @param string           $value   Expected value ('' = any non-empty value).
	 * @return bool
	 */
	private function meta_matches( $product, $parent, $key, $value ) {
		foreach ( array_filter( array( $product, $parent ) ) as $p ) {
			$current = get_post_meta( $p->get_id(), $key, true );
			if ( is_scalar( $current ) && ( '' === $value ? '' !== (string) $current : (string) $current === $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Brand of a product with several brand terms: a priority brand (brand_priority) when the product has it,
	 * else the brand that opens the product name (brand_from_title), else the fallback of the brand source.
	 *
	 * @param \WC_Product $holder   Product holding the terms (parent for variations).
	 * @param string      $current  Value resolved by the brand source.
	 * @param string[]    $warnings Warnings (multi-brand products are logged).
	 * @return string|null New brand, or null to keep the current value.
	 */
	private function brand( $holder, $current, array &$warnings ) {
		$spec  = $this->mapping['brand'];
		$terms = get_the_terms( $holder->get_id(), $spec['key'] );
		if ( ! is_array( $terms ) || count( $terms ) < 2 ) {
			return null;
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = Util::clean_text( html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		$chosen = '';
		foreach ( $this->feed['brand_priority'] as $priority ) {
			foreach ( $names as $name ) {
				if ( 0 === strcasecmp( $name, $priority ) ) {
					$chosen = $name;
					break 2;
				}
			}
		}
		if ( '' === $chosen && ! empty( $this->feed['brand_from_title'] ) ) {
			$chosen = self::opening_name( $holder->get_name( 'edit' ), $names );
		}
		if ( '' === $chosen ) {
			$chosen = '' !== $spec['fallback'] ? $spec['fallback'] : $current;
		}
		$warnings[] = 'multi_brand:' . $chosen;
		return $chosen;
	}

	/**
	 * Brand term that opens a title ('' when none). Every term of the brand taxonomy is checked.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private function opening_brand( $title ) {
		if ( ! isset( $this->mapping['brand'] ) || 'taxonomy' !== $this->mapping['brand']['type'] ) {
			$this->brand_names = array();
			return '';
		}
		if ( null === $this->brand_names ) {
			$terms             = get_terms(
				array(
					'taxonomy'   => $this->mapping['brand']['key'],
					'hide_empty' => false,
					'fields'     => 'names',
				)
			);
			$this->brand_names = is_array( $terms ) ? array_map(
				static function ( $name ) {
					return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				},
				$terms
			) : array();
		}
		return self::opening_name( $title, $this->brand_names );
	}

	/**
	 * Writes the brand names with the spelling of their term ("ACME BRAND" becomes "Acme Brand").
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function brand_case( $text ) {
		if ( empty( $this->feed['title_brand_case'] ) || '' === $text ) {
			return $text;
		}
		$this->opening_brand( '' ); // Loads the brand names.
		foreach ( (array) $this->brand_names as $name ) {
			if ( mb_strlen( $name ) < 3 ) {
				continue;
			}
			$text = (string) preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', $name, $text );
		}
		return $text;
	}

	/**
	 * Longest name that opens a text (case-insensitive, whole words).
	 *
	 * @param string   $text  Text.
	 * @param string[] $names Names.
	 * @return string
	 */
	private static function opening_name( $text, array $names ) {
		$text = Util::clean_text( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		usort(
			$names,
			static function ( $a, $b ) {
				return mb_strlen( $b ) - mb_strlen( $a );
			}
		);
		foreach ( $names as $name ) {
			if ( '' !== $name && preg_match( '/^' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu', $text ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Does a field rule apply (product categories, with children, or Google categories)?
	 *
	 * @param array<string,mixed> $rule   Rule.
	 * @param \WC_Product         $holder Product holding the categories.
	 * @param array<string,mixed> $item   Item.
	 * @return bool
	 */
	private function rule_applies( array $rule, $holder, array $item ) {
		if ( empty( $rule['categories'] ) && empty( $rule['google_categories'] ) ) {
			return true;
		}
		if ( ! empty( $rule['google_categories'] ) && isset( $item['google_product_category'] ) && in_array( (string) $item['google_product_category'], $rule['google_categories'], true ) ) {
			return true;
		}
		if ( ! empty( $rule['categories'] ) ) {
			$terms = get_the_terms( $holder->get_id(), 'product_cat' );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$chain = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
				if ( array_intersect( $chain, $rule['categories'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Value of a field rule: a static value, or the label of the longest word found in the product name.
	 *
	 * @param array<string,mixed> $rule    Rule.
	 * @param \WC_Product         $product Product or variation.
	 * @param \WC_Product         $holder  Parent or product.
	 * @return string
	 */
	private function rule_value( array $rule, $product, $holder ) {
		if ( empty( $rule['words'] ) ) {
			return $rule['value'];
		}
		$haystack = $product->get_name( 'edit' ) . ' ' . $holder->get_name( 'edit' );
		$needles  = array_keys( $rule['words'] );
		usort(
			$needles,
			static function ( $a, $b ) {
				return mb_strlen( (string) $b ) - mb_strlen( (string) $a );
			}
		);
		foreach ( $needles as $needle ) {
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( (string) $needle, '/' ) . '(?![\p{L}\p{N}])/iu', $haystack ) ) {
				return '' !== $rule['words'][ $needle ] ? $rule['words'][ $needle ] : (string) $needle;
			}
		}
		return $rule['value'];
	}

	/**
	 * Title in the feed language.
	 *
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent.
	 * @param string           $brand   Brand.
	 * @return string
	 */
	private function title( $product, $parent, $brand ) {
		$lang   = $this->feed['language'];
		$values = array();
		if ( $parent ) {
			$title = Lang::translate( $parent->get_name( 'edit' ), $lang );
			$name  = $title;
			foreach ( $product->get_attributes( 'edit' ) as $attribute => $slug ) {
				if ( '' === (string) $slug ) {
					continue;
				}
				$label = (string) $slug;
				if ( taxonomy_exists( $attribute ) ) {
					$term  = get_term_by( 'slug', $slug, $attribute );
					$label = $term ? $term->name : $label;
				}
				$value = html_entity_decode( Lang::translate( $label, $lang ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				foreach ( $this->feed['title_strip_patterns'] as $pattern ) {
					$value = (string) preg_replace( Util::regex( $pattern ), '', $value );
				}
				$value = trim( $value );
				if ( '' !== $value ) {
					$values[] = $value;
				}
			}
			if ( $values ) {
				$title .= ' - ' . implode( ', ', $values );
			}
		} else {
			$title = Lang::translate( $product->get_name( 'edit' ), $lang );
			$name  = $title;
		}
		$title = $this->brand_case( Util::clean_text( $title ) );
		$name  = $this->brand_case( $name );
		if ( '' !== $this->feed['title_template'] ) {
			return $this->title_from_template( $product, $parent, $brand, $name, $values );
		}
		// Brand prefix only when the brand is not already in the title (word order changes with the language).
		if ( ! empty( $this->feed['title_brand_prefix'] ) && '' !== $brand && false === mb_stripos( $title, $brand ) && '' === $this->opening_brand( $title ) ) {
			$title = $brand . ' ' . $title;
		}
		return $title;
	}

	/**
	 * Title built from the feed template. Tokens: {title}, {name}, {variation}, {brand}, {type}, {category},
	 * {sku}, {attr:name}, {meta:key}. The brand and the type are dropped when the name already contains them.
	 *
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent.
	 * @param string           $brand   Brand.
	 * @param string           $name    Translated product name (parent name for a variation).
	 * @param string[]         $values  Translated variation values.
	 * @return string
	 */
	private function title_from_template( $product, $parent, $brand, $name, array $values ) {
		$holder    = $parent ? $parent : $product;
		$name      = Util::clean_text( $name );
		$variation = Util::clean_text( implode( ', ', $values ) );
		$type      = $this->title_type_from_rules( $holder, $name );
		if ( '' === $type ) {
			$type = $this->title_type( $holder );
		}
		$template = $this->feed['title_template'];
		$tokens   = array(
			'{name}'      => $name,
			'{variation}' => $variation,
			'{title}'     => $name . ( '' !== $variation ? ' - ' . $variation : '' ),
			'{brand}'     => ( '' !== $brand && false === mb_stripos( $name, $brand ) && '' === $this->opening_brand( $name ) ) ? $brand : '',
			'{type}'      => $this->type_for_name( $type, $name ),
			'{category}'  => '',
			'{sku}'       => (string) $product->get_sku( 'edit' ),
		);
		if ( false !== strpos( $template, '{category}' ) ) {
			$path                 = explode( ' > ', Sources::taxonomy( 'product_cat', 'path', $holder, $this->feed, true ) );
			$tokens['{category}'] = (string) end( $path );
		}
		$title = strtr( $template, $tokens );
		$feed  = $this->feed;
		$title = preg_replace_callback(
			'/\{(attr|meta):([^}]+)\}/',
			static function ( $m ) use ( $product, $feed ) {
				$spec = Sources::sanitize_spec(
					array(
						'type'      => 'attr' === $m[1] ? 'attribute' : 'meta',
						'key'       => $m[2],
						'parent'    => true,
						'translate' => true,
					)
				);
				return $spec ? Sources::resolve( $spec, $product, $feed ) : '';
			},
			$title
		);
		// Tidy separators left by empty tokens.
		$title = preg_replace( '/\(\s*\)/u', '', (string) $title );
		$title = preg_replace( '/\s*([\-–—|,])(\s*[\-–—|,])+/u', ' $1', (string) $title );
		$title = trim( (string) $title, " \t-–—|," );
		return Util::clean_text( $title );
	}

	/**
	 * Type to add to a name: empty when the name already says it. Words in brackets are optional
	 * qualifiers ("[Cotton] Shirt" gives "Cotton Shirt", left out when the name already has "Shirt").
	 *
	 * @param string $type Type label.
	 * @param string $name Product name.
	 * @return string
	 */
	private function type_for_name( $type, $name ) {
		$full = trim( (string) preg_replace( '/\s+/u', ' ', str_replace( array( '[', ']' ), '', $type ) ) );
		if ( '' === $full || false !== mb_stripos( $name, $full ) ) {
			return '';
		}
		$core = trim( (string) preg_replace( '/\s+/u', ' ', (string) preg_replace( '/\[[^\]]*\]/u', '', $type ) ) );
		if ( '' !== $core && $core !== $full && preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $core, '/' ) . '(?:s|x|es)?(?![\p{L}\p{N}])/iu', $name ) ) {
			return '';
		}
		return $full;
	}

	/**
	 * Type label from the name rules (title_type_rules): first rule whose pattern matches the name, for
	 * a product in one of its categories (with children).
	 *
	 * @param \WC_Product $holder Product holding the categories.
	 * @param string      $name   Product name in the feed language.
	 * @return string
	 */
	private function title_type_from_rules( $holder, $name ) {
		// The name in every language of the site: the same rule wins in every feed of the product
		// (a word present only in one translation still gives the same type everywhere).
		$names = array( $name, $holder->get_name( 'edit' ) );
		foreach ( array_keys( Lang::languages() ) as $code ) {
			$names[] = Lang::translate( $holder->get_name( 'edit' ), $code );
		}
		$haystack = implode( "\n", array_unique( $names ) );
		foreach ( $this->feed['title_type_rules'] as $rule ) {
			if ( ! preg_match( Util::regex( $rule['pattern'] ), $haystack ) ) {
				continue;
			}
			if ( empty( $rule['categories'] ) || $this->rule_applies(
				array(
					'categories'        => $rule['categories'],
					'google_categories' => array(),
				),
				$holder,
				array()
			) ) {
				return $rule['label'];
			}
		}
		return '';
	}

	/**
	 * Type label of the deepest mapped product category (title template {type}).
	 *
	 * @param \WC_Product $holder Product holding the categories.
	 * @return string
	 */
	private function title_type( $holder ) {
		$map = $this->feed['title_type_map'];
		if ( empty( $map ) ) {
			return '';
		}
		$best  = '';
		$depth = -1;
		$terms = get_the_terms( $holder->get_id(), 'product_cat' );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$chain = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
			$count = count( $chain );
			foreach ( $chain as $i => $term_id ) {
				if ( isset( $map[ $term_id ] ) ) {
					if ( $count - 1 - $i > $depth ) {
						$depth = $count - 1 - $i;
						$best  = (string) $map[ $term_id ];
					}
					break;
				}
			}
		}
		return $best;
	}

	/**
	 * Plain text description in the feed language, with the configured fallbacks.
	 *
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent.
	 * @return string
	 */
	private function description( $product, $parent ) {
		$lang   = $this->feed['language'];
		$min    = (int) $this->feed['description_min_length'];
		$limit  = 5000;
		$holder = $parent ? $parent : $product;
		$html   = $product->get_description( 'edit' );
		if ( '' === trim( wp_strip_all_tags( $html ) ) && $parent ) {
			$html = $parent->get_description( 'edit' );
		}
		$text = $this->clean_description( Util::clean_text( Lang::translate_content( $html, $lang ), $limit ) );

		// Content without a translation in this language: a translated fallback (meta description) wins,
		// even when it is shorter.
		$untranslated = '' !== $text && Lang::needs_translation( $lang )
			&& Util::clean_text( Lang::translate_content( $html, $lang ) ) === Util::clean_text( Lang::render_content( $html ) );
		if ( $untranslated ) {
			foreach ( $this->feed['description_fallbacks'] as $spec ) {
				$candidate = $this->fallback_candidate( $spec, $holder, $limit );
				if ( '' !== $candidate['text'] && $candidate['translated'] ) {
					$text         = $candidate['text'];
					$untranslated = false;
					break;
				}
			}
		}

		if ( $min > 0 && mb_strlen( $text ) < $min ) {
			$translated_text = ! $untranslated;
			foreach ( $this->feed['description_fallbacks'] as $spec ) {
				$candidate = $this->fallback_candidate( $spec, $holder, $limit );
				// Never trade a translated text for an untranslated one.
				if ( mb_strlen( $candidate['text'] ) > mb_strlen( $text ) && ( $candidate['translated'] || ! $translated_text || ! Lang::needs_translation( $lang ) ) ) {
					$text = $candidate['text'];
				}
				if ( mb_strlen( $text ) >= $min ) {
					break;
				}
			}
		}
		if ( '' === $text ) {
			$short = $holder->get_short_description( 'edit' );
			$text  = $this->clean_description( Util::clean_text( Lang::translate_content( $short, $lang ), $limit ) );
		}
		if ( ! empty( $this->feed['description_attributes'] ) && ( 0 === $min || mb_strlen( $text ) < $min ) ) {
			$sentences = $this->attribute_sentences( $holder );
			if ( '' !== $sentences ) {
				$text = Util::clean_text( trim( $text . ( '' !== $text && ! preg_match( '/[.!?]$/u', $text ) ? '. ' : ' ' ) . $sentences ), $limit );
			}
		}
		if ( '' === $text ) {
			// Last resort: the product name (the platforms require a description).
			$text = Util::clean_text( Lang::translate( $holder->get_name( 'edit' ), $lang ) );
		}
		return $text;
	}

	/**
	 * A description fallback in the feed language, and whether it has a translation.
	 *
	 * @param array<string,mixed> $spec   Source spec.
	 * @param \WC_Product         $holder Product holding the value.
	 * @param int                 $limit  Maximum length.
	 * @return array{text:string,translated:bool}
	 */
	private function fallback_candidate( array $spec, $holder, $limit ) {
		$text       = $this->clean_description( Util::clean_text( Sources::resolve( $spec, $holder, $this->feed ), $limit ) );
		$translated = true;
		if ( '' !== $text && ! empty( $spec['translate'] ) && Lang::needs_translation( $this->feed['language'] ) ) {
			$original   = $this->clean_description( Util::clean_text( Sources::resolve( array_merge( $spec, array( 'translate' => false ) ), $holder, $this->feed ), $limit ) );
			$translated = $original !== $text;
		}
		return array(
			'text'       => $text,
			'translated' => $translated,
		);
	}

	/**
	 * Removes the configured fragments (description_remove) and the sentences with an email address.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function clean_description( $text ) {
		if ( '' === $text ) {
			return $text;
		}
		foreach ( $this->feed['description_remove'] as $pattern ) {
			$text = (string) preg_replace( Util::regex( $pattern ), '', $text );
		}
		if ( ! empty( $this->feed['description_remove_emails'] ) ) {
			// From the start of the sentence to the address (and its final period).
			$text = (string) preg_replace( '/[^.!?]*?[\w.+\-]+@[\w\-]+(?:\.[\w\-]+)+\b[.!?]?/u', '', $text );
		}
		// Spaces left before punctuation by the removals. Kept: ", .009" (number) and, in French, the space
		// before ; : ! ? (French typography).
		$text = (string) preg_replace( '/\s+([.,])(?!\d)/u', '$1', $text );
		if ( 0 !== strpos( strtolower( (string) $this->feed['language'] ), 'fr' ) ) {
			$text = (string) preg_replace( '/\s+([;:!?])/u', '$1', $text );
		}
		$text = (string) preg_replace( '/([,;:!?])\1+/u', '$1', $text );
		$text = (string) preg_replace( '/(?<!\.)\.\.(?!\.)/u', '.', $text );
		return trim( Util::clean_text( $text ), " \t,;:-–" );
	}

	/**
	 * Visible product attributes as short sentences ("Top: Solid spruce.").
	 *
	 * @param \WC_Product $product Product holding the attributes.
	 * @return string
	 */
	private function attribute_sentences( $product ) {
		$lang = $this->feed['language'];
		$out  = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! is_object( $attribute ) || ! $attribute->get_visible() || $attribute->get_variation() ) {
				continue;
			}
			$label  = Lang::translate( wc_attribute_label( $attribute->get_name(), $product ), $lang );
			$values = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			$values = array_map(
				static function ( $v ) use ( $lang ) {
					return html_entity_decode( Lang::translate( (string) $v, $lang ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				},
				(array) $values
			);
			$values = array_filter( array_map( 'trim', $values ) );
			if ( $values ) {
				$out[] = rtrim( $label, ': ' ) . ': ' . implode( ', ', $values ) . '.';
			}
		}
		return implode( ' ', $out );
	}

	/**
	 * Product link in the feed language. Extra query arguments are added later
	 * (g:link and mobile_link) so mapped links get the same parameters.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function link( $product ) {
		$url = Lang::feed_url( $product->get_permalink(), $this->feed['language'], ! empty( $this->feed['force_https'] ) );

		/**
		 * Filters a product link.
		 *
		 * @param string      $url     URL.
		 * @param \WC_Product $product Product.
		 * @param array       $feed    Feed configuration.
		 */
		return Util::encode_url( (string) apply_filters( 'oliprfe_link', $url, $product, $this->feed ) );
	}

	/**
	 * Main and additional image URLs.
	 *
	 * @param \WC_Product      $product Product.
	 * @param \WC_Product|null $parent  Parent.
	 * @return array{main:string,additional:string[]}
	 */
	private function images( $product, $parent ) {
		$size    = $this->feed['image_size'];
		$main_id = (int) $product->get_image_id( 'edit' );
		if ( ! $main_id && $parent ) {
			$main_id = (int) $parent->get_image_id( 'edit' );
		}
		$holder  = $parent ? $parent : $product;
		$gallery = array_map( 'intval', $holder->get_gallery_image_ids( 'edit' ) );
		if ( $parent && $main_id !== (int) $parent->get_image_id( 'edit' ) && $parent->get_image_id( 'edit' ) ) {
			array_unshift( $gallery, (int) $parent->get_image_id( 'edit' ) );
		}
		$main = $main_id ? $this->image_url( $main_id, $size ) : '';
		if ( '' === $main && ! empty( $gallery ) ) {
			$main = $this->image_url( (int) array_shift( $gallery ), $size );
		}
		$max        = min( (int) $this->feed['max_additional_images'], $this->channel->max_additional_images() );
		$additional = array();
		foreach ( array_unique( $gallery ) as $id ) {
			if ( count( $additional ) >= $max ) {
				break;
			}
			$url = $id !== $main_id ? $this->image_url( $id, $size ) : '';
			if ( '' !== $url && $url !== $main && ! in_array( $url, $additional, true ) ) {
				$additional[] = $url;
			}
		}
		return array(
			'main'       => $main,
			'additional' => $additional,
		);
	}

	/**
	 * Attachment URL.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $size Image size.
	 * @return string
	 */
	private function image_url( $id, $size ) {
		$url = 'full' === $size ? wp_get_attachment_url( $id ) : wp_get_attachment_image_url( $id, $size );
		if ( ! $url ) {
			return '';
		}
		// Skip attachments whose file is missing on disk (only for files served from the local uploads folder).
		static $base_url = null;
		if ( null === $base_url ) {
			$uploads  = wp_upload_dir( null, false );
			$base_url = set_url_scheme( $uploads['baseurl'], 'https' );
		}
		if ( 0 === strpos( set_url_scheme( $url, 'https' ), $base_url ) ) {
			$file = get_attached_file( $id );
			if ( 'full' !== $size ) {
				$file = trailingslashit( dirname( (string) $file ) ) . basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			}
			if ( $file && ! file_exists( $file ) ) {
				return '';
			}
		}
		$url = ! empty( $this->feed['force_https'] ) ? Util::maybe_https( $url ) : $url;
		return Util::encode_url( $url );
	}

	/**
	 * Feed availability.
	 *
	 * @param string           $stock_status WooCommerce stock status.
	 * @param \WC_Product      $product      Product.
	 * @param \WC_Product|null $parent       Parent.
	 * @return string
	 */
	private function availability( $stock_status, $product, $parent ) {
		$map          = array(
			'instock'     => 'in_stock',
			'onbackorder' => 'backorder',
			'outofstock'  => 'out_of_stock',
		);
		$availability = isset( $map[ $stock_status ] ) ? $map[ $stock_status ] : 'out_of_stock';
		$override     = Availability::override( $product, $parent );
		if ( ( ! empty( $this->feed['backorder_lead_enabled'] ) || '' !== $override ) && in_array( $stock_status, array( 'onbackorder', 'outofstock' ), true ) ) {
			$availability = ! empty( $this->feed['backorder_as_preorder'] ) ? 'preorder' : 'backorder';
		}
		foreach ( $this->feed['availability_rules'] as $rule ) {
			if ( $this->meta_matches( $product, $parent, $rule['meta'], $rule['value'] ) ) {
				$availability = $rule['availability'];
				break;
			}
		}

		/**
		 * Filters the availability (in_stock, out_of_stock, backorder or preorder).
		 *
		 * @param string      $availability Availability.
		 * @param \WC_Product $product      Product.
		 * @param array       $feed         Feed configuration.
		 */
		$availability = (string) apply_filters( 'oliprfe_availability', $availability, $product, $this->feed );
		return in_array( $availability, array( 'in_stock', 'out_of_stock', 'backorder', 'preorder' ), true ) ? $availability : 'out_of_stock';
	}

	/**
	 * g:availability_date and optional handling times for backorder / preorder items
	 * (Google, Microsoft, Meta — only when the channel has the field).
	 *
	 * @param array<string,mixed> $item    Item (by reference).
	 * @param \WC_Product         $product Product or variation.
	 * @param \WC_Product|null    $parent  Parent.
	 */
	private function apply_availability_extras( array &$item, $product, $parent ) {
		$override = Availability::override( $product, $parent );
		if ( ! Availability::wants_date( $this->feed, $override, $item['availability'] ) ) {
			return;
		}
		$fields = $this->channel->fields();
		if ( isset( $fields['availability_date'] ) && empty( $item['availability_date'] ) && ! $this->mapped_none( 'availability_date' ) ) {
			$source = '' !== $override ? $override : (string) ( isset( $this->feed['backorder_lead_days'] ) ? $this->feed['backorder_lead_days'] : 21 );
			$date   = Availability::datetime( $source );
			if ( '' !== $date ) {
				/**
				 * Filters the ISO 8601 availability date of a backorder / preorder item.
				 *
				 * @param string      $date    Date.
				 * @param \WC_Product $product Product.
				 * @param array       $feed    Feed configuration.
				 */
				$item['availability_date'] = (string) apply_filters( 'oliprfe_availability_date', $date, $product, $this->feed );
			}
		}
		$min = isset( $this->feed['backorder_min_handling_time'] ) ? (string) $this->feed['backorder_min_handling_time'] : '';
		$max = isset( $this->feed['backorder_max_handling_time'] ) ? (string) $this->feed['backorder_max_handling_time'] : '';
		if ( '' === $min && '' === $max ) {
			return;
		}
		if ( '' === $min ) {
			$min = $max;
		}
		if ( '' === $max ) {
			$max = $min;
		}
		if ( (int) $max < (int) $min ) {
			$max = $min;
		}
		if ( isset( $fields['min_handling_time'] ) && empty( $item['min_handling_time'] ) && ! $this->mapped_none( 'min_handling_time' ) ) {
			$item['min_handling_time'] = $min;
		}
		if ( isset( $fields['max_handling_time'] ) && empty( $item['max_handling_time'] ) && ! $this->mapped_none( 'max_handling_time' ) ) {
			$item['max_handling_time'] = $max;
		}
	}

	/**
	 * Is this field mapped to "Do not send"?
	 *
	 * @param string $field Field.
	 * @return bool
	 */
	private function mapped_none( $field ) {
		return isset( $this->mapping[ $field ] ) && 'none' === $this->mapping[ $field ]['type'];
	}

	/**
	 * Google category: item override, else the deepest mapped product category, else the default.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param \WC_Product $holder  Product holding the categories.
	 * @return string
	 */
	private function google_category( $product, $holder ) {
		$items = $this->feed['google_category_items'];
		foreach ( array( $product->get_id(), $holder->get_id() ) as $id ) {
			if ( isset( $items[ $id ] ) ) {
				return (string) $items[ $id ];
			}
		}
		$map = $this->feed['google_category_map'];
		if ( ! empty( $map ) ) {
			$terms = get_the_terms( $holder->get_id(), 'product_cat' );
			$best  = '';
			$depth = -1;
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				$chain = array_merge( array( (int) $term->term_id ), array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
				$count = count( $chain );
				foreach ( $chain as $i => $term_id ) {
					if ( isset( $map[ $term_id ] ) ) {
						$d = $count - 1 - $i;
						if ( $d > $depth ) {
							$depth = $d;
							$best  = (string) $map[ $term_id ];
						}
						break;
					}
				}
			}
			if ( '' !== $best ) {
				return $best;
			}
		}
		return (string) $this->feed['google_category_default'];
	}
}
