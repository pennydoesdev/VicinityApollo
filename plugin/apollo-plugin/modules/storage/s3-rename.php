<?php
/**
 * S3 Filename Anonymisation Tool
 *
 * Renames existing S3 objects to the anonymous format
 * media-{P|V|A|F}-{20 digits}.{ext} (same scheme used for new uploads when
 * the "Anonymise filenames" toggle is on). After renaming each object, updates
 * all database references so post_content URLs and post_meta keys continue to
 * resolve correctly.
 *
 * Access: WordPress Admin → Cloudflare → S3 Rename Tool
 *
 * Process (AJAX, one object per call):
 *   1. Fetch the list of objects from S3 (cached in transient for 5 min).
 *   2. Pick the next object that does NOT already have an anon filename.
 *   3. server-side CopyObject (new key) → DeleteObject (old key).
 *   4. DB rewrite: search post_content + all post_meta for the old S3 URL.
 *   5. Store the mapping (old key → new key) in `apollo_s3_rename_map` option.
 *   6. Return progress stats to the caller.
 *
 * Dependencies loaded before this file via BOOT_MANIFEST:
 *   modules/storage/s3-core.php (apollo_s3_copy_object, apollo_s3_delete_object,
 *                                 apollo_s3_list_all_objects, apollo_s3_anon_key,
 *                                 apollo_s3_config, apollo_s3_public_url)
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// Register admin page — priority 25 so it follows s3-sync (priority 20).
add_action( 'admin_menu', 'apollo_s3_rename_register_menu', 25 );

function apollo_s3_rename_register_menu(): void {
	add_submenu_page(
		'apollo-cloudflare',
		'S3 Rename Tool',
		'S3 Rename Tool',
		'manage_options',
		'apollo-s3-rename',
		'apollo_s3_rename_page'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: scan — list all non-anon objects (cached)
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_apollo_s3_rename_scan', 'apollo_s3_rename_ajax_scan' );
function apollo_s3_rename_ajax_scan(): void {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'apollo_s3_rename', 'nonce', false ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	// Invalidate cache if requested.
	if ( ! empty( $_POST['refresh'] ) ) {
		delete_transient( 'apollo_s3_rename_pending' );
	}

	$pending = get_transient( 'apollo_s3_rename_pending' );
	if ( $pending === false ) {
		if ( ! function_exists( 'apollo_s3_list_all_objects' ) ) {
			wp_send_json_error( 'apollo_s3_list_all_objects() not available — install latest plugin.' );
		}
		$all = apollo_s3_list_all_objects();
		if ( is_wp_error( $all ) ) {
			wp_send_json_error( $all->get_error_message() );
		}
		// Keep only objects that do NOT already match the anon pattern.
		$anon_re = '#/media-[PVAF]-\d{20}\.[a-z0-9]+$#i';
		$pending = array_values( array_filter( $all, static function ( $o ) use ( $anon_re ) {
			return ! preg_match( $anon_re, $o['key'] );
		} ) );
		set_transient( 'apollo_s3_rename_pending', $pending, 300 );
	}

	wp_send_json_success( [
		'total'     => count( $pending ),
		'remaining' => count( $pending ),
		'objects'   => array_slice( $pending, 0, 50 ), // preview first 50
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX: process one object
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'wp_ajax_apollo_s3_rename_one', 'apollo_s3_rename_ajax_one' );
function apollo_s3_rename_ajax_one(): void {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'apollo_s3_rename', 'nonce', false ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$pending = get_transient( 'apollo_s3_rename_pending' );
	if ( ! is_array( $pending ) || empty( $pending ) ) {
		wp_send_json_success( [ 'done' => true, 'message' => 'All objects have been renamed.' ] );
	}

	$obj = array_shift( $pending );
	set_transient( 'apollo_s3_rename_pending', $pending, 300 );

	$old_key = $obj['key'];
	$ext     = strtolower( (string) pathinfo( $old_key, PATHINFO_EXTENSION ) );

	// Determine type from path prefix.
	if ( str_starts_with( $old_key, 'videos/' ) ) {
		$type   = 'video';
		$prefix = 'videos';
	} elseif ( str_starts_with( $old_key, 'podcast/' ) ) {
		$type   = 'audio';
		$prefix = 'podcast';
	} elseif ( str_starts_with( $old_key, 'audio/' ) ) {
		$type   = 'audio';
		$prefix = 'audio';
	} elseif ( str_starts_with( $old_key, 'images/' ) ) {
		$type   = 'image';
		$prefix = 'images';
	} else {
		$type   = 'file';
		$prefix = dirname( $old_key ) ?: 'files';
	}

	if ( ! function_exists( 'apollo_s3_anon_key' ) ) {
		wp_send_json_error( 'apollo_s3_anon_key() not available.' );
	}

	$new_key = apollo_s3_anon_key( $ext, $type, $prefix );

	// 1. Server-side copy.
	$copy = apollo_s3_copy_object( $old_key, $new_key );
	if ( is_wp_error( $copy ) ) {
		wp_send_json_error( "Copy failed for {$old_key}: " . $copy->get_error_message() );
	}

	// 2. Delete old key.
	$del = apollo_s3_delete_object( $old_key );
	if ( is_wp_error( $del ) ) {
		// Object was already copied — log but don't bail.
		error_log( "[apollo_s3_rename] Delete failed for {$old_key}: " . $del->get_error_message() );
	}

	// 3. DB rewrite.
	$cfg     = apollo_s3_config();
	$old_url = apollo_s3_public_url( $old_key, $cfg );
	$new_url = apollo_s3_public_url( $new_key, $cfg );

	apollo_s3_rename_db_rewrite( $old_key, $new_key, $old_url, $new_url );

	// 4. Persist mapping.
	$map               = (array) get_option( 'apollo_s3_rename_map', [] );
	$map[ $old_key ]   = $new_key;
	update_option( 'apollo_s3_rename_map', $map, false );

	wp_send_json_success( [
		'done'      => false,
		'old_key'   => $old_key,
		'new_key'   => $new_key,
		'remaining' => count( $pending ),
	] );
}

// ─────────────────────────────────────────────────────────────────────────────
// DB rewrite helper
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Rewrite all database references from an old S3 key/URL to the new one.
 *
 * Searches:
 *   - wp_posts.post_content (URL replacement)
 *   - wp_postmeta: _svh_r2_key, _ep_audio_r2_key, _apollo_s3_key (key replacement)
 *   - wp_postmeta: _ep_audio_url, _svh_r2_public_url, _apollo_s3_url (URL replacement)
 *   - wp_options: any serialised option containing the old URL
 */
