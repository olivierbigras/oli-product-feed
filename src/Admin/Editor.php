<?php
/**
 * Feed editor: renders the add/edit form and turns the posted form into a feed configuration.
 *
 * @package Oliprfe
 */

namespace Oliprfe\Admin;

use Oliprfe\Channels\Channel;
use Oliprfe\Channels\Registry;
use Oliprfe\Feeds;
use Oliprfe\Generator;
use Oliprfe\Integrations\PriceBasedOnCountry;
use Oliprfe\Lang;
use Oliprfe\Pricing;
use Oliprfe\Scheduler;
use Oliprfe\Sources;
use Oliprfe\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Feed form.
 */
final class Editor {

	/**
	 * Sample row shown in the mapping table.
	 *
	 * @var array{id:int,row:array<string,mixed>}|null
	 */
	private static $preview = null;

	/**
	 * Fields built by the plugin when no source is mapped.
	 *
	 * @return array<string,string>
	 */
	private static function automatic_fields() {
		return array(
			'item_group_id'             => __( 'Parent product (variations)', 'oli-product-feed' ),
			'title'                     => __( 'Product name in the feed language (or the title template)', 'oli-product-feed' ),
			'description'               => __( 'Description in the feed language, plain text', 'oli-product-feed' ),
			'link'                      => __( 'Product URL in the feed language', 'oli-product-feed' ),
			'image_link'                => __( 'Main image (variation image first)', 'oli-product-feed' ),
			'additional_image_link'     => __( 'Gallery images', 'oli-product-feed' ),
			'availability'              => __( 'Stock status and availability rules (backorder date when the lead time option is on)', 'oli-product-feed' ),
			'availability_date'         => __( 'Now plus the backorder lead time, in the site time zone', 'oli-product-feed' ),
			'min_handling_time'         => __( 'Feed handling time for backorder items', 'oli-product-feed' ),
			'max_handling_time'         => __( 'Feed handling time for backorder items', 'oli-product-feed' ),
			'price'                     => __( 'Regular price and currency (price source)', 'oli-product-feed' ),
			'sale_price'                => __( 'Sale price when lower than the regular price', 'oli-product-feed' ),
			'sale_price_effective_date' => __( 'Sale dates, when set', 'oli-product-feed' ),
			'condition'                 => __( 'new', 'oli-product-feed' ),
			'google_product_category'   => __( 'Google category of the product category (see below)', 'oli-product-feed' ),
			'product_type'              => __( 'Category path in the feed language', 'oli-product-feed' ),
			'identifier_exists'         => __( '"no" when there is no GTIN or MPN', 'oli-product-feed' ),
			'color'                     => __( 'Attribute pa_color (or pa_colour, Color, Colour), variation value first, one value only', 'oli-product-feed' ),
			'size'                      => __( 'Attribute pa_size (or Size), variation value first, one value only', 'oli-product-feed' ),
		);
	}

	/**
	 * Source types.
	 *
	 * @return array<string,string>
	 */
	private static function source_types() {
		return array(
			'auto'      => __( 'Automatic', 'oli-product-feed' ),
			'field'     => __( 'Product field', 'oli-product-feed' ),
			'attribute' => __( 'Product attribute', 'oli-product-feed' ),
			'taxonomy'  => __( 'Taxonomy', 'oli-product-feed' ),
			'meta'      => __( 'Custom field (meta key)', 'oli-product-feed' ),
			'static'    => __( 'Static value', 'oli-product-feed' ),
			'item'      => __( 'Copy of another feed attribute (final value)', 'oli-product-feed' ),
			'none'      => __( 'Do not send', 'oli-product-feed' ),
		);
	}

