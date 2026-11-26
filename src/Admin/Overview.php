<?php
/**
 * Feeds screen overview: summary tiles, error notice and generation notices (local data only).
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Feeds;

defined( 'ABSPATH' ) || exit;

/**
 * Overview of the feeds (reads the last runs stored by the generator: no loop over the catalog).
 */
final class Overview {

	/**
	 * Totals of every feed.
	 *
	 * @return array{total:int,active:int,items:int,excluded:int,errors:int[],running:int[]}
	 */
	public static function stats() {
		$out = array(
			'total'    => 0,
			'active'   => 0,
			'items'    => 0,
			'excluded' => 0,
			'errors'   => array(),
			'running'  => array(),
		);
		foreach ( Feeds::all_ids() as $id ) {
			++$out['total'];
			$key = Admin::status_key( $id );
			if ( 'error' === $key ) {
				$out['errors'][] = $id;
			} elseif ( 'generating' === $key ) {
				$out['running'][] = $id;
			}
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			++$out['active'];
			$ok = Admin::last_success( $id );
			if ( isset( $ok['items'] ) ) {
				$out['items']    += (int) $ok['items'];
				$out['excluded'] += max( 0, (int) ( isset( $ok['candidates'] ) ? $ok['candidates'] : 0 ) - (int) $ok['items'] );
			}
		}
		return $out;
	}

	/**
	 * Summary tiles. "Feed with an error" only when at least one feed failed.
	 *
	 * @param array<string,mixed> $stats Totals from stats().
	 */
	public static function tiles( array $stats ) {
		$diag = Admin::diag_url();
		echo '<ul class="oliprfe-stats">';
		/* translators: 1: active feeds, 2: all feeds. */
		echo '<li><b>' . esc_html( sprintf( __( '%1$s of %2$s', 'oli-product-feed' ), number_format_i18n( $stats['active'] ), number_format_i18n( $stats['total'] ) ) ) . '</b>' . esc_html__( 'Active feeds', 'oli-product-feed' ) . '</li>';
		echo '<li><b>' . esc_html( number_format_i18n( $stats['items'] ) ) . '</b>' . esc_html__( 'Items in feeds', 'oli-product-feed' ) . '</li>';
		echo '<li><b>' . esc_html( number_format_i18n( $stats['excluded'] ) ) . '</b>' . esc_html__( 'Products excluded', 'oli-product-feed' ) . ' <a href="' . esc_url( $diag ) . '">' . esc_html__( 'Why?', 'oli-product-feed' ) . '</a></li>';
		$errors = count( $stats['errors'] );
		if ( $errors ) {
			echo '<li><b>' . esc_html( number_format_i18n( $errors ) ) . '</b>' . esc_html( _n( 'Feed with an error', 'Feeds with an error', $errors, 'oli-product-feed' ) ) . ' <a href="' . esc_url( $diag ) . '">' . esc_html__( 'Fix', 'oli-product-feed' ) . '</a></li>';
		}
		echo '</ul>';
	}

	/**
	 * Error notice: how many feeds failed, with the first failure and a link to its diagnostics.
	 *
	 * @param int[] $errors Feed IDs in error.
	 */
	public static function error_notice( array $errors ) {
		if ( ! $errors ) {
			return;
		}
		$first = (int) $errors[0];
		$feed  = Feeds::get( $first );
		$last  = Feeds::last_run( $first );
		/* translators: %d: number of feeds. */
		$count = sprintf( _n( '%d feed could not be generated.', '%d feeds could not be generated.', count( $errors ), 'oli-product-feed' ), count( $errors ) );
		echo '<div class="notice notice-error"><p><strong>' . esc_html( $count ) . '</strong> ';
		/* translators: 1: feed name, 2: error message. */
		echo esc_html( sprintf( __( '%1$s: %2$s', 'oli-product-feed' ), $feed ? $feed['name'] : '#' . $first, isset( $last['message'] ) ? $last['message'] : '' ) );
		echo ' <a href="' . esc_url( Admin::diag_url( $first ) ) . '">' . esc_html__( 'See diagnostics', 'oli-product-feed' ) . '</a></p></div>';
	}

	/**
	 * Notice of a running generation (progress text and a Cancel link), refreshed by the script.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function running_notice( $feed_id ) {
		$feed = Feeds::get( $feed_id );
		if ( ! $feed ) {
			return '';
		}
		$p = Admin::progress( $feed_id );
		return '<div class="notice notice-info inline oliprfe-run-notice" data-oliprfe-notice="' . (int) $feed_id . '"><p>'
			. '<span class="oliprfe-status oliprfe-is-ok"><span class="dashicons dashicons-update" aria-hidden="true"></span>' . esc_html__( 'Generating', 'oli-product-feed' ) . '</span> '
			. '<span class="oliprfe-run-text">' . esc_html( self::running_text( $feed['name'], $p ) ) . '</span> '
			. '<a href="' . esc_url( Admin::action_url( 'oliprfe_cancel_generation', $feed_id ) ) . '">' . esc_html__( 'Cancel generation', 'oli-product-feed' ) . '</a></p></div>';
	}

	/**
	 * Text of a running generation.
	 *
	 * @param string                                $name Feed name.
	 * @param array{done:int,total:int,percent:int} $p    Progress.
	 * @return string
	 */
	public static function running_text( $name, array $p ) {
		/* translators: 1: feed name, 2: processed products, 3: total products, 4: percentage. */
		return sprintf( __( '%1$s: %2$s of %3$s products (%4$s%%). You can leave this page.', 'oli-product-feed' ), $name, number_format_i18n( $p['done'] ), number_format_i18n( $p['total'] ), $p['percent'] );
	}

	/**
	 * Notice shown when a generation ends (success with the exclusions, or the error).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function finished_notice( $feed_id ) {
		$feed = Feeds::get( $feed_id );
		$last = Feeds::last_run( $feed_id );
		if ( ! $feed || empty( $last['status'] ) ) {
			return '';
		}
		$diag = Admin::diag_url( (int) $feed_id );
		if ( 'error' === $last['status'] ) {
			/* translators: 1: feed name, 2: error message. */
			$text = sprintf( __( '%1$s could not be generated: %2$s', 'oli-product-feed' ), $feed['name'], isset( $last['message'] ) ? $last['message'] : '' );
			return '<div class="notice notice-error inline"><p>' . esc_html( $text ) . ' <a href="' . esc_url( $diag ) . '">' . esc_html__( 'See diagnostics', 'oli-product-feed' ) . '</a></p></div>';
		}
		$items    = (int) ( isset( $last['items'] ) ? $last['items'] : 0 );
		$excluded = max( 0, (int) ( isset( $last['candidates'] ) ? $last['candidates'] : 0 ) - $items );
		/* translators: 1: feed name, 2: items, 3: excluded products, 4: duration in seconds. */
		$text = sprintf( __( '%1$s generated: %2$s items, %3$s excluded, %4$s s.', 'oli-product-feed' ), $feed['name'], number_format_i18n( $items ), number_format_i18n( $excluded ), number_format_i18n( (float) ( isset( $last['duration'] ) ? $last['duration'] : 0 ), 1 ) );
		$html = '<div class="notice notice-success inline"><p>' . esc_html( $text );
		if ( $excluded ) {
			/* translators: %s: number of excluded products. */
			$html .= ' <a href="' . esc_url( $diag ) . '">' . esc_html( sprintf( _n( 'See why %s was excluded', 'See why %s were excluded', $excluded, 'oli-product-feed' ), number_format_i18n( $excluded ) ) ) . '</a>';
		}
		return $html . '</p></div>';
	}
}
