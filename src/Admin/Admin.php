<?php // phpcs:disable WordPress.Files.FileName -- PSR-4 autoload (Oliprfe\Foo -> src/Foo.php).
/**
 * Admin screens: WooCommerce > Product Feeds (feeds list, feed editor, settings) and their actions.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

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
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_oliprfe_save_feed', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_oliprfe_delete_feed', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_oliprfe_duplicate_feed', array( __CLASS__, 'handle_duplicate' ) );
		add_action( 'admin_post_oliprfe_generate', array( __CLASS__, 'handle_generate' ) );
		add_action( 'admin_post_oliprfe_save_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_oliprfe_generate_all', array( __CLASS__, 'handle_generate_all' ) );
		add_action( 'wp_ajax_oliprfe_feed_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'wp_ajax_oliprfe_match_count', array( __CLASS__, 'ajax_match_count' ) );
		add_action( 'wp_ajax_oliprfe_dismiss_donate', array( __CLASS__, 'ajax_dismiss_donate' ) );
		add_action( 'admin_notices', array( __CLASS__, 'donate_notice' ) );
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
	 * Plugins screen links (Feeds + Donate).
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		$feeds  = '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Feeds', 'oli-product-feed' ) . '</a>';
		$donate = '<a href="' . esc_url( OLIPRFE_DONATE_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Donate', 'oli-product-feed' ) . '</a>';
		array_unshift( $links, $feeds, $donate );
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
		if ( plugin_basename( OLIPRFE_FILE ) !== $file ) {
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
	 * Dismissible support notice on plugin screens.
	 */
	public static function donate_notice() {
		if ( ! self::is_plugin_screen() || ! current_user_can( Settings::capability() ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'oliprfe_donate_dismissed', true ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible oliprfe-donate-notice">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: donate URL */
						__( 'Enjoying Oli Product Feed? <a href="%s" target="_blank" rel="noopener noreferrer">Buy me a coffee</a> — your support keeps the plugin free and maintained.', 'oli-product-feed' ),
						esc_url( OLIPRFE_DONATE_URL )
					),
					array(
						'a' => array(
							'href'   => true,
							'target' => true,
							'rel'    => true,
						),
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Persist donate notice dismissal.
	 */
	public static function ajax_dismiss_donate() {
		check_ajax_referer( 'oliprfe_donate', 'nonce' );
		if ( ! current_user_can( Settings::capability() ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), 'oliprfe_donate_dismissed', 1 );
		wp_send_json_success();
	}

	/**
	 * Footer donation line used on plugin screens.
	 */
	public static function support_footer() {
		echo '<p class="description oliprfe-support-footer">';
		echo wp_kses(
			sprintf(
				/* translators: %s: donate URL */
				__( 'If this plugin helps you, please consider a <a href="%s" target="_blank" rel="noopener noreferrer">donation</a>. Thank you!', 'oli-product-feed' ),
				esc_url( OLIPRFE_DONATE_URL )
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		);
		echo '</p>';
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
			add_action( 'load-' . $hook, array( __CLASS__, 'handle_bulk' ) );
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
		wp_localize_script(
			'oliprfe-admin',
			'oliprfeAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'oliprfe_feed_status' ),
				'donateNonce' => wp_create_nonce( 'oliprfe_donate' ),
				'i18n'        => array(
					'copy'       => __( 'Copy', 'oli-product-feed' ),
					'copied'     => __( 'Copied', 'oli-product-feed' ),
					'copiedLong' => __( 'Feed URL copied to clipboard.', 'oli-product-feed' ),
					'copyFailed' => __( 'Press Ctrl+C (Cmd+C on a Mac) to copy the selected URL.', 'oli-product-feed' ),
					'generated'  => __( 'Feed generated.', 'oli-product-feed' ),
					'failed'     => __( 'Feed generation failed.', 'oli-product-feed' ),
					'badName'    => __( 'Use lowercase letters, digits and underscores.', 'oli-product-feed' ),
					'exists'     => __( 'This attribute is already in the list.', 'oli-product-feed' ),
					'counting'   => __( 'Counting…', 'oli-product-feed' ),
					'countError' => __( 'The count failed.', 'oli-product-feed' ),
				),
			)
		);
		wp_add_inline_script( 'oliprfe-admin', self::donate_dismiss_js() );
	}

	/**
	 * Dismiss handler for the support notice (attached to the enqueued admin script).
	 *
	 * @return string
	 */
	private static function donate_dismiss_js() {
		return "(function () {\n"
			. "\tvar notice = document.querySelector('.oliprfe-donate-notice');\n"
			. "\tif (!notice || typeof oliprfeAdmin === 'undefined') return;\n"
			. "\tnotice.addEventListener('click', function (e) {\n"
			. "\t\tif (!e.target.classList.contains('notice-dismiss')) return;\n"
			. "\t\tvar body = new FormData();\n"
			. "\t\tbody.append('action', 'oliprfe_dismiss_donate');\n"
			. "\t\tbody.append('nonce', oliprfeAdmin.donateNonce || '');\n"
			. "\t\tif (window.fetch) {\n"
			. "\t\t\tfetch(oliprfeAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' });\n"
			. "\t\t}\n"
			. "\t});\n"
			. '})();';
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
		if ( 'new' === $action || ( 'edit' === $action && $feed && Feeds::get( $feed ) ) ) {
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
	 * Tabs.
	 *
	 * @param string $current feeds|settings.
	 */
	public static function tabs( $current ) {
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Secondary menu', 'oli-product-feed' ) . '">';
		foreach ( array(
			'feeds'    => __( 'Feeds', 'oli-product-feed' ),
			'settings' => __( 'Settings', 'oli-product-feed' ),
		) as $tab => $label ) {
			$url = 'feeds' === $tab ? self::url() : self::url( array( 'tab' => $tab ) );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $current === $tab ? ' nav-tab-active" aria-current="page' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Notices from the last action.
	 */
	public static function notices() {
		$code     = isset( $_GET['oliprfe_notice'] ) ? sanitize_key( wp_unslash( $_GET['oliprfe_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'          => array( 'success', __( 'Feed saved.', 'oli-product-feed' ) ),
			'saved_queued'   => array( 'success', __( 'Feed saved. Generation started in the background.', 'oli-product-feed' ) ),
			'deleted'        => array( 'success', __( 'Feed deleted.', 'oli-product-feed' ) ),
			'duplicated'     => array( 'success', __( 'Feed duplicated (inactive). Review it, then generate it.', 'oli-product-feed' ) ),
			'queued'         => array( 'info', __( 'Generation started in the background.', 'oli-product-feed' ) ),
			'running'        => array( 'warning', __( 'A generation is already running for this feed.', 'oli-product-feed' ) ),
			'error'          => array( 'error', __( 'The action failed.', 'oli-product-feed' ) ),
			'settings_saved' => array( 'success', __( 'Settings saved.', 'oli-product-feed' ) ),
			'all_queued'     => array( 'info', __( 'Generation of every active feed started in the background.', 'oli-product-feed' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
		if ( 'folder_refused' === $code ) {
			$folder = isset( $_GET['oliprfe_folder'] ) ? Storage::sanitize_folder( sanitize_text_field( wp_unslash( $_GET['oliprfe_folder'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$reason = isset( $_GET['oliprfe_reason'] ) ? sanitize_key( wp_unslash( $_GET['oliprfe_reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
				__( 'Settings saved, but the output folder was not changed.', 'oli-product-feed' ) . ' ' . Storage::folder_message( $folder, $reason ) . ' ' . sprintf(
				/* translators: %s: folder, e.g. "oli-product-feed". */
					__( 'The feeds stay in wp-content/uploads/%s.', 'oli-product-feed' ),
					Storage::folder()
				)
			) . '</p></div>';
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
		if ( ! \Oliprfe\Lang::has_translatepress() && 'feeds' === ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'feeds' ) && ! isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No multilingual plugin detected: feeds use the site language. With TranslatePress, you can create one feed per language.', 'oli-product-feed' ) . '</p></div>';
		}
	}

	/**
	 * Status badge HTML.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function status_badge( $feed_id ) {
		$last = Feeds::last_run( $feed_id );
		if ( Generator::is_running( $feed_id ) ) {
			$status = array( 'generating', 'dashicons-update', __( 'Generating', 'oli-product-feed' ) );
		} elseif ( ! empty( $last['status'] ) && 'error' === $last['status'] ) {
			$status = array( 'error', 'dashicons-warning', __( 'Error', 'oli-product-feed' ) );
		} elseif ( 'publish' !== get_post_status( $feed_id ) ) {
			$status = array( 'inactive', 'dashicons-controls-pause', __( 'Inactive', 'oli-product-feed' ) );
		} elseif ( empty( $last['time'] ) ) {
			$status = array( 'never', 'dashicons-clock', __( 'Never generated', 'oli-product-feed' ) );
		} else {
			$status = array( 'active', 'dashicons-yes-alt', __( 'Active', 'oli-product-feed' ) );
		}
		return '<span class="oliprfe-status oliprfe-status-' . esc_attr( $status[0] ) . '"><span class="dashicons ' . esc_attr( $status[1] ) . '" aria-hidden="true"></span>' . esc_html( $status[2] ) . '</span>';
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
	 * Status cell of the list (badge, progress or error).
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	public static function status_cell( $feed_id ) {
		$html = self::status_badge( $feed_id );
		$last = Feeds::last_run( $feed_id );
		if ( Generator::is_running( $feed_id ) ) {
			$p     = self::progress( $feed_id );
			$html  = '<div data-oliprfe-generating="' . (int) $feed_id . '">' . $html;
			$html .= '<progress class="oliprfe-progress" max="100" value="' . (int) $p['percent'] . '" aria-label="' . esc_attr__( 'Generation progress', 'oli-product-feed' ) . '">' . (int) $p['percent'] . '%</progress>';
			$html .= '<span class="oliprfe-meta oliprfe-progress-text">' . esc_html( self::progress_text( $p ) ) . '</span></div>';
		} elseif ( ! empty( $last['status'] ) && 'error' === $last['status'] ) {
			$html .= '<span class="oliprfe-error-text">' . esc_html( isset( $last['message'] ) ? $last['message'] : '' ) . '</span>';
		}
		return $html;
	}

	/**
	 * Progress text.
	 *
	 * @param array{done:int,total:int,percent:int} $p Progress.
	 * @return string
	 */
	private static function progress_text( array $p ) {
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
		$url     = Storage::url_for( $feed_id );
		$exists  = file_exists( Storage::path_for( $feed_id ) );
		$id      = 'oliprfe-url-' . $context . '-' . (int) $feed_id;
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
		$button  = '<button type="button" class="button button-small oliprfe-copy" data-target="' . esc_attr( $id ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span> <span class="oliprfe-copy-label">' . esc_html__( 'Copy', 'oli-product-feed' ) . '</span></button>';
		if ( ! $exists ) {
			echo '<span class="oliprfe-meta">' . esc_html__( 'Generate the feed to publish its URL.', 'oli-product-feed' ) . '</span>';
			return;
		}
		if ( 'list' === $context ) {
			// Short distinct label: the file name (static file) or the feed slug (WordPress serving); full URL in the title.
			$config = Feeds::get( $feed_id );
			$label  = Endpoint::enabled() && $config ? '?' . $config['file_slug'] : basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$html   = '<div class="oliprfe-feed-url"><a href="' . esc_url( $url ) . '" class="oliprfe-url-link" title="' . esc_attr( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'oli-product-feed' ) . '</span></a>' . $button . '</div>';
			echo wp_kses( $html, $allowed );
			echo '<div class="oliprfe-feed-url"><label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html__( 'Feed URL', 'oli-product-feed' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" class="code oliprfe-url-input" readonly value="' . esc_attr( $url ) . '" hidden></div><span class="oliprfe-copy-hint" hidden></span>';
			return;
		}
		$html = '<div class="oliprfe-feed-url"><label for="' . esc_attr( $id ) . '" class="screen-reader-text">' . esc_html__( 'Feed URL', 'oli-product-feed' ) . '</label><input type="text" id="' . esc_attr( $id ) . '" class="code oliprfe-url-input" readonly value="' . esc_attr( $url ) . '">' . $button . '</div><span class="oliprfe-copy-hint" hidden></span>';
		echo wp_kses( $html, $allowed );
	}

	/**
	 * Feed list screen.
	 */
	private static function render_list() {
		$table = new ListTable();
		$table->prepare_items();
		echo '<div class="wrap oliprfe-wrap">';
		self::heading( __( 'Product Feeds', 'oli-product-feed' ) );
		self::tabs( 'feeds' );
		self::notices();
		if ( empty( Feeds::all_ids() ) ) {
			echo '<div class="oliprfe-empty"><span class="dashicons dashicons-rss" aria-hidden="true"></span><h2>' . esc_html__( 'Create your first product feed', 'oli-product-feed' ) . '</h2>';
			echo '<p>' . esc_html__( 'One feed per platform, language and currency. The file is regenerated in the background and its URL never changes.', 'oli-product-feed' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( self::url( array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Add New Feed', 'oli-product-feed' ) . '</a></p></div>';
			self::support_footer();
			echo '</div>';
			return;
		}
		$table->views();
		echo '<form method="get" class="oliprfe-search">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		if ( isset( $_GET['channel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="channel" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['channel'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search feeds', 'oli-product-feed' ), 'oliprfe-feeds' );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( self::url() ) . '">';
		$table->display();
		echo '</form>';
		self::support_footer();
		echo '</div>';
	}

	/**
	 * Settings screen.
	 */
	private static function render_settings() {
		$s     = Settings::all();
		$base  = Storage::base();
		$files = glob( trailingslashit( $base['dir'] ) . '*.{xml,csv,tsv,txt}', GLOB_BRACE );
		$files = is_array( $files ) ? $files : array();
		$size  = 0;
		foreach ( $files as $file ) {
			$size += (int) filesize( $file );
		}
		echo '<div class="wrap oliprfe-wrap">';
		self::heading( __( 'Product Feeds Settings', 'oli-product-feed' ), false );
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
		echo '<tr><th scope="row"><label for="oliprfe-log_level">' . esc_html__( 'Log', 'oli-product-feed' ) . '</label></th><td><select id="oliprfe-log_level" name="s[log_level]">';
		foreach ( array(
			'off'   => __( 'Off', 'oli-product-feed' ),
			'error' => __( 'Errors only', 'oli-product-feed' ),
			'info'  => __( 'Errors and every generation', 'oli-product-feed' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $s['log_level'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Written to WooCommerce > Status > Logs (source: oli-product-feed).', 'oli-product-feed' ) . '</p></td></tr>';
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
		echo '<h2>' . esc_html__( 'Uninstall', 'oli-product-feed' ) . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">' . esc_html__( 'Plugin data', 'oli-product-feed' ) . '</th><td>';
		echo '<input type="hidden" name="s[delete_on_uninstall]" value="0"><label><input type="checkbox" name="s[delete_on_uninstall]" value="1"' . checked( (bool) $s['delete_on_uninstall'], true, false ) . '> ' . esc_html__( 'Delete all data when the plugin is deleted (feeds, settings and generated files)', 'oli-product-feed' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Checked: the feeds, the settings, the generated files of every output folder the plugin used and its WooCommerce logs are deleted; files the plugin did not write are never touched. Unchecked: everything is kept.', 'oli-product-feed' ) . '</p>';
		echo '</td></tr></tbody></table>';
		submit_button();
		echo '</form>';
		echo '<h2>' . esc_html__( 'Maintenance', 'oli-product-feed' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="oliprfe_generate_all">';
		wp_nonce_field( 'oliprfe_generate_all' );
		echo '<p>' . esc_html__( 'Regenerates every active feed in the background. The current files stay online until the new ones replace them.', 'oli-product-feed' ) . '</p>';
		submit_button( __( 'Regenerate all active feeds', 'oli-product-feed' ), 'secondary', 'submit', false );
		echo '</form>';
		self::support_footer();
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
	 */
	private static function back( $notice, array $args = array() ) {
		wp_safe_redirect( self::url( array_merge( $args, array( 'oliprfe_notice' => $notice ) ) ) );
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
			wp_safe_redirect(
				self::url(
					$feed_id ? array(
						'action' => 'edit',
						'feed'   => $feed_id,
					) : array( 'action' => 'new' )
				)
			);
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
		self::back(
			$notice,
			array(
				'action' => 'edit',
				'feed'   => $saved,
			)
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
		Feeds::delete( self::checked_feed( 'oliprfe_delete_feed' ) );
		self::back( 'deleted' );
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
	 * Bulk actions of the list (posted to the list screen itself).
	 */
	public static function handle_bulk() {
		if ( empty( $_POST['feed_ids'] ) ) {
			return;
		}
		self::guard();
		check_admin_referer( 'bulk-feeds' );
		$table  = new ListTable();
		$action = $table->current_action();
		$ids    = array_map( 'absint', (array) wp_unslash( $_POST['feed_ids'] ) );
		if ( ! in_array( $action, array( 'generate', 'delete' ), true ) ) {
			return;
		}
		foreach ( $ids as $id ) {
			if ( ! Feeds::get( $id ) ) {
				continue;
			}
			if ( 'generate' === $action ) {
				Scheduler::queue( $id );
			} else {
				Feeds::delete( $id );
			}
		}
		self::back( 'delete' === $action ? 'deleted' : 'queued' );
	}

	/**
	 * Save settings.
	 */
	public static function handle_settings() {
		self::guard();
		check_admin_referer( 'oliprfe_save_settings' );
		$raw   = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::save().
		$saved = Settings::save( $raw );
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
			$p     = self::progress( $id );
			$last  = Feeds::last_run( $id );
			$out[] = array(
				'id'           => $id,
				'name'         => $feed['name'],
				'status'       => Generator::is_running( $id ) ? 'generating' : ( isset( $last['status'] ) ? $last['status'] : 'never' ),
				'percent'      => $p['percent'],
				'progressText' => self::progress_text( $p ),
			);
		}
		wp_send_json_success( $out );
	}
}