function apollo_s3_rename_db_rewrite(
	string $old_key,
	string $new_key,
	string $old_url,
	string $new_url
): void {
	global $wpdb;

	// post_content — simple URL replacement.
	if ( $old_url && $new_url ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s)
				 WHERE post_content LIKE %s",
				$old_url,
				$new_url,
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);
	}

	// Post meta — key fields (store the S3 key directly, not the URL).
	$key_meta = [ '_svh_r2_key', '_ep_audio_r2_key', '_apollo_s3_key' ];
	foreach ( $key_meta as $meta_key ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s
				 WHERE meta_key = %s AND meta_value = %s",
				$new_key,
				$meta_key,
				$old_key
			)
		);
	}

	// Post meta — URL fields.
	if ( $old_url && $new_url ) {
		$url_meta = [ '_ep_audio_url', '_svh_r2_public_url', '_apollo_s3_url', '_wp_attached_file', 'guid' ];
		foreach ( $url_meta as $meta_key ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s)
					 WHERE meta_key = %s AND meta_value LIKE %s",
					$old_url,
					$new_url,
					$meta_key,
					'%' . $wpdb->esc_like( $old_url ) . '%'
				)
			);
		}

		// wp_posts.guid (attachment URL).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET guid = REPLACE(guid, %s, %s) WHERE guid LIKE %s",
				$old_url,
				$new_url,
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Admin page HTML
// ─────────────────────────────────────────────────────────────────────────────

