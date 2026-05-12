<?php
/**
 * Apollo — Media Library
 *
 * Replaces r2-pdf.php. All document/PDF file management uses the WordPress
 * Media Library. Videos and audio continue to use R2/S3 via s3-core.php.
 *
 * Public surface mirrors the old s3-core helpers so existing callers
 * continue to work without changes:
 *
 *   apollo_media_url( $post_id, $hub )  → resolved public URL
 *   apollo_media_attachment_id( $post_id, $hub ) → WP attachment ID
 *   apollo_media_config()               → config array (no credentials needed)
 *
 * Per-hub meta keys:
 *   Videos  : _svh_media_url  (external URL)  | _svh_wp_media_id  (WP attachment)
 *   Audio   : _ep_audio_url   (external URL)  | _ep_wp_media_id   (WP attachment)
 *   PDFs    : _doc_file_url   (external URL)  | _doc_wp_media_id  (WP attachment)
 *
 * The admin settings page for Cloudflare CDN API (cache purging) still
 * exists in cloudflare-settings.php — that is CDN edge config only,
 * not storage credentials.
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// 1. CONFIG (no credentials — just CDN base URL if set)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the media configuration array.
 *
 * Keys:
 *   cdn_base_url  — optional CDN prefix for external media URLs (no trailing slash)
 *   use_cdn       — bool, prepend cdn_base_url to relative external URLs
 *
 * @param bool $reset  Flush static cache.
 * @return array<string,mixed>
 */
function apollo_media_config( bool $reset = false ): array {
	static $c = null;
	if ( $reset ) {
		$c = null;
	}
	if ( $c !== null ) {
		return $c;
	}

	$saved = (array) get_option( 'apollo_media_config', [] );

	$c = wp_parse_args( $saved, [
		'cdn_base_url' => defined( 'APOLLO_CDN_BASE_URL' ) ? APOLLO_CDN_BASE_URL : '',
		'use_cdn'      => false,
	] );

	$c['cdn_base_url'] = rtrim( (string) $c['cdn_base_url'], '/' );
	return $c;
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. URL RESOLUTION HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Resolve a public media URL for a given post and hub.
 *
 * Resolution order:
 *   1. External URL meta field (editor-entered URL)
 *   2. WordPress attachment URL (WP Media Library)
 *   3. Empty string (caller handles missing state)
 *
 * @param int    $post_id  Post ID.
 * @param string $hub      'video' | 'audio' | 'pdf' | 'image'
 * @return string
 */
function apollo_media_url( int $post_id, string $hub = 'video' ): string {
	[ $url_key, $id_key ] = apollo_media_meta_keys( $hub );

	// 1. External URL field
	$ext_url = (string) get_post_meta( $post_id, $url_key, true );
	if ( $ext_url !== '' ) {
		return apollo_media_maybe_cdn( $ext_url );
	}

	// 2. WP attachment
	$att_id = (int) get_post_meta( $post_id, $id_key, true );
	if ( $att_id > 0 ) {
		$att_url = wp_get_attachment_url( $att_id );
		if ( $att_url ) {
			return apollo_media_maybe_cdn( $att_url );
		}
	}

	return '';
}

/**
 * Return the WordPress attachment ID for a post/hub combo (0 if none).
 */
function apollo_media_attachment_id( int $post_id, string $hub = 'video' ): int {
	[ , $id_key ] = apollo_media_meta_keys( $hub );
	return (int) get_post_meta( $post_id, $id_key, true );
}

/**
 * Optionally prepend the CDN base URL to a relative or same-host URL.
 */
function apollo_media_maybe_cdn( string $url ): string {
	if ( $url === '' ) {
		return '';
	}
	$cfg = apollo_media_config();
	if ( empty( $cfg['use_cdn'] ) || $cfg['cdn_base_url'] === '' ) {
		return $url;
	}
	// Only prepend if the URL is relative or matches the site host
	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$url_host  = wp_parse_url( $url, PHP_URL_HOST );
	if ( $url_host === null || $url_host === $site_host ) {
		$path = ltrim( wp_parse_url( $url, PHP_URL_PATH ) ?? '', '/' );
		return $cfg['cdn_base_url'] . '/' . $path;
	}
	return $url;
}

/**
 * Map hub slug → [ external_url_meta_key, attachment_id_meta_key ].
 *
 * @return array{0:string,1:string}
 */
function apollo_media_meta_keys( string $hub ): array {
	return match ( $hub ) {
		'audio'  => [ '_ep_audio_url',  '_ep_wp_media_id'  ],
		'pdf'    => [ '_doc_file_url',  '_doc_wp_media_id' ],
		'image'  => [ '_img_media_url', '_img_wp_media_id' ],
		default  => [ '_svh_media_url', '_svh_wp_media_id' ],
	};
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. BACK-COMPAT SHIMS  (old callers used svh_r2_config / sah_r2_config)
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'svh_r2_config' ) ) {
	/** @deprecated Use apollo_media_config() */
	function svh_r2_config(): array {
		return [];
	}
}
if ( ! function_exists( 'sah_r2_config' ) ) {
	/** @deprecated Use apollo_media_config() */
	function sah_r2_config(): array {
		return [];
	}
}
if ( ! function_exists( 'apollo_cf_config' ) ) {
	/**
	 * Stub so code that only needed CDN config (not storage) still works.
	 * Full implementation is in cloudflare-settings.php.
	 */
	function apollo_cf_config(): array {
		return [
			'account_id'  => '',
			'access_key'  => '',
			'secret_key'  => '',
			'bucket'      => '',
			'public_url'  => '',
			'api_token'   => (string) get_option( 'apollo_cf_api_token', '' ),
			'zone_id'     => (string) get_option( 'apollo_cf_zone_id', '' ),
			'browser_ttl' => 14400,
		];
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. ADMIN SETTINGS PAGE  (CDN base URL only — no credentials)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function (): void {
	add_options_page(
		__( 'Apollo Media Settings', 'apollo-plugin' ),
		__( 'Apollo Media', 'apollo-plugin' ),
		'manage_options',
		'apollo-media-settings',
		'apollo_media_settings_page'
	);
} );

add_action( 'admin_init', function (): void {
	register_setting( 'apollo_media_settings', 'apollo_media_config', [
		'sanitize_callback' => 'apollo_media_sanitize_config',
	] );

	add_settings_section(
		'apollo_media_cdn',
		__( 'CDN / External Media Base URL', 'apollo-plugin' ),
		null,
		'apollo-media-settings'
	);

	add_settings_field(
		'apollo_media_cdn_base_url',
		__( 'CDN Base URL', 'apollo-plugin' ),
		function (): void {
			$cfg = apollo_media_config();
			printf(
				'<input type="url" name="apollo_media_config[cdn_base_url]" value="%s" class="regular-text" placeholder="https://media.example.com">
				<p class="description">%s</p>',
				esc_attr( $cfg['cdn_base_url'] ),
				esc_html__( 'Optional. If set, relative/same-host media URLs are rewritten through this CDN prefix. Leave blank to use WordPress upload URLs as-is.', 'apollo-plugin' )
			);
		},
		'apollo-media-settings',
		'apollo_media_cdn'
	);

	add_settings_field(
		'apollo_media_use_cdn',
		__( 'Enable CDN rewrite', 'apollo-plugin' ),
		function (): void {
			$cfg = apollo_media_config();
			printf(
				'<input type="checkbox" name="apollo_media_config[use_cdn]" value="1" %s>
				<label>%s</label>',
				checked( $cfg['use_cdn'], true, false ),
				esc_html__( 'Rewrite media URLs through the CDN Base URL above.', 'apollo-plugin' )
			);
		},
		'apollo-media-settings',
		'apollo_media_cdn'
	);
} );

function apollo_media_sanitize_config( $input ): array {
	if ( ! is_array( $input ) ) {
		return [];
	}
	return [
		'cdn_base_url' => esc_url_raw( $input['cdn_base_url'] ?? '' ),
		'use_cdn'      => ! empty( $input['use_cdn'] ),
	];
}

function apollo_media_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Apollo Media Settings', 'apollo-plugin' ); ?></h1>
		<p><?php esc_html_e( 'Apollo uses the WordPress Media Library for PDF and document file management. Videos and audio files use R2/S3 via the storage settings. You may optionally set a CDN base URL to rewrite media URLs.', 'apollo-plugin' ); ?></p>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'apollo_media_settings' );
			do_settings_sections( 'apollo-media-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. REST META REGISTRATION  (external URL fields for video + audio + pdf)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'init', function (): void {
	$defs = [
		// Video external URL
		[ 'serve_video', '_svh_media_url', 'string' ],
		// Audio external URL + WP attachment
		[ 'serve_episode', '_ep_audio_url',   'string'  ],
		[ 'serve_episode', '_ep_wp_media_id', 'integer' ],
		// PDF / document
		[ 'serve_document', '_doc_file_url',   'string'  ],
		[ 'serve_document', '_doc_wp_media_id', 'integer' ],
	];
	foreach ( $defs as [ $pt, $key, $type ] ) {
		register_post_meta( $pt, $key, [
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => $type,
			'auth_callback' => fn() => current_user_can( 'edit_posts' ),
		] );
	}
}, 11 ); // after CPT registration

