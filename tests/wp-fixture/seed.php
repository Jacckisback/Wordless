<?php
/**
 * Seed the "Atelier Lumière" WordPress 7.1.4 fixture with realistic French content.
 *
 * Usage: php /tmp/wp-test/seed.php
 *
 * Must run on a FRESH install (build.sh: copy WordPress -> install.php -> seed.php). It refuses to
 * run twice on the same database: rebuild with /tmp/wp-test/build.sh instead. Everything is
 * deterministic (fixed dates, fixed upload folders, fixed image pixels, fixed application password),
 * so a rebuild produces the same IDs, slugs, URLs and files.
 *
 * Creates: 3 users, categories (nested) + tags, 5 generated images (+ WordPress sub-sizes, one "-scaled"),
 * 11 posts (Gutenberg / classic / Yoast FAQ / password / draft / future / private / sticky...),
 * 7 pages (static front page, posts page, child page, Elementor page...), 1 synced pattern (wp_block),
 * comments (threads, pending, spam, pingback), 2 classic menus + the block-theme wp_navigation
 * WordPress derives from the main menu, and an Application Password for admin.
 * Writes /tmp/wp-test/.seed-manifest.json (symbolic name => ID + curated feature notes) for inventory.php.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Behave like an importer: no "_pingme"/"_encloseme" post meta and no "do_pings" cron event on publish.
define( 'WP_IMPORTING', true );

$_SERVER['HTTP_HOST']      = '127.0.0.1:8081';
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['SERVER_PORT']    = '8081';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require __DIR__ . '/site/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

const FX_URL         = 'http://127.0.0.1:8081';
const FX_MANIFEST    = __DIR__ . '/.seed-manifest.json';
const FX_APP_PW      = 'FixtAtelLumi2026RestApiK'; // 24 chars, shown chunked as "Fixt Atel Lumi 2026 Rest ApiK".
const FX_APP_PW_NAME = 'Migration fixture (REST import)';
const FX_YOUTUBE     = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

if ( username_exists( 'claire' ) ) {
	fwrite( STDERR, "This site is already seeded. Rebuild from scratch with /tmp/wp-test/build.sh\n" );
	exit( 1 );
}

// Act as the administrator: unfiltered_html (no kses stripping of iframes/attributes), like content written in wp-admin.
wp_set_current_user( 1 );
// No revisions: the fixture represents the final state of each post.
add_filter( 'wp_revisions_to_keep', '__return_zero' );

$GLOBALS['fx_manifest'] = array(
	'ids'   => array(),
	'notes' => array(),
);

/* ---------------------------------------------------------------------------------------------
 * Small helpers
 * ------------------------------------------------------------------------------------------- */

function fx_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

/** Abort on WP_Error / falsy results. */
function fx_ok( $result, $what ) {
	if ( is_wp_error( $result ) ) {
		fx_fail( $what . ': ' . $result->get_error_message() );
	}
	if ( ! $result ) {
		fx_fail( $what . ': failed' );
	}
	return $result;
}

/** Record a symbolic name => ID (+ curated notes) in the manifest used by inventory.php. */
function fx_remember( $key, $id, array $notes = array() ) {
	$GLOBALS['fx_manifest']['ids'][ $key ] = (int) $id;
	if ( $notes ) {
		$GLOBALS['fx_manifest']['notes'][ $key ] = $notes;
	}
	return (int) $id;
}

function fx_id( $key ) {
	if ( ! isset( $GLOBALS['fx_manifest']['ids'][ $key ] ) ) {
		fx_fail( "unknown fixture key {$key}" );
	}
	return $GLOBALS['fx_manifest']['ids'][ $key ];
}

function fx_url( $path = '/' ) {
	return FX_URL . $path;
}

/** Escape an HTML attribute value the way the block editor serializer does (& and " only). */
function fx_attr( $value ) {
	return str_replace( array( '&', '"' ), array( '&amp;', '&quot;' ), $value );
}

function fx_gmt( $local ) {
	return get_gmt_from_date( $local );
}

/* ---------------------------------------------------------------------------------------------
 * Block markup helpers - output matches the block editor's serializer for WordPress 7.1
 * (comment delimiters with JSON attributes escaped by serialize_block_attributes(), "\n" around
 * the inner HTML, inner blocks joined with "\n\n").
 * ------------------------------------------------------------------------------------------- */

function fx_blk( $name, array $attrs = array(), $html = '' ) {
	$name = strip_core_block_namespace( $name );
	$json = $attrs ? serialize_block_attributes( $attrs ) . ' ' : '';
	if ( '' === $html ) {
		return "<!-- wp:{$name} {$json}/-->";
	}
	return "<!-- wp:{$name} {$json}-->\n{$html}\n<!-- /wp:{$name} -->";
}

function fx_join( array $blocks ) {
	return implode( "\n\n", $blocks );
}

function fx_p( $html ) {
	return fx_blk( 'core/paragraph', array(), "<p>{$html}</p>" );
}

function fx_h( $html, $level = 2, $anchor = '' ) {
	$attrs = 2 === $level ? array() : array( 'level' => $level );
	$id    = $anchor ? ' id="' . fx_attr( $anchor ) . '"' : '';
	return fx_blk( 'core/heading', $attrs, "<h{$level} class=\"wp-block-heading\"{$id}>{$html}</h{$level}>" );
}

/** $items: strings, or array( 'html', array( nested items ) ). */
function fx_list( array $items, $ordered = false ) {
	$lis = array();
	foreach ( $items as $item ) {
		if ( is_array( $item ) ) {
			$lis[] = fx_blk( 'core/list-item', array(), '<li>' . $item[0] . fx_list( $item[1] ) . '</li>' );
		} else {
			$lis[] = fx_blk( 'core/list-item', array(), "<li>{$item}</li>" );
		}
	}
	$tag = $ordered ? 'ol' : 'ul';
	return fx_blk( 'core/list', $ordered ? array( 'ordered' => true ) : array(), "<{$tag} class=\"wp-block-list\">" . fx_join( $lis ) . "</{$tag}>" );
}

function fx_quote( array $paragraphs, $cite ) {
	$inner = fx_join( array_map( 'fx_p', $paragraphs ) );
	return fx_blk( 'core/quote', array(), '<blockquote class="wp-block-quote">' . $inner . '<cite>' . $cite . '</cite></blockquote>' );
}

function fx_table( array $head, array $rows, $caption = '' ) {
	$html  = '<figure class="wp-block-table"><table class="has-fixed-layout">';
	$html .= '<thead><tr><th>' . implode( '</th><th>', $head ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr><td>' . implode( '</td><td>', $row ) . '</td></tr>';
	}
	$html .= '</tbody></table>';
	if ( $caption ) {
		$html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
	}
	$html .= '</figure>';
	return fx_blk( 'core/table', array(), $html );
}

/** core/image referencing an attachment. $opt: align, link ('none'|'media'). */
function fx_image( $id, $size = 'large', $caption = '', array $opt = array() ) {
	$url   = wp_get_attachment_image_url( $id, $size );
	$alt   = get_post_meta( $id, '_wp_attachment_image_alt', true );
	$link  = $opt['link'] ?? 'none';
	$attrs = array(
		'id'              => $id,
		'sizeSlug'        => $size,
		'linkDestination' => $link,
	);
	$class = 'wp-block-image';
	if ( ! empty( $opt['align'] ) ) {
		$attrs['align'] = $opt['align'];
		$class         .= ' align' . $opt['align'];
	}
	$class .= ' size-' . $size;
	$img    = '<img src="' . fx_attr( $url ) . '" alt="' . fx_attr( $alt ) . '" class="wp-image-' . $id . '"/>';
	if ( 'media' === $link ) {
		$img = '<a href="' . fx_attr( wp_get_attachment_url( $id ) ) . '">' . $img . '</a>';
	}
	$fig = '<figure class="' . $class . '">' . $img;
	if ( $caption ) {
		$fig .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
	}
	$fig .= '</figure>';
	return fx_blk( 'core/image', $attrs, $fig );
}

function fx_gallery( array $ids, $columns, $caption = '' ) {
	$images = array();
	foreach ( $ids as $id ) {
		$images[] = fx_image( $id, 'large' );
	}
	$html = '<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped">' . fx_join( $images );
	if ( $caption ) {
		$html .= '<figcaption class="blocks-gallery-caption wp-element-caption">' . $caption . '</figcaption>';
	}
	$html .= '</figure>';
	return fx_blk(
		'core/gallery',
		array(
			'columns' => $columns,
			'linkTo'  => 'none',
		),
		$html
	);
}

/** $columns: list of arrays of block strings. */
function fx_columns( array $columns ) {
	$cols = array();
	foreach ( $columns as $blocks ) {
		$cols[] = fx_blk( 'core/column', array(), '<div class="wp-block-column">' . fx_join( $blocks ) . '</div>' );
	}
	return fx_blk( 'core/columns', array(), '<div class="wp-block-columns">' . fx_join( $cols ) . '</div>' );
}

/** $buttons: list of array( text, url, className|'' ). */
function fx_buttons( array $buttons ) {
	$out = array();
	foreach ( $buttons as $b ) {
		$class = empty( $b[2] ) ? '' : ' ' . $b[2];
		$out[] = fx_blk(
			'core/button',
			empty( $b[2] ) ? array() : array( 'className' => $b[2] ),
			'<div class="wp-block-button' . $class . '"><a class="wp-block-button__link wp-element-button" href="' . fx_attr( $b[1] ) . '">' . $b[0] . '</a></div>'
		);
	}
	return fx_blk( 'core/buttons', array(), '<div class="wp-block-buttons">' . fx_join( $out ) . '</div>' );
}

function fx_embed_youtube( $url, $caption = '' ) {
	$html = '<figure class="wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">' . "\n" . $url . "\n" . '</div>';
	if ( $caption ) {
		$html .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
	}
	$html .= '</figure>';
	return fx_blk(
		'core/embed',
		array(
			'url'              => $url,
			'type'             => 'video',
			'providerNameSlug' => 'youtube',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
		),
		$html
	);
}

function fx_separator() {
	return fx_blk( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
}

function fx_html( $html ) {
	return fx_blk( 'core/html', array(), $html );
}

function fx_more() {
	return fx_blk( 'core/more', array(), '<!--more-->' );
}

function fx_shortcode_block( $shortcode ) {
	return fx_blk( 'core/shortcode', array(), $shortcode );
}

function fx_block_ref( $ref ) {
	return fx_blk( 'core/block', array( 'ref' => $ref ) );
}

/** core/cover with an attachment background (large size) and inner blocks. */
function fx_cover( $id, array $inner_blocks, $overlay, $dim, $min_height, $unit = 'px' ) {
	$url   = wp_get_attachment_image_url( $id, 'large' );
	$attrs = array(
		'url'                => $url,
		'id'                 => $id,
		'dimRatio'           => $dim,
		'customOverlayColor' => $overlay,
		'isUserOverlayColor' => true,
		'minHeight'          => $min_height,
		'minHeightUnit'      => $unit,
		'sizeSlug'           => 'large',
		'layout'             => array( 'type' => 'constrained' ),
	);
	$dim_class = ( 50 === $dim ) ? '' : ' has-background-dim-' . ( 10 * round( $dim / 10 ) );
	$html      = '<div class="wp-block-cover" style="min-height:' . $min_height . $unit . '">'
		. '<img class="wp-block-cover__image-background wp-image-' . $id . ' size-large" alt="" src="' . fx_attr( $url ) . '" data-object-fit="cover"/>'
		. '<span aria-hidden="true" class="wp-block-cover__background' . $dim_class . ' has-background-dim" style="background-color:' . $overlay . '"></span>'
		. '<div class="wp-block-cover__inner-container">' . fx_join( $inner_blocks ) . '</div></div>';
	return fx_blk( 'core/cover', $attrs, $html );
}

function fx_media_text( $id, array $inner_blocks ) {
	$url   = wp_get_attachment_image_url( $id, 'large' );
	$alt   = get_post_meta( $id, '_wp_attachment_image_alt', true );
	$attrs = array(
		'mediaId'       => $id,
		'mediaLink'     => get_attachment_link( $id ),
		'mediaType'     => 'image',
		'mediaSizeSlug' => 'large',
	);
	$html  = '<div class="wp-block-media-text is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="' . fx_attr( $url ) . '" alt="' . fx_attr( $alt ) . '" class="wp-image-' . $id . ' size-large"/></figure>'
		. '<div class="wp-block-media-text__content">' . fx_join( $inner_blocks ) . '</div></div>';
	return fx_blk( 'core/media-text', $attrs, $html );
}

/**
 * Yoast SEO FAQ block, serialized like Yoast SEO 28.x does (string format: question/answer +
 * jsonQuestion/jsonAnswer + images). The Yoast plugin itself is NOT installed.
 * $qas: list of array( id_suffix_ms, question_html, answer_html ).
 */
function fx_yoast_faq( array $qas ) {
	$questions = array();
	$sections  = '';
	foreach ( $qas as $qa ) {
		list( $qid, $q, $a ) = $qa;
		$questions[]         = array(
			'id'           => $qid,
			'question'     => $q,
			'answer'       => $a,
			'jsonQuestion' => $q,
			'jsonAnswer'   => $a,
			'images'       => array(),
		);
		$sections           .= '<div class="schema-faq-section" id="' . $qid . '"><strong class="schema-faq-question">' . $q . '</strong><p class="schema-faq-answer">' . $a . '</p></div>';
	}
	return fx_blk( 'yoast/faq-block', array( 'questions' => $questions ), '<div class="schema-faq wp-block-yoast-faq-block">' . $sections . '</div>' );
}

/* ---------------------------------------------------------------------------------------------
 * Image generation (GD) + attachment creation
 * ------------------------------------------------------------------------------------------- */

function fx_font() {
	foreach ( array(
		'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
		'/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
		'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
		'/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
	) as $font ) {
		if ( is_file( $font ) ) {
			return $font;
		}
	}
	return null;
}

function fx_hex( $im, $hex, $alpha = 0 ) {
	$hex = ltrim( $hex, '#' );
	return imagecolorallocatealpha( $im, hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ), $alpha );
}

