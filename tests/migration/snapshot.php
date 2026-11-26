<?php
/**
 * Non-loss snapshot (CA-51 to CA-53), run with WP-CLI on a private test site:
 *   wp eval-file tests/migration/snapshot.php <out.json>
 * Writes the raw stored bytes of every feed configuration (_oliprfe_config) and of the oliprfe_settings
 * option, plus the SHA-256 of every feed file with its timestamps removed. Reads only.
 *
 * @package Oliprfe
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;
$oliprfe_out = array(
	'version'  => defined( 'OLIPRFE_VERSION' ) ? OLIPRFE_VERSION : '',
	'settings' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'oliprfe_settings' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	'feeds'    => array(),
);
foreach ( \Oliprfe\Feeds::all_ids() as $oliprfe_id ) {
	$oliprfe_raw  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $oliprfe_id, '_oliprfe_config' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$oliprfe_path = \Oliprfe\Storage::path_for( $oliprfe_id );
	$oliprfe_file = file_exists( $oliprfe_path ) ? (string) file_get_contents( $oliprfe_path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	// Timestamps only: ISO 8601 dates and RFC 822 dates written by the channels.
	$oliprfe_norm = preg_replace( '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:?\d{2}|Z)?/', 'TS', $oliprfe_file );
	$oliprfe_norm = preg_replace( '/[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} [+-]\d{4}/', 'TS', (string) $oliprfe_norm );
	$oliprfe_out['feeds'][ $oliprfe_id ] = array(
		'status'      => get_post_status( $oliprfe_id ),
		'title'       => get_the_title( $oliprfe_id ),
		'config'      => $oliprfe_raw,
		'url'         => \Oliprfe\Storage::url_for( $oliprfe_id ),
		'path'        => $oliprfe_path,
		'file_sha256' => '' === $oliprfe_file ? '' : hash( 'sha256', (string) $oliprfe_norm ),
		'file_bytes'  => strlen( $oliprfe_file ),
	);
}
$oliprfe_target = isset( $args[0] ) ? $args[0] : 'php://stdout';
file_put_contents( $oliprfe_target, wp_json_encode( $oliprfe_out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
