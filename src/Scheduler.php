<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Background scheduling: Action Scheduler when available (WooCommerce ships it), WP-Cron otherwise.
 *
 * @package Oliprfe
 */

namespace Oliprfe;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring tick + batch actions.
 */
final class Scheduler {

	const GROUP        = 'oli-product-feed';
	const TICK         = 'oliprfe_tick';
	const BATCH        = 'oliprfe_run_batch';
	const TICK_SECONDS = 900;

	const CHANGED_OPTION = 'oliprfe_products_changed';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::TICK, array( __CLASS__, 'tick' ) );
		add_action( self::BATCH, array( __CLASS__, 'batch' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'product_changed' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'product_changed' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( 'init', array( __CLASS__, 'ensure_tick' ), 20 );
	}

	/**
	 * Uses Action Scheduler?
	 *
	 * @return bool
	 */
	private static function use_as() {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * 15-minute WP-Cron schedule (fallback only).
	 *
	 * @param array<string,array<string,mixed>> $schedules Schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['oliprfe_15min'] = array(
			'interval' => self::TICK_SECONDS,
			'display'  => __( 'Every 15 minutes (Oli Product Feed)', 'oli-product-feed' ),
		);
		return $schedules;
	}

	/**
	 * Makes sure the recurring tick exists.
	 */
	public static function ensure_tick() {
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( self::use_as() ) {
			if ( ! as_has_scheduled_action( self::TICK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, self::TICK_SECONDS, self::TICK, array(), self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::TICK ) ) {
			wp_schedule_event( time() + 60, 'oliprfe_15min', self::TICK );
		}
	}

	/**
	 * Tick: starts the feeds that are due.
	 */
	public static function tick() {
		foreach ( Feeds::all_ids() as $feed_id ) {
			$feed = Feeds::get( $feed_id );
			if ( ! $feed || 'publish' !== $feed['status'] || Generator::is_running( $feed_id ) ) {
				continue;
			}
			$last = Feeds::last_run( $feed_id );
			if ( self::is_due( $feed, $last ) || self::changed_since( $last ) ) {
				self::queue( $feed_id );
			}
		}
	}

	/**
	 * Product saved: remembered for the "regenerate when products change" setting (read by the tick).
	 */
	public static function product_changed() {
		if ( Settings::get( 'regen_on_update' ) ) {
			update_option( self::CHANGED_OPTION, time(), false );
		}
	}

	/**
	 * Did products change since the last generation (setting on, at most every 15 minutes)?
	 *
	 * @param array<string,mixed> $last Last log entry.
	 * @return bool
	 */
	private static function changed_since( array $last ) {
		if ( ! Settings::get( 'regen_on_update' ) ) {
			return false;
		}
		$changed  = (int) get_option( self::CHANGED_OPTION, 0 );
		$last_run = isset( $last['time'] ) ? (int) $last['time'] : 0;
		return $changed > $last_run && time() - $last_run >= self::TICK_SECONDS;
	}

	/**
	 * Next scheduled generation of a feed (timestamp, 0: manual or inactive).
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @param array<string,mixed> $last Last log entry.
	 * @return int
	 */
	public static function next_run( array $feed, array $last ) {
		$interval = self::interval( $feed['frequency'] );
		if ( ! $interval || ! isset( $feed['status'] ) || 'publish' !== $feed['status'] ) {
			return 0;
		}
		$last_run = isset( $last['time'] ) ? (int) $last['time'] : 0;
		if ( $feed['start_hour'] >= 0 && in_array( $feed['frequency'], array( 'daily', 'weekly' ), true ) ) {
			$next = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( (int) $feed['start_hour'], 0 )->getTimestamp();
			while ( $next <= $last_run || $next - $last_run < $interval - DAY_IN_SECONDS + HOUR_IN_SECONDS ) {
				$next += DAY_IN_SECONDS;
			}
		} else {
			$next = $last_run + $interval;
		}
		// The tick starts it: at most TICK_SECONDS later.
		return max( $next, time() );
	}

	/**
	 * Regeneration interval of a frequency, in seconds (0: manual).
	 *
	 * @param string $frequency manual|hourly|twicedaily|daily|weekly.
	 * @return int
	 */
	public static function interval( $frequency ) {
		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
			'weekly'     => WEEK_IN_SECONDS,
		);
		return isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : 0;
	}

	/**
	 * Is a feed due?
	 *
	 * @param array<string,mixed> $feed Feed configuration.
	 * @param array<string,mixed> $last Last log entry.
	 * @return bool
	 */
	public static function is_due( array $feed, array $last ) {
		$interval = self::interval( $feed['frequency'] );
		if ( ! $interval ) {
			return false;
		}
		$now      = time();
		$last_run = isset( $last['time'] ) ? (int) $last['time'] : 0;
		if ( $feed['start_hour'] >= 0 && in_array( $feed['frequency'], array( 'daily', 'weekly' ), true ) ) {
			$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( (int) $feed['start_hour'], 0 )->getTimestamp();
			$slot  = $now >= $today ? $today : $today - DAY_IN_SECONDS;
			return $last_run < $slot && $now - $last_run >= $interval - DAY_IN_SECONDS + HOUR_IN_SECONDS;
		}
		return $now - $last_run >= $interval - self::TICK_SECONDS / 2;
	}

	/**
	 * Starts a generation in the background.
	 *
	 * @param int $feed_id Feed ID.
	 * @return true|\WP_Error
	 */
	public static function queue( $feed_id ) {
		$started = Generator::start( $feed_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		self::schedule_batch( $feed_id );
		return true;
	}

	/**
	 * Schedules the next batch.
	 *
	 * @param int $feed_id Feed ID.
	 */
	private static function schedule_batch( $feed_id ) {
		$args = array( (int) $feed_id );
		if ( self::use_as() ) {
			as_enqueue_async_action( self::BATCH, $args, self::GROUP );
			return;
		}
		wp_schedule_single_event( time(), self::BATCH, $args );
	}

	/**
	 * Batch action.
	 *
	 * @param int $feed_id Feed ID.
	 */
	public static function batch( $feed_id ) {
		$status = Generator::run_batch( (int) $feed_id );
		if ( 'more' === $status ) {
			self::schedule_batch( (int) $feed_id );
		}
	}

	/**
	 * Seconds the recurring tick is late (0 when on time). A late tick means the queue runner does not run,
	 * usually because WP-Cron is disabled (DISABLE_WP_CRON) without a server cron.
	 *
	 * @return int
	 */
	public static function tick_delay() {
		$next = self::use_as() ? as_next_scheduled_action( self::TICK, array(), self::GROUP ) : wp_next_scheduled( self::TICK );
		if ( ! is_int( $next ) ) {
			return 0;
		}
		$late = time() - $next;
		return $late > 0 ? $late : 0;
	}

	/**
	 * Next run of the recurring tick (timestamp, 0: not scheduled).
	 *
	 * @return int
	 */
	public static function next_tick() {
		$next = self::use_as() ? as_next_scheduled_action( self::TICK, array(), self::GROUP ) : wp_next_scheduled( self::TICK );
		return is_int( $next ) ? $next : 0;
	}

	/**
	 * Runs the tick and every pending batch in this process (WP-CLI or server cron without WP-Cron).
	 *
	 * @return int[] Feed IDs generated.
	 */
	public static function run_due() {
		$done = array();
		foreach ( Feeds::all_ids() as $feed_id ) {
			$feed = Feeds::get( $feed_id );
			if ( ! $feed || 'publish' !== $feed['status'] || Generator::is_running( $feed_id ) ) {
				continue;
			}
			$last = Feeds::last_run( $feed_id );
			if ( ( self::is_due( $feed, $last ) || self::changed_since( $last ) ) && ! is_wp_error( Generator::run_now( $feed_id ) ) ) {
				$done[] = $feed_id;
			}
		}
		return $done;
	}

	/**
	 * Removes every scheduled action of the plugin (deactivation).
	 */
	public static function unschedule_all() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::TICK, null, self::GROUP );
			as_unschedule_all_actions( self::BATCH, null, self::GROUP );
		}
		wp_unschedule_hook( self::TICK );
		wp_unschedule_hook( self::BATCH );
	}
}