/** Draws a simple "pottery studio" picture: gradient wall, shelf, pots, caption band. Returns binary JPEG/PNG. */
function fx_render_image( array $s ) {
	$w  = $s['w'];
	$h  = $s['h'];
	$im = imagecreatetruecolor( $w, $h );
	mt_srand( $s['seed'] );

	// Wall: vertical gradient.
	list( $top, $bottom ) = $s['wall'];
	$t1                   = array_map( 'hexdec', str_split( ltrim( $top, '#' ), 2 ) );
	$t2                   = array_map( 'hexdec', str_split( ltrim( $bottom, '#' ), 2 ) );
	for ( $y = 0; $y < $h; $y++ ) {
		$k   = $y / max( 1, $h - 1 );
		$col = imagecolorallocate( $im, (int) round( $t1[0] + ( $t2[0] - $t1[0] ) * $k ), (int) round( $t1[1] + ( $t2[1] - $t1[1] ) * $k ), (int) round( $t1[2] + ( $t2[2] - $t1[2] ) * $k ) );
		imageline( $im, 0, $y, $w - 1, $y, $col );
	}

	// Window light.
	imagefilledrectangle( $im, (int) ( $w * 0.62 ), (int) ( $h * 0.08 ), (int) ( $w * 0.9 ), (int) ( $h * 0.45 ), fx_hex( $im, '#FFF8E7', 60 ) );

	// Workbench.
	$bench = (int) ( $h * 0.7 );
	imagefilledrectangle( $im, 0, $bench, $w, $h, fx_hex( $im, $s['bench'] ) );
	imagefilledrectangle( $im, 0, $bench, $w, $bench + max( 4, (int) ( $h * 0.015 ) ), fx_hex( $im, '#3B2A1E' ) );

	// Pots.
	$n = $s['pots'];
	for ( $i = 0; $i < $n; $i++ ) {
		$pw    = (int) ( $w / $n * ( 0.45 + mt_rand( 0, 25 ) / 100 ) );
		$ph    = (int) ( $pw * ( 0.55 + mt_rand( 0, 90 ) / 100 ) );
		$cx    = (int) ( ( $i + 0.5 ) * $w / $n ) + mt_rand( -10, 10 );
		$base  = $bench + (int) ( $h * 0.01 );
		$color = $s['glazes'][ $i % count( $s['glazes'] ) ];
		imagefilledellipse( $im, $cx, $base - (int) ( $ph * 0.45 ), $pw, $ph, fx_hex( $im, $color ) );
		imagefilledrectangle( $im, $cx - (int) ( $pw * 0.22 ), $base - (int) ( $ph * 1.02 ), $cx + (int) ( $pw * 0.22 ), $base - (int) ( $ph * 0.75 ), fx_hex( $im, $color ) );
		imagefilledellipse( $im, $cx, $base - (int) ( $ph * 1.02 ), (int) ( $pw * 0.46 ), max( 4, (int) ( $ph * 0.08 ) ), fx_hex( $im, '#2B1D14', 40 ) );
		imagefilledellipse( $im, $cx - (int) ( $pw * 0.18 ), $base - (int) ( $ph * 0.6 ), (int) ( $pw * 0.12 ), (int) ( $ph * 0.3 ), fx_hex( $im, '#FFFFFF', 85 ) );
	}

	// Caption band with label.
	$band = (int) ( $h * 0.1 );
	imagefilledrectangle( $im, 0, $h - $band, $w, $h, fx_hex( $im, '#1F140D', 30 ) );
	$label = $s['label'] . '  ·  ' . $w . '×' . $h;
	$font  = fx_font();
	if ( $font && function_exists( 'imagettftext' ) ) {
		imagettftext( $im, max( 10, (int) ( $band * 0.38 ) ), 0, (int) ( $w * 0.03 ), $h - (int) ( $band * 0.32 ), fx_hex( $im, '#FFFFFF' ), $font, $label );
	} else {
		imagestring( $im, 5, (int) ( $w * 0.03 ), $h - (int) ( $band * 0.7 ), remove_accents( $label ), fx_hex( $im, '#FFFFFF' ) );
	}

	ob_start();
	if ( 'png' === $s['type'] ) {
		imagepng( $im, null, 6 );
	} else {
		imagejpeg( $im, null, 82 );
	}
	$bits = ob_get_clean();
	imagedestroy( $im );
	return $bits;
}

function fx_add_image( $key, array $s ) {
	$upload = wp_upload_bits( $s['file'], null, fx_render_image( $s ), $s['folder'] );
	if ( ! empty( $upload['error'] ) ) {
		fx_fail( 'upload ' . $s['file'] . ': ' . $upload['error'] );
	}
	$id = fx_ok(
		wp_insert_attachment(
			wp_slash(
				array(
					'post_mime_type' => 'png' === $s['type'] ? 'image/png' : 'image/jpeg',
					'post_title'     => $s['title'],
					'post_content'   => $s['description'],
					'post_excerpt'   => $s['caption'],
					'post_status'    => 'inherit',
					'post_author'    => $s['author'],
					'post_date'      => $s['date'],
					'post_date_gmt'  => fx_gmt( $s['date'] ),
					'guid'           => $upload['url'],
				)
			),
			$upload['file'],
			0,
			true
		),
		'attachment ' . $s['file']
	);
	update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $s['alt'] ) );
	$meta = wp_generate_attachment_metadata( $id, $upload['file'] );
	if ( empty( $meta['sizes'] ) ) {
		fx_fail( 'no sub-sizes generated for ' . $s['file'] );
	}
	wp_update_attachment_metadata( $id, $meta );
	return fx_remember( $key, $id, $s['notes'] ?? array() );
}

