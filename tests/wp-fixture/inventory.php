<?php
/**
 * Build the machine-readable inventory (fixture.json) and the human documentation (FIXTURE.md)
 * of the "Atelier Lumière" fixture, from the live database + .seed-manifest.json + export.xml.
 * REST expectations are computed with internal rest_do_request() calls (anonymous and as admin).
 *
 * Usage:
 *   php /tmp/wp-test/inventory.php                 -> writes fixture.json + FIXTURE.md
 *   php /tmp/wp-test/inventory.php --fingerprint   -> prints the content fingerprint (used by verify.sh)
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$_SERVER['HTTP_HOST']      = '127.0.0.1:8081';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8081';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require __DIR__ . '/site/wp-load.php';

const INV_BASE = __DIR__;
const INV_URL  = 'http://127.0.0.1:8081';

/**
 * SHA-256 over every content table (posts, postmeta, comments, commentmeta, terms, term_taxonomy,
 * term_relationships, termmeta, users without password hashes) + the main reading options. Stable across
 * rebuilds. usermeta is excluded on purpose: WordPress updates the application password "last used" data on each
 * authenticated request.
 */
function inv_fingerprint() {
	global $wpdb;
	// Auto-drafts (created e.g. by the wp-admin Dashboard "Quick Draft" box) are ignored: they are never exported
	// to WXR nor listed by the REST API.
	$auto_drafts = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'";
	$tables      = array(
		"{$wpdb->posts} WHERE post_status <> 'auto-draft'" => 'ID',
		"{$wpdb->postmeta} WHERE post_id NOT IN ({$auto_drafts})" => 'meta_id',
		$wpdb->comments           => 'comment_ID',
		$wpdb->commentmeta        => 'meta_id',
		$wpdb->terms              => 'term_id',
		$wpdb->term_taxonomy      => 'term_taxonomy_id',
		$wpdb->term_relationships => 'object_id, term_taxonomy_id',
		$wpdb->termmeta           => 'meta_id',
		$wpdb->users              => 'ID',
	);
	$ctx    = hash_init( 'sha256' );
	foreach ( $tables as $table => $order ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$order}", ARRAY_A );
		if ( $wpdb->users === $table ) {
			foreach ( $rows as &$row ) {
				unset( $row['user_pass'], $row['user_activation_key'] ); // Salted hashes differ on every build.
			}
			unset( $row );
		}
		hash_update( $ctx, $table . ':' . wp_json_encode( $rows ) );
	}
	foreach ( array( 'siteurl', 'home', 'blogname', 'blogdescription', 'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts', 'sticky_posts', 'timezone_string' ) as $option ) {
		hash_update( $ctx, $option . '=' . maybe_serialize( get_option( $option ) ) );
	}
	return hash_final( $ctx );
}

if ( in_array( '--fingerprint', $argv, true ) ) {
	echo inv_fingerprint(), "\n";
	exit( 0 );
}

$manifest = json_decode( (string) file_get_contents( INV_BASE . '/.seed-manifest.json' ), true );
if ( ! $manifest ) {
	fwrite( STDERR, "Missing .seed-manifest.json - run seed.php first.\n" );
	exit( 1 );
}
// Symbolic keys of post-table objects only (term, user, comment and post IDs overlap).
$key_of = array();
foreach ( $manifest['ids'] as $k => $v ) {
	if ( preg_match( '/^(img|page|post|block|navigation|menu_item)_/', $k ) ) {
		$key_of[ $v ] = $k;
	}
}
$notes = array(); // post ID => curated notes.
foreach ( $manifest['notes'] as $k => $list ) {
	if ( isset( $manifest['ids'][ $k ] ) ) {
		$notes[ (string) $manifest['ids'][ $k ] ] = $list;
	}
}

// Permalinks as an administrator sees them (anonymous visitors get ?p= links for private posts).
wp_set_current_user( 1 );

global $wpdb;

/* ---------------------------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------------------------- */

function inv_rel( $url ) {
	return str_starts_with( (string) $url, INV_URL ) ? substr( $url, strlen( INV_URL ) ) : $url;
}

function inv_block_counts( array $blocks, array &$acc ) {
	foreach ( $blocks as $b ) {
		if ( $b['blockName'] ) {
			$acc[ $b['blockName'] ] = ( $acc[ $b['blockName'] ] ?? 0 ) + 1;
		}
		inv_block_counts( $b['innerBlocks'], $acc );
	}
}

function inv_block_refs( array $blocks, array &$acc ) {
	foreach ( $blocks as $b ) {
		if ( 'core/block' === $b['blockName'] && ! empty( $b['attrs']['ref'] ) ) {
			$acc[] = (int) $b['attrs']['ref'];
		}
		inv_block_refs( $b['innerBlocks'], $acc );
	}
}

/** Map every uploaded file name (full, scaled, original, sub-sizes) to its attachment ID and size name. */
function inv_upload_file_map() {
	$map = array();
	foreach ( get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
		)
	) as $a ) {
		$meta = wp_get_attachment_metadata( $a->ID );
		$dir  = dirname( $meta['file'] );
		$map[ $meta['file'] ] = array( $a->ID, 'full' );
		if ( ! empty( $meta['original_image'] ) ) {
			$map[ $dir . '/' . $meta['original_image'] ] = array( $a->ID, 'original_image' );
		}
		foreach ( $meta['sizes'] as $size => $s ) {
			$map[ $dir . '/' . $s['file'] ] = array( $a->ID, $size );
		}
	}
	return $map;
}

/** Resolve an internal URL to what it points at. */
function inv_resolve( $href, array $file_map ) {
	$path = (string) wp_parse_url( $href, PHP_URL_PATH );
	if ( preg_match( '#^/wp-content/uploads/(.+)$#', $path, $m ) ) {
		return isset( $file_map[ $m[1] ] ) ? array(
			'type' => 'attachment_file',
			'id'   => $file_map[ $m[1] ][0],
			'size' => $file_map[ $m[1] ][1],
		) : array( 'type' => 'missing_file' );
	}
	if ( preg_match( '#^/category/(?:.+/)?([^/]+)/$#', $path, $m ) ) {
		$term = get_term_by( 'slug', $m[1], 'category' );
		return $term ? array(
			'type' => 'category',
			'id'   => $term->term_id,
		) : array( 'type' => 'unknown' );
	}
	$id = url_to_postid( $href );
	if ( $id ) {
		return array(
			'type' => get_post_type( $id ),
			'id'   => $id,
		);
	}
	if ( '/' === $path ) {
		return array(
			'type' => 'page',
			'id'   => (int) get_option( 'page_on_front' ),
		);
	}
	return array( 'type' => 'unknown' );
}

function inv_rest( $method, $route, array $params = array(), $user = 0 ) {
	$previous_user = get_current_user_id();
	wp_set_current_user( $user );
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	$server   = rest_get_server();
	$data     = $server->response_to_data( $response, false );
	$headers  = $response->get_headers();
	wp_set_current_user( $previous_user );
	return array(
		'status' => $response->get_status(),
		'total'  => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : null,
		'data'   => $data,
	);
}

function inv_md_escape( $s ) {
	return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), (string) $s );
}

/* ---------------------------------------------------------------------------------------------
 * Site, environment, credentials
 * ------------------------------------------------------------------------------------------- */

$theme   = wp_get_theme();
$app_pw  = $manifest['application_password'];
$plugins = get_plugins();

