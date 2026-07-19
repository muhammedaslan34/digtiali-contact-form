<?php
/**
 * Compile .po to .mo for the Digtiali Contact Form plugin using WordPress's POMO library.
 *
 * Usage:
 *   php wp-content/plugins/digtiali-contact-form/languages/compile-mo.php
 *   php wp-content/plugins/digtiali-contact-form/languages/compile-mo.php en_US
 *
 * @package Digtiali_Contact_Form
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' ) {
	exit( 'This script is command-line only.' );
}

$lang_dir = __DIR__;
$root     = dirname( $lang_dir, 4 ); // app/public
$pomo_dir = $root . '/wp-includes/pomo';

foreach ( array( 'translations.php', 'entry.php', 'streams.php', 'po.php', 'mo.php' ) as $f ) {
	require_once $pomo_dir . '/' . $f;
}

if ( ! class_exists( 'PO' ) || ! class_exists( 'MO' ) ) {
	fwrite( STDERR, "ERROR: WordPress POMO classes not found in {$pomo_dir}\n" );
	exit( 1 );
}

$targets = array();
if ( $argc > 1 ) {
	foreach ( array_slice( $argv, 1 ) as $locale ) {
		$po = $lang_dir . '/digtiali-contact-form-' . $locale . '.po';
		if ( ! is_readable( $po ) ) {
			fwrite( STDERR, "SKIP: {$po} not found\n" );
			continue;
		}
		$targets[] = $po;
	}
} else {
	foreach ( glob( $lang_dir . '/digtiali-contact-form-*.po' ) as $po ) {
		$targets[] = $po;
	}
}

if ( ! $targets ) {
	fwrite( STDERR, "No .po files to compile.\n" );
	exit( 1 );
}

foreach ( $targets as $po_file ) {
	$mo_file = substr( $po_file, 0, -3 ) . '.mo';

	$po = new PO();
	if ( ! $po->import_from_file( $po_file ) ) {
		fwrite( STDERR, "FAIL: could not parse {$po_file}\n" );
		continue;
	}

	$mo = new MO();
	$mo->set_header( 'Project-Id-Version', $po->headers['Project-Id-Version'] ?? 'Digtiali Contact Form' );
	$mo->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
	$mo->set_header( 'Content-Transfer-Encoding', '8bit' );
	if ( isset( $po->headers['Plural-Forms'] ) ) {
		$mo->set_header( 'Plural-Forms', $po->headers['Plural-Forms'] );
	}
	$mo->set_header( 'Language', $po->headers['Language'] ?? '' );

	foreach ( $po->entries as $key => $entry ) {
		if ( '' === $entry->singular ) {
			continue;
		}
		$mo->add_entry( $entry );
	}

	if ( $mo->export_to_file( $mo_file ) ) {
		$count = count( $mo->entries );
		echo "OK: {$mo_file} ({$count} entries)\n";
	} else {
		fwrite( STDERR, "FAIL: could not write {$mo_file}\n" );
	}
}
