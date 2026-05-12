<?php
/**
 * Storage Migration Tool — Bidirectional R2 ↔ S3
 *
 * Admin page that copies files between Cloudflare R2 and Amazon S3 in either
 * direction (one batch at a time via AJAX) and rewrites database URLs so
 * post_content and post_meta point to the new storage domain.
 *
 * Directions:
 *   R2 → S3  : List R2 objects, stream-download via presigned GET, PUT to S3
 *   S3 → R2  : List S3 objects, stream-download via presigned GET, PUT to R2
 *
 * Access: WordPress Admin → Cloudflare → Storage Migration
 * Capability required: manage_options
 *
 * Dependencies (loaded via BOOT_MANIFEST before this file):
 *   - modules/storage/s3-core.php
 *   - modules/admin-ui/cloudflare-settings.php — apollo_cf_config()
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// Priority 20 — must fire AFTER apollo_cf_register_menu (priority 10).
add_action( 'admin_menu', 'apollo_s3_sync_register_menu', 20 );

function apollo_s3_sync_register_menu(): void {
	add_submenu_page(
		'apollo-cloudflare',
		'Storage Migration',
		'Storage Migration',
		'manage_options',
		'apollo-s3-sync',
		'apollo_s3_sync_page'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin page rendering
// ─────────────────────────────────────────────────────────────────────────────

function apollo_s3_sync_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$cf  = apollo_cf_config();
	$s3  = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [];

	$r2_ready = ! empty( $cf['account_id'] ) && ! empty( $cf['access_key'] )
	          && ! empty( $cf['secret_key'] ) && ! empty( $cf['bucket'] );
	$s3_ready = ! empty( $s3['access_key'] ) && ! empty( $s3['secret_key'] )
	          && ! empty( $s3['region'] )     && ! empty( $s3['bucket'] );

	$r2_domain = rtrim( (string) ( $cf['public_url'] ?: '' ), '/' );
	$s3_domain = rtrim( (string) ( $s3['cf_url'] ?? get_option( 'apollo_s3_cf_url', '' ) ), '/' );

	// Nonces for both directions
	$n_r2s3_scan  = wp_create_nonce( 'apollo_s3_sync_scan' );
	$n_r2s3_batch = wp_create_nonce( 'apollo_s3_sync_batch' );
	$n_r2s3_db    = wp_create_nonce( 'apollo_s3_sync_db' );
	$n_s3r2_scan  = wp_create_nonce( 'apollo_r2_sync_scan' );
	$n_s3r2_batch = wp_create_nonce( 'apollo_r2_sync_batch' );
	$n_s3r2_db    = wp_create_nonce( 'apollo_r2_sync_db' );
	?>
	<div class="wrap" style="max-width:860px">
		<h1 style="display:flex;align-items:center;gap:10px">
			<span style="font-size:22px">☁↔☁</span> Storage Migration Tool
		</h1>
		<p style="color:#555;margin-top:0">
			Copy files between Cloudflare R2 and Amazon S3 in either direction,
			then rewrite database URLs to match your new storage domain.
		</p>

		<?php if ( ! $r2_ready ): ?>
		<div class="notice notice-error"><p>❌ <strong>R2 not configured.</strong> Go to <a href="<?php echo esc_url(admin_url('admin.php?page=apollo-cloudflare')); ?>">Cloudflare → Settings</a> and enter your R2 credentials.</p></div>
		<?php endif; ?>

		<?php if ( ! $s3_ready ): ?>
		<div class="notice notice-error"><p>❌ <strong>S3 not configured.</strong> Go to <a href="<?php echo esc_url(admin_url('admin.php?page=apollo-cloudflare')); ?>">Cloudflare → Settings</a> and enter your Amazon S3 credentials.</p></div>
		<?php endif; ?>

		<!-- Direction toggle -->
		<div style="display:flex;gap:0;margin-bottom:20px;border:1px solid #ddd;border-radius:8px;overflow:hidden;width:fit-content">
			<button id="dir-btn-r2-s3" onclick="apolloSyncSetDir('r2-s3')"
				style="padding:9px 20px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:#2563eb;color:#fff">
				📦→☁ R2 → S3
			</button>
			<button id="dir-btn-s3-r2" onclick="apolloSyncSetDir('s3-r2')"
				style="padding:9px 20px;border:none;cursor:pointer;font-size:13px;font-weight:600;background:#f3f4f6;color:#374151;border-left:1px solid #ddd">
				☁→📦 S3 → R2
			</button>
		</div>

		<!-- Config summary — two panels, shown based on direction -->
		<div id="cards-r2-s3" style="display:flex;gap:12px;margin-bottom:24px;flex-wrap:wrap">
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">📦 Source — Cloudflare R2</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					Bucket: <code><?php echo esc_html( $cf['bucket'] ); ?></code><br>
					Domain: <code><?php echo esc_html( $r2_domain ?: '(default r2.dev)' ); ?></code>
				</p>
			</div>
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">☁ Destination — Amazon S3</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					Bucket: <code><?php echo esc_html( $s3['bucket'] ?? '' ); ?></code><br>
					Region: <code><?php echo esc_html( $s3['region'] ?? '' ); ?></code><br>
					CDN: <code><?php echo esc_html( $s3_domain ?: '(S3 direct)' ); ?></code>
				</p>
			</div>
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">🔄 DB URL Rewrite</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $r2_domain && $s3_domain ): ?>
					<code style="color:#059669"><?php echo esc_html( $r2_domain ); ?></code><br>
					→ <code style="color:#2563eb"><?php echo esc_html( $s3_domain ); ?></code>
					<?php else: ?>
					<span style="color:#d97706">⚠ Set both R2 Public URL and S3 CloudFront URL to enable DB rewrite.</span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<div id="cards-s3-r2" style="display:none;gap:12px;margin-bottom:24px;flex-wrap:wrap">
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">☁ Source — Amazon S3</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					Bucket: <code><?php echo esc_html( $s3['bucket'] ?? '' ); ?></code><br>
					Region: <code><?php echo esc_html( $s3['region'] ?? '' ); ?></code><br>
					CDN: <code><?php echo esc_html( $s3_domain ?: '(S3 direct)' ); ?></code>
				</p>
			</div>
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">📦 Destination — Cloudflare R2</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					Bucket: <code><?php echo esc_html( $cf['bucket'] ); ?></code><br>
					Domain: <code><?php echo esc_html( $r2_domain ?: '(default r2.dev)' ); ?></code>
				</p>
			</div>
			<div style="flex:1;min-width:200px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px 16px">
				<strong style="font-size:13px">🔄 DB URL Rewrite</strong>
				<p style="margin:6px 0 0;font-size:12px;color:#555">
					<?php if ( $s3_domain && $r2_domain ): ?>
					<code style="color:#2563eb"><?php echo esc_html( $s3_domain ); ?></code><br>
					→ <code style="color:#059669"><?php echo esc_html( $r2_domain ); ?></code>
					<?php else: ?>
					<span style="color:#d97706">⚠ Set both R2 Public URL and S3 CloudFront URL to enable DB rewrite.</span>
					<?php endif; ?>
				</p>
			</div>
		</div>

		<?php if ( $r2_ready && $s3_ready ): ?>

		<!-- Progress display (shared by both directions) -->
		<div id="apollo-sync-card" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
			<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
				<h2 style="margin:0;font-size:15px;font-weight:700">📋 Sync Progress</h2>
				<span id="apollo-sync-badge" style="font-size:11px;padding:2px 8px;border-radius:10px;background:#f3f4f6;color:#374151">Idle</span>
			</div>

			<div style="margin-bottom:12px">
				<div style="display:flex;justify-content:space-between;margin-bottom:4px">
					<span id="apollo-sync-status" style="font-size:13px;color:#374151">Ready to scan.</span>
					<span id="apollo-sync-pct" style="font-size:12px;color:#6b7280;font-weight:600">0%</span>
				</div>
				<div style="background:#e5e7eb;border-radius:4px;height:10px;overflow:hidden">
					<div id="apollo-sync-bar" style="background:linear-gradient(90deg,#6366f1,#818cf8);height:100%;width:0%;transition:width .3s"></div>
				</div>
			</div>

			<div id="apollo-sync-stats" style="display:flex;gap:20px;font-size:12px;color:#6b7280;margin-bottom:16px">
				<span>Scanned: <strong id="stat-scanned">0</strong></span>
				<span>Copied: <strong id="stat-copied">0</strong></span>
				<span>Skipped: <strong id="stat-skipped">0</strong></span>
				<span>Errors: <strong id="stat-errors">0</strong></span>
			</div>

			<div id="apollo-sync-log" style="background:#0f172a;color:#94a3b8;font-family:monospace;font-size:11px;padding:10px 14px;border-radius:6px;height:160px;overflow-y:auto;margin-bottom:16px;line-height:1.7">
				<span style="color:#475569">// Log will appear here…</span>
			</div>

			<div style="display:flex;gap:10px;flex-wrap:wrap">
				<button id="apollo-sync-start" class="button button-primary">
					🚀 Start Sync
				</button>
				<button id="apollo-sync-db" class="button button-secondary"
					<?php echo ( $r2_domain && $s3_domain ) ? '' : 'disabled title="Set both CDN domains first"'; ?>>
					🔄 Rewrite DB URLs only
				</button>
				<button id="apollo-sync-stop" class="button" style="display:none">
					⏹ Stop
				</button>
			</div>
		</div>

		<!-- Warning notice — content updated by JS based on direction -->
		<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;font-size:12px;color:#78350f">
			<strong>⚠ Before you start:</strong>
			<ul style="margin:6px 0 0 16px;padding:0">
				<li>Sync <strong>copies</strong> files — it does not delete them from the source. Source files remain available during and after migration.</li>
				<li>The DB rewrite touches <code>wp_posts.post_content</code>, <code>wp_postmeta.meta_value</code>, and <code>wp_options.option_value</code>. A database backup is strongly recommended first.</li>
				<li>Large buckets (100K+ files) may take many minutes. You can stop and resume — progress is tracked by a continuation token stored in wp_options.</li>
				<li>After sync completes, switch per-hub backend in <a href="<?php echo esc_url(admin_url('admin.php?page=apollo-cloudflare')); ?>">Cloudflare → Settings → Storage Backend</a>.</li>
			</ul>
		</div>

		<script>
		(function () {
			var ajaxUrl    = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
			var dbEnabled  = <?php echo wp_json_encode( (bool) ( $r2_domain && $s3_domain ) ); ?>;

			// Nonces — R2→S3
			var nR2S3 = {
				scan:  <?php echo wp_json_encode( $n_r2s3_scan ); ?>,
				batch: <?php echo wp_json_encode( $n_r2s3_batch ); ?>,
				db:    <?php echo wp_json_encode( $n_r2s3_db ); ?>
			};
			// Nonces — S3→R2
			var nS3R2 = {
				scan:  <?php echo wp_json_encode( $n_s3r2_scan ); ?>,
				batch: <?php echo wp_json_encode( $n_s3r2_batch ); ?>,
				db:    <?php echo wp_json_encode( $n_s3r2_db ); ?>
			};

			// AJAX action names by direction
			var actions = {
				'r2-s3': { scan: 'apollo_s3_sync_scan',   batch: 'apollo_s3_sync_batch',   db: 'apollo_s3_sync_db_rewrite' },
				's3-r2': { scan: 'apollo_r2_sync_scan',   batch: 'apollo_r2_sync_batch',   db: 'apollo_r2_sync_db_rewrite' }
			};

			var direction  = 'r2-s3';
			var running    = false;
			var stopped    = false;
			var contToken  = '';
			var statScanned = 0, statCopied = 0, statSkipped = 0, statErrors = 0;

			var btnStart = document.getElementById('apollo-sync-start');
			var btnDb    = document.getElementById('apollo-sync-db');
			var btnStop  = document.getElementById('apollo-sync-stop');
			var bar      = document.getElementById('apollo-sync-bar');
			var status   = document.getElementById('apollo-sync-status');
			var pctEl    = document.getElementById('apollo-sync-pct');
			var badge    = document.getElementById('apollo-sync-badge');
			var log      = document.getElementById('apollo-sync-log');

			// Exposed globally for the direction toggle buttons
			window.apolloSyncSetDir = function(dir) {
				if (running) return; // don't switch mid-run
				direction = dir;
				document.getElementById('cards-r2-s3').style.display = (dir === 'r2-s3') ? 'flex' : 'none';
				document.getElementById('cards-s3-r2').style.display = (dir === 's3-r2') ? 'flex' : 'none';
				var btnActive   = document.getElementById('dir-btn-' + dir);
				var btnInactive = document.getElementById('dir-btn-' + (dir === 'r2-s3' ? 's3-r2' : 'r2-s3'));
				btnActive.style.background   = '#2563eb';
				btnActive.style.color        = '#fff';
				btnInactive.style.background = '#f3f4f6';
				btnInactive.style.color      = '#374151';
				// Reset UI
				log.innerHTML = '<span style="color:#475569">// Log will appear here…</span>';
				status.textContent = 'Ready to scan.';
				setProgress(0);
				setBadge('Idle', '#f3f4f6', '#374151');
				statScanned = statCopied = statSkipped = statErrors = 0;
				updateStats();
				contToken = '';
			};

			function nonces() {
				return direction === 'r2-s3' ? nR2S3 : nS3R2;
			}

			function logLine(msg, color) {
				var span = document.createElement('span');
				span.style.color   = color || '#94a3b8';
				span.style.display = 'block';
				span.textContent   = '[' + new Date().toTimeString().slice(0,8) + '] ' + msg;
				log.appendChild(span);
				log.scrollTop = log.scrollHeight;
			}

			function updateStats() {
				document.getElementById('stat-scanned').textContent = statScanned;
				document.getElementById('stat-copied').textContent  = statCopied;
				document.getElementById('stat-skipped').textContent = statSkipped;
				document.getElementById('stat-errors').textContent  = statErrors;
			}

			function setBadge(text, bg, fg) {
				badge.textContent      = text;
				badge.style.background = bg  || '#f3f4f6';
				badge.style.color      = fg  || '#374151';
			}

			function setProgress(pct, msg) {
				bar.style.width    = pct + '%';
				pctEl.textContent  = pct + '%';
				if (msg) status.textContent = msg;
			}

			function post(action, nonce, extra) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce',  nonce);
				if (extra) Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); });
				return fetch(ajaxUrl, { method: 'POST', body: fd }).then(function (r) { return r.json(); });
			}

			// ── Batch loop ────────────────────────────────────────────────────
			function runBatch() {
				if (stopped) {
					setBadge('Stopped', '#fef3c7', '#92400e');
					status.textContent = 'Stopped. Click Start Sync to resume.';
					btnStart.disabled  = false;
					btnStop.style.display = 'none';
					return;
				}

				var a = actions[direction];
				var n = nonces();
				post(a.batch, n.batch, { cont_token: contToken })
					.then(function (d) {
						if (!d.success) {
							logLine('ERROR: ' + d.data, '#f87171');
							statErrors++;
							updateStats();
							setBadge('Error', '#fef2f2', '#991b1b');
							status.textContent = 'Error — see log. Retry or stop.';
							btnStart.disabled  = false;
							btnStop.style.display = 'none';
							return;
						}

						var data = d.data;
						statScanned += data.scanned || 0;
						statCopied  += data.copied  || 0;
						statSkipped += data.skipped || 0;
						statErrors  += data.errors  || 0;
						updateStats();

						if (data.log && data.log.length) {
							data.log.forEach(function (entry) {
								logLine(entry.msg, entry.ok ? '#4ade80' : '#f87171');
							});
						}

						contToken  = data.cont_token || '';
						var pct    = data.progress_pct || 0;
						setProgress(pct, 'Syncing… ' + statCopied + ' copied, ' + statScanned + ' scanned');

						if (data.done) {
							setProgress(100, '✓ All files synced!');
							setBadge('Complete', '#f0fdf4', '#166534');
							logLine('✓ Sync complete. ' + statCopied + ' copied, ' + statSkipped + ' skipped, ' + statErrors + ' errors.', '#4ade80');
							btnStart.disabled = false;
							btnStop.style.display = 'none';
							if (dbEnabled) {
								logLine('Running DB URL rewrite…', '#60a5fa');
								runDbRewrite();
							}
						} else {
							setTimeout(runBatch, 300);
						}
					})
					.catch(function (err) {
						logLine('Network error: ' + err.message, '#f87171');
						setBadge('Error', '#fef2f2', '#991b1b');
						btnStart.disabled = false;
						btnStop.style.display = 'none';
					});
			}

			// ── DB rewrite ────────────────────────────────────────────────────
			function runDbRewrite() {
				var a = actions[direction];
				var n = nonces();
				post(a.db, n.db, {})
					.then(function (d) {
						if (d.success) {
							logLine('✓ DB rewrite done: ' + (d.data.replaced || 0) + ' rows updated.', '#4ade80');
						} else {
							logLine('DB rewrite error: ' + d.data, '#f87171');
						}
					})
					.catch(function (err) { logLine('DB rewrite network error: ' + err.message, '#f87171'); });
			}

			if (btnStart) {
				btnStart.addEventListener('click', function () {
					running = true; stopped = false;
					btnStart.disabled = true;
					btnStop.style.display = '';
					setBadge('Running', '#dbeafe', '#1e40af');
					log.innerHTML = '';
					logLine('Starting ' + (direction === 'r2-s3' ? 'R2 → S3' : 'S3 → R2') + ' sync…', '#60a5fa');

					var a = actions[direction];
					var n = nonces();
					post(a.scan, n.scan, {})
						.then(function (d) {
							if (!d.success) {
								logLine('Scan error: ' + d.data, '#f87171');
								setBadge('Error', '#fef2f2', '#991b1b');
								btnStart.disabled = false;
								btnStop.style.display = 'none';
								return;
							}
							contToken = d.data.cont_token || '';
							statScanned = statCopied = statSkipped = statErrors = 0;
							updateStats();
							runBatch();
						})
						.catch(function (err) {
							logLine('Scan network error: ' + err.message, '#f87171');
							btnStart.disabled = false;
							btnStop.style.display = 'none';
						});
				});
			}

			if (btnStop) {
				btnStop.addEventListener('click', function () {
					stopped = true;
					btnStop.disabled = true;
					logLine('Stop requested…', '#fbbf24');
				});
			}

			if (btnDb) {
				btnDb.addEventListener('click', function () {
					btnDb.disabled = true;
					logLine('Running DB URL rewrite (' + (direction === 'r2-s3' ? 'R2→S3' : 'S3→R2') + ')…', '#60a5fa');
					setBadge('Rewriting', '#dbeafe', '#1e40af');
					runDbRewrite();
					setTimeout(function () {
						btnDb.disabled = false;
						setBadge('Done', '#f0fdf4', '#166534');
					}, 3000);
				});
			}
		}());
		</script>

		<?php else: ?>
		<div class="notice notice-warning">
			<p>Configure both R2 and S3 credentials in <a href="<?php echo esc_url(admin_url('admin.php?page=apollo-cloudflare')); ?>">Cloudflare → Settings</a> before using this tool.</p>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: R2→S3 Scan — clears saved token to restart from beginning
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_s3_sync_scan', 'apollo_s3_ajax_sync_scan' );

function apollo_s3_ajax_sync_scan(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_s3_sync_scan', 'nonce' );
	delete_option( 'apollo_s3_sync_cont_token' );
	wp_send_json_success( [ 'cont_token' => '' ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: R2→S3 Batch — list up to 1 R2 object, download, upload to S3
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_s3_sync_batch', 'apollo_s3_ajax_sync_batch' );

function apollo_s3_ajax_sync_batch(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_s3_sync_batch', 'nonce' );

	if ( ! function_exists( 'apollo_s3_config' ) ) {
		wp_send_json_error( 'S3 module not loaded.' );
	}

	$r2_cfg = apollo_cf_config();
	$s3_cfg = apollo_s3_config();

	if ( empty( $r2_cfg['account_id'] ) || empty( $r2_cfg['access_key'] ) || empty( $r2_cfg['secret_key'] ) || empty( $r2_cfg['bucket'] ) ) {
		wp_send_json_error( 'R2 credentials not configured.' );
	}
	if ( empty( $s3_cfg['access_key'] ) || empty( $s3_cfg['secret_key'] ) || empty( $s3_cfg['region'] ) || empty( $s3_cfg['bucket'] ) ) {
		wp_send_json_error( 'S3 credentials not configured.' );
	}

	$cont_token = sanitize_text_field( wp_unslash( $_POST['cont_token'] ?? '' ) );

	$list_result = apollo_r2_list_page( null, 1, $cont_token );
	if ( is_wp_error( $list_result ) ) {
		wp_send_json_error( 'R2 list error: ' . $list_result->get_error_message() );
	}

	$objects    = $list_result['objects']    ?? [];
	$next_token = $list_result['next_token'] ?? '';
	$is_done    = empty( $next_token );

	if ( $next_token ) {
		update_option( 'apollo_s3_sync_cont_token', $next_token, false );
	} else {
		delete_option( 'apollo_s3_sync_cont_token' );
	}

	$log     = [];
	$copied  = 0;
	$skipped = 0;
	$errors  = 0;
	$scanned = count( $objects );

	$mpu_threshold = 5 * 1024 * 1024;
	$mpu_part_size = 50 * 1024 * 1024;

	foreach ( $objects as $obj ) {
		$key  = $obj['key']  ?? '';
		$size = (int) ( $obj['size'] ?? 0 );
		if ( ! $key ) { $skipped++; continue; }

		$ext          = strtolower( pathinfo( $key, PATHINFO_EXTENSION ) );
		$content_type = $obj['content_type'] ?? 'application/octet-stream';
		$size_label   = $size > 0 ? ( $size >= 1048576 ? round( $size / 1048576, 1 ) . ' MB' : round( $size / 1024, 1 ) . ' KB' ) : '?';

		// Stream from R2 to temp file
		$get_url  = svh_r2_presign_get_url( $key, 3600 );
		$tmp_path = wp_tempnam( 'apollo-r2-sync-' . md5( $key ) );

		$response = wp_remote_get( $get_url, [
			'timeout'    => 300,
			'stream'     => true,
			'filename'   => $tmp_path,
			'decompress' => false,
		] );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL GET {$key}: " . $response->get_error_message() ];
			$errors++;
			continue;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL GET {$key}: HTTP {$http_code}" ];
			$errors++;
			continue;
		}

		$actual_size = file_exists( $tmp_path ) ? filesize( $tmp_path ) : 0;
		if ( $actual_size === 0 ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL {$key}: downloaded file is empty" ];
			$errors++;
			continue;
		}

		// Upload to S3
		if ( $actual_size <= $mpu_threshold ) {
			$body       = file_get_contents( $tmp_path );
			@unlink( $tmp_path );
			$put_result = apollo_s3_put_object( $key, $body, $content_type );
			unset( $body );
			if ( is_wp_error( $put_result ) ) {
				$log[] = [ 'ok' => false, 'msg' => "FAIL PUT {$key}: " . $put_result->get_error_message() ];
				$errors++;
				continue;
			}
		} else {
			$upload_id = apollo_s3_mpu_init( $key, $content_type );
			if ( is_wp_error( $upload_id ) ) {
				@unlink( $tmp_path );
				$log[] = [ 'ok' => false, 'msg' => "FAIL MPU INIT {$key}: " . $upload_id->get_error_message() ];
				$errors++;
				continue;
			}

			$fh       = fopen( $tmp_path, 'rb' );
			$part_num = 1;
			$parts    = [];
			$mpu_ok   = true;

			while ( ! feof( $fh ) ) {
				$chunk = fread( $fh, $mpu_part_size );
				if ( $chunk === false || strlen( $chunk ) === 0 ) break;

				$part_url  = apollo_s3_presign_part_url( $key, $upload_id, $part_num, 3600 );
				$part_resp = wp_remote_request( $part_url, [
					'method'  => 'PUT',
					'body'    => $chunk,
					'timeout' => 120,
					'headers' => [ 'Content-Length' => strlen( $chunk ) ],
				] );
				unset( $chunk );

				if ( is_wp_error( $part_resp ) ) {
					$log[] = [ 'ok' => false, 'msg' => "FAIL MPU PART {$part_num} {$key}: " . $part_resp->get_error_message() ];
					$mpu_ok = false;
					break;
				}
				$part_code = wp_remote_retrieve_response_code( $part_resp );
				if ( $part_code !== 200 ) {
					$log[] = [ 'ok' => false, 'msg' => "FAIL MPU PART {$part_num} {$key}: HTTP {$part_code}" ];
					$mpu_ok = false;
					break;
				}
				$etag = wp_remote_retrieve_header( $part_resp, 'etag' );
				$parts[ $part_num ] = trim( $etag, '"' );
				$part_num++;
			}
			fclose( $fh );
			@unlink( $tmp_path );

			if ( ! $mpu_ok ) {
				apollo_s3_mpu_abort( $key, $upload_id );
				$errors++;
				continue;
			}
			$complete = apollo_s3_mpu_complete( $key, $upload_id, $parts );
			if ( is_wp_error( $complete ) ) {
				$log[] = [ 'ok' => false, 'msg' => "FAIL MPU COMPLETE {$key}: " . $complete->get_error_message() ];
				$errors++;
				continue;
			}
		}

		$log[] = [ 'ok' => true, 'msg' => "✓ {$key} ({$size_label})" ];
		$copied++;
	}

	wp_send_json_success( [
		'scanned'      => $scanned,
		'copied'       => $copied,
		'skipped'      => $skipped,
		'errors'       => $errors,
		'log'          => $log,
		'cont_token'   => $next_token,
		'done'         => $is_done,
		'progress_pct' => $is_done ? 100 : 50,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: R2→S3 DB Rewrite — replace R2 CDN domain with S3 CloudFront URL
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_s3_sync_db_rewrite', 'apollo_s3_ajax_db_rewrite' );

function apollo_s3_ajax_db_rewrite(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_s3_sync_db', 'nonce' );

	$cf  = apollo_cf_config();
	$s3  = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [];

	$old = rtrim( (string) ( $cf['public_url'] ?: '' ), '/' );
	$new = rtrim( (string) ( $s3['cf_url'] ?? get_option( 'apollo_s3_cf_url', '' ) ), '/' );

	if ( ! $old || ! $new ) {
		wp_send_json_error( 'Cannot rewrite: set both R2 Public URL and S3 CloudFront URL in Cloudflare → Settings.' );
	}
	if ( $old === $new ) {
		wp_send_json_error( 'Old and new URLs are identical — nothing to rewrite.' );
	}

	wp_send_json_success( apollo_s3_sync_db_replace( $old, $new ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: S3→R2 Scan — clears saved token to restart from beginning
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_r2_sync_scan', 'apollo_r2_ajax_sync_scan' );

function apollo_r2_ajax_sync_scan(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_r2_sync_scan', 'nonce' );
	delete_option( 'apollo_r2_sync_cont_token' );
	wp_send_json_success( [ 'cont_token' => '' ] );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: S3→R2 Batch — list up to 1 S3 object, download, upload to R2
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_r2_sync_batch', 'apollo_r2_ajax_sync_batch' );

function apollo_r2_ajax_sync_batch(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_r2_sync_batch', 'nonce' );

	if ( ! function_exists( 'apollo_s3_config' ) || ! function_exists( 'apollo_s3_list_page' ) ) {
		wp_send_json_error( 'S3 module not loaded.' );
	}

	$r2_cfg = apollo_cf_config();
	$s3_cfg = apollo_s3_config();

	if ( empty( $s3_cfg['access_key'] ) || empty( $s3_cfg['secret_key'] ) || empty( $s3_cfg['region'] ) || empty( $s3_cfg['bucket'] ) ) {
		wp_send_json_error( 'S3 credentials not configured.' );
	}
	if ( empty( $r2_cfg['account_id'] ) || empty( $r2_cfg['access_key'] ) || empty( $r2_cfg['secret_key'] ) || empty( $r2_cfg['bucket'] ) ) {
		wp_send_json_error( 'R2 credentials not configured.' );
	}

	$cont_token = sanitize_text_field( wp_unslash( $_POST['cont_token'] ?? '' ) );

	$list_result = apollo_s3_list_page( null, 1, $cont_token );
	if ( is_wp_error( $list_result ) ) {
		wp_send_json_error( 'S3 list error: ' . $list_result->get_error_message() );
	}

	$objects    = $list_result['objects']    ?? [];
	$next_token = $list_result['next_token'] ?? '';
	$is_done    = empty( $next_token );

	if ( $next_token ) {
		update_option( 'apollo_r2_sync_cont_token', $next_token, false );
	} else {
		delete_option( 'apollo_r2_sync_cont_token' );
	}

	$log     = [];
	$copied  = 0;
	$skipped = 0;
	$errors  = 0;
	$scanned = count( $objects );

	$mpu_threshold = 5 * 1024 * 1024;
	$mpu_part_size = 50 * 1024 * 1024;

	foreach ( $objects as $obj ) {
		$key  = $obj['key']  ?? '';
		$size = (int) ( $obj['size'] ?? 0 );
		if ( ! $key ) { $skipped++; continue; }

		$content_type = $obj['content_type'] ?? 'application/octet-stream';
		$size_label   = $size > 0 ? ( $size >= 1048576 ? round( $size / 1048576, 1 ) . ' MB' : round( $size / 1024, 1 ) . ' KB' ) : '?';

		// Stream from S3 to temp file via presigned GET
		$get_url  = apollo_s3_presign_get_url( $key, 3600 );
		$tmp_path = wp_tempnam( 'apollo-s3-sync-' . md5( $key ) );

		$response = wp_remote_get( $get_url, [
			'timeout'    => 300,
			'stream'     => true,
			'filename'   => $tmp_path,
			'decompress' => false,
		] );

		if ( is_wp_error( $response ) ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL GET {$key}: " . $response->get_error_message() ];
			$errors++;
			continue;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL GET {$key}: HTTP {$http_code}" ];
			$errors++;
			continue;
		}

		$actual_size = file_exists( $tmp_path ) ? filesize( $tmp_path ) : 0;
		if ( $actual_size === 0 ) {
			@unlink( $tmp_path );
			$log[] = [ 'ok' => false, 'msg' => "FAIL {$key}: downloaded file is empty" ];
			$errors++;
			continue;
		}

		// Upload to R2
		if ( $actual_size <= $mpu_threshold ) {
			$body       = file_get_contents( $tmp_path );
			@unlink( $tmp_path );
			$put_result = apollo_r2_put_object( $key, $body, $content_type, $r2_cfg );
			unset( $body );
			if ( is_wp_error( $put_result ) ) {
				$log[] = [ 'ok' => false, 'msg' => "FAIL PUT {$key}: " . $put_result->get_error_message() ];
				$errors++;
				continue;
			}
		} else {
			$upload_id = apollo_r2_mpu_init( $key, $content_type, $r2_cfg );
			if ( is_wp_error( $upload_id ) ) {
				@unlink( $tmp_path );
				$log[] = [ 'ok' => false, 'msg' => "FAIL MPU INIT {$key}: " . $upload_id->get_error_message() ];
				$errors++;
				continue;
			}

			$fh       = fopen( $tmp_path, 'rb' );
			$part_num = 1;
			$parts    = [];
			$mpu_ok   = true;

			while ( ! feof( $fh ) ) {
				$chunk = fread( $fh, $mpu_part_size );
				if ( $chunk === false || strlen( $chunk ) === 0 ) break;

				// Use presigned URL for each part upload
				$part_url  = apollo_r2_presign_part_url( $key, $upload_id, $part_num, 3600, $r2_cfg );
				$part_resp = wp_remote_request( $part_url, [
					'method'  => 'PUT',
					'body'    => $chunk,
					'timeout' => 120,
					'headers' => [ 'Content-Length' => strlen( $chunk ) ],
				] );
				unset( $chunk );

				if ( is_wp_error( $part_resp ) ) {
					$log[] = [ 'ok' => false, 'msg' => "FAIL MPU PART {$part_num} {$key}: " . $part_resp->get_error_message() ];
					$mpu_ok = false;
					break;
				}
				$part_code = wp_remote_retrieve_response_code( $part_resp );
				if ( $part_code !== 200 ) {
					$log[] = [ 'ok' => false, 'msg' => "FAIL MPU PART {$part_num} {$key}: HTTP {$part_code}" ];
					$mpu_ok = false;
					break;
				}
				$etag = wp_remote_retrieve_header( $part_resp, 'etag' );
				$parts[ $part_num ] = trim( $etag, '"' );
				$part_num++;
			}
			fclose( $fh );
			@unlink( $tmp_path );

			if ( ! $mpu_ok ) {
				apollo_r2_mpu_abort( $key, $upload_id, $r2_cfg );
				$errors++;
				continue;
			}
			$complete = apollo_r2_mpu_complete( $key, $upload_id, $parts, $r2_cfg );
			if ( is_wp_error( $complete ) ) {
				$log[] = [ 'ok' => false, 'msg' => "FAIL MPU COMPLETE {$key}: " . $complete->get_error_message() ];
				$errors++;
				continue;
			}
		}

		$log[] = [ 'ok' => true, 'msg' => "✓ {$key} ({$size_label})" ];
		$copied++;
	}

	wp_send_json_success( [
		'scanned'      => $scanned,
		'copied'       => $copied,
		'skipped'      => $skipped,
		'errors'       => $errors,
		'log'          => $log,
		'cont_token'   => $next_token,
		'done'         => $is_done,
		'progress_pct' => $is_done ? 100 : 50,
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: S3→R2 DB Rewrite — replace S3 CloudFront URL with R2 CDN domain
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'wp_ajax_apollo_r2_sync_db_rewrite', 'apollo_r2_ajax_db_rewrite' );

function apollo_r2_ajax_db_rewrite(): void {
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );
	check_ajax_referer( 'apollo_r2_sync_db', 'nonce' );

	$cf  = apollo_cf_config();
	$s3  = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [];

	// S3→R2: old = CloudFront/S3 URL, new = R2 public URL
	$old = rtrim( (string) ( $s3['cf_url'] ?? get_option( 'apollo_s3_cf_url', '' ) ), '/' );
	$new = rtrim( (string) ( $cf['public_url'] ?: '' ), '/' );

	if ( ! $old || ! $new ) {
		wp_send_json_error( 'Cannot rewrite: set both S3 CloudFront URL and R2 Public URL in Cloudflare → Settings.' );
	}
	if ( $old === $new ) {
		wp_send_json_error( 'Old and new URLs are identical — nothing to rewrite.' );
	}

	wp_send_json_success( apollo_s3_sync_db_replace( $old, $new ) );
}

// ─────────────────────────────────────────────────────────────────────────────
// Shared helper: run DB search-replace across posts, postmeta, options
// ─────────────────────────────────────────────────────────────────────────────

function apollo_s3_sync_db_replace( string $old, string $new ): array {
	global $wpdb;
	$replaced = 0;

	// wp_posts.post_content
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s",
			'%' . $wpdb->esc_like( $old ) . '%'
		)
	);
	foreach ( (array) $rows as $row ) {
		$updated = str_replace( $old, $new, $row->post_content );
		if ( $updated !== $row->post_content ) {
			$wpdb->update( $wpdb->posts, [ 'post_content' => $updated ], [ 'ID' => $row->ID ] );
			$replaced++;
		}
	}

	// wp_postmeta.meta_value
	$meta_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
			'%' . $wpdb->esc_like( $old ) . '%'
		)
	);
	foreach ( (array) $meta_rows as $row ) {
		$raw     = $row->meta_value;
		$updated = str_replace( $old, $new, $raw );
		if ( $updated !== $raw ) {
			$wpdb->update( $wpdb->postmeta, [ 'meta_value' => $updated ], [ 'meta_id' => $row->meta_id ] );
			$replaced++;
		}
	}

	// wp_options.option_value
	$opt_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_id, option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
			'%' . $wpdb->esc_like( $old ) . '%'
		)
	);
	foreach ( (array) $opt_rows as $row ) {
		$updated = str_replace( $old, $new, $row->option_value );
		if ( $updated !== $row->option_value ) {
			$wpdb->update( $wpdb->options, [ 'option_value' => $updated ], [ 'option_id' => $row->option_id ] );
			$replaced++;
		}
	}

	wp_cache_flush();

	return [
		'old'      => $old,
		'new'      => $new,
		'replaced' => $replaced,
	];
}
