<?php
/**
 * Feed list (WP_List_Table): 6 columns, views, channel filter, search, sorting, Screen Options.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Channels\Registry;
use Oliprfe\Feeds;
use Oliprfe\Generator;
use Oliprfe\Scheduler;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Feed list table.
 */
final class ListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'feed',
				'plural'   => 'feeds',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns (also listed in Screen Options).
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'cb'             => '<input type="checkbox">',
			'name'           => __( 'Name', 'oli-product-feed' ),
			'target'         => __( 'Target', 'oli-product-feed' ),
			'items'          => __( 'Items', 'oli-product-feed' ),
			'status'         => __( 'Status', 'oli-product-feed' ),
			'last_generated' => __( 'Last generated', 'oli-product-feed' ),
			'url'            => __( 'Feed URL', 'oli-product-feed' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return self::columns();
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	protected function get_sortable_columns() {
		return array(
			'name'           => array( 'name', false ),
			'last_generated' => array( 'last_generated', true ),
		);
	}

	/**
	 * Primary column (row actions, "Show more details" on small screens).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'generate'   => __( 'Generate now', 'oli-product-feed' ),
			'activate'   => __( 'Activate', 'oli-product-feed' ),
			'deactivate' => __( 'Deactivate', 'oli-product-feed' ),
			'delete'     => __( 'Delete', 'oli-product-feed' ),
		);
	}

	/**
	 * Current view: all, active, errors or inactive.
	 *
	 * @return string
	 */
	public static function current_view() {
		$view = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// 0.5 link: ?channel=errors.
		if ( '' === $view && isset( $_GET['channel'] ) && 'errors' === $_GET['channel'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$view = 'errors';
		}
		return in_array( $view, array( 'active', 'errors', 'inactive' ), true ) ? $view : 'all';
	}

	/**
	 * Does a feed belong to a view? Active: published and not in error. Errors: the last generation
	 * failed. Inactive: not published.
	 *
	 * @param int    $feed_id Feed ID.
	 * @param string $view    View.
	 * @return bool
	 */
	public static function in_view( $feed_id, $view ) {
		$error = self::has_error( $feed_id );
		switch ( $view ) {
			case 'active':
				return 'publish' === get_post_status( $feed_id );
			case 'errors':
				return $error;
			case 'inactive':
				return 'publish' !== get_post_status( $feed_id );
		}
		return true;
	}

	/**
	 * Views All / Active / Errors / Inactive with exact counts.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = self::current_view();
		$labels  = array(
			'all'      => __( 'All', 'oli-product-feed' ),
			'active'   => __( 'Active', 'oli-product-feed' ),
			'errors'   => __( 'Errors', 'oli-product-feed' ),
			'inactive' => __( 'Inactive', 'oli-product-feed' ),
		);
		$ids     = Feeds::all_ids();
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$count = 0;
			foreach ( $ids as $id ) {
				$count += self::in_view( $id, $view ) ? 1 : 0;
			}
			$url            = 'all' === $view ? Admin::url() : Admin::url( array( 'status' => $view ) );
			$views[ $view ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $view ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $count . ')</span></a>';
		}
		return $views;
	}

	/**
	 * Did the last generation of a feed fail?
	 *
	 * @param int $feed_id Feed ID.
	 * @return bool
	 */
	public static function has_error( $feed_id ) {
		$last = Feeds::last_run( (int) $feed_id );
		return isset( $last['status'] ) && 'error' === $last['status'];
	}

	/**
	 * Channel filter next to the bulk actions.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions"><label for="oliprfe-filter-channel" class="screen-reader-text">' . esc_html__( 'Filter by channel', 'oli-product-feed' ) . '</label>';
		echo '<select name="channel" id="oliprfe-filter-channel"><option value="">' . esc_html__( 'All channels', 'oli-product-feed' ) . '</option>';
		foreach ( Registry::all() as $channel ) {
			echo '<option value="' . esc_attr( $channel->id() ) . '"' . selected( $current, $channel->id(), false ) . '>' . esc_html( $channel->label() ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'oli-product-feed' ), '', 'filter_action', false, array( 'id' => 'oliprfe-filter-submit' ) );
		echo '</div>';
	}

	/**
	 * Loads the rows: view, channel, search, sorting and pagination.
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : '';
		$search  = isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'desc' : 'asc';
		// phpcs:enable
		$view  = self::current_view();
		$items = array();
		foreach ( Feeds::all_ids() as $id ) {
			$feed = Feeds::get( $id );
			if ( ! $feed || ! self::in_view( $id, $view ) ) {
				continue;
			}
			if ( '' !== $channel && 'errors' !== $channel && $feed['channel'] !== $channel ) {
				continue;
			}
			if ( '' !== $search && false === mb_stripos( $feed['name'] . ' ' . $feed['file_slug'], $search ) ) {
				continue;
			}
			$items[] = $feed;
		}
		if ( in_array( $orderby, array( 'name', 'last_generated' ), true ) ) {
			usort(
				$items,
				static function ( $a, $b ) use ( $orderby, $order ) {
					if ( 'name' === $orderby ) {
						$cmp = strnatcasecmp( $a['name'], $b['name'] );
					} else {
						$la  = Admin::last_success( $a['id'] );
						$lb  = Admin::last_success( $b['id'] );
						$cmp = ( isset( $la['time'] ) ? (int) $la['time'] : 0 ) - ( isset( $lb['time'] ) ? (int) $lb['time'] : 0 );
					}
					return 'desc' === $order ? -$cmp : $cmp;
				}
			);
		}
		$per_page              = $this->get_items_per_page( 'oliprfe_feeds_per_page', 20 );
		$total                 = count( $items );
		$this->_column_headers = array( $this->get_columns(), get_hidden_columns( $this->screen ), $this->get_sortable_columns(), 'name' );
		$this->items           = array_slice( $items, ( $this->get_pagenum() - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Checkbox column.
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	protected function column_cb( $item ) {
		/* translators: %s: feed name. */
		$label = sprintf( __( 'Select %s', 'oli-product-feed' ), '' !== $item['name'] ? $item['name'] : __( '(no name)', 'oli-product-feed' ) );
		return '<label class="screen-reader-text" for="oliprfe-cb-' . (int) $item['id'] . '">' . esc_html( $label ) . '</label><input type="checkbox" id="oliprfe-cb-' . (int) $item['id'] . '" name="feed_ids[]" value="' . (int) $item['id'] . '" data-name="' . esc_attr( $item['name'] ) . '">';
	}

	/**
	 * Name column: name, channel and format.
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	protected function column_name( $item ) {
		$channel = Registry::get( $item['channel'] );
		return '<strong><a class="row-title" href="' . esc_url( self::edit_url( $item ) ) . '">' . esc_html( '' !== $item['name'] ? $item['name'] : __( '(no name)', 'oli-product-feed' ) ) . '</a></strong>'
			. '<span class="oliprfe-meta">' . esc_html( ( $channel ? $channel->label() : $item['channel'] ) . ' · ' . strtoupper( $item['format'] ) ) . '</span>';
	}

	/**
	 * Editor URL of a feed.
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	private static function edit_url( array $item ) {
		return Admin::url(
			array(
				'action' => 'edit',
				'feed'   => (int) $item['id'],
			)
		);
	}

	/**
	 * Row actions of the primary column: Edit, Generate now, Preview, Duplicate, Delete.
	 *
	 * @param array<string,mixed> $item        Feed.
	 * @param string              $column_name Column.
	 * @param string              $primary     Primary column.
	 * @return string
	 */
	protected function handle_row_actions( $item, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}
		$id      = (int) $item['id'];
		$edit    = self::edit_url( $item );
		$actions = array(
			'edit' => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'oli-product-feed' ) . '</a>',
		);
		if ( Generator::is_running( $id ) ) {
			$actions['generate'] = '<a role="link" aria-disabled="true" title="' . esc_attr__( 'A generation is already running for this feed.', 'oli-product-feed' ) . '">' . esc_html__( 'Generate now', 'oli-product-feed' ) . '</a>';
		} else {
			$actions['generate'] = '<a href="' . esc_url( Admin::action_url( 'oliprfe_generate', $id ) ) . '">' . esc_html__( 'Generate now', 'oli-product-feed' ) . '</a>';
		}
		$actions['preview']   = '<a href="' . esc_url( Editor::preview_url( $id ) ) . '">' . esc_html__( 'Preview', 'oli-product-feed' ) . '</a>';
		$actions['duplicate'] = '<a href="' . esc_url( Admin::action_url( 'oliprfe_duplicate_feed', $id ) ) . '">' . esc_html__( 'Duplicate', 'oli-product-feed' ) . '</a>';
		$actions['delete']    = '<a class="submitdelete" ' . Admin::delete_attrs( $id ) . '>' . esc_html__( 'Delete', 'oli-product-feed' ) . '</a>';
		return $this->row_actions( $actions );
	}

	/**
	 * Language code of a feed (the site language when the feed has none).
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	private static function language_code( array $item ) {
		$locale = '' !== $item['language'] ? $item['language'] : determine_locale();
		return strtolower( (string) preg_replace( '/[_-].*$/', '', $locale ) );
	}

	/**
	 * Target cell: language · country · currency (full names for screen readers in the title).
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	private static function target_cell( array $item ) {
		$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
		$country   = isset( $countries[ $item['country'] ] ) ? $countries[ $item['country'] ] : $item['country'];
		$text      = implode( ' · ', array_filter( array( self::language_code( $item ), $item['country'], $item['currency'] ) ) );
		return '<span title="' . esc_attr( html_entity_decode( (string) $country, ENT_QUOTES, 'UTF-8' ) ) . '">' . esc_html( '' !== $text ? $text : '—' ) . '</span>';
	}

	/**
	 * Items cell: items of the last successful generation and a link to the excluded products.
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	public static function items_cell( array $item ) {
		$ok = Admin::last_success( (int) $item['id'] );
		if ( ! isset( $ok['items'] ) ) {
			return '—';
		}
		$excluded = max( 0, (int) ( isset( $ok['candidates'] ) ? $ok['candidates'] : 0 ) - (int) $ok['items'] );
		$html     = esc_html( number_format_i18n( (int) $ok['items'] ) );
		if ( $excluded ) {
			$url = Admin::diag_url( (int) $item['id'] );
			/* translators: %s: number of excluded products. */
			$text  = sprintf( __( '%s excluded', 'oli-product-feed' ), number_format_i18n( $excluded ) );
			$html .= '<a class="oliprfe-meta" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
		}
		return $html;
	}

	/**
	 * Schedule label of a feed (e.g. "Daily at 3:00 am", "Manual only").
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	public static function schedule_label( array $item ) {
		$freq = array(
			'manual'     => __( 'Manual only', 'oli-product-feed' ),
			'hourly'     => __( 'Hourly', 'oli-product-feed' ),
			'twicedaily' => __( 'Twice a day', 'oli-product-feed' ),
			'daily'      => __( 'Daily', 'oli-product-feed' ),
			'weekly'     => __( 'Weekly', 'oli-product-feed' ),
		);
		$label = isset( $freq[ $item['frequency'] ] ) ? $freq[ $item['frequency'] ] : $item['frequency'];
		if ( $item['start_hour'] >= 0 && in_array( $item['frequency'], array( 'daily', 'weekly' ), true ) ) {
			$time = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( (int) $item['start_hour'], 0 );
			/* translators: 1: frequency, e.g. "Daily", 2: time. */
			$label = sprintf( __( '%1$s at %2$s', 'oli-product-feed' ), $label, wp_date( get_option( 'time_format' ), $time->getTimestamp() ) );
		}
		return $label;
	}

	/**
	 * Date of a run, in the site format.
	 *
	 * @param int $time Timestamp.
	 * @return string
	 */
	private static function date( $time ) {
		/* translators: 1: date, 2: time. */
		$text = sprintf( __( '%1$s at %2$s', 'oli-product-feed' ), wp_date( get_option( 'date_format' ), $time ), wp_date( get_option( 'time_format' ), $time ) );
		return '<time datetime="' . esc_attr( wp_date( 'c', $time ) ) . '">' . esc_html( $text ) . '</time>';
	}

	/**
	 * Last generated cell: date of the last successful file and the next run (or why there is none).
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	public static function last_cell( array $item ) {
		$id   = (int) $item['id'];
		$last = Feeds::last_run( $id );
		$ok   = Admin::last_success( $id );
		$html = ! empty( $ok['time'] ) ? self::date( (int) $ok['time'] ) : '—';
		if ( Generator::is_running( $id ) ) {
			$meta = __( 'Running now', 'oli-product-feed' );
		} elseif ( isset( $last['status'] ) && 'error' === $last['status'] ) {
			$meta = ! empty( $ok['time'] ) ? __( 'Last success', 'oli-product-feed' ) : __( 'No successful generation yet', 'oli-product-feed' );
		} elseif ( 'publish' !== $item['status'] ) {
			$meta = 'manual' === $item['frequency'] ? __( 'Manual only', 'oli-product-feed' ) : __( 'Not scheduled (inactive)', 'oli-product-feed' );
		} elseif ( empty( $last['time'] ) ) {
			$meta = self::schedule_label( $item );
		} else {
			$next = Scheduler::next_run( $item, $last );
			/* translators: %s: date and time of the next generation. */
			$meta = $next ? sprintf( __( 'Next: %s', 'oli-product-feed' ), wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $next ) ) : __( 'Manual only', 'oli-product-feed' );
		}
		return $html . '<span class="oliprfe-meta">' . esc_html( $meta ) . '</span>';
	}

	/**
	 * Feed URL cell.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function url_cell( $feed_id ) {
		ob_start();
		Admin::feed_url_field( (int) $feed_id, 'list' );
		return (string) ob_get_clean();
	}

	/**
	 * Other columns.
	 *
	 * @param array<string,mixed> $item        Feed.
	 * @param string              $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'target':
				return self::target_cell( $item );
			case 'items':
				return self::items_cell( $item );
			case 'status':
				return Admin::status_cell( (int) $item['id'] );
			case 'last_generated':
				return self::last_cell( $item );
			case 'url':
				return self::url_cell( (int) $item['id'] );
		}
		return '';
	}

	/**
	 * Row with the feed ID (used by the progress refresh).
	 *
	 * @param array<string,mixed> $item Feed.
	 */
	public function single_row( $item ) {
		echo '<tr data-oliprfe-row="' . (int) $item['id'] . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * No feed matches the view, the filter or the search.
	 */
	public function no_items() {
		esc_html_e( 'No feed matches.', 'oli-product-feed' );
	}
}