/** wp_insert_post() wrapper (data must be slashed - block attribute JSON contains backslashes). */
function fx_insert( $key, array $args, array $notes = array() ) {
	$defaults = array(
		'post_type'   => 'post',
		'post_status' => 'publish',
	);
	$args     = array_merge( $defaults, $args );
	if ( isset( $args['post_date'] ) && ! isset( $args['post_date_gmt'] ) && ! in_array( $args['post_status'], array( 'draft', 'pending' ), true ) ) {
		$args['post_date_gmt'] = fx_gmt( $args['post_date'] );
	}
	if ( 'page' === $args['post_type'] ) {
		$args += array(
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
	}
	$id = fx_ok( wp_insert_post( wp_slash( $args ), true ), 'insert ' . $key );
	return fx_remember( $key, $id, $notes );
}

function fx_meta( $post_id, array $meta ) {
	foreach ( $meta as $k => $v ) {
		fx_ok( update_post_meta( $post_id, $k, wp_slash( $v ) ), "meta {$k} on #{$post_id}" );
	}
}

function fx_comment( $key, array $c ) {
	$c   += array(
		'comment_author_IP' => '203.0.113.10',
		'comment_agent'     => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
		'comment_type'      => 'comment',
		'comment_parent'    => 0,
		'user_id'           => 0,
		'comment_approved'  => 1,
		'comment_karma'     => 0,
		'comment_author_url' => '',
	);
	$c['comment_date_gmt'] = fx_gmt( $c['comment_date'] );
	$id                    = fx_ok( wp_insert_comment( wp_slash( $c ) ), 'comment ' . $key );
	return fx_remember( 'comment_' . $key, $id );
}

/* =============================================================================================
 * 0. Default content: drop "Hello world!", its comment and "Sample Page"; keep the privacy page (#3).
 * =========================================================================================== */

wp_delete_comment( 1, true );
wp_delete_post( 1, true );
wp_delete_post( 2, true );

/* =============================================================================================
 * 1. Users
 * =========================================================================================== */

fx_ok(
	wp_update_user(
		wp_slash(
			array(
				'ID'           => 1,
				'first_name'   => 'Émilie',
				'last_name'    => 'Roux',
				'nickname'     => 'Émilie',
				'display_name' => 'Émilie Roux',
				'user_url'     => 'https://www.atelier-lumiere.example/',
				'description'  => "Céramiste et fondatrice de l'Atelier Lumière. Formée à Saint-Quentin-la-Poterie, Émilie tourne le grès depuis quinze ans et anime les stages intensifs.",
			)
		)
	),
	'update admin'
);
fx_remember( 'user_admin', 1 );

$users = array(
	'user_claire' => array(
		'user_login'      => 'claire',
		'user_pass'       => 'Claire-Test-2026!',
		'user_email'      => 'claire@example.com',
		'first_name'      => 'Claire',
		'last_name'       => 'Martin',
		'nickname'        => 'Claire',
		'display_name'    => 'Claire Martin',
		'role'            => 'editor',
		'user_url'        => '',
		'user_registered' => '2026-01-05 09:00:00',
		'description'     => "Responsable de l'émaillage et des cuissons. Claire anime le cours du jeudi soir et teste chaque semaine de nouvelles recettes d'émaux.",
	),
	'user_lucas'  => array(
		'user_login'      => 'lucas',
		'user_pass'       => 'Lucas-Test-2026!',
		'user_email'      => 'lucas@example.com',
		'first_name'      => 'Lucas',
		'last_name'       => 'Petit',
		'nickname'        => 'Lucas',
		'display_name'    => 'Lucas Petit',
		'role'            => 'author',
		'user_url'        => 'https://lucas-raku.example/',
		'user_registered' => '2026-01-08 13:30:00',
		'description'     => 'Passionné de raku et de modelage, Lucas encadre les ateliers enfants du mercredi et les cuissons raku du samedi.',
	),
);
foreach ( $users as $key => $u ) {
	fx_remember( $key, fx_ok( wp_insert_user( wp_slash( $u ) ), 'user ' . $u['user_login'] ) );
}
$admin  = 1;
$claire = fx_id( 'user_claire' );
$lucas  = fx_id( 'user_lucas' );

/* =============================================================================================
 * 2. Taxonomies
 * =========================================================================================== */

function fx_term( $key, $taxonomy, $name, $slug, $description, $parent = 0 ) {
	$r = fx_ok(
		wp_insert_term(
			wp_slash( $name ),
			$taxonomy,
			array(
				'slug'        => $slug,
				'description' => wp_slash( $description ),
				'parent'      => $parent,
			)
		),
		"term {$name}"
	);
	return fx_remember( $key, $r['term_id'] );
}

fx_remember( 'cat_uncategorized', (int) get_option( 'default_category' ) );
$cat_ateliers = fx_term( 'cat_ateliers', 'category', 'Ateliers', 'ateliers', 'Tous nos ateliers de poterie et de céramique : cours réguliers, stages et séances découverte.' );
$cat_enfants  = fx_term( 'cat_ateliers_enfants', 'category', 'Ateliers enfants', 'ateliers-enfants', 'Modelage et poterie pour les 6–12 ans, le mercredi et pendant les vacances scolaires.', $cat_ateliers );
$cat_adultes  = fx_term( 'cat_ateliers_adultes', 'category', 'Ateliers adultes', 'ateliers-adultes', 'Cours hebdomadaires, stages intensifs et soirées découverte pour adultes.', $cat_ateliers );
$cat_tech     = fx_term( 'cat_techniques', 'category', 'Techniques', 'techniques', 'Tournage, émaillage, cuisson : nos articles pour comprendre et progresser.' );
$cat_email    = fx_term( 'cat_emaillage', 'category', 'Émaillage', 'emaillage', "Recettes, modes d'application et défauts d'émail expliqués.", $cat_tech );
$cat_actu     = fx_term( 'cat_actualites', 'category', 'Actualités', 'actualites', "La vie de l'atelier : nouveautés, événements, marchés et portes ouvertes." );

$tag_argile     = fx_term( 'tag_argile', 'post_tag', 'argile', 'argile', 'Terres et argiles : grès, faïence, porcelaine…' );
$tag_email      = fx_term( 'tag_email', 'post_tag', 'émail', 'email', 'Tout sur les émaux céramiques : composition, application, cuisson.' );
$tag_gres       = fx_term( 'tag_gres', 'post_tag', 'grès', 'gres', 'Le grès, terre reine de la vaisselle utilitaire.' );
$tag_raku       = fx_term( 'tag_raku', 'post_tag', 'raku', 'raku', "Technique de cuisson d'origine japonaise, spectaculaire et imprévisible." );
$tag_tournage   = fx_term( 'tag_tournage', 'post_tag', 'tournage', 'tournage', 'Le travail au tour de potier : centrage, montée, tournassage.' );
$tag_cuisson    = fx_term( 'tag_cuisson', 'post_tag', 'cuisson', 'cuisson', 'Fours, températures et courbes de cuisson.' );
$tag_porcelaine = fx_term( 'tag_porcelaine', 'post_tag', 'porcelaine', 'porcelaine', 'Travailler la porcelaine, fine et translucide.' );
$tag_lyon       = fx_term( 'tag_lyon', 'post_tag', 'Lyon', 'lyon', 'Ateliers et événements céramique à Lyon et alentours.' );

/* =============================================================================================
 * 3. Media (generated with GD, uploaded in fixed month folders, WordPress generates the sub-sizes)
 * =========================================================================================== */

$warm = array( '#C8A27A', '#7A9E9F', '#E9DCC9', '#9C4A2F', '#4F6D7A' );

$img_tour = fx_add_image(
	'img_tour',
	array(
		'file'        => 'atelier-tour-de-potier.jpg',
		'type'        => 'jpeg',
		'w'           => 1600,
		'h'           => 1000,
		'folder'      => '2026/01',
		'date'        => '2026-01-12 10:00:00',
		'author'      => 1,
		'seed'        => 101,
		'pots'        => 5,
		'wall'        => array( '#E8D9C4', '#B89574' ),
		'bench'       => '#6B4A33',
		'glazes'      => $warm,
		'label'       => 'Atelier Lumière – le tour de potier',
		'title'       => 'Le tour de potier',
		'alt'         => 'Mains de potière façonnant un bol sur le tour',
		'caption'     => "Le tour de potier, cœur de l'atelier.",
		'description' => "Image d'illustration (générée) : le coin tournage de l'atelier.",
		'notes'       => array( 'JPEG 1600x1000, unattached (post_parent 0)', 'medium size is -300x188' ),
	)
);
$img_pano = fx_add_image(
	'img_panorama',
	array(
		'file'        => 'panorama-atelier-croix-rousse.jpg',
		'type'        => 'jpeg',
		'w'           => 3000,
		'h'           => 2000,
		'folder'      => '2026/02',
		'date'        => '2026-02-08 16:20:00',
		'author'      => 1,
		'seed'        => 202,
		'pots'        => 9,
		'wall'        => array( '#F2E6D3', '#C9A882' ),
		'bench'       => '#5A3D2B',
		'glazes'      => $warm,
		'label'       => "Panorama de l'atelier de la Croix-Rousse",
		'title'       => "Panorama de l'atelier",
		'alt'         => "Vue d'ensemble de l'atelier avec les étagères de pièces crues",
		'caption'     => "L'atelier de la Croix-Rousse vu depuis la mezzanine.",
		'description' => "Image d'illustration (générée), plus grande que le seuil big_image_size_threshold (2560 px).",
		'notes'       => array( 'JPEG 3000x2000 > 2560 px: WordPress created a "-scaled" copy (_wp_attached_file = ...-scaled.jpg, original_image in metadata)', 'medium size is -300x200' ),
	)
);
$img_bols = fx_add_image(
	'img_bols',
	array(
		'file'        => 'bols-gres-emailles.jpg',
		'type'        => 'jpeg',
		'w'           => 1200,
		'h'           => 1200,
		'folder'      => '2026/03',
		'date'        => '2026-03-16 11:45:00',
		'author'      => $claire,
		'seed'        => 303,
		'pots'        => 3,
		'wall'        => array( '#DDE7E6', '#9DB8B6' ),
		'bench'       => '#7B5B44',
		'glazes'      => array( '#8FB8B0', '#5E8C86', '#B7D3CD' ),
		'label'       => 'Bols en grès, émail céladon',
		'title'       => 'Bols en grès émaillés',
		'alt'         => 'Trois bols en grès émaillés bleu céladon',
		'caption'     => 'Bols en grès, émail céladon maison.',
		'description' => "Image d'illustration (générée) : bols de la collection de printemps.",
		'notes'       => array( 'JPEG 1200x1200 (square)', 'medium size is -300x300' ),
	)
);
$img_raku = fx_add_image(
	'img_raku',
	array(
		'file'        => 'cuisson-raku.jpg',
		'type'        => 'jpeg',
		'w'           => 800,
		'h'           => 600,
		'folder'      => '2026/04',
		'date'        => '2026-04-20 19:10:00',
		'author'      => $lucas,
		'seed'        => 404,
		'pots'        => 2,
		'wall'        => array( '#3A2418', '#1A0F0A' ),
		'bench'       => '#2B1D14',
		'glazes'      => array( '#E3642B', '#F2A541' ),
		'label'       => 'Sortie du four raku',
		'title'       => 'Cuisson raku',
		'alt'         => 'Pièce incandescente sortie du four raku',
		'caption'     => 'Sortie du four à 950 °C.',
		'description' => "Image d'illustration (générée) : cuisson raku dans la cour.",
		'notes'       => array( 'JPEG 800x600 (no "large" size: smaller than 1024)', 'medium size is -300x225' ),
	)
);
$img_enfants = fx_add_image(
	'img_enfants',
	array(
		'file'        => 'atelier-enfants-modelage.png',
		'type'        => 'png',
		'w'           => 1200,
		'h'           => 800,
		'folder'      => '2026/06',
		'date'        => '2026-06-10 15:30:00',
		'author'      => $lucas,
		'seed'        => 505,
		'pots'        => 6,
		'wall'        => array( '#FBE9D0', '#F2C79B' ),
		'bench'       => '#8C6A4F',
		'glazes'      => array( '#E07A5F', '#81B29A', '#F2CC8F', '#3D405B' ),
		'label'       => 'Atelier enfants : modelage',
		'title'       => 'Atelier enfants',
		'alt'         => "Enfants modelant de l'argile autour d'une table",
		'caption'     => 'Atelier du mercredi : modelage libre.',
		'description' => "Image d'illustration (générée) : l'atelier enfants du mercredi.",
		'notes'       => array( 'PNG 1200x800', 'medium size is -300x200' ),
	)
);

/* =============================================================================================
 * 4. Pages
 * =========================================================================================== */

// Synced pattern (reusable block), referenced from the home page and from a post.
$newsletter = fx_insert(
	'block_newsletter',
	array(
		'post_type'    => 'wp_block',
		'post_title'   => 'Bandeau newsletter',
		'post_name'    => 'bandeau-newsletter',
		'post_author'  => $admin,
		'post_date'    => '2026-01-14 18:00:00',
		'post_content' => fx_join(
			array(
				fx_h( "Recevez notre lettre d'information", 3 ),
				fx_p( 'Un e-mail par mois : nouveaux ateliers, cuissons raku et ventes d\'atelier. <a href="' . fx_url( '/contact/' ) . '">Inscrivez-vous ici</a>.' ),
			)
		),
	),
	array( 'Synced pattern (post_type wp_block) referenced with <!-- wp:block {"ref":ID} /--> from the home page and from the émaillage post' )
);

$p_home = fx_insert(
	'page_accueil',
	array(
		'post_type'    => 'page',
		'post_title'   => 'Accueil',
		'post_name'    => 'accueil',
		'post_author'  => $admin,
		'post_date'    => '2026-01-15 10:00:00',
		'menu_order'   => 0,
		'post_content' => fx_join(
			array(
				fx_cover(
					$img_tour,
					array(
						fx_h( 'Céramique artisanale à Lyon', 1 ),
						fx_p( 'Cours, stages et créations en grès sur les pentes de la Croix-Rousse.' ),
					),
					'#1f140d',
					40,
					70,
					'vh'
				),
				fx_p( "Bienvenue à l'<strong>Atelier Lumière</strong> ! Depuis 2016, nous partageons notre passion de la terre avec les Lyonnais : cours hebdomadaires, stages intensifs, ateliers enfants et cuissons raku dans la cour." ),
				fx_columns(
					array(
						array( fx_h( 'Ateliers', 3 ), fx_p( 'Initiation, perfectionnement et stages pour adultes et enfants.' ) ),
						array( fx_h( 'Cours réguliers', 3 ), fx_p( 'Un créneau par semaine, toute l\'année, en petit groupe de huit personnes.' ) ),
						array( fx_h( 'Boutique', 3 ), fx_p( "Bols, tasses et assiettes en grès, tournés et émaillés à l'atelier." ) ),
					)
				),
				fx_buttons(
					array(
						array( 'Découvrir nos ateliers', fx_url( '/nos-ateliers/' ), '' ),
						array( 'Nous contacter', fx_url( '/contact/' ), 'is-style-outline' ),
					)
				),
				fx_h( 'Derniers articles' ),
				fx_blk(
					'core/latest-posts',
					array(
						'postsToShow'     => 3,
						'displayPostDate' => true,
					)
				),
				fx_block_ref( $newsletter ),
			)
		),
	),
	array( 'Static front page (show_on_front=page, page_on_front)', 'core/cover (h1 inside), core/columns x3, core/buttons, dynamic core/latest-posts, core/block ref to the synced pattern', 'Yoast meta (title uses %%sitedesc%%)' )
);

$p_blog = fx_insert(
	'page_blog',
	array(
		'post_type'    => 'page',
		'post_title'   => 'Blog',
		'post_name'    => 'blog',
		'post_author'  => $admin,
		'post_date'    => '2026-01-15 10:05:00',
		'menu_order'   => 40,
		'post_content' => '',
	),
	array( 'Posts page (page_for_posts) - empty content, WordPress lists the posts at /blog/' )
);

$p_about = fx_insert(
	'page_a_propos',
	array(
		'post_type'    => 'page',
		'post_title'   => 'À propos',
		'post_name'    => 'a-propos',
		'post_author'  => $admin,
		'post_date'    => '2026-01-15 10:10:00',
		'menu_order'   => 20,
		'post_content' => fx_join(
			array(
				fx_media_text(
					$img_tour,
					array(
						fx_h( 'Notre histoire' ),
						fx_p( "L'Atelier Lumière est né en 2016 de l'envie d'Émilie Roux, céramiste formée à Saint-Quentin-la-Poterie, de créer un lieu ouvert où chacun peut apprendre à travailler la terre." ),
					)
				),
				fx_p( 'Dix ans plus tard, l\'atelier accueille chaque semaine près de 120 élèves, de 6 à 82 ans, dans un ancien atelier de soyeux des pentes de la Croix-Rousse.' ),
				fx_h( "L'équipe" ),
				fx_list(
					array(
						'<strong>Émilie Roux</strong> – fondatrice, tourneuse et spécialiste du grès',
						'<strong>Claire Martin</strong> – émaux et cuissons, elle anime les cours du jeudi',
						'<strong>Lucas Petit</strong> – raku et ateliers enfants',
					)
				),
				fx_quote( array( 'La terre apprend la patience : elle ne pardonne pas la précipitation.' ), 'Émilie Roux' ),
				fx_p( 'Découvrez nos <a href="' . fx_url( '/a-propos/tarifs/' ) . '">tarifs</a> ou passez nous voir à l\'atelier !' ),
			)
		),
	),
	array( 'Parent page of "Tarifs"', 'core/media-text, core/list, core/quote', 'Rank Math meta' )
);

$p_tarifs = fx_insert(
	'page_tarifs',
	array(
		'post_type'    => 'page',
		'post_title'   => 'Tarifs',
		'post_name'    => 'tarifs',
		'post_parent'  => $p_about,
		'post_author'  => $claire,
		'post_date'    => '2026-01-16 09:00:00',
		'menu_order'   => 1,
		'post_content' => fx_join(
			array(
				fx_p( 'Tous nos tarifs incluent la terre, les cuissons et les émaux. Paiement possible en trois fois sans frais.' ),
				fx_table(
					array( 'Formule', 'Durée', 'Tarif' ),
					array(
						array( 'Atelier découverte', '2 h', '45 €' ),
						array( 'Cours hebdomadaire (trimestre)', '11 × 2 h 30', '420 €' ),
						array( 'Stage intensif', '5 × 3 h 30', '390 €' ),
						array( 'Atelier enfants (séance)', '2 h', '22 €' ),
						array( 'Cuisson raku', '3 h', '65 €' ),
					),
					"Tarifs valables jusqu'au 31 août 2027."
				),
				fx_h( 'Bons cadeaux' ),
				fx_p( "Offrez un atelier ! Nos bons cadeaux sont valables un an et disponibles à l'atelier ou par courrier." ),
				fx_buttons( array( array( 'Demander un bon cadeau', fx_url( '/contact/' ), '' ) ) ),
			)
		),
	),
	array( 'Child page of "À propos" (URL /a-propos/tarifs/)', 'core/table', 'Yoast meta' )
);

$p_contact = fx_insert(
	'page_contact',
	array(
		'post_type'    => 'page',
		'post_title'   => 'Contact',
		'post_name'    => 'contact',
		'post_author'  => $admin,
		'post_date'    => '2026-01-15 10:20:00',
		'menu_order'   => 50,
		'post_content' => fx_join(
			array(
				fx_p( '<strong>Atelier Lumière</strong><br>27 bis montée de la Grande-Côte<br>69001 Lyon' ),
				fx_p( 'Téléphone : <a href="tel:+33465711234">04 65 71 12 34</a><br>E-mail : <a href="mailto:contact@atelier-lumiere.example">contact@atelier-lumiere.example</a>' ),
				fx_h( 'Nous écrire' ),
				fx_shortcode_block( '[contact-form-7 id="12" title="Contact"]' ),
				fx_h( "Horaires d'ouverture" ),
				fx_list(
					array(
						'Mardi et jeudi : 14 h – 21 h',
						'Mercredi : 10 h – 18 h',
						'Samedi : 9 h – 17 h',
					)
				),
				fx_h( "Plan d'accès" ),
				fx_html( '<iframe title="Plan d\'accès à l\'Atelier Lumière" width="100%" height="320" style="border:0" loading="lazy" src="https://www.openstreetmap.org/export/embed.html?bbox=4.8290%2C45.7690%2C4.8390%2C45.7740&amp;layer=mapnik&amp;marker=45.7715%2C4.8340"></iframe>' ),
			)
		),
	),
	array( 'core/shortcode block with the unknown shortcode [contact-form-7 id="12" title="Contact"]', 'core/html with an OpenStreetMap <iframe>', 'tel: and mailto: links', 'Yoast meta description only' )
);

$p_privacy = 3;
fx_ok(
	wp_update_post(
		wp_slash(
			array(
				'ID'            => $p_privacy,
				'post_title'    => 'Politique de confidentialité',
				'post_name'     => 'politique-de-confidentialite',
				'post_status'   => 'publish',
				'post_author'   => $admin,
				'post_date'     => '2026-01-15 10:30:00',
				'post_date_gmt' => fx_gmt( '2026-01-15 10:30:00' ),
				'edit_date'     => true,
				'menu_order'    => 90,
				'post_content'  => fx_join(
					array(
						fx_h( 'Qui sommes-nous ?' ),
						fx_p( "L'adresse de notre site est : " . FX_URL . '.' ),
						fx_h( 'Données collectées' ),
						fx_p( "Lorsque vous laissez un commentaire, nous collectons les données affichées dans le formulaire, ainsi que l'adresse IP et l'agent utilisateur de votre navigateur afin de détecter les commentaires indésirables." ),
						fx_h( 'Durée de conservation' ),
						fx_p( 'Les commentaires et leurs métadonnées sont conservés indéfiniment, afin de reconnaître et approuver automatiquement les commentaires suivants.' ),
					)
				),
			)
		),
		true
	),
	'privacy page'
);
fx_remember( 'page_confidentialite', $p_privacy, array( 'Default WordPress privacy page (#3) translated and published; still wp_page_for_privacy_policy' ) );

// SEO plugin data on pages (the plugins themselves are not installed).
fx_meta(
	$p_home,
	array(
		'_yoast_wpseo_title'    => '%%sitename%% %%sep%% %%sitedesc%%',
		'_yoast_wpseo_metadesc' => 'Cours de poterie, stages de tournage et ateliers enfants à Lyon Croix-Rousse : céramique artisanale en grès.',
		'_yoast_wpseo_focuskw'  => 'atelier poterie Lyon',
	)
);
fx_meta(
	$p_about,
	array(
		'rank_math_title'         => '%title% %sep% %sitename%',
		'rank_math_description'   => "L'histoire de l'Atelier Lumière et de son équipe : Émilie, Claire et Lucas, céramistes à Lyon.",
		'rank_math_focus_keyword' => 'atelier céramique lyon,céramiste lyon',
		'rank_math_seo_score'     => '68',
	)
);
fx_meta(
	$p_tarifs,
	array(
		'_yoast_wpseo_title'    => 'Tarifs des cours de poterie %%sep%% %%sitename%%',
		'_yoast_wpseo_metadesc' => 'Atelier découverte 45 €, cours hebdomadaire 420 € le trimestre, stage intensif 390 € : tous nos tarifs.',
		'_yoast_wpseo_focuskw'  => 'tarif cours poterie lyon',
	)
);
fx_meta(
	$p_contact,
	array(
		'_yoast_wpseo_metadesc' => "Contactez l'Atelier Lumière : 27 bis montée de la Grande-Côte, Lyon 1er. Téléphone, e-mail, horaires et plan d'accès.",
	)
);

/* ---- Elementor page (data only, Elementor is NOT installed) ---- */

function fx_el_widget( $id, $type, array $settings ) {
	return array(
		'id'         => $id,
		'elType'     => 'widget',
		'settings'   => $settings,
		'elements'   => array(),
		'widgetType' => $type,
	);
}
function fx_el_image( $id ) {
	return array(
		'url'    => wp_get_attachment_url( $id ),
		'id'     => $id,
		'size'   => '',
		'alt'    => '',
		'source' => 'library',
	);
}
function fx_el_link( $url ) {
	return array(
		'url'               => $url,
		'is_external'       => '',
		'nofollow'          => '',
		'custom_attributes' => '',
	);
}
function fx_el_size( $size, $unit = 'px' ) {
	return array(
		'unit'  => $unit,
		'size'  => $size,
		'sizes' => array(),
	);
}
$fa = function ( $icon ) {
	return array(
		'value'   => 'fas fa-' . $icon,
		'library' => 'fa-solid',
	);
};

$elementor_data = array(
	// Hero: flexbox container (Elementor >= 3.6 "container" element).
	array(
		'id'       => '3c1e5f7',
		'elType'   => 'container',
		'settings' => array(
			'content_width'                 => 'boxed',
			'flex_direction'                => 'column',
			'flex_justify_content'          => 'center',
			'flex_align_items'              => 'center',
			'min_height'                    => fx_el_size( 60, 'vh' ),
			'background_background'         => 'classic',
			'background_image'              => fx_el_image( $img_pano ),
			'background_position'           => 'center center',
			'background_size'               => 'cover',
			'background_overlay_background' => 'classic',
			'background_overlay_color'      => '#2B1D14',
			'background_overlay_opacity'    => fx_el_size( 0.55 ),
			'padding'                       => array(
				'unit'     => 'px',
				'top'      => '80',
				'right'    => '20',
				'bottom'   => '80',
				'left'     => '20',
				'isLinked' => false,
			),
		),
		'elements' => array(
			fx_el_widget(
				'8a4d2b9',
				'heading',
				array(
					'title'                  => 'Nos ateliers de céramique',
					'header_size'            => 'h1',
					'align'                  => 'center',
					'title_color'            => '#FFFFFF',
					'typography_typography'  => 'custom',
					'typography_font_family' => 'Playfair Display',
					'typography_font_size'   => fx_el_size( 52 ),
					'typography_font_weight' => '600',
				)
			),
			fx_el_widget(
				'e6f0a13',
				'text-editor',
				array(
					'editor'     => '<p>Initiation, cours réguliers, stages intensifs et ateliers enfants : trouvez la formule qui vous ressemble.</p>',
					'align'      => 'center',
					'text_color' => '#F7F3EE',
				)
			),
			fx_el_widget(
				'5b9c7d2',
				'button',
				array(
					'text'              => 'Réserver un créneau',
					'link'              => fx_el_link( fx_url( '/contact/' ) ),
					'align'             => 'center',
					'size'              => 'md',
					'selected_icon'     => $fa( 'arrow-right' ),
					'icon_align'        => 'row-reverse',
					'background_color'  => '#C8A27A',
					'button_text_color' => '#1F140D',
					'border_radius'     => array(
						'unit'     => 'px',
						'top'      => '30',
						'right'    => '30',
						'bottom'   => '30',
						'left'     => '30',
						'isLinked' => true,
					),
				)
			),
		),
		'isInner'  => false,
	),
	// Legacy section / columns structure: image + text.
	array(
		'id'       => '1f7a3e8',
		'elType'   => 'section',
		'settings' => array(
			'structure'        => '20',
			'gap'              => 'wide',
			'content_position' => 'middle',
		),
		'elements' => array(
			array(
				'id'       => '9d2c4b6',
				'elType'   => 'column',
				'settings' => array(
					'_column_size' => 50,
					'_inline_size' => null,
				),
				'elements' => array(
					fx_el_widget(
						'a3f5c71',
						'image',
						array(
							'image'          => fx_el_image( $img_enfants ),
							'image_size'     => 'large',
							'caption_source' => 'custom',
							'caption'        => 'Atelier du mercredi',
							'link_to'        => 'file',
							'open_lightbox'  => 'default',
						)
					),
				),
				'isInner'  => false,
			),
			array(
				'id'       => '4e8b1a0',
				'elType'   => 'column',
				'settings' => array(
					'_column_size' => 50,
					'_inline_size' => null,
				),
				'elements' => array(
					fx_el_widget(
						'b2c9d84',
						'heading',
						array(
							'title'       => 'Ateliers enfants',
							'header_size' => 'h2',
							'__globals__' => array( 'title_color' => 'globals/colors?id=primary' ),
						)
					),
					fx_el_widget(
						'0f3e6a9',
						'text-editor',
						array(
							'editor' => '<p>Chaque mercredi, les enfants de 6 à 12 ans découvrent le modelage, les engobes colorés et la magie de la cuisson. <strong>Groupes de 8 enfants maximum.</strong></p>',
						)
					),
					fx_el_widget(
						'7c0d9e4',
						'icon-list',
						array(
							'icon_list'     => array(
								array(
									'text'          => 'De 6 à 12 ans',
									'selected_icon' => $fa( 'check' ),
									'_id'           => 'b1e2c30',
									'link'          => fx_el_link( '' ),
								),
								array(
									'text'          => 'Le mercredi de 14 h à 16 h',
									'selected_icon' => $fa( 'clock' ),
									'_id'           => 'c4d5e61',
									'link'          => fx_el_link( '' ),
								),
								array(
									'text'          => 'Matériel et cuissons compris',
									'selected_icon' => $fa( 'check' ),
									'_id'           => 'd7e8f92',
									'link'          => fx_el_link( '' ),
								),
								array(
									'text'          => 'Voir le programme détaillé',
									'selected_icon' => $fa( 'arrow-right' ),
									'_id'           => 'e0f1a23',
									'link'          => fx_el_link( fx_url( '/atelier-enfants-modeler-argile/' ) ),
								),
							),
							'icon_color'    => '#C8A27A',
							'space_between' => fx_el_size( 10 ),
						)
					),
				),
				'isInner'  => false,
			),
		),
		'isInner'  => false,
	),
	// Spacer, divider, video.
	array(
		'id'       => '2b6e9f1',
		'elType'   => 'container',
		'settings' => array(
			'content_width'  => 'boxed',
			'flex_direction' => 'column',
		),
		'elements' => array(
			fx_el_widget( '6d3a8c5', 'spacer', array( 'space' => fx_el_size( 40 ) ) ),
			fx_el_widget(
				'f1c7b20',
				'divider',
				array(
					'style'  => 'solid',
					'weight' => fx_el_size( 2 ),
					'color'  => '#C8A27A',
					'width'  => fx_el_size( 30, '%' ),
					'align'  => 'center',
					'gap'    => fx_el_size( 20 ),
				)
			),
			fx_el_widget(
				'c3d4e5f',
				'heading',
				array(
					'title'       => 'Le raku en vidéo',
					'header_size' => 'h2',
					'align'       => 'center',
				)
			),
			fx_el_widget(
				'0a9b8c7',
				'video',
				array(
					'video_type'         => 'youtube',
					'youtube_url'        => FX_YOUTUBE,
					'controls'           => 'yes',
					'aspect_ratio'       => '169',
					'show_image_overlay' => '',
				)
			),
			fx_el_widget( '8e7f6a5', 'spacer', array( 'space' => fx_el_size( 40 ) ) ),
		),
		'isInner'  => false,
	),
	// FAQ: nested containers with accordion + toggle.
	array(
		'id'       => '5e4d3c2',
		'elType'   => 'container',
		'settings' => array(
			'content_width'  => 'boxed',
			'flex_direction' => 'row',
			'flex_gap'       => array(
				'unit'   => 'px',
				'size'   => 40,
				'column' => '40',
				'row'    => '40',
			),
		),
		'elements' => array(
			array(
				'id'       => 'b8a7f61',
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => fx_el_size( 50, '%' ),
				),
				'elements' => array(
					fx_el_widget(
						'd9e0f1a',
						'heading',
						array(
							'title'       => 'Questions fréquentes',
							'header_size' => 'h2',
						)
					),
					fx_el_widget(
						'3a2b1c0',
						'accordion',
						array(
							'tabs'                 => array(
								array(
									'tab_title'   => 'Faut-il savoir dessiner ?',
									'tab_content' => '<p>Pas du tout ! La céramique est avant tout une affaire de toucher et de patience.</p>',
									'_id'         => 'f4e3d21',
								),
								array(
									'tab_title'   => 'Que deviennent mes pièces ?',
									'tab_content' => '<p>Elles vous appartiennent : vous les récupérez après la dernière cuisson, environ trois semaines plus tard.</p>',
									'_id'         => 'a5f4e32',
								),
								array(
									'tab_title'   => 'Peut-on offrir un atelier ?',
									'tab_content' => '<p>Oui, nos bons cadeaux sont valables un an. Voir la page <a href="' . fx_url( '/a-propos/tarifs/' ) . '">Tarifs</a>.</p>',
									'_id'         => 'b6a5f43',
								),
							),
							'selected_icon'        => $fa( 'plus' ),
							'selected_active_icon' => $fa( 'minus' ),
							'title_html_tag'       => 'h3',
						)
					),
				),
				'isInner'  => true,
			),
			array(
				'id'       => 'c9b8a72',
				'elType'   => 'container',
				'settings' => array(
					'content_width' => 'full',
					'width'         => fx_el_size( 50, '%' ),
				),
				'elements' => array(
					fx_el_widget(
						'e1f2a3b',
						'heading',
						array(
							'title'       => 'Bon à savoir',
							'header_size' => 'h2',
						)
					),
					fx_el_widget(
						'4b3c2d1',
						'toggle',
						array(
							'tabs'                 => array(
								array(
									'tab_title'   => 'Tenue conseillée',
									'tab_content' => '<p>Des vêtements confortables qui ne craignent pas la terre. Les tabliers sont fournis.</p>',
									'_id'         => 'c7b6a54',
								),
								array(
									'tab_title'   => 'Accessibilité',
									'tab_content' => "<p>L'atelier est de plain-pied et accessible aux personnes à mobilité réduite.</p>",
									'_id'         => 'd8c7b65',
								),
							),
							'selected_icon'        => $fa( 'caret-right' ),
							'selected_active_icon' => $fa( 'caret-up' ),
						)
					),
				),
				'isInner'  => true,
			),
		),
		'isInner'  => false,
	),
	// Testimonial.
	array(
		'id'       => '9f8e7d6',
		'elType'   => 'container',
		'settings' => array(
			'content_width'         => 'boxed',
			'flex_direction'        => 'column',
			'background_background' => 'classic',
			'background_color'      => '#F7F3EE',
		),
		'elements' => array(
			fx_el_widget(
				'1e2d3c4',
				'testimonial',
				array(
					'testimonial_content'        => 'Un vrai moment de déconnexion chaque semaine. Claire et Lucas sont patients et passionnés, et je suis fière de boire mon café dans mon propre bol !',
					'testimonial_image'          => fx_el_image( $img_bols ),
					'testimonial_name'           => 'Sophie Bernard',
					'testimonial_job'            => 'Élève du cours du jeudi',
					'testimonial_image_position' => 'aside',
					'testimonial_alignment'      => 'center',
				)
			),
		),
		'isInner'  => false,
	),
);

