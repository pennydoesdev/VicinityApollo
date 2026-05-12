<?php
/**
 * Centralized Cloudflare & R2 + Amazon S3 Configuration
 *
 * Single source of truth for ALL Cloudflare/R2 and Amazon S3 credentials, plus
 * per-hub storage backend selection (R2 or S3) across the Apollo plugin.
 * Reads wp-config.php constants first (highest priority), then apollo_cf_* /
 * apollo_s3_* wp_options.
 *
 * Supported wp-config.php constants:
 *
 *   // ── Cloudflare R2 credentials ──────────────────────────────────────────
 *   define( 'SVH_R2_ACCOUNT_ID',      'your-32-char-account-id' );
 *   define( 'SVH_R2_ACCESS_KEY',      'your-access-key-id' );
 *   define( 'SVH_R2_SECRET_KEY',      'your-secret-key' );
 *
 *   // Default (shared) R2 bucket — used when a hub has no specific bucket set
 *   define( 'SVH_R2_BUCKET',          'your-default-bucket' );
 *   define( 'SVH_R2_PUBLIC_URL',      'https://media.yoursite.com' );
 *
 *   // Video Hub R2 overrides (optional)
 *   define( 'APOLLO_VIDEO_BUCKET',    'pennytribune' );
 *   define( 'APOLLO_VIDEO_PUBLIC_URL','https://serve.pennycdn.com' );
 *
 *   // Audio Hub R2 overrides (optional)
 *   define( 'APOLLO_AUDIO_BUCKET',    'pennytribune' );
 *   define( 'APOLLO_AUDIO_PUBLIC_URL','https://serve.pennycdn.com' );
 *
 *   // Cloudflare CDN API — cache purging
 *   define( 'APOLLO_CF_API_TOKEN',    'your-cf-api-token' );
 *   define( 'APOLLO_CF_ZONE_ID',      'your-cf-zone-id' );
 *
 *   // ── Amazon S3 credentials ───────────────────────────────────────────────
 *   define( 'APOLLO_S3_ACCESS_KEY',   'AKIAIOSFODNN7EXAMPLE' );
 *   define( 'APOLLO_S3_SECRET_KEY',   'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
 *   define( 'APOLLO_S3_REGION',       'us-east-1' );
 *   define( 'APOLLO_S3_BUCKET',       'pennytribune-media' );
 *   define( 'APOLLO_S3_CF_URL',       'https://d1234abcdef.cloudfront.net' );
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Core config functions — available on ALL requests (frontend + admin)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Central Cloudflare + R2 configuration.
 *
 * @param bool $reset  Pass true to flush the static cache (used after save).
 * @return array<string, string|int>
 */
function apollo_cf_config( bool $reset = false ): array {
	static $c = null;
	if ( $reset ) {
		$c = null;
	}
	if ( $c !== null ) {
		return $c;
	}

	apollo_cf_maybe_migrate();

	$c = [
		'account_id' => defined( 'SVH_R2_ACCOUNT_ID' )
			? SVH_R2_ACCOUNT_ID
			: (string) get_option( 'apollo_cf_account_id', '' ),

		'access_key' => defined( 'SVH_R2_ACCESS_KEY' )
			? SVH_R2_ACCESS_KEY
			: (string) get_option( 'apollo_cf_access_key', '' ),

		'secret_key' => defined( 'SVH_R2_SECRET_KEY' )
			? SVH_R2_SECRET_KEY
			: (string) get_option( 'apollo_cf_secret_key', '' ),

		'bucket'     => defined( 'SVH_R2_BUCKET' )
			? SVH_R2_BUCKET
			: (string) get_option( 'apollo_cf_bucket', '' ),

		'public_url' => rtrim(
			defined( 'SVH_R2_PUBLIC_URL' )
				? SVH_R2_PUBLIC_URL
				: (string) get_option( 'apollo_cf_public_url', '' ),
			'/'
		),

		'video_bucket' => defined( 'APOLLO_VIDEO_BUCKET' )
			? APOLLO_VIDEO_BUCKET
			: (string) get_option( 'apollo_cf_video_bucket', '' ),

		'video_public_url' => rtrim(
			defined( 'APOLLO_VIDEO_PUBLIC_URL' )
				? APOLLO_VIDEO_PUBLIC_URL
				: (string) get_option( 'apollo_cf_video_public_url', '' ),
			'/'
		),

		'audio_bucket' => defined( 'APOLLO_AUDIO_BUCKET' )
			? APOLLO_AUDIO_BUCKET
			: (string) get_option( 'apollo_cf_audio_bucket', '' ),

		'audio_public_url' => rtrim(
			defined( 'APOLLO_AUDIO_PUBLIC_URL' )
				? APOLLO_AUDIO_PUBLIC_URL
				: (string) get_option( 'apollo_cf_audio_public_url', '' ),
			'/'
		),

		'api_token'  => defined( 'APOLLO_CF_API_TOKEN' )
			? APOLLO_CF_API_TOKEN
			: (string) get_option( 'apollo_cf_api_token', '' ),

		'zone_id'    => defined( 'APOLLO_CF_ZONE_ID' )
			? APOLLO_CF_ZONE_ID
			: (string) get_option( 'apollo_cf_zone_id', '' ),

		'browser_ttl' => (int) get_option( 'apollo_cf_browser_ttl', 14400 ),
	];

	return $c;
}