$inv = array(
	'fixture'     => array(
		'name'         => 'Atelier Lumière - WordPress 7.1.4 migration test fixture',
		'version'      => 1,
		'generated_at' => gmdate( 'c' ),
		'deterministic_note' => 'Rebuilding with build.sh reproduces the same IDs, slugs, dates, URLs and files. Only generated_at, the WXR pubDate/created header, password hashes and the application password UUID differ.',
	),
	'paths'       => array(
		'base'          => INV_BASE,
		'docroot'       => ABSPATH,
		'wp_config'     => ABSPATH . 'wp-config.php',
		'database'      => DB_PATH,
		'uploads'       => wp_get_upload_dir()['basedir'],
		'router'        => INV_BASE . '/router.php',
		'start_script'  => INV_BASE . '/start.sh',
		'build_script'  => INV_BASE . '/build.sh',
		'install_script' => INV_BASE . '/install.php',
		'seed_script'   => INV_BASE . '/seed.php',
		'export_script' => INV_BASE . '/export.php',
		'verify_script' => INV_BASE . '/verify.sh',
		'export_file'   => INV_BASE . '/export.xml',
		'server_log'    => INV_BASE . '/server.log',
		'debug_log'     => INV_BASE . '/debug.log',
		'mu_plugin'     => WPMU_PLUGIN_DIR . '/fixture-tweaks.php',
		'db_dropin'     => WP_CONTENT_DIR . '/db.php',
	),
	'environment' => array(
		'wordpress'          => get_bloginfo( 'version' ),
		'db_version'         => (int) get_option( 'db_version' ),
		'php'                => PHP_VERSION,
		'database_engine'    => 'sqlite (SQLite Database Integration plugin ' . ( $plugins['sqlite-database-integration/load.php']['Version'] ?? '?' ) . ', db.php drop-in ' . SQLITE_DB_DROPIN_VERSION . ')',
		'web_server'         => 'PHP built-in server (php -S 127.0.0.1:8081 -t site router.php, PHP_CLI_SERVER_WORKERS=4)',
		'theme'              => array(
			'stylesheet'          => get_stylesheet(),
			'name'                => $theme->get( 'Name' ),
			'version'             => $theme->get( 'Version' ),
			'is_block_theme'      => wp_is_block_theme(),
			'nav_menu_locations'  => get_registered_nav_menus(),
		),
		'active_plugins'     => get_option( 'active_plugins' ),
		'installed_plugins'  => array_keys( $plugins ),
		'mu_plugins'         => array_keys( get_mu_plugins() ),
		'wp_config_constants' => array(
			'DB_PATH'                    => DB_PATH,
			'WP_HTTP_BLOCK_EXTERNAL'     => WP_HTTP_BLOCK_EXTERNAL,
			'DISABLE_WP_CRON'            => DISABLE_WP_CRON,
			'AUTOMATIC_UPDATER_DISABLED' => AUTOMATIC_UPDATER_DISABLED,
			'WP_DEBUG'                   => WP_DEBUG,
			'WP_DEBUG_DISPLAY'           => WP_DEBUG_DISPLAY,
			'WP_DEBUG_LOG'               => WP_DEBUG_LOG,
		),
		'application_passwords_available' => wp_is_application_passwords_available(),
		'attachment_pages_enabled'        => (bool) get_option( 'wp_attachment_pages_enabled' ),
	),
	'site'        => array(
		'url'                        => home_url(),
		'siteurl'                    => site_url(),
		'rest_root'                  => rest_url(),
		'title'                      => get_option( 'blogname' ),
		'tagline'                    => get_option( 'blogdescription' ),
		'admin_email'                => get_option( 'admin_email' ),
		'language'                   => get_locale(),
		'content_language'           => 'fr',
		'timezone_string'            => get_option( 'timezone_string' ),
		'date_format'                => get_option( 'date_format' ),
		'time_format'                => get_option( 'time_format' ),
		'permalink_structure'        => get_option( 'permalink_structure' ),
		'category_base'              => '/category/',
		'tag_base'                   => '/tag/',
		'show_on_front'              => get_option( 'show_on_front' ),
		'page_on_front'              => (int) get_option( 'page_on_front' ),
		'page_for_posts'             => (int) get_option( 'page_for_posts' ),
		'wp_page_for_privacy_policy' => (int) get_option( 'wp_page_for_privacy_policy' ),
		'posts_per_page'             => (int) get_option( 'posts_per_page' ),
		'default_category'           => (int) get_option( 'default_category' ),
		'sticky_posts'               => array_map( 'intval', get_option( 'sticky_posts' ) ),
		'blog_public'                => (int) get_option( 'blog_public' ),
		'thread_comments_depth'      => (int) get_option( 'thread_comments_depth' ),
	),
	'credentials' => array(
		'admin'                => array(
			'login'    => 'admin',
			'password' => 'Admin-Test-2026!',
			'email'    => 'admin@example.com',
			'role'     => 'administrator',
		),
		'application_password' => array(
			'user'           => 'admin',
			'name'           => $app_pw['name'],
			'uuid'           => $app_pw['uuid'],
			'password'       => $app_pw['password'],
			'password_nospace' => $app_pw['raw'],
			'curl_example'   => "curl -u 'admin:{$app_pw['password']}' '" . INV_URL . "/wp-json/wp/v2/posts?context=edit&status=any&per_page=100'",
			'basic_auth_header' => 'Authorization: Basic ' . base64_encode( 'admin:' . $app_pw['password'] ),
		),
		'claire'               => array(
			'login'    => 'claire',
			'password' => 'Claire-Test-2026!',
			'email'    => 'claire@example.com',
			'role'     => 'editor',
		),
		'lucas'                => array(
			'login'    => 'lucas',
			'password' => 'Lucas-Test-2026!',
			'email'    => 'lucas@example.com',
			'role'     => 'author',
		),
	),
);

/* ---------------------------------------------------------------------------------------------
 * Users
 * ------------------------------------------------------------------------------------------- */

$inv['users'] = array();
foreach ( get_users( array( 'orderby' => 'ID' ) ) as $u ) {
	$counts = array();
	foreach ( array( 'publish', 'draft', 'future', 'private' ) as $st ) {
		$counts[ $st ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type = 'post' AND post_status = %s", $u->ID, $st ) );
	}
	$inv['users'][] = array(
		'id'           => $u->ID,
		'login'        => $u->user_login,
		'slug'         => $u->user_nicename,
		'email'        => $u->user_email,
		'display_name' => $u->display_name,
		'first_name'   => $u->first_name,
		'last_name'    => $u->last_name,
		'nickname'     => $u->nickname,
		'role'         => $u->roles[0],
		'url'          => $u->user_url,
		'description'  => $u->description,
		'registered'   => $u->user_registered,
		'author_url'   => get_author_posts_url( $u->ID ),
		'posts'        => $counts,
	);
}
$login_of = wp_list_pluck( get_users(), 'user_login', 'ID' );

/* ---------------------------------------------------------------------------------------------
 * Terms
 * ------------------------------------------------------------------------------------------- */

foreach ( array(
	'categories'   => 'category',
	'tags'         => 'post_tag',
	'post_formats' => 'post_format',
) as $bucket => $tax ) {
	$inv[ $bucket ] = array();
	foreach ( get_terms(
		array(
			'taxonomy'   => $tax,
			'hide_empty' => false,
			'orderby'    => 'term_id',
		)
	) as $t ) {
		$parent             = $t->parent ? get_term( $t->parent, $tax ) : null;
		$inv[ $bucket ][]   = array(
			'id'          => $t->term_id,
			'name'        => $t->name,
			'slug'        => $t->slug,
			'parent'      => $t->parent,
			'parent_slug' => $parent ? $parent->slug : null,
			'description' => $t->description,
			'count'       => $t->count,
			'url'         => get_term_link( $t ),
		);
	}
}

/* ---------------------------------------------------------------------------------------------
 * Media
 * ------------------------------------------------------------------------------------------- */

$file_map     = inv_upload_file_map();
$inv['media'] = array();
foreach ( get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
) as $a ) {
	$meta  = wp_get_attachment_metadata( $a->ID );
	$dir   = dirname( $meta['file'] );
	$sizes = array();
	foreach ( $meta['sizes'] as $name => $s ) {
		$sizes[ $name ] = array(
			'file'   => $dir . '/' . $s['file'],
			'width'  => $s['width'],
			'height' => $s['height'],
			'url'    => wp_get_attachment_image_url( $a->ID, $name ),
		);
	}
	$inv['media'][] = array(
		'id'                 => $a->ID,
		'key'                => $key_of[ $a->ID ] ?? null,
		'title'              => $a->post_title,
		'slug'               => $a->post_name,
		'mime_type'          => $a->post_mime_type,
		'author'             => $login_of[ $a->post_author ],
		'date'               => $a->post_date,
		'date_gmt'           => $a->post_date_gmt,
		'parent'             => $a->post_parent,
		'guid'               => $a->guid,
		'attached_file'      => get_post_meta( $a->ID, '_wp_attached_file', true ),
		'url'                => wp_get_attachment_url( $a->ID ),
		'original_image_url' => ! empty( $meta['original_image'] ) ? wp_get_original_image_url( $a->ID ) : null,
		'width'              => $meta['width'],
		'height'             => $meta['height'],
		'filesize'           => $meta['filesize'] ?? null,
		'alt'                => get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
		'caption'            => $a->post_excerpt,
		'description'        => $a->post_content,
		'sizes'              => $sizes,
		'notes'              => $notes[ (string) $a->ID ] ?? array(),
	);
}

/* ---------------------------------------------------------------------------------------------
 * Posts, pages, synced patterns, navigation posts
 * ------------------------------------------------------------------------------------------- */

$features = array();
$add_feature = function ( $name, $id ) use ( &$features ) {
	$features[ $name ]   = $features[ $name ] ?? array();
	$features[ $name ][] = $id;
};

