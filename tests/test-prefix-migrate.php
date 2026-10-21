<?php
/**
 * Unit tests for the oli_pf_ → oliprfe_ name map (0.5.6).
 *
 * Run: php tests/test-prefix-migrate.php
 *
 * phpcs:ignoreFile
 *
 * @package Oliprfe
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}
	if ( ! defined( 'OLIPRFE_VERSION' ) ) {
		define( 'OLIPRFE_VERSION', '0.5.6' );
	}

	require_once dirname( __DIR__ ) . '/src/PrefixMigrate.php';

	$failed = 0;
	$oliprfe_assert = static function ( $label, $expected, $actual ) use ( &$failed ) {
		if ( $expected === $actual ) {
			echo "ok  {$label}\n";
			return;
		}
		++$failed;
		echo "FAIL {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	};

	$oliprfe_assert( 'settings option', 'oliprfe_settings', \Oliprfe\PrefixMigrate::rename( 'oli_pf_settings' ) );
	$oliprfe_assert( 'output dirs', 'oliprfe_output_dirs', \Oliprfe\PrefixMigrate::rename( 'oli_pf_output_dirs' ) );
	$oliprfe_assert( 'endpoint index', 'oliprfe_endpoint_index', \Oliprfe\PrefixMigrate::rename( 'oli_pf_endpoint_index' ) );
	$oliprfe_assert( 'generation state', 'oliprfe_state_12', \Oliprfe\PrefixMigrate::rename( 'oli_pf_state_12' ) );
	$oliprfe_assert( 'lock transient', 'oliprfe_lock_12', \Oliprfe\PrefixMigrate::rename( 'oli_pf_lock_12' ) );
	$oliprfe_assert( 'config meta', '_oliprfe_config', \Oliprfe\PrefixMigrate::rename( '_oli_pf_config' ) );
	$oliprfe_assert( 'log meta', '_oliprfe_log', \Oliprfe\PrefixMigrate::rename( '_oli_pf_log' ) );
	$oliprfe_assert( 'last-run meta', '_oliprfe_last_run', \Oliprfe\PrefixMigrate::rename( '_oli_pf_last_run' ) );
	$oliprfe_assert( 'post type', 'oliprfe_feed', \Oliprfe\PrefixMigrate::rename( 'oli_pf_feed' ) );
	$oliprfe_assert( 'already new option unchanged', 'oliprfe_settings', \Oliprfe\PrefixMigrate::rename( 'oliprfe_settings' ) );
	$oliprfe_assert( 'unrelated name unchanged', 'other_option', \Oliprfe\PrefixMigrate::rename( 'other_option' ) );

	if ( $failed ) {
		echo "\n{$failed} failed\n";
		exit( 1 );
	}
	echo "\nall passed\n";
}