function apollo_cf_video_config(): array {
	$cfg = apollo_cf_config();
	return array_merge( $cfg, [
		'bucket'     => $cfg['video_bucket']     ?: $cfg['bucket'],
		'public_url' => $cfg['video_public_url'] ?: $cfg['public_url'],
	] );
}

function apollo_cf_audio_config(): array {
	$cfg = apollo_cf_config();
	return array_merge( $cfg, [
		'bucket'     => $cfg['audio_bucket']     ?: $cfg['bucket'],
		'public_url' => $cfg['audio_public_url'] ?: $cfg['public_url'],
	] );
}

if ( ! function_exists( 'serve_cf_get_config' ) ) {
	function serve_cf_get_config(): array {
		return apollo_cf_config();
	}
}

function apollo_cf_maybe_migrate(): void {
	if ( get_option( 'apollo_cf_migrated' ) ) {
		return;
	}

	$svh_map = [
		'svh_r2_account_id' => 'apollo_cf_account_id',
		'svh_r2_access_key' => 'apollo_cf_access_key',
		'svh_r2_secret_key' => 'apollo_cf_secret_key',
		'svh_r2_bucket'     => 'apollo_cf_bucket',
		'svh_r2_public_url' => 'apollo_cf_public_url',
	];
	foreach ( $svh_map as $old => $new ) {
		$val = get_option( $old, '' );
		if ( $val !== '' && ! get_option( $new ) ) {
			update_option( $new, $val, false );
		}
	}

	$pdf_cfg = get_option( 'serve_r2_config', [] );
	if ( is_array( $pdf_cfg ) ) {
		$pdf_map = [
			'account_id'    => 'apollo_cf_account_id',
			'access_key'    => 'apollo_cf_access_key',
			'secret_key'    => 'apollo_cf_secret_key',
			'bucket_name'   => 'apollo_cf_bucket',
			'custom_domain' => 'apollo_cf_public_url',
		];
		foreach ( $pdf_map as $pdf_key => $new ) {
			if ( ! empty( $pdf_cfg[ $pdf_key ] ) && ! get_option( $new ) ) {
				update_option( $new, $pdf_cfg[ $pdf_key ], false );
			}
		}
	}

	update_option( 'apollo_cf_migrated', '1', false );
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin page
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'apollo_cf_register_menu' );

function apollo_cf_register_menu(): void {
	add_menu_page(
		'Cloudflare Settings',
		'Cloudflare',
		'manage_options',
		'apollo-cloudflare',
		'apollo_cf_settings_page',
		'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path fill="#F38020" d="M78.2 55.3c.3-1 .4-2 .4-3.1 0-7.8-6.3-14.1-14.1-14.1-.9 0-1.8.1-2.7.2-1.7-6.3-7.4-11-14.3-11-7.5 0-13.7 5.6-14.5 12.9C27.5 41.2 22 47 22 54c0 .5 0 1 .1 1.5l56.1-.2z"/><path fill="#FBAD41" d="M79.8 55.3H22.1c-.1.4-.1.9-.1 1.3 0 4.5 3.7 8.2 8.2 8.2H71c4.5 0 8.2-3.7 8.2-8.2 0-.5-.1-1-.1-1.3H79.8z"/></svg>' ),
		30
	);
}