function inv_content_entry( WP_Post $p, array $file_map, array $notes, array $key_of, array $login_of, callable $add_feature ) {
	$content = $p->post_content;
	$blocks  = parse_blocks( $content );
	$counts  = array();
	inv_block_counts( $blocks, $counts );
	ksort( $counts );
	$refs = array();
	inv_block_refs( $blocks, $refs );
	$elementor = 'builder' === get_post_meta( $p->ID, '_elementor_edit_mode', true );
	$editor    = $elementor ? 'elementor' : ( has_blocks( $content ) ? 'gutenberg' : ( '' === trim( $content ) ? 'empty' : 'classic' ) );

	// Shortcodes (known and unknown), bare URLs on their own line, internal links, images.
	preg_match_all( '/\[([a-z][a-z0-9_-]*)(?=[\s\]])[^\]]*\]/', $content, $m );
	$shortcodes = array_values( array_unique( $m[1] ) );
	preg_match_all( '/\[([a-z][a-z0-9_-]*)(?=[\s\]])[^\]]*\]/', $content, $m_full );
	$shortcode_tags = array_values( array_unique( $m_full[0] ) );
	preg_match_all( '#^\s*(https?://\S+)\s*$#m', $content, $m );
	$bare_urls = array_values( array_unique( $m[1] ) );
	preg_match_all( '#href="(' . preg_quote( INV_URL, '#' ) . '[^"]*)"#', $content, $m );
	$links = array();
	foreach ( array_unique( $m[1] ) as $href ) {
		$links[] = array_merge( array( 'url' => $href ), inv_resolve( $href, $file_map ) );
	}
	preg_match_all( '#<img[^>]+src="([^"]+)"#', $content, $m );
	$images = array();
	foreach ( array_unique( $m[1] ) as $src ) {
		$r        = inv_resolve( $src, $file_map );
		$images[] = array(
			'src'           => $src,
			'attachment_id' => $r['id'] ?? null,
			'size'          => $r['size'] ?? null,
		);
	}
	preg_match_all( '#https?://[^\s"<\]]+#', $content, $m );
	$external = array_values(
		array_unique(
			array_filter(
				$m[0],
				function ( $u ) {
					return ! str_starts_with( $u, INV_URL );
				}
			)
		)
	);

	// Meta, grouped.
	$all_meta = get_post_meta( $p->ID );
	$seo      = array();
	$elem     = array();
	$other    = array();
	foreach ( $all_meta as $k => $values ) {
		$v = maybe_unserialize( $values[0] );
		if ( str_starts_with( $k, '_yoast_wpseo_' ) || str_starts_with( $k, 'rank_math_' ) ) {
			$seo[ $k ] = $v;
		} elseif ( str_starts_with( $k, '_elementor_' ) ) {
			$elem[ $k ] = '_elementor_data' === $k ? 'JSON, ' . strlen( $values[0] ) . ' bytes (see elementor.data_summary)' : $v;
		} else {
			$other[ $k ] = is_string( $v ) && strlen( $v ) > 120 ? substr( $v, 0, 117 ) . '...' : $v;
		}
	}
	ksort( $seo );
	ksort( $other );
	$seo_plugin = null;
	foreach ( array_keys( $seo ) as $k ) {
		$seo_plugin = str_starts_with( $k, '_yoast' ) ? 'yoast' : 'rankmath';
	}

	$thumb = (int) get_post_thumbnail_id( $p->ID );
	$cats  = 'post' === $p->post_type ? wp_get_post_categories( $p->ID, array( 'fields' => 'all' ) ) : array();
	$tags  = 'post' === $p->post_type ? wp_get_post_tags( $p->ID ) : array();

	$comment_counts = array();
	foreach ( get_comments(
		array(
			'post_id' => $p->ID,
			'status'  => 'all',
			'type'    => 'all',
		)
	) as $c ) {
		$st                    = '1' === $c->comment_approved ? 'approved' : 'hold';
		$label                 = 'comment' === $c->comment_type ? $st : $c->comment_type . '_' . $st;
		$comment_counts[ $label ] = ( $comment_counts[ $label ] ?? 0 ) + 1;
	}
	$spam = (int) get_comments(
		array(
			'post_id' => $p->ID,
			'status'  => 'spam',
			'count'   => true,
		)
	);
	if ( $spam ) {
		$comment_counts['spam'] = $spam;
	}

	$entry = array(
		'id'             => $p->ID,
		'key'            => $key_of[ $p->ID ] ?? null,
		'type'           => $p->post_type,
		'slug'           => $p->post_name,
		'title'          => $p->post_title,
		'status'         => $p->post_status,
		'author'         => $login_of[ $p->post_author ],
		'author_id'      => (int) $p->post_author,
		'date'           => $p->post_date,
		'date_gmt'       => $p->post_date_gmt,
		'modified'       => $p->post_modified,
		'modified_gmt'   => $p->post_modified_gmt,
		'url'            => get_permalink( $p ),
		'path'           => inv_rel( get_permalink( $p ) ),
		'guid'           => $p->guid,
		'parent'         => $p->post_parent,
		'menu_order'     => $p->menu_order,
		'comment_status' => $p->comment_status,
		'ping_status'    => $p->ping_status,
		'password'       => $p->post_password,
		'sticky'         => 'post' === $p->post_type && is_sticky( $p->ID ),
		'format'         => 'post' === $p->post_type ? ( get_post_format( $p ) ?: 'standard' ) : null,
		'template'       => get_page_template_slug( $p ) ?: null,
		'categories'     => wp_list_pluck( $cats, 'slug' ),
		'category_ids'   => array_map( 'intval', wp_list_pluck( $cats, 'term_id' ) ),
		'tags'           => wp_list_pluck( $tags, 'slug' ),
		'tag_ids'        => array_map( 'intval', wp_list_pluck( $tags, 'term_id' ) ),
		'featured_media' => $thumb ?: null,
		'excerpt_raw'    => $p->post_excerpt,
		'has_more_tag'   => str_contains( $content, '<!--more-->' ),
		'editor'         => $editor,
		'blocks'         => $counts,
		'synced_pattern_refs' => $refs,
		'shortcodes'     => $shortcodes,
		'shortcode_tags' => $shortcode_tags,
		'bare_urls_on_own_line' => $bare_urls,
		'internal_links' => $links,
		'images_in_content' => $images,
		'external_urls'  => $external,
		'content_length' => strlen( $content ),
		'content_sha256' => hash( 'sha256', $content ),
		'seo'            => $seo ? array(
			'plugin' => $seo_plugin,
			'meta'   => $seo,
		) : null,
		'elementor_meta' => $elem ?: null,
		'other_meta'     => $other,
		'comments'       => $comment_counts,
		'notes'          => $notes[ (string) $p->ID ] ?? array(),
	);

	// Feature index.
	$id = $p->ID;
	if ( in_array( $p->post_type, array( 'post', 'page' ), true ) ) {
		$add_feature( 'editor_' . $editor, $id );
		$add_feature( 'status_' . $p->post_status, $id );
		if ( $entry['sticky'] ) {
			$add_feature( 'sticky', $id );
		}
		if ( '' !== $p->post_password ) {
			$add_feature( 'password_protected', $id );
		}
		if ( '' === $p->post_name ) {
			$add_feature( 'empty_slug', $id );
		}
		if ( $p->post_parent && 'page' === $p->post_type ) {
			$add_feature( 'child_page', $id );
		}
		if ( $thumb ) {
			$add_feature( 'featured_image', $id );
		}
		if ( '' !== $p->post_excerpt ) {
			$add_feature( 'custom_excerpt', $id );
		}
		if ( $entry['has_more_tag'] ) {
			$add_feature( 'more_tag', $id );
		}
		if ( $entry['format'] && 'standard' !== $entry['format'] ) {
			$add_feature( 'post_format_' . $entry['format'], $id );
		}
		foreach ( array_keys( $counts ) as $block ) {
			$add_feature( 'block:' . $block, $id );
		}
		foreach ( $shortcodes as $sc ) {
			$add_feature( 'shortcode:' . $sc, $id );
		}
		if ( $bare_urls ) {
			$add_feature( 'bare_url_on_own_line', $id );
		}
		if ( $refs ) {
			$add_feature( 'synced_pattern_ref', $id );
		}
		foreach ( $links as $l ) {
			if ( str_contains( $l['url'], '/?p=' ) ) {
				$add_feature( 'internal_link_shortlink', $id );
			}
			if ( 'category' === $l['type'] ) {
				$add_feature( 'internal_link_category', $id );
			}
		}
		foreach ( $images as $img ) {
			if ( preg_match( '/-\d+x\d+\.(jpe?g|png)$/', $img['src'] ) ) {
				$add_feature( 'content_image_subsize_url', $id );
				break;
			}
		}
		if ( preg_match( '/[\x{10000}-\x{10FFFF}]/u', $content ) ) {
			$add_feature( 'emoji_4byte_utf8', $id );
		}
		if ( $seo_plugin ) {
			$add_feature( 'seo_' . $seo_plugin, $id );
		}
		if ( '1' === ( $seo['_yoast_wpseo_meta-robots-noindex'] ?? null ) ) {
			$add_feature( 'seo_yoast_noindex', $id );
		}
		if ( ! empty( $seo['_yoast_wpseo_canonical'] ) ) {
			$add_feature( 'seo_yoast_canonical', $id );
		}
		if ( ! empty( $seo['_yoast_wpseo_primary_category'] ) ) {
			$add_feature( 'seo_yoast_primary_category', $id );
		}
		if ( is_array( $seo['rank_math_robots'] ?? null ) && in_array( 'noindex', $seo['rank_math_robots'], true ) ) {
			$add_feature( 'seo_rankmath_noindex', $id );
		}
		if ( isset( $other['_wp_old_slug'] ) ) {
			$add_feature( 'old_slug_redirect', $id );
		}
		foreach ( array_keys( $other ) as $k ) {
			if ( str_starts_with( $k, '_oembed_' ) ) {
				$add_feature( 'oembed_cache_meta', $id );
				break;
			}
		}
		if ( $elementor ) {
			$add_feature( 'elementor', $id );
		}
	}
	return $entry;
}

function inv_elementor_summary( $post_id ) {
	$data = json_decode( (string) get_post_meta( $post_id, '_elementor_data', true ), true );
	$el   = array();
	$wid  = array();
	$walk = function ( array $elements, $depth ) use ( &$walk, &$el, &$wid ) {
		foreach ( $elements as $e ) {
			$el[ $e['elType'] ] = ( $el[ $e['elType'] ] ?? 0 ) + 1;
			if ( 'widget' === $e['elType'] ) {
				$wid[ $e['widgetType'] ] = ( $wid[ $e['widgetType'] ] ?? 0 ) + 1;
			}
			$walk( $e['elements'] ?? array(), $depth + 1 );
		}
	};
	$walk( $data ?: array(), 0 );
	ksort( $wid );
	return array(
		'top_level_elements' => count( $data ?: array() ),
		'element_types'      => $el,
		'widget_types'       => $wid,
		'images'             => array_values( array_unique( array_map( 'intval', preg_match_all( '/"image":\{"url":"[^"]*","id":(\d+)|"testimonial_image":\{"url":"[^"]*","id":(\d+)|"background_image":\{"url":"[^"]*","id":(\d+)/', wp_json_encode( $data, JSON_UNESCAPED_SLASHES ), $m ) ? array_filter( array_merge( $m[1], $m[2], $m[3] ) ) : array() ) ) ),
		'youtube_url'        => preg_match( '/"youtube_url":"([^"]+)"/', wp_json_encode( $data, JSON_UNESCAPED_SLASHES ), $m ) ? $m[1] : null,
		'raw_storage_note'   => 'Stored like Elementor does: wp_slash( wp_json_encode( $data ) ) -> slashes escaped as \\/ and accents as \\u00e9 in the database and in the WXR.',
	);
}

$inv['posts'] = array();
$inv['pages'] = array();
foreach ( array(
	'posts' => 'post',
	'pages' => 'page',
) as $bucket => $type ) {
	foreach ( get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => array( 'publish', 'draft', 'future', 'private', 'pending' ),
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	) as $p ) {
		$e = inv_content_entry( $p, $file_map, $notes, $key_of, $login_of, $add_feature );
		if ( 'page' === $type ) {
			$e['is_front_page'] = (int) get_option( 'page_on_front' ) === $p->ID;
			$e['is_posts_page'] = (int) get_option( 'page_for_posts' ) === $p->ID;
			$e['is_privacy_page'] = (int) get_option( 'wp_page_for_privacy_policy' ) === $p->ID;
			if ( 'elementor' === $e['editor'] ) {
				$e['elementor'] = inv_elementor_summary( $p->ID );
			}
		}
		$e['rest_route']        = '/wp/v2/' . $bucket . '/' . $p->ID;
		$e['public_in_rest']    = 'publish' === $p->post_status;
		$e['in_wxr']            = true;
		$inv[ $bucket ][]       = $e;
	}
}