// Fallback post_content, shaped like Elementor's Plugin::$instance->db->save_plain_text() output.
$enfants_large = wp_get_attachment_image_src( $img_enfants, 'large' );
$elementor_plain = implode(
	"\n",
	array(
		"\t\t\t<h1>Nos ateliers de céramique</h1>\t\t",
		"\t\t<p>Initiation, cours réguliers, stages intensifs et ateliers enfants : trouvez la formule qui vous ressemble.</p>\t\t",
		"\t\t\t<a href=\"" . fx_url( '/contact/' ) . '">',
		"\t\t\t\t\t\tRéserver un créneau",
		"\t\t\t\t\t</a>",
		"\t\t\t\t\t\t<figure>",
		"\t\t\t\t\t\t\t\t\t\t\t<a href=\"" . wp_get_attachment_url( $img_enfants ) . '">',
		"\t\t\t\t\t\t\t<img width=\"{$enfants_large[1]}\" height=\"{$enfants_large[2]}\" src=\"{$enfants_large[0]}\" alt=\"Enfants modelant de l'argile autour d'une table\" />\t\t\t\t\t\t\t\t</a>",
		"\t\t\t\t\t\t\t<figcaption>Atelier du mercredi</figcaption>",
		"\t\t\t\t\t\t</figure>",
		"\t\t\t<h2>Ateliers enfants</h2>\t\t",
		"\t\t<p>Chaque mercredi, les enfants de 6 à 12 ans découvrent le modelage, les engobes colorés et la magie de la cuisson. <strong>Groupes de 8 enfants maximum.</strong></p>\t\t",
		"\t\t<ul>",
		"\t\t\t\t\t\t\t<li>",
		"\t\t\t\t\t\t\t\t\t\tDe 6 à 12 ans",
		"\t\t\t\t\t\t\t\t\t</li>",
		"\t\t\t\t\t\t\t<li>",
		"\t\t\t\t\t\t\t\t\t\tLe mercredi de 14 h à 16 h",
		"\t\t\t\t\t\t\t\t\t</li>",
		"\t\t\t\t\t\t\t<li>",
		"\t\t\t\t\t\t\t\t\t\tMatériel et cuissons compris",
		"\t\t\t\t\t\t\t\t\t</li>",
		"\t\t\t\t\t\t\t<li>",
		"\t\t\t\t\t\t\t\t\t\t\t<a href=\"" . fx_url( '/atelier-enfants-modeler-argile/' ) . '">',
		"\t\t\t\t\t\t\t\t\t\tVoir le programme détaillé",
		"\t\t\t\t\t\t\t\t\t\t\t</a>",
		"\t\t\t\t\t\t\t\t\t</li>",
		"\t\t\t\t\t\t</ul>",
		"\t\t\t<h2>Le raku en vidéo</h2>\t\t",
		"\t\t\t\t\t\t" . FX_YOUTUBE,
		"\t\t\t<h2>Questions fréquentes</h2>\t\t",
		"\t\t\t\t\t\t\t\t\t<a tabindex=\"0\">Faut-il savoir dessiner ?</a>",
		"\t\t\t\t\t<p>Pas du tout ! La céramique est avant tout une affaire de toucher et de patience.</p>",
		"\t\t\t\t\t\t\t\t\t<a tabindex=\"0\">Que deviennent mes pièces ?</a>",
		"\t\t\t\t\t<p>Elles vous appartiennent : vous les récupérez après la dernière cuisson, environ trois semaines plus tard.</p>",
		"\t\t\t\t\t\t\t\t\t<a tabindex=\"0\">Peut-on offrir un atelier ?</a>",
		"\t\t\t\t\t<p>Oui, nos bons cadeaux sont valables un an. Voir la page <a href=\"" . fx_url( '/a-propos/tarifs/' ) . '">Tarifs</a>.</p>',
		"\t\t\t<h2>Bon à savoir</h2>\t\t",
		"\t\t\t\t\t\t\t\t\t<a tabindex=\"0\">Tenue conseillée</a>",
		"\t\t\t\t\t<p>Des vêtements confortables qui ne craignent pas la terre. Les tabliers sont fournis.</p>",
		"\t\t\t\t\t\t\t\t\t<a tabindex=\"0\">Accessibilité</a>",
		"\t\t\t\t\t<p>L'atelier est de plain-pied et accessible aux personnes à mobilité réduite.</p>",
		"\t\t\t\t\tUn vrai moment de déconnexion chaque semaine. Claire et Lucas sont patients et passionnés, et je suis fière de boire mon café dans mon propre bol !",
		"\t\t\t\t\t\t\t\tSophie Bernard",
		"\t\t\t\t\t\t\t\t\t\t\tÉlève du cours du jeudi",
	)
);

