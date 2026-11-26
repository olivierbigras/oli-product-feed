<?php
/**
 * Activity log tab: native list table of the events (Date, Feed, Event, By).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Activity;
use Oliprfe\Feeds;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Activity list table: filters by feed and event, 20 per page.
 */
final class ActivityTable extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Feed filter (0: every feed).
	 *
	 * @var int
	 */
	private $feed = 0;

	/**
	 * Event filter ('' : every event).
	 *
	 * @var string
	 */
	private $event = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'event',
				'plural'   => 'events',
				'ajax'     => false,
			)
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$this->feed  = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		$event       = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		$this->event = in_array( $event, Activity::EVENTS, true ) ? $event : '';
		// phpcs:enable
	}

	/**
	 * Event labels.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'generated'   => __( 'Generated', 'oli-product-feed' ),
			'failed'      => __( 'Failed', 'oli-product-feed' ),
			'queued'      => __( 'Queued', 'oli-product-feed' ),
			'cancelled'   => __( 'Cancelled', 'oli-product-feed' ),
			'created'     => __( 'Created', 'oli-product-feed' ),
			'edited'      => __( 'Edited', 'oli-product-feed' ),
			'activated'   => __( 'Activated', 'oli-product-feed' ),
			'deactivated' => __( 'Deactivated', 'oli-product-feed' ),
			'deleted'     => __( 'Deleted', 'oli-product-feed' ),
			'settings'    => __( 'Settings saved', 'oli-product-feed' ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'date'  => __( 'Date', 'oli-product-feed' ),
			'feed'  => __( 'Feed', 'oli-product-feed' ),
			'event' => __( 'Event', 'oli-product-feed' ),
			'by'    => __( 'By', 'oli-product-feed' ),
		);
	}

	/**
	 * Table classes (oliprfe-log: small-screen rules of admin.css).
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array_merge( parent::get_table_classes(), array( 'oliprfe-log' ) );
	}

	/**
	 * Primary column: the event (on small screens the date, feed and author are under "Show more details").
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'event';
	}

	/**
	 * Loads the filtered page.
	 */
	public function prepare_items() {
		$feed    = $this->feed;
		$event   = $this->event;
		$entries = array_values(
			array_filter(
				Activity::all(),
				static function ( $entry ) use ( $feed, $event ) {
					return ( ! $feed || (int) $entry['f'] === $feed ) && ( '' === $event || $entry['e'] === $event );
				}
			)
		);
		$total   = count( $entries );
		$page    = $this->get_pagenum();
		$this->items           = array_slice( $entries, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'event' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Empty table.
	 */
	public function no_items() {
		esc_html_e( 'No activity yet.', 'oli-product-feed' );
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions"><label for="oliprfe-log-feed" class="screen-reader-text">' . esc_html__( 'Filter by feed', 'oli-product-feed' ) . '</label><select id="oliprfe-log-feed" name="feed"><option value="0">' . esc_html__( 'All feeds', 'oli-product-feed' ) . '</option>';
		foreach ( Feeds::all_ids() as $id ) {
			echo '<option value="' . (int) $id . '"' . selected( $id, $this->feed, false ) . '>' . esc_html( get_the_title( $id ) ) . '</option>';
		}
		echo '</select><label for="oliprfe-log-event" class="screen-reader-text">' . esc_html__( 'Filter by event', 'oli-product-feed' ) . '</label><select id="oliprfe-log-event" name="event"><option value="">' . esc_html__( 'All events', 'oli-product-feed' ) . '</option>';
		foreach ( self::labels() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $this->event, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'oli-product-feed' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Date (site format).
	 *
	 * @param array<string,mixed> $item Entry.
	 * @return string
	 */
	public function column_date( $item ) {
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['t'] ) );
	}

	/**
	 * Feed (link while it exists).
	 *
	 * @param array<string,mixed> $item Entry.
	 * @return string
	 */
	public function column_feed( $item ) {
		$id = (int) $item['f'];
		if ( ! $id ) {
			return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No feed', 'oli-product-feed' ) . '</span>';
		}
		if ( Feeds::get( $id ) ) {
			return '<a href="' . esc_url( Admin::url( array(
				'action' => 'edit',
				'feed'   => $id,
			) ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>';
		}
		/* translators: %s: feed name. */
		return esc_html( sprintf( __( '%s (deleted)', 'oli-product-feed' ), '' !== (string) $item['n'] ? $item['n'] : '#' . $id ) );
	}

	/**
	 * Event: icon + text + detail.
	 *
	 * @param array<string,mixed> $item Entry.
	 * @return string
	 */
	public function column_event( $item ) {
		$labels = self::labels();
		$event  = (string) $item['e'];
		$label  = isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
		$data   = isset( $item['d'] ) && is_array( $item['d'] ) ? $item['d'] : array();
		$icons  = array(
			'generated'   => array( 'oliprfe-is-ok', 'yes-alt' ),
			'failed'      => array( 'oliprfe-is-error', 'warning' ),
			'deactivated' => array( 'oliprfe-is-off', 'controls-pause' ),
			'cancelled'   => array( 'oliprfe-is-off', 'dismiss' ),
		);
		$icon   = isset( $icons[ $event ] ) ? $icons[ $event ] : array( 'oliprfe-is-off', 'marker' );
		$html   = '<span class="oliprfe-status ' . esc_attr( $icon[0] ) . '"><span class="dashicons dashicons-' . esc_attr( $icon[1] ) . '" aria-hidden="true"></span>' . esc_html( $label ) . '</span>';
		$detail = '';
		if ( 'generated' === $event && isset( $data['items'] ) ) {
			$detail = sprintf(
				/* translators: 1: items, 2: excluded products, 3: seconds. */
				__( '%1$s items, %2$s excluded · %3$s s', 'oli-product-feed' ),
				number_format_i18n( (int) $data['items'] ),
				number_format_i18n( (int) $data['excluded'] ),
				number_format_i18n( (float) $data['duration'], 1 )
			);
		} elseif ( ! empty( $data['message'] ) ) {
			$detail = (string) $data['message'];
		}
		return $html . ( '' !== $detail ? '<span class="oliprfe-meta">' . esc_html( $detail ) . '</span>' : '' );
	}

	/**
	 * Author: Schedule, Product update or the user name.
	 *
	 * @param array<string,mixed> $item Entry.
	 * @return string
	 */
	public function column_by( $item ) {
		$by = $item['b'];
		if ( 'schedule' === $by ) {
			return esc_html__( 'Schedule', 'oli-product-feed' );
		}
		if ( 'product' === $by ) {
			return esc_html__( 'Product update', 'oli-product-feed' );
		}
		if ( is_numeric( $by ) && (int) $by ) {
			$user = get_userdata( (int) $by );
			return esc_html( $user ? $user->display_name : '#' . (int) $by );
		}
		return '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Unknown', 'oli-product-feed' ) . '</span>';
	}
}