$inv['synced_patterns'] = array();
$inv['navigation_posts'] = array();
foreach ( array(
	'synced_patterns'  => 'wp_block',
	'navigation_posts' => 'wp_navigation',
) as $bucket => $type ) {
	foreach ( get_posts(
		array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	) as $p ) {
		$counts = array();
		inv_block_counts( parse_blocks( $p->post_content ), $counts );
		$used_by = array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_content LIKE %s ORDER BY ID", '%' . $wpdb->esc_like( '<!-- wp:block {"ref":' . $p->ID . '}' ) . '%' ) )
		);
		$inv[ $bucket ][] = array(
			'id'       => $p->ID,
			'key'      => $key_of[ $p->ID ] ?? null,
			'type'     => $type,
			'slug'     => $p->post_name,
			'title'    => $p->post_title,
			'status'   => $p->post_status,
			'date'     => $p->post_date,
			'blocks'   => $counts,
			'referenced_by' => 'wp_block' === $type ? $used_by : null,
			'rest_route' => '/wp/v2/' . ( 'wp_block' === $type ? 'blocks' : 'navigation' ) . '/' . $p->ID,
			'content'  => $p->post_content,
			'notes'    => $notes[ (string) $p->ID ] ?? array(),
		);
	}
}

/* ---------------------------------------------------------------------------------------------
 * Comments
 * ------------------------------------------------------------------------------------------- */

$all_comments = array_merge(
	get_comments(
		array(
			'status'  => 'all',
			'type'    => 'all',
			'orderby' => 'comment_ID',
			'order'   => 'ASC',
		)
	),
	get_comments(
		array(
			'status'  => 'spam',
			'orderby' => 'comment_ID',
			'order'   => 'ASC',
		)
	)
);
usort(
	$all_comments,
	function ( $a, $b ) {
		return $a->comment_ID <=> $b->comment_ID;
	}
);
$parent_of = array();
foreach ( $all_comments as $c ) {
	$parent_of[ $c->comment_ID ] = (int) $c->comment_parent;
}
$inv['comments'] = array();
foreach ( $all_comments as $c ) {
	$depth = 1;
	for ( $pid = $parent_of[ $c->comment_ID ]; $pid; $pid = $parent_of[ $pid ] ?? 0 ) {
		++$depth;
	}
	$status            = '1' === $c->comment_approved ? 'approved' : ( 'spam' === $c->comment_approved ? 'spam' : 'hold' );
	$inv['comments'][] = array(
		'id'           => (int) $c->comment_ID,
		'post_id'      => (int) $c->comment_post_ID,
		'post_slug'    => get_post_field( 'post_name', $c->comment_post_ID ),
		'parent'       => (int) $c->comment_parent,
		'depth'        => $depth,
		'type'         => $c->comment_type,
		'status'       => $status,
		'comment_approved_raw' => $c->comment_approved,
		'author'       => $c->comment_author,
		'author_email' => $c->comment_author_email,
		'author_url'   => $c->comment_author_url,
		'author_ip'    => $c->comment_author_IP,
		'user_id'      => (int) $c->user_id,
		'user_login'   => $c->user_id ? ( $login_of[ $c->user_id ] ?? null ) : null,
		'date'         => $c->comment_date,
		'date_gmt'     => $c->comment_date_gmt,
		'content'      => $c->comment_content,
		'in_wxr'       => 'spam' !== $status,
		'public_in_rest' => 'approved' === $status,
		'rest_note'    => 'pingback' === $c->comment_type ? 'Only listed by /wp/v2/comments when ?type=pingback is passed' : null,
	);
}

/* ---------------------------------------------------------------------------------------------
 * Menus
 * ------------------------------------------------------------------------------------------- */

$inv['menus'] = array();
foreach ( wp_get_nav_menus() as $menu ) {
	$items = array();
	foreach ( wp_get_nav_menu_items( $menu->term_id ) as $it ) {
		$items[] = array(
			'id'          => $it->ID,
			'key'         => $key_of[ $it->ID ] ?? null,
			'title'       => $it->title,
			'stored_title' => $it->post_title,
			'type'        => $it->type,
			'object'      => $it->object,
			'object_id'   => (int) $it->object_id,
			'url'         => $it->url,
			'parent_item' => (int) $it->menu_item_parent,
			'position'    => (int) $it->menu_order,
			'target'      => $it->target,
			'attr_title'  => $it->attr_title,
			'description' => $it->description,
			'classes'     => array_values( array_filter( (array) $it->classes ) ),
			'xfn'         => $it->xfn,
			'post_parent_column' => (int) get_post_field( 'post_parent', $it->ID ),
		);
	}
	$inv['menus'][] = array(
		'id'        => $menu->term_id,
		'name'      => $menu->name,
		'slug'      => $menu->slug,
		'count'     => $menu->count,
		'locations' => array_keys( array_filter( get_nav_menu_locations(), fn( $mid ) => (int) $mid === $menu->term_id ) ),
		'items'     => $items,
	);
}
$inv['menu_locations'] = array(
	'registered_by_theme' => get_registered_nav_menus(),
	'assigned'            => get_nav_menu_locations(),
	'note'                => 'Twenty Twenty-Five (block theme) registers no classic menu locations, so no menu is assigned to a location. Its header Navigation block uses the wp_navigation post derived from "Menu principal".',
);

/* ---------------------------------------------------------------------------------------------
 * Counts
 * ------------------------------------------------------------------------------------------- */

$count_status = function ( $type ) use ( $wpdb ) {
	$out = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_status, COUNT(*) n FROM {$wpdb->posts} WHERE post_type = %s GROUP BY post_status ORDER BY post_status", $type ) ) as $r ) {
		$out[ $r->post_status ] = (int) $r->n;
	}
	$out['total'] = array_sum( $out );
	return $out;
};
$comment_stats = array(
	'approved' => 0,
	'hold'     => 0,
	'spam'     => 0,
	'by_type'  => array(),
);
foreach ( $inv['comments'] as $c ) {
	++$comment_stats[ $c['status'] ];
	$comment_stats['by_type'][ $c['type'] ] = ( $comment_stats['by_type'][ $c['type'] ] ?? 0 ) + 1;
}
$comment_stats['total'] = count( $inv['comments'] );

$inv['counts'] = array(
	'users'            => count( $inv['users'] ),
	'posts'            => $count_status( 'post' ),
	'pages'            => $count_status( 'page' ),
	'attachments'      => $count_status( 'attachment' )['total'],
	'attachment_files_on_disk' => count( $file_map ),
	'synced_patterns'  => count( $inv['synced_patterns'] ),
	'navigation_posts' => count( $inv['navigation_posts'] ),
	'categories'       => count( $inv['categories'] ),
	'tags'             => count( $inv['tags'] ),
	'menus'            => count( $inv['menus'] ),
	'menu_items'       => array_sum( array_map( fn( $m ) => count( $m['items'] ), $inv['menus'] ) ),
	'comments'         => $comment_stats,
	'application_passwords' => 1,
);

/* ---------------------------------------------------------------------------------------------
 * WXR facts (parsed from export.xml)
 * ------------------------------------------------------------------------------------------- */

$wxr_file = INV_BASE . '/export.xml';
$xml      = simplexml_load_file( $wxr_file );
if ( ! $xml ) {
	fwrite( STDERR, "export.xml missing or invalid - run export.php first.\n" );
	exit( 1 );
}
$ns    = 'http://wordpress.org/export/1.2/';
$wp    = $xml->channel->children( $ns );
$items = array();
$wxr_comments = array();
$wxr_postmeta_keys = array();
foreach ( $xml->channel->item as $item ) {
	$w    = $item->children( $ns );
	$type = (string) $w->post_type;
	$st   = (string) $w->status;
	$items[ $type ][ $st ] = ( $items[ $type ][ $st ] ?? 0 ) + 1;
	foreach ( $w->comment as $c ) {
		$wxr_comments[] = (int) $c->comment_id;
	}
	foreach ( $w->postmeta as $pm ) {
		$k                       = (string) $pm->meta_key;
		$wxr_postmeta_keys[ $k ] = ( $wxr_postmeta_keys[ $k ] ?? 0 ) + 1;
	}
}
ksort( $items );
ksort( $wxr_postmeta_keys );
$term_entries = array();
foreach ( $wp->term as $t ) {
	$tax                  = (string) $t->term_taxonomy;
	$term_entries[ $tax ] = ( $term_entries[ $tax ] ?? 0 ) + 1;
}
ksort( $term_entries );
sort( $wxr_comments );
$inv['wxr'] = array(
	'file'           => $wxr_file,
	'bytes'          => filesize( $wxr_file ),
	'generator'      => 'WordPress ' . get_bloginfo( 'version' ) . ' export_wp( array( "content" => "all" ) )',
	'wxr_version'    => (string) $wp->wxr_version,
	'base_site_url'  => (string) $wp->base_site_url,
	'base_blog_url'  => (string) $wp->base_blog_url,
	'authors'        => count( $wp->author ),
	'category_elements' => count( $wp->category ),
	'tag_elements'   => count( $wp->tag ),
	'term_elements_by_taxonomy' => $term_entries,
	'items_total'    => count( $xml->channel->item ),
	'items_by_type_and_status' => $items,
	'comments_total' => count( $wxr_comments ),
	'comment_ids'    => $wxr_comments,
	'excluded'       => array(
		'spam_comment_ids' => array_values( array_map( fn( $c ) => $c['id'], array_filter( $inv['comments'], fn( $c ) => 'spam' === $c['status'] ) ) ),
		'note'             => 'export_wp() skips spam comments (comment_approved <> "spam") and auto-drafts. Drafts, scheduled, private and password-protected posts ARE exported. Revisions are not exported (none exist anyway). Options/settings, theme mods, users without posts and application passwords are never part of WXR.',
	),
	'postmeta_key_occurrences' => $wxr_postmeta_keys,
	'quirks'         => array(
		'WordPress 7.1 also emits every category, tag, nav_menu and post_format term as a generic <wp:term> (get_terms() is called with an empty custom-taxonomy list), so categories/tags appear twice (as <wp:category>/<wp:tag> and as <wp:term>) - dedupe on term_id + taxonomy.',
		'nav_menu terms are emitted twice as <wp:term> (generic loop + wxr_nav_menu_terms()).',
		'Attachments: <wp:attachment_url> is wp_get_attachment_url(), i.e. the "-scaled" file for the 3000x2000 panorama; <guid> keeps the original upload URL; _wp_attachment_metadata (serialized) lists original_image and all sub-sizes.',
		'_oembed_<md5> postmeta ({{unknown}}) and _wp_old_slug, _thumbnail_id, _wp_page_template, _menu_item_* are exported as plain postmeta.',
		'The <pubDate> and the "created" generator comment change on every export; everything else is deterministic.',
	),
);