function apollo_cf_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$saved = false;
	$nonce = sanitize_key( wp_unslash( $_POST['apollo_cf_nonce'] ?? '' ) );
	if ( isset( $_POST['apollo_cf_save'] ) && wp_verify_nonce( $nonce, 'apollo_cf_save_settings' ) ) {

		$url_fields = [ 'apollo_cf_public_url', 'apollo_cf_video_public_url', 'apollo_cf_audio_public_url' ];
		$text_fields = [
			'apollo_cf_account_id',
			'apollo_cf_access_key',
			'apollo_cf_bucket',
			'apollo_cf_video_bucket',
			'apollo_cf_audio_bucket',
			'apollo_cf_api_token',
			'apollo_cf_zone_id',
			'apollo_s3_access_key',
			'apollo_s3_region',
			'apollo_s3_bucket',
		];

		foreach ( $text_fields as $field ) {
			update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) ), false );
		}
		foreach ( $url_fields as $field ) {
			update_option( $field, esc_url_raw( wp_unslash( $_POST[ $field ] ?? '' ) ), false );
		}

		update_option( 'apollo_s3_cf_url', esc_url_raw( wp_unslash( $_POST['apollo_s3_cf_url'] ?? '' ) ), false );

		$new_secret = sanitize_text_field( wp_unslash( $_POST['apollo_cf_secret_key'] ?? '' ) );
		if ( $new_secret !== '' ) {
			update_option( 'apollo_cf_secret_key', $new_secret, false );
		}
		$new_s3_secret = sanitize_text_field( wp_unslash( $_POST['apollo_s3_secret_key'] ?? '' ) );
		if ( $new_s3_secret !== '' ) {
			update_option( 'apollo_s3_secret_key', $new_s3_secret, false );
		}

		update_option( 'apollo_cf_browser_ttl', absint( $_POST['apollo_cf_browser_ttl'] ?? 14400 ), false );

		$valid_backends = [ 'r2', 's3' ];
		foreach ( [ 'apollo_storage_video', 'apollo_storage_audio', 'apollo_storage_images' ] as $bk_opt ) {
			$val = sanitize_key( wp_unslash( $_POST[ $bk_opt ] ?? 'r2' ) );
			update_option( $bk_opt, in_array( $val, $valid_backends, true ) ? $val : 'r2', false );
		}

		update_option( 'apollo_storage_anon_filenames', isset( $_POST['apollo_storage_anon_filenames'] ) ? 1 : 0, false );

		$sync_map = [
			'apollo_cf_account_id' => 'svh_r2_account_id',
			'apollo_cf_access_key' => 'svh_r2_access_key',
			'apollo_cf_bucket'     => 'svh_r2_bucket',
			'apollo_cf_public_url' => 'svh_r2_public_url',
		];
		foreach ( $sync_map as $new => $old ) {
			update_option( $old, get_option( $new, '' ), false );
		}
		if ( $new_secret !== '' ) {
			update_option( 'svh_r2_secret_key', $new_secret, false );
		}

		apollo_cf_reset_static_cache();
		if ( function_exists( 'apollo_s3_config' ) ) {
			apollo_s3_config( true );
		}
		$saved = true;
	}

	$cfg      = apollo_cf_config();
	$vid_cfg  = apollo_cf_video_config();
	$aud_cfg  = apollo_cf_audio_config();

	$r2_ready  = $cfg['account_id'] && $cfg['access_key'] && $cfg['secret_key'];
	$vid_ready = $r2_ready && $vid_cfg['bucket'];
	$aud_ready = $r2_ready && $aud_cfg['bucket'];
	$cdn_ready = $cfg['api_token'] && $cfg['zone_id'];

	$s3_cfg = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [
		'access_key' => (string) get_option( 'apollo_s3_access_key', '' ),
		'secret_key' => (string) get_option( 'apollo_s3_secret_key', '' ),
		'region'     => (string) get_option( 'apollo_s3_region', 'us-east-1' ),
		'bucket'     => (string) get_option( 'apollo_s3_bucket', '' ),
		'cf_url'     => (string) get_option( 'apollo_s3_cf_url', '' ),
	];
	$s3_ready = ! empty( $s3_cfg['access_key'] ) && ! empty( $s3_cfg['secret_key'] )
	            && ! empty( $s3_cfg['region'] )   && ! empty( $s3_cfg['bucket'] );

	$backend_video  = (string) get_option( 'apollo_storage_video',  'r2' );
	$backend_audio  = (string) get_option( 'apollo_storage_audio',  'r2' );
	$backend_images = (string) get_option( 'apollo_storage_images', 'r2' );

	$const = [
		'account_id'       => defined( 'SVH_R2_ACCOUNT_ID' ),
		'access_key'       => defined( 'SVH_R2_ACCESS_KEY' ),
		'secret_key'       => defined( 'SVH_R2_SECRET_KEY' ),
		'bucket'           => defined( 'SVH_R2_BUCKET' ),
		'public_url'       => defined( 'SVH_R2_PUBLIC_URL' ),
		'video_bucket'     => defined( 'APOLLO_VIDEO_BUCKET' ),
		'video_public_url' => defined( 'APOLLO_VIDEO_PUBLIC_URL' ),
		'audio_bucket'     => defined( 'APOLLO_AUDIO_BUCKET' ),
		'audio_public_url' => defined( 'APOLLO_AUDIO_PUBLIC_URL' ),
		'api_token'        => defined( 'APOLLO_CF_API_TOKEN' ),
		'zone_id'          => defined( 'APOLLO_CF_ZONE_ID' ),
		's3_access_key'    => defined( 'APOLLO_S3_ACCESS_KEY' ),
		's3_secret_key'    => defined( 'APOLLO_S3_SECRET_KEY' ),
		's3_region'        => defined( 'APOLLO_S3_REGION' ),
		's3_bucket'        => defined( 'APOLLO_S3_BUCKET' ),
		's3_cf_url'        => defined( 'APOLLO_S3_CF_URL' ),
	];
	$any_const = array_filter( $const );
	?>
	<div class="wrap" style="max-width:800px">
		<h1 style="display:flex;align-items:center;gap:10px">
			<span style="font-size:26px">☁</span> Cloudflare Settings
		</h1>
		<p style="color:#555;margin-top:0;margin-bottom:20px">
			Universal Cloudflare credentials and per-hub bucket configuration for
			<strong>Video Hub, Audio Hub, Secure Drop, and R2-PDF</strong>.
			Set credentials once — each hub can optionally use its own bucket and custom domain.
		</p>

		<?php if ( $saved ): ?>
		<div class="notice notice-success is-dismissible"><p>✅ Cloudflare settings saved.</p></div>
		<?php endif; ?>

		<?php if ( $any_const ): ?>
		<div class="notice notice-info" style="margin:0 0 20px">
			<p>
				<strong>🔒 wp-config.php constants detected.</strong>
				Fields set via <code>define()</code> are shown as read-only and cannot be changed here.
			</p>
		</div>
		<?php endif; ?>

		<!-- Status cards -->
		<div style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap">
			<?php $vid_backend_ok = ( $backend_video === 's3' ) ? $s3_ready : $vid_ready; ?>
			<div style="flex:1;min-width:180px;background:<?php echo $vid_backend_ok ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $vid_backend_ok ? '#bbf7d0' : '#fecaca'; ?>;border-radius:8px;padding:14px 16px">
				<strong style="color:<?php echo $vid_backend_ok ? '#166534' : '#991b1b'; ?>">
					<?php echo $vid_backend_ok ? '✅' : '❌'; ?> Video Hub
					<span style="font-size:10px;font-weight:400;background:<?php echo $backend_video==='s3'?'#dbeafe':'#fde68a'; ?>;color:<?php echo $backend_video==='s3'?'#1e40af':'#78350f'; ?>;padding:1px 5px;border-radius:8px;margin-left:4px"><?php echo $backend_video === 's3' ? 'S3' : 'R2'; ?></span>
				</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $backend_video === 's3' && $s3_ready ): ?>
						Bucket: <code><?php echo esc_html( $s3_cfg['bucket'] ); ?></code><br>
						CDN: <code><?php echo esc_html( $s3_cfg['cf_url'] ?: 's3 direct' ); ?></code>
					<?php elseif ( $backend_video === 's3' ): ?>
						S3 credentials not configured.
					<?php elseif ( $vid_ready ): ?>
						Bucket: <code><?php echo esc_html( $vid_cfg['bucket'] ); ?></code><br>
						Domain: <code><?php echo esc_html( $vid_cfg['public_url'] ?: '(r2.dev fallback)' ); ?></code>
					<?php else: ?>
						Bucket not configured.
					<?php endif; ?>
				</p>
			</div>
			<?php $aud_backend_ok = ( $backend_audio === 's3' ) ? $s3_ready : $aud_ready; ?>
			<div style="flex:1;min-width:180px;background:<?php echo $aud_backend_ok ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $aud_backend_ok ? '#bbf7d0' : '#fecaca'; ?>;border-radius:8px;padding:14px 16px">
				<strong style="color:<?php echo $aud_backend_ok ? '#166534' : '#991b1b'; ?>">
					<?php echo $aud_backend_ok ? '✅' : '❌'; ?> Audio Hub
					<span style="font-size:10px;font-weight:400;background:<?php echo $backend_audio==='s3'?'#dbeafe':'#fde68a'; ?>;color:<?php echo $backend_audio==='s3'?'#1e40af':'#78350f'; ?>;padding:1px 5px;border-radius:8px;margin-left:4px"><?php echo $backend_audio === 's3' ? 'S3' : 'R2'; ?></span>
				</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $backend_audio === 's3' && $s3_ready ): ?>
						Bucket: <code><?php echo esc_html( $s3_cfg['bucket'] ); ?></code><br>
						CDN: <code><?php echo esc_html( $s3_cfg['cf_url'] ?: 's3 direct' ); ?></code>
					<?php elseif ( $backend_audio === 's3' ): ?>
						S3 credentials not configured.
					<?php elseif ( $aud_ready ): ?>
						Bucket: <code><?php echo esc_html( $aud_cfg['bucket'] ); ?></code><br>
						Domain: <code><?php echo esc_html( $aud_cfg['public_url'] ?: '(r2.dev fallback)' ); ?></code>
					<?php else: ?>
						Bucket not configured.
					<?php endif; ?>
				</p>
			</div>
			<?php $img_backend_ok = ( $backend_images === 's3' ) ? $s3_ready : true; ?>
			<div style="flex:1;min-width:180px;background:<?php echo $img_backend_ok ? '#f0fdf4' : '#fef2f2'; ?>;border:1px solid <?php echo $img_backend_ok ? '#bbf7d0' : '#fecaca'; ?>;border-radius:8px;padding:14px 16px">
				<strong style="color:<?php echo $img_backend_ok ? '#166534' : '#991b1b'; ?>">
					<?php echo $img_backend_ok ? '✅' : '❌'; ?> Images
					<span style="font-size:10px;font-weight:400;background:<?php echo $backend_images==='s3'?'#dbeafe':'#f3f4f6'; ?>;color:<?php echo $backend_images==='s3'?'#1e40af':'#374151'; ?>;padding:1px 5px;border-radius:8px;margin-left:4px"><?php echo $backend_images === 's3' ? 'S3' : 'WP'; ?></span>
				</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $backend_images === 's3' && $s3_ready ): ?>
						Offloading to S3<br>
						CDN: <code><?php echo esc_html( $s3_cfg['cf_url'] ?: 's3 direct' ); ?></code>
					<?php elseif ( $backend_images === 's3' ): ?>
						S3 credentials not configured.
					<?php else: ?>
						WordPress media library.
					<?php endif; ?>
				</p>
			</div>
			<div style="flex:1;min-width:180px;background:<?php echo $cdn_ready ? '#f0fdf4' : '#fefce8'; ?>;border:1px solid <?php echo $cdn_ready ? '#bbf7d0' : '#fde68a'; ?>;border-radius:8px;padding:14px 16px">
				<strong style="color:<?php echo $cdn_ready ? '#166534' : '#854d0e'; ?>">
					<?php echo $cdn_ready ? '✅' : '⚠'; ?> CF Cache
				</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $cdn_ready ): ?>
						Zone: <code><?php echo esc_html( $cfg['zone_id'] ); ?></code>
					<?php else: ?>
						API Token + Zone ID needed.
					<?php endif; ?>
				</p>
			</div>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'apollo_cf_save_settings', 'apollo_cf_nonce' ); ?>
			<input type="hidden" name="apollo_cf_save" value="1">

			<!-- Shared API credentials -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🔑 Shared API Credentials</h2>
				<p style="margin:0 0 16px;font-size:12px;color:#666">
					One set of R2 API keys signs requests for all your buckets.
					Get them from <a href="https://dash.cloudflare.com/?to=/:account/r2" target="_blank" rel="noopener">Cloudflare → R2 → Manage R2 API Tokens ↗</a>
				</p>

				<?php
				$cred_fields = [
					[
						'key'   => 'account_id', 'option' => 'apollo_cf_account_id',
						'label' => 'Account ID', 'type' => 'text',
						'ph'    => '32-character hex ID from the Cloudflare dashboard sidebar',
						'help'  => 'Found in Cloudflare dashboard sidebar under "Account ID".',
					],
					[
						'key'   => 'access_key', 'option' => 'apollo_cf_access_key',
						'label' => 'Access Key ID', 'type' => 'text',
						'ph'    => 'R2 API Token Access Key ID',
						'help'  => 'From Cloudflare → R2 → Manage R2 API Tokens → Create Token.',
					],
					[
						'key'   => 'secret_key', 'option' => 'apollo_cf_secret_key',
						'label' => 'Secret Access Key', 'type' => 'password',
						'ph'    => $cfg['secret_key'] ? '(saved — type a new value to change)' : 'Secret Access Key',
						'help'  => 'Leave blank to keep the existing saved value.',
					],
				];
				foreach ( $cred_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = $f['key'] === 'secret_key' ? '' : esc_attr( $cfg[ $f['key'] ] );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>
			</div>

			<!-- Video Hub bucket -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🎬 Video Hub — Bucket & Custom Domain</h2>
				<?php
				$vid_fields = [
					[
						'key' => 'video_bucket', 'option' => 'apollo_cf_video_bucket',
						'label' => 'Video Bucket Name', 'type' => 'text',
						'ph' => 'e.g. pennytribune (leave blank to use shared bucket)',
						'help' => 'The R2 bucket that receives video uploads.',
					],
					[
						'key' => 'video_public_url', 'option' => 'apollo_cf_video_public_url',
						'label' => 'Video Custom Domain', 'type' => 'url',
						'ph' => 'https://serve.pennycdn.com',
						'help' => 'Custom domain pointed at your video R2 bucket.',
					],
				];
				foreach ( $vid_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = esc_attr( $cfg[ $f['key'] ] );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>
			</div>

			<!-- Audio Hub bucket -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🎙 Audio Hub — Bucket & Custom Domain</h2>
				<?php
				$aud_fields = [
					[
						'key' => 'audio_bucket', 'option' => 'apollo_cf_audio_bucket',
						'label' => 'Audio Bucket Name', 'type' => 'text',
						'ph' => 'e.g. pennytribune (leave blank to use shared bucket)',
						'help' => 'The R2 bucket that receives podcast/episode audio uploads.',
					],
					[
						'key' => 'audio_public_url', 'option' => 'apollo_cf_audio_public_url',
						'label' => 'Audio Custom Domain', 'type' => 'url',
						'ph' => 'https://serve.pennycdn.com',
						'help' => 'Custom domain pointed at your audio R2 bucket.',
					],
				];
				foreach ( $aud_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = esc_attr( $cfg[ $f['key'] ] );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>
			</div>

			<!-- Shared default bucket -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">📦 Shared Default Bucket</h2>
				<?php
				$default_fields = [
					[
						'key' => 'bucket', 'option' => 'apollo_cf_bucket',
						'label' => 'Default Bucket Name', 'type' => 'text',
						'ph' => 'e.g. pennytribune',
						'help' => 'Fallback bucket for any hub that does not have a hub-specific bucket set above.',
					],
					[
						'key' => 'public_url', 'option' => 'apollo_cf_public_url',
						'label' => 'Default Public URL', 'type' => 'url',
						'ph' => 'https://serve.pennycdn.com',
						'help' => 'Fallback custom domain.',
					],
				];
				foreach ( $default_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = esc_attr( $cfg[ $f['key'] ] );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>
			</div>

			<!-- Cloudflare CDN -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🌐 Cloudflare CDN — Cache Purging</h2>
				<?php
				$cdn_fields = [
					[
						'key' => 'api_token', 'option' => 'apollo_cf_api_token',
						'label' => 'API Token', 'type' => 'text',
						'ph' => 'Cloudflare API Token with Cache Purge permission',
						'help' => 'Go to Cloudflare → My Profile → API Tokens.',
					],
					[
						'key' => 'zone_id', 'option' => 'apollo_cf_zone_id',
						'label' => 'Zone ID', 'type' => 'text',
						'ph' => '32-character Zone ID',
						'help' => 'Cloudflare dashboard → your domain → Overview → Zone ID (right sidebar).',
					],
				];
				foreach ( $cdn_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = esc_attr( $cfg[ $f['key'] ] );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>

				<div style="display:flex;align-items:flex-start;gap:16px;padding:10px 0;border-top:1px solid #f0f0f0">
					<label style="min-width:180px;font-size:13px;font-weight:600;padding-top:8px;color:#222">Browser Cache TTL</label>
					<div style="flex:1">
						<input type="number" name="apollo_cf_browser_ttl" value="<?php echo esc_attr( $cfg['browser_ttl'] ); ?>"
							min="0" max="2592000" style="width:120px;padding:7px 10px;border:1px solid #ddd;border-radius:4px"> seconds
						<p style="margin:4px 0 0;font-size:11px;color:#888">Default: 14400 (4 hours).</p>
					</div>
				</div>
			</div>

			<!-- Amazon S3 -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">☁ Amazon S3 Settings</h2>
				<?php
				$s3_fields = [
					[
						'key'  => 's3_access_key', 'option' => 'apollo_s3_access_key',
						'label' => 'Access Key ID', 'type' => 'text',
						'ph'   => 'AKIAIOSFODNN7EXAMPLE',
						'help' => 'AWS IAM user Access Key ID.',
					],
					[
						'key'  => 's3_secret_key', 'option' => 'apollo_s3_secret_key',
						'label' => 'Secret Access Key', 'type' => 'password',
						'ph'   => $s3_cfg['secret_key'] ? '(saved — type a new value to change)' : 'Secret Access Key',
						'help' => 'Leave blank to keep the existing saved value.',
					],
					[
						'key'  => 's3_region', 'option' => 'apollo_s3_region',
						'label' => 'AWS Region', 'type' => 'text',
						'ph'   => 'us-east-1',
						'help' => 'AWS region where your S3 bucket lives.',
					],
					[
						'key'  => 's3_bucket', 'option' => 'apollo_s3_bucket',
						'label' => 'Bucket Name', 'type' => 'text',
						'ph'   => 'pennytribune-media',
						'help' => 'Your S3 bucket name.',
					],
					[
						'key'  => 's3_cf_url', 'option' => 'apollo_s3_cf_url',
						'label' => 'CloudFront URL', 'type' => 'url',
						'ph'   => 'https://d1234abcdef.cloudfront.net',
						'help' => 'Your CloudFront distribution URL pointed at this S3 bucket.',
					],
				];
				foreach ( $s3_fields as $f ):
					$locked = $const[ $f['key'] ] ?? false;
					$val    = $f['key'] === 's3_secret_key' ? '' : esc_attr( $s3_cfg[ str_replace( 's3_', '', $f['key'] ) ] ?? '' );
				?>
				<?php apollo_cf_field_row( $f['label'], $f['option'], $f['type'], $val, $f['ph'], $f['help'], $locked ); ?>
				<?php endforeach; ?>
			</div>

			<!-- Storage Backend -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🗄 Storage Backend</h2>
				<?php
				$hub_backends = [
					[
						'opt'   => 'apollo_storage_video',
						'label' => '🎬 Video Hub',
						'val'   => $backend_video,
						'note'  => 'MP4/HLS video files uploaded through the Video Hub.',
					],
					[
						'opt'   => 'apollo_storage_audio',
						'label' => '🎙 Audio Hub',
						'val'   => $backend_audio,
						'note'  => 'Podcast and episode audio files uploaded through the Audio Hub.',
					],
					[
						'opt'   => 'apollo_storage_images',
						'label' => '🖼 Images',
						'val'   => $backend_images,
						'note'  => 'WordPress media library image uploads.',
					],
				];
				foreach ( $hub_backends as $hb ): ?>
				<div style="display:flex;align-items:flex-start;gap:16px;padding:12px 0;border-top:1px solid #f0f0f0">
					<label style="min-width:180px;font-size:13px;font-weight:600;padding-top:4px;color:#222"><?php echo $hb['label']; ?></label>
					<div style="flex:1">
						<label style="display:inline-flex;align-items:center;gap:6px;margin-right:20px;font-size:13px;cursor:pointer">
							<input type="radio" name="<?php echo esc_attr( $hb['opt'] ); ?>" value="r2"
								<?php checked( $hb['val'], 'r2' ); ?> style="margin:0">
							<span>☁ Cloudflare R2</span>
						</label>
						<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;cursor:pointer">
							<input type="radio" name="<?php echo esc_attr( $hb['opt'] ); ?>" value="s3"
								<?php checked( $hb['val'], 's3' ); ?> style="margin:0">
							<span>☁ Amazon S3</span>
						</label>
						<p style="margin:5px 0 0;font-size:11px;color:#888"><?php echo esc_html( $hb['note'] ); ?></p>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<!-- Filename Anonymisation -->
			<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
				<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">🔒 Filename Anonymisation</h2>
				<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;cursor:pointer">
					<input type="checkbox" name="apollo_storage_anon_filenames" value="1"
						<?php checked( (bool) get_option( 'apollo_storage_anon_filenames', 0 ) ); ?>
						style="width:16px;height:16px;margin:0">
					<span style="font-weight:600">Anonymise filenames for new uploads</span>
				</label>
				<p style="margin:8px 0 0;font-size:11px;color:#888">
					Example: <code>videos/2026/04/media-V-04817263951738402657.mp4</code>
				</p>
			</div>

			<?php submit_button( 'Save Settings', 'primary large' ); ?>
		</form>

		<!-- R2 Connection Test -->
		<?php if ( $r2_ready ): ?>
		<div style="margin-top:24px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px">
			<h2 style="margin:0 0 8px;font-size:15px;font-weight:700">🔧 Test R2 Connection</h2>
			<button id="apollo-cf-test-btn" class="button button-secondary">Run R2 Connection Test</button>
			<span id="apollo-cf-test-result" style="margin-left:12px;font-size:13px;font-weight:600"></span>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('apollo-cf-test-btn');
			var res = document.getElementById('apollo-cf-test-result');
			if (!btn) return;
			btn.addEventListener('click', function(){
				btn.disabled = true; res.style.color='#888'; res.textContent='Testing…';
				var fd = new FormData();
				fd.append('action','apollo_cf_test_connection');
				fd.append('nonce',<?php echo wp_json_encode( wp_create_nonce('apollo_cf_test') ); ?>);
				fetch(ajaxurl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
					btn.disabled=false;
					res.style.color=d.success?'#166534':'#991b1b';
					res.textContent=(d.success?'✅ ':'❌ ')+d.data;
				}).catch(function(e){btn.disabled=false;res.style.color='#991b1b';res.textContent='❌ '+e.message;});
			});
		})();
		</script>
		<?php endif; ?>

		<?php if ( $s3_ready ): ?>
		<div style="margin-top:16px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px">
			<h2 style="margin:0 0 8px;font-size:15px;font-weight:700">🔧 Test S3 Connection</h2>
			<button id="apollo-s3-test-btn" class="button button-secondary">Run S3 Connection Test</button>
			<span id="apollo-s3-test-result" style="margin-left:12px;font-size:13px;font-weight:600"></span>
		</div>
		<script>
		(function(){
			var btn = document.getElementById('apollo-s3-test-btn');
			var res = document.getElementById('apollo-s3-test-result');
			if (!btn) return;
			btn.addEventListener('click', function(){
				btn.disabled=true; res.style.color='#888'; res.textContent='Testing…';
				var fd = new FormData();
				fd.append('action','apollo_s3_test_connection');
				fd.append('nonce',<?php echo wp_json_encode( wp_create_nonce('apollo_s3_test') ); ?>);
				fetch(ajaxurl,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
					btn.disabled=false;
					res.style.color=d.success?'#166534':'#991b1b';
					res.textContent=(d.success?'✅ ':'❌ ')+d.data;
				}).catch(function(e){btn.disabled=false;res.style.color='#991b1b';res.textContent='❌ '+e.message;});
			});
		})();
		</script>
		<?php endif; ?>
	</div>
	<?php
}

