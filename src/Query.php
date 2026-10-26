<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Product IDs of a feed.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the ordered list of product/variation IDs to process.
 */
final class Query {

	/**
	 * IDs of the items candidates: published products visible in the catalog, and the published
	 * variations of variable products (the variable parent itself is not an item when variations are on).
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @return int[]
	 */
	public static function ids( array $feed ) {
		$tax_query = array(
			'relation' => 'AND',
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'slug',
				'terms'    => array( 'exclude-from-catalog' ),
				'operator' => 'NOT IN',
			),
		);
		if ( ! empty( $feed['filters']['categories_include'] ) ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $feed['filters']['categories_include'],
				'include_children' => true,
			);
		}
		if ( ! empty( $feed['filters']['categories_exclude'] ) ) {
			$tax_query[] = array(
				'taxonomy'         => 'product_cat',
				'field'            => 'term_id',
				'terms'            => $feed['filters']['categories_exclude'],
				'operator'         => 'NOT IN',
				'include_children' => true,
			);
		}

		if ( ! empty( $feed['filters']['tags_include'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => $feed['filters']['tags_include'],
			);
		}
		if ( ! empty( $feed['filters']['tags_exclude'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => $feed['filters']['tags_exclude'],
				'operator' => 'NOT IN',
			);
		}

		$args = array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		);
		if ( ! empty( $feed['filters']['exclude_ids'] ) ) {
			$args['post__not_in'] = $feed['filters']['exclude_ids']; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
		}

		/**
		 * Filters the WP_Query arguments used to list the products of a feed.
		 *
		 * @param array $args WP_Query arguments.
		 * @param array $feed Feed configuration.
		 */
		$args        = apply_filters( 'oliprfe_query_args', $args, $feed );
		$product_ids = array_map( 'intval', get_posts( $args ) );

		if ( empty( $feed['variations'] ) || empty( $product_ids ) ) {
			return $product_ids;
		}

		$variable_ids = self::variable_ids( $product_ids );
		if ( empty( $variable_ids ) ) {
			return $product_ids;
		}

		$children  = get_posts(
			array(
				'post_type'       => 'product_variation',
				'post_status'     => 'publish',
				'posts_per_page'  => -1,
				'fields'          => 'ids',
				'cache_results'   => false,
				'orderby'         => 'menu_order ID',
				'order'           => 'ASC',
				'post_parent__in' => $variable_ids,
				'no_found_rows'   => true,
			)
		);
		$by_parent = array();
		// "ids" + parent lookup: the "id=>parent" shape is not reliable when the query comes from the
		// query cache (several feeds generated in one request returned 0 as variation IDs).
		foreach ( array_map( 'intval', $children ) as $child_id ) {
			$by_parent[ (int) wp_get_post_parent_id( $child_id ) ][] = $child_id;
		}

		$ids = array();
		foreach ( $product_ids as $id ) {
			if ( in_array( $id, $variable_ids, true ) ) {
				if ( ! empty( $by_parent[ $id ] ) ) {
					foreach ( $by_parent[ $id ] as $child_id ) {
						if ( ! in_array( $child_id, $feed['filters']['exclude_ids'], true ) ) {
							$ids[] = $child_id;
						}
					}
				}
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}

	/**
	 * Which of these products are variable products.
	 *
	 * @param int[] $ids Product IDs.
	 * @return int[]
	 */
	private static function variable_ids( array $ids ) {
		$term = get_term_by( 'slug', 'variable', 'product_type' );
		if ( ! $term ) {
			return array();
		}
		$variable = get_objects_in_term( (int) $term->term_id, 'product_type' );
		if ( is_wp_error( $variable ) ) {
			return array();
		}
		return array_values( array_intersect( $ids, array_map( 'intval', $variable ) ) );
	}
}
