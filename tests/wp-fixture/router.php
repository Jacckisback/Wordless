<?php
/**
 * Router for PHP's built-in web server, serving the WordPress fixture with pretty permalinks.
 *
 * Usage (see start.sh):
 *   php -S 127.0.0.1:8081 -t /tmp/wp-test/site /tmp/wp-test/router.php
 *
 * - Existing files (static assets, uploads, wp-login.php, wp-admin/*.php, ...) are served
 *   directly by the built-in server (returning false hands the request back to it).
 * - Everything else (pretty permalinks, /wp-json/..., 404s) goes through WordPress' front
 *   controller index.php, like the standard WordPress .htaccess rules do on Apache.
 */

$docroot = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );
$path    = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( '' === $path ) {
	$path = '/';
}

// Never serve dotfiles/dot-directories (the SQLite database lives in wp-content/database/.ht.sqlite).
if ( preg_match( '#/\.#', $path ) || str_contains( $path, '..' ) ) {
	http_response_code( 403 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "Forbidden\n";
	return true;
}

$target = $docroot . $path;

// Existing regular file: let the built-in server serve it (or execute it when it is a .php script).
if ( is_file( $target ) ) {
	return false;
}

// Existing directory with an index file (e.g. /wp-admin/): add the trailing slash, then let the server handle it.
if ( '/' !== $path && is_dir( $target ) && ( is_file( $target . '/index.php' ) || is_file( $target . '/index.html' ) ) ) {
	if ( ! str_ends_with( $path, '/' ) ) {
		$query = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY );
		header( 'Location: ' . $path . '/' . ( $query ? '?' . $query : '' ), true, 301 );
		return true;
	}
	return false;
}

// Everything else: WordPress front controller.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $docroot );
require $docroot . '/index.php';
