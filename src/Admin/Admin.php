<?php
/**
 * Admin screens: WooCommerce > Product Feeds (feeds list, feed editor, settings) and their actions.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Activity;
use Oliprfe\Channels\Registry;
use Oliprfe\Endpoint;
use Oliprfe\Feeds;
use Oliprfe\Generator;
use Oliprfe\Scheduler;
use Oliprfe\Settings;
use Oliprfe\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
final class Admin {

	const SLUG = 'oli-product-feed';

	/**
	 * User meta: the multilingual notice was hidden by this user.
	 */
	const HIDE_MULTILINGUAL = 'oliprfe_hide_multilingual';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_oliprfe_save_feed', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_oliprfe_create_feed', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_oliprfe_delete_feed', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_oliprfe_duplicate_feed', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_oliprfe_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_oliprfe_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_oliprfe_generate_all', array( __CLASS__, 'handle_generate_all' ) );
		add_action( 'admin_post_oliprfe_activate_feed', array( __CLASS__, 'handle_activate' ) );
		add_action( 'admin_post_oliprfe_deactivate_feed', array( __CLASS__, 'handle_deactivate' ) );
		add_action( 'admin_post_oliprfe_cancel_generation', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_oliprfe_hide_notice', array( __CLASS__, 'handle_hide_notice' ) );
		add_action( 'wp_ajax_oliprfe_feed_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_oliprfe_match_count', array( __CLASS__, 'ajax_match_count' ) );
		add_action( 'wp_ajax_oliprfe_preview_row', array( __CLASS__, 'ajax_preview_row' ) );
		add_action( 'wp_ajax_oliprfe_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_oliprfe_product_search', array( __CLASS__, 'ajax_product_search' ) );
		add_action( 'wp_ajax_oliprfe_gcat_search', array( __CLASS__, 'ajax_gcat_search' ) );
		add_action( 'admin_init', array( __CLASS__, 'upgrade' ) );
		add_action( 'admin_footer', array( __CLASS__, 'delete_dialog' ) );
		add_filter( 'set_screen_option_oliprfe_feeds_per_page', array( __CLASS__, 'set_per_page' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( OLIPRFE_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string,mixed> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Diagnostics tab URL (of one feed, or the default feed).
	 *
	 * @param int $feed_id Feed ID (0: the first feed in error, else the last generated).
	 * @return string
	 */
	public static function diag_url( $feed_id = 0 ) {
		$args = array( 'tab' => 'diagnostics' );
		if ( $feed_id ) {
			$args['feed'] = (int) $feed_id;
		}
		return self::url( $args );
	}

	/**
	 * Nonced admin-post URL for a feed action.
	 *
	 * @param string $action  Action.
	 * @param int    $feed_id Feed ID.
	 * @return string
	 */
	public static function action_url( $action, $feed_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'feed'   => (int) $feed_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . (int) $feed_id
		);
	}

	/**
	 * Attributes of a Delete link: the nonced URL (used as is without JavaScript) and what the delete
	 * dialog needs (feed name, file name, nonced "Deactivate instead" URL). Every value is escaped.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function delete_attrs( $feed_id ) {
		$feed = Feeds::get( $feed_id );
		$file = $feed ? basename( (string) wp_parse_url( Storage::url_for( $feed_id ), PHP_URL_PATH ) ) : '';
		if ( $feed && Endpoint::enabled() ) {
			$file = $feed['file_slug'];
		}
		return 'href="' . esc_url( self::action_url( 'oliprfe_delete_feed', $feed_id ) ) . '"'
			. ' data-oliprfe-delete="' . (int) $feed_id . '"'
			. ' data-name="' . esc_attr( $feed ? $feed['name'] : '' ) . '"'
			. ' data-file="' . esc_attr( file_exists( Storage::path_for( $feed_id ) ) ? $file : '' ) . '"'
			. ' data-deactivate="' . esc_url( self::action_url( 'oliprfe_deactivate_feed', $feed_id ) ) . '"';
	}

	/**
	 * Plugins screen links (Feeds + Donate).
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$feeds = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Feeds', 'oli-product-feed' ) . '</a>';
		array_unshift( $links, $feeds );
		return $links;
	}

	/**
	 * Donate in plugin meta row.
	 *
	 * @param string[] $links Links.
	 * @param string   $file  Basename.
	 * @return string[]
	 */
	public static function plugin_row_meta( $links, $file ) {
		if ( $file !== plugin_basename( OLIPRFE_FILE ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( OLIPRFE_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'oli-product-feed' ) . '</a>';
		return $links;
	}

	/**
	 * Whether the current admin screen belongs to this plugin.
	 *
	 * @return bool
	 */
	public static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return false !== strpos( (string) $screen->id, self::SLUG );
	}

	/**
	 * One-time clean-up when the 0.6 screens are first loaded: the dismissed flag of the former support
	 * notice (removed in 0.6.0) is deleted. Feeds, their configuration and the settings are not touched.
	 */
	public static function upgrade() {
		if ( OLIPRFE_UI_VERSION === get_option( 'oliprfe_ui_version' ) ) {
			return;
		}
		delete_metadata( 'user', 0, 'oliprfe_donate_dismissed', '', true );
		update_option( 'oliprfe_ui_version', OLIPRFE_UI_VERSION, false );
	}

	/**
	 * Screen Options: number of feeds per page.
	 *
	 * @param mixed  $keep   Value to keep (false by default).
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public static function set_per_page( $keep, $option, $value ) {
		return 'oliprfe_feeds_per_page' === $option ? max( 1, min( 999, (int) $value ) ) : $keep;
	}

	/**
	 * Screen Options of the feed list (columns that can be hidden, feeds per page) and bulk actions.
	 */
	public static function load_screen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feeds';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:enable
		if ( 'feeds' === $tab && ( '' === $action || '-1' === $action || in_array( $action, array( 'generate', 'activate', 'deactivate', 'delete' ), true ) ) ) {
			add_screen_option(
				'per_page',
				array(
					'default' => 20,
					'option'  => 'oliprfe_feeds_per_page',
				)
			);
			$screen = get_current_screen();
			if ( $screen ) {
				add_filter( 'manage_' . $screen->id . '_columns', array( __CLASS__, 'screen_columns' ) );
			}
		}
		self::handle_bulk();
	}

	/**
	 * Columns listed in Screen Options.
	 *
	 * @return array<string,string>
	 */
	public static function screen_columns() {
		return ListTable::columns();
	}

	/**
	 * Menu entry.
	 */
	public static function menu() {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Product Feeds', 'oli-product-feed' ),
			__( 'Product Feeds', 'oli-product-feed' ),
			Settings::capability(),
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'load_screen' ) );
		}
	}

	/**
	 * CSS and JS on the plugin screens only.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'oliprfe-admin', OLIPRFE_URL . 'assets/css/admin.css', array(), OLIPRFE_VERSION );
		wp_enqueue_script( 'oliprfe-admin', OLIPRFE_URL . 'assets/js/admin.js', array( 'wp-a11y' ), OLIPRFE_VERSION, true );
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		if ( 'new' === $action || 'edit' === $action ) {
			wp_enqueue_script( 'oliprfe-editor', OLIPRFE_URL . 'assets/js/editor.js', array( 'wp-a11y', 'oliprfe-admin' ), OLIPRFE_VERSION, true );
		}
		wp_localize_script(
			'oliprfe-admin',
			'oliprfeAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'oliprfe_feed_status' ),
				'i18n'    => array(
					'copy'          => __( 'Copy', 'oli-product-feed' ),
					'copied'        => __( 'Copied', 'oli-product-feed' ),
					'copiedLong'    => __( 'Feed URL copied to clipboard.', 'oli-product-feed' ),
					'copyFailed'    => __( 'Press Ctrl+C (Cmd+C on a Mac) to copy the selected URL.', 'oli-product-feed' ),
					'generated'     => __( 'Feed generated.', 'oli-product-feed' ),
					'failed'        => __( 'Feed generation failed.', 'oli-product-feed' ),
					'badName'       => __( 'Use lowercase letters, digits and underscores.', 'oli-product-feed' ),
					'exists'        => __( 'This attribute is already in the list.', 'oli-product-feed' ),
					'counting'      => __( 'Counting…', 'oli-product-feed' ),
					'countError'    => __( 'The count failed.', 'oli-product-feed' ),
					'selectOne'     => __( 'Select at least one feed.', 'oli-product-feed' ),
					'loading'       => __( 'Loading…', 'oli-product-feed' ),
					/* translators: %d: number of products. */
					'found'         => __( '%d products found.', 'oli-product-feed' ),
					'previewFailed' => __( 'The preview failed.', 'oli-product-feed' ),
				),
				'plural'  => self::delete_plurals(),
			)
		);
	}

	/**
	 * Plural titles and buttons of the delete dialog, for 1 to the number of feeds (translated with _n()).
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function delete_plurals() {
		$out = array(
			'title'  => array(),
			'button' => array(),
			'desc'   => array(),
		);
		$max = max( 1, count( Feeds::all_ids() ) );
		for ( $n = 1; $n <= $max; $n++ ) {
			/* translators: %d: number of feeds. */
			$out['title'][ $n ] = sprintf( _n( 'Delete %d feed?', 'Delete %d feeds?', $n, 'oli-product-feed' ), $n );
			/* translators: %d: number of feeds. */
			$out['button'][ $n ] = sprintf( _n( 'Delete %d feed', 'Delete %d feeds', $n, 'oli-product-feed' ), $n );
			$out['desc'][ $n ]   = self::delete_consequence( $n );
		}
		return $out;
	}

	/**
	 * Consequence sentence of the delete dialog and page, singular or plural.
	 *
	 * @param int $n Number of feeds.
	 * @return string
	 */
	private static function delete_consequence( $n ) {
		return _n(
			'Its file is deleted too. Platforms that fetch this URL will get an error and may pause your products.',
			'Their files are deleted too. Platforms that fetch these URLs will get an error and may pause your products.',
			$n,
			'oli-product-feed'
		);
	}

	/**
	 * Router.
	 */
	public static function render() {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feeds';
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$feed   = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		// phpcs:enable
		if ( 'settings' === $tab ) {
			self::render_settings();
			return;
		}
		if ( 'diagnostics' === $tab ) {
			Diagnostics::render();
			return;
		}
		if ( 'log' === $tab ) {
			self::render_log();
			return;
		}
		if ( 'new' === $action ) {
			$form = get_transient( 'oliprfe_form_' . get_current_user_id() );
			if ( is_array( $form ) ) {
				delete_transient( 'oliprfe_form_' . get_current_user_id() );
			}
			Editor::render_new( is_array( $form ) ? $form['errors'] : array(), is_array( $form ) ? $form['config'] : array() );
			return;
		}
		if ( 'edit' === $action && $feed && Feeds::get( $feed ) ) {
			$form = get_transient( 'oliprfe_form_' . get_current_user_id() );
			if ( is_array( $form ) ) {
				delete_transient( 'oliprfe_form_' . get_current_user_id() );
				Editor::render( 'new' === $action ? 0 : $feed, $form['errors'], $form['config'] );
				return;
			}
			Editor::render( 'new' === $action ? 0 : $feed );
			return;
		}
		self::render_list();
	}

	/**
	 * Page heading.
	 *
	 * @param string $title   Title.
	 * @param bool   $add_new Show "Add New Feed".
	 */
	public static function heading( $title, $add_new = true ) {
		echo '<h1 class="wp-heading-inline oliprfe-title"><img src="' . esc_url( OLIPRFE_URL . 'assets/images/icon.svg' ) . '" width="32" height="32" alt="">' . esc_html( $title ) . '</h1>';
		if ( $add_new ) {
			echo ' <a href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Add New Feed', 'oli-product-feed' ) . '</a>';
		}
		echo '<hr class="wp-header-end">';
	}

	/**
	 * Screen tabs (real links): Feeds, Diagnostics, Activity log, Settings.
	 *
	 * @param string $current feeds|diagnostics|log|settings.
	 */
	public static function tabs( $current ) {
		$tabs = array(
			'feeds'       => __( 'Feeds', 'oli-product-feed' ),
			'diagnostics' => __( 'Diagnostics', 'oli-product-feed' ),
			'log'         => __( 'Activity log', 'oli-product-feed' ),
			'settings'    => __( 'Settings', 'oli-product-feed' ),
		);
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Product feed screens', 'oli-product-feed' ) . '">';
		foreach ( $tabs as $tab => $label ) {
			$url = 'feeds' === $tab ? self::url() : self::url( array( 'tab' => $tab ) );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $current === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Activity log tab: the runs kept by 0.5.7 in each feed are imported once, then the native table.
	 */
	private static function render_log() {
		Activity::import_legacy();
		$table = new ActivityTable();
		$table->prepare_items();
		echo '<div class="wrap oliprfe-wrap">';
		self::heading( __( 'Product Feeds', 'oli-product-feed' ) );
		self::tabs( 'log' );
		self::notices();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log">';
		$table->display();
		echo '</form><p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of entries. */
				__( 'The last %s entries are kept (Settings › Activity log). Older entries are deleted automatically.', 'oli-product-feed' ),
				number_format_i18n( Activity::keep() )
			)
		) . '</p></div>';
	}

	/**
	 * Notices from the last action.
	 */
	public static function notices() {
		$code = isset( $_GET['oliprfe_notice'] ) ? sanitize_key( wp_unslash( $_GET['oliprfe_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'          => array( 'success', __( 'Feed saved.', 'oli-product-feed' ) ),
			'saved_queued'   => array( 'success', __( 'Feed saved. Generation started in the background.', 'oli-product-feed' ) ),
			'created'        => array( 'success', __( 'Feed created (inactive). Review the mapping, then generate it.', 'oli-product-feed' ) ),
			'deleted'        => array( 'success', __( 'Feed deleted.', 'oli-product-feed' ) ),
			'duplicated'     => array( 'success', __( 'Feed duplicated (inactive). Review it, then generate it.', 'oli-product-feed' ) ),
			'queued'         => array( 'info', __( 'Generation started in the background.', 'oli-product-feed' ) ),
			'running'        => array( 'warning', __( 'A generation is already running for this feed.', 'oli-product-feed' ) ),
			'error'          => array( 'error', __( 'The action failed.', 'oli-product-feed' ) ),
			'settings_saved' => array( 'success', __( 'Settings saved.', 'oli-product-feed' ) ),
			'all_queued'     => array( 'info', __( 'Generation of every active feed started in the background.', 'oli-product-feed' ) ),
			'activated'      => array( 'success', __( 'Feed activated.', 'oli-product-feed' ) ),
			'deactivated'    => array( 'success', __( 'Feed deactivated. Its URL keeps the last file.', 'oli-product-feed' ) ),
			'bulk_activated' => array( 'success', __( 'Feeds activated.', 'oli-product-feed' ) ),
			'bulk_off'       => array( 'success', __( 'Feeds deactivated. Their URLs keep the last file.', 'oli-product-feed' ) ),
			'bulk_deleted'   => array( 'success', __( 'Feeds deleted.', 'oli-product-feed' ) ),
			'cancelled'      => array( 'info', __( 'Generation cancelled. The previous file is still online.', 'oli-product-feed' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible' . ( 'created' === $code ? ' oliprfe-speak' : '' ) . '"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
		if ( 'folder_refused' === $code ) {
			$folder = isset( $_GET['oliprfe_folder'] ) ? Storage::sanitize_folder( sanitize_text_field( wp_unslash( $_GET['oliprfe_folder'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason = isset( $_GET['oliprfe_reason'] ) ? sanitize_key( wp_unslash( $_GET['oliprfe_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( __( 'Settings saved, but the output folder was not changed.', 'oli-product-feed' ) . ' ' . Storage::folder_message( $folder, $reason ) . ' ' . sprintf(
				/* translators: %s: folder, e.g. "oli-product-feed". */
				__( 'The feeds stay in wp-content/uploads/%s.', 'oli-product-feed' ),
				Storage::folder()
			) ) . '</p></div>';
		}
		$problem = Storage::problem();
		if ( $problem ) {
			$next = '' !== $problem['fallback'] ? sprintf(
				/* translators: %s: folder, e.g. "oli-product-feed". */
				__( 'Nothing was moved or deleted: the feeds are still generated and served from wp-content/uploads/%s. Fix the folder or choose another one in the settings (or in the oliprfe_output_dir filter).', 'oli-product-feed' ),
				$problem['fallback']
			) : __( 'The feeds cannot be generated until the folder is fixed or another one is chosen in the settings (or in the oliprfe_output_dir filter).', 'oli-product-feed' );
			echo '<div class="notice notice-error"><p>' . esc_html( Storage::folder_message( $problem['folder'], $problem['reason'] ) . ' ' . $next ) . '</p></div>';
		}
		$conflicts = $problem ? array() : Storage::conflicts();
		if ( $conflicts ) {
			echo '<div class="notice notice-warning">';
			foreach ( $conflicts as $conflict ) {
				echo '<p>' . esc_html(
					sprintf(
						/* translators: 1: file name, 2: output folder, 3: previous folder. */
						__( 'The feed file %1$s was not moved to wp-content/uploads/%2$s: a file of the same name that the plugin did not write is there. That file is left untouched, and the feed is still served from wp-content/uploads/%3$s (not regenerated). Remove or rename that file, or change the file name of the feed, then generate the feed.', 'oli-product-feed' ),
						$conflict['name'],
						Storage::folder(),
						$conflict['folder']
					)
				) . '</p>';
			}
			echo '</div>';
		}
		if ( Scheduler::tick_delay() > HOUR_IN_SECONDS ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %s: delay, e.g. "3 hours". */
					__( 'Scheduled generation is %s late: WP-Cron does not seem to run on this site (DISABLE_WP_CRON without a server cron). Ask your host for a server cron that calls wp-cron.php, or run "wp oli-feed run-due" from a system cron.', 'oli-product-feed' ),
					human_time_diff( time() - Scheduler::tick_delay() )
				)
			) . '</p></div>';
		}
		// Information only (the Add New Feed screen says it too): Feeds list with feeds, until the user hides it.
		if ( ! \Oliprfe\Lang::has_translatepress() && 'feeds' === ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feeds' ) && ! isset( $_GET['action'] ) && Feeds::all_ids() && ! get_user_meta( get_current_user_id(), self::HIDE_MULTILINGUAL, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$hide = wp_nonce_url( add_query_arg( 'action', 'oliprfe_hide_notice', admin_url( 'admin-post.php' ) ), 'oliprfe_hide_notice' );
			echo '<div class="notice notice-info is-dismissible" data-oliprfe-hide="' . esc_url( $hide ) . '"><p>' . esc_html__( 'No multilingual plugin detected: feeds use the site language. With TranslatePress, you can create one feed per language.', 'oli-product-feed' ) . ' <a href="' . esc_url( $hide ) . '">' . esc_html__( 'Do not show again', 'oli-product-feed' ) . '</a></p></div>';
		}
	}

	/**
	 * Status of a feed: generating, error, inactive, never or active.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function status_key( $feed_id ) {
		$last = Feeds::last_run( $feed_id );
		if ( Generator::is_running( $feed_id ) ) {
			return 'generating';
		}
		if ( ! empty( $last['status'] ) && 'error' === $last['status'] ) {
			return 'error';
		}
		if ( 'publish' !== get_post_status( $feed_id ) ) {
			return 'inactive';
		}
		return empty( $last['time'] ) ? 'never' : 'active';
	}

	/**
	 * Status badge HTML: icon (decorative) + text, never the color alone.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function status_badge( $feed_id ) {
		$all    = array(
			'generating' => array( 'ok', 'dashicons-update', __( 'Generating', 'oli-product-feed' ) ),
			'error'      => array( 'error', 'dashicons-warning', __( 'Error', 'oli-product-feed' ) ),
			'inactive'   => array( 'off', 'dashicons-controls-pause', __( 'Inactive', 'oli-product-feed' ) ),
			'never'      => array( 'off', 'dashicons-clock', __( 'Never generated', 'oli-product-feed' ) ),
			'active'     => array( 'ok', 'dashicons-yes-alt', __( 'Active', 'oli-product-feed' ) ),
		);
		$status = $all[ self::status_key( $feed_id ) ];
		return '<span class="oliprfe-status oliprfe-is-' . esc_attr( $status[0] ) . '"><span class="dashicons ' . esc_attr( $status[1] ) . '" aria-hidden="true"></span>' . esc_html( $status[2] ) . '</span>';
	}

	/**
	 * Last successful run of a feed (the last run, or the latest success of the run history).
	 *
	 * @param int $feed_id Feed ID.
	 * @return array<string,mixed>
	 */
	public static function last_success( $feed_id ) {
		$last = Feeds::last_run( $feed_id );
		if ( isset( $last['status'] ) && 'success' === $last['status'] ) {
			return $last;
		}
		$log = get_post_meta( $feed_id, Feeds::META_LOG, true );
		foreach ( is_array( $log ) ? $log : array() as $entry ) {
			if ( is_array( $entry ) && isset( $entry['status'] ) && 'success' === $entry['status'] ) {
				return $entry;
			}
		}
		return array();
	}

	/**
	 * Progress of a running generation.
	 *
	 * @param int $feed_id Feed ID.
	 * @return array{done:int,total:int,percent:int}
	 */
	public static function progress( $feed_id ) {
		$state = get_option( Generator::STATE_PREFIX . (int) $feed_id );
		$total = is_array( $state ) ? count( $state['ids'] ) : 0;
		$done  = is_array( $state ) ? min( $total, (int) $state['offset'] ) : 0;
		return array(
			'done'    => $done,
			'total'   => $total,
			'percent' => $total ? (int) floor( 100 * $done / $total ) : 0,
		);
	}

	/**
	 * Status cell of the list (badge, progress or error with a link to the diagnostics).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function status_cell( $feed_id ) {
		$html = self::status_badge( $feed_id );
		$last = Feeds::last_run( $feed_id );
		$key  = self::status_key( $feed_id );
		if ( 'generating' === $key ) {
			$p     = self::progress( $feed_id );
			$html  = '<div data-oliprfe-generating="' . (int) $feed_id . '">' . $html;
			$html .= '<progress class="oliprfe-progress" max="100" value="' . (int) $p['percent'] . '" aria-label="' . esc_attr__( 'Generation progress', 'oli-product-feed' ) . '">' . (int) $p['percent'] . '%</progress>';
			$html .= '<span class="oliprfe-meta oliprfe-progress-text">' . esc_html( self::progress_text( $p, false ) ) . '</span></div>';
		} elseif ( 'error' === $key ) {
			$html .= '<span class="oliprfe-meta">' . esc_html( isset( $last['message'] ) ? $last['message'] : '' ) . ' <a href="' . esc_url( self::diag_url( (int) $feed_id ) ) . '">' . esc_html__( 'See diagnostics', 'oli-product-feed' ) . '</a></span>';
		}
		return $html;
	}

	/**
	 * Progress text.
	 *
	 * @param array{done:int,total:int,percent:int} $p       Progress.
	 * @param bool                                  $percent With the percentage.
	 * @return string
	 */
	public static function progress_text( array $p, $percent = true ) {
		if ( ! $percent ) {
			/* translators: 1: processed products, 2: total products. */
			return sprintf( __( '%1$s of %2$s products', 'oli-product-feed' ), number_format_i18n( $p['done'] ), number_format_i18n( $p['total'] ) );
		}
		/* translators: 1: processed products, 2: total products, 3: percentage. */
		return sprintf( __( '%1$s of %2$s products (%3$s%%)', 'oli-product-feed' ), number_format_i18n( $p['done'] ), number_format_i18n( $p['total'] ), $p['percent'] );
	}

	/**
	 * Feed URL with a Copy button.
	 *
	 * @param int    $feed_id Feed ID.
	 * @param string $context list|side.
	 */
	public static function feed_url_field( $feed_id, $context ) {
		$url    = Storage::url_for( $feed_id );
		$exists = file_exists( Storage::path_for( $feed_id ) );
		$id     = 'oliprfe-url-' . $context . '-' . (int) $feed_id;
		$allowed = array(
			'div'    => array( 'class' => true ),
			'a'      => array(
				'href'   => true,
				'class'  => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'span'   => array(
				'class'       => true,
				'aria-hidden' => true,
				'hidden'      => true,
			),
			'button' => array(
				'type'        => true,
				'class'       => true,
				'data-target' => true,
			),
			'label'  => array(
				'for'   => true,
				'class' => true,
			),
			'input'  => array(
				'type'     => true,
				'id'       => true,
				'class'    => true,
				'readonly' => true,
				'value'    => true,
				'hidden'   => true,
			),
		);
		$button = '<button type="button" class="button button-small oliprfe-copy" data-target="' . esc_attr( $id ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <span class="oliprfe-copy-label">' . esc_html__( 'Copy', 'oli-product-feed' ) . '</span></button>';
		if ( ! $exists ) {
			echo '<span class="oliprfe-meta">' . esc_html__( 'Generate the feed to publish its URL.', 'oli-product-feed' ) . '</span>';
			return;
		}
		if ( 'list' === $context ) {
			// Short label (shortened by CSS), full URL in the title and in the hidden field used by Copy.
			$file   = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$config = Feeds::get( $feed_id );
			if ( Endpoint::enabled() && $config ) {
				$file = $config['file_slug'];
			}
			/* translators: %s: feed file name. */
			$label  = sprintf( __( 'Copy feed URL of %s', 'oli-product-feed' ), $file );
			$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$short  = '' !== $query ? '…/?' . $query : '…/' . $file;
			$html   = '<div class="oliprfe-url"><code title="' . esc_attr( $url ) . '">' . esc_html( $short ) . '</code>';
			$html  .= '<button type="button" class="button button-small oliprfe-copy" data-target="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $label ) . '"><span class="oliprfe-copy-label">' . esc_html__( 'Copy', 'oli-product-feed' ) . '</span></button></div>';
			$html  .= '<label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html__( 'Feed URL', 'oli-product-feed' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" class="code oliprfe-url-input" readonly value="' . esc_attr( $url ) . '" hidden><span class="oliprfe-copy-hint" hidden></span>';
			$allowed['code']   = array( 'title' => true );
			$allowed['button']['aria-label'] = true;
			echo wp_kses( $html, $allowed );
			return;
		}
		$html = '<div class="oliprfe-feed-url"><label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html__( 'Feed URL', 'oli-product-feed' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" class="code oliprfe-url-input" readonly value="' . esc_attr( $url ) . '">' . $button . '</div><span class="oliprfe-copy-hint" hidden></span>';
		echo wp_kses( $html, $allowed );
	}

	/**
	 * Feed list screen: notices, tiles, tabs, views, search, bulk actions, filter and table.
	 */
	private static function render_list() {
		echo '<div class="wrap oliprfe-wrap">';
		self::heading( __( 'Product Feeds', 'oli-product-feed' ) );
		if ( empty( Feeds::all_ids() ) ) {
			self::tabs( 'feeds' );
			self::notices();
			echo '<div class="oliprfe-empty"><span class="dashicons dashicons-rss" aria-hidden="true"></span><h2>' . esc_html__( 'Create your first product feed', 'oli-product-feed' ) . '</h2>';
			echo '<p>' . esc_html__( 'A feed is a file that a shopping platform fetches on a schedule. Pick a channel, a language and a currency, and the rest is set up for you.', 'oli-product-feed' ) . '</p>';
			echo '<p><a class="button button-primary button-large" href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Feed', 'oli-product-feed' ) . '</a></p></div>';
			echo '</div>';
			return;
		}
		$table = new ListTable();
		$table->prepare_items();
		$stats = Overview::stats();
		self::notices();
		Overview::error_notice( $stats['errors'] );
		echo '<div class="oliprfe-run-notices">';
		foreach ( $stats['running'] as $running ) {
			echo Overview::running_notice( $running ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Overview::running_notice().
		}
		echo '</div>';
		Overview::tiles( $stats );
		self::tabs( 'feeds' );
		$table->views();
		echo '<form method="get" id="oliprfe-feeds-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state.
		if ( isset( $_GET['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">';
		}
		foreach ( array( 'orderby', 'order' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( sanitize_key( wp_unslash( $_GET[ $key ] ) ) ) . '">';
			}
		}
		// phpcs:enable
		$table->search_box( __( 'Search Feeds', 'oli-product-feed' ), 'oliprfe-feeds' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Delete dialog (feed list and editor): replaces the browser confirm(). Filled by the script.
	 */
	public static function delete_dialog() {
		if ( ! self::is_plugin_screen() || ! current_user_can( Settings::capability() ) ) {
			return;
		}
		?>
		<dialog class="oliprfe-dialog" id="oliprfe-delete-dialog" aria-labelledby="oliprfe-delete-title" aria-describedby="oliprfe-delete-desc">
			<h2 id="oliprfe-delete-title"></h2>
			<p id="oliprfe-delete-desc"><?php echo esc_html( self::delete_consequence( 2 ) ); ?></p>
			<ul class="ul-disc oliprfe-delete-list"></ul>
			<p><?php esc_html_e( 'To stop a feed without breaking its URL, deactivate it instead.', 'oli-product-feed' ); ?></p>
			<footer>
				<button type="button" class="button oliprfe-dialog-cancel" autofocus><?php esc_html_e( 'Cancel', 'oli-product-feed' ); ?></button>
				<button type="button" class="button oliprfe-dialog-deactivate"><?php esc_html_e( 'Deactivate instead', 'oli-product-feed' ); ?></button>
				<button type="button" class="button oliprfe-danger oliprfe-dialog-delete"></button>
			</footer>
		</dialog>
		<?php
	}

	/**
	 * Settings screen.
	 */
	private static function render_settings() {
		$s     = Settings::all();
		$base  = Storage::base();
		$files = array();
		// One pattern per extension: GLOB_BRACE is missing on some PHP builds (musl, Solaris).
		foreach ( array( 'xml', 'csv', 'tsv', 'txt' ) as $ext ) {
			$found = glob( trailingslashit( $base['dir'] ) . '*.' . $ext );
			$files = array_merge( $files, is_array( $found ) ? $found : array() );
		}
		$size  = 0;
		foreach ( $files as $file ) {
			$size += (int) filesize( $file );
		}
		echo '<div class="wrap oliprfe-wrap">';
		self::heading( __( 'Product Feeds', 'oli-product-feed' ) );
		self::tabs( 'settings' );
		self::notices();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oliprfe_save_settings">';
		wp_nonce_field( 'oliprfe_save_settings' );
		echo '<h2>' . esc_html__( 'Feed files', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="oliprfe-output_dir">' . esc_html__( 'Output folder', 'oli-product-feed' ) . '</label></th><td><code>wp-content/uploads/</code><input type="text" class="regular-text code" id="oliprfe-output_dir" name="s[output_dir]" value="' . esc_attr( $s['output_dir'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Folder inside uploads, created when a feed is generated. Changing it changes every feed URL: the feed files move to the new folder, and the previous folder is removed when the plugin created it and nothing else is in it. A folder that cannot be used (symbolic link, outside uploads, not writable) is refused, and nothing is moved. The oliprfe_output_dir filter can also set it.', 'oli-product-feed' ) . '</p>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: number of files, 2: total size. */
				__( 'Currently %1$s files, %2$s.', 'oli-product-feed' ),
				number_format_i18n( count( $files ) ),
				size_format( $size )
			)
		) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Serve feeds', 'oli-product-feed' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Serve feeds', 'oli-product-feed' ) . '</legend>';
		echo '<label><input type="radio" name="s[serve_mode]" value="file"' . checked( $s['serve_mode'], 'file', false ) . '> ' . esc_html__( 'Static file (fastest; the host or CDN decides the cache headers)', 'oli-product-feed' ) . '</label><br>';
		echo '<label><input type="radio" name="s[serve_mode]" value="endpoint"' . checked( $s['serve_mode'], 'endpoint', false ) . '> ' . esc_html__( 'Through WordPress (?oliprfe_feed=<file>): short cache, noindex, ETag and Last-Modified on any host', 'oli-product-feed' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Use WordPress serving when a CDN caches static files for a long time (stale feeds). The feed URLs change: update them in the platforms.', 'oli-product-feed' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="oliprfe-cache_max_age">' . esc_html__( 'Cache lifetime', 'oli-product-feed' ) . '</label></th><td><input type="number" min="0" max="86400" step="60" class="small-text" id="oliprfe-cache_max_age" name="s[cache_max_age]" value="' . esc_attr( (string) $s['cache_max_age'] ) . '"> ' . esc_html__( 'seconds (WordPress serving only; at most half of the regeneration interval of the feed; 0: no-cache)', 'oli-product-feed' ) . '</td></tr>';
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Generation', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Product changes', 'oli-product-feed' ) . '</th><td><input type="hidden" name="s[regen_on_update]" value="0"><label><input type="checkbox" name="s[regen_on_update]" value="1"' . checked( (bool) $s['regen_on_update'], true, false ) . '> ' . esc_html__( 'Regenerate the active feeds when a product is updated (at most every 15 minutes)', 'oli-product-feed' ) . '</label></td></tr>';
		$next = Scheduler::next_tick();
		echo '<tr><th scope="row">' . esc_html__( 'Schedule check', 'oli-product-feed' ) . '</th><td>' . esc_html(
			$next ? sprintf(
				/* translators: %s: date and time. */
				__( 'Next check of the due feeds: %s (every 15 minutes).', 'oli-product-feed' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
			) : __( 'Not scheduled yet: it is scheduled on the next page load.', 'oli-product-feed' )
		) . '</td></tr>';
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Access', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Shop managers', 'oli-product-feed' ) . '</th><td>';
		echo '<input type="hidden" name="s[shop_manager_access]" value="0"><label><input type="checkbox" name="s[shop_manager_access]" value="1"' . checked( (bool) $s['shop_manager_access'], true, false ) . '> ' . esc_html__( 'Shop managers can manage the feeds (otherwise administrators only)', 'oli-product-feed' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Checked: users with the manage_woocommerce capability (administrators and shop managers). Unchecked: users with the manage_options capability (administrators).', 'oli-product-feed' ) . '</p>';
		echo '</td></tr></tbody></table>';
		echo '<h2>' . esc_html__( 'Activity log', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="oliprfe-log_keep">' . esc_html__( 'Keep', 'oli-product-feed' ) . '</label></th><td><input type="number" min="10" max="5000" step="1" class="small-text" id="oliprfe-log_keep" name="s[log_keep]" value="' . esc_attr( (string) $s['log_keep'] ) . '" aria-describedby="oliprfe-log_keep-desc"> ' . esc_html__( 'entries', 'oli-product-feed' );
		echo '<p class="description" id="oliprfe-log_keep-desc">' . esc_html__( 'Older entries are deleted automatically.', 'oli-product-feed' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="oliprfe-log_level">' . esc_html__( 'WooCommerce log', 'oli-product-feed' ) . '</label></th><td><select id="oliprfe-log_level" name="s[log_level]">';
		foreach ( array(
			'off'   => __( 'Off', 'oli-product-feed' ),
			'error' => __( 'Errors only', 'oli-product-feed' ),
			'info'  => __( 'Errors and every generation', 'oli-product-feed' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['log_level'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Written to WooCommerce > Status > Logs (source: oli-product-feed).', 'oli-product-feed' ) . '</p></td></tr>';
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Uninstall', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Plugin data', 'oli-product-feed' ) . '</th><td>';
		echo '<input type="hidden" name="s[delete_on_uninstall]" value="0"><label><input type="checkbox" name="s[delete_on_uninstall]" value="1"' . checked( (bool) $s['delete_on_uninstall'], true, false ) . '> ' . esc_html__( 'Delete all data when the plugin is deleted (feeds, settings and generated files)', 'oli-product-feed' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Checked: the feeds, the settings, the generated files of every output folder the plugin used and its WooCommerce logs are deleted; files the plugin did not write are never touched. Unchecked: everything is kept. Deactivating the plugin never deletes anything.', 'oli-product-feed' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button();
		echo '</form>';
		echo '<h2>' . esc_html__( 'Maintenance', 'oli-product-feed' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oliprfe_generate_all">';
		wp_nonce_field( 'oliprfe_generate_all' );
		echo '<p>' . esc_html__( 'Queues every active feed now. Feeds already running are skipped. The current files stay online until the new ones replace them.', 'oli-product-feed' ) . '</p>';
		submit_button( __( 'Generate all active feeds', 'oli-product-feed' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Regenerate every active feed (background).
	 */
	public static function handle_generate_all() {
		self::guard();
		check_admin_referer( 'oliprfe_generate_all' );
		foreach ( Feeds::all_ids() as $feed_id ) {
			$feed = Feeds::get( $feed_id );
			if ( $feed && 'publish' === $feed['status'] && ! Generator::is_running( $feed_id ) ) {
				Scheduler::queue( $feed_id );
			}
		}
		self::back( 'all_queued', array( 'tab' => 'settings' ) );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string              $notice Notice code.
	 * @param array<string,mixed> $args   Extra arguments.
	 * @param string              $fragment Editor section to reopen (anchor), or ''.
	 */
	private static function back( $notice, array $args = array(), $fragment = '' ) {
		wp_safe_redirect( self::url( array_merge( $args, array( 'oliprfe_notice' => $notice ) ) ) . ( '' !== $fragment ? '#' . $fragment : '' ) );
		exit;
	}

	/**
	 * Capability check.
	 */
	private static function guard() {
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'oli-product-feed' ), 403 );
		}
	}

	/**
	 * Sanitizes a parsed feed configuration: every setting through Feeds::sanitize(), plus the name
	 * (sanitize_text_field) and the active flag (boolean). Used before the configuration is validated,
	 * stored in the error transient or saved, so no unsanitized input is ever persisted.
	 *
	 * @param array<string,mixed> $config Parsed configuration from Editor::parse().
	 * @return array<string,mixed>
	 */
	private static function sanitize_config( array $config ) {
		$clean           = Feeds::sanitize( $config );
		$clean['name']   = isset( $config['name'] ) ? sanitize_text_field( (string) $config['name'] ) : '';
		$clean['active'] = ! empty( $config['active'] );
		return $clean;
	}

	/**
	 * Save feed.
	 */
	public static function handle_save() {
		self::guard();
		$feed_id = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0;
		check_admin_referer( 'oliprfe_save_feed_' . $feed_id );
		// Every value is sanitized by self::sanitize_config() before it is validated, stored or saved.
		$raw    = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized on the next line by self::sanitize_config().
		$config = self::sanitize_config( Editor::parse( $raw ) );
		$errors = Editor::validate( $config, $feed_id );
		if ( $errors ) {
			set_transient(
				'oliprfe_form_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'config' => $config,
				),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( self::url( $feed_id ? array(
				'action' => 'edit',
				'feed'   => $feed_id,
			) : array( 'action' => 'new' ) ) );
			exit;
		}
		$saved = Feeds::save( $config, $feed_id );
		if ( is_wp_error( $saved ) ) {
			self::back( 'error' );
		}
		$notice = 'saved';
		if ( ! empty( $_POST['generate'] ) ) {
			$notice = is_wp_error( Scheduler::queue( $saved ) ) ? 'running' : 'saved_queued';
		}
		$section = isset( $_POST['oliprfe_section'] ) ? sanitize_key( wp_unslash( $_POST['oliprfe_section'] ) ) : '';
		self::back(
			$notice,
			array(
				'action' => 'edit',
				'feed'   => $saved,
			),
			isset( Editor::sections()[ $section ] ) ? $section : ''
		);
	}

	/**
	 * Creates a feed from the light wizard (channel, language, country, currency, name): inactive,
	 * with the defaults of the channel, then opens its mapping.
	 */
	public static function handle_create() {
		self::guard();
		check_admin_referer( 'oliprfe_create_feed' );
		$raw     = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the keys below are read, each one sanitized.
		$keys    = array( 'channel', 'language', 'country', 'currency', 'name' );
		$picked  = array();
		$channel = Registry::get( isset( $raw['channel'] ) ? sanitize_key( (string) $raw['channel'] ) : '' );
		foreach ( $keys as $key ) {
			$picked[ $key ] = isset( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
		}
		$config = Feeds::sanitize(
			array(
				'channel'  => $picked['channel'],
				'format'   => $channel ? (string) current( $channel->formats() ) : '',
				'language' => $picked['language'],
				'country'  => $picked['country'],
				'currency' => $picked['currency'],
			)
		);
		$config['name']   = '' !== trim( $picked['name'] ) ? $picked['name'] : Editor::suggested_name( $config );
		$config['active'] = false;
		$errors           = array();
		if ( ! $channel ) {
			$errors['channel'] = __( 'Choose a channel.', 'oli-product-feed' );
		}
		if ( '' === $config['country'] ) {
			$errors['country'] = __( 'Choose the country this feed targets.', 'oli-product-feed' );
		}
		if ( $errors ) {
			set_transient(
				'oliprfe_form_' . get_current_user_id(),
				array(
					'errors' => $errors,
					'config' => array_merge( $config, array( 'name' => $picked['name'] ) ),
				),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( self::url( array( 'action' => 'new' ) ) );
			exit;
		}
		$slug = sanitize_title( $config['name'] );
		$base = $slug;
		$i    = 2;
		while ( '' !== $slug && Feeds::find_by_slug( $slug ) ) {
			$slug = $base . '-' . $i;
			++$i;
		}
		$config['file_slug'] = $slug;
		$saved               = Feeds::save( $config, 0 );
		if ( is_wp_error( $saved ) ) {
			self::back( 'error' );
		}
		self::back(
			'created',
			array(
				'action' => 'edit',
				'feed'   => $saved,
			),
			'mapping'
		);
	}

	/**
	 * Feed ID of a nonced GET action.
	 *
	 * @param string $action Action.
	 * @return int
	 */
	private static function checked_feed( $action ) {
		self::guard();
		$feed_id = isset( $_GET['feed'] ) ? absint( $_GET['feed'] ) : 0;
		check_admin_referer( $action . '_' . $feed_id );
		if ( ! Feeds::get( $feed_id ) ) {
			self::back( 'error' );
		}
		return $feed_id;
	}

	/**
	 * Delete feed.
	 */
	public static function handle_delete() {
		$feed_id = self::checked_feed( 'oliprfe_delete_feed' );
		// Without JavaScript the link lands here first: nothing is deleted before the confirmation page.
		if ( empty( $_GET['confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in checked_feed().
			self::confirm_delete( array( $feed_id ) );
		}
		Feeds::delete( $feed_id );
		self::back( 'deleted' );
	}

	/**
	 * Native confirmation page of a deletion (no JavaScript): Delete, Deactivate instead, Cancel.
	 * The JavaScript dialog sends confirm=1 and never shows this page.
	 *
	 * @param int[] $ids Feed IDs.
	 */
	private static function confirm_delete( array $ids ) {
		$n    = count( $ids );
		$list = '';
		foreach ( $ids as $id ) {
			$path  = Storage::path_for( $id );
			$list .= '<li>' . esc_html( get_the_title( $id ) ) . ( file_exists( $path ) ? ' <code>' . esc_html( basename( $path ) ) . '</code>' : '' ) . '</li>';
		}
		/* translators: %d: number of feeds. */
		$title  = sprintf( _n( 'Delete %d feed?', 'Delete %d feeds?', $n, 'oli-product-feed' ), $n );
		/* translators: %d: number of feeds. */
		$delete = sprintf( _n( 'Delete %d feed', 'Delete %d feeds', $n, 'oli-product-feed' ), $n );
		$html   = '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( self::delete_consequence( $n ) ) . '</p><ul>' . $list . '</ul><p>' . esc_html__( 'To stop a feed without breaking its URL, deactivate it instead.', 'oli-product-feed' ) . '</p>';
		if ( 1 === $n ) {
			$html .= '<p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'oli-product-feed' ) . '</a> <a class="button" href="' . esc_url( self::action_url( 'oliprfe_deactivate_feed', $ids[0] ) ) . '">' . esc_html__( 'Deactivate instead', 'oli-product-feed' ) . '</a> <a class="button" href="' . esc_url( add_query_arg( 'confirm', '1', self::action_url( 'oliprfe_delete_feed', $ids[0] ) ) ) . '">' . esc_html( $delete ) . '</a></p>';
		} else {
			$html .= '<form method="post" action="' . esc_url( self::url() ) . '"><input type="hidden" name="confirm" value="1"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'bulk-feeds' ) ) . '">';
			foreach ( $ids as $id ) {
				$html .= '<input type="hidden" name="feed_ids[]" value="' . (int) $id . '">';
			}
			$html .= '<p><a class="button" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Cancel', 'oli-product-feed' ) . '</a> <button type="submit" class="button" name="action" value="deactivate">' . esc_html__( 'Deactivate instead', 'oli-product-feed' ) . '</button> <button type="submit" class="button" name="action" value="delete">' . esc_html( $delete ) . '</button></p></form>';
		}
		wp_die(
			$html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			esc_html( $title ),
			array(
				'response'  => 200,
				'back_link' => false,
			)
		);
	}

	/**
	 * Duplicate feed (inactive copy, new file name).
	 */
	public static function handle_duplicate() {
		$feed_id = self::checked_feed( 'oliprfe_duplicate_feed' );
		$feed    = Feeds::get( $feed_id );
		/* translators: %s: feed name. */
		$feed['name']      = sprintf( __( '%s (copy)', 'oli-product-feed' ), $feed['name'] );
		$feed['active']    = false;
		$feed['file_slug'] = $feed['file_slug'] . '-copy-' . wp_rand( 100, 999 );
		$new               = Feeds::save( $feed );
		self::back( is_wp_error( $new ) ? 'error' : 'duplicated' );
	}

	/**
	 * Generate now (background).
	 */
	public static function handle_generate() {
		$feed_id = self::checked_feed( 'oliprfe_generate' );
		self::back( is_wp_error( Scheduler::queue( $feed_id ) ) ? 'running' : 'queued' );
	}

	/**
	 * Bulk actions of the list (Generate now, Activate, Deactivate, Delete): nonce and capability checked.
	 */
	public static function handle_bulk() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below before any change.
		if ( empty( $_REQUEST['feed_ids'] ) ) {
			return;
		}
		$table  = new ListTable();
		$action = $table->current_action();
		if ( ! in_array( $action, array( 'generate', 'activate', 'deactivate', 'delete' ), true ) ) {
			return;
		}
		self::guard();
		check_admin_referer( 'bulk-feeds' );
		$ids = array_map( 'absint', (array) wp_unslash( $_REQUEST['feed_ids'] ) );
		if ( 'delete' === $action && empty( $_REQUEST['confirm'] ) ) {
			// Without JavaScript: confirmation page first (the dialog sends confirm=1).
			self::confirm_delete( array_values( array_filter( $ids, array( Feeds::class, 'get' ) ) ) );
		}
		foreach ( $ids as $id ) {
			if ( ! Feeds::get( $id ) ) {
				continue;
			}
			if ( 'generate' === $action ) {
				Scheduler::queue( $id );
			} elseif ( 'delete' === $action ) {
				Feeds::delete( $id );
			} else {
				self::set_active( $id, 'activate' === $action );
			}
		}
		$notices = array(
			'generate'   => 'queued',
			'activate'   => 'bulk_activated',
			'deactivate' => 'bulk_off',
			'delete'     => 'bulk_deleted',
		);
		self::back( $notices[ $action ] );
	}

	/**
	 * Activates or deactivates a feed: only the post status changes (the configuration is not rewritten).
	 *
	 * @param int  $feed_id Feed ID.
	 * @param bool $active  Active.
	 */
	private static function set_active( $feed_id, $active ) {
		$status = $active ? 'publish' : 'draft';
		if ( get_post_status( $feed_id ) === $status ) {
			return;
		}
		wp_update_post(
			array(
				'ID'          => (int) $feed_id,
				'post_status' => $status,
			)
		);
		Endpoint::refresh_index();
		Activity::add( $active ? 'activated' : 'deactivated', $feed_id );
	}

	/**
	 * Activate a feed.
	 */
	public static function handle_activate() {
		self::set_active( self::checked_feed( 'oliprfe_activate_feed' ), true );
		self::back( 'activated' );
	}

	/**
	 * Deactivate a feed ("Deactivate instead" of the delete dialog): the URL keeps the last file.
	 */
	public static function handle_deactivate() {
		self::set_active( self::checked_feed( 'oliprfe_deactivate_feed' ), false );
		self::back( 'deactivated' );
	}

	/**
	 * Hides the multilingual notice for the current user ("Do not show again", or its dismiss button).
	 */
	public static function handle_hide_notice() {
		self::guard();
		check_admin_referer( 'oliprfe_hide_notice' );
		update_user_meta( get_current_user_id(), self::HIDE_MULTILINGUAL, 1 );
		if ( ! empty( $_GET['ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked above.
			wp_die( '', '', array( 'response' => 204 ) );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Cancel a running generation: the previous file stays online.
	 */
	public static function handle_cancel() {
		$feed_id = self::checked_feed( 'oliprfe_cancel_generation' );
		Generator::cancel( $feed_id );
		Activity::add( 'cancelled', $feed_id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::BATCH, array( $feed_id ), Scheduler::GROUP );
		}
		wp_clear_scheduled_hook( Scheduler::BATCH, array( $feed_id ) );
		self::back( 'cancelled' );
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'oliprfe_save_settings' );
		$raw = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$saved = Settings::save( $raw );
		Activity::add( 'settings' );
		Activity::prune();
		if ( is_wp_error( $saved ) ) {
			$data = (array) $saved->get_error_data();
			self::back(
				'folder_refused',
				array(
					'tab'            => 'settings',
					'oliprfe_folder' => isset( $data['folder'] ) ? $data['folder'] : '',
					'oliprfe_reason' => isset( $data['reason'] ) ? $data['reason'] : '',
				)
			);
		}
		self::back( 'settings_saved', array( 'tab' => 'settings' ) );
	}

	/**
	 * AJAX: number of products that match the filters of the edited (unsaved) form.
	 */
	public static function ajax_match_count() {
		check_ajax_referer( 'oliprfe_feed_status' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$raw  = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Feeds::sanitize().
		$feed = Feeds::sanitize( Editor::parse( $raw ) );
		$ids  = \Oliprfe\Query::ids( $feed );
		$vars = $ids ? count(
			get_posts(
				array(
					'post_type'      => 'product_variation',
					'post_status'    => 'any',
					'post__in'       => $ids,
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		) : 0;
		wp_send_json_success(
			array(
				'text' => sprintf(
					/* translators: 1: items, 2: products, 3: variations. */
					__( '%1$s items match these filters (%2$s products and %3$s variations).', 'oli-product-feed' ),
					number_format_i18n( count( $ids ) ),
					number_format_i18n( count( $ids ) - $vars ),
					number_format_i18n( $vars )
				),
			)
		);
	}

	/**
	 * Nonce + capability of the editor AJAX actions.
	 */
	private static function ajax_guard() {
		check_ajax_referer( 'oliprfe_feed_status' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
	}

	/**
	 * Unsaved feed configuration posted by the editor (sanitized), with its ID.
	 *
	 * @return array<string,mixed>
	 */
	private static function posted_feed() {
		$raw        = isset( $_POST['feed'] ) && is_array( $_POST['feed'] ) ? wp_unslash( $_POST['feed'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked in self::ajax_guard(); sanitized by Feeds::sanitize().
		$parsed       = Editor::parse( $raw );
		$feed         = Feeds::sanitize( $parsed );
		$feed['name'] = isset( $parsed['name'] ) ? sanitize_text_field( (string) $parsed['name'] ) : ''; // Header of the file (site_description).
		$feed['id']   = isset( $_POST['feed_id'] ) ? absint( $_POST['feed_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in self::ajax_guard().
		return $feed;
	}

	/**
	 * AJAX: mapping preview of one product with the unsaved form (one value per attribute).
	 */
	public static function ajax_preview_row() {
		self::ajax_guard();
		$feed    = self::posted_feed();
		$product = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in self::ajax_guard().
		$result  = $product ? Generator::build_one( $feed, $product ) : null;
		if ( ! $result ) {
			wp_send_json_error();
		}
		$fields = array();
		foreach ( (array) $result['row'] as $field => $value ) {
			$value            = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$fields[ $field ] = mb_strlen( $value ) > 140 ? mb_substr( $value, 0, 140 ) . '…' : $value;
		}
		wp_send_json_success(
			array(
				'head'     => sprintf(
					/* translators: %s: product name. */
					__( 'Preview: %s', 'oli-product-feed' ),
					Editor::product_label( $product )
				),
				'fields'   => $fields,
				'excluded' => null === $result['row'] ? sprintf(
					/* translators: %s: reason. */
					__( 'This product is not in the feed: %s', 'oli-product-feed' ),
					Editor::reason_label( $result['reason'] )
				) : '',
			)
		);
	}

	/**
	 * AJAX: dry-run preview of the first 5 items with the unsaved form.
	 */
	public static function ajax_preview() {
		self::ajax_guard();
		wp_send_json_success( array( 'html' => Editor::preview_html( self::posted_feed() ) ) );
	}

	/**
	 * AJAX: products and variations whose name contains the search (20 at most).
	 */
	public static function ajax_product_search() {
		self::ajax_guard();
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in self::ajax_guard().
		$out  = array();
		if ( '' !== $term ) {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'publish',
					's'              => $term,
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( ctype_digit( $term ) && wc_get_product( (int) $term ) ) {
				array_unshift( $ids, (int) $term );
			}
			foreach ( array_slice( array_unique( array_map( 'intval', $ids ) ), 0, 20 ) as $id ) {
				$out[] = array(
					'id'    => $id,
					'label' => Editor::product_label( $id ),
				);
			}
		}
		wp_send_json_success( $out );
	}

	/**
	 * AJAX: Google categories from the local list (20 at most, no network call).
	 */
	public static function ajax_gcat_search() {
		self::ajax_guard();
		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked in self::ajax_guard().
		$out  = array();
		foreach ( GoogleTaxonomy::search( $term, 20 ) as $id => $path ) {
			$out[] = array(
				'id'   => $id,
				'path' => $path,
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * AJAX: progress of running feeds.
	 */
	public static function ajax_status() {
		check_ajax_referer( 'oliprfe_feed_status' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		$ids = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ) : array();
		$out = array();
		foreach ( $ids as $id ) {
			$feed = Feeds::get( $id );
			if ( ! $feed ) {
				continue;
			}
			$p       = self::progress( $id );
			$last    = Feeds::last_run( $id );
			$running = Generator::is_running( $id );
			$row     = array(
				'id'           => $id,
				'name'         => $feed['name'],
				'status'       => $running ? 'generating' : ( isset( $last['status'] ) ? $last['status'] : 'never' ),
				'percent'      => $p['percent'],
				'progressText' => self::progress_text( $p, false ),
				'noticeText'   => Overview::running_text( $feed['name'], $p ),
			);
			if ( ! $running ) {
				// Finished: the new cells of the row and the end notice (escaped HTML built on the server).
				$row['cells']  = array(
					'status'         => self::status_cell( $id ),
					'items'          => ListTable::items_cell( $feed ),
					'last_generated' => ListTable::last_cell( $feed ),
					'url'            => ListTable::url_cell( $id ),
				);
				$row['notice'] = Overview::finished_notice( $id );
				$row['speak']  = 'error' === $row['status']
					/* translators: 1: feed name, 2: error message. */
					? sprintf( __( '%1$s could not be generated: %2$s', 'oli-product-feed' ), $feed['name'], isset( $last['message'] ) ? $last['message'] : '' )
					: wp_strip_all_tags( $row['notice'] );
			}
			$out[] = $row;
		}
		wp_send_json_success( $out );
	}
}