$p_elementor = fx_insert(
	'page_nos_ateliers',
	array(
		'post_type'    => 'page',
		'post_title'   => 'Nos ateliers',
		'post_name'    => 'nos-ateliers',
		'post_author'  => $admin,
		'post_date'    => '2026-01-20 11:00:00',
		'menu_order'   => 10,
		'post_content' => $elementor_plain,
	),
	array( 'Elementor page: real content only in postmeta _elementor_data (JSON); post_content is Elementor\'s plain-HTML fallback', 'Widgets: heading, text-editor, button, image, icon-list, spacer, divider, video (youtube_url), accordion, toggle, testimonial; containers (incl. nested isInner containers) + one legacy section/column structure' )
);
fx_meta(
	$p_elementor,
	array(
		'_elementor_edit_mode'     => 'builder',
		'_elementor_template_type' => 'wp-page',
		'_elementor_version'       => '3.25.0',
		'_wp_page_template'        => 'elementor_header_footer',
		'_elementor_page_settings' => array( 'hide_title' => 'yes' ),
		'_elementor_page_assets'   => array( 'styles' => array( 'widget-heading', 'widget-text-editor', 'widget-image', 'widget-icon-list', 'widget-spacer', 'widget-divider', 'widget-video', 'widget-accordion', 'widget-toggle', 'widget-testimonial' ) ),
	)
);
// Same as Elementor's Document::update_json_meta(): wp_slash( wp_json_encode( $data ) ) - escaped slashes and \uXXXX accents.
fx_ok( update_post_meta( $p_elementor, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) ), '_elementor_data' );

/* =============================================================================================
 * 5. Posts
 * =========================================================================================== */

// --- Sticky Gutenberg post: cover, columns, gallery, YouTube embed, separator, html, buttons, more. ---
$post_atelier = fx_insert(
	'post_nouvel_atelier',
	array(
		'post_title'    => 'Notre nouvel atelier à la Croix-Rousse',
		'post_name'     => 'notre-nouvel-atelier-croix-rousse',
		'post_author'   => $admin,
		'post_date'     => '2026-02-10 09:30:00',
		'post_category' => array( $cat_actu ),
		'tags_input'    => array( $tag_lyon, $tag_argile ),
		'post_content'  => fx_join(
			array(
				fx_cover(
					$img_pano,
					array(
						fx_h( 'Bienvenue dans notre nouvel atelier' ),
						fx_p( 'Pentes de la Croix-Rousse, Lyon 1<sup>er</sup>' ),
					),
					'#2b1d14',
					50,
					420
				),
				fx_p( "Après dix ans passés dans un petit local de la rue d'Austerlitz, l'<strong>Atelier Lumière</strong> a déménagé en janvier dans un ancien atelier de soyeux, au cœur des pentes de la Croix-Rousse. Plus de lumière, plus de tours, et enfin un vrai four à gaz pour nos cuissons raku." ),
				fx_more(),
				fx_h( 'Un lieu pensé pour la céramique' ),
				fx_columns(
					array(
						array( fx_h( "120 m² d'atelier", 3 ), fx_p( "Huit tours électriques, deux grandes tables de modelage et un espace d'émaillage ventilé, séparé de la zone de travail." ) ),
						array( fx_h( 'Trois fours', 3 ), fx_p( 'Un four électrique de 200 litres pour le grès, un petit four de test et un four à gaz dédié au raku, installé dans la cour.' ) ),
					)
				),
				fx_gallery( array( $img_tour, $img_bols, $img_raku ), 3, "Quelques vues de l'atelier : le coin tournage, les étagères de séchage et la cour." ),
				fx_h( 'La visite en vidéo' ),
				fx_embed_youtube( FX_YOUTUBE, "Visite guidée de l'atelier (2 min)." ),
				fx_separator(),
				fx_html( "<div class=\"atelier-infos\" style=\"border:1px solid #c8a27a;padding:1.5em;border-radius:8px\">\n\t<h3 style=\"margin-top:0\">Infos pratiques</h3>\n\t<p><strong>Adresse :</strong> 27 bis montée de la Grande-Côte, 69001 Lyon<br><strong>Métro :</strong> Croix-Paquet (ligne C)</p>\n\t<iframe title=\"Plan d'accès\" width=\"100%\" height=\"300\" style=\"border:0\" loading=\"lazy\" src=\"https://www.openstreetmap.org/export/embed.html?bbox=4.8290%2C45.7690%2C4.8390%2C45.7740&amp;layer=mapnik&amp;marker=45.7715%2C4.8340\"></iframe>\n</div>" ),
				fx_p( 'Les inscriptions pour les cours de printemps sont ouvertes : consultez <a href="' . fx_url( '/a-propos/tarifs/' ) . '">nos tarifs</a> et réservez votre place.' ),
				fx_buttons(
					array(
						array( 'Réserver un atelier', fx_url( '/contact/' ), '' ),
						array( 'Voir les tarifs', fx_url( '/a-propos/tarifs/' ), 'is-style-outline' ),
					)
				),
			)
		),
	),
	array( 'STICKY post', 'Gutenberg: core/cover (h2 inside) with the -scaled panorama, core/more, core/columns, core/gallery (3 images, caption), core/embed YouTube (caption), core/separator, core/html (div + OpenStreetMap iframe), core/buttons (2, one is-style-outline)', 'Yoast meta' )
);
stick_post( $post_atelier );
set_post_thumbnail( $post_atelier, $img_pano );
fx_meta(
	$post_atelier,
	array(
		'_yoast_wpseo_title'                          => '%%title%% %%page%% %%sep%% %%sitename%%',
		'_yoast_wpseo_metadesc'                       => "L'Atelier Lumière s'installe sur les pentes de la Croix-Rousse : 120 m², huit tours et un four raku. Visite en images et en vidéo.",
		'_yoast_wpseo_focuskw'                        => 'atelier céramique Lyon',
		'_yoast_wpseo_content_score'                  => '60',
		'_yoast_wpseo_estimated-reading-time-minutes' => '3',
	)
);

