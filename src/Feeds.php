<?php
/**
 * Feed storage: one private custom post type, configuration as JSON in post meta.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Feed CRUD and configuration schema.
 */
final class Feeds {

	const POST_TYPE  = 'oliprfe_feed';
	const META_KEY   = '_oliprfe_config';
	const META_LOG   = '_oliprfe_log';
	const META_LAST  = '_oliprfe_last_run';
	const LOG_LENGTH = 10;

	/**
	 * Registers the private post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Product feeds', 'oli-product-feed' ),
					'singular_name' => __( 'Product feed', 'oli-product-feed' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Default configuration of a feed.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'channel'                 => 'google',
			'format'                  => 'xml',
			'file_slug'               => '',
			'language'                => '',
			'country'                 => '',
			'currency'                => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'price_source'            => 'woocommerce',
			'price_source_options'    => array(),
			'min_price'               => '',
			'variations'              => true,
			'frequency'               => 'daily',
			'start_hour'              => -1,
			'batch_size'              => 100,
			'image_size'              => 'full',
			'max_additional_images'   => 10,
			'force_https'             => true,
			'link_args'               => array(),
			'utm'                     => array(),
			'filters'                 => array(
				'categories_include' => array(),
				'categories_exclude' => array(),
				'stock_statuses'     => array( 'instock', 'onbackorder', 'outofstock' ),
				'exclude_ids'        => array(),
				'title_contains'     => '',
				'title_not_contains' => '',
				'require_fields'     => array(),
				'tags_include'       => array(),
				'tags_exclude'       => array(),
				'price_min'          => '',
				'price_max'          => '',
			),
			'availability_rules'      => array(),
			'backorder_lead_enabled'  => false,
			'backorder_lead_days'     => 21,
			'backorder_as_preorder'   => false,
			'backorder_min_handling_time' => '',
			'backorder_max_handling_time' => '',
			'exclude_rules'           => array(),
			'title_brand_prefix'      => false,
			'title_template'          => '',
			'title_type_map'          => array(),
			'title_type_rules'        => array(),
			'mpn_brands'              => array(),
			'mpn_only_without_gtin'   => false,
			'brand_priority'          => array(),
			'brand_from_title'        => false,
			'description_min_length'  => 0,
			'description_fallbacks'   => array(),
			'description_attributes'  => false,
			'description_remove'      => array(),
			'description_remove_emails' => false,
			'title_strip_patterns'    => array(),
			'title_brand_case'        => false,
			'field_rules'             => array(),
			'item_overrides'          => array(),
			'google_category_default' => '',
			'google_category_map'     => array(),
			'google_category_items'   => array(),
			'mapping'                 => array(),
			'columns'                 => array(),
			'xml_root'                => 'products',
			'xml_item'                => 'product',
		);
	}

	/**
	 * Returns a feed configuration merged with defaults, or null.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( $feed_id ) {
		$post = get_post( $feed_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		$raw    = get_post_meta( $feed_id, self::META_KEY, true );
		$stored = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : array();
		$config = self::sanitize( is_array( $stored ) ? $stored : array() );

		$config['id']     = (int) $feed_id;
		$config['name']   = $post->post_title;
		$config['status'] = $post->post_status;
		if ( '' === $config['file_slug'] ) {
			$config['file_slug'] = 'feed-' . (int) $feed_id;
		}
		return $config;
	}

	/**
	 * All feeds (published or draft).
	 *
	 * @return int[]
	 */
	public static function all_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => array( 'publish', 'draft' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			)
		);
	}

	/**
	 * Feed ID by file slug (0 when none).
	 *
	 * @param string $slug File slug.
	 * @return int
	 */
	public static function find_by_slug( $slug ) {
		foreach ( self::all_ids() as $id ) {
			$feed = self::get( $id );
			if ( $feed && $feed['file_slug'] === $slug ) {
				return $id;
			}
		}
		return 0;
	}

	/**
	 * Creates or updates a feed.
	 *
	 * @param array<string,mixed> $config  Configuration (name, active and every config key).
	 * @param int                 $feed_id Existing feed ID or 0.
	 * @return int|\WP_Error Feed ID.
	 */
	public static function save( array $config, $feed_id = 0 ) {
		$name   = isset( $config['name'] ) ? sanitize_text_field( (string) $config['name'] ) : '';
		$status = ( isset( $config['active'] ) && ! $config['active'] ) ? 'draft' : 'publish';
		$clean  = self::sanitize( $config );

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => '' !== $name ? $name : __( 'Product feed', 'oli-product-feed' ),
			'post_status' => $status,
		);
		if ( $feed_id ) {
			$postarr['ID'] = (int) $feed_id;
			$result        = wp_update_post( $postarr, true );
		} else {
			$result = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		update_post_meta( (int) $result, self::META_KEY, wp_slash( wp_json_encode( $clean ) ) );
		Endpoint::refresh_index();
		return (int) $result;
	}

	/**
	 * Deletes the generated file of a feed (and its temporary file).
	 *
	 * @param int $feed_id Feed ID.
	 */
	public static function delete_files( $feed_id ) {
		$name   = Storage::filename( (int) $feed_id );
		$folder = Storage::served_folder( (int) $feed_id );
		// A file the plugin did not write is never deleted (no record yet: update from 0.3.2, rules of 0.3.2).
		if ( OutputDirs::recorded() && ! OutputDirs::owns( $folder, $name ) ) {
			return;
		}
		$path = Storage::path_for( (int) $feed_id );
		foreach ( array( $path, $path . '.tmp' ) as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		OutputDirs::forget_file( $folder, $name );
	}

	/**
	 * A feed trashed or deleted outside the plugin (Posts API, WP-CLI): its file is removed (so neither
	 * the endpoint nor the static URL serves it), its generation stopped and the endpoint index refreshed.
	 * Hooked to wp_trash_post, transition_post_status (any change to "trash", e.g. wp_update_post) and
	 * before_delete_post. A status changed by a direct database query fires no hook: the endpoint checks
	 * the feed status before serving. A restored feed comes back as a draft (WordPress behavior) and
	 * is served again once it is generated.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_trash( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		Generator::cancel( (int) $post_id );
		self::delete_files( (int) $post_id );
		Endpoint::refresh_index();
	}

	/**
	 * A feed moved to the trash by any path that saves the post (wp_trash_post, wp_update_post,
	 * wp_insert_post with post_status "trash"): see on_trash().
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_status( $new_status, $old_status, $post ) {
		if ( 'trash' === $new_status && 'trash' !== $old_status && $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			self::on_trash( (int) $post->ID );
		}
	}

	/**
	 * Removes the files of the trashed feeds (feeds trashed before 0.3.2 kept their file).
	 */
	public static function delete_trashed_files() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'trash',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $feed_id ) {
			Generator::cancel( (int) $feed_id );
			self::delete_files( (int) $feed_id );
		}
	}

	/**
	 * A feed restored from the trash: refreshes the endpoint index.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_untrash( $post_id ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			Endpoint::refresh_index();
		}
	}

	/**
	 * Deletes a feed, its state and its file.
	 *
	 * @param int $feed_id Feed ID.
	 */
	public static function delete( $feed_id ) {
		$feed_id = (int) $feed_id;
		if ( ! self::get( $feed_id ) ) {
			return;
		}
		self::delete_files( $feed_id );
		delete_option( Generator::STATE_PREFIX . $feed_id );
		delete_transient( Generator::LOCK_PREFIX . $feed_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::BATCH, array( $feed_id ), Scheduler::GROUP );
		}
		wp_clear_scheduled_hook( Scheduler::BATCH, array( $feed_id ) );
		wp_delete_post( $feed_id, true );
		Endpoint::refresh_index();
	}

	/**
	 * Sanitizes a configuration array (unknown keys are dropped).
	 *
	 * @param array<string,mixed> $in Raw configuration.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();

		$channel        = isset( $in['channel'] ) ? sanitize_key( $in['channel'] ) : $d['channel'];
		$out['channel'] = Channels\Registry::get( $channel ) ? $channel : $d['channel'];
		$formats        = Channels\Registry::get( $out['channel'] )->formats();
		$format         = isset( $in['format'] ) ? sanitize_key( $in['format'] ) : $d['format'];
		$out['format']  = in_array( $format, $formats, true ) ? $format : $formats[0];

		$out['file_slug']    = isset( $in['file_slug'] ) ? sanitize_title( (string) $in['file_slug'] ) : '';
		$out['language']     = isset( $in['language'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $in['language'] ) : '';
		$out['country']      = isset( $in['country'] ) ? strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $in['country'] ), 0, 2 ) ) : '';
		$currency            = isset( $in['currency'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $in['currency'] ) ) : '';
		$out['currency']     = 3 === strlen( $currency ) ? $currency : $d['currency'];
		$out['price_source'] = isset( $in['price_source'] ) ? sanitize_key( $in['price_source'] ) : $d['price_source'];
		$out['min_price']    = self::amount( isset( $in['min_price'] ) ? $in['min_price'] : '' );

		$out['price_source_options'] = array();
		if ( isset( $in['price_source_options'] ) && is_array( $in['price_source_options'] ) ) {
			foreach ( $in['price_source_options'] as $k => $v ) {
				$out['price_source_options'][ sanitize_key( $k ) ] = is_bool( $v ) ? $v : sanitize_text_field( (string) $v );
			}
		}

		$out['variations']            = isset( $in['variations'] ) ? (bool) $in['variations'] : $d['variations'];
		$frequency                    = isset( $in['frequency'] ) ? sanitize_key( $in['frequency'] ) : $d['frequency'];
		$out['frequency']             = in_array( $frequency, array( 'manual', 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $frequency : $d['frequency'];
		$out['start_hour']            = isset( $in['start_hour'] ) && '' !== $in['start_hour'] ? max( -1, min( 23, (int) $in['start_hour'] ) ) : -1;
		$out['batch_size']            = isset( $in['batch_size'] ) ? max( 10, min( 1000, (int) $in['batch_size'] ) ) : $d['batch_size'];
		$size                         = isset( $in['image_size'] ) ? sanitize_key( $in['image_size'] ) : $d['image_size'];
		$out['image_size']            = '' !== $size ? $size : 'full';
		$out['max_additional_images'] = isset( $in['max_additional_images'] ) ? max( 0, min( 10, (int) $in['max_additional_images'] ) ) : $d['max_additional_images'];
		$out['force_https']           = isset( $in['force_https'] ) ? (bool) $in['force_https'] : $d['force_https'];

		$out['link_args'] = Util::query_pairs( isset( $in['link_args'] ) ? $in['link_args'] : array() );
		$out['utm']       = array();
		$utm              = self::sanitize_pairs( isset( $in['utm'] ) ? $in['utm'] : array() );
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $k ) {
			if ( isset( $utm[ $k ] ) && '' !== $utm[ $k ] ) {
				$out['utm'][ $k ] = $utm[ $k ];
			}
		}

		$f              = isset( $in['filters'] ) && is_array( $in['filters'] ) ? $in['filters'] : array();
		$df             = $d['filters'];
		$out['filters'] = array(
			'categories_include' => self::int_list( isset( $f['categories_include'] ) ? $f['categories_include'] : array(), true ),
			'categories_exclude' => self::int_list( isset( $f['categories_exclude'] ) ? $f['categories_exclude'] : array(), true ),
			'stock_statuses'     => isset( $f['stock_statuses'] ) && is_array( $f['stock_statuses'] )
				? array_values( array_intersect( array( 'instock', 'onbackorder', 'outofstock' ), array_map( 'sanitize_key', $f['stock_statuses'] ) ) )
				: $df['stock_statuses'],
			'exclude_ids'        => self::int_list( isset( $f['exclude_ids'] ) ? $f['exclude_ids'] : array() ),
			'title_contains'     => isset( $f['title_contains'] ) ? sanitize_text_field( (string) $f['title_contains'] ) : '',
			'title_not_contains' => isset( $f['title_not_contains'] ) ? sanitize_text_field( (string) $f['title_not_contains'] ) : '',
			'require_fields'     => isset( $f['require_fields'] ) && is_array( $f['require_fields'] ) ? array_values( array_filter( array_map( 'sanitize_key', $f['require_fields'] ) ) ) : array(),
			'tags_include'       => self::slug_list( isset( $f['tags_include'] ) ? $f['tags_include'] : array() ),
			'tags_exclude'       => self::slug_list( isset( $f['tags_exclude'] ) ? $f['tags_exclude'] : array() ),
			'price_min'          => self::amount( isset( $f['price_min'] ) ? $f['price_min'] : '' ),
			'price_max'          => self::amount( isset( $f['price_max'] ) ? $f['price_max'] : '' ),
		);

		$out['availability_rules'] = array();
		if ( isset( $in['availability_rules'] ) && is_array( $in['availability_rules'] ) ) {
			foreach ( $in['availability_rules'] as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['meta'] ) || empty( $rule['availability'] ) ) {
					continue;
				}
				$availability = sanitize_key( $rule['availability'] );
				if ( ! in_array( $availability, array( 'in_stock', 'out_of_stock', 'backorder', 'preorder' ), true ) ) {
					continue;
				}
				$out['availability_rules'][] = array(
					'meta'         => sanitize_text_field( (string) $rule['meta'] ),
					'value'        => isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '',
					'availability' => $availability,
				);
			}
		}

		$out['backorder_lead_enabled'] = ! empty( $in['backorder_lead_enabled'] );
		$days                          = isset( $in['backorder_lead_days'] ) ? (int) $in['backorder_lead_days'] : $d['backorder_lead_days'];
		$out['backorder_lead_days']    = max( 1, min( 3650, $days ) );
		$out['backorder_as_preorder']  = ! empty( $in['backorder_as_preorder'] );
		$min                           = isset( $in['backorder_min_handling_time'] ) ? trim( (string) $in['backorder_min_handling_time'] ) : '';
		$max                           = isset( $in['backorder_max_handling_time'] ) ? trim( (string) $in['backorder_max_handling_time'] ) : '';
		$out['backorder_min_handling_time'] = ( '' !== $min && is_numeric( $min ) ) ? (string) max( 0, min( 365, (int) $min ) ) : '';
		$out['backorder_max_handling_time'] = ( '' !== $max && is_numeric( $max ) ) ? (string) max( 0, min( 365, (int) $max ) ) : '';
		if ( '' !== $out['backorder_min_handling_time'] && '' !== $out['backorder_max_handling_time'] && (int) $out['backorder_max_handling_time'] < (int) $out['backorder_min_handling_time'] ) {
			$out['backorder_max_handling_time'] = $out['backorder_min_handling_time'];
		}

		$out['exclude_rules'] = array();
		if ( isset( $in['exclude_rules'] ) && is_array( $in['exclude_rules'] ) ) {
			foreach ( $in['exclude_rules'] as $rule ) {
				if ( is_array( $rule ) && ! empty( $rule['meta'] ) ) {
					$out['exclude_rules'][] = array(
						'meta'  => sanitize_text_field( (string) $rule['meta'] ),
						'value' => isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '',
					);
				}
			}
		}

		$out['title_brand_prefix']     = ! empty( $in['title_brand_prefix'] );
		$out['title_template']         = isset( $in['title_template'] ) ? sanitize_text_field( (string) $in['title_template'] ) : '';
		$out['title_type_rules']       = array();
		if ( isset( $in['title_type_rules'] ) && is_array( $in['title_type_rules'] ) ) {
			foreach ( $in['title_type_rules'] as $rule ) {
				$pattern = is_array( $rule ) && isset( $rule['pattern'] ) ? self::pattern_list( array( $rule['pattern'] ) ) : array();
				$label   = is_array( $rule ) && isset( $rule['label'] ) ? sanitize_text_field( (string) $rule['label'] ) : '';
				if ( $pattern && '' !== $label ) {
					$out['title_type_rules'][] = array(
						'pattern'    => $pattern[0],
						'label'      => $label,
						'categories' => self::int_list( isset( $rule['categories'] ) ? $rule['categories'] : array(), true ),
					);
				}
			}
		}
		$out['title_strip_patterns']   = self::pattern_list( isset( $in['title_strip_patterns'] ) ? $in['title_strip_patterns'] : array() );
		$out['title_brand_case']       = ! empty( $in['title_brand_case'] );
		$out['description_remove']     = self::pattern_list( isset( $in['description_remove'] ) ? $in['description_remove'] : array() );
		$out['description_remove_emails'] = ! empty( $in['description_remove_emails'] );
		$out['field_rules']            = array();
		if ( isset( $in['field_rules'] ) && is_array( $in['field_rules'] ) ) {
			foreach ( $in['field_rules'] as $rule ) {
				if ( ! is_array( $rule ) || empty( $rule['field'] ) ) {
					continue;
				}
				$field = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $rule['field'] ) );
				$words = array();
				if ( isset( $rule['words'] ) && is_array( $rule['words'] ) ) {
					foreach ( $rule['words'] as $needle => $label ) {
						$needle = sanitize_text_field( (string) $needle );
						if ( '' !== $needle ) {
							$words[ $needle ] = sanitize_text_field( (string) $label );
						}
					}
				}
				$value = isset( $rule['value'] ) ? sanitize_text_field( (string) $rule['value'] ) : '';
				if ( '' === $field || ( '' === $value && empty( $words ) ) ) {
					continue;
				}
				$out['field_rules'][] = array(
					'field'             => $field,
					'value'             => $value,
					'words'             => $words,
					'categories'        => self::int_list( isset( $rule['categories'] ) ? $rule['categories'] : array(), true ),
					'google_categories' => array_values( array_filter( array_map( 'trim', array_map( 'strval', is_array( isset( $rule['google_categories'] ) ? $rule['google_categories'] : '' ) ? $rule['google_categories'] : explode( ',', (string) ( isset( $rule['google_categories'] ) ? $rule['google_categories'] : '' ) ) ) ) ) ),
					'active'            => ! empty( $rule['active'] ),
				);
			}
		}
		$out['item_overrides'] = array();
		if ( isset( $in['item_overrides'] ) && is_array( $in['item_overrides'] ) ) {
			foreach ( $in['item_overrides'] as $override ) {
				if ( ! is_array( $override ) ) {
					continue;
				}
				$product = isset( $override['product'] ) ? absint( $override['product'] ) : 0;
				$field   = isset( $override['field'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $override['field'] ) ) : '';
				if ( $product > 0 && '' !== $field && ! in_array( $field, array( 'id', 'item_group_id' ), true ) ) {
					$out['item_overrides'][] = array(
						'product' => $product,
						'field'   => $field,
						'value'   => isset( $override['value'] ) ? sanitize_text_field( (string) $override['value'] ) : '',
					);
				}
			}
		}
		$out['title_type_map']         = array();
		if ( isset( $in['title_type_map'] ) && is_array( $in['title_type_map'] ) ) {
			foreach ( $in['title_type_map'] as $term_id => $label ) {
				$label = sanitize_text_field( (string) $label );
				if ( (int) $term_id > 0 && '' !== $label ) {
					$out['title_type_map'][ (int) $term_id ] = $label;
				}
			}
		}
		$out['mpn_brands']            = self::text_list( isset( $in['mpn_brands'] ) ? $in['mpn_brands'] : array() );
		$out['brand_priority']        = self::text_list( isset( $in['brand_priority'] ) ? $in['brand_priority'] : array() );
		$out['brand_from_title']      = ! empty( $in['brand_from_title'] );
		$out['mpn_only_without_gtin'] = ! empty( $in['mpn_only_without_gtin'] );
		$out['description_min_length'] = isset( $in['description_min_length'] ) ? max( 0, min( 5000, (int) $in['description_min_length'] ) ) : 0;
		$out['description_attributes'] = ! empty( $in['description_attributes'] );
		$out['description_fallbacks']  = array();
		if ( isset( $in['description_fallbacks'] ) && is_array( $in['description_fallbacks'] ) ) {
			foreach ( $in['description_fallbacks'] as $spec ) {
				$spec = Sources::sanitize_spec( $spec );
				if ( $spec ) {
					$out['description_fallbacks'][] = $spec;
				}
			}
		}

		$out['google_category_default'] = isset( $in['google_category_default'] ) ? self::google_category( $in['google_category_default'] ) : '';
		$out['google_category_map']     = array();
		if ( isset( $in['google_category_map'] ) && is_array( $in['google_category_map'] ) ) {
			foreach ( $in['google_category_map'] as $term_id => $google ) {
				$google = self::google_category( $google );
				if ( (int) $term_id > 0 && '' !== $google ) {
					$out['google_category_map'][ (int) $term_id ] = $google;
				}
			}
		}

		$out['google_category_items'] = array();
		if ( isset( $in['google_category_items'] ) && is_array( $in['google_category_items'] ) ) {
			foreach ( $in['google_category_items'] as $item_id => $google ) {
				$google = self::google_category( $google );
				if ( (int) $item_id > 0 && '' !== $google ) {
					$out['google_category_items'][ (int) $item_id ] = $google;
				}
			}
		}

		$out['mapping'] = array();
		if ( isset( $in['mapping'] ) && is_array( $in['mapping'] ) ) {
			foreach ( $in['mapping'] as $field => $spec ) {
				$field = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $field ) );
				$spec  = Sources::sanitize_spec( $spec );
				if ( '' !== $field && $spec ) {
					$out['mapping'][ $field ] = $spec;
				}
			}
		}

		$out['columns'] = array();
		if ( isset( $in['columns'] ) && is_array( $in['columns'] ) ) {
			foreach ( $in['columns'] as $column ) {
				if ( ! is_array( $column ) ) {
					continue;
				}
				$field = isset( $column['field'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $column['field'] ) ) : '';
				$name  = isset( $column['name'] ) ? trim( preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $column['name'] ) ) : '';
				if ( '' !== $field ) {
					$out['columns'][] = array(
						'name'  => '' !== $name ? $name : $field,
						'field' => $field,
					);
				}
			}
		}
		foreach ( array( 'xml_root', 'xml_item' ) as $key ) {
			$name        = isset( $in[ $key ] ) ? preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $in[ $key ] ) : '';
			$out[ $key ] = '' !== $name && preg_match( '/^[A-Za-z_]/', $name ) ? $name : $d[ $key ];
		}

		return $out;
	}

	/**
	 * Term slugs (array, or comma separated).
	 *
	 * @param mixed $list Raw list.
	 * @return string[]
	 */
	private static function slug_list( $list ) {
		if ( is_string( $list ) ) {
			$list = explode( ',', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'strval', $list ) ) ) ) );
	}

	/**
	 * Amount as a string ('' when empty or invalid).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function amount( $value ) {
		$value = str_replace( ',', '.', trim( (string) $value ) );
		return is_numeric( $value ) && (float) $value >= 0 ? (string) (float) $value : '';
	}

	/**
	 * Key/value pairs for query arguments.
	 *
	 * @param mixed $pairs Raw pairs.
	 * @return array<string,string>
	 */
	private static function sanitize_pairs( $pairs ) {
		$out = array();
		if ( ! is_array( $pairs ) ) {
			return $out;
		}
		foreach ( $pairs as $k => $v ) {
			$k = preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', (string) $k );
			if ( '' !== $k && is_scalar( $v ) ) {
				$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	/**
	 * List of regular expressions (without delimiters); invalid ones are dropped.
	 *
	 * @param mixed $list Raw list (array or one per line).
	 * @return string[]
	 */
	private static function pattern_list( $list ) {
		if ( is_string( $list ) ) {
			$list = preg_split( '/\r\n|\r|\n/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $pattern ) {
			$pattern = trim( wp_check_invalid_utf8( (string) $pattern ) );
			if ( '' !== $pattern && false !== @preg_match( Util::regex( $pattern ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Validation of a user pattern.
				$out[] = $pattern;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * List of short texts (array, or one per line / comma separated).
	 *
	 * @param mixed $list Raw list.
	 * @return string[]
	 */
	private static function text_list( $list ) {
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\r\n,]+/', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'trim', array_map( 'sanitize_text_field', array_map( 'strval', $list ) ) ) ) ) );
	}

	/**
	 * List of positive integers.
	 *
	 * @param mixed $list Raw list (array or comma separated).
	 * @param bool  $sort Sort ascending.
	 * @return int[]
	 */
	private static function int_list( $list, $sort = false ) {
		if ( is_string( $list ) ) {
			$list = explode( ',', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		$list = array_values( array_unique( array_filter( array_map( 'absint', $list ) ) ) );
		if ( $sort ) {
			sort( $list );
		}
		return $list;
	}

	/**
	 * Google category: numeric ID or full path text.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private static function google_category( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( preg_match( '/^(\d+)/', $value, $m ) ) {
			return $m[1];
		}
		return $value;
	}

	/**
	 * Appends a generation log entry and stores it as the last run.
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $entry   Log entry.
	 */
	public static function add_log( $feed_id, array $entry ) {
		$log = get_post_meta( $feed_id, self::META_LOG, true );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_post_meta( $feed_id, self::META_LOG, array_slice( $log, 0, self::LOG_LENGTH ) );
		update_post_meta( $feed_id, self::META_LAST, $entry );
	}

	/**
	 * Last run entry.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array<string,mixed>
	 */
	public static function last_run( $feed_id ) {
		$last = get_post_meta( $feed_id, self::META_LAST, true );
		return is_array( $last ) ? $last : array();
	}
}