function apollo_cf_field_row( string $label, string $name, string $type, string $value, string $ph, string $help, bool $locked ): void {
	$input_style = $locked
		? 'background:#f5f5f5;color:#888;cursor:not-allowed;width:100%;max-width:480px;padding:7px 10px;border:1px solid #ddd;border-radius:4px'
		: 'width:100%;max-width:480px;padding:7px 10px;border:1px solid #ddd;border-radius:4px';
	?>
	<div style="display:flex;align-items:flex-start;gap:16px;padding:10px 0;border-top:1px solid #f0f0f0">
		<label style="min-width:180px;font-size:13px;font-weight:600;padding-top:8px;color:#222">
			<?php echo esc_html( $label ); ?>
			<?php if ( $locked ): ?><span style="font-size:10px;font-weight:400;color:#888;display:block">🔒 via wp-config.php</span><?php endif; ?>
		</label>
		<div style="flex:1">
			<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>"
				value="<?php echo $value; ?>" placeholder="<?php echo esc_attr( $ph ); ?>"
				class="regular-text" style="<?php echo $input_style; ?>"
				<?php echo $locked ? 'readonly' : ''; ?>>
			<p style="margin:4px 0 0;font-size:11px;color:#888"><?php echo esc_html( $help ); ?></p>
		</div>
	</div>
	<?php
}

