<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Feed list (WP_List_Table).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Channels\Registry;
use Oliprfe\Feeds;
use Oliprfe\Lang;

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
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'             => '<input type="checkbox">',
			'name'           => __( 'Name', 'oli-product-feed' ),
			'language'       => __( 'Language', 'oli-product-feed' ),
			'country'        => __( 'Country', 'oli-product-feed' ),
			'currency'       => __( 'Currency', 'oli-product-feed' ),
			'products'       => __( 'Products', 'oli-product-feed' ),
			'last_generated' => __( 'Last generated', 'oli-product-feed' ),
			'status'         => __( 'Status', 'oli-product-feed' ),
			'url'            => __( 'Feed URL', 'oli-product-feed' ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'generate' => __( 'Generate now', 'oli-product-feed' ),
			'delete'   => __( 'Delete', 'oli-product-feed' ),
		);
	}

	/**
	 * Views by channel.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$counts  = array();
		$all     = 0;
		foreach ( Feeds::all_ids() as $id ) {
			$feed = Feeds::get( $id );
			if ( $feed ) {
				$counts[ $feed['channel'] ] = isset( $counts[ $feed['channel'] ] ) ? $counts[ $feed['channel'] ] + 1 : 1;
				++$all;
			}
		}
		$base  = Admin::url();
		$views = array(
			'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'All', 'oli-product-feed' ) . ' <span class="count">(' . (int) $all . ')</span></a>',
		);
		foreach ( $counts as $channel_id => $count ) {
			$channel = Registry::get( $channel_id );
			if ( $channel ) {
				$views[ $channel_id ] = '<a href="' . esc_url( add_query_arg( 'channel', $channel_id, $base ) ) . '"' . ( $current === $channel_id ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $channel->label() ) . ' <span class="count">(' . (int) $count . ')</span></a>';
			}
		}
		$errors = count( array_filter( Feeds::all_ids(), array( __CLASS__, 'has_error' ) ) );
		if ( $errors ) {
			$views['errors'] = '<a href="' . esc_url( add_query_arg( 'channel', 'errors', $base ) ) . '"' . ( 'errors' === $current ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html__( 'Errors', 'oli-product-feed' ) . ' <span class="count">(' . (int) $errors . ')</span></a>';
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
	 * Loads the rows.
	 */
	public function prepare_items() {
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_REQUEST['s'] ) ? trim( sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$items   = array();
		foreach ( Feeds::all_ids() as $id ) {
			$feed = Feeds::get( $id );
			if ( ! $feed ) {
				continue;
			}
			if ( 'errors' === $channel ? ! self::has_error( $id ) : ( '' !== $channel && $feed['channel'] !== $channel ) ) {
				continue;
			}
			if ( '' !== $search && false === mb_stripos( $feed['name'] . ' ' . $feed['file_slug'], $search ) ) {
				continue;
			}
			$items[] = $feed;
		}
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $items;
		$this->set_pagination_args(
			array(
				'total_items' => count( $items ),
				'per_page'    => max( 1, count( $items ) ),
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
		return '<label class="screen-reader-text" for="oliprfe-cb-' . (int) $item['id'] . '">' . esc_html( $item['name'] ) . '</label><input type="checkbox" id="oliprfe-cb-' . (int) $item['id'] . '" name="feed_ids[]" value="' . (int) $item['id'] . '">';
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array<string,mixed> $item Feed.
	 * @return string
	 */
	protected function column_name( $item ) {
		$id      = (int) $item['id'];
		$edit    = add_query_arg(
			array(
				'action' => 'edit',
				'feed'   => $id,
			),
			Admin::url()
		);
		$channel = Registry::get( $item['channel'] );
		$freq    = array(
			'manual'     => __( 'Manual', 'oli-product-feed' ),
			'hourly'     => __( 'Hourly', 'oli-product-feed' ),
			'twicedaily' => __( 'Twice a day', 'oli-product-feed' ),
			'daily'      => __( 'Daily', 'oli-product-feed' ),
			'weekly'     => __( 'Weekly', 'oli-product-feed' ),
		);
		$sched   = $freq[ $item['frequency'] ];
		if ( $item['start_hour'] >= 0 && in_array( $item['frequency'], array( 'daily', 'weekly' ), true ) ) {
			$sched .= ' ' . sprintf( '%02d:00', (int) $item['start_hour'] );
		}
		$actions = array(
			'edit'      => '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'oli-product-feed' ) . '</a>',
			'generate'  => '<a href="' . esc_url( Admin::action_url( 'oliprfe_generate', $id ) ) . '">' . esc_html__( 'Generate now', 'oli-product-feed' ) . '</a>',
			'duplicate' => '<a href="' . esc_url( Admin::action_url( 'oliprfe_duplicate_feed', $id ) ) . '">' . esc_html__( 'Duplicate', 'oli-product-feed' ) . '</a>',
			'delete'    => '<a class="submitdelete oliprfe-confirm" href="' . esc_url( Admin::action_url( 'oliprfe_delete_feed', $id ) ) . '" data-confirm="' . esc_attr__( 'Delete this feed and its file?', 'oli-product-feed' ) . '">' . esc_html__( 'Delete', 'oli-product-feed' ) . '</a>',
		);
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( '' !== $item['name'] ? $item['name'] : __( '(no name)', 'oli-product-feed' ) ) . '</a></strong>'
			. '<span class="oliprfe-meta">' . esc_html( ( $channel ? $channel->label() : $item['channel'] ) . ' · ' . strtoupper( $item['format'] ) . ' · ' . $sched ) . '</span>'
			. $this->row_actions( $actions );
	}

	/**
	 * Other columns.
	 *
	 * @param array<string,mixed> $item        Feed.
	 * @param string              $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$id   = (int) $item['id'];
		$last = Feeds::last_run( $id );
		switch ( $column_name ) {
			case 'language':
				$langs = Lang::languages();
				return esc_html( isset( $langs[ $item['language'] ] ) ? $langs[ $item['language'] ] : ( '' !== $item['language'] ? $item['language'] : '—' ) );
			case 'country':
				$countries = WC()->countries->get_countries();
				return esc_html( isset( $countries[ $item['country'] ] ) ? $countries[ $item['country'] ] : '—' );
			case 'currency':
				return esc_html( $item['currency'] );
			case 'products':
				return isset( $last['items'] ) ? esc_html( number_format_i18n( (int) $last['items'] ) ) : '—';
			case 'last_generated':
				if ( empty( $last['time'] ) ) {
					return '—';
				}
				$abs = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] );
				/* translators: %s: human time difference. */
				return '<time datetime="' . esc_attr( wp_date( 'c', (int) $last['time'] ) ) . '" title="' . esc_attr( $abs ) . '">' . esc_html( sprintf( __( '%s ago', 'oli-product-feed' ), human_time_diff( (int) $last['time'] ) ) ) . '</time>';
			case 'status':
				return Admin::status_cell( $id );
			case 'url':
				ob_start();
				Admin::feed_url_field( $id, 'list' );
				return (string) ob_get_clean();
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		esc_html_e( 'No feed yet.', 'oli-product-feed' );
	}
}
