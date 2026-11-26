<?php
/**
 * Diagnostics tab: last generation of one feed, exclusion reasons, warnings, site checks and a plain-text
 * report (local data only: nothing is sent anywhere).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Channels\Registry;
use Oliprfe\Feeds;
use Oliprfe\Integrations\PriceBasedOnCountry;
use Oliprfe\Lang;
use Oliprfe\OutputDirs;
use Oliprfe\Scheduler;
use Oliprfe\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the run entries stored by the generator (_oliprfe_last_run, _oliprfe_log): no loop over the catalog.
 */
final class Diagnostics {

	/**
	 * Products listed by "View" (the generator keeps the first 100 IDs of each reason).
	 */
	const LIST_MAX = 100;

	/**
	 * Feed shown without a feed parameter: the first feed in error, else the last generated one.
	 *
	 * @param int[] $ids Feed IDs.
	 * @return int
	 */
	public static function default_feed( array $ids ) {
		$best = 0;
		$time = -1;
		foreach ( $ids as $id ) {
			if ( 'error' === Admin::status_key( $id ) ) {
				return (int) $id;
			}
			$last = Feeds::last_run( $id );
			$t    = isset( $last['time'] ) ? (int) $last['time'] : 0;
			if ( $t > $time ) {
				$best = (int) $id;
				$time = $t;
			}
		}
		return $best;
	}

