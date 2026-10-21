<?php
/**
 * Uninstall.
 *
 * Always: the scheduled tasks, their Action Scheduler history and the early endpoint index are removed.
 *
 * When "Delete all data when the plugin is deleted" is checked: feeds (trash included), settings, generation
 * states, the generated files in every output folder the plugin used (current and previous ones, option
 * oliprfe_output_dirs) and the WooCommerce logs of the plugin (source oli-product-feed: log files and database
 * log handler rows) are deleted. Only the files the plugin wrote (feed files, their .tmp and its index.php)
 * are deleted; a folder is removed only when the plugin created it and it is empty; nothing outside the
 * uploads directory and no symbolic link is touched.
 *
 * When it is unchecked (default), as in 0.3.2: feeds, settings, generated files and logs are kept.
 *
 * @package Oliprfe
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Runs in a closure: no global variables.
(
	static function () {
		global $wpdb;

		foreach ( array( 'oliprfe_tick', 'oliprfe_run_batch', 'oli_pf_tick', 'oli_pf_run_batch' ) as $oliprfe_hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $oliprfe_hook );
			}
			wp_unschedule_hook( $oliprfe_hook );
		}

		// Action Scheduler history of the plugin (completed, failed and canceled actions, their logs and
		// the group). Skipped when the Action Scheduler tables do not exist.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of the plugin's rows.
		$oliprfe_as_actions = $wpdb->prefix . 'actionscheduler_actions';
		$oliprfe_as_logs    = $wpdb->prefix . 'actionscheduler_logs';
		$oliprfe_as_groups  = $wpdb->prefix . 'actionscheduler_groups';
		$oliprfe_has_table  = static function ( $table ) use ( $wpdb ) {
			return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		};
		if ( $oliprfe_has_table( $oliprfe_as_actions ) ) {
			$oliprfe_group_id = $oliprfe_has_table( $oliprfe_as_groups )
				? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $oliprfe_as_groups, 'oli-product-feed' ) )
				: 0;
			if ( $oliprfe_has_table( $oliprfe_as_logs ) ) {
				$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.hook IN (%s, %s, %s, %s) OR ( a.group_id > 0 AND a.group_id = %d )', $oliprfe_as_logs, $oliprfe_as_actions, 'oliprfe_tick', 'oliprfe_run_batch', 'oli_pf_tick', 'oli_pf_run_batch', $oliprfe_group_id ) );
			}
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hook IN (%s, %s, %s, %s) OR ( group_id > 0 AND group_id = %d )', $oliprfe_as_actions, 'oliprfe_tick', 'oliprfe_run_batch', 'oli_pf_tick', 'oli_pf_run_batch', $oliprfe_group_id ) );
			if ( $oliprfe_group_id && ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE group_id = %d', $oliprfe_as_actions, $oliprfe_group_id ) ) ) {
				$wpdb->delete( $oliprfe_as_groups, array( 'group_id' => $oliprfe_group_id ), array( '%d' ) );
			}
		}
		// phpcs:enable

		// Early endpoint index: useless without the plugin.
		delete_option( 'oliprfe_endpoint_index' );

		$oliprfe_settings = get_option( 'oliprfe_settings', array() );
		if ( ! is_array( $oliprfe_settings ) || empty( $oliprfe_settings['delete_on_uninstall'] ) ) {
			return;
		}

		// Classes without side effects (the plugin is not loaded during the uninstall).
		$oliprfe_src = __DIR__ . '/src/';
		require_once $oliprfe_src . 'OutputDirs.php';
		require_once $oliprfe_src . 'Storage.php';
		require_once $oliprfe_src . 'Settings.php';

		// Current output folder (setting and oliprfe_output_dir filter), recorded first when the plugin was
		// updated from 0.3.2 or older and never generated since.
		$oliprfe_folder = Oliprfe\Storage::folder();
		$oliprfe_feeds  = get_posts(
			array(
				'post_type'      => array( 'oliprfe_feed', 'oli_pf_feed' ),
				'post_status'    => array_keys( get_post_stati() ), // Trash included ('any' excludes it).
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$oliprfe_names  = array();
		foreach ( $oliprfe_feeds as $oliprfe_id ) {
			$oliprfe_config = json_decode( (string) get_post_meta( $oliprfe_id, '_oliprfe_config', true ), true );
			if ( ! is_array( $oliprfe_config ) ) {
				$oliprfe_config = json_decode( (string) get_post_meta( $oliprfe_id, '_oli_pf_config', true ), true );
			}
			$oliprfe_slug   = is_array( $oliprfe_config ) && ! empty( $oliprfe_config['file_slug'] ) ? sanitize_file_name( $oliprfe_config['file_slug'] ) : 'feed-' . (int) $oliprfe_id;
			foreach ( array( 'xml', 'csv', 'tsv', 'txt' ) as $oliprfe_ext ) {
				$oliprfe_names[] = $oliprfe_slug . '.' . $oliprfe_ext;
			}
		}
		// Not recorded yet (update from 0.3.2 or older, never generated since): the files of the feeds in the
		// current folder are recorded as the plugin's (rules of 0.3.2). Once recorded, only the files the plugin
		// wrote are deleted (a file of the same name that it did not write is left, see clean_all()).
		Oliprfe\OutputDirs::maybe_migrate( $oliprfe_folder, $oliprfe_names );
		foreach ( $oliprfe_feeds as $oliprfe_id ) {
			wp_delete_post( $oliprfe_id, true );
		}

		// Every output folder used by the plugin (current and previous): its files, its index.php, the folder
		// when the plugin created it and it is empty.
		Oliprfe\OutputDirs::clean_all();

		// WooCommerce logs of the plugin (source oli-product-feed): files of the file handlers
		// (oli-product-feed-YYYY-MM-DD-hash.log and their rotations) and rows of the database handler.
		if ( class_exists( 'Automattic\WooCommerce\Utilities\LoggingUtil' ) && method_exists( 'Automattic\WooCommerce\Utilities\LoggingUtil', 'get_log_directory' ) ) {
			$oliprfe_log_dir = Automattic\WooCommerce\Utilities\LoggingUtil::get_log_directory( false );
		} elseif ( defined( 'WC_LOG_DIR' ) ) {
			$oliprfe_log_dir = WC_LOG_DIR;
		} else {
			$oliprfe_uploads = wp_upload_dir( null, false );
			$oliprfe_log_dir = trailingslashit( $oliprfe_uploads['basedir'] ) . 'wc-logs/'; // WooCommerce inactive: its default folder.
		}
		$oliprfe_log_dir = is_string( $oliprfe_log_dir ) && '' !== $oliprfe_log_dir && ! is_link( untrailingslashit( $oliprfe_log_dir ) ) ? realpath( $oliprfe_log_dir ) : false;
		if ( $oliprfe_log_dir && is_dir( $oliprfe_log_dir ) ) {
			foreach ( (array) scandir( $oliprfe_log_dir ) as $oliprfe_log ) {
				$oliprfe_log_file = $oliprfe_log_dir . '/' . $oliprfe_log;
				if ( preg_match( '/^oli-product-feed(\.\d+)?-\d{4}-\d{2}-\d{2}-[A-Za-z0-9]+(\.\d+)?\.log$/', (string) $oliprfe_log ) && ! is_link( $oliprfe_log_file ) && is_file( $oliprfe_log_file ) ) {
					wp_delete_file( $oliprfe_log_file );
				}
			}
		}
		$oliprfe_log_table = $wpdb->prefix . 'woocommerce_log';
		if ( $oliprfe_has_table( $oliprfe_log_table ) ) {
			$wpdb->delete( $oliprfe_log_table, array( 'source' => 'oli-product-feed' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup of the plugin's log rows.
		}

		// Per-product backorder override (simple products and variations).
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_oliprfe_availability' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time cleanup of the plugin's product meta.
		foreach ( array( '_oli_pf_config', '_oli_pf_log', '_oli_pf_last_run' ) as $oliprfe_old_meta ) {
			$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $oliprfe_old_meta ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Leftover pre-0.3.5 keys.
		}

		// Options (settings and generation states) and transients (locks).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup by prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'oliprfe_' ) . '%', $wpdb->esc_like( '_transient_oliprfe_' ) . '%', $wpdb->esc_like( '_transient_timeout_oliprfe_' ) . '%', $wpdb->esc_like( 'oli_pf_' ) . '%', $wpdb->esc_like( '_transient_oli_pf_' ) . '%', $wpdb->esc_like( '_transient_timeout_oli_pf_' ) . '%' ) );
		// phpcs:enable
		wp_cache_flush();
	}
)();