/* ---------------------------------------------------------------------------------------------
 * REST expectations (internal rest_do_request, same code path as HTTP)
 * ------------------------------------------------------------------------------------------- */

// Anonymous view of the password-protected post FIRST: the posts controller remembers password checks that an
// earlier context=edit admin request passed within the same PHP process (separate HTTP requests are not affected).
$sample_public = inv_rest( 'GET', '/wp/v2/posts/' . $manifest['ids']['post_recette_celadon'], array() );
$checks        = array(
	'anonymous' => array(
		'posts'      => inv_rest( 'GET', '/wp/v2/posts', array( 'per_page' => 100 ) ),
		'pages'      => inv_rest( 'GET', '/wp/v2/pages', array( 'per_page' => 100 ) ),
		'media'      => inv_rest( 'GET', '/wp/v2/media', array( 'per_page' => 100 ) ),
		'categories' => inv_rest( 'GET', '/wp/v2/categories', array( 'per_page' => 100 ) ),
		'tags'       => inv_rest( 'GET', '/wp/v2/tags', array( 'per_page' => 100 ) ),
		'users'      => inv_rest( 'GET', '/wp/v2/users', array( 'per_page' => 100 ) ),
		'comments'   => inv_rest( 'GET', '/wp/v2/comments', array( 'per_page' => 100 ) ),
		'menus'      => inv_rest( 'GET', '/wp/v2/menus', array() ),
		'blocks'     => inv_rest( 'GET', '/wp/v2/blocks', array() ),
		'navigation' => inv_rest( 'GET', '/wp/v2/navigation', array() ),
	),
	'admin'     => array(
		'posts_any'        => inv_rest( 'GET', '/wp/v2/posts', array( 'per_page' => 100, 'status' => 'any', 'context' => 'edit' ), 1 ),
		'pages_any'        => inv_rest( 'GET', '/wp/v2/pages', array( 'per_page' => 100, 'status' => 'any', 'context' => 'edit' ), 1 ),
		'users'            => inv_rest( 'GET', '/wp/v2/users', array( 'per_page' => 100, 'context' => 'edit' ), 1 ),
		'comments_all'     => inv_rest( 'GET', '/wp/v2/comments', array( 'per_page' => 100, 'status' => 'all', 'context' => 'edit' ), 1 ),
		'comments_hold'    => inv_rest( 'GET', '/wp/v2/comments', array( 'per_page' => 100, 'status' => 'hold' ), 1 ),
		'comments_spam'    => inv_rest( 'GET', '/wp/v2/comments', array( 'per_page' => 100, 'status' => 'spam' ), 1 ),
		'pingbacks'        => inv_rest( 'GET', '/wp/v2/comments', array( 'per_page' => 100, 'type' => 'pingback' ), 1 ),
		'menus'            => inv_rest( 'GET', '/wp/v2/menus', array( 'context' => 'edit' ), 1 ),
		'menu_items'       => inv_rest( 'GET', '/wp/v2/menu-items', array( 'per_page' => 100, 'context' => 'edit' ), 1 ),
		'menu_locations'   => inv_rest( 'GET', '/wp/v2/menu-locations', array(), 1 ),
		'blocks'           => inv_rest( 'GET', '/wp/v2/blocks', array( 'context' => 'edit' ), 1 ),
		'navigation'       => inv_rest( 'GET', '/wp/v2/navigation', array( 'context' => 'edit' ), 1 ),
		'settings'         => inv_rest( 'GET', '/wp/v2/settings', array(), 1 ),
	),
);
$rest = array();
foreach ( $checks as $who => $list ) {
	foreach ( $list as $name => $r ) {
		$row = array(
			'status' => $r['status'],
			'total'  => $r['total'] ?? ( is_array( $r['data'] ) && array_is_list( $r['data'] ) ? count( $r['data'] ) : null ),
		);
		if ( 200 === $r['status'] && is_array( $r['data'] ) && array_is_list( $r['data'] ) && isset( $r['data'][0]['id'] ) ) {
			$row['ids'] = array_map( 'intval', array_column( $r['data'], 'id' ) );
		}
		if ( 'menu_locations' === $name ) {
			$row['data'] = $r['data'];
		}
		$rest[ $who ][ $name ] = $row;
	}
}
$sample   = inv_rest( 'GET', '/wp/v2/posts/' . $manifest['ids']['post_emaillage'], array( 'context' => 'edit' ), 1 );
$settings = $checks['admin']['settings']['data'];
$inv['rest'] = array(
	'root'                => rest_url(),
	'auth'                => 'HTTP Basic with the application password: curl -u "admin:' . $app_pw['password'] . '" ...',
	'expected'            => $rest,
	'post_meta_field'     => $sample['data']['meta'] ?? null,
	'post_fields_edit_context' => array_keys( $sample['data'] ),
	'password_protected_public_view' => array(
		'content.protected' => $sample_public['data']['content']['protected'] ?? null,
		'content.rendered'  => $sample_public['data']['content']['rendered'] ?? null,
		'note'              => 'Pass ?password=celadon2026 to get the rendered content.',
	),
	'settings_subset'     => array_intersect_key( $settings, array_flip( array( 'title', 'description', 'url', 'timezone', 'date_format', 'time_format', 'start_of_week', 'language', 'posts_per_page', 'default_category', 'show_on_front', 'page_on_front', 'page_for_posts' ) ) ),
	'notes'               => array(
		'SEO (Yoast/Rank Math) and Elementor postmeta are NOT exposed by the REST API (protected "_" keys / unregistered meta, plugins not installed: no yoast_head / yoast_head_json either). Get them from the WXR, or register them yourself.',
		'The only registered post meta is "footnotes" (core), so meta = {"footnotes": ""}.',
		'Embeds render as plain links in content.rendered (oEmbed lookups are blocked); use content.raw (context=edit) for block markup.',
		'/wp/v2/comments lists only type=comment by default; pingbacks need ?type=pingback.',
		'Public /wp/v2/users only lists users with published posts.',
		'Anonymous GET /wp/v2/blocks answers [] while X-WP-Total says 1 (core: the published wp_block is counted, then filtered out by the per-item read permission check). Authenticated, it returns the pattern.',
		'Published wp_navigation posts are readable anonymously (/wp/v2/navigation).',
		'Private posts: anonymous visitors get plain ?p=ID permalinks; authenticated users get the pretty URL (core wp_force_plain_post_permalink()). Drafts and scheduled posts always have ?p=ID links.',
	),
);

/* ---------------------------------------------------------------------------------------------
 * Feature index + fingerprint
 * ------------------------------------------------------------------------------------------- */

foreach ( $features as $k => $v ) {
	$features[ $k ] = array_values( array_unique( $v ) );
	sort( $features[ $k ] );
}
ksort( $features );
$inv['features']            = $features;
$inv['content_fingerprint'] = array(
	'sha256' => inv_fingerprint(),
	'how'    => 'php ' . INV_BASE . '/inventory.php --fingerprint  (verify.sh checks it is unchanged after crawling the site)',
);
$groups = array(
	'users'       => '/^user_/',
	'categories'  => '/^cat_/',
	'tags'        => '/^tag_/',
	'media'       => '/^img_/',
	'pages'       => '/^page_/',
	'posts'       => '/^post_/',
	'synced_patterns' => '/^block_/',
	'navigation_posts' => '/^navigation_/',
	'comments'    => '/^comment_/',
	'menus'       => '/^menu_(principal|pied_de_page)$/',
	'menu_items'  => '/^menu_item_/',
);
$inv['ids'] = array();
foreach ( $groups as $group => $re ) {
	foreach ( $manifest['ids'] as $k => $v ) {
		if ( preg_match( $re, $k ) ) {
			$inv['ids'][ $group ][ preg_replace( '/^(user|cat|tag|img|page|post|block|navigation|comment|menu_item)_/', '', $k ) ] = $v;
		}
	}
}