// --- Gutenberg "long read": headings, ol/ul (nested), quote, table, image + caption, synced pattern. ---
$post_emaillage = fx_insert(
	'post_emaillage',
	array(
		'post_title'    => "Les secrets de l'émaillage : guide complet",
		'post_name'     => 'secrets-emaillage-guide-complet',
		'post_author'   => $claire,
		'post_date'     => '2026-03-18 14:00:00',
		'post_excerpt'  => "Silice, fondants, alumine : comprendre la composition d'un émail, choisir sa température de cuisson et éviter les défauts les plus courants.",
		'post_category' => array( $cat_tech, $cat_email ),
		'tags_input'    => array( $tag_email, $tag_gres, $tag_cuisson ),
		'post_content'  => fx_join(
			array(
				fx_p( "L'émail, c'est la <em>peau</em> de la céramique : une fine couche de verre qui rend la pièce étanche, résistante… et belle. Dans ce guide, Claire, notre responsable émaillage, partage les bases que nous enseignons en atelier." ),
				fx_h( "Qu'est-ce qu'un émail ?", 2, 'qu-est-ce-qu-un-email' ),
				fx_p( "Un émail est un mélange de minéraux broyés, mis en suspension dans l'eau, qui vitrifie à la cuisson. On l'applique au trempage, au pinceau ou au pistolet sur une pièce déjà cuite une première fois (le <strong>biscuit</strong>)." ),
				fx_image( $img_bols, 'large', 'Bols en grès émaillés au céladon, cuits à 1280 °C.', array( 'link' => 'media' ) ),
				fx_h( 'Les trois composants de base', 3 ),
				fx_list(
					array(
						'La <strong>silice</strong>, qui forme le verre ;',
						'les <strong>fondants</strong> (feldspaths, craie, frittes), qui abaissent la température de fusion ;',
						"l'<strong>alumine</strong>, qui stabilise l'émail et l'empêche de couler.",
					),
					true
				),
				fx_h( "Les grandes familles d'émaux", 3 ),
				fx_list(
					array(
						'Émaux transparents et brillants',
						'Émaux mats et satinés',
						array( 'Émaux à effets :', array( 'céladons', 'tenmokus', 'émaux à cristallisation' ) ),
					)
				),
				fx_quote( array( 'Un bon émail, c\'est 10 % de recette et 90 % de patience : on teste, on note, on recommence.' ), 'Claire Martin, atelier du jeudi' ),
				fx_h( 'Températures de cuisson' ),
				fx_table(
					array( 'Type de terre', 'Cuisson biscuit', 'Cuisson émail' ),
					array(
						array( 'Faïence', '980 °C', "1\u{202F}040 °C" ),
						array( 'Grès', '980 °C', "1\u{202F}280 °C" ),
						array( 'Porcelaine', "1\u{202F}000 °C", "1\u{202F}300 °C" ),
					),
					"Températures indicatives pratiquées à l'atelier."
				),
				fx_p( 'Pour le raku, les températures sont plus basses (autour de 950 °C) : nous y consacrerons un prochain article. En attendant, découvrez <a href="' . fx_url( '/notre-nouvel-atelier-croix-rousse/' ) . '">notre nouvel atelier</a> et <a href="' . fx_url( '/a-propos/tarifs/' ) . '">nos tarifs</a>.' ),
				fx_separator(),
				fx_block_ref( $newsletter ),
			)
		),
	),
	array( 'Gutenberg: h2 with anchor id, h3, ordered list, nested unordered list, core/quote with cite, core/table (thead + caption, U+202F thousands separators), core/image large + caption linked to the media file, core/separator, core/block ref (synced pattern)', 'Custom excerpt', '_wp_old_slug = guide-emaillage (old URL should redirect)', 'Yoast meta incl. _yoast_wpseo_canonical (external URL) and _yoast_wpseo_primary_category', 'Comment thread (3 levels)', 'Modified after publication' )
);
set_post_thumbnail( $post_emaillage, $img_bols );
add_post_meta( $post_emaillage, '_wp_old_slug', 'guide-emaillage' );
fx_meta(
	$post_emaillage,
	array(
		'_yoast_wpseo_title'                          => '%%title%% %%sep%% %%sitename%%',
		'_yoast_wpseo_metadesc'                       => "Composition d'un émail, températures de cuisson, grandes familles d'émaux : notre guide complet de l'émaillage céramique.",
		'_yoast_wpseo_focuskw'                        => 'émaillage céramique',
		'_yoast_wpseo_canonical'                      => 'https://www.atelier-lumiere.example/blog/secrets-emaillage/',
		'_yoast_wpseo_primary_category'               => (string) $cat_email,
		'_yoast_wpseo_opengraph-title'                => "Guide de l'émaillage céramique",
		'_yoast_wpseo_opengraph-description'          => 'Tout ce que nous enseignons sur les émaux, résumé en un article.',
		'_yoast_wpseo_opengraph-image'                => wp_get_attachment_url( $img_bols ),
		'_yoast_wpseo_opengraph-image-id'             => (string) $img_bols,
		'_yoast_wpseo_content_score'                  => '90',
		'_yoast_wpseo_estimated-reading-time-minutes' => '4',
	)
);

// --- Classic editor post: wpautop paragraphs, [caption], bare YouTube URL, unknown shortcode. ---
$raku_full = wp_get_attachment_image_src( $img_raku, 'full' );
$post_raku = fx_insert(
	'post_raku',
	array(
		'post_title'    => 'Cuisson raku : le feu et la fumée',
		'post_name'     => 'cuisson-raku-feu-et-fumee',
		'post_author'   => $lucas,
		'post_date'     => '2026-04-22 18:15:00',
		'post_excerpt'  => 'Sortir une pièce incandescente du four, l\'enfumer dans la sciure et découvrir ses craquelures : bienvenue dans le monde du raku.',
		'post_category' => array( $cat_tech ),
		'tags_input'    => array( $tag_raku, $tag_cuisson ),
		'post_content'  => implode(
			"\n",
			array(
				"Le raku est une technique de cuisson d'origine japonaise, née au XVI<sup>e</sup> siècle à Kyoto. Chez nous, c'est surtout <strong>un moment de partage</strong> : on sort les pièces incandescentes du four à environ 950 °C, puis on les plonge dans la sciure.",
				'',
				'[caption id="attachment_' . $img_raku . '" align="aligncenter" width="800"]<img class="size-full wp-image-' . $img_raku . '" src="' . $raku_full[0] . '" alt="Pièce incandescente sortie du four raku" width="800" height="600" /> Sortie du four à 950 °C : le moment le plus spectaculaire.[/caption]',
				'',
				'<h2>Comment se déroule une cuisson ?</h2>',
				'Après une première cuisson (le biscuit), les pièces sont émaillées puis enfournées dans un petit four à gaz. Comptez environ <em>45 minutes</em> de montée en température.',
				"Une fois l'émail fondu, on ouvre le four et on saisit les pièces à la pince.",
				'',
				'<ul>',
				"\t<li>Enfumage dans un bac de sciure ou de papier journal</li>",
				"\t<li>Refroidissement à l'eau</li>",
				"\t<li>Nettoyage et découverte des craquelures</li>",
				'</ul>',
				'Voici une vidéo qui montre l\'ambiance d\'une cuisson :',
				'',
				FX_YOUTUBE,
				'',
				'Pour bien choisir vos émaux, relisez notre <a href="' . fx_url( '/secrets-emaillage-guide-complet/' ) . '">guide complet de l\'émaillage</a>. Pour réserver votre place à la prochaine session, remplissez le formulaire ci-dessous :',
				'',
				'[contact-form-7 id="12" title="Contact"]',
			)
		),
	),
	array( 'CLASSIC editor content (no block comments): blank-line separated paragraphs + single line break (wpautop -> <p>/<br />)', '[caption id="attachment_X" align="aligncenter" width="800"] shortcode around a full-size <img>', 'Bare YouTube URL alone on its own line (oEmbed autoembed)', 'Unknown shortcode [contact-form-7 id="12" title="Contact"] (plugin not installed, renders as-is)', 'Raw <h2> and <ul> HTML in classic content', 'Post format: video', 'Custom excerpt', 'Yoast meta with _yoast_wpseo_meta-robots-noindex = 1' )
);
set_post_thumbnail( $post_raku, $img_raku );
set_post_format( $post_raku, 'video' );
fx_meta(
	$post_raku,
	array(
		'_yoast_wpseo_title'                 => '%%title%% %%sep%% %%sitename%%',
		'_yoast_wpseo_metadesc'              => 'Le raku, cuisson japonaise spectaculaire : déroulement, enfumage, craquelures. Réservez votre session à Lyon.',
		'_yoast_wpseo_focuskw'               => 'cuisson raku',
		'_yoast_wpseo_meta-robots-noindex'   => '1',
		'_yoast_wpseo_content_score'         => '30',
	)
);

// --- Classic editor post: <!--more-->, inline -300x200 image, [gallery], ?p= shortlink, &nbsp; paragraph. ---
$pano_full = wp_get_attachment_url( $img_pano );
$pano_med  = wp_get_attachment_image_src( $img_pano, 'medium' );
$post_bols = fx_insert(
	'post_bols',
	array(
		'post_title'    => 'Nos bols en grès pour la nouvelle saison',
		'post_name'     => 'bols-gres-nouvelle-saison',
		'post_author'   => $claire,
		'post_date'     => '2026-05-30 11:00:00',
		'post_category' => array( $cat_actu ),
		'tags_input'    => array( $tag_gres, $tag_porcelaine ),
		'post_content'  => implode(
			"\n",
			array(
				"Après six mois d'essais, notre nouvelle collection de bols en grès est enfin prête ! Chaque pièce est tournée à la main dans l'atelier de la Croix-Rousse, puis émaillée avec nos recettes maison.",
				'',
				'<!--more-->',
				'',
				'<a href="' . $pano_full . '"><img class="alignright size-medium wp-image-' . $img_pano . '" src="' . $pano_med[0] . '" alt="Vue d\'ensemble de l\'atelier avec les étagères de pièces crues" width="' . $pano_med[1] . '" height="' . $pano_med[2] . '" /></a>Les bols sèchent pendant deux semaines sur les étagères de la mezzanine avant leur première cuisson. Nous avons choisi trois émaux pour cette saison : un céladon, un tenmoku et un blanc satiné.',
				'',
				'[gallery ids="' . $img_tour . ',' . $img_bols . ',' . $img_raku . '" columns="3" size="medium" link="file"]',
				'',
				'Les bols sont disponibles à l\'atelier à partir de 28 € pièce. Retrouvez aussi <a href="' . fx_url( '/a-propos/tarifs/' ) . '">nos tarifs de cours</a> et notre article sur <a href="' . fx_url( '/?p=' . $post_raku ) . '">la cuisson raku</a>.',
				'',
				'&nbsp;',
				'',
				'<strong>Horaires de la boutique :</strong> du mercredi au samedi, 10 h – 19 h.',
			)
		),
	),
	array( 'CLASSIC editor content', '<!--more--> teaser split', 'Inline <img> using the -300x200 medium URL of the -scaled panorama (class alignright size-medium wp-image-X), linked to the full image', '[gallery ids="..." columns="3" size="medium" link="file"] shortcode', 'Internal link in ?p=ID (shortlink) form to the raku post', '&nbsp; spacer paragraph', 'Rank Math meta (rank_math_robots = [index])' )
);
set_post_thumbnail( $post_bols, $img_bols );
fx_meta(
	$post_bols,
	array(
		'rank_math_title'                     => '%title% %sep% %sitename%',
		'rank_math_description'               => 'Découvrez notre collection de bols en grès tournés à la main à Lyon : céladon, tenmoku et blanc satiné.',
		'rank_math_focus_keyword'             => 'bols en grès,céramique artisanale',
		'rank_math_robots'                    => array( 'index' ),
		'rank_math_primary_category'          => (string) $cat_actu,
		'rank_math_seo_score'                 => '81',
		'rank_math_internal_links_processed'  => '1',
	)
);