function apollo_cf_reset_static_cache(): void {
	apollo_cf_config( true );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX handlers
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_s3_test_connection', 'apollo_s3_ajax_test_connection' );
add_action( 'wp_ajax_apollo_cf_test_connection', 'apollo_cf_ajax_test_connection' );

function apollo_cf_ajax_test_connection(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );
	check_ajax_referer( 'apollo_cf_test', 'nonce' );

	$cfg = apollo_cf_config();

	if ( empty( $cfg['account_id'] ) || empty( $cfg['access_key'] ) || empty( $cfg['secret_key'] ) ) {
		wp_send_json_error( 'Credentials not fully configured — Account ID, Access Key, and Secret Key are all required.' );
	}

	$acct       = trim( $cfg['account_id'] );
	$key_id     = trim( $cfg['access_key'] );
	$secret     = trim( $cfg['secret_key'] );
	$host       = "{$acct}.r2.cloudflarestorage.com";
	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );
	$region     = 'auto';
	$service    = 's3';
	$body_hash  = hash( 'sha256', '' );

	$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$body_hash}\nx-amz-date:{$amz_date}\n";
	$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
	$canonical = "GET\n/\n\n{$canonical_headers}\n{$signed_headers}\n{$body_hash}";
	$scope     = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date, true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region, true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	$signature = hash_hmac( 'sha256', $str_to_sign,   $k_signing );
	$auth = "AWS4-HMAC-SHA256 Credential={$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request,SignedHeaders={$signed_headers},Signature={$signature}";

	$response = wp_remote_get( "https://{$host}/", [
		'headers' => [
			'Authorization'        => $auth,
			'x-amz-content-sha256' => $body_hash,
			'x-amz-date'           => $amz_date,
		],
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'Connection failed: ' . $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );

	if ( $code === 200 || $code === 403 ) {
		wp_send_json_success( "Connected to R2 (HTTP {$code}). Bucket: " . trim( $cfg['bucket'] ?: '(none)' ) . ". Credentials are valid." );
	}

	$r2_code = $r2_msg = '';
	if ( preg_match( '/<Code>([^<]+)<\/Code>/', $body, $mc ) ) $r2_code = $mc[1];
	if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $mm ) ) $r2_msg = $mm[1];
	$detail = $r2_code ? "{$r2_code}: {$r2_msg}" : substr( wp_strip_all_tags( $body ), 0, 200 );
	wp_send_json_error( "R2 returned HTTP {$code} — {$detail}." );
}