	/**
	 * Tab.
	 */
	public static function render() {
		$ids = Feeds::all_ids();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen.
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		$view    = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $feed_id, $ids, true ) ) {
			$feed_id = self::default_feed( $ids );
		}
		echo '<div class="wrap oliprfe-wrap">';
		Admin::heading( __( 'Product Feeds', 'oli-product-feed' ) );
		Admin::tabs( 'diagnostics' );
		Admin::notices();
		if ( ! $feed_id ) {
			echo '<p>' . esc_html__( 'No feed yet. Diagnostics appear after the first generation.', 'oli-product-feed' ) . ' <a href="' . esc_url( Admin::url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Feed', 'oli-product-feed' ) . '</a></p></div>';
			return;
		}
		$feed = Feeds::get( $feed_id );
		$last = Feeds::last_run( $feed_id );
		$ok   = Admin::last_success( $feed_id );
		self::picker( $ids, $feed_id, $ok );
		if ( isset( $last['status'] ) && 'error' === $last['status'] ) {
			$failure = self::failure( $feed_id, $last );
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The last generation failed:', 'oli-product-feed' ) . '</strong> ' . esc_html( isset( $last['message'] ) ? (string) $last['message'] : '' ) . ( '' !== $failure['note'] ? ' ' . esc_html( $failure['note'] ) : '' ) . ' <a href="' . esc_url( $failure['link'][0] ) . '"' . ( $failure['button'] ? ' class="button"' : '' ) . '>' . esc_html( $failure['link'][1] ) . '</a></p></div>';
		}
		if ( ! isset( $ok['items'] ) ) {
			echo '<p>' . esc_html__( 'This feed has not been generated yet: generate it to see why products are excluded.', 'oli-product-feed' ) . '</p>';
		} else {
			$warnings = self::warnings( $feed, $ok );
			self::tiles( $ok, $warnings );
			if ( '' !== $view ) {
				self::product_list( $feed_id, $ok, $view );
			}
			self::reasons( $feed_id, $ok );
			self::warnings_table( $feed_id, $warnings );
			self::empty_table( $feed_id, $feed, $ok );
		}
		$checks = self::site_checks( $feed_id, $feed, $last );
		echo '<div class="oliprfe-panel"><h2>' . esc_html__( 'Site checks', 'oli-product-feed' ) . '</h2><ul class="oliprfe-checks">';
		foreach ( $checks as $check ) {
			echo '<li>' . self::badge( $check['state'] ) . '<span>' . esc_html( $check['text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in badge().
			if ( ! empty( $check['link'] ) ) {
				echo ' <a href="' . esc_url( $check['link'][0] ) . '">' . esc_html( $check['link'][1] ) . '</a>';
			}
			echo '</span></li>';
		}
		echo '</ul>';
		echo '<details><summary>' . esc_html__( 'Copy a diagnostic report', 'oli-product-feed' ) . '</summary>';
		echo '<p class="description">' . esc_html__( 'Plain text, generated on this site. Nothing is sent anywhere. Remove anything private before you share it.', 'oli-product-feed' ) . '</p>';
		echo '<label for="oliprfe-report" class="screen-reader-text">' . esc_html__( 'Diagnostic report', 'oli-product-feed' ) . '</label><textarea id="oliprfe-report" class="large-text code" rows="10" readonly>' . esc_textarea( self::report( $feed_id, $feed, $last, $ok, $checks ) ) . '</textarea>';
		echo '<p><button type="button" class="button oliprfe-copy" data-target="oliprfe-report">' . esc_html__( 'Copy report', 'oli-product-feed' ) . '</button><span class="oliprfe-copy-hint" hidden></span></p></details></div>';
		echo '</div>';
	}

	/**
	 * Feed selector (GET form) and context line of the last successful generation.
	 *
	 * @param int[]               $ids     Feed IDs.
	 * @param int                 $feed_id Selected feed.
	 * @param array<string,mixed> $ok      Last successful run.
	 */
	private static function picker( array $ids, $feed_id, array $ok ) {
		echo '<form method="get" class="oliprfe-match"><input type="hidden" name="page" value="' . esc_attr( Admin::SLUG ) . '"><input type="hidden" name="tab" value="diagnostics">';
		echo '<label for="oliprfe-diag-feed">' . esc_html__( 'Feed:', 'oli-product-feed' ) . '</label><select id="oliprfe-diag-feed" name="feed">';
		foreach ( $ids as $id ) {
			echo '<option value="' . (int) $id . '"' . selected( $id, $feed_id, false ) . '>' . esc_html( get_the_title( $id ) ) . '</option>';
		}
		echo '</select><button type="submit" class="button">' . esc_html__( 'Show', 'oli-product-feed' ) . '</button>';
		if ( isset( $ok['time'] ) ) {
			echo '<span class="oliprfe-meta">' . esc_html(
				sprintf(
					/* translators: 1: date and time, 2: seconds, 3: file size. */
					__( 'Last generation: %1$s · %2$s s · %3$s', 'oli-product-feed' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ok['time'] ),
					number_format_i18n( (float) $ok['duration'], 1 ),
					size_format( isset( $ok['bytes'] ) ? (int) $ok['bytes'] : 0 )
				)
			) . '</span>';
		}
		echo '</form>';
	}

	/**
	 * Four tiles: products checked = items in the feed + excluded.
	 *
	 * @param array<string,mixed>            $ok       Last successful run.
	 * @param array<int,array<string,mixed>> $warnings Warning rows.
	 */
	private static function tiles( array $ok, array $warnings ) {
		$items   = (int) $ok['items'];
		$checked = max( $items, (int) ( isset( $ok['candidates'] ) ? $ok['candidates'] : $items ) );
		$warned  = 0;
		foreach ( $warnings as $row ) {
			$warned += (int) $row['count'];
		}
		$tiles = array(
			array( $checked, __( 'Products checked', 'oli-product-feed' ) ),
			array( $items, __( 'Items in the feed', 'oli-product-feed' ) ),
			array( $checked - $items, __( 'Excluded', 'oli-product-feed' ) ),
			array( $warned, __( 'Warnings', 'oli-product-feed' ) ),
		);
		echo '<ul class="oliprfe-stats">';
		foreach ( $tiles as $tile ) {
			echo '<li><b>' . esc_html( number_format_i18n( $tile[0] ) ) . '</b>' . esc_html( $tile[1] ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Translated label of an exclusion code (unknown codes: the code itself, never empty).
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function label( $code ) {
		$labels = array(
			'stock_status'         => __( 'Out of stock (stock filter of the feed)', 'oli-product-feed' ),
			'min_price'            => __( 'Price below the minimum', 'oli-product-feed' ),
			'no_price'             => __( 'No price', 'oli-product-feed' ),
			'price_range'          => __( 'Price outside the range of the feed', 'oli-product-feed' ),
			'title_filter'         => __( 'Excluded by a title rule', 'oli-product-feed' ),
			'filtered'             => __( 'Excluded by a filter (code)', 'oli-product-feed' ),
			'parent_not_published' => __( 'Parent product not published', 'oli-product-feed' ),
			'password_protected'   => __( 'Password protected', 'oli-product-feed' ),
			'invalid_product'      => __( 'Product cannot be read', 'oli-product-feed' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		$label = Editor::reason_label( $code );
		return '' !== $label ? $label : $code;
	}

	/**
	 * How to fix an exclusion: text and editor section ('' when there is nothing to change in the feed).
	 *
	 * @param string $code Code.
	 * @return array{0:string,1:string}
	 */
	public static function fix( $code ) {
		$prefix = strtok( $code, ':' );
		$map    = array(
			'stock_status'         => array( __( 'Change the stock filter in Products', 'oli-product-feed' ), 'products' ),
			'min_price'            => array( __( 'Lower the minimum price in Products', 'oli-product-feed' ), 'products' ),
			'price_range'          => array( __( 'Change the price range in Products', 'oli-product-feed' ), 'products' ),
			'title_filter'         => array( __( 'Review the rule in Products', 'oli-product-feed' ), 'products' ),
			'rule'                 => array( __( 'Review the rule in Products', 'oli-product-feed' ), 'products' ),
			'filtered'             => array( __( 'Review the code that uses the oliprfe_include_product filter', 'oli-product-feed' ), '' ),
			'no_price'             => array( __( 'Add a price to these products', 'oli-product-feed' ), '' ),
			'parent_not_published' => array( __( 'Publish the parent products', 'oli-product-feed' ), '' ),
			'password_protected'   => array( __( 'Remove the password of these products', 'oli-product-feed' ), '' ),
			'invalid_product'      => array( __( 'Open these products and save them again', 'oli-product-feed' ), '' ),
			'missing'              => array( __( 'Map the missing fields in Mapping', 'oli-product-feed' ), 'mapping' ),
			'empty'                => array( __( 'Map the field or turn off "Exclude when empty" in Mapping', 'oli-product-feed' ), 'mapping' ),
		);
		return isset( $map[ $code ] ) ? $map[ $code ] : ( isset( $map[ $prefix ] ) ? $map[ $prefix ] : array( '', '' ) );
	}

	/**
	 * Fix cell: the text, linked to the editor section when the change is in the feed.
	 *
	 * @param int                     $feed_id Feed ID.
	 * @param array{0:string,1:string} $fix     Text and section.
	 * @return string
	 */
	private static function fix_cell( $feed_id, array $fix ) {
		if ( '' === $fix[1] ) {
			return esc_html( $fix[0] );
		}
		return '<a href="' . esc_url( Admin::url( array(
			'action' => 'edit',
			'feed'   => $feed_id,
		) ) . '#' . $fix[1] ) . '">' . esc_html( $fix[0] ) . '</a>';
	}

	/**
	 * "View" link of a reason or warning.
	 *
	 * @param int    $feed_id Feed ID.
	 * @param string $code    Code.
	 * @param int    $count   Products.
	 * @return string
	 */
	private static function view_link( $feed_id, $code, $count ) {
		$url = Admin::url(
			array(
				'tab'  => 'diagnostics',
				'feed' => $feed_id,
				'view' => $code,
			)
		) . '#oliprfe-view';
		/* translators: %s: reason. */
		return esc_html( number_format_i18n( $count ) ) . ' <a href="' . esc_url( $url ) . '" aria-label="' . esc_attr( sprintf( __( 'View the products: %s', 'oli-product-feed' ), self::label( $code ) ) ) . '">' . esc_html__( 'View', 'oli-product-feed' ) . '</a>';
	}

	/**
	 * Exclusion reasons.
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $ok      Last successful run.
	 */
	private static function reasons( $feed_id, array $ok ) {
		$excluded = isset( $ok['excluded'] ) && is_array( $ok['excluded'] ) ? $ok['excluded'] : array();
		uasort(
			$excluded,
			static function ( $a, $b ) {
				return (int) $b['count'] - (int) $a['count'];
			}
		);
		echo '<h2>' . esc_html__( 'Excluded products', 'oli-product-feed' ) . '</h2>';
		if ( ! $excluded ) {
			echo '<p>' . esc_html__( 'No product was excluded.', 'oli-product-feed' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Reason', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Products', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'How to fix', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( $excluded as $code => $info ) {
			$code = (string) $code;
			echo '<tr><td>' . esc_html( self::label( $code ) ) . '<br><code>' . esc_html( $code ) . '</code></td><td>' . self::view_link( $feed_id, $code, (int) $info['count'] ) . '</td><td>' . self::fix_cell( $feed_id, self::fix( $code ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in view_link() and fix_cell().
		}
		echo '</tbody></table>';
	}

	/**
	 * Warning rows: engine warnings (brand, several values, invalid GTIN) and required or recommended
	 * attributes left empty.
	 *
	 * @param array<string,mixed>|null $feed Feed.
	 * @param array<string,mixed>      $ok   Last successful run.
	 * @return array<int,array<string,mixed>>
	 */
	public static function warnings( $feed, array $ok ) {
		$rows   = array();
		$counts = isset( $ok['warned'] ) && is_array( $ok['warned'] ) ? $ok['warned'] : array();
		foreach ( isset( $ok['warnings'] ) && is_array( $ok['warnings'] ) ? $ok['warnings'] : array() as $code => $ids ) {
			$code  = (string) $code;
			$ids   = array_map( 'intval', (array) $ids );
			$field = 0 === strpos( $code, 'multiple_values_' ) ? substr( $code, 16 ) : '';
			if ( 'no_brand' === $code ) {
				$text = __( 'brand is empty', 'oli-product-feed' );
				$help = __( 'Sent without a brand. Assign a brand or set a fallback in Mapping.', 'oli-product-feed' );
			} elseif ( '' !== $field ) {
				/* translators: %s: attribute. */
				$text = sprintf( __( '%s has several values', 'oli-product-feed' ), $field );
				$help = __( 'Only the first value is sent.', 'oli-product-feed' );
			} elseif ( 'invalid_gtin' === $code ) {
				$text = __( 'gtin is not valid', 'oli-product-feed' );
				$help = __( 'Not sent: check the GTIN of these products.', 'oli-product-feed' );
			} else {
				$text = self::label( $code );
				$help = '';
			}
			$rows[] = array(
				'code'    => $code,
				'text'    => $text,
				'help'    => $help,
				'count'   => isset( $counts[ $code ] ) ? (int) $counts[ $code ] : count( $ids ),
				'ids'     => $ids,
				'section' => 'mapping',
			);
		}
		$channel = $feed ? Registry::get( $feed['channel'] ) : null;
		if ( $channel ) {
			$feed['id'] = isset( $feed['id'] ) ? $feed['id'] : 0;
			foreach ( Editor::checks( $feed, $channel ) as $field => $check ) {
				if ( ! Editor::is_problem( $check ) ) {
					continue;
				}
				$help = 'error' === $check['state'] ? __( 'Required by the platform: these products may be refused.', 'oli-product-feed' ) : __( 'Recommended: these products may get less visibility.', 'oli-product-feed' );
				$same = 'brand' === $field ? array_search( 'no_brand', array_column( $rows, 'code' ), true ) : false;
				if ( false !== $same ) {
					// One row for an empty brand: the engine warning keeps its product IDs.
					$rows[ $same ]['help']  = $help;
					$rows[ $same ]['count'] = max( $rows[ $same ]['count'], (int) $check['count'] );
					continue;
				}
				$rows[] = array(
					'code'    => 'empty:' . $field,
					/* translators: %s: attribute. */
					'text'    => sprintf( __( '%s is empty', 'oli-product-feed' ), $field ),
					'help'    => $help,
					'count'   => (int) $check['count'],
					'ids'     => array(),
					'section' => 'mapping',
				);
			}
		}
		return $rows;
	}

	/**
	 * Warnings table.
	 *
	 * @param int                            $feed_id  Feed ID.
	 * @param array<int,array<string,mixed>> $warnings Rows.
	 */
	private static function warnings_table( $feed_id, array $warnings ) {
		echo '<h2>' . esc_html__( 'Warnings', 'oli-product-feed' ) . '</h2>';
		if ( ! $warnings ) {
			echo '<p>' . esc_html__( 'No warning.', 'oli-product-feed' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Warning', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Products', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'How to fix', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( $warnings as $row ) {
			$count = $row['ids'] ? self::view_link( $feed_id, $row['code'], $row['count'] ) : esc_html( number_format_i18n( $row['count'] ) ) . ' <span class="oliprfe-meta">' . esc_html__( '(list not kept)', 'oli-product-feed' ) . '</span>';
			echo '<tr><td>' . esc_html( $row['text'] ) . '<span class="oliprfe-meta">' . esc_html( $row['help'] ) . '</span></td><td>' . $count . '</td><td>' . self::fix_cell( $feed_id, array( __( 'Review the mapping', 'oli-product-feed' ), $row['section'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above and in fix_cell().
		}
		echo '</tbody></table>';
	}

	/**
	 * Empty values per attribute in the last generation (every attribute, not only a total), with its level.
	 *
	 * @param int                      $feed_id Feed ID.
	 * @param array<string,mixed>|null $feed    Feed.
	 * @param array<string,mixed>      $ok      Last successful run.
	 */
	private static function empty_table( $feed_id, $feed, array $ok ) {
		$empty = isset( $ok['empty'] ) && is_array( $ok['empty'] ) ? array_filter( array_map( 'intval', $ok['empty'] ) ) : array();
		if ( ! $empty ) {
			return;
		}
		arsort( $empty );
		$channel = $feed ? Registry::get( $feed['channel'] ) : null;
		$fields  = $channel ? $channel->fields() : array();
		$levels  = array(
			'required'    => __( 'Required', 'oli-product-feed' ),
			'recommended' => __( 'Recommended', 'oli-product-feed' ),
		);
		echo '<h2>' . esc_html__( 'Empty values by attribute', 'oli-product-feed' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Attribute', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Level', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Products with an empty value', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( $empty as $field => $count ) {
			$level = isset( $fields[ $field ]['level'] ) && isset( $levels[ $fields[ $field ]['level'] ] ) ? $levels[ $fields[ $field ]['level'] ] : __( 'Optional', 'oli-product-feed' );
			$url   = Admin::url(
				array(
					'action' => 'edit',
					'feed'   => $feed_id,
				)
			) . '#mapping';
			echo '<tr><td><a href="' . esc_url( $url ) . '"><code>' . esc_html( (string) $field ) . '</code></a></td><td>' . esc_html( $level ) . '</td><td>' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Products of one reason or warning (the IDs kept by the generator, at most 100), with edit links.
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $ok      Last successful run.
	 * @param string              $code    Code.
	 */
	private static function product_list( $feed_id, array $ok, $code ) {
		$ids   = array();
		$count = 0;
		if ( isset( $ok['excluded'][ $code ] ) ) {
			$ids   = (array) $ok['excluded'][ $code ]['ids'];
			$count = (int) $ok['excluded'][ $code ]['count'];
		} elseif ( isset( $ok['warnings'][ $code ] ) ) {
			$ids   = (array) $ok['warnings'][ $code ];
			$count = isset( $ok['warned'][ $code ] ) ? (int) $ok['warned'][ $code ] : count( $ids );
		}
		$ids = array_slice( array_map( 'intval', $ids ), 0, self::LIST_MAX );
		echo '<div class="oliprfe-panel" id="oliprfe-view" tabindex="-1"><h2>' . esc_html( self::label( $code ) ) . '</h2>';
		if ( ! $ids ) {
			echo '<p>' . esc_html__( 'No product for this reason in the last generation.', 'oli-product-feed' ) . '</p></div>';
			return;
		}
		if ( $count > count( $ids ) ) {
			echo '<p>' . esc_html__( 'Showing the first 100.', 'oli-product-feed' ) . ' ' . esc_html(
				sprintf(
					/* translators: %s: number of products. */
					__( '%s products in total.', 'oli-product-feed' ),
					number_format_i18n( $count )
				)
			) . '</p>';
		}
		echo '<ul>';
		foreach ( $ids as $id ) {
			$title = get_the_title( $id );
			$link  = get_edit_post_link( $id, 'raw' );
			$text  = sprintf( '#%d %s', $id, '' !== $title ? $title : __( '(no title)', 'oli-product-feed' ) );
			echo '<li>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text ) ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( Admin::diag_url( $feed_id ) ) . '">' . esc_html__( 'Close the list', 'oli-product-feed' ) . '</a></p></div>';
	}

	/**
	 * Site checks: scheduler, output folder, last file of the feed, TranslatePress, Price Based on Country.
	 * Each problem has a sentence and a link.
	 *
	 * @param int                      $feed_id Feed ID.
	 * @param array<string,mixed>|null $feed    Feed.
	 * @param array<string,mixed>      $last    Last run.
	 * @return array<int,array<string,mixed>>
	 */
	public static function site_checks( $feed_id, $feed, array $last ) {
		$out    = array();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$runner = Scheduler::use_as() ? 'Action Scheduler' : 'WP-Cron';
		$next   = Scheduler::next_tick();
		$late   = Scheduler::tick_delay();
		$tools  = array( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=oliprfe' ), __( 'WooCommerce › Status › Scheduled Actions', 'oli-product-feed' ) );
		if ( ! $next ) {
			$out[] = array(
				'state' => 'warn',
				/* translators: %s: Action Scheduler or WP-Cron. */
				'text'  => sprintf( __( '%s: the schedule check is not scheduled yet. It is scheduled on the next admin page load.', 'oli-product-feed' ), $runner ),
				'link'  => $tools,
			);
		} elseif ( $late > \HOUR_IN_SECONDS ) {
			$out[] = array(
				'state' => 'error',
				/* translators: 1: Action Scheduler or WP-Cron, 2: delay. */
				'text'  => sprintf( __( '%1$s does not run: the schedule check is %2$s late. WP-Cron may be off without a server cron.', 'oli-product-feed' ), $runner, human_time_diff( time() - $late ) ),
				'link'  => $tools,
			);
		} else {
			$out[] = array(
				'state' => 'ok',
				/* translators: 1: Action Scheduler or WP-Cron, 2: date and time. */
				'text'  => sprintf( __( '%1$s runs. Next schedule check: %2$s.', 'oli-product-feed' ), $runner, wp_date( $format, $next ) ),
			);
		}
		$base     = Storage::base();
		$folder   = $base['dir'];
		$relative = self::relative( $folder );
		$problem  = Storage::problem();
		$settings = array( Admin::url( array( 'tab' => 'settings' ) ), __( 'Settings › Feed files', 'oli-product-feed' ) );
		if ( $problem ) {
			$out[] = array(
				'state' => 'error',
				'text'  => Storage::folder_message( self::relative( $problem['folder'] ), $problem['reason'] ),
				'link'  => $settings,
			);
		} elseif ( self::folder_bad() ) {
			$out[] = array(
				'state' => 'error',
				/* translators: %s: folder. */
				'text'  => sprintf( __( 'Output folder %s is not writable.', 'oli-product-feed' ), $relative ),
				'link'  => $settings,
			);
		} else {
			$out[] = array(
				'state' => 'ok',
				/* translators: %s: folder. */
				'text'  => sprintf( __( 'Output folder %s is writable.', 'oli-product-feed' ), $relative ),
			);
		}
		$path = Storage::path_for( $feed_id );
		$name = basename( $path );
		$edit = array(
			Admin::url( array(
				'action' => 'edit',
				'feed'   => $feed_id,
			) ),
			__( 'Edit feed', 'oli-product-feed' ),
		);
		if ( isset( $last['status'] ) && 'error' === $last['status'] ) {
			$failure = self::failure( $feed_id, $last );
			$out[]   = array(
				'state' => $failure['button'] ? 'warn' : 'error',
				/* translators: 1: file name, 2: error message. */
				'text'  => sprintf( __( '%1$s: the last generation failed. %2$s The previous file stays online.', 'oli-product-feed' ), $name, isset( $last['message'] ) ? $last['message'] : '' ) . ( '' !== $failure['note'] ? ' ' . $failure['note'] : '' ),
				'link'  => $failure['link'],
			);
		} elseif ( file_exists( $path ) ) {
			$out[] = array(
				'state' => 'ok',
				/* translators: 1: file name, 2: date and time, 3: size. */
				'text'  => sprintf( __( '%1$s written %2$s (%3$s).', 'oli-product-feed' ), $name, wp_date( $format, (int) filemtime( $path ) ), size_format( (int) filesize( $path ) ) ),
			);
		} else {
			$out[] = array(
				'state' => 'warn',
				/* translators: %s: file name. */
				'text'  => sprintf( __( '%s has not been written yet: generate the feed.', 'oli-product-feed' ), $name ),
				'link'  => $edit,
			);
		}
		$language = $feed && ! empty( $feed['language'] ) ? (string) $feed['language'] : '';
		if ( Lang::has_translatepress() ) {
			$count = count( Lang::languages() );
			$out[] = array(
				'state' => 'ok',
				/* translators: %s: number of languages. */
				'text'  => sprintf( _n( 'TranslatePress detected: %s language.', 'TranslatePress detected: %s languages.', $count, 'oli-product-feed' ), number_format_i18n( $count ) ),
			);
		} elseif ( '' !== $language && $language !== Lang::default_language() ) {
			$out[] = array(
				'state' => 'error',
				/* translators: %s: language code. */
				'text'  => sprintf( __( 'The feed is in %s but TranslatePress is not active: texts are sent in the store language.', 'oli-product-feed' ), $language ),
				'link'  => array( admin_url( 'plugins.php' ), __( 'Plugins', 'oli-product-feed' ) ),
			);
		} else {
			$out[] = array(
				'state' => 'info',
				'text'  => __( 'TranslatePress not detected: one language.', 'oli-product-feed' ),
			);
		}
		$pbc = $feed && isset( $feed['price_source'] ) && 'woocommerce' !== $feed['price_source'];
		if ( PriceBasedOnCountry::available() ) {
			$out[] = array(
				'state' => 'ok',
				'text'  => __( 'Price Based on Country detected.', 'oli-product-feed' ),
			);
		} elseif ( $pbc ) {
			$out[] = array(
				'state' => 'error',
				'text'  => __( 'The feed uses the prices of another plugin that is not active: the store prices are sent.', 'oli-product-feed' ),
				'link'  => array(
					Admin::url( array(
						'action' => 'edit',
						'feed'   => $feed_id,
					) ) . '#target',
					__( 'Edit feed', 'oli-product-feed' ),
				),
			);
		} else {
			$out[] = array(
				'state' => 'info',
				'text'  => __( 'Price Based on Country not detected: prices use the store currency.', 'oli-product-feed' ),
			);
		}
		return $out;
	}

	/**
	 * Is the output folder unusable right now (refused, or not writable)?
	 *
	 * @return bool
	 */
	private static function folder_bad() {
		if ( Storage::problem() ) {
			return true;
		}
		$base = Storage::base();
		return is_dir( $base['dir'] ) && ! wp_is_writable( $base['dir'] );
	}

	/**
	 * What to do after a failed generation: a folder failure links to Settings › Feed files while the
	 * folder is still unusable, or offers "Generate now" once it is writable again; any other failure
	 * links to the feed editor.
	 *
	 * @param int                 $feed_id Feed ID.
	 * @param array<string,mixed> $last    Last run (error).
	 * @return array{note:string,link:array{0:string,1:string},button:bool}
	 */
	public static function failure( $feed_id, array $last ) {
		$code   = isset( $last['code'] ) ? (string) $last['code'] : '';
		$folder = in_array( $code, array( 'oliprfe_dir', 'oliprfe_write' ), true ) || ( '' === $code && false !== stripos( isset( $last['message'] ) ? (string) $last['message'] : '', 'folder' ) );
		if ( $folder && self::folder_bad() ) {
			return array(
				'note'   => '',
				'link'   => array( Admin::url( array( 'tab' => 'settings' ) ), __( 'Settings › Feed files', 'oli-product-feed' ) ),
				'button' => false,
			);
		}
		if ( $folder ) {
			return array(
				'note'   => __( 'The folder is writable now: generate the feed again.', 'oli-product-feed' ),
				'link'   => array( Admin::action_url( 'oliprfe_generate', $feed_id ), __( 'Generate now', 'oli-product-feed' ) ),
				'button' => true,
			);
		}
		return array(
			'note'   => '',
			'link'   => array(
				Admin::url(
					array(
						'action' => 'edit',
						'feed'   => $feed_id,
					)
				),
				__( 'Edit feed', 'oli-product-feed' ),
			),
			'button' => false,
		);
	}

	/**
	 * Folder relative to wp-content (no full server path on screen or in the report).
	 *
	 * @param string $folder Absolute folder.
	 * @return string
	 */
	private static function relative( $folder ) {
		$folder = wp_normalize_path( (string) $folder );
		$root   = wp_normalize_path( \WP_CONTENT_DIR );
		return 0 === strpos( $folder, $root ) ? ltrim( substr( $folder, strlen( $root ) ), '/' ) . '/' : basename( $folder ) . '/';
	}

	/**
	 * Status badge: decorative icon + text.
	 *
	 * @param string $state ok|warn|error|info.
	 * @return string
	 */
	private static function badge( $state ) {
		$map = array(
			'ok'    => array( 'oliprfe-is-ok', 'yes-alt', __( 'OK', 'oli-product-feed' ) ),
			'warn'  => array( 'oliprfe-is-warn', 'warning', __( 'Check', 'oli-product-feed' ) ),
			'error' => array( 'oliprfe-is-error', 'warning', __( 'Problem', 'oli-product-feed' ) ),
			'info'  => array( 'oliprfe-is-off', 'info-outline', __( 'Info', 'oli-product-feed' ) ),
		);
		$m   = isset( $map[ $state ] ) ? $map[ $state ] : $map['info'];
		return '<span class="oliprfe-status ' . esc_attr( $m[0] ) . '"><span class="dashicons dashicons-' . esc_attr( $m[1] ) . '" aria-hidden="true"></span>' . esc_html( $m[2] ) . '</span>';
	}

	/**
	 * Plain-text report: versions, feed settings that matter, last generation, reasons, site checks.
	 * No key, no email, no full site URL (only the feed file name).
	 *
	 * @param int                            $feed_id Feed ID.
	 * @param array<string,mixed>|null       $feed    Feed.
	 * @param array<string,mixed>            $last    Last run.
	 * @param array<string,mixed>            $ok      Last successful run.
	 * @param array<int,array<string,mixed>> $checks  Site checks.
	 * @return string
	 */
	public static function report( $feed_id, $feed, array $last, array $ok, array $checks ) {
		global $wp_version;
		$lines   = array( 'Oli Product Feed diagnostic report' );
		$lines[] = sprintf( 'Plugin %s · WordPress %s · WooCommerce %s · PHP %s', \OLIPRFE_VERSION, $wp_version, defined( 'WC_VERSION' ) ? \WC_VERSION : '-', \PHP_VERSION );
		$lines[] = sprintf( 'Generated: %s', gmdate( 'Y-m-d H:i' ) . ' UTC' );
		$lines[] = '';
		if ( $feed ) {
			$lines[] = sprintf( 'Feed #%d · channel %s · file %s · %s', $feed_id, $feed['channel'], basename( Storage::path_for( $feed_id ) ), 'publish' === $feed['status'] ? 'active' : 'inactive' );
			$lines[] = sprintf( 'Language %s · country %s · currency %s · price source %s · frequency %s', '' !== $feed['language'] ? $feed['language'] : '-', '' !== $feed['country'] ? $feed['country'] : '-', '' !== $feed['currency'] ? $feed['currency'] : '-', $feed['price_source'], $feed['frequency'] );
		}
		if ( isset( $last['status'] ) && 'error' === $last['status'] ) {
			$lines[] = sprintf( 'Last generation failed: %s', isset( $last['message'] ) ? $last['message'] : '' );
		}
		if ( isset( $ok['items'] ) ) {
			$checked = max( (int) $ok['items'], (int) ( isset( $ok['candidates'] ) ? $ok['candidates'] : 0 ) );
			$lines[] = sprintf( 'Last successful generation: %s UTC · %s s · %s bytes', gmdate( 'Y-m-d H:i', (int) $ok['time'] ), $ok['duration'], isset( $ok['bytes'] ) ? (int) $ok['bytes'] : 0 );
			$lines[] = sprintf( 'Checked %d · in the feed %d · excluded %d', $checked, (int) $ok['items'], $checked - (int) $ok['items'] );
			foreach ( isset( $ok['excluded'] ) ? (array) $ok['excluded'] : array() as $code => $info ) {
				$lines[] = sprintf( '  excluded %s: %d', $code, (int) $info['count'] );
			}
			foreach ( self::warnings( $feed, $ok ) as $row ) {
				$lines[] = sprintf( '  warning %s: %d', $row['code'], (int) $row['count'] );
			}
			foreach ( isset( $ok['empty'] ) ? array_filter( array_map( 'intval', (array) $ok['empty'] ) ) : array() as $field => $count ) {
				$lines[] = sprintf( '  empty %s: %d', $field, $count );
			}
		}
		$lines[] = '';
		$lines[] = 'Site checks';
		foreach ( $checks as $check ) {
			$lines[] = sprintf( '  [%s] %s', $check['state'], $check['text'] );
		}
		$out = implode( "\n", $lines );
		// Never a full URL of the site or an email address in the report.
		$out = str_replace( array( home_url(), site_url() ), '(site)', $out );
		return (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '(email)', $out );
	}
}
