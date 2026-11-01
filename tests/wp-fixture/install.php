<?php
/**
 * Non-interactive WordPress install for the "Atelier Lumière" fixture (CLI only).
 *
 * Usage: php /tmp/wp-test/install.php
 * Called by build.sh on a fresh copy of WordPress (wp-config.php + SQLite drop-in already in place).
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const FIXTURE_URL = 'http://127.0.0.1:8081';

define( 'WP_INSTALLING', true );

// Pretend to be a request to the real site so that URL guessing is correct.
$_SERVER['HTTP_HOST']      = '127.0.0.1:8081';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8081';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/wp-admin/install.php';
$_SERVER['PHP_SELF']       = '/wp-admin/install.php';
$_SERVER['SCRIPT_NAME']    = '/wp-admin/install.php';

require __DIR__ . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( is_blog_installed() ) {
	fwrite( STDERR, "WordPress is already installed - run build.sh for a fresh fixture.\n" );
	exit( 1 );
}

$result = wp_install(
	'Atelier Lumière',            // Site title (unslashed, like wp-admin/install.php does).
	'admin',
	'admin@example.com',
	true,                         // Public (indexable).
	'',
	wp_slash( 'Admin-Test-2026!' ),
	''                            // en_US.
);

if ( empty( $result['user_id'] ) ) {
	fwrite( STDERR, "wp_install() failed.\n" );
	exit( 1 );
}

// General settings.
update_option( 'siteurl', FIXTURE_URL );
update_option( 'home', FIXTURE_URL );
update_option( 'blogdescription', 'Céramique artisanale à Lyon' );
update_option( 'timezone_string', 'Europe/Paris' );
update_option( 'gmt_offset', '' );
update_option( 'date_format', 'j F Y' );
update_option( 'time_format', 'H:i' );
update_option( 'start_of_week', 1 );

// Permalinks: /%postname%/ (no .htaccess needed, the PHP router plays that role).
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
update_option( 'category_base', '' );
update_option( 'tag_base', '' );
flush_rewrite_rules( false );

// Mark the SQLite Database Integration plugin as active (the drop-in would otherwise do it on the first admin page view).
$activation = activate_plugin( 'sqlite-database-integration/load.php', '', false, true );
if ( is_wp_error( $activation ) ) {
	fwrite( STDERR, 'Could not activate the SQLite plugin: ' . $activation->get_error_message() . "\n" );
	exit( 1 );
}

printf(
	"Installed WordPress %s (%s) - admin user #%d, DB %s\n",
	get_bloginfo( 'version' ),
	get_option( 'home' ),
	$result['user_id'],
	defined( 'DB_PATH' ) ? DB_PATH : '?'
);