// --- Gutenberg post with a Yoast FAQ block. ---
$faq_base  = strtotime( '2026-06-12 06:30:00 UTC' ) * 1000;
$post_faq  = fx_insert(
	'post_atelier_enfants',
	array(
		'post_title'    => "Atelier enfants : modeler l'argile dès 6 ans",
		'post_name'     => 'atelier-enfants-modeler-argile',
		'post_author'   => $lucas,
		'post_date'     => '2026-06-12 08:45:00',
		'post_category' => array( $cat_ateliers, $cat_enfants ),
		'tags_input'    => array( $tag_argile, $tag_lyon ),
		'post_content'  => fx_join(
			array(
				fx_p( "Chaque mercredi après-midi et pendant les vacances scolaires, l'atelier accueille les enfants de 6 à 12 ans pour deux heures de modelage. Pas de tour pour les plus jeunes : on travaille la terre avec les mains, des estèques et beaucoup d'imagination !" ),
				fx_image(
					$img_enfants,
					'medium',
					'',
					array(
						'align' => 'right',
						'link'  => 'media',
					)
				),
				fx_h( "Déroulement d'une séance" ),
				fx_list(
					array(
						'Accueil et présentation du thème du jour (animaux, bols, masques…)',
						'Modelage libre ou guidé, en petits groupes de 8 enfants maximum',
						'Décoration aux engobes colorés',
						'Goûter et rangement',
					)
				),
				fx_p( 'Les pièces sèchent pendant deux semaines avant d\'être cuites, puis émaillées lors d\'une séance suivante. Retrouvez tous nos <a href="' . fx_url( '/category/ateliers/ateliers-enfants/' ) . '">ateliers enfants</a>, ou admirez les <a href="' . fx_url( '/bols-gres-nouvelle-saison/' ) . '">bols de la nouvelle saison</a> décorés par leurs aînés.' ),
				fx_h( 'Questions fréquentes' ),
				fx_yoast_faq(
					array(
						array( 'faq-question-' . ( $faq_base + 101 ), "À partir de quel âge mon enfant peut-il participer ?", 'Les ateliers sont ouverts aux enfants de 6 à 12 ans. Pour les 4-5 ans, nous proposons des séances parent-enfant le samedi matin.' ),
						array( 'faq-question-' . ( $faq_base + 202 ), 'Faut-il apporter du matériel ?', 'Non, tout est fourni : argile, outils, tabliers et émaux. Prévoyez simplement des vêtements qui ne craignent rien.' ),
						array( 'faq-question-' . ( $faq_base + 303 ), 'Quand récupère-t-on les pièces ?', 'Comptez <strong>trois à quatre semaines</strong> : séchage, première cuisson, émaillage puis seconde cuisson.' ),
						array( 'faq-question-' . ( $faq_base + 404 ), 'Comment inscrire mon enfant ?', 'Par téléphone ou via notre <a href="' . fx_url( '/contact/' ) . '">formulaire de contact</a>. Le règlement se fait au trimestre (180 €) ou à la séance (22 €).' ),
					)
				),
				fx_p( 'Une question qui n\'apparaît pas ici ? <a href="' . fx_url( '/contact/' ) . '">Écrivez-nous</a>, nous répondons sous 48 h.' ),
			)
		),
	),
	array( 'Yoast FAQ block <!-- wp:yoast/faq-block --> with 4 questions (answers contain <strong> and <a>; HTML in the JSON attributes is \\u003c-escaped)', 'core/image medium (-300x200 PNG) aligned right, linked to the media file', 'Link to a category archive URL', 'Yoast meta incl. _yoast_wpseo_primary_category', 'Comments: 1 approved, 1 pending, 1 spam' )
);
set_post_thumbnail( $post_faq, $img_enfants );
fx_meta(
	$post_faq,
	array(
		'_yoast_wpseo_title'            => 'Atelier poterie enfants à Lyon %%sep%% %%sitename%%',
		'_yoast_wpseo_metadesc'         => 'Atelier poterie pour enfants de 6 à 12 ans à Lyon : modelage, engobes et cuisson. Questions fréquentes et inscriptions.',
		'_yoast_wpseo_focuskw'          => 'atelier poterie enfants',
		'_yoast_wpseo_primary_category' => (string) $cat_enfants,
		'_yoast_wpseo_content_score'    => '90',
	)
);

// --- Gutenberg post with Rank Math noindex. ---
$post_stage = fx_insert(
	'post_stage_ete',
	array(
		'post_title'    => "Stage d'été : tournage intensif en 5 jours",
		'post_name'     => 'stage-ete-tournage-intensif',
		'post_author'   => $admin,
		'post_date'     => '2026-07-03 10:00:00',
		'post_excerpt'  => 'Du lundi au vendredi, cinq matinées pour apprendre à centrer, monter et tournasser vos premières pièces.',
		'post_category' => array( $cat_ateliers, $cat_adultes ),
		'tags_input'    => array( $tag_tournage, $tag_lyon ),
		'post_content'  => fx_join(
			array(
				fx_p( 'Vous rêvez de vous asseoir derrière un tour ? Ce stage intensif de cinq matinées est fait pour vous. En petit groupe (6 personnes maximum), vous apprendrez les gestes fondamentaux du tournage.' ),
				fx_h( 'Programme de la semaine' ),
				fx_table(
					array( 'Jour', 'Au programme' ),
					array(
						array( 'Lundi', 'Préparation de la terre, centrage' ),
						array( 'Mardi', 'Ouverture et montée des parois' ),
						array( 'Mercredi', 'Bols et cylindres' ),
						array( 'Jeudi', 'Tournassage et anses' ),
						array( 'Vendredi', 'Finitions, émaillage et bilan' ),
					)
				),
				fx_h( 'Infos pratiques' ),
				fx_list(
					array(
						'Du 20 au 24 juillet 2026, de 9 h à 12 h 30',
						'Tarif : 390 € (terre, cuissons et émaux compris)',
						'Niveau : débutant ou faux débutant',
					)
				),
				fx_p( 'Les enfants ne sont pas oubliés : découvrez notre <a href="' . fx_url( '/atelier-enfants-modeler-argile/' ) . '">atelier enfants</a>.' ),
				fx_buttons( array( array( "Je m'inscris", fx_url( '/contact/' ), '' ) ) ),
			)
		),
	),
	array( 'Gutenberg: core/table without caption, list, buttons', 'Custom excerpt', 'Rank Math meta with rank_math_robots = [noindex, nofollow]' )
);
set_post_thumbnail( $post_stage, $img_tour );
fx_meta(
	$post_stage,
	array(
		'rank_math_title'                    => 'Stage de tournage à Lyon %sep% %sitename%',
		'rank_math_description'              => "Stage intensif de tournage à Lyon en juillet : 5 matinées, 6 participants maximum, terre et cuissons comprises.",
		'rank_math_focus_keyword'            => 'stage tournage lyon,stage poterie été',
		'rank_math_robots'                   => array( 'noindex', 'nofollow' ),
		'rank_math_seo_score'                => '74',
		'rank_math_internal_links_processed' => '1',
	)
);

// --- Classic post without explicit category (falls back to "Uncategorized"), emoji. ---
$post_pause = fx_insert(
	'post_fermeture',
	array(
		'post_title'   => "Fermeture estivale de l'atelier",
		'post_name'    => 'fermeture-estivale',
		'post_author'  => $lucas,
		'post_date'    => '2026-08-01 12:00:00',
		'post_content' => implode(
			"\n",
			array(
				"L'atelier fera une pause du 3 au 23 août ☀️ Les fours refroidissent, l'équipe recharge les batteries.",
				'',
				'Réouverture le lundi 24 août à 10 h. Les inscriptions pour la rentrée restent ouvertes par e-mail : <a href="mailto:contact@atelier-lumiere.example">contact@atelier-lumiere.example</a> 🏺',
				'',
				'Bel été à toutes et à tous !',
			)
		),
	),
	array( 'CLASSIC editor content with emoji (4-byte UTF-8)', 'No category given -> default category "Uncategorized"', 'No tags, no featured image' )
);

// --- Password-protected post. ---
$post_password = fx_insert(
	'post_recette_celadon',
	array(
		'post_title'    => "Recette d'émail céladon (réservé aux élèves)",
		'post_name'     => 'recette-email-celadon',
		'post_author'   => $claire,
		'post_date'     => '2026-09-09 17:30:00',
		'post_password' => 'celadon2026',
		'post_category' => array( $cat_tech, $cat_email ),
		'tags_input'    => array( $tag_email ),
		'post_content'  => fx_join(
			array(
				fx_p( 'Voici la recette de céladon que nous utilisons en atelier, comme promis pendant le cours de jeudi.' ),
				fx_table(
					array( 'Matière', 'Pourcentage' ),
					array(
						array( 'Feldspath potassique', '40 %' ),
						array( 'Silice', '30 %' ),
						array( 'Craie', '20 %' ),
						array( 'Kaolin', '10 %' ),
						array( 'Oxyde de fer (ajout)', '1,5 %' ),
					),
					'Cuisson en réduction à 1280 °C.'
				),
				fx_p( "Merci de ne pas diffuser cette recette en dehors de l'atelier." ),
			)
		),
	),
	array( 'PASSWORD-PROTECTED (post_password = celadon2026): REST returns content.protected=true and empty rendered content unless ?password= is given' )
);

// --- Private post. ---
$post_private = fx_insert(
	'post_notes_internes',
	array(
		'post_title'    => 'Notes internes : inventaire des émaux',
		'post_name'     => 'notes-internes-inventaire-emaux',
		'post_status'   => 'private',
		'post_author'   => $admin,
		'post_date'     => '2026-09-21 19:05:00',
		'post_category' => array( $cat_email ),
		'post_content'  => fx_join(
			array(
				fx_p( 'À commander avant la rentrée :' ),
				fx_list(
					array(
						'Céladon : 2 seaux restants (commander 10 kg de feldspath)',
						'Tenmoku : OK',
						'Blanc mat : à refaire, le dernier lot a coulé',
					)
				),
				fx_p( 'Fournisseur habituel : livraison sous 10 jours.' ),
			)
		),
	),
	array( 'PRIVATE post (only visible with authentication)' )
);

// --- Draft (no slug, floating date). ---
$post_draft = fx_insert(
	'post_brouillon_horaires',
	array(
		'post_title'    => "Nouveaux horaires d'hiver",
		'post_status'   => 'draft',
		'post_author'   => $claire,
		'post_date'     => '2026-10-30 16:20:00',
		'post_category' => array( $cat_actu ),
		'post_content'  => fx_join(
			array(
				fx_p( "À partir du 2 novembre, l'atelier passe à l'heure d'hiver :" ),
				fx_list(
					array(
						'Mardi et jeudi : 14 h – 21 h',
						"Mercredi : 10 h – 18 h (ateliers enfants l'après-midi)",
						'Samedi : 9 h – 17 h',
					)
				),
				fx_p( '[À compléter : horaires de la boutique pendant les fêtes]' ),
			)
		),
	),
	array( 'DRAFT: empty post_name (WordPress does not assign slugs to drafts) and post_date_gmt 0000-00-00 00:00:00 (floating date)' )
);

// --- Scheduled (future) post. ---
$post_future = fx_insert(
	'post_portes_ouvertes',
	array(
		'post_title'    => 'Portes ouvertes : venez tourner votre premier bol',
		'post_name'     => 'portes-ouvertes-premier-bol',
		'post_status'   => 'future',
		'post_author'   => $admin,
		'post_date'     => '2030-04-06 10:00:00',
		'post_category' => array( $cat_actu, $cat_ateliers ),
		'tags_input'    => array( $tag_lyon, $tag_tournage ),
		'post_content'  => fx_join(
			array(
				fx_p( "Le samedi 6 avril, l'atelier ouvre ses portes de 10 h à 18 h : démonstrations de tournage, cuisson raku dans la cour et initiation gratuite pour les visiteurs." ),
				fx_image( $img_tour, 'medium', 'Le tour vous attend !' ),
				fx_buttons( array( array( 'Prévenir mes amis', fx_url( '/contact/' ), '' ) ) ),
			)
		),
	),
	array( 'SCHEDULED post (status future, 2030-04-06 10:00 Europe/Paris). WP-Cron is disabled so it never auto-publishes' )
);
set_post_thumbnail( $post_future, $img_tour );

// Attach the images to the post they were "uploaded from" (the tour image stays unattached).
foreach ( array(
	$img_pano    => $post_atelier,
	$img_bols    => $post_emaillage,
	$img_raku    => $post_raku,
	$img_enfants => $post_faq,
) as $attachment_id => $parent_id ) {
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->posts, array( 'post_parent' => $parent_id ), array( 'ID' => $attachment_id ) );
	clean_post_cache( $attachment_id );
}

/* =============================================================================================
 * 6. Comments
 * =========================================================================================== */

