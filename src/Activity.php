<?php
/**
 * Activity log: one bounded list of what happened to the feeds (local data only).
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the events in one option that is not autoloaded, newest first, bounded by the log_keep setting.
 */
final class Activity {

	const OPTION   = 'oliprfe_activity';
	const IMPORTED = 'oliprfe_activity_imported';
	const EVENTS   = array( 'generated', 'failed', 'queued', 'cancelled', 'created', 'edited', 'activated', 'deactivated', 'deleted', 'settings' );

	/**
	 * Author of the next events when no user acts: schedule|product (set by the scheduler), '' for the default.
	 *
	 * @var string
	 */
	public static $by = '';

	/**
	 * Author of an event happening now: the scheduler context, else the current user, else the schedule.
	 *
	 * @return string|int
	 */
	public static function current_by() {
		if ( '' !== self::$by ) {
			return self::$by;
		}
		$user = get_current_user_id();
		return $user ? $user : 'schedule';
	}

	/**
	 * Adds an event.
	 *
	 * @param string              $event   One of EVENTS.
	 * @param int                 $feed_id Feed ID (0: no feed).
	 * @param array<string,mixed> $data    Details (items, excluded, duration, message).
	 * @param string|int|null     $by      Author (null: current_by()).
	 */
	public static function add( $event, $feed_id = 0, array $data = array(), $by = null ) {
		$feed_id = (int) $feed_id;
		$entries = self::all();
		array_unshift(
			$entries,
			array(
				't' => time(),
				'f' => $feed_id,
				'n' => $feed_id ? (string) get_the_title( $feed_id ) : '',
				'e' => (string) $event,
				'd' => $data,
				'b' => null === $by ? self::current_by() : $by,
			)
		);
		self::store( $entries );
	}

	/**
	 * Every entry, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Retention: the log_keep setting (10 to 5,000, 200 by default).
	 *
	 * @return int
	 */
	public static function keep() {
		return max( 10, min( 5000, (int) Settings::get( 'log_keep' ) ) );
	}

	/**
	 * Saves the entries, sorted newest first and bounded by the retention.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 */
	private static function store( array $entries ) {
		usort(
			$entries,
			static function ( $a, $b ) {
				return (int) $b['t'] - (int) $a['t'];
			}
		);
		update_option( self::OPTION, array_slice( $entries, 0, self::keep() ), false );
	}

	/**
	 * Applies a new retention right away (after the settings are saved).
	 */
	public static function prune() {
		$entries = self::all();
		if ( count( $entries ) > self::keep() ) {
			self::store( $entries );
		}
	}

	/**
	 * Imports the runs kept by 0.5.7 in each feed (_oliprfe_log, read only) once, with their own time.
	 * The author of these runs was not recorded: it is left empty.
	 */
	public static function import_legacy() {
		if ( get_option( self::IMPORTED ) ) {
			return;
		}
		$entries = self::all();
		$seen    = array();
		foreach ( $entries as $entry ) {
			$seen[ $entry['f'] . ':' . $entry['t'] . ':' . $entry['e'] ] = true;
		}
		foreach ( Feeds::all_ids() as $feed_id ) {
			$log = get_post_meta( $feed_id, Feeds::META_LOG, true );
			foreach ( is_array( $log ) ? $log : array() as $run ) {
				if ( ! is_array( $run ) || empty( $run['time'] ) ) {
					continue;
				}
				$ok    = isset( $run['status'] ) && 'success' === $run['status'];
				$event = $ok ? 'generated' : 'failed';
				$key   = $feed_id . ':' . (int) $run['time'] . ':' . $event;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$entries[]    = array(
					't' => (int) $run['time'],
					'f' => (int) $feed_id,
					'n' => (string) get_the_title( $feed_id ),
					'e' => $event,
					'd' => self::run_data( $run ),
					'b' => '',
				);
			}
		}
		self::store( $entries );
		update_option( self::IMPORTED, OLIPRFE_VERSION, false );
	}

	/**
	 * Details of a run entry (generated or failed).
	 *
	 * @param array<string,mixed> $run Run entry of the generator.
	 * @return array<string,mixed>
	 */
	public static function run_data( array $run ) {
		if ( isset( $run['status'] ) && 'success' === $run['status'] ) {
			return array(
				'items'    => (int) $run['items'],
				'excluded' => max( 0, (int) ( isset( $run['candidates'] ) ? $run['candidates'] : 0 ) - (int) $run['items'] ),
				'duration' => isset( $run['duration'] ) ? (float) $run['duration'] : 0,
			);
		}
		return array( 'message' => isset( $run['message'] ) ? (string) $run['message'] : '' );
	}
}
