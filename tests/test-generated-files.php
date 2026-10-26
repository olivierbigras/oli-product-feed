<?php
/**
 * Unit tests for Storage::generated_files() (0.5.8).
 *
 * Covers listing xml/csv/tsv/txt without GLOB_BRACE (missing on Alpine/musl
 * PHP). Run: php tests/test-generated-files.php
 *
 * phpcs:ignoreFile
 *
 * @package Oliprfe
 */

namespace {

	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', '/tmp/' );
	}

	if ( ! function_exists( 'trailingslashit' ) ) {
		/**
		 * @param string $string Path.
		 * @return string
		 */
		function trailingslashit( $string ) {
			return rtrim( (string) $string, '/\\' ) . '/';
		}
	}

	require_once dirname( __DIR__ ) . '/src/Storage.php';

	$failed = 0;
	$oliprfe_assert = static function ( $label, $expected, $actual ) use ( &$failed ) {
		if ( $expected === $actual ) {
			echo "ok  {$label}\n";
			return;
		}
		++$failed;
		echo "FAIL {$label}\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
	};

	$root = dirname( __DIR__ );
	$src  = file_get_contents( $root . '/src/Admin/Admin.php' );
	$oliprfe_assert( 'settings screen does not use GLOB_BRACE', false, false !== strpos( (string) $src, 'GLOB_BRACE' ) );
	$oliprfe_assert( 'settings screen uses generated_files()', true, false !== strpos( (string) $src, 'generated_files(' ) );

	$hits = array();
	foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) ) as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'GLOB_BRACE' ) ) {
			$hits[] = $file->getPathname();
		}
	}
	$oliprfe_assert( 'no GLOB_BRACE in src/', array(), $hits );

	$dir = sys_get_temp_dir() . '/oliprfe-generated-files-' . getmypid();
	if ( ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		echo "FAIL could not create {$dir}\n";
		exit( 1 );
	}

	file_put_contents( $dir . '/feed.xml', '<rss/>' );
	file_put_contents( $dir . '/catalog.csv', 'sku,name' );
	file_put_contents( $dir . '/items.tsv', "sku\tname" );
	file_put_contents( $dir . '/bing.txt', "sku\tname" );
	file_put_contents( $dir . '/notes.json', '{}' );
	file_put_contents( $dir . '/README', 'x' );

	$found = \Oliprfe\Storage::generated_files( $dir );
	$names = array_map( 'basename', $found );
	sort( $names );

	$oliprfe_assert(
		'lists xml csv tsv txt only',
		array( 'bing.txt', 'catalog.csv', 'feed.xml', 'items.tsv' ),
		$names
	);
	$oliprfe_assert( 'ignores json and extensionless files', false, in_array( 'notes.json', $names, true ) || in_array( 'README', $names, true ) );

	$empty = $dir . '/empty';
	mkdir( $empty );
	$oliprfe_assert( 'empty folder is an empty list', array(), \Oliprfe\Storage::generated_files( $empty ) );

	foreach ( array( 'feed.xml', 'catalog.csv', 'items.tsv', 'bing.txt', 'notes.json', 'README' ) as $name ) {
		unlink( $dir . '/' . $name );
	}
	rmdir( $empty );
	rmdir( $dir );

	if ( $failed ) {
		echo "\n{$failed} failed\n";
		exit( 1 );
	}
	echo "\nall passed\n";
}