	/**
	 * Renders the edit screen.
	 *
	 * @param int                 $feed_id Feed ID (0 = new).
	 * @param array<string,mixed> $errors  Field errors (key => message).
	 * @param array<string,mixed> $posted  Posted configuration to show again after an error.
	 */
	public static function render( $feed_id, array $errors = array(), array $posted = array() ) {
		$feed = $feed_id ? Feeds::get( $feed_id ) : null;
		if ( $posted ) {
			$feed = array_merge( $feed ? $feed : array(), Feeds::sanitize( $posted ), array(
					'name'   => isset( $posted['name'] ) ? $posted['name'] : '',
					'status' => ! empty( $posted['active'] ) ? 'publish' : 'draft',
				)
			);
		}
		if ( ! $feed ) {
			$feed           = Feeds::sanitize( array() );
			$feed['name']   = '';
			$feed['status'] = 'publish';
			$feed['id']     = 0;
		}
		$channel = Registry::get( $feed['channel'] );
		$title   = $feed_id ? __( 'Edit Feed', 'oli-product-feed' ) : __( 'Add New Feed', 'oli-product-feed' );

		echo '<div class="wrap oliprfe-wrap">';
		Admin::heading( $title, (bool) $feed_id );
		Admin::tabs( 'feeds' );
		Admin::notices();
		if ( $errors ) {
			echo '<div class="notice notice-error oliprfe-error-summary" tabindex="-1"><p><strong>' . esc_html__( 'The feed was not saved. Fix the following:', 'oli-product-feed' ) . '</strong></p><ul>';
			foreach ( $errors as $key => $message ) {
				echo '<li><a href="#oliprfe-' . esc_attr( $key ) . '">' . esc_html( $message ) . '</a></li>';
			}
			echo '</ul></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" novalidate>';
		echo '<input type="hidden" name="action" value="oliprfe_save_feed">';
		echo '<input type="hidden" name="feed_id" value="' . esc_attr( (string) (int) $feed_id ) . '">';
		wp_nonce_field( 'oliprfe_save_feed_' . (int) $feed_id );

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2">';
		echo '<div id="post-body-content">';
		echo '<div id="titlediv"><div id="titlewrap"><label class="screen-reader-text" for="oliprfe-name">' . esc_html__( 'Feed name', 'oli-product-feed' ) . '</label>';
		echo wp_kses( '<input type="text" name="feed[name]" id="oliprfe-name" size="30" value="' . esc_attr( $feed['name'] ) . '" spellcheck="true" autocomplete="off" placeholder="' . esc_attr__( 'Feed name', 'oli-product-feed' ) . '"' . self::invalid( $errors, 'name' ) . '>', self::allowed_html() );
		self::error( $errors, 'name' );
		echo '</div></div></div>';

		echo '<div id="postbox-container-1" class="postbox-container"><div class="oliprfe-sticky">';
		self::sidebar( $feed, (int) $feed_id );
		echo '</div></div>';

		echo '<div id="postbox-container-2" class="postbox-container">';
		self::box_target( $feed, $errors );
		self::box_filters( $feed );
		self::box_texts( $feed );
		self::box_mapping( $feed, $channel );
		self::box_categories( $feed );
		self::box_links( $feed );
		self::box_schedule( $feed );
		echo '<p class="oliprfe-submit-bottom"><button type="submit" class="button button-primary button-large" name="save" value="1">' . esc_html__( 'Save feed', 'oli-product-feed' ) . '</button></p>';
		echo '</div></div><br class="clear"></div></form>';
		Admin::support_footer();
		echo '</div>';
	}

	/**
	 * aria-invalid attributes for a field.
	 *
	 * @param array<string,string> $errors Errors.
	 * @param string               $key    Field key.
	 * @return string
	 */
	private static function invalid( array $errors, $key ) {
		return isset( $errors[ $key ] ) ? ' aria-invalid="true" aria-describedby="oliprfe-' . esc_attr( $key ) . '-err"' : '';
	}

	/**
	 * HTML tags and attributes allowed in admin form fragments built by this class.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function allowed_html() {
		$attrs = array(
			'class'             => true,
			'id'                => true,
			'name'              => true,
			'value'             => true,
			'type'              => true,
			'for'               => true,
			'href'              => true,
			'title'             => true,
			'target'            => true,
			'rel'               => true,
			'checked'           => true,
			'selected'          => true,
			'disabled'          => true,
			'readonly'          => true,
			'hidden'            => true,
			'open'              => true,
			'min'               => true,
			'max'               => true,
			'step'              => true,
			'rows'              => true,
			'cols'              => true,
			'size'              => true,
			'placeholder'       => true,
			'pattern'           => true,
			'list'              => true,
			'autocomplete'      => true,
			'spellcheck'        => true,
			'role'              => true,
			'scope'             => true,
			'colspan'           => true,
			'aria-label'        => true,
			'aria-labelledby'   => true,
			'aria-describedby'  => true,
			'aria-hidden'       => true,
			'aria-invalid'      => true,
			'aria-current'      => true,
			'aria-live'         => true,
			'data-src'          => true,
			'data-target'       => true,
			'data-confirm'      => true,
			'data-formats'      => true,
			'data-colname'      => true,
			'data-oliprfe-generating' => true,
			'width'             => true,
			'height'            => true,
			'alt'               => true,
			'src'               => true,
		);
		$tags = array(
			'a', 'br', 'button', 'caption', 'code', 'details', 'div', 'em', 'fieldset',
			'h2', 'h3', 'img', 'input', 'label', 'legend', 'li', 'option', 'p', 'progress',
			'select', 'span', 'strong', 'summary', 'table', 'tbody', 'td', 'textarea',
			'th', 'thead', 'tr', 'ul',
		);
		$out = array();
		foreach ( $tags as $tag ) {
			$out[ $tag ] = $attrs;
		}
		return $out;
	}


	/**
	 * Field error message.
	 *
	 * @param array<string,string> $errors Errors.
	 * @param string               $key    Field key.
	 */
	private static function error( array $errors, $key ) {
		if ( isset( $errors[ $key ] ) ) {
			echo '<p class="oliprfe-field-error" id="oliprfe-' . esc_attr( $key ) . '-err">' . esc_html( $errors[ $key ] ) . '</p>';
		}
	}

	/**
	 * Opens a postbox.
	 *
	 * @param string $id    Box ID.
	 * @param string $title Title.
	 */
	private static function open_box( $id, $title ) {
		echo '<div id="' . esc_attr( $id ) . '" class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html( $title ) . '</h2></div><div class="inside">';
	}

	/**
	 * Closes a postbox.
	 */
	private static function close_box() {
		echo '</div></div>';
	}

	/**
	 * Form table row.
	 *
	 * @param string $label Label (already plain text).
	 * @param string $for   Input ID ('' = fieldset).
	 * @param string $html  Control HTML (already escaped).
	 * @param string $desc     Description.
	 * @param string $tr_class Optional class on the row.
	 * @param bool   $hidden   Hidden attribute.
	 */
	private static function row( $label, $for, $html, $desc = '', $tr_class = '', $hidden = false ) {
		echo '<tr' . ( '' !== $tr_class ? ' class="' . esc_attr( $tr_class ) . '"' : '' ) . ( $hidden ? ' hidden' : '' ) . '><th scope="row">';
		if ( '' !== $for ) {
			echo '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>';
		} else {
			echo esc_html( $label );
		}
		echo '</th><td>' . wp_kses( $html, self::allowed_html() );
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * <select> HTML.
	 *
	 * @param string               $name     Name.
	 * @param string               $id       ID.
	 * @param array<string,string> $options  Options.
	 * @param string               $selected Selected value.
	 * @param string               $attrs    Extra attributes (already escaped).
	 * @return string
	 */
	public static function select( $name, $id, array $options, $selected, $attrs = '' ) {
		$html = '<select name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . $attrs . '>';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * Checkbox HTML.
	 *
	 * @param string $name    Name.
	 * @param bool   $checked Checked.
	 * @param string $label   Label.
	 * @param string $value   Value.
	 * @return string
	 */
	private static function checkbox( $name, $checked, $label, $value = '1' ) {
		return '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * Text input HTML.
	 *
	 * @param string $name  Name.
	 * @param string $id    ID.
	 * @param string $value Value.
	 * @param string $class CSS class.
	 * @param string $attrs Extra attributes (already escaped).
	 * @return string
	 */
	private static function text( $name, $id, $value, $class = 'regular-text', $attrs = '' ) {
		return '<input type="text" name="' . esc_attr( $name ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . ' class="' . esc_attr( $class ) . '" value="' . esc_attr( (string) $value ) . '"' . $attrs . '>';
	}

	/**
	 * Key/value lines ("key=value" per line).
	 *
	 * @param array<string,string> $pairs Pairs.
	 * @return string
	 */
	private static function pairs_text( array $pairs ) {
		$lines = array();
		foreach ( $pairs as $k => $v ) {
			$lines[] = $k . '=' . $v;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Key/value pairs as a query string ("key=value&key=value").
	 *
	 * @param array<string,string> $pairs Pairs.
	 * @return string
	 */
	private static function query_string( array $pairs ) {
		$parts = array();
		foreach ( $pairs as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}
		return implode( '&', $parts );
	}

	/**
	 * Sidebar: status, URL, actions, log.
	 *
	 * @param array<string,mixed> $feed    Feed.
	 * @param int                 $feed_id Feed ID.
	 */
	private static function sidebar( array $feed, $feed_id ) {
		echo '<div id="submitdiv" class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Feed', 'oli-product-feed' ) . '</h2></div><div class="inside"><div class="submitbox">';
		echo '<div id="misc-publishing-actions">';
		echo wp_kses( '<div class="misc-pub-section">' . self::checkbox( 'feed[active]', 'publish' === $feed['status'], __( 'Active (generated on schedule)', 'oli-product-feed' ) ) . '</div>', self::allowed_html() );
		if ( $feed_id ) {
			$last = Feeds::last_run( $feed_id );
			echo '<div class="misc-pub-section"><span class="dashicons dashicons-flag" aria-hidden="true"></span> ' . esc_html__( 'Status:', 'oli-product-feed' ) . ' ';
			echo wp_kses_post( Admin::status_badge( $feed_id ) );
			echo '</div>';
			if ( ! empty( $last['time'] ) ) {
				echo '<div class="misc-pub-section"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> ' . esc_html(
					sprintf(
						/* translators: %s: date and time. */
						__( 'Last generated: %s', 'oli-product-feed' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] )
					)
				) . '</div>';
			}
			$next = Scheduler::next_run( $feed, $last );
			echo '<div class="misc-pub-section"><span class="dashicons dashicons-clock" aria-hidden="true"></span> ' . esc_html(
				$next ? sprintf(
					/* translators: %s: date and time. */
					__( 'Next generation: %s', 'oli-product-feed' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next )
				) : __( 'Next generation: manual', 'oli-product-feed' )
			) . '</div>';
			if ( isset( $last['items'] ) ) {
				echo '<div class="misc-pub-section"><span class="dashicons dashicons-products" aria-hidden="true"></span> ' . esc_html(
					sprintf(
						/* translators: 1: items in the feed, 2: excluded products. */
						__( 'Items: %1$s (excluded: %2$s)', 'oli-product-feed' ),
						number_format_i18n( (int) $last['items'] ),
						number_format_i18n( (int) $last['candidates'] - (int) $last['items'] )
					)
				) . '</div>';
			}
			echo '<div class="misc-pub-section"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span> ' . esc_html__( 'Feed URL:', 'oli-product-feed' );
			Admin::feed_url_field( $feed_id, 'side' );
			echo '</div>';
		}
		echo '</div><div id="major-publishing-actions">';
		if ( $feed_id ) {
			$delete = wp_nonce_url( add_query_arg( array( 'action' => 'oliprfe_delete_feed', 'feed' => $feed_id ), admin_url( 'admin-post.php' ) ), 'oliprfe_delete_feed_' . $feed_id );
			echo '<div id="delete-action"><a class="submitdelete deletion oliprfe-confirm" href="' . esc_url( $delete ) . '" data-confirm="' . esc_attr__( 'Delete this feed and its file?', 'oli-product-feed' ) . '">' . esc_html__( 'Delete feed', 'oli-product-feed' ) . '</a></div>';
		}
		echo '<div id="publishing-action"><button type="submit" class="button" name="generate" value="1">' . esc_html__( 'Save and generate now', 'oli-product-feed' ) . '</button> ';
		echo '<button type="submit" class="button button-primary" name="save" value="1">' . esc_html__( 'Save feed', 'oli-product-feed' ) . '</button></div><div class="clear"></div></div>';
		echo '</div></div></div>';

		$channel = Registry::get( $feed['channel'] );
		if ( $feed_id && $channel && '' !== $channel->help() ) {
			echo '<div class="postbox oliprfe-help"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Submit this feed', 'oli-product-feed' ) . '</h2></div><div class="inside"><p>' . esc_html( $channel->help() ) . '</p>';
			echo '<p class="description">' . esc_html__( 'Use the same frequency (or less) on the platform as the regeneration of the feed.', 'oli-product-feed' ) . '</p></div></div>';
		}

		if ( $feed_id ) {
			$log = get_post_meta( $feed_id, Feeds::META_LOG, true );
			if ( is_array( $log ) && $log ) {
				echo '<div class="postbox"><div class="postbox-header"><h2 class="hndle">' . esc_html__( 'Last runs', 'oli-product-feed' ) . '</h2></div><div class="inside">';
				foreach ( array_slice( $log, 0, 5 ) as $entry ) {
					echo '<p><strong>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</strong><br>';
					if ( 'success' === $entry['status'] ) {
						echo esc_html(
							sprintf(
								/* translators: 1: items, 2: seconds. */
								__( '%1$s items in %2$s s', 'oli-product-feed' ),
								number_format_i18n( (int) $entry['items'] ),
								(string) $entry['duration']
							)
						);
						foreach ( (array) $entry['excluded'] as $reason => $info ) {
							echo '<br><span class="oliprfe-meta">' . esc_html( self::reason_label( $reason ) . ' : ' . (int) $info['count'] ) . ' <span class="code">(' . esc_html( implode( ', ', array_slice( (array) $info['ids'], 0, 10 ) ) ) . ')</span></span>';
						}
						foreach ( (array) $entry['warnings'] as $code => $ids ) {
							echo '<br><span class="oliprfe-meta">' . esc_html( self::reason_label( $code ) . ' : ' . count( (array) $ids ) ) . ' <span class="code">(' . esc_html( implode( ', ', array_slice( (array) $ids, 0, 10 ) ) ) . ')</span></span>';
						}
					} else {
						echo '<span class="oliprfe-error-text">' . esc_html( isset( $entry['message'] ) ? $entry['message'] : __( 'Error', 'oli-product-feed' ) ) . '</span>';
					}
					echo '</p>';
				}
				echo '</div></div>';
			}
		}
	}

	/**
	 * Human label of an exclusion reason or warning code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function reason_label( $code ) {
		$labels = array(
			'invalid_product'      => __( 'Invalid product', 'oli-product-feed' ),
			'parent_not_published' => __( 'Parent not published', 'oli-product-feed' ),
			'password_protected'   => __( 'Password protected', 'oli-product-feed' ),
			'stock_status'         => __( 'Stock status filtered', 'oli-product-feed' ),
			'title_filter'         => __( 'Title filter', 'oli-product-feed' ),
			'filtered'             => __( 'Excluded by a filter', 'oli-product-feed' ),
			'no_price'             => __( 'No price', 'oli-product-feed' ),
			'price_range'          => __( 'Outside the price range', 'oli-product-feed' ),
			'min_price'            => __( 'Below the minimum price', 'oli-product-feed' ),
			'invalid_gtin'         => __( 'Invalid GTIN (not sent)', 'oli-product-feed' ),
			'no_brand'             => __( 'No brand', 'oli-product-feed' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		if ( 0 === strpos( $code, 'missing:' ) ) {
			/* translators: %s: field names. */
			return sprintf( __( 'Missing required field: %s', 'oli-product-feed' ), substr( $code, 8 ) );
		}
		if ( 0 === strpos( $code, 'rule:' ) ) {
			/* translators: %s: meta key. */
			return sprintf( __( 'Exclusion rule: %s', 'oli-product-feed' ), substr( $code, 5 ) );
		}
		if ( 0 === strpos( $code, 'empty:' ) ) {
			/* translators: %s: field name. */
			return sprintf( __( 'Empty field: %s', 'oli-product-feed' ), substr( $code, 6 ) );
		}
		return $code;
	}

	/**
	 * Channel and target box.
	 *
	 * @param array<string,mixed>  $feed   Feed.
	 * @param array<string,string> $errors Errors.
	 */
	private static function box_target( array $feed, array $errors ) {
		self::open_box( 'oliprfe-general', __( 'Channel and target', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		$radios = '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Channel', 'oli-product-feed' ) . '</legend>';
		foreach ( Registry::all() as $id => $channel ) {
			$radios .= '<p><label><input type="radio" name="feed[channel]" value="' . esc_attr( $id ) . '" data-formats="' . esc_attr( implode( ',', $channel->formats() ) ) . '"' . checked( $feed['channel'], $id, false ) . '> ' . esc_html( $channel->label() ) . '</label><br><span class="description">' . esc_html( strtoupper( implode( ', ', $channel->formats() ) ) ) . '</span></p>';
		}
		$radios .= '</fieldset>';
		self::row( __( 'Channel', 'oli-product-feed' ), '', $radios, __( 'Changing the channel resets the attribute list below after saving.', 'oli-product-feed' ) );

		$formats = array(
			'xml' => 'XML',
			'csv' => 'CSV',
			'tsv' => 'TSV',
			'txt' => __( 'TXT (tab-delimited)', 'oli-product-feed' ),
		);
		self::row( __( 'Format', 'oli-product-feed' ), 'oliprfe-format', self::select( 'feed[format]', 'oliprfe-format', $formats, $feed['format'] ), __( 'Only the formats of the selected channel are available.', 'oli-product-feed' ) );

		// Generic channel: free columns and XML element names (kept for the other channels, hidden).
		$columns = array();
		foreach ( $feed['columns'] as $column ) {
			$columns[] = $column['name'] === $column['field'] ? $column['field'] : $column['name'] . ' = ' . $column['field'];
		}
		$generic = 'generic' === $feed['channel'] ? '' : ' hidden';
		echo '<tr class="oliprfe-for-generic"' . esc_attr( $generic ) . '><th scope="row"><label for="oliprfe-columns">' . esc_html__( 'Columns', 'oli-product-feed' ) . '</label></th><td>';
		echo '<textarea name="feed[columns]" id="oliprfe-columns" rows="6" class="large-text code" placeholder="' . esc_attr( "sku = sku\nname = title\nurl = link\nprice = price\ncurrency = currency" ) . '">' . esc_textarea( implode( "\n", $columns ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One column per line, in file order: column name = field (a field of the attribute mapping below, or any attribute you add there). Empty: every field under its own name.', 'oli-product-feed' ) . '</p></td></tr>';
		echo '<tr class="oliprfe-for-generic"' . esc_attr( $generic ) . '><th scope="row"><label for="oliprfe-xml_root">' . esc_html__( 'XML elements', 'oli-product-feed' ) . '</label></th><td>';
		echo wp_kses( self::text( 'feed[xml_root]', 'oliprfe-xml_root', $feed['xml_root'], 'code' ) . ' ' . self::text( 'feed[xml_item]', 'oliprfe-xml_item', $feed['xml_item'], 'code', ' aria-label="' . esc_attr__( 'Product element', 'oli-product-feed' ) . '"' ), self::allowed_html() );
		echo '<p class="description">' . esc_html__( 'Root element and product element of the XML file (for example products and product).', 'oli-product-feed' ) . '</p></td></tr>';
		self::row( __( 'File name', 'oli-product-feed' ), 'oliprfe-file_slug', self::text( 'feed[file_slug]', 'oliprfe-file_slug', $feed['file_slug'], 'regular-text code', self::invalid( $errors, 'file_slug' ) ), __( 'Letters, digits and dashes. The extension is added. Keep it stable: the platforms fetch this URL.', 'oli-product-feed' ) );
		self::error( $errors, 'file_slug' );

		$languages = Lang::languages();
		if ( $languages ) {
			self::row( __( 'Language', 'oli-product-feed' ), 'oliprfe-language', self::select( 'feed[language]', 'oliprfe-language', $languages, $feed['language'] ? $feed['language'] : Lang::default_language() ), __( 'Titles, descriptions, categories and product URLs are exported in this language (TranslatePress). The feed language is applied for the whole generation (locale and translated permalinks).', 'oli-product-feed' ) );
		} else {
			echo '<input type="hidden" name="feed[language]" value="">';
		}

		$countries = array( '' => __( '— Any country —', 'oli-product-feed' ) ) + WC()->countries->get_countries();
		self::row( __( 'Country', 'oli-product-feed' ), 'oliprfe-country', self::select( 'feed[country]', 'oliprfe-country', $countries, $feed['country'] ), __( 'Target country of the feed. Used by the price source.', 'oli-product-feed' ) );
		$currencies = get_woocommerce_currencies();
		foreach ( $currencies as $code => $name ) {
			$currencies[ $code ] = $code . ' — ' . $name;
		}
		self::row( __( 'Currency', 'oli-product-feed' ), 'oliprfe-currency', self::select( 'feed[currency]', 'oliprfe-currency', $currencies, $feed['currency'] ), __( 'Prices are written in this currency. Use a price source that returns prices in this currency.', 'oli-product-feed' ) );

		$html = self::select( 'feed[price_source]', 'oliprfe-price_source', Pricing::sources(), $feed['price_source'] );
		if ( PriceBasedOnCountry::available() ) {
			$opts  = $feed['price_source_options'];
			$html .= '<p>' . esc_html__( 'Pricing zone ID (empty: zone of the country):', 'oli-product-feed' ) . ' ' . self::text( 'feed[price_source_options][zone]', 'oliprfe-zone', isset( $opts['zone'] ) ? $opts['zone'] : '', 'regular-text code' ) . '</p>';
			$html .= '<p>' . self::checkbox( 'feed[price_source_options][country_param]', ! empty( $opts['country_param'] ), __( 'Add ?wcpbc-manual-country=<country> to the product links', 'oli-product-feed' ) ) . '</p>';
		}
		self::row( __( 'Price source', 'oli-product-feed' ), 'oliprfe-price_source', $html );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Category checklist.
	 *
	 * @param string $name     Field name.
	 * @param int[]  $selected Selected term IDs.
	 * @param string $label    Accessible label.
	 * @return string
	 */
	private static function category_checklist( $name, array $selected, $label ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '<p>' . esc_html__( 'No product categories.', 'oli-product-feed' ) . '</p>';
		}
		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}
		$walk = static function ( $parent ) use ( &$walk, $by_parent, $name, $selected ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return '';
			}
			$html = '';
			foreach ( $by_parent[ $parent ] as $term ) {
				$html .= '<li><label class="selectit"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( (string) $term->term_id ) . '"' . checked( in_array( (int) $term->term_id, $selected, true ), true, false ) . '> ' . esc_html( html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . '</label>';
				$children = $walk( (int) $term->term_id );
				$html    .= '' !== $children ? '<ul class="children">' . $children . '</ul>' : '';
				$html    .= '</li>';
			}
			return $html;
		};
		return '<div class="categorydiv oliprfe-checklist"><div class="tabs-panel" role="group" aria-label="' . esc_attr( $label ) . '"><ul class="categorychecklist form-no-clear">' . $walk( 0 ) . '</ul></div></div>';
	}

	/**
	 * Rule rows (meta = value [→ availability]).
	 *
	 * @param string                           $name  Field name.
	 * @param array<int,array<string,string>> $rules Rules.
	 * @param bool                             $with_availability Add an availability column.
	 * @return string
	 */
	private static function rule_rows( $name, array $rules, $with_availability ) {
		$availabilities = array(
			'in_stock'     => __( 'In stock', 'oli-product-feed' ),
			'out_of_stock' => __( 'Out of stock', 'oli-product-feed' ),
			'backorder'    => __( 'Backorder', 'oli-product-feed' ),
			'preorder'     => __( 'Preorder', 'oli-product-feed' ),
		);
		$rules[] = array();
		$rules[] = array();
		$html    = '<table class="widefat oliprfe-rules"><thead><tr><th scope="col">' . esc_html__( 'Meta key', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Value (empty: any value)', 'oli-product-feed' ) . '</th>';
		$html   .= $with_availability ? '<th scope="col">' . esc_html__( 'Availability', 'oli-product-feed' ) . '</th>' : '';
		$html   .= '</tr></thead><tbody>';
		foreach ( array_values( $rules ) as $i => $rule ) {
			$html .= '<tr><td>' . self::text( $name . '[' . $i . '][meta]', '', isset( $rule['meta'] ) ? $rule['meta'] : '', 'code', ' aria-label="' . esc_attr__( 'Meta key', 'oli-product-feed' ) . '"' ) . '</td>';
			$html .= '<td>' . self::text( $name . '[' . $i . '][value]', '', isset( $rule['value'] ) ? $rule['value'] : '', '', ' aria-label="' . esc_attr__( 'Value', 'oli-product-feed' ) . '"' ) . '</td>';
			if ( $with_availability ) {
				$html .= '<td>' . self::select( $name . '[' . $i . '][availability]', '', $availabilities, isset( $rule['availability'] ) ? $rule['availability'] : 'out_of_stock', ' aria-label="' . esc_attr__( 'Availability', 'oli-product-feed' ) . '"' ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Product filters box.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function box_filters( array $feed ) {
		$f = $feed['filters'];
		self::open_box( 'oliprfe-filters', __( 'Products', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'Include categories', 'oli-product-feed' ), '', self::category_checklist( 'feed[filters][categories_include]', $f['categories_include'], __( 'Include categories', 'oli-product-feed' ) ), __( 'None checked: all categories. Subcategories are included.', 'oli-product-feed' ) );
		self::row( __( 'Exclude categories', 'oli-product-feed' ), '', self::category_checklist( 'feed[filters][categories_exclude]', $f['categories_exclude'], __( 'Exclude categories', 'oli-product-feed' ) ) );
		$stock = '<fieldset class="oliprfe-inline-radios"><legend class="screen-reader-text">' . esc_html__( 'Stock status', 'oli-product-feed' ) . '</legend>';
		foreach ( wc_get_product_stock_status_options() as $status => $label ) {
			$stock .= self::checkbox( 'feed[filters][stock_statuses][]', in_array( $status, $f['stock_statuses'], true ), $label, $status );
		}
		$stock .= '</fieldset>';
		self::row( __( 'Stock status', 'oli-product-feed' ), '', $stock );
		self::row( __( 'Tags', 'oli-product-feed' ), '', '<p><label for="oliprfe-tags_include">' . esc_html__( 'Only products with these tags', 'oli-product-feed' ) . '</label><br>' . self::text( 'feed[filters][tags_include]', 'oliprfe-tags_include', implode( ', ', $f['tags_include'] ), 'regular-text code' ) . '</p><p><label for="oliprfe-tags_exclude">' . esc_html__( 'Exclude products with these tags', 'oli-product-feed' ) . '</label><br>' . self::text( 'feed[filters][tags_exclude]', 'oliprfe-tags_exclude', implode( ', ', $f['tags_exclude'] ), 'regular-text code' ) . '</p>', __( 'Tag slugs, separated by commas. Empty: no tag filter.', 'oli-product-feed' ) );
		$range  = '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Price range', 'oli-product-feed' ) . '</legend>';
		$range .= '<label for="oliprfe-price_min">' . esc_html__( 'Min', 'oli-product-feed' ) . '</label> <input type="number" min="0" step="0.01" class="small-text" id="oliprfe-price_min" name="feed[filters][price_min]" value="' . esc_attr( $f['price_min'] ) . '"> ';
		$range .= '<label for="oliprfe-price_max">' . esc_html__( 'Max', 'oli-product-feed' ) . '</label> <input type="number" min="0" step="0.01" class="small-text" id="oliprfe-price_max" name="feed[filters][price_max]" value="' . esc_attr( $f['price_max'] ) . '"> ' . esc_html( $feed['currency'] ) . '</fieldset>';
		self::row( __( 'Price range', 'oli-product-feed' ), '', $range, __( 'Empty: no limit. Uses the price shown to customers (sale price included).', 'oli-product-feed' ) );
		$min_price  = '<input type="number" min="0" step="0.01" class="small-text" id="oliprfe-min_price" name="feed[min_price]" value="' . esc_attr( isset( $feed['min_price'] ) ? (string) $feed['min_price'] : '' ) . '"> ';
		$min_price .= esc_html( $feed['currency'] );
		self::row(
			__( 'Minimum price', 'oli-product-feed' ),
			'oliprfe-min_price',
			$min_price,
			__( 'Simple products and variations whose displayed price in this feed currency is below this amount are excluded. Empty or 0: off. The displayed price is the sale price when a promotion is active, otherwise the regular price — the same amount written in g:price / g:sale_price (including Price Based on Country or converted zone prices). Enter the threshold in the feed currency (150 for a 150 USD or 150 CAD feed); it is not converted.', 'oli-product-feed' )
		);
		self::row( __( 'Variations', 'oli-product-feed' ), '', self::checkbox( 'feed[variations]', $feed['variations'], __( 'Export each variation as its own item (linked by item_group_id); the variable parent is not exported', 'oli-product-feed' ) ) );
		self::row( __( 'Exclude product IDs', 'oli-product-feed' ), 'oliprfe-exclude_ids', self::text( 'feed[filters][exclude_ids]', 'oliprfe-exclude_ids', implode( ', ', $f['exclude_ids'] ), 'regular-text code' ), __( 'Product or variation IDs, separated by commas.', 'oli-product-feed' ) );
		self::row( __( 'Title contains', 'oli-product-feed' ), 'oliprfe-title_contains', self::text( 'feed[filters][title_contains]', 'oliprfe-title_contains', $f['title_contains'] ) );
		self::row( __( 'Title does not contain', 'oli-product-feed' ), 'oliprfe-title_not_contains', self::text( 'feed[filters][title_not_contains]', 'oliprfe-title_not_contains', $f['title_not_contains'] ) );
		self::row( __( 'Exclude when empty', 'oli-product-feed' ), 'oliprfe-require_fields', self::text( 'feed[filters][require_fields]', 'oliprfe-require_fields', implode( ', ', $f['require_fields'] ), 'regular-text code' ), __( 'Feed attributes, separated by commas (for example gtin, brand). Products with an empty value are excluded.', 'oli-product-feed' ) );
		self::row( __( 'Exclusion rules', 'oli-product-feed' ), '', self::rule_rows( 'feed[exclude_rules]', $feed['exclude_rules'], false ), __( 'Exclude a product when its meta (or its parent\'s) has this value.', 'oli-product-feed' ) );
		self::row( __( 'Availability rules', 'oli-product-feed' ), '', self::rule_rows( 'feed[availability_rules]', $feed['availability_rules'], true ), __( 'Force an availability when a meta (or the parent\'s) has this value. The first matching rule wins.', 'oli-product-feed' ) );
		self::row(
			__( 'Backorder lead time', 'oli-product-feed' ),
			'',
			self::checkbox( 'feed[backorder_lead_enabled]', ! empty( $feed['backorder_lead_enabled'] ), __( 'Set an availability date for products on backorder and for out-of-stock products included in this feed', 'oli-product-feed' ) ),
			__( 'Google, Microsoft (Bing) and Meta. In-stock items are unchanged. A per-product override (days or a date) can be set on the product edit screen.', 'oli-product-feed' )
		);
		$lead  = '<input type="number" min="1" max="3650" class="small-text" id="oliprfe-backorder_lead_days" name="feed[backorder_lead_days]" value="' . esc_attr( (string) ( isset( $feed['backorder_lead_days'] ) ? $feed['backorder_lead_days'] : 21 ) ) . '"> ';
		$lead .= esc_html__( 'days from the generation date (default 21, about 2–3 weeks)', 'oli-product-feed' );
		$hide_lead = empty( $feed['backorder_lead_enabled'] );
		self::row( __( 'Lead time (days)', 'oli-product-feed' ), 'oliprfe-backorder_lead_days', $lead, __( 'Written as g:availability_date in ISO 8601 with the site time zone, recalculated at each generation.', 'oli-product-feed' ), 'oliprfe-for-backorder', $hide_lead );
		self::row(
			__( 'Send as preorder', 'oli-product-feed' ),
			'',
			self::checkbox( 'feed[backorder_as_preorder]', ! empty( $feed['backorder_as_preorder'] ), __( 'Use preorder instead of backorder (Google spec). Microsoft already maps backorders to preorder.', 'oli-product-feed' ) ),
			'',
			'oliprfe-for-backorder',
			$hide_lead
		);
		$handling  = '<label for="oliprfe-backorder_min_handling_time">' . esc_html__( 'Min', 'oli-product-feed' ) . '</label> ';
		$handling .= '<input type="number" min="0" max="365" class="small-text" id="oliprfe-backorder_min_handling_time" name="feed[backorder_min_handling_time]" value="' . esc_attr( (string) ( isset( $feed['backorder_min_handling_time'] ) ? $feed['backorder_min_handling_time'] : '' ) ) . '"> ';
		$handling .= '<label for="oliprfe-backorder_max_handling_time">' . esc_html__( 'Max', 'oli-product-feed' ) . '</label> ';
		$handling .= '<input type="number" min="0" max="365" class="small-text" id="oliprfe-backorder_max_handling_time" name="feed[backorder_max_handling_time]" value="' . esc_attr( (string) ( isset( $feed['backorder_max_handling_time'] ) ? $feed['backorder_max_handling_time'] : '' ) ) . '"> ';
		$handling .= esc_html__( 'days', 'oli-product-feed' );
		self::row( __( 'Handling time (backorders)', 'oli-product-feed' ), 'oliprfe-backorder_min_handling_time', $handling, __( 'Optional g:min_handling_time and g:max_handling_time for these items (Google and Microsoft). Empty: not sent.', 'oli-product-feed' ), 'oliprfe-for-backorder', $hide_lead );
		echo '</tbody></table>';
		echo '<p class="oliprfe-match"><span class="oliprfe-match-text" aria-live="polite">' . esc_html__( 'Count the products that match the category, tag, stock and ID filters (before the other rules).', 'oli-product-feed' ) . '</span> <button type="button" class="button oliprfe-match-refresh">' . esc_html__( 'Refresh count', 'oli-product-feed' ) . '</button></p>';
		self::close_box();
	}

	/**
	 * Titles and descriptions box.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function box_texts( array $feed ) {
		self::open_box( 'oliprfe-texts', __( 'Titles and descriptions', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			__( 'Title template', 'oli-product-feed' ),
			'oliprfe-title_template',
			self::text( 'feed[title_template]', 'oliprfe-title_template', $feed['title_template'], 'large-text code', ' placeholder="{brand} {name} {type} - {variation}"' ),
			__( 'Empty: product name (+ variation values). Tokens: {title}, {name}, {variation}, {brand}, {type}, {category}, {sku}, {attr:pa_color}, {meta:key}. {brand} and {type} are skipped when the name already contains them.', 'oli-product-feed' )
		);
		self::row( __( 'Brand in title', 'oli-product-feed' ), '', self::checkbox( 'feed[title_brand_prefix]', $feed['title_brand_prefix'], __( 'Without a template: put the brand first when the title does not contain it', 'oli-product-feed' ) ) );
		$type_rules   = $feed['title_type_rules'];
		$type_rules[] = array();
		$html         = '<table class="widefat oliprfe-rules"><thead><tr><th scope="col">' . esc_html__( 'Name pattern', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Type', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Product category IDs', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( array_values( $type_rules ) as $i => $rule ) {
			$base  = 'feed[title_type_rules][' . $i . ']';
			$html .= '<tr><td>' . self::text( $base . '[pattern]', '', isset( $rule['pattern'] ) ? $rule['pattern'] : '', 'code', ' aria-label="' . esc_attr__( 'Name pattern', 'oli-product-feed' ) . '"' ) . '</td>';
			$html .= '<td>' . self::text( $base . '[label]', '', isset( $rule['label'] ) ? $rule['label'] : '', '', ' aria-label="' . esc_attr__( 'Type', 'oli-product-feed' ) . '"' ) . '</td>';
			$html .= '<td>' . self::text( $base . '[categories]', '', implode( ', ', isset( $rule['categories'] ) ? (array) $rule['categories'] : array() ), 'code', ' aria-label="' . esc_attr__( 'Product category IDs', 'oli-product-feed' ) . '"' ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		self::row( __( 'Type by name', 'oli-product-feed' ), '', $html, __( 'Before the category table: the first rule whose pattern (regular expression) matches the product name gives the {type}, for products of these categories (empty: all).', 'oli-product-feed' ) );
		self::row( __( 'Brand spelling', 'oli-product-feed' ), '', self::checkbox( 'feed[title_brand_case]', $feed['title_brand_case'], __( 'Write brand names as their brand term ("TV JONES" becomes "TV Jones")', 'oli-product-feed' ) ) );
		self::row( __( 'Remove from variation values', 'oli-product-feed' ), 'oliprfe-title_strip_patterns', '<textarea name="feed[title_strip_patterns]" id="oliprfe-title_strip_patterns" rows="2" class="large-text code">' . esc_textarea( implode( "\n", $feed['title_strip_patterns'] ) ) . '</textarea>', __( 'Regular expressions, one per line, case-insensitive (for example ^\d{6}\s*[-–]\s* removes a SKU prefix such as "023936 - ").', 'oli-product-feed' ) );
		$keys = array();
		foreach ( $feed['description_fallbacks'] as $spec ) {
			if ( 'meta' === $spec['type'] ) {
				$keys[] = $spec['key'];
			}
		}
		self::row( __( 'Short descriptions', 'oli-product-feed' ), 'oliprfe-description_min_length', '<input type="number" min="0" max="5000" class="small-text" id="oliprfe-description_min_length" name="feed[description_min_length]" value="' . esc_attr( (string) $feed['description_min_length'] ) . '"> ' . esc_html__( 'characters minimum (0: off)', 'oli-product-feed' ) );
		self::row( __( 'Fallback meta keys', 'oli-product-feed' ), 'oliprfe-description_fallbacks', self::text( 'feed[description_fallbacks]', 'oliprfe-description_fallbacks', implode( ', ', $keys ), 'regular-text code', ' list="oliprfe-meta-keys"' ), __( 'Used in this order when the description is shorter than the minimum (for example rank_math_description). Translated in the feed language.', 'oli-product-feed' ) );
		self::row( __( 'Attributes', 'oli-product-feed' ), '', self::checkbox( 'feed[description_attributes]', $feed['description_attributes'], __( 'Then add the visible product attributes as short sentences ("Top: Solid spruce.")', 'oli-product-feed' ) ) );
		self::row( __( 'Remove from descriptions', 'oli-product-feed' ), 'oliprfe-description_remove', '<textarea name="feed[description_remove]" id="oliprfe-description_remove" rows="3" class="large-text code">' . esc_textarea( implode( "\n", $feed['description_remove'] ) ) . '</textarea>', __( 'Regular expressions, one per line, case-insensitive. Each match is removed (for example a call to action of the meta description).', 'oli-product-feed' ) );
		self::row( __( 'Email addresses', 'oli-product-feed' ), '', self::checkbox( 'feed[description_remove_emails]', $feed['description_remove_emails'], __( 'Remove the sentences that contain an email address', 'oli-product-feed' ) ) );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Attribute mapping box.
	 *
	 * @param array<string,mixed> $feed    Feed.
	 * @param Channel             $channel Channel.
	 */
	private static function box_mapping( array $feed, Channel $channel ) {
		$defaults = $channel->default_mapping();
		$auto     = self::automatic_fields();
		self::open_box( 'oliprfe-mapping', __( 'Attribute mapping', 'oli-product-feed' ) );
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: channel name. */
				__( 'Choose where each %s attribute comes from. "Automatic" uses the plugin rule shown under the attribute. Text values are taken in the feed language.', 'oli-product-feed' ),
				$channel->label()
			)
		) . '</p>';
		echo '<table class="widefat striped oliprfe-mapping"><caption class="screen-reader-text">' . esc_html__( 'Attribute mapping', 'oli-product-feed' ) . '</caption>';
		self::$preview = ! empty( $feed['id'] ) ? Generator::preview( $feed ) : null;
		$preview_head  = self::$preview
			? sprintf(
				/* translators: %s: product ID. */
				__( 'Preview (#%s)', 'oli-product-feed' ),
				self::$preview['id']
			)
			: __( 'Preview', 'oli-product-feed' );
		echo '<thead><tr><th scope="col" class="oliprfe-col-attr">' . esc_html__( 'Attribute', 'oli-product-feed' ) . '</th><th scope="col" class="oliprfe-col-type">' . esc_html__( 'Source type', 'oli-product-feed' ) . '</th><th scope="col" class="oliprfe-col-src">' . esc_html__( 'Source', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Options', 'oli-product-feed' ) . '</th><th scope="col" class="oliprfe-col-preview">' . esc_html( $preview_head ) . '</th></tr></thead>';

		$groups = array(
			Channel::REQUIRED    => __( 'Required', 'oli-product-feed' ),
			Channel::RECOMMENDED => __( 'Recommended', 'oli-product-feed' ),
			Channel::OPTIONAL    => __( 'Optional', 'oli-product-feed' ),
		);
		$fields = $channel->fields();
		foreach ( $groups as $level => $label ) {
			echo '<tbody><tr><th scope="rowgroup" colspan="5">' . esc_html( $label ) . '</th></tr>';
			foreach ( $fields as $field => $def ) {
				if ( $level !== $def['level'] ) {
					continue;
				}
				$hint = isset( $auto[ $field ] ) ? $auto[ $field ] : ( isset( $defaults[ $field ] ) ? self::spec_label( $defaults[ $field ] ) : __( 'Not sent', 'oli-product-feed' ) );
				self::mapping_row( $field, $def['label'], isset( $feed['mapping'][ $field ] ) ? $feed['mapping'][ $field ] : null, $hint, false );
			}
			echo '</tbody>';
		}
		echo '<tbody class="oliprfe-custom-rows"><tr><th scope="rowgroup" colspan="5">' . esc_html__( 'Custom', 'oli-product-feed' ) . '</th></tr>';
		foreach ( $feed['mapping'] as $field => $spec ) {
			if ( ! isset( $fields[ $field ] ) ) {
				self::mapping_row( $field, '', $spec, '', true );
			}
		}
		echo '</tbody></table>';
		echo '<template id="oliprfe-row-template">';
		self::mapping_row( '__FIELD__', '', array( 'type' => 'static' ), '', true );
		echo '</template>';
		echo '<p><label for="oliprfe-add-attr">' . esc_html__( 'Add an attribute (name as expected by the platform):', 'oli-product-feed' ) . '</label> ';
		echo '<input type="text" id="oliprfe-add-attr" class="code" pattern="[a-z0-9_]+" placeholder="custom_label_2"> <button type="button" class="button oliprfe-add-row">' . esc_html__( 'Add attribute', 'oli-product-feed' ) . '</button></p>';
		self::meta_datalist();

		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'MPN only for these brands', 'oli-product-feed' ), 'oliprfe-mpn_brands', '<textarea name="feed[mpn_brands]" id="oliprfe-mpn_brands" rows="3" class="large-text code">' . esc_textarea( implode( "\n", $feed['mpn_brands'] ) ) . '</textarea>', __( 'One brand per line. Empty: the MPN is sent for every brand. Useful when the SKU is the manufacturer part number only for your own brands.', 'oli-product-feed' ) );
		self::row( __( 'MPN and GTIN', 'oli-product-feed' ), '', self::checkbox( 'feed[mpn_only_without_gtin]', $feed['mpn_only_without_gtin'], __( 'Send the MPN only when the product has no valid GTIN', 'oli-product-feed' ) ) );
		self::row( __( 'Priority brands', 'oli-product-feed' ), 'oliprfe-brand_priority', '<textarea name="feed[brand_priority]" id="oliprfe-brand_priority" rows="2" class="large-text code">' . esc_textarea( implode( "\n", $feed['brand_priority'] ) ) . '</textarea>', __( 'Products with several brand terms: a brand of this list wins (first listed first). One brand per line.', 'oli-product-feed' ) );
		self::row( __( 'Several brands', 'oli-product-feed' ), '', self::checkbox( 'feed[brand_from_title]', $feed['brand_from_title'], __( 'Otherwise use the brand that opens the product name, else the fallback of the brand source', 'oli-product-feed' ) ) );
		echo '</tbody></table>';
		self::field_rules( $feed['field_rules'] );
		$overrides = array();
		foreach ( $feed['item_overrides'] as $override ) {
			$overrides[] = $override['product'] . ' ' . $override['field'] . '=' . $override['value'];
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'Per-product values', 'oli-product-feed' ), 'oliprfe-item_overrides', '<textarea name="feed[item_overrides]" id="oliprfe-item_overrides" rows="3" class="large-text code" placeholder="1234 color=Black">' . esc_textarea( implode( "\n", $overrides ) ) . '</textarea>', __( 'One value per line: product_id attribute=value (product or parent variable product ID). Applied after the field rules; replaces the computed value.', 'oli-product-feed' ) );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Field rules table (values by category, e.g. apparel color, age_group, gender).
	 *
	 * @param array<int,array<string,mixed>> $rules Rules.
	 */
	private static function field_rules( array $rules ) {
		$rules[] = array();
		$rules[] = array();
		echo '<h3>' . esc_html__( 'Field rules', 'oli-product-feed' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Fill an empty attribute for some categories: a fixed value, or the label of the first word found in the product name (word=label; …). Inactive rules are kept but not applied.', 'oli-product-feed' ) . '</p>';
		echo '<table class="widefat oliprfe-rules"><thead><tr><th scope="col">' . esc_html__( 'Attribute', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Value', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Words in the name (word=label; …)', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Google category IDs', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Product category IDs', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Active', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		foreach ( array_values( $rules ) as $i => $rule ) {
			$base  = 'feed[field_rules][' . $i . ']';
			$words = array();
			foreach ( isset( $rule['words'] ) ? (array) $rule['words'] : array() as $needle => $label ) {
				$words[] = $needle . '=' . $label;
			}
			echo wp_kses( '<tr><td>' . self::text( $base . '[field]', '', isset( $rule['field'] ) ? $rule['field'] : '', 'code', ' aria-label="' . esc_attr__( 'Attribute', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo wp_kses( '<td>' . self::text( $base . '[value]', '', isset( $rule['value'] ) ? $rule['value'] : '', '', ' aria-label="' . esc_attr__( 'Value', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo wp_kses( '<td>' . self::text( $base . '[words]', '', implode( '; ', $words ), '', ' aria-label="' . esc_attr__( 'Words in the name', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo wp_kses( '<td>' . self::text( $base . '[google_categories]', '', implode( ', ', isset( $rule['google_categories'] ) ? (array) $rule['google_categories'] : array() ), 'code', ' aria-label="' . esc_attr__( 'Google category IDs', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo wp_kses( '<td>' . self::text( $base . '[categories]', '', implode( ', ', isset( $rule['categories'] ) ? (array) $rule['categories'] : array() ), 'code', ' aria-label="' . esc_attr__( 'Product category IDs', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo '<td><input type="checkbox" name="' . esc_attr( $base . '[active]' ) . '" value="1"' . checked( ! empty( $rule['active'] ), true, false ) . ' aria-label="' . esc_attr__( 'Active', 'oli-product-feed' ) . '"></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Human description of a source spec.
	 *
	 * @param array<string,mixed> $spec Spec.
	 * @return string
	 */
	private static function spec_label( array $spec ) {
		$types = self::source_types();
		$key   = isset( $spec['key'] ) ? $spec['key'] : '';
		if ( 'field' === $spec['type'] ) {
			$fields = Sources::fields();
			$key    = isset( $fields[ $key ] ) ? $fields[ $key ] : $key;
		}
		return $types[ $spec['type'] ] . ( '' !== $key ? ': ' . $key : '' );
	}

	/**
	 * One mapping row.
	 *
	 * @param string                   $field     Attribute name.
	 * @param string                   $label     Attribute label.
	 * @param array<string,mixed>|null $spec      Current spec (null = automatic).
	 * @param string                   $hint      Automatic rule.
	 * @param bool                     $removable Custom row.
	 */
	private static function mapping_row( $field, $label, $spec, $hint, $removable ) {
		$spec = $spec ? wp_parse_args(
			$spec,
			array(
				'type'      => 'auto',
				'key'       => '',
				'value'     => '',
				'mode'      => 'first',
				'parent'    => false,
				'prefix'    => '',
				'suffix'    => '',
				'translate' => false,
				'fallback'  => '',
				'map'       => array(),
				'single'    => false,
			)
		) : array(
			'type'      => 'auto',
			'key'       => '',
			'value'     => '',
			'mode'      => 'first',
			'parent'    => false,
			'prefix'    => '',
			'suffix'    => '',
			'translate' => false,
			'fallback'  => '',
			'map'       => array(),
			'single'    => false,
		);
		$base  = 'feed[mapping][' . $field . ']';
		$rid   = 'oliprfe-map-' . $field;
		$type  = $spec['type'];
		$types = self::source_types();
		if ( $removable ) {
			unset( $types['auto'] );
		}

		echo '<tr><th scope="row"><code>' . esc_html( $field ) . '</code>';
		if ( '' !== $label ) {
			echo '<span class="oliprfe-attr-label">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== $hint ) {
			echo '<span class="oliprfe-attr-label">' . esc_html(
				sprintf(
					/* translators: %s: automatic rule. */
					__( 'Automatic: %s', 'oli-product-feed' ),
					$hint
				)
			) . '</span>';
		}
		if ( $removable ) {
			echo '<button type="button" class="button-link button-link-delete oliprfe-remove-row">' . esc_html__( 'Remove', 'oli-product-feed' ) . '<span class="screen-reader-text"> ' . esc_html( $field ) . '</span></button>';
		}
		echo wp_kses( '</th><td>' . self::select( $base . '[type]', $rid . '-type', $types, $type, ' class="oliprfe-src-type" aria-label="' . esc_attr__( 'Source type', 'oli-product-feed' ) . '"' ) . '</td><td>', self::allowed_html() );

		$hidden = static function ( $kind ) use ( $type ) {
			return $kind === $type ? '' : ' hidden disabled';
		};
		echo wp_kses( self::select( $base . '[key]', '', Sources::fields(), 'field' === $type ? $spec['key'] : '', ' data-src="field" aria-label="' . esc_attr__( 'Product field', 'oli-product-feed' ) . '"' . $hidden( 'field' ) ), self::allowed_html() );
		echo wp_kses( self::text( $base . '[key]', '', 'attribute' === $type ? $spec['key'] : '', 'code', ' list="oliprfe-attribute-keys" data-src="attribute" aria-label="' . esc_attr__( 'Attribute name', 'oli-product-feed' ) . '"' . $hidden( 'attribute' ) ), self::allowed_html() );
		echo wp_kses( self::select( $base . '[key]', '', self::taxonomies(), 'taxonomy' === $type ? $spec['key'] : '', ' data-src="taxonomy" aria-label="' . esc_attr__( 'Taxonomy', 'oli-product-feed' ) . '"' . $hidden( 'taxonomy' ) ), self::allowed_html() );
		echo wp_kses( self::text( $base . '[key]', '', 'meta' === $type ? $spec['key'] : '', 'code', ' list="oliprfe-meta-keys" data-src="meta" aria-label="' . esc_attr__( 'Meta key', 'oli-product-feed' ) . '"' . $hidden( 'meta' ) ), self::allowed_html() );
		echo wp_kses( self::text( $base . '[key]', '', 'item' === $type ? $spec['key'] : '', 'code', ' placeholder="brand" pattern="[a-z0-9_]+" data-src="item" aria-label="' . esc_attr__( 'Feed attribute to copy', 'oli-product-feed' ) . '"' . $hidden( 'item' ) ), self::allowed_html() );
		echo wp_kses( self::text( $base . '[value]', '', 'static' === $type ? $spec['value'] : '', '', ' data-src="static" aria-label="' . esc_attr__( 'Static value', 'oli-product-feed' ) . '"' . $hidden( 'static' ) ), self::allowed_html() );
		echo '</td><td><details' . ( ( $spec['prefix'] || $spec['suffix'] || $spec['fallback'] || $spec['map'] || 'first' !== $spec['mode'] || $spec['parent'] || $spec['translate'] || $spec['single'] ) ? ' open' : '' ) . '><summary>' . esc_html__( 'Options', 'oli-product-feed' ) . '</summary>';
		echo wp_kses( '<p><label>' . esc_html__( 'Prefix', 'oli-product-feed' ) . ' ' . self::text( $base . '[prefix]', '', $spec['prefix'], '' ) . '</label></p>', self::allowed_html() );
		echo wp_kses( '<p><label>' . esc_html__( 'Suffix', 'oli-product-feed' ) . ' ' . self::text( $base . '[suffix]', '', $spec['suffix'], '' ) . '</label></p>', self::allowed_html() );
		echo wp_kses( '<p><label>' . esc_html__( 'Fallback when empty', 'oli-product-feed' ) . ' ' . self::text( $base . '[fallback]', '', $spec['fallback'], '' ) . '</label></p>', self::allowed_html() );
		$map = array();
		foreach ( $spec['map'] as $from => $to ) {
			$map[] = $from . '=' . $to;
		}
		echo wp_kses( '<p><label>' . esc_html__( 'Rewrite values (from=to; …)', 'oli-product-feed' ) . ' ' . self::text( $base . '[map]', '', implode( '; ', $map ), 'code' ) . '</label></p>', self::allowed_html() );
		$terms_select = self::select(
			$base . '[mode]',
			'',
			array(
				'first' => __( 'First term', 'oli-product-feed' ),
				'all'   => __( 'All terms', 'oli-product-feed' ),
				'path'  => __( 'Full path (A > B)', 'oli-product-feed' ),
				'top'   => __( 'Top-level term', 'oli-product-feed' ),
			),
			$spec['mode']
		);
		echo wp_kses( '<p><label>' . esc_html__( 'Terms', 'oli-product-feed' ) . ' ' . $terms_select . '</label></p>', self::allowed_html() );
		echo wp_kses( '<p>' . self::checkbox( $base . '[parent]', (bool) $spec['parent'], __( 'Use the parent value when a variation has none', 'oli-product-feed' ) ) . '<br>', self::allowed_html() );
		echo wp_kses( self::checkbox( $base . '[translate]', (bool) $spec['translate'], __( 'Translate in the feed language', 'oli-product-feed' ) ) . '<br>', self::allowed_html() );
		echo wp_kses( self::checkbox( $base . '[single]', (bool) $spec['single'], __( 'One value only: send nothing when the product has several values', 'oli-product-feed' ) ) . '</p>', self::allowed_html() );
		echo '</details></td>';
		$value = '';
		if ( self::$preview && isset( self::$preview['row'][ $field ] ) ) {
			$value = self::$preview['row'][ $field ];
			$value = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			$value = mb_strlen( $value ) > 140 ? mb_substr( $value, 0, 140 ) . '…' : $value;
		}
		echo '<td class="oliprfe-preview" data-colname="' . esc_attr__( 'Preview', 'oli-product-feed' ) . '">' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Product taxonomies (name => label).
	 *
	 * @return array<string,string>
	 */
	private static function taxonomies() {
		$out = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $name => $tax ) {
			if ( in_array( $name, array( 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				continue;
			}
			$out[ $name ] = $tax->label . ' (' . $name . ')';
		}
		return $out;
	}

	/**
	 * Datalists of meta keys and attribute names.
	 */
	private static function meta_datalist() {
		global $wpdb;
		$keys = get_transient( 'oliprfe_meta_keys' );
		if ( ! is_array( $keys ) ) {
			// Distinct product meta keys, for autocompletion only (cached 1 hour).
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN (%s, %s) ORDER BY pm.meta_key LIMIT 500", 'product', 'product_variation' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached in a transient.
			set_transient( 'oliprfe_meta_keys', $keys, HOUR_IN_SECONDS );
		}
		echo '<datalist id="oliprfe-meta-keys">';
		foreach ( (array) $keys as $key ) {
			echo '<option value="' . esc_attr( $key ) . '">';
		}
		echo '</datalist><datalist id="oliprfe-attribute-keys">';
		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			echo '<option value="' . esc_attr( wc_attribute_taxonomy_name( $attribute->attribute_name ) ) . '">' . esc_html( $attribute->attribute_label ) . '</option>';
		}
		echo '</datalist>';
	}

	/**
	 * Categories box (Google categories, title types).
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function box_categories( array $feed ) {
		self::open_box( 'oliprfe-categories', __( 'Categories', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'Default Google category', 'oli-product-feed' ), 'oliprfe-google_category_default', self::text( 'feed[google_category_default]', 'oliprfe-google_category_default', $feed['google_category_default'], 'regular-text code' ), __( 'Numeric ID from the Google product taxonomy (for example 80 for Guitars). Used when no product category below has one.', 'oli-product-feed' ) );
		echo '</tbody></table>';
		echo '<p>' . esc_html__( 'Per product category: Google category ID and the {type} used by the title template. The deepest category with a value wins.', 'oli-product-feed' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'The type is left out when the name already contains it. Words in brackets are optional: "[Guitar] Strap" adds "Guitar Strap", or nothing when the name already says "Strap".', 'oli-product-feed' ) . '</p>';
		echo '<table class="widefat striped oliprfe-categories"><thead><tr><th scope="col">' . esc_html__( 'Product category', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Google category ID', 'oli-product-feed' ) . '</th><th scope="col">' . esc_html__( 'Type for the title', 'oli-product-feed' ) . '</th></tr></thead><tbody>';
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$rows = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$path = array_reverse( array_map( 'intval', get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) );
			$name = array();
			foreach ( $path as $ancestor ) {
				$a      = get_term( $ancestor, 'product_cat' );
				$name[] = $a && ! is_wp_error( $a ) ? $a->name : '';
			}
			$name[]                     = $term->name;
			$rows[ implode( ' > ', $name ) ] = $term;
		}
		ksort( $rows, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $rows as $path => $term ) {
			$id = (int) $term->term_id;
			echo '<tr><th scope="row">' . esc_html( html_entity_decode( $path, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) . ' <span class="oliprfe-meta">(' . (int) $term->count . ')</span></th>';
			echo wp_kses( '<td>' . self::text( 'feed[google_category_map][' . $id . ']', '', isset( $feed['google_category_map'][ $id ] ) ? $feed['google_category_map'][ $id ] : '', 'small-text code', ' aria-label="' . esc_attr__( 'Google category ID', 'oli-product-feed' ) . '"' ) . '</td>', self::allowed_html() );
			echo wp_kses( '<td>' . self::text( 'feed[title_type_map][' . $id . ']', '', isset( $feed['title_type_map'][ $id ] ) ? $feed['title_type_map'][ $id ] : '', 'regular-text', ' aria-label="' . esc_attr__( 'Type for the title', 'oli-product-feed' ) . '"' ) . '</td></tr>', self::allowed_html() );
		}
		echo '</tbody></table>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'Per product', 'oli-product-feed' ), 'oliprfe-google_category_items', '<textarea name="feed[google_category_items]" id="oliprfe-google_category_items" rows="4" class="large-text code">' . esc_textarea( self::pairs_text( $feed['google_category_items'] ) ) . '</textarea>', __( 'One product per line: product_id=google_category_id. Overrides the category table.', 'oli-product-feed' ) );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Links and images box.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function box_links( array $feed ) {
		self::open_box( 'oliprfe-links', __( 'Links and images', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( __( 'HTTPS', 'oli-product-feed' ), '', self::checkbox( 'feed[force_https]', $feed['force_https'], __( 'Write https:// links when the site runs on https', 'oli-product-feed' ) ) );
		$utm = '';
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			$utm .= '<p><label><code>' . esc_html( $key ) . '</code> ' . self::text( 'feed[utm][' . $key . ']', '', isset( $feed['utm'][ $key ] ) ? $feed['utm'][ $key ] : '', 'regular-text' ) . '</label></p>';
		}
		self::row( __( 'UTM tags', 'oli-product-feed' ), '', $utm, __( 'Optional. Leave empty for clean links.', 'oli-product-feed' ) );
		self::row(
			__( 'Parameters added to links', 'oli-product-feed' ),
			'oliprfe-link_args',
			self::text( 'feed[link_args]', 'oliprfe-link_args', self::query_string( $feed['link_args'] ), 'regular-text code', ' placeholder="wcpbc-manual-country=US"' ),
			__( 'Query string of key=value pairs (for example wcpbc-manual-country=US). Added to each item link (g:link) and to mobile_link when it is set. Existing parameters (variation attribute_*, lang) are kept. Not added to image_link. Empty: off.', 'oli-product-feed' )
		);
		$sizes = array( 'full' => __( 'Original file (recommended)', 'oli-product-feed' ) );
		foreach ( get_intermediate_image_sizes() as $size ) {
			$sizes[ $size ] = $size;
		}
		self::row( __( 'Image size', 'oli-product-feed' ), 'oliprfe-image_size', self::select( 'feed[image_size]', 'oliprfe-image_size', $sizes, $feed['image_size'] ) );
		self::row( __( 'Additional images', 'oli-product-feed' ), 'oliprfe-max_additional_images', '<input type="number" min="0" max="10" class="small-text" id="oliprfe-max_additional_images" name="feed[max_additional_images]" value="' . esc_attr( (string) $feed['max_additional_images'] ) . '"> ' . esc_html__( 'gallery images at most', 'oli-product-feed' ) );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Schedule box.
	 *
	 * @param array<string,mixed> $feed Feed.
	 */
	private static function box_schedule( array $feed ) {
		self::open_box( 'oliprfe-schedule', __( 'Schedule', 'oli-product-feed' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		$frequencies = array(
			'manual'     => __( 'Manually only (Generate now button or WP-CLI)', 'oli-product-feed' ),
			'hourly'     => __( 'Every hour', 'oli-product-feed' ),
			'twicedaily' => __( 'Twice a day', 'oli-product-feed' ),
			'daily'      => __( 'Every day', 'oli-product-feed' ),
			'weekly'     => __( 'Every week', 'oli-product-feed' ),
		);
		$radios = '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Regenerate', 'oli-product-feed' ) . '</legend>';
		foreach ( $frequencies as $value => $label ) {
			$radios .= '<label><input type="radio" name="feed[frequency]" value="' . esc_attr( $value ) . '"' . checked( $feed['frequency'], $value, false ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		$radios .= '</fieldset>';
		self::row( __( 'Regenerate', 'oli-product-feed' ), '', $radios, __( 'Runs in the background with Action Scheduler (WP-Cron when it is not available).', 'oli-product-feed' ) );
		$hours = array( '-1' => __( 'Any time', 'oli-product-feed' ) );
		for ( $h = 0; $h < 24; $h++ ) {
			$hours[ (string) $h ] = sprintf( '%02d:00', $h );
		}
		self::row( __( 'Start hour', 'oli-product-feed' ), 'oliprfe-start_hour', self::select( 'feed[start_hour]', 'oliprfe-start_hour', $hours, (string) $feed['start_hour'] ), __( 'Daily and weekly feeds start at this hour of the site time zone (off-peak hours are best).', 'oli-product-feed' ) . ' ' . sprintf(
			/* translators: %s: time zone name. */
			__( 'Site time zone: %s.', 'oli-product-feed' ),
			wp_timezone_string()
		) );
		self::row( __( 'Batch size', 'oli-product-feed' ), 'oliprfe-batch_size', '<input type="number" min="10" max="1000" step="10" class="small-text" id="oliprfe-batch_size" name="feed[batch_size]" value="' . esc_attr( (string) $feed['batch_size'] ) . '"> ' . esc_html__( 'products per batch', 'oli-product-feed' ) );
		echo '</tbody></table>';
		self::close_box();
	}

	/**
	 * Turns the posted form into a configuration (sanitized afterwards by Feeds::save()).
	 *
	 * @param array<string,mixed> $post Raw $_POST['feed'] (unslashed).
	 * @return array<string,mixed>
	 */
	public static function parse( array $post ) {
		$get = static function ( $key, $default = '' ) use ( $post ) {
			return isset( $post[ $key ] ) ? $post[ $key ] : $default;
		};
		$lines = static function ( $text ) {
			$out = array();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
				$parts = explode( '=', $line, 2 );
				if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
					$out[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
			return $out;
		};
		$rules = static function ( $rows, $with_availability ) {
			$out = array();
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( ! is_array( $row ) || empty( $row['meta'] ) || '' === trim( (string) $row['meta'] ) ) {
					continue;
				}
				$rule = array(
					'meta'  => trim( (string) $row['meta'] ),
					'value' => isset( $row['value'] ) ? trim( (string) $row['value'] ) : '',
				);
				if ( $with_availability ) {
					$rule['availability'] = isset( $row['availability'] ) ? (string) $row['availability'] : 'out_of_stock';
				}
				$out[] = $rule;
			}
			return $out;
		};

		$filters = is_array( $get( 'filters', array() ) ) ? $get( 'filters', array() ) : array();
		$config  = array(
			'name'                    => (string) $get( 'name' ),
			'active'                  => ! empty( $post['active'] ),
			'channel'                 => (string) $get( 'channel', 'google' ),
			'format'                  => (string) $get( 'format', 'xml' ),
			'file_slug'               => (string) $get( 'file_slug' ),
			'language'                => (string) $get( 'language' ),
			'country'                 => (string) $get( 'country' ),
			'currency'                => (string) $get( 'currency' ),
			'price_source'            => (string) $get( 'price_source', 'woocommerce' ),
			'price_source_options'    => array(),
			'min_price'               => (string) $get( 'min_price' ),
			'variations'              => ! empty( $post['variations'] ),
			'frequency'               => (string) $get( 'frequency', 'daily' ),
			'start_hour'              => (string) $get( 'start_hour', '-1' ),
			'batch_size'              => (int) $get( 'batch_size', 100 ),
			'image_size'              => (string) $get( 'image_size', 'full' ),
			'max_additional_images'   => (int) $get( 'max_additional_images', 10 ),
			'force_https'             => ! empty( $post['force_https'] ),
			'link_args'               => Util::query_pairs( $get( 'link_args' ) ),
			'utm'                     => is_array( $get( 'utm', array() ) ) ? $get( 'utm', array() ) : array(),
			'filters'                 => array(
				'categories_include' => isset( $filters['categories_include'] ) ? (array) $filters['categories_include'] : array(),
				'categories_exclude' => isset( $filters['categories_exclude'] ) ? (array) $filters['categories_exclude'] : array(),
				'stock_statuses'     => isset( $filters['stock_statuses'] ) ? (array) $filters['stock_statuses'] : array(),
				'exclude_ids'        => isset( $filters['exclude_ids'] ) ? (string) $filters['exclude_ids'] : '',
				'title_contains'     => isset( $filters['title_contains'] ) ? (string) $filters['title_contains'] : '',
				'title_not_contains' => isset( $filters['title_not_contains'] ) ? (string) $filters['title_not_contains'] : '',
				'require_fields'     => array_filter( array_map( 'trim', explode( ',', isset( $filters['require_fields'] ) ? (string) $filters['require_fields'] : '' ) ) ),
				'tags_include'       => isset( $filters['tags_include'] ) ? (string) $filters['tags_include'] : '',
				'tags_exclude'       => isset( $filters['tags_exclude'] ) ? (string) $filters['tags_exclude'] : '',
				'price_min'          => isset( $filters['price_min'] ) ? (string) $filters['price_min'] : '',
				'price_max'          => isset( $filters['price_max'] ) ? (string) $filters['price_max'] : '',
			),
			'exclude_rules'           => $rules( $get( 'exclude_rules', array() ), false ),
			'availability_rules'      => $rules( $get( 'availability_rules', array() ), true ),
			'backorder_lead_enabled'  => ! empty( $post['backorder_lead_enabled'] ),
			'backorder_lead_days'     => (int) $get( 'backorder_lead_days', 21 ),
			'backorder_as_preorder'   => ! empty( $post['backorder_as_preorder'] ),
			'backorder_min_handling_time' => (string) $get( 'backorder_min_handling_time' ),
			'backorder_max_handling_time' => (string) $get( 'backorder_max_handling_time' ),
			'title_template'          => (string) $get( 'title_template' ),
			'title_brand_prefix'      => ! empty( $post['title_brand_prefix'] ),
			'title_type_map'          => is_array( $get( 'title_type_map', array() ) ) ? $get( 'title_type_map', array() ) : array(),
			'description_min_length'  => (int) $get( 'description_min_length', 0 ),
			'description_fallbacks'   => array(),
			'description_attributes'  => ! empty( $post['description_attributes'] ),
			'description_remove'      => (string) $get( 'description_remove' ),
			'description_remove_emails' => ! empty( $post['description_remove_emails'] ),
			'title_strip_patterns'    => (string) $get( 'title_strip_patterns' ),
			'title_brand_case'        => ! empty( $post['title_brand_case'] ),
			'title_type_rules'        => array_values(
				array_filter(
					(array) $get( 'title_type_rules', array() ),
					static function ( $row ) {
						return is_array( $row ) && '' !== trim( isset( $row['pattern'] ) ? (string) $row['pattern'] : '' );
					}
				)
			),
			'field_rules'             => array(),
			'mpn_brands'              => (string) $get( 'mpn_brands' ),
			'mpn_only_without_gtin'   => ! empty( $post['mpn_only_without_gtin'] ),
			'brand_priority'          => (string) $get( 'brand_priority' ),
			'brand_from_title'        => ! empty( $post['brand_from_title'] ),
			'google_category_default' => (string) $get( 'google_category_default' ),
			'google_category_map'     => is_array( $get( 'google_category_map', array() ) ) ? $get( 'google_category_map', array() ) : array(),
			'google_category_items'   => $lines( $get( 'google_category_items' ) ),
			'item_overrides'          => array(),
			'mapping'                 => array(),
			'columns'                 => array(),
			'xml_root'                => (string) $get( 'xml_root', 'products' ),
			'xml_item'                => (string) $get( 'xml_item', 'product' ),
		);
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $get( 'columns' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' === $parts[0] ) {
				continue;
			}
			$config['columns'][] = array(
				'name'  => $parts[0],
				'field' => isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $parts[0],
			);
		}
		$pso = $get( 'price_source_options', array() );
		if ( is_array( $pso ) ) {
			if ( isset( $pso['zone'] ) && '' !== trim( (string) $pso['zone'] ) ) {
				$config['price_source_options']['zone'] = trim( (string) $pso['zone'] );
			}
			if ( ! empty( $pso['country_param'] ) ) {
				$config['price_source_options']['country_param'] = true;
			}
		}
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $get( 'description_fallbacks' ) ) ) ) as $key ) {
			$config['description_fallbacks'][] = array(
				'type'      => 'meta',
				'key'       => $key,
				'parent'    => true,
				'translate' => true,
			);
		}
		foreach ( is_array( $get( 'mapping', array() ) ) ? $get( 'mapping', array() ) : array() as $field => $row ) {
			if ( ! is_array( $row ) || empty( $row['type'] ) || 'auto' === $row['type'] || '__FIELD__' === $field ) {
				continue;
			}
			$map = array();
			foreach ( array_filter( array_map( 'trim', explode( ';', isset( $row['map'] ) ? (string) $row['map'] : '' ) ) ) as $pair ) {
				$parts = explode( '=', $pair, 2 );
				if ( 2 === count( $parts ) ) {
					$map[ trim( $parts[0] ) ] = trim( $parts[1] );
				}
			}
			$spec = array(
				'type'      => (string) $row['type'],
				'key'       => isset( $row['key'] ) ? (string) $row['key'] : '',
				'value'     => isset( $row['value'] ) ? (string) $row['value'] : '',
				'mode'      => isset( $row['mode'] ) ? (string) $row['mode'] : 'first',
				'parent'    => ! empty( $row['parent'] ),
				'prefix'    => isset( $row['prefix'] ) ? (string) $row['prefix'] : '',
				'suffix'    => isset( $row['suffix'] ) ? (string) $row['suffix'] : '',
				'translate' => ! empty( $row['translate'] ),
				'fallback'  => isset( $row['fallback'] ) ? (string) $row['fallback'] : '',
				'map'       => $map,
				'single'    => ! empty( $row['single'] ),
			);
			$config['mapping'][ $field ] = $spec;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $get( 'item_overrides' ) ) as $line ) {
			if ( preg_match( '/^\s*(\d+)\s+([A-Za-z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m ) ) {
				$config['item_overrides'][] = array(
					'product' => (int) $m[1],
					'field'   => $m[2],
					'value'   => $m[3],
				);
			}
		}
		foreach ( (array) $get( 'field_rules', array() ) as $row ) {
			if ( ! is_array( $row ) || '' === trim( isset( $row['field'] ) ? (string) $row['field'] : '' ) ) {
				continue;
			}
			$words = array();
			foreach ( array_filter( array_map( 'trim', explode( ';', isset( $row['words'] ) ? (string) $row['words'] : '' ) ) ) as $pair ) {
				$parts                       = explode( '=', $pair, 2 );
				$words[ trim( $parts[0] ) ] = isset( $parts[1] ) ? trim( $parts[1] ) : trim( $parts[0] );
			}
			$config['field_rules'][] = array(
				'field'             => trim( (string) $row['field'] ),
				'value'             => isset( $row['value'] ) ? (string) $row['value'] : '',
				'words'             => $words,
				'google_categories' => isset( $row['google_categories'] ) ? (string) $row['google_categories'] : '',
				'categories'        => isset( $row['categories'] ) ? (string) $row['categories'] : '',
				'active'            => ! empty( $row['active'] ),
			);
		}
		return $config;
	}

	/**
	 * Validation errors of a parsed configuration.
	 *
	 * @param array<string,mixed> $config  Parsed configuration.
	 * @param int                 $feed_id Feed ID being saved.
	 * @return array<string,string>
	 */
	public static function validate( array $config, $feed_id ) {
		$errors = array();
		if ( '' === trim( $config['name'] ) ) {
			$errors['name'] = __( 'Enter a feed name.', 'oli-product-feed' );
		}
		$slug = sanitize_title( $config['file_slug'] );
		if ( '' !== $slug ) {
			$other = Feeds::find_by_slug( $slug );
			if ( $other && $other !== (int) $feed_id ) {
				$errors['file_slug'] = __( 'Another feed already uses this file name.', 'oli-product-feed' );
			}
		}
		return $errors;
	}
}
