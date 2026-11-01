<?php
/**
 * Produce the WXR file with WordPress' own exporter - the same code path as
 * Tools > Export > "All content" in wp-admin (export_wp( array( 'content' => 'all' ) )).
 *
 * Usage: php /tmp/wp-test/export.php [output-file]   (default: /tmp/wp-test/export.xml)
 *
 * export_wp() sends download headers and echoes the XML: in the CLI the headers are no-ops and the
 * output is captured with an output buffer.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = '127.0.0.1:8081';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8081';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/wp-admin/export.php?download=true&content=all';

require __DIR__ . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/export.php';

wp_set_current_user( 1 ); // An administrator clicking "Download Export File".

$out = $argv[1] ?? __DIR__ . '/export.xml';

ob_start();
export_wp( array( 'content' => 'all' ) );
$xml = ob_get_clean();

libxml_use_internal_errors( true );
$doc = simplexml_load_string( $xml );
if ( false === $doc ) {
	foreach ( libxml_get_errors() as $error ) {
		fwrite( STDERR, trim( $error->message ) . ' (line ' . $error->line . ")\n" );
	}
	fwrite( STDERR, "Export is not well-formed XML.\n" );
	exit( 1 );
}

file_put_contents( $out, $xml );

$wp    = $doc->channel->children( 'http://wordpress.org/export/1.2/' );
$types = array();
foreach ( $doc->channel->item as $item ) {
	$type           = (string) $item->children( 'http://wordpress.org/export/1.2/' )->post_type;
	$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
}
ksort( $types );
printf(
	"Wrote %s (%d bytes): WXR %s, %d authors, %d categories, %d tags, %d other terms, %d items %s\n",
	$out,
	strlen( $xml ),
	(string) $wp->wxr_version,
	count( $wp->author ),
	count( $wp->category ),
	count( $wp->tag ),
	count( $wp->term ),
	count( $doc->channel->item ),
	wp_json_encode( $types )
);