// ─────────────────────────────────────────────────────────────────────────────
// 6. PDF EMBED SHORTCODE  (replaces r2-pdf.php)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * [apollo_pdf url="https://..." title="Report" height="700"]
 *
 * Embeds a PDF inline using <object>/<iframe> fallback.
 * The URL can be a WordPress media library URL or any external URL.
 */
add_shortcode( 'apollo_pdf', 'apollo_pdf_shortcode' );
add_shortcode( 'serve_pdf', 'apollo_pdf_shortcode' ); // back-compat alias

function apollo_pdf_shortcode( array $atts ): string {
	$atts = shortcode_atts( [
		'url'    => '',
		'title'  => __( 'Document', 'apollo-plugin' ),
		'height' => '700',
		'style'  => 'inline', // inline | download
	], $atts, 'apollo_pdf' );

	$url = esc_url( $atts['url'] );
	if ( $url === '' ) {
		return '';
	}

	if ( $atts['style'] === 'download' ) {
		return sprintf(
			'<div class="apollo-pdf-download"><a href="%s" class="apollo-pdf-download__link" target="_blank" rel="noopener noreferrer">%s %s</a></div>',
			esc_url( $url ),
			'<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
			esc_html( $atts['title'] )
		);
	}

	$height = absint( $atts['height'] ) ?: 700;

	return sprintf(
		'<div class="apollo-pdf-embed" style="width:100%%;height:%dpx;position:relative;">
			<object data="%s" type="application/pdf" width="100%%" height="%d">
				<iframe src="%s" width="100%%" height="%d" style="border:none;">
					<p>%s <a href="%s">%s</a>.</p>
				</iframe>
			</object>
		</div>',
		$height,
		esc_url( $url ),
		$height,
		esc_url( $url ),
		$height,
		esc_html__( 'This browser does not support PDF viewing.', 'apollo-plugin' ),
		esc_url( $url ),
		esc_html__( 'Download the PDF', 'apollo-plugin' )
	);
}