function apollo_s3_ajax_test_connection(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Insufficient permissions.' );
	check_ajax_referer( 'apollo_s3_test', 'nonce' );

	$s3 = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [
		'access_key' => (string) get_option( 'apollo_s3_access_key', '' ),
		'secret_key' => (string) get_option( 'apollo_s3_secret_key', '' ),
		'region'     => (string) get_option( 'apollo_s3_region', 'us-east-1' ),
		'bucket'     => (string) get_option( 'apollo_s3_bucket', '' ),
	];

	if ( empty( $s3['access_key'] ) || empty( $s3['secret_key'] ) || empty( $s3['region'] ) || empty( $s3['bucket'] ) ) {
		wp_send_json_error( 'S3 credentials not fully configured.' );
	}

	$key_id     = trim( $s3['access_key'] );
	$secret     = trim( $s3['secret_key'] );
	$region     = trim( $s3['region'] );
	$bucket     = trim( $s3['bucket'] );
	$host       = "{$bucket}.s3.{$region}.amazonaws.com";
	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );
	$body_hash  = hash( 'sha256', '' );

	$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$body_hash}\nx-amz-date:{$amz_date}\n";
	$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';
	$canonical_request = "HEAD\n/\n\n{$canonical_headers}\n{$signed_headers}\n{$body_hash}";
	$scope       = "{$date_stamp}/{$region}/s3/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date, true );
	$k_service = hash_hmac( 'sha256', 's3',           $k_region, true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	$signature = hash_hmac( 'sha256', $str_to_sign,   $k_signing );
	$auth = "AWS4-HMAC-SHA256 Credential={$key_id}/{$date_stamp}/{$region}/s3/aws4_request,SignedHeaders={$signed_headers},Signature={$signature}";

	$response = wp_remote_head( "https://{$host}/", [
		'headers' => [
			'Authorization'        => $auth,
			'x-amz-content-sha256' => $body_hash,
			'x-amz-date'           => $amz_date,
		],
		'timeout' => 15,
	] );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( 'Connection failed: ' . $response->get_error_message() );
	}

	$code = wp_remote_retrieve_response_code( $response );

	if ( $code === 200 ) {
		wp_send_json_success( "Connected to S3 (HTTP 200). Bucket: {$bucket} in {$region}." );
	}
	if ( $code === 403 ) {
		wp_send_json_success( "S3 credentials authenticated (HTTP 403). Bucket: {$bucket}." );
	}
	if ( $code === 301 ) {
		$redirect = wp_remote_retrieve_header( $response, 'x-amz-bucket-region' );
		wp_send_json_error( $redirect ? "Bucket is in region '{$redirect}', not '{$region}'." : "Bucket redirect — check your region." );
	}
	if ( $code === 404 ) {
		wp_send_json_error( "Bucket '{$bucket}' not found in region '{$region}'." );
	}

	wp_send_json_error( "S3 returned HTTP {$code}." );
}