function apollo_s3_rename_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$s3      = function_exists( 'apollo_s3_config' ) ? apollo_s3_config() : [];
	$s3_ready = ! empty( $s3['access_key'] ) && ! empty( $s3['secret_key'] )
	             && ! empty( $s3['bucket'] )   && ! empty( $s3['region'] );

	$map   = (array) get_option( 'apollo_s3_rename_map', [] );
	$nonce = wp_create_nonce( 'apollo_s3_rename' );
	?>
	<div class="wrap" style="max-width:860px">
		<h1 style="display:flex;align-items:center;gap:10px">
			<span style="font-size:24px">🔏</span> S3 Filename Anonymisation
		</h1>
		<p style="color:#555;margin-top:0">
			Renames existing S3 objects from their original slugs to randomised
			<code>media-V/A/P/F-{20 digits}.ext</code> names, then rewrites all
			WordPress database references (post_content URLs, post meta) so nothing breaks.
			Processed one file at a time — safe to pause and resume.
		</p>

		<?php if ( ! $s3_ready ): ?>
		<div class="notice notice-error inline"><p>
			<strong>S3 credentials not configured.</strong>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=apollo-cloudflare' ) ); ?>">Set up S3 credentials →</a>
		</p></div>
		<?php else: ?>

		<!-- Progress panel -->
		<div id="s3rn-panel" style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
			<h2 style="margin:0 0 12px;font-size:15px;font-weight:700">Rename Queue</h2>
			<p id="s3rn-status" style="margin:0 0 12px;font-size:13px;color:#555">
				Click <strong>Scan Bucket</strong> to discover objects that need renaming.
			</p>
			<div id="s3rn-progress-wrap" style="display:none;background:#f0f0f0;border-radius:4px;height:8px;margin-bottom:12px;overflow:hidden">
				<div id="s3rn-progress-bar" style="height:8px;background:#2271b1;border-radius:4px;width:0;transition:width .3s"></div>
			</div>
			<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
				<button id="s3rn-scan-btn" class="button button-secondary">🔍 Scan Bucket</button>
				<button id="s3rn-start-btn" class="button button-primary" disabled>▶ Start Renaming</button>
				<button id="s3rn-pause-btn" class="button button-secondary" disabled>⏸ Pause</button>
				<button id="s3rn-reset-btn" class="button button-link-delete" style="color:#b32d2e">🗑 Clear Map + Rescan</button>
			</div>
			<div id="s3rn-log" style="display:none;max-height:260px;overflow-y:auto;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;padding:8px 12px;font-family:monospace;font-size:11px;line-height:1.7"></div>
		</div>

		<!-- Completed mapping -->
		<?php if ( ! empty( $map ) ): ?>
		<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px 24px;margin-bottom:20px">
			<h2 style="margin:0 0 4px;font-size:15px;font-weight:700">Rename Map (<?php echo number_format( count( $map ) ); ?> entries)</h2>
			<p style="margin:0 0 12px;font-size:12px;color:#666">
				Stored in <code>apollo_s3_rename_map</code> option. Each entry maps the original key to its anonymised replacement.
			</p>
			<details>
				<summary style="cursor:pointer;font-size:13px;font-weight:600;color:#2271b1">Show / hide mapping table</summary>
				<div style="max-height:320px;overflow-y:auto;margin-top:8px">
				<table class="widefat striped" style="font-size:11px">
					<thead><tr>
						<th style="width:50%">Original key</th>
						<th style="width:50%">New key</th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $map, -200 ) as $old => $new ): ?>
					<tr>
						<td style="font-family:monospace;word-break:break-all"><?php echo esc_html( $old ); ?></td>
						<td style="font-family:monospace;word-break:break-all"><?php echo esc_html( $new ); ?></td>
					</tr>
					<?php endforeach; ?>
					<?php if ( count( $map ) > 200 ): ?>
					<tr><td colspan="2" style="color:#888;font-style:italic">... and <?php echo number_format( count( $map ) - 200 ); ?> more (showing last 200)</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
				</div>
			</details>
		</div>
		<?php endif; ?>

		<script>
		(function(){
		var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxurl  = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
		var scanBtn  = document.getElementById('s3rn-scan-btn');
		var startBtn = document.getElementById('s3rn-start-btn');
		var pauseBtn = document.getElementById('s3rn-pause-btn');
		var resetBtn = document.getElementById('s3rn-reset-btn');
		var status   = document.getElementById('s3rn-status');
		var log      = document.getElementById('s3rn-log');
		var bar      = document.getElementById('s3rn-progress-bar');
		var barWrap  = document.getElementById('s3rn-progress-wrap');

		var total    = 0;
		var remaining = 0;
		var running  = false;
		var paused   = false;

		function logLine(msg, color) {
			log.style.display = 'block';
			var line = document.createElement('div');
			line.style.color = color || '#1d2327';
			line.textContent = msg;
			log.appendChild(line);
			log.scrollTop = log.scrollHeight;
		}

		function setStatus(msg) { status.textContent = msg; }

		function updateProgress(rem) {
			remaining = rem;
			if (total > 0) {
				var pct = Math.round((total - remaining) / total * 100);
				bar.style.width = pct + '%';
				barWrap.style.display = 'block';
				setStatus('Progress: ' + (total - remaining) + ' / ' + total + ' renamed (' + pct + '%)');
			}
			if (remaining === 0) {
				setStatus('✅ All done! ' + total + ' object(s) renamed.');
				running = false;
				startBtn.disabled = true;
				pauseBtn.disabled = true;
				logLine('✅ Rename complete.', '#166534');
			}
		}

		function doScan(refresh) {
			scanBtn.disabled = true;
			setStatus('Scanning S3 bucket…');
			var fd = new FormData();
			fd.append('action', 'apollo_s3_rename_scan');
			fd.append('nonce', nonce);
			if (refresh) fd.append('refresh', '1');
			fetch(ajaxurl, {method:'POST',body:fd})
				.then(function(r){return r.json();})
				.then(function(d){
					scanBtn.disabled = false;
					if (!d.success) { setStatus('Scan error: ' + d.data); return; }
					total     = d.data.total;
					remaining = d.data.remaining;
					setStatus(total + ' object(s) need renaming. ' + (total===0?'Nothing to do!':'Click Start Renaming.'));
					if (total > 0) { startBtn.disabled = false; }
					bar.style.width = '0';
					barWrap.style.display = total > 0 ? 'block' : 'none';
				})
				.catch(function(e){ scanBtn.disabled=false; setStatus('Network error: '+e.message); });
		}

		function processNext() {
			if (paused || !running) return;
			var fd = new FormData();
			fd.append('action', 'apollo_s3_rename_one');
			fd.append('nonce', nonce);
			fetch(ajaxurl, {method:'POST',body:fd})
				.then(function(r){return r.json();})
				.then(function(d){
					if (!d.success) {
						logLine('❌ ' + d.data, '#b32d2e');
						running = false;
						startBtn.disabled = false;
						pauseBtn.disabled = true;
						return;
					}
					if (d.data.done) {
						updateProgress(0);
						return;
					}
					logLine('✓ ' + d.data.old_key + ' → ' + d.data.new_key, '#166534');
					updateProgress(d.data.remaining);
					if (!paused && remaining > 0) {
						setTimeout(processNext, 200);
					} else if (!paused && remaining === 0) {
						updateProgress(0);
					}
				})
				.catch(function(e){
					logLine('❌ Network error: '+e.message, '#b32d2e');
					running = false;
					startBtn.disabled = false;
					pauseBtn.disabled = true;
				});
		}

		scanBtn.addEventListener('click', function(){ doScan(false); });
		resetBtn.addEventListener('click', function(){
			if (!confirm('Clear the rename map and rescan? This does NOT undo renames already performed.')) return;
			doScan(true);
		});
		startBtn.addEventListener('click', function(){
			if (remaining === 0) return;
			running = true;
			paused  = false;
			startBtn.disabled = true;
			pauseBtn.disabled = false;
			logLine('▶ Starting rename batch…');
			processNext();
		});
		pauseBtn.addEventListener('click', function(){
			paused  = true;
			running = false;
			startBtn.disabled = false;
			pauseBtn.disabled = true;
			logLine('⏸ Paused. Click Start to resume.', '#666');
		});
		})();
		</script>

		<?php endif; // s3_ready ?>
	</div>
	<?php
}