file_put_contents( INV_BASE . '/fixture.json', wp_json_encode( $inv, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

/* =============================================================================================
 * FIXTURE.md
 * =========================================================================================== */

$by_id = array();
foreach ( array_merge( $inv['posts'], $inv['pages'] ) as $e ) {
	$by_id[ $e['id'] ] = $e;
}
$cat_name = wp_list_pluck( get_terms( array( 'taxonomy' => array( 'category', 'post_tag' ), 'hide_empty' => false ) ), 'name', 'term_id' );
$title    = function ( $id ) use ( $by_id, $wpdb ) {
	return $by_id[ $id ]['title'] ?? (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $id ) );
};
$md   = array();
$line = function ( $s = '' ) use ( &$md ) {
	$md[] = $s;
};
$list_ids = function ( array $ids ) use ( $title ) {
	return implode( ', ', array_map( fn( $i ) => "#{$i} " . $title( $i ), $ids ) );
};

$line( '# WordPress 7.1.4 test fixture — "Atelier Lumière"' );
$line();
$line( 'Realistic, reproducible WordPress site (French ceramics workshop in Lyon) used to test a WordPress → new-CMS migration tool, both from the **WXR export** and from the **live REST API**.' );
$line( 'Everything below is generated from the live database by `inventory.php`; the same data is available as machine-readable JSON in `fixture.json`.' );
$line();
$line( '## Quick start' );
$line();
$line( '| What | Value |' );
$line( '|---|---|' );
$line( '| Site URL | ' . home_url( '/' ) . ' |' );
$line( '| REST API root | ' . rest_url() . ' |' );
$line( '| Admin | ' . admin_url() . ' |' );
$line( '| (Re)start server | `/tmp/wp-test/start.sh` (idempotent; PHP built-in server in background, log `/tmp/wp-test/server.log`) |' );
$line( '| Rebuild everything from scratch | `/tmp/wp-test/build.sh` (wipes `site/`, reinstalls, reseeds, re-exports, regenerates this file, starts the server, runs `verify.sh`) |' );
$line( '| Stop server | `kill $(cat /tmp/wp-test/server.pid)` |' );
$line( '| Smoke tests | `/tmp/wp-test/verify.sh` (HTTP + REST + application password + “crawling does not modify content”) |' );
$line( '| WXR export | `/tmp/wp-test/export.xml` (regenerate: `php /tmp/wp-test/export.php`) |' );
$line( '| Machine-readable inventory | `/tmp/wp-test/fixture.json` |' );
$line( '| Seed script | `/tmp/wp-test/seed.php` (runs on a fresh install only) |' );
$line();
$line( '## Credentials' );
$line();
$line( '| User | Login | Password | Role | E-mail |' );
$line( '|---|---|---|---|---|' );
foreach ( array( 'admin', 'claire', 'lucas' ) as $k ) {
	$c = $inv['credentials'][ $k ];
	$u = array_values( array_filter( $inv['users'], fn( $x ) => $x['login'] === $c['login'] ) )[0];
	$line( "| {$u['display_name']} (#{$u['id']}) | `{$c['login']}` | `{$c['password']}` | {$c['role']} | {$c['email']} |" );
}
$line();
$line( '**Application password** (admin, name “' . $app_pw['name'] . '”): `' . $app_pw['password'] . '` (spaces optional: `' . $app_pw['raw'] . '`). It is fixed by the seed script, so it survives rebuilds.' );
$line();
$line( '```bash' );
$line( "curl -u 'admin:{$app_pw['password']}' '" . INV_URL . "/wp-json/wp/v2/posts?context=edit&status=any&per_page=100'" );
$line( '# header form: ' . $inv['credentials']['application_password']['basic_auth_header'] );
$line( '```' );
$line();
$line( 'Application Passwords normally require HTTPS: the mu-plugin `wp-content/mu-plugins/fixture-tweaks.php` adds `add_filter( \'wp_is_application_passwords_available\', \'__return_true\' )` (and disables outgoing e-mail).' );
$line();
$line( '## Environment' );
$line();
$line( '- WordPress ' . $inv['environment']['wordpress'] . ' (db_version ' . $inv['environment']['db_version'] . '), PHP ' . PHP_VERSION . ', theme **Twenty Twenty-Five** (block theme, no classic menu locations), language `en_US`, content in French, timezone `Europe/Paris` (dates in the DB are local; `*_gmt` columns are UTC, DST-aware).' );
$line( '- Database: **SQLite** through the official *SQLite Database Integration* plugin (' . ( $plugins['sqlite-database-integration/load.php']['Version'] ?? '?' ) . '). `wp-content/db.php` is the plugin\'s `db.copy` with `{SQLITE_IMPLEMENTATION_FOLDER_PATH}` and `{SQLITE_PLUGIN}` replaced exactly like the plugin\'s activation routine does; the plugin is also marked active. `DB_PATH` is pinned in `wp-config.php` to `' . DB_PATH . '` (instead of the plugin\'s randomized default path).' );
$line( '- Web server: `php -S 127.0.0.1:8081 -t /tmp/wp-test/site /tmp/wp-test/router.php` with 4 workers. `router.php` serves existing files directly, routes everything else to `index.php` (pretty permalinks, `/wp-json/`), and returns 403 for dotfiles (the `.ht.sqlite` database).' );
$line( '- Hermetic settings in `wp-config.php`: `WP_HTTP_BLOCK_EXTERNAL` (no outgoing HTTP: oEmbed lookups fail → embeds render as plain links, cached as `_oembed_<md5> = {{unknown}}`), `DISABLE_WP_CRON` (the scheduled post never auto-publishes), `AUTOMATIC_UPDATER_DISABLED`, `WP_DEBUG_LOG` → `/tmp/wp-test/debug.log` (display off).' );
$line( '- wp-admin works (log in as `admin`), but note: opening the Dashboard creates an auto-draft (Quick Draft box) and wp-admin visits trigger update checks that fail (external HTTP blocked) and log PHP warnings in `debug.log`. Auto-drafts are ignored by WXR, REST and the fingerprint; run `build.sh` to get a pristine fixture again.' );
$line( '- `server.log` contains harmless “Failed to poll event” lines: PHP\'s built-in server prints them when several workers wait for the same connection.' );
$line( '- Determinism: fixed dates (post/modified/comment/registration), fixed upload folders (`2026/01`…`2026/06`), GD-generated images (fixed pixels), fixed application password. Browsing or calling the REST API does not change content tables: `verify.sh` checks the content fingerprint `' . substr( $inv['content_fingerprint']['sha256'], 0, 16 ) . '…` before/after a crawl.' );
$line();
$line( '## Inventory summary' );
$line();
$line( '| Type | Count | Detail |' );
$line( '|---|---|---|' );
$fmt_status = fn( array $s ) => implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( array_diff_key( $s, array( 'total' => 1 ) ) ), array_diff_key( $s, array( 'total' => 1 ) ) ) );
$line( '| Users | ' . $inv['counts']['users'] . ' | administrator, editor, author |' );
$line( '| Posts | ' . $inv['counts']['posts']['total'] . ' | ' . $fmt_status( $inv['counts']['posts'] ) . ' (publish includes 1 sticky and 1 password-protected) |' );
$line( '| Pages | ' . $inv['counts']['pages']['total'] . ' | ' . $fmt_status( $inv['counts']['pages'] ) . ' (1 child page, 1 Elementor page) |' );
$line( '| Media (attachments) | ' . $inv['counts']['attachments'] . ' | ' . $inv['counts']['attachment_files_on_disk'] . ' files on disk incl. WordPress sub-sizes and one `-scaled` image |' );
$line( '| Categories | ' . $inv['counts']['categories'] . ' | incl. default “Uncategorized”, 2 levels |' );
$line( '| Tags | ' . $inv['counts']['tags'] . ' | |' );
$line( '| Comments | ' . $comment_stats['total'] . ' | approved: ' . $comment_stats['approved'] . ' (incl. 1 pingback), pending: ' . $comment_stats['hold'] . ', spam: ' . $comment_stats['spam'] . ' |' );
$line( '| Nav menus (classic) | ' . $inv['counts']['menus'] . ' | ' . $inv['counts']['menu_items'] . ' items |' );
$line( '| Synced patterns (`wp_block`) | ' . $inv['counts']['synced_patterns'] . ' | |' );
$line( '| Navigation posts (`wp_navigation`) | ' . $inv['counts']['navigation_posts'] . ' | generated by WordPress from “Menu principal” |' );
$line( '| Application passwords | 1 | admin |' );
$line();
$line( '## Users' );
$line();
$line( '| ID | Login / slug | Display name | Role | Published posts | Bio |' );
$line( '|---|---|---|---|---|---|' );
foreach ( $inv['users'] as $u ) {
	$line( "| {$u['id']} | `{$u['login']}` / `{$u['slug']}` | {$u['display_name']} | {$u['role']} | {$u['posts']['publish']} (+ draft {$u['posts']['draft']}, future {$u['posts']['future']}, private {$u['posts']['private']}) | " . inv_md_escape( $u['description'] ) . ' |' );
}
$line();
$line( '## Taxonomies' );
$line();
$line( '| ID | Category | Slug | Parent | Posts | URL path | Description |' );
$line( '|---|---|---|---|---|---|---|' );
foreach ( $inv['categories'] as $t ) {
	$line( "| {$t['id']} | {$t['name']} | `{$t['slug']}` | " . ( $t['parent_slug'] ? "`{$t['parent_slug']}`" : '—' ) . " | {$t['count']} | `" . inv_rel( $t['url'] ) . '` | ' . inv_md_escape( $t['description'] ) . ' |' );
}
$line();
$line( '| ID | Tag | Slug | Posts | Description |' );
$line( '|---|---|---|---|---|' );
foreach ( $inv['tags'] as $t ) {
	$line( "| {$t['id']} | {$t['name']} | `{$t['slug']}` | {$t['count']} | " . inv_md_escape( $t['description'] ) . ' |' );
}
$line();
$line( 'Accented names get accent-free slugs (`émail` → `email`, `grès` → `gres`, `Émaillage` → `emaillage`). Post format terms: ' . implode( ', ', array_map( fn( $t ) => "`{$t['slug']}` (#{$t['id']}, {$t['count']} post)", $inv['post_formats'] ) ) . '.' );
$line();
$line( '## Media' );
$line();
$line( '| ID | File (`_wp_attached_file`) | Size | Attached to | Title / alt / caption | Notable sub-size |' );
$line( '|---|---|---|---|---|---|' );
foreach ( $inv['media'] as $a ) {
	$medium = $a['sizes']['medium']['file'] ?? '';
	$line( "| {$a['id']} | `{$a['attached_file']}` | {$a['width']}×{$a['height']} {$a['mime_type']} | " . ( $a['parent'] ? "#{$a['parent']}" : 'unattached' ) . ' | ' . inv_md_escape( "{$a['title']} / alt: {$a['alt']} / caption: {$a['caption']}" ) . " | `" . basename( $medium ) . '` |' );
}
$line();
$line( 'Uploads live in `' . $inv['paths']['uploads'] . '` and are served at `' . INV_URL . '/wp-content/uploads/…`. Sub-sizes: thumbnail 150×150 (crop), medium ≤300, medium_large 768w, large ≤1024, 1536×1536, 2048×2048 (only when the source is bigger).' );
$pano = array_values( array_filter( $inv['media'], fn( $a ) => 'img_panorama' === $a['key'] ) )[0];
$line( 'The panorama (#' . $pano['id'] . ') is 3000×2000, above `big_image_size_threshold` (2560): WordPress keeps the original (`original_image` in the metadata, URL `' . inv_rel( $pano['original_image_url'] ) . '`) and serves `' . inv_rel( $pano['url'] ) . '` as the attachment URL (`_wp_attached_file` = `' . $pano['attached_file'] . '`).' );
$line();
$line( '## Posts' );
$line();
$line( '| ID | Slug | Title | Status | Author | Date (Paris) | Categories | Tags | Featured | Editor |' );
$line( '|---|---|---|---|---|---|---|---|---|---|' );
foreach ( $inv['posts'] as $p ) {
	$st = $p['status'] . ( $p['sticky'] ? ', **sticky**' : '' ) . ( $p['password'] ? ', **password**' : '' );
	$line( "| {$p['id']} | `" . ( '' === $p['slug'] ? '(empty)' : $p['slug'] ) . '` | ' . inv_md_escape( $p['title'] ) . " | {$st} | {$p['author']} | {$p['date']} | " . implode( ', ', $p['categories'] ) . ' | ' . implode( ', ', $p['tags'] ) . ' | ' . ( $p['featured_media'] ? '#' . $p['featured_media'] : '—' ) . " | {$p['editor']} |" );
}
$line();
$line( '### Post details (special features)' );
$line();
foreach ( $inv['posts'] as $p ) {
	$line( "#### #{$p['id']} — {$p['title']}" );
	$line();
	$line( "- URL: {$p['url']} · REST: `{$p['rest_route']}` · status `{$p['status']}` · author `{$p['author']}` · date {$p['date']} (GMT {$p['date_gmt']}) · modified {$p['modified']}" );
	foreach ( $p['notes'] as $n ) {
		$line( '- ' . $n );
	}
	if ( $p['blocks'] ) {
		$line( '- Blocks: ' . implode( ', ', array_map( fn( $k, $v ) => "`{$k}`×{$v}", array_keys( $p['blocks'] ), $p['blocks'] ) ) );
	}
	if ( $p['shortcode_tags'] ) {
		$line( '- Shortcodes: ' . implode( ' ', array_map( fn( $s ) => "`{$s}`", $p['shortcode_tags'] ) ) );
	}
	if ( $p['bare_urls_on_own_line'] ) {
		$line( '- URL alone on its own line (oEmbed candidate): ' . implode( ', ', $p['bare_urls_on_own_line'] ) );
	}
	if ( $p['excerpt_raw'] ) {
		$line( '- Custom excerpt: “' . $p['excerpt_raw'] . '”' );
	}
	if ( $p['images_in_content'] ) {
		$line( '- Images in content: ' . implode( ', ', array_map( fn( $i ) => '`' . inv_rel( $i['src'] ) . '` (#' . $i['attachment_id'] . ' ' . $i['size'] . ')', $p['images_in_content'] ) ) );
	}
	if ( $p['internal_links'] ) {
		$line( '- Internal links: ' . implode( ', ', array_map( fn( $l ) => '`' . inv_rel( $l['url'] ) . '` → ' . $l['type'] . ( isset( $l['id'] ) ? ' #' . $l['id'] : '' ), $p['internal_links'] ) ) );
	}
	if ( $p['seo'] ) {
		$line( '- SEO (' . ( 'yoast' === $p['seo']['plugin'] ? 'Yoast' : 'Rank Math' ) . ' postmeta): ' . implode( '; ', array_map( fn( $k, $v ) => "`{$k}` = `" . ( is_array( $v ) ? wp_json_encode( $v ) . ' (serialized array)' : $v ) . '`', array_keys( $p['seo']['meta'] ), $p['seo']['meta'] ) ) );
	}
	if ( $p['comments'] ) {
		$line( '- Comments: ' . implode( ', ', array_map( fn( $k, $v ) => "{$k}: {$v}", array_keys( $p['comments'] ), $p['comments'] ) ) );
	}
	$line( '- Other postmeta: ' . ( $p['other_meta'] ? implode( ', ', array_map( fn( $k ) => "`{$k}`", array_keys( $p['other_meta'] ) ) ) : '—' ) );
	$line();
}
$line( '## Pages' );
$line();
$line( '| ID | Path | Title | Parent | Order | Author | Editor | Role |' );
$line( '|---|---|---|---|---|---|---|---|' );
foreach ( $inv['pages'] as $p ) {
	$role = array();
	if ( $p['is_front_page'] ) {
		$role[] = '**static front page** (`page_on_front`; `/accueil/` 301 → `/`)';
	}
	if ( $p['is_posts_page'] ) {
		$role[] = '**posts page** (`page_for_posts`)';
	}
	if ( $p['is_privacy_page'] ) {
		$role[] = 'privacy policy page';
	}
	if ( 'elementor' === $p['editor'] ) {
		$role[] = '**Elementor** (template `' . $p['template'] . '`)';
	}
	$line( "| {$p['id']} | `{$p['path']}` | {$p['title']} | " . ( $p['parent'] ? "#{$p['parent']}" : '—' ) . " | {$p['menu_order']} | {$p['author']} | {$p['editor']} | " . implode( '; ', $role ) . ' |' );
}
$line();
foreach ( $inv['pages'] as $p ) {
	$line( "#### Page #{$p['id']} — {$p['title']}" );
	$line();
	foreach ( $p['notes'] as $n ) {
		$line( '- ' . $n );
	}
	if ( $p['blocks'] ) {
		$line( '- Blocks: ' . implode( ', ', array_map( fn( $k, $v ) => "`{$k}`×{$v}", array_keys( $p['blocks'] ), $p['blocks'] ) ) );
	}
	if ( $p['shortcode_tags'] ) {
		$line( '- Shortcodes: ' . implode( ' ', array_map( fn( $s ) => "`{$s}`", $p['shortcode_tags'] ) ) );
	}
	if ( $p['seo'] ) {
		$line( '- SEO (' . ( 'yoast' === $p['seo']['plugin'] ? 'Yoast' : 'Rank Math' ) . ' postmeta): ' . implode( '; ', array_map( fn( $k, $v ) => "`{$k}` = `" . ( is_array( $v ) ? wp_json_encode( $v ) . ' (serialized array)' : $v ) . '`', array_keys( $p['seo']['meta'] ), $p['seo']['meta'] ) ) );
	}
	if ( ! empty( $p['elementor'] ) ) {
		$e = $p['elementor'];
		$line( '- Elementor postmeta: ' . implode( ', ', array_map( fn( $k, $v ) => "`{$k}` = `" . ( is_array( $v ) ? wp_json_encode( $v, JSON_UNESCAPED_SLASHES ) : $v ) . '`', array_keys( $p['elementor_meta'] ), $p['elementor_meta'] ) ) );
		$line( '- `_elementor_data`: ' . $e['top_level_elements'] . ' top-level elements; element types ' . wp_json_encode( $e['element_types'] ) . '; widgets ' . wp_json_encode( $e['widget_types'] ) . '; images used: ' . implode( ', ', array_map( fn( $i ) => "#{$i}", $e['images'] ) ) . '; video `' . $e['youtube_url'] . '`.' );
		$line( '- ' . $e['raw_storage_note'] );
		$line( '- `post_content` is only Elementor\'s plain-HTML fallback (headings, paragraphs, links, `<figure>`, `<ul>`, the bare YouTube URL…), not the real layout.' );
	}
	$line( '- Other postmeta: ' . ( $p['other_meta'] ? implode( ', ', array_map( fn( $k ) => "`{$k}`", array_keys( $p['other_meta'] ) ) ) : '—' ) );
	$line();
}
$line( '## Synced pattern and navigation posts' );
$line();
foreach ( array_merge( $inv['synced_patterns'], $inv['navigation_posts'] ) as $x ) {
	$line( "- #{$x['id']} `{$x['type']}` “{$x['title']}” (slug `{$x['slug']}`, REST `{$x['rest_route']}`): blocks " . wp_json_encode( $x['blocks'] ) . ( $x['referenced_by'] ? '; referenced by ' . $list_ids( $x['referenced_by'] ) : '' ) . '. ' . implode( ' ', $x['notes'] ) );
}
$line();
$line( '## Comments' );
$line();
$line( '| ID | Post | Parent | Depth | Type | Status | Author | User | Date | In WXR | Public REST |' );
$line( '|---|---|---|---|---|---|---|---|---|---|---|' );
foreach ( $inv['comments'] as $c ) {
	$line( "| {$c['id']} | #{$c['post_id']} `{$c['post_slug']}` | " . ( $c['parent'] ?: '—' ) . " | {$c['depth']} | {$c['type']} | {$c['status']} (`{$c['comment_approved_raw']}`) | " . inv_md_escape( $c['author'] ) . ' | ' . ( $c['user_login'] ? "`{$c['user_login']}`" : '—' ) . " | {$c['date']} | " . ( $c['in_wxr'] ? 'yes' : '**no**' ) . ' | ' . ( $c['public_in_rest'] ? ( 'pingback' === $c['type'] ? 'with `?type=pingback`' : 'yes' ) : 'auth only' ) . ' |' );
}
$line();
$line( 'Comment tree:' );
$line();
$line( '```' );
$children = array();
foreach ( $inv['comments'] as $c ) {
	$children[ $c['post_id'] ][ $c['parent'] ][] = $c;
}
foreach ( $children as $post_id => $tree ) {
	$line( "#{$post_id} " . $title( $post_id ) );
	$print = function ( $parent, $indent ) use ( &$print, $tree, $line ) {
		foreach ( $tree[ $parent ] ?? array() as $c ) {
			$line( str_repeat( '   ', $indent ) . "└─ #{$c['id']} {$c['author']} [{$c['type']}, {$c['status']}] " . mb_strimwidth( str_replace( "\n", ' ', $c['content'] ), 0, 60, '…' ) );
			$print( $c['id'], $indent + 1 );
		}
	};
	$print( 0, 1 );
}
$line( '```' );
$line();
$line( '## Menus' );
$line();
foreach ( $inv['menus'] as $m ) {
	$line( "### “{$m['name']}” (nav_menu term #{$m['id']}, slug `{$m['slug']}`, {$m['count']} items, location: " . ( $m['locations'] ? implode( ', ', $m['locations'] ) : 'none' ) . ')' );
	$line();
	$line( '```' );
	$by_parent = array();
	foreach ( $m['items'] as $it ) {
		$by_parent[ $it['parent_item'] ][] = $it;
	}
	$print = function ( $parent, $indent ) use ( &$print, $by_parent, $line ) {
		foreach ( $by_parent[ $parent ] ?? array() as $it ) {
			$extra = array();
			if ( $it['stored_title'] ) {
				$extra[] = 'custom title';
			}
			if ( $it['target'] ) {
				$extra[] = 'target=' . $it['target'];
			}
			if ( $it['classes'] ) {
				$extra[] = 'classes=' . implode( ' ', $it['classes'] );
			}
			if ( $it['xfn'] ) {
				$extra[] = 'rel=' . $it['xfn'];
			}
			if ( $it['attr_title'] ) {
				$extra[] = 'title attr';
			}
			if ( $it['description'] ) {
				$extra[] = 'description';
			}
			$obj = 'custom' === $it['type'] ? 'custom' : "{$it['type']}:{$it['object']} #{$it['object_id']}";
			$line( str_repeat( '  ', $indent ) . "- [{$it['position']}] item #{$it['id']} “{$it['title']}” → {$it['url']} ({$obj})" . ( $extra ? ' [' . implode( ', ', $extra ) . ']' : '' ) );
			$print( $it['id'], $indent + 1 );
		}
	};
	$print( 0, 0 );
	$line( '```' );
	$line();
}
$line( 'Twenty Twenty-Five registers **no classic menu locations**, so neither menu is assigned to a location (`/wp/v2/menu-locations` returns `[]`). The theme header’s Navigation block uses `wp_navigation` #' . $manifest['ids']['navigation_menu_principal'] . ', which WordPress’ own `WP_Classic_To_Block_Menu_Converter` generated from “Menu principal” during seeding (WordPress would otherwise create it lazily on the first front-end visit). Note: the `post_parent` column of `nav_menu_item` posts holds the parent of the *linked object* (core behaviour); the menu hierarchy is in `_menu_item_menu_item_parent`.' );
$line();
$line( '## Feature index (for assertions)' );
$line();
$line( '| Feature | IDs |' );
$line( '|---|---|' );
foreach ( $features as $f => $list ) {
	$line( "| `{$f}` | " . $list_ids( $list ) . ' |' );
}
$line();
$line( '## WXR export (`export.xml`)' );
$line();
$line( '- Produced by WordPress itself: `export_wp( array( \'content\' => \'all\' ) )` (same as Tools → Export → All content), WXR ' . $inv['wxr']['wxr_version'] . ', ' . number_format( $inv['wxr']['bytes'] ) . ' bytes, base URL ' . $inv['wxr']['base_site_url'] . '.' );
$line( '- ' . $inv['wxr']['authors'] . ' `<wp:author>`, ' . $inv['wxr']['category_elements'] . ' `<wp:category>`, ' . $inv['wxr']['tag_elements'] . ' `<wp:tag>`, `<wp:term>` by taxonomy: ' . wp_json_encode( $inv['wxr']['term_elements_by_taxonomy'] ) . '.' );
$line( '- ' . $inv['wxr']['items_total'] . ' `<item>` by type/status: ' . wp_json_encode( $inv['wxr']['items_by_type_and_status'] ) . '.' );
$line( '- ' . $inv['wxr']['comments_total'] . ' `<wp:comment>` (IDs ' . implode( ', ', $inv['wxr']['comment_ids'] ) . '); spam comment(s) ' . implode( ', ', $inv['wxr']['excluded']['spam_comment_ids'] ) . ' are **not** exported.' );
$line( '- ' . $inv['wxr']['excluded']['note'] );
foreach ( $inv['wxr']['quirks'] as $q ) {
	$line( '- ' . $q );
}
$line();
$line( '## REST API (`' . rest_url() . '`)' );
$line();
$line( '| Request | As | HTTP | X-WP-Total / count | IDs |' );
$line( '|---|---|---|---|---|' );
$req_label = array(
	'posts'            => 'GET /wp/v2/posts',
	'pages'            => 'GET /wp/v2/pages',
	'media'            => 'GET /wp/v2/media',
	'categories'       => 'GET /wp/v2/categories',
	'tags'             => 'GET /wp/v2/tags',
	'users'            => 'GET /wp/v2/users',
	'comments'         => 'GET /wp/v2/comments',
	'menus'            => 'GET /wp/v2/menus',
	'blocks'           => 'GET /wp/v2/blocks',
	'navigation'       => 'GET /wp/v2/navigation',
	'posts_any'        => 'GET /wp/v2/posts?status=any&context=edit',
	'pages_any'        => 'GET /wp/v2/pages?status=any&context=edit',
	'comments_all'     => 'GET /wp/v2/comments?status=all',
	'comments_hold'    => 'GET /wp/v2/comments?status=hold',
	'comments_spam'    => 'GET /wp/v2/comments?status=spam',
	'pingbacks'        => 'GET /wp/v2/comments?type=pingback',
	'menu_items'       => 'GET /wp/v2/menu-items',
	'menu_locations'   => 'GET /wp/v2/menu-locations',
	'settings'         => 'GET /wp/v2/settings',
);
foreach ( $rest as $who => $list ) {
	foreach ( $list as $name => $r ) {
		$line( '| `' . $req_label[ $name ] . '` | ' . ( 'admin' === $who ? 'admin (app password)' : 'anonymous' ) . " | {$r['status']} | " . ( null === $r['total'] ? '—' : $r['total'] ) . ' | ' . ( isset( $r['ids'] ) ? implode( ', ', $r['ids'] ) : '' ) . ' |' );
	}
}
$line();
foreach ( $inv['rest']['notes'] as $n ) {
	$line( '- ' . $n );
}
$line( '- Password-protected post #' . $manifest['ids']['post_recette_celadon'] . ' anonymously: `content.protected = ' . wp_json_encode( $inv['rest']['password_protected_public_view']['content.protected'] ) . '`, `content.rendered = ' . wp_json_encode( $inv['rest']['password_protected_public_view']['content.rendered'] ) . '`. ' . $inv['rest']['password_protected_public_view']['note'] );
$line( '- Draft #' . $manifest['ids']['post_brouillon_horaires'] . ' has `slug: ""`; with `context=edit` the REST API also returns `generated_slug`.' );
$line( '- `_embed` works (author, featured media, terms): e.g. `' . INV_URL . '/wp-json/wp/v2/posts?_embed`.' );
$line();
$line( '## `fixture.json` layout' );
$line();
$line( 'Top-level keys: ' . implode( ', ', array_map( fn( $k ) => "`{$k}`", array_keys( $inv ) ) ) . '.' );
$line();
$line( '- `ids`: symbolic name → ID, grouped (`ids.posts.emaillage`, `ids.pages.nos_ateliers`, `ids.media.panorama`, `ids.comments.spam`, `ids.menu_items.main_boutique`…).' );
$line( '- `posts[]` / `pages[]`: one entry per post/page with slug, status, dates (local + GMT), URL, author, terms, featured media, block counts, shortcodes, internal links (resolved to IDs), images (resolved to attachment + size), SEO meta, other meta keys, comment counts, curated `notes`.' );
$line( '- `features`: feature name → list of IDs (same as the table above). `wxr`: counts parsed from export.xml. `rest.expected`: status code, `X-WP-Total` and IDs for each endpoint, anonymous and as admin.' );
$line( '- `content_fingerprint.sha256`: hash of the content tables; `php /tmp/wp-test/inventory.php --fingerprint` recomputes it.' );
$line();
$line( '## Things that are intentionally *not* there' );
$line();
$line( '- Yoast SEO, Rank Math and Elementor are **not installed**: their data exists only as postmeta / block markup, exactly as a migration source would contain it. The `yoast/faq-block` therefore renders its saved HTML as-is on the front end.' );
$line( '- No WooCommerce, no custom post types, no ACF fields, no multisite, no revisions, no trashed content.' );
$cf7_clash = get_post( 12 );
$line( '- `[contact-form-7 id="12" title="Contact"]` refers to a form that does not exist (Contact Form 7 not installed); the shortcode is printed verbatim.' . ( $cf7_clash ? ' Post ID 12 happens to be the ' . $cf7_clash->post_type . ' “' . $cf7_clash->post_title . '” — do not resolve the shortcode id as a post ID.' : '' ) );
$line();

file_put_contents( INV_BASE . '/FIXTURE.md', implode( "\n", $md ) . "\n" );
printf( "Wrote %s and %s (fingerprint %s)\n", INV_BASE . '/fixture.json', INV_BASE . '/FIXTURE.md', substr( $inv['content_fingerprint']['sha256'], 0, 16 ) );