$c_sophie = fx_comment(
	'sophie_1',
	array(
		'comment_post_ID'      => $post_emaillage,
		'comment_author'       => 'Sophie Bernard',
		'comment_author_email' => 'sophie.bernard@example.org',
		'comment_date'         => '2026-03-19 08:12:00',
		'comment_content'      => "Merci pour ce guide très clair ! Est-ce que vous proposez des recettes d'émaux sans plomb pour la vaisselle ?",
		'comment_author_IP'    => '203.0.113.21',
	)
);
$c_claire = fx_comment(
	'claire_reply',
	array(
		'comment_post_ID'      => $post_emaillage,
		'comment_parent'       => $c_sophie,
		'user_id'              => $claire,
		'comment_author'       => 'Claire Martin',
		'comment_author_email' => 'claire@example.com',
		'comment_date'         => '2026-03-19 10:40:00',
		'comment_content'      => "Bonjour Sophie, tous nos émaux sont sans plomb et testés pour un usage alimentaire. Nous en parlons en détail pendant le cours du jeudi !",
		'comment_author_IP'    => '127.0.0.1',
	)
);
fx_comment(
	'sophie_2',
	array(
		'comment_post_ID'      => $post_emaillage,
		'comment_parent'       => $c_claire,
		'comment_author'       => 'Sophie Bernard',
		'comment_author_email' => 'sophie.bernard@example.org',
		'comment_date'         => '2026-03-19 12:05:00',
		'comment_content'      => 'Super, je viens m\'inscrire alors 😊',
		'comment_author_IP'    => '203.0.113.21',
	)
);
fx_comment(
	'marc',
	array(
		'comment_post_ID'      => $post_emaillage,
		'comment_author'       => 'Marc Dubois',
		'comment_author_email' => 'marc.dubois@example.net',
		'comment_author_url'   => 'https://marc-poterie.example.net/',
		'comment_date'         => '2026-03-21 18:30:00',
		'comment_content'      => "Le tableau des températures est très utile.\n\nPour le grès, vous cuisez en réduction ou en oxydation ?",
		'comment_author_IP'    => '198.51.100.7',
	)
);
fx_comment(
	'pingback',
	array(
		'comment_post_ID'      => $post_atelier,
		'comment_type'         => 'pingback',
		'comment_author'       => "Les secrets de l'émaillage : guide complet – Atelier Lumière",
		'comment_author_email' => '',
		'comment_author_url'   => fx_url( '/secrets-emaillage-guide-complet/' ),
		'comment_date'         => '2026-03-18 14:00:07',
		'comment_content'      => '[…] En attendant, découvrez notre nouvel atelier et nos tarifs. […]',
		'comment_author_IP'    => '127.0.0.1',
		'comment_agent'        => 'WordPress/7.1.4; ' . FX_URL,
	)
);
$c_pierre = fx_comment(
	'pierre',
	array(
		'comment_post_ID'      => $post_raku,
		'comment_author'       => 'Pierre Garnier',
		'comment_author_email' => 'pierre.garnier@example.org',
		'comment_date'         => '2026-04-23 09:00:00',
		'comment_content'      => 'Superbe vidéo ! La prochaine session raku est prévue quand ?',
		'comment_author_IP'    => '203.0.113.54',
	)
);
fx_comment(
	'lucas_reply',
	array(
		'comment_post_ID'      => $post_raku,
		'comment_parent'       => $c_pierre,
		'user_id'              => $lucas,
		'comment_author'       => 'Lucas Petit',
		'comment_author_email' => 'lucas@example.com',
		'comment_author_url'   => 'https://lucas-raku.example/',
		'comment_date'         => '2026-04-23 11:30:00',
		'comment_content'      => 'Merci Pierre ! La prochaine cuisson a lieu le samedi 16 mai, il reste trois places.',
		'comment_author_IP'    => '127.0.0.1',
	)
);
fx_comment(
	'julie',
	array(
		'comment_post_ID'      => $post_faq,
		'comment_author'       => 'Julie Moreau',
		'comment_author_email' => 'julie.moreau@example.com',
		'comment_date'         => '2026-06-13 20:15:00',
		'comment_content'      => 'Ma fille de 7 ans a adoré la séance de mercredi, elle ne parle que de son hérisson en argile !',
		'comment_author_IP'    => '203.0.113.88',
	)
);
fx_comment(
	'antoine_pending',
	array(
		'comment_post_ID'      => $post_faq,
		'comment_approved'     => 0,
		'comment_author'       => 'Antoine Lefèvre',
		'comment_author_email' => 'antoine.lefevre@example.com',
		'comment_date'         => '2026-06-14 07:50:00',
		'comment_content'      => 'Bonjour, reste-t-il de la place pour les vacances de la Toussaint ?',
		'comment_author_IP'    => '198.51.100.23',
	)
);
fx_comment(
	'spam',
	array(
		'comment_post_ID'      => $post_faq,
		'comment_approved'     => 'spam',
		'comment_author'       => 'Montres de luxe pas chères',
		'comment_author_email' => 'promo@cheap-watches.example.com',
		'comment_author_url'   => 'https://cheap-watches.example.com/',
		'comment_date'         => '2026-06-15 03:22:00',
		'comment_content'      => 'Best replica watches, free shipping!!! Visit https://cheap-watches.example.com',
		'comment_author_IP'    => '192.0.2.66',
		'comment_agent'        => 'Mozilla/5.0 (compatible; SpamBot/1.0)',
	)
);

/* =============================================================================================
 * 7. Options: static front page, posts page
 * =========================================================================================== */

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $p_home );
update_option( 'page_for_posts', $p_blog );

/* =============================================================================================
 * 8. Navigation menus (classic) + the wp_navigation post the block theme derives from them
 * =========================================================================================== */

function fx_menu_item( $menu_id, $key, array $args ) {
	$args += array(
		'menu-item-status'        => 'publish',
		'menu-item-post-date'     => '2026-01-21 09:00:00',
		'menu-item-post-date-gmt' => fx_gmt( '2026-01-21 09:00:00' ),
	);
	$id = fx_ok( wp_update_nav_menu_item( $menu_id, 0, $args ), 'menu item ' . $key );
	return fx_remember( 'menu_item_' . $key, $id );
}

$menu_main = fx_remember( 'menu_principal', fx_ok( wp_create_nav_menu( 'Menu principal' ), 'menu principal' ) );
$pos       = 0;
$page_item = function ( $key, $page_id, $parent = 0, $title = '' ) use ( $menu_main, &$pos ) {
	return fx_menu_item(
		$menu_main,
		$key,
		array(
			'menu-item-type'      => 'post_type',
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_id,
			'menu-item-parent-id' => $parent,
			'menu-item-position'  => ++$pos,
			'menu-item-title'     => $title,
		)
	);
};
$page_item( 'main_accueil', $p_home );
$mi_ateliers = $page_item( 'main_nos_ateliers', $p_elementor );
fx_menu_item(
	$menu_main,
	'main_cat_enfants',
	array(
		'menu-item-type'      => 'taxonomy',
		'menu-item-object'    => 'category',
		'menu-item-object-id' => $cat_enfants,
		'menu-item-parent-id' => $mi_ateliers,
		'menu-item-position'  => ++$pos,
	)
);
$mi_about = $page_item( 'main_a_propos', $p_about );
$page_item( 'main_tarifs', $p_tarifs, $mi_about );
$page_item( 'main_blog', $p_blog, 0, 'Le blog' );
$page_item( 'main_contact', $p_contact );
fx_menu_item(
	$menu_main,
	'main_boutique',
	array(
		'menu-item-type'        => 'custom',
		'menu-item-title'       => 'Boutique en ligne',
		'menu-item-url'         => 'https://boutique.atelier-lumiere.example/',
		'menu-item-target'      => '_blank',
		'menu-item-attr-title'  => 'Notre boutique en ligne (nouvel onglet)',
		'menu-item-classes'     => 'menu-externe menu-cta',
		'menu-item-xfn'         => 'noopener',
		'menu-item-description' => 'Bols, tasses et bons cadeaux',
		'menu-item-position'    => ++$pos,
	)
);

$menu_footer = fx_remember( 'menu_pied_de_page', fx_ok( wp_create_nav_menu( 'Pied de page' ), 'menu pied de page' ) );
fx_menu_item(
	$menu_footer,
	'footer_confidentialite',
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p_privacy,
		'menu-item-position'  => 1,
	)
);
fx_menu_item(
	$menu_footer,
	'footer_contact',
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'page',
		'menu-item-object-id' => $p_contact,
		'menu-item-position'  => 2,
	)
);
fx_menu_item(
	$menu_footer,
	'footer_guide',
	array(
		'menu-item-type'      => 'post_type',
		'menu-item-object'    => 'post',
		'menu-item-object-id' => $post_emaillage,
		'menu-item-title'     => "Guide de l'émaillage",
		'menu-item-position'  => 3,
	)
);
fx_menu_item(
	$menu_footer,
	'footer_instagram',
	array(
		'menu-item-type'     => 'custom',
		'menu-item-title'    => 'Instagram',
		'menu-item-url'      => 'https://www.instagram.com/atelier.lumiere.lyon/',
		'menu-item-target'   => '_blank',
		'menu-item-xfn'      => 'me noopener',
		'menu-item-position' => 4,
	)
);

// Twenty Twenty-Five registers no classic menu locations: assign one only if the active theme has some.
$locations = get_registered_nav_menus();
if ( $locations ) {
	$assign = array();
	foreach ( array_keys( $locations ) as $loc ) {
		$assign[ $loc ] = ( false !== strpos( $loc, 'footer' ) ) ? $menu_footer : $menu_main;
	}
	set_theme_mod( 'nav_menu_locations', $assign );
}

// The theme's header Navigation block has no "ref": on the first front-end view WordPress would convert the most recently
// created classic menu into a wp_navigation post (WP_Navigation_Fallback). Do the same conversion now, explicitly from
// "Menu principal", so the database (and the WXR export) does not change when the site is first visited.
$nav_blocks = WP_Classic_To_Block_Menu_Converter::convert( wp_get_nav_menu_object( $menu_main ) );
if ( is_wp_error( $nav_blocks ) || ! $nav_blocks ) {
	fx_fail( 'could not convert Menu principal to blocks' );
}
fx_insert(
	'navigation_menu_principal',
	array(
		'post_type'    => 'wp_navigation',
		'post_title'   => 'Menu principal',
		'post_name'    => 'menu-principal',
		'post_author'  => $admin,
		'post_date'    => '2026-01-21 09:05:00',
		'post_content' => $nav_blocks,
	),
	array( 'wp_navigation post generated by WordPress (WP_Classic_To_Block_Menu_Converter) from the classic menu "Menu principal"; used by the Twenty Twenty-Five header Navigation block' )
);

/* =============================================================================================
 * 9. Application password for admin (fixed value so that rebuilds keep the same credentials)
 * =========================================================================================== */

$fixed_pw = static function () {
	return FX_APP_PW;
};
add_filter( 'random_password', $fixed_pw, 99 );
$app = WP_Application_Passwords::create_new_application_password( 1, array( 'name' => FX_APP_PW_NAME ) );
remove_filter( 'random_password', $fixed_pw, 99 );
if ( is_wp_error( $app ) ) {
	fx_fail( 'application password: ' . $app->get_error_message() );
}
$GLOBALS['fx_manifest']['application_password'] = array(
	'user'     => 'admin',
	'name'     => FX_APP_PW_NAME,
	'uuid'     => $app[1]['uuid'],
	'password' => WP_Application_Passwords::chunk_password( $app[0] ),
	'raw'      => $app[0],
);

/* =============================================================================================
 * 10. Determinism: oEmbed cache, fixed modification / registration dates
 * =========================================================================================== */

// Render every post/page/pattern once, like a first front-end or REST visit would. Content with a YouTube URL on its own
// line (embed block, classic bare URL, Elementor fallback HTML) triggers an oEmbed lookup; external HTTP is blocked
// (WP_HTTP_BLOCK_EXTERNAL), so WordPress caches the failure as post meta _oembed_<md5> = {{unknown}} and renders a plain
// link. Doing it now keeps the database - and the WXR export - unchanged when the site is crawled later.
$render_ids = get_posts(
	array(
		'post_type'      => array( 'post', 'page', 'wp_block' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
foreach ( $render_ids as $pid ) {
	$GLOBALS['post'] = get_post( $pid );
	setup_postdata( $GLOBALS['post'] );
	apply_filters( 'the_content', $GLOBALS['post']->post_content );
}
wp_reset_postdata();

global $wpdb;
$modified = array(
	$post_emaillage => '2026-04-02 09:15:00', // Edited two weeks after publication.
	$p_tarifs       => '2026-09-01 08:30:00', // Prices updated for the new season.
	$post_draft     => '2026-10-30 16:45:00',
	$post_future    => '2026-10-28 11:00:00', // Scheduled post: last edited before its publication date.
);
// Default: modified = published.
$wpdb->query( "UPDATE {$wpdb->posts} SET post_modified = post_date, post_modified_gmt = post_date_gmt WHERE post_status <> 'draft'" );
foreach ( $modified as $id => $local ) {
	$wpdb->update(
		$wpdb->posts,
		array(
			'post_modified'     => $local,
			'post_modified_gmt' => fx_gmt( $local ),
		),
		array( 'ID' => $id )
	);
}
$wpdb->update( $wpdb->users, array( 'user_registered' => '2025-11-03 08:12:00' ), array( 'ID' => 1 ) );

wp_cache_flush();

$GLOBALS['fx_manifest']['seeded_with'] = array(
	'wordpress' => get_bloginfo( 'version' ),
	'php'       => PHP_VERSION,
	'font'      => fx_font(),
);
file_put_contents( FX_MANIFEST, wp_json_encode( $GLOBALS['fx_manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );

printf(
	"Seeded: %d posts, %d pages, %d attachments, %d comments, %d menu items. Application password for admin: %s\n",
	array_sum( (array) wp_count_posts( 'post' ) ),
	array_sum( (array) wp_count_posts( 'page' ) ),
	array_sum( (array) wp_count_posts( 'attachment' ) ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ),
	(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item'" ),
	WP_Application_Passwords::chunk_password( $app[0] )
);
