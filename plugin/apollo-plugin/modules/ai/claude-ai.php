<?php
/**
 * AI Settings — Claude, Featherless.ai, HuggingFace, Google AI Studio.
 *
 * Single admin page at Settings → AI Settings.
 * serve_ai_call() / serve_ai_call_with_system() route to the active provider.
 * All AI features on the site (Social Optimization, Article Summaries, etc.)
 * use these helpers — one place to change the model/key for everything.
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Meta registration.
// ---------------------------------------------------------------------------
add_action( 'init', function(): void {
	$post_types = [ 'post', 'page', 'serve_video', 'serve_podcast', 'serve_episode', 'serve_podcast_ep' ];
	$meta_keys  = [
		'serve_seo_title'       => 'string',
		'serve_seo_description' => 'string',
		'serve_seo_keywords'    => 'string',
		'serve_og_title'        => 'string',
		'serve_og_description'  => 'string',
		'serve_og_image_url'    => 'string',
	];
	foreach ( $post_types as $pt ) {
		foreach ( $meta_keys as $key => $type ) {
			register_post_meta( $pt, $key, [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => $type,
				'auth_callback' => fn() => current_user_can( 'edit_posts' ),
			] );
		}
	}
} );

// ---------------------------------------------------------------------------
// Feature registry — any module may register AI features via the filter.
// ---------------------------------------------------------------------------

/**
 * Returns the canonical map of feature_key => human label.
 * Other modules extend this by adding to the 'serve_ai_features' filter:
 *
 *   add_filter( 'serve_ai_features', function( $features ) {
 *       $features['my_feature'] = 'My Feature Label';
 *       return $features;
 *   } );
 *
 * The Settings → AI Settings page, the Gutenberg sidebar, and the save
 * handler all call this function so new features appear automatically.
 */
function serve_ai_registered_features(): array {
	static $cache = null;
	if ( $cache !== null ) return $cache;
	$base = [
		'takeaways' => 'Article Takeaways',
		'seo'       => 'SEO Title & Description',
		'og'        => 'Open Graph / Social',
		'keywords'  => 'Keywords & Tags',
		'excerpt'   => 'Excerpt',
		'alt_text'  => 'Alt Text for Images',
		'search'    => 'Site Search',
	];
	$cache = (array) apply_filters( 'serve_ai_features', $base );
	return $cache;
}

// ---------------------------------------------------------------------------
// Settings helpers.
// ---------------------------------------------------------------------------

/** Reset the static settings cache (call after updating the option). */
function serve_ai_reset_settings_cache(): void {
	serve_ai_settings( true );
}

/**
 * Full AI settings array merged with defaults.
 * @param bool $reset  Pass true to clear the static cache.
 */
function serve_ai_settings( bool $reset = false ): array {
	static $cache = null;
	if ( $reset ) { $cache = null; return []; }
	if ( $cache !== null ) return $cache;

	$all_feature_defaults = [];
	foreach ( array_keys( serve_ai_registered_features() ) as $feat_key ) {
		$all_feature_defaults[ $feat_key ] = 'auto';
	}

	$defaults = [
		'active_provider'     => 'claude',
		'enabled_providers'   => [ 'claude' ],
		'feature_providers'   => $all_feature_defaults,
		'claude_api_key'      => '',
		'claude_model'        => 'claude-sonnet-4-6',
		'claude_max_tokens'   => 500,
		'openai_api_key'      => '',
		'openai_model'        => 'gpt-4o-mini',
		'featherless_api_key' => '',
		'featherless_model'   => 'meta-llama/Llama-3.3-70B-Instruct',
		'hf_api_token'        => '',
		'hf_model'            => 'mistralai/Mistral-7B-Instruct-v0.2',
		'google_api_key'      => '',
		'google_model'        => 'gemini-2.0-flash',
		'tone'                => 'professional',
		'language'            => 'en',
		'auto_generate'       => false,
	];

	$stored = get_option( 'serve_ai_settings', [] );
	$cache  = array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	return $cache;
}

/** Get a single AI setting. */
function serve_ai_get( string $key, $fallback = null ) {
	return serve_ai_settings()[ $key ] ?? $fallback;
}

/** Does the active provider have credentials? Returns true if any enabled provider is ready. */
function serve_ai_has_key( string $provider = '' ): bool {
	$s = serve_ai_settings();
	if ( $provider !== '' ) {
		return serve_ai_provider_has_key( $provider, $s );
	}
	foreach ( (array) ( $s['enabled_providers'] ?? [ $s['active_provider'] ] ) as $p ) {
		if ( serve_ai_provider_has_key( $p, $s ) ) return true;
	}
	return false;
}

/** Check if a specific provider has its credentials set. */
function serve_ai_provider_has_key( string $provider, array $s ): bool {
	return match ( $provider ) {
		'claude'      => ! empty( $s['claude_api_key'] ),
		'openai'      => ! empty( $s['openai_api_key'] ),
		'featherless' => ! empty( $s['featherless_api_key'] ),
		'huggingface' => ! empty( $s['hf_api_token'] ),
		'google'      => ! empty( $s['google_api_key'] ),
		default       => false,
	};
}

/**
 * Resolve which provider (and optional model override) to use for a feature.
 * Returns array: [ 'provider' => string, 'model' => string|null ]
 */
function serve_ai_get_provider_for_feature( string $feature ): array {
	$s        = serve_ai_settings();
	$map      = (array) ( $s['feature_providers'] ?? [] );
	$assigned = $map[ $feature ] ?? 'auto';

	if ( $assigned !== 'auto' && str_contains( $assigned, ':' ) ) {
		[ $provider, $model ] = explode( ':', $assigned, 2 );
		if ( serve_ai_provider_has_key( $provider, $s ) ) {
			return [ 'provider' => $provider, 'model' => $model ];
		}
	}

	if ( $assigned !== 'auto' && ! str_contains( $assigned, ':' ) && serve_ai_provider_has_key( $assigned, $s ) ) {
		return [ 'provider' => $assigned, 'model' => null ];
	}

	foreach ( (array) ( $s['enabled_providers'] ?? [ $s['active_provider'] ] ) as $p ) {
		if ( serve_ai_provider_has_key( $p, $s ) ) return [ 'provider' => $p, 'model' => null ];
	}

	return [ 'provider' => 'claude', 'model' => null ];
}

// ---------------------------------------------------------------------------
// Kill switch.
// ---------------------------------------------------------------------------

function serve_ai_is_killed(): bool {
	return (bool) get_option( 'serve_ai_kill' );
}

// ---------------------------------------------------------------------------
// Job tracking.
// ---------------------------------------------------------------------------

function serve_ai_job_push( array $data ): string {
	$id   = 'j' . substr( md5( uniqid( '', true ) ), 0, 10 );
	$user = function_exists( 'wp_get_current_user' ) ? ( wp_get_current_user()->user_login ?: 'system' ) : 'system';
	set_transient(
		'serve_ai_job_' . $id,
		array_merge( [
			'provider' => serve_ai_get( 'active_provider', 'claude' ),
			'user'     => $user,
			'task'     => 'generation',
			'post_id'  => 0,
			'started'  => time(),
		], $data ),
		300
	);
	return $id;
}

function serve_ai_job_pop( string $id ): void {
	delete_transient( 'serve_ai_job_' . $id );
}

function serve_ai_jobs_get(): array {
	global $wpdb;
	$prefix  = '_transient_serve_ai_job_';
	$timeout = '_transient_timeout_serve_ai_job_';
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			   AND option_name NOT LIKE %s
			 ORDER BY option_id DESC LIMIT 50",
			$wpdb->esc_like( $prefix ) . '%',
			$wpdb->esc_like( $timeout ) . '%'
		),
		ARRAY_A
	);
	$jobs = [];
	foreach ( $rows as $row ) {
		$id   = str_replace( $prefix, '', $row['option_name'] );
		$data = maybe_unserialize( $row['option_value'] );
		if ( is_array( $data ) ) {
			$jobs[ $id ] = $data;
		}
	}
	return $jobs;
}

// ---------------------------------------------------------------------------
// AJAX — kill switch toggle & live job status.
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_serve_ai_kill_toggle', function (): void {
	check_ajax_referer( 'serve_ai_kill_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

	$action = sanitize_text_field( $_POST['kill_action'] ?? '' );
	if ( $action === 'kill' ) {
		update_option( 'serve_ai_kill', '1', false );
	} elseif ( $action === 'resume' ) {
		delete_option( 'serve_ai_kill' );
	}
	wp_send_json_success( [ 'killed' => serve_ai_is_killed() ] );
} );

add_action( 'wp_ajax_serve_ai_jobs_status', function (): void {
	check_ajax_referer( 'serve_ai_kill_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
	wp_send_json_success( [
		'killed' => serve_ai_is_killed(),
		'jobs'   => array_values( serve_ai_jobs_get() ),
	] );
} );

// ---------------------------------------------------------------------------
// Admin settings page — Settings → AI Settings.
// ---------------------------------------------------------------------------

add_action( 'admin_menu', function (): void {
	add_options_page(
		__( 'AI Settings', 'apollo' ),
		__( 'AI Settings', 'apollo' ),
		'manage_options',
		'serve-ai-settings',
		'serve_ai_settings_page'
	);
} );

function serve_ai_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'apollo' ) );
	}

	$saved = false;
	if ( isset( $_POST['serve_ai_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['serve_ai_nonce'] ) ), 'serve_ai_save' ) ) {
		$valid_providers = [ 'claude', 'openai', 'featherless', 'huggingface', 'google' ];
		$valid_features  = array_keys( serve_ai_registered_features() );

		$enabled_raw = array_filter( array_map( 'sanitize_text_field', (array) ( $_POST['enabled_providers'] ?? [] ) ), fn( $p ) => in_array( $p, $valid_providers, true ) );
		$enabled     = array_values( $enabled_raw ) ?: [ 'claude' ];

		$feature_providers_raw = (array) ( $_POST['feature_providers'] ?? [] );
		$feature_providers     = [];
		foreach ( $valid_features as $feat ) {
			$val = sanitize_text_field( $feature_providers_raw[ $feat ] ?? 'auto' );
			if ( $val === 'auto' ) {
				$feature_providers[ $feat ] = 'auto';
			} elseif ( str_contains( $val, ':' ) ) {
				[ $pv, $mv ] = explode( ':', $val, 2 );
				$feature_providers[ $feat ] = in_array( $pv, $valid_providers, true ) ? $val : 'auto';
			} else {
				$feature_providers[ $feat ] = in_array( $val, $valid_providers, true ) ? $val : 'auto';
			}
		}

		$data = [
			'active_provider'     => $enabled[0],
			'enabled_providers'   => $enabled,
			'feature_providers'   => $feature_providers,
			'claude_api_key'      => sanitize_text_field( wp_unslash( $_POST['claude_api_key'] ?? '' ) ),
			'claude_model'        => sanitize_text_field( wp_unslash( $_POST['claude_model'] ?? 'claude-sonnet-4-6' ) ),
			'claude_max_tokens'   => absint( $_POST['claude_max_tokens'] ?? 500 ),
			'openai_api_key'      => sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ?? '' ) ),
			'openai_model'        => sanitize_text_field( wp_unslash( $_POST['openai_model'] ?? 'gpt-4o-mini' ) ),
			'featherless_api_key' => sanitize_text_field( wp_unslash( $_POST['featherless_api_key'] ?? '' ) ),
			'featherless_model'   => sanitize_text_field( wp_unslash( $_POST['featherless_model'] ?? '' ) ),
			'hf_api_token'        => sanitize_text_field( wp_unslash( $_POST['hf_api_token'] ?? '' ) ),
			'hf_model'            => sanitize_text_field( wp_unslash( $_POST['hf_model'] ?? '' ) ),
			'google_api_key'      => sanitize_text_field( wp_unslash( $_POST['google_api_key'] ?? '' ) ),
			'google_model'        => sanitize_text_field( wp_unslash( $_POST['google_model'] ?? 'gemini-2.0-flash' ) ),
			'tone'                => sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'professional' ) ),
			'language'            => sanitize_text_field( wp_unslash( $_POST['language'] ?? 'en' ) ),
			'auto_generate'       => ! empty( $_POST['auto_generate'] ),
		];
		update_option( 'serve_ai_settings', $data );

		$social_raw   = (array) ( $_POST['site_social'] ?? [] );
		$social_valid = [ 'twitter', 'facebook', 'instagram', 'youtube', 'linkedin', 'tiktok', 'threads', 'bluesky', 'pinterest', 'github' ];
		$social_clean = [];
		foreach ( $social_valid as $k ) {
			$url = sanitize_url( wp_unslash( $social_raw[ $k ] ?? '' ) );
			if ( $url ) $social_clean[ $k ] = $url;
		}
		update_option( 'serve_site_social_links', $social_clean );

		serve_ai_reset_settings_cache();
		$saved = true;
	}

	$s      = serve_ai_settings();
	$killed = serve_ai_is_killed();
	$jobs   = serve_ai_jobs_get();
	$nonce  = wp_create_nonce( 'serve_ai_kill_nonce' );
	$ajax   = admin_url( 'admin-ajax.php' );
	?>
	<div class="wrap" id="serve-ai-settings-wrap">
		<h1><?php esc_html_e( 'AI Settings', 'apollo' ); ?></h1>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'apollo' ); ?></p></div>
		<?php endif; ?>

		<div id="ai-monitor" style="max-width:700px;margin-bottom:28px;border-radius:8px;overflow:hidden;border:2px solid <?php echo $killed ? '#dc2626' : '#e5e7eb'; ?>;background:<?php echo $killed ? '#fef2f2' : '#f9fafb'; ?>;">
			<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid <?php echo $killed ? '#fca5a5' : '#e5e7eb'; ?>;">
				<div style="display:flex;align-items:center;gap:10px;">
					<div id="kill-indicator" style="width:10px;height:10px;border-radius:50%;background:<?php echo $killed ? '#dc2626' : '#22c55e'; ?>;box-shadow:0 0 6px <?php echo $killed ? 'rgba(220,38,38,.5)' : 'rgba(34,197,94,.5)'; ?>;transition:all .3s;"></div>
					<strong style="font-size:14px;color:<?php echo $killed ? '#b91c1c' : '#166534'; ?>;">
						<?php echo $killed ? esc_html__( 'AI Processing PAUSED', 'apollo' ) : esc_html__( 'AI Processing Active', 'apollo' ); ?>
					</strong>
					<span id="job-count-badge" style="font-size:11px;padding:2px 8px;border-radius:99px;background:<?php echo count( $jobs ) ? '#dbeafe' : '#f3f4f6'; ?>;color:<?php echo count( $jobs ) ? '#1d4ed8' : '#6b7280'; ?>;font-weight:600;">
						<?php echo count( $jobs ) > 0 ? count( $jobs ) . ' running' : 'idle'; ?>
					</span>
				</div>
				<div style="display:flex;gap:8px;">
					<button id="btn-kill" type="button"
						style="padding:6px 16px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:<?php echo $killed ? '#d1fae5' : '#dc2626'; ?>;color:<?php echo $killed ? '#065f46' : '#fff'; ?>;display:<?php echo $killed ? 'none' : 'inline-block'; ?>;">
						Stop All AI
					</button>
					<button id="btn-resume" type="button"
						style="padding:6px 16px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:#22c55e;color:#fff;display:<?php echo $killed ? 'inline-block' : 'none'; ?>;">
						Resume AI
					</button>
				</div>
			</div>
			<div style="padding:12px 18px;">
				<p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;margin:0 0 8px;">Active Processes</p>
				<div id="jobs-table">
					<?php if ( empty( $jobs ) ) : ?>
						<p id="jobs-empty" style="font-size:12px;color:#9ca3af;margin:0;font-style:italic;">No AI processes running.</p>
					<?php else : ?>
						<table style="width:100%;border-collapse:collapse;font-size:12px;">
							<thead>
								<tr style="text-align:left;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.05em;">
									<th style="padding:4px 8px 4px 0;">Provider</th>
									<th style="padding:4px 8px 4px 0;">Task</th>
									<th style="padding:4px 8px 4px 0;">Post</th>
									<th style="padding:4px 8px 4px 0;">User</th>
									<th style="padding:4px 8px 4px 0;">Started</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $jobs as $job ) : ?>
									<tr style="border-top:1px solid #f3f4f6;">
										<td style="padding:5px 8px 5px 0;font-weight:600;color:#374151;"><?php echo esc_html( ucfirst( $job['provider'] ?? '—' ) ); ?></td>
										<td style="padding:5px 8px 5px 0;color:#374151;"><?php echo esc_html( $job['task'] ?? '—' ); ?></td>
										<td style="padding:5px 8px 5px 0;color:#6b7280;">
											<?php
											$pid = (int) ( $job['post_id'] ?? 0 );
											if ( $pid ) {
												echo '<a href="' . esc_url( get_edit_post_link( $pid ) ) . '" style="color:#3b82f6;">' . esc_html( get_the_title( $pid ) ?: '#' . $pid ) . '</a>';
											} else {
												echo '—';
											}
											?>
										</td>
										<td style="padding:5px 8px 5px 0;color:#6b7280;"><?php echo esc_html( $job['user'] ?? '—' ); ?></td>
										<td style="padding:5px 8px 5px 0;color:#9ca3af;">
											<?php
											$age = time() - (int) ( $job['started'] ?? time() );
											echo $age < 60 ? $age . 's ago' : round( $age / 60 ) . 'm ago';
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
				<p style="font-size:10px;color:#d1d5db;margin:8px 0 0;">Auto-refreshes every 5 seconds. Kill switch stops all future calls immediately.</p>
			</div>
		</div>

		<script>
		(function(){
			var NONCE='<?php echo esc_js( $nonce ); ?>',AJAX='<?php echo esc_url( $ajax ); ?>',monitor=document.getElementById('ai-monitor'),badge=document.getElementById('job-count-badge'),indicator=document.getElementById('kill-indicator'),tableDiv=document.getElementById('jobs-table'),btnKill=document.getElementById('btn-kill'),btnResume=document.getElementById('btn-resume');
			function setKilled(killed){monitor.style.borderColor=killed?'#dc2626':'#e5e7eb';monitor.style.background=killed?'#fef2f2':'#f9fafb';indicator.style.background=killed?'#dc2626':'#22c55e';indicator.style.boxShadow=killed?'0 0 6px rgba(220,38,38,.5)':'0 0 6px rgba(34,197,94,.5)';indicator.nextElementSibling.style.color=killed?'#b91c1c':'#166534';indicator.nextElementSibling.textContent=killed?'AI Processing PAUSED':'AI Processing Active';btnKill.style.display=killed?'none':'inline-block';btnResume.style.display=killed?'inline-block':'none';}
			function renderJobs(jobs){if(!jobs||!jobs.length){badge.textContent='idle';badge.style.background='#f3f4f6';badge.style.color='#6b7280';tableDiv.innerHTML='<p style="font-size:12px;color:#9ca3af;margin:0;font-style:italic;">No AI processes running.</p>';return;}badge.textContent=jobs.length+' running';badge.style.background='#dbeafe';badge.style.color='#1d4ed8';var rows=jobs.map(function(j){var age=Math.round((Date.now()/1000)-(j.started||0));return'<tr style="border-top:1px solid #f3f4f6;"><td style="padding:5px 8px 5px 0;font-weight:600;color:#374151;">'+(j.provider||'—')+'</td><td style="padding:5px 8px 5px 0;color:#374151;">'+(j.task||'—')+'</td><td style="padding:5px 8px 5px 0;color:#6b7280;">'+(j.post_id||'—')+'</td><td style="padding:5px 8px 5px 0;color:#6b7280;">'+(j.user||'—')+'</td><td style="padding:5px 8px 5px 0;color:#9ca3af;">'+(age<60?age+'s ago':Math.round(age/60)+'m ago')+'</td></tr>';}).join('');tableDiv.innerHTML='<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="text-align:left;color:#9ca3af;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.05em;"><th style="padding:4px 8px 4px 0;">Provider</th><th style="padding:4px 8px 4px 0;">Task</th><th style="padding:4px 8px 4px 0;">Post</th><th style="padding:4px 8px 4px 0;">User</th><th style="padding:4px 8px 4px 0;">Started</th></tr></thead><tbody>'+rows+'</tbody></table>';}
			function poll(){fetch(AJAX+'?action=serve_ai_jobs_status&nonce='+NONCE,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){if(d.success){setKilled(d.data.killed);renderJobs(d.data.jobs);}}).catch(function(){});}
			function toggle(action){btnKill.disabled=btnResume.disabled=true;var fd=new FormData();fd.append('action','serve_ai_kill_toggle');fd.append('nonce',NONCE);fd.append('kill_action',action);fetch(AJAX,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(d){if(d.success)setKilled(d.data.killed);}).finally(function(){btnKill.disabled=btnResume.disabled=false;});}
			btnKill.addEventListener('click',function(){toggle('kill');});
			btnResume.addEventListener('click',function(){toggle('resume');});
			setInterval(poll,5000);
		})();
		</script>

		<script>
		(function(){
			var checkboxes = document.querySelectorAll('[name="enabled_providers[]"]');
			function applyEnabled(){checkboxes.forEach(function(chk){var id=chk.value;var card=document.getElementById('card-'+id);var sect=document.getElementById('section-'+id);var color=chk.dataset.color||'#888';var on=chk.checked;if(card){card.style.opacity=on?'1':'.5';card.style.borderColor=on?color:'#e0e0e0';card.style.background=on?'#fffbf5':'#fafafa';}if(sect){sect.style.opacity=on?'1':'.4';sect.style.pointerEvents=on?'':'none';}});}
			checkboxes.forEach(function(chk){chk.addEventListener('change',applyEnabled);});
			applyEnabled();
		})();
		</script>

		<p style="color:#666;max-width:700px;line-height:1.6;margin-top:0;"><?php esc_html_e( 'Enable one or more AI providers and assign each site feature to the provider you want.', 'apollo' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'serve_ai_save', 'serve_ai_nonce' ); ?>

			<?php
			$enabled_providers = (array) ( $s['enabled_providers'] ?? [ $s['active_provider'] ] );
			$feature_providers = (array) ( $s['feature_providers'] ?? [] );
			$all_providers = [
				'claude'      => [ 'Claude AI',        'Anthropic',          '#d97706' ],
				'openai'      => [ 'OpenAI',            'GPT-4o / GPT-4',     '#10a37f' ],
				'featherless' => [ 'Featherless.ai',    'Open-source models', '#7c3aed' ],
				'huggingface' => [ 'HuggingFace',       'Inference API',      '#f59e0b' ],
				'google'      => [ 'Google AI Studio',  'Gemini models',      '#1a73e8' ],
			];
			$feature_labels = serve_ai_registered_features();
			?>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Enabled Providers', 'apollo' ); ?></h2>
			<div id="provider-cards" style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:28px;">
				<?php foreach ( $all_providers as $id => [ $name, $sub, $color ] ) :
					$is_enabled = in_array( $id, $enabled_providers, true );
				?>
				<label id="card-<?php echo esc_attr( $id ); ?>" for="provider_chk_<?php echo esc_attr( $id ); ?>" style="display:flex;align-items:center;gap:12px;padding:14px 18px;min-width:170px;border:2px solid <?php echo $is_enabled ? esc_attr( $color ) : '#e0e0e0'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $is_enabled ? '#fffbf5' : '#fafafa'; ?>;opacity:<?php echo $is_enabled ? '1' : '.5'; ?>;transition:opacity .2s,border-color .2s,background .2s;">
					<input type="checkbox" id="provider_chk_<?php echo esc_attr( $id ); ?>" name="enabled_providers[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $is_enabled ); ?> data-color="<?php echo esc_attr( $color ); ?>" style="width:18px;height:18px;accent-color:<?php echo esc_attr( $color ); ?>;">
					<div>
						<div style="font-weight:700;font-size:14px;color:#1a1a1a;"><?php echo esc_html( $name ); ?></div>
						<div style="font-size:11px;color:#888;margin-top:2px;"><?php echo esc_html( $sub ); ?></div>
					</div>
				</label>
				<?php endforeach; ?>
			</div>

			<h2 style="margin-top:4px;"><?php esc_html_e( 'Feature Routing', 'apollo' ); ?></h2>
			<?php
			$provider_model_options = [
				'claude'      => [ 'claude-opus-4-6' => 'Claude Opus 4', 'claude-sonnet-4-6' => 'Claude Sonnet 4', 'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5' ],
				'openai'      => [ 'gpt-4o' => 'GPT-4o', 'gpt-4.1' => 'GPT-4.1', 'gpt-4o-mini' => 'GPT-4o mini', 'gpt-4.1-nano' => 'GPT-4.1 Nano', 'gpt-4-turbo' => 'GPT-4 Turbo', 'gpt-3.5-turbo' => 'GPT-3.5 Turbo' ],
				'featherless' => [],
				'huggingface' => [],
				'google'      => [ 'gemini-2.5-pro-preview-05-06' => 'Gemini 2.5 Pro (Preview)', 'gemini-2.5-flash-preview-04-17' => 'Gemini 2.5 Flash (Preview)', 'gemini-2.0-flash' => 'Gemini 2.0 Flash', 'gemini-2.0-flash-lite' => 'Gemini 2.0 Flash Lite', 'gemini-1.5-pro' => 'Gemini 1.5 Pro', 'gemini-1.5-flash' => 'Gemini 1.5 Flash', 'gemini-1.5-flash-8b' => 'Gemini 1.5 Flash 8B', 'gemini-1.0-pro' => 'Gemini 1.0 Pro' ],
			];
			?>
			<table class="form-table" role="presentation" style="max-width:700px;">
				<?php foreach ( $feature_labels as $feat => $label ) :
					$assigned = $feature_providers[ $feat ] ?? 'auto';
				?>
				<tr>
					<th scope="row" style="width:220px;"><?php echo esc_html( $label ); ?></th>
					<td>
						<select name="feature_providers[<?php echo esc_attr( $feat ); ?>]" style="min-width:240px;">
							<option value="auto" <?php selected( $assigned, 'auto' ); ?>><?php esc_html_e( 'Auto (first available)', 'apollo' ); ?></option>
							<?php foreach ( $all_providers as $pid => [ $pname ] ) :
								if ( ! in_array( $pid, $enabled_providers, true ) ) continue;
								$models = $provider_model_options[ $pid ] ?? [];
								if ( empty( $models ) ) :
									$val = $pid;
								?>
								<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $assigned, $val ); ?>><?php echo esc_html( $pname . ' (default model)' ); ?></option>
								<?php else :
									foreach ( $models as $mid => $mname ) :
										$val = $pid . ':' . $mid;
									?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $assigned, $val ); ?>><?php echo esc_html( $pname . ' — ' . $mname ); ?></option>
									<?php endforeach;
								endif;
							endforeach; ?>
						</select>
					</td>
				</tr>
				<?php endforeach; ?>
			</table>

			<hr style="margin:24px 0;max-width:700px;">

			<div class="provider-section" id="section-claude" style="max-width:700px;<?php echo ! in_array( 'claude', $enabled_providers, true ) ? 'opacity:.4;pointer-events:none;' : ''; ?>">
				<h2 style="border-left:4px solid #d97706;padding-left:10px;"><?php esc_html_e( 'Claude AI — Anthropic', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="claude_api_key"><?php esc_html_e( 'API Key', 'apollo' ); ?></label></th><td><input type="password" id="claude_api_key" name="claude_api_key" value="<?php echo esc_attr( $s['claude_api_key'] ); ?>" class="regular-text" autocomplete="off"><p class="description"><?php esc_html_e( 'Starts with sk-ant-. Get it at console.anthropic.com.', 'apollo' ); ?></p></td></tr>
					<tr><th><label for="claude_model"><?php esc_html_e( 'Model', 'apollo' ); ?></label></th><td><select id="claude_model" name="claude_model"><option value="claude-opus-4-6" <?php selected( $s['claude_model'], 'claude-opus-4-6' ); ?>><?php esc_html_e( 'Claude Opus 4 (most capable)', 'apollo' ); ?></option><option value="claude-sonnet-4-6" <?php selected( $s['claude_model'], 'claude-sonnet-4-6' ); ?>><?php esc_html_e( 'Claude Sonnet 4 (recommended)', 'apollo' ); ?></option><option value="claude-haiku-4-5-20251001" <?php selected( $s['claude_model'], 'claude-haiku-4-5-20251001' ); ?>><?php esc_html_e( 'Claude Haiku 4.5 (fastest)', 'apollo' ); ?></option></select></td></tr>
					<tr><th><label for="claude_max_tokens"><?php esc_html_e( 'Max Tokens', 'apollo' ); ?></label></th><td><input type="number" id="claude_max_tokens" name="claude_max_tokens" value="<?php echo esc_attr( $s['claude_max_tokens'] ); ?>" min="100" max="2000" step="50" class="small-text"><p class="description"><?php esc_html_e( '300–500 is sufficient for metadata generation.', 'apollo' ); ?></p></td></tr>
				</table>
			</div>

			<hr style="margin:20px 0;max-width:700px;">

			<div class="provider-section" id="section-openai" style="max-width:700px;<?php echo ! in_array( 'openai', $enabled_providers, true ) ? 'opacity:.4;pointer-events:none;' : ''; ?>">
				<h2 style="border-left:4px solid #10a37f;padding-left:10px;"><?php esc_html_e( 'OpenAI', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="openai_api_key"><?php esc_html_e( 'API Key', 'apollo' ); ?></label></th><td><input type="password" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr( $s['openai_api_key'] ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th><label for="openai_model"><?php esc_html_e( 'Model', 'apollo' ); ?></label></th><td><select id="openai_model" name="openai_model"><option value="gpt-4o" <?php selected( $s['openai_model'], 'gpt-4o' ); ?>>GPT-4o</option><option value="gpt-4.1" <?php selected( $s['openai_model'], 'gpt-4.1' ); ?>>GPT-4.1</option><option value="gpt-4o-mini" <?php selected( $s['openai_model'], 'gpt-4o-mini' ); ?>>GPT-4o mini (recommended)</option><option value="gpt-4.1-nano" <?php selected( $s['openai_model'], 'gpt-4.1-nano' ); ?>>GPT-4.1 Nano</option><option value="gpt-4-turbo" <?php selected( $s['openai_model'], 'gpt-4-turbo' ); ?>>GPT-4 Turbo</option><option value="gpt-3.5-turbo" <?php selected( $s['openai_model'], 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo</option></select></td></tr>
				</table>
			</div>

			<hr style="margin:20px 0;max-width:700px;">

			<div class="provider-section" id="section-featherless" style="max-width:700px;<?php echo ! in_array( 'featherless', $enabled_providers, true ) ? 'opacity:.4;pointer-events:none;' : ''; ?>">
				<h2 style="border-left:4px solid #7c3aed;padding-left:10px;"><?php esc_html_e( 'Featherless.ai', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="featherless_api_key"><?php esc_html_e( 'API Key', 'apollo' ); ?></label></th><td><input type="password" id="featherless_api_key" name="featherless_api_key" value="<?php echo esc_attr( $s['featherless_api_key'] ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th><label for="featherless_model"><?php esc_html_e( 'Model', 'apollo' ); ?></label></th><td><input type="text" id="featherless_model" name="featherless_model" value="<?php echo esc_attr( $s['featherless_model'] ); ?>" class="regular-text" placeholder="meta-llama/Llama-3.3-70B-Instruct"></td></tr>
				</table>
			</div>

			<hr style="margin:20px 0;max-width:700px;">

			<div class="provider-section" id="section-huggingface" style="max-width:700px;<?php echo ! in_array( 'huggingface', $enabled_providers, true ) ? 'opacity:.4;pointer-events:none;' : ''; ?>">
				<h2 style="border-left:4px solid #f59e0b;padding-left:10px;"><?php esc_html_e( 'HuggingFace Inference API', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="hf_api_token"><?php esc_html_e( 'API Token', 'apollo' ); ?></label></th><td><input type="password" id="hf_api_token" name="hf_api_token" value="<?php echo esc_attr( $s['hf_api_token'] ); ?>" class="regular-text" autocomplete="off"></td></tr>
					<tr><th><label for="hf_model"><?php esc_html_e( 'Model', 'apollo' ); ?></label></th><td><input type="text" id="hf_model" name="hf_model" value="<?php echo esc_attr( $s['hf_model'] ); ?>" class="regular-text" placeholder="mistralai/Mistral-7B-Instruct-v0.2"></td></tr>
				</table>
			</div>

			<hr style="margin:20px 0;max-width:700px;">

			<div class="provider-section" id="section-google" style="max-width:700px;<?php echo ! in_array( 'google', $enabled_providers, true ) ? 'opacity:.4;pointer-events:none;' : ''; ?>">
				<h2 style="border-left:4px solid #1a73e8;padding-left:10px;"><?php esc_html_e( 'Google AI Studio — Gemini', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="google_api_key"><?php esc_html_e( 'API Key', 'apollo' ); ?></label></th><td><input type="password" id="google_api_key" name="google_api_key" value="<?php echo esc_attr( $s['google_api_key'] ); ?>" class="regular-text" autocomplete="off"><p class="description"><?php esc_html_e( 'Free at aistudio.google.com.', 'apollo' ); ?></p></td></tr>
					<tr><th><label for="google_model"><?php esc_html_e( 'Model', 'apollo' ); ?></label></th><td><select id="google_model" name="google_model"><option value="gemini-2.5-pro-preview-05-06" <?php selected( $s['google_model'], 'gemini-2.5-pro-preview-05-06' ); ?>>Gemini 2.5 Pro (Preview)</option><option value="gemini-2.5-flash-preview-04-17" <?php selected( $s['google_model'], 'gemini-2.5-flash-preview-04-17' ); ?>>Gemini 2.5 Flash (Preview)</option><option value="gemini-2.0-flash" <?php selected( $s['google_model'], 'gemini-2.0-flash' ); ?>>Gemini 2.0 Flash — recommended</option><option value="gemini-2.0-flash-lite" <?php selected( $s['google_model'], 'gemini-2.0-flash-lite' ); ?>>Gemini 2.0 Flash Lite</option><option value="gemini-1.5-pro" <?php selected( $s['google_model'], 'gemini-1.5-pro' ); ?>>Gemini 1.5 Pro</option><option value="gemini-1.5-flash" <?php selected( $s['google_model'], 'gemini-1.5-flash' ); ?>>Gemini 1.5 Flash</option><option value="gemini-1.5-flash-8b" <?php selected( $s['google_model'], 'gemini-1.5-flash-8b' ); ?>>Gemini 1.5 Flash 8B</option><option value="gemini-1.0-pro" <?php selected( $s['google_model'], 'gemini-1.0-pro' ); ?>>Gemini 1.0 Pro</option></select></td></tr>
				</table>
			</div>

			<hr style="margin:20px 0;max-width:700px;">

			<div style="max-width:700px;">
				<h2><?php esc_html_e( 'Generation Settings', 'apollo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="tone"><?php esc_html_e( 'Writing Tone', 'apollo' ); ?></label></th><td><select id="tone" name="tone"><option value="professional" <?php selected( $s['tone'], 'professional' ); ?>><?php esc_html_e( 'Professional / Journalistic', 'apollo' ); ?></option><option value="casual" <?php selected( $s['tone'], 'casual' ); ?>><?php esc_html_e( 'Casual / Conversational', 'apollo' ); ?></option><option value="formal" <?php selected( $s['tone'], 'formal' ); ?>><?php esc_html_e( 'Formal / Academic', 'apollo' ); ?></option><option value="engaging" <?php selected( $s['tone'], 'engaging' ); ?>><?php esc_html_e( 'Engaging / Marketing', 'apollo' ); ?></option></select></td></tr>
					<tr><th><label for="language"><?php esc_html_e( 'Output Language', 'apollo' ); ?></label></th><td><select id="language" name="language"><?php foreach ( [ 'en'=>'English','es'=>'Spanish','fr'=>'French','de'=>'German','pt'=>'Portuguese','ar'=>'Arabic','zh'=>'Chinese','ja'=>'Japanese','ko'=>'Korean','hi'=>'Hindi' ] as $code=>$lbl ) { printf('<option value="%s" %s>%s</option>',esc_attr($code),selected($s['language'],$code,false),esc_html($lbl)); } ?></select></td></tr>
					<tr><th><?php esc_html_e( 'Auto-generate on Publish', 'apollo' ); ?></th><td><label><input type="checkbox" name="auto_generate" value="1" <?php checked( $s['auto_generate'] ); ?>><?php esc_html_e( 'Automatically generate SEO + OG metadata when a post is first published.', 'apollo' ); ?></label></td></tr>
				</table>
			</div>

			<hr style="margin:24px 0;max-width:700px;">

			<div style="max-width:700px;">
				<h2><?php esc_html_e( 'Site Social Links', 'apollo' ); ?></h2>
				<?php
				$social_links  = (array) get_option( 'serve_site_social_links', [] );
				$social_fields = [ 'twitter'=>['Twitter / X','https://x.com/yourhandle'],'facebook'=>['Facebook','https://facebook.com/yourpage'],'instagram'=>['Instagram','https://instagram.com/yourhandle'],'youtube'=>['YouTube','https://youtube.com/@yourchannel'],'linkedin'=>['LinkedIn','https://linkedin.com/in/you'],'tiktok'=>['TikTok','https://tiktok.com/@yourhandle'],'threads'=>['Threads','https://threads.net/@yourhandle'],'bluesky'=>['Bluesky','https://bsky.app/profile/you'],'pinterest'=>['Pinterest','https://pinterest.com/yourhandle'],'github'=>['GitHub','https://github.com/yourorg'] ];
				?>
				<table class="form-table" role="presentation">
					<?php foreach ( $social_fields as $key => [ $label, $placeholder ] ) : ?>
					<tr><th><label for="social_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th><td><input type="url" id="social_<?php echo esc_attr($key); ?>" name="site_social[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($social_links[$key]??''); ?>" class="regular-text" placeholder="<?php echo esc_attr($placeholder); ?>"></td></tr>
					<?php endforeach; ?>
				</table>
			</div>

			<?php submit_button( __( 'Save AI Settings', 'apollo' ) ); ?>
		</form>

		<?php do_action( 'serve_ai_settings_after_form' ); ?>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Unified AI calls — route to active provider.
// ---------------------------------------------------------------------------

function serve_ai_call( string $prompt ): string|\WP_Error {
	return serve_ai_call_with_system( '', $prompt );
}

function serve_ai_call_with_system( string $system, string $prompt, array $job_meta = [] ): string|\WP_Error {
	if ( serve_ai_is_killed() ) {
		return new \WP_Error( 'killed', __( 'AI processing is paused. Go to Settings → AI Settings to resume.', 'apollo' ) );
	}

	$s          = serve_ai_settings();
	$feature    = $job_meta['task'] ?? '';
	$routing    = serve_ai_get_provider_for_feature( $feature ?: 'default' );
	$provider   = $routing['provider'];
	$model_over = $routing['model'];
	$tokens     = absint( $s['claude_max_tokens'] ?? 500 );

	$job_id = serve_ai_job_push( array_merge( [ 'provider' => $provider ], $job_meta ) );

	$result = match ( $provider ) {
		'openai'      => serve_ai_call_openai_compat( 'https://api.openai.com/v1/chat/completions', $s['openai_api_key'] ?? '', $model_over ?: ( $s['openai_model'] ?? 'gpt-4o-mini' ), $system, $prompt, $tokens ),
		'featherless' => serve_ai_call_openai_compat( 'https://api.featherless.ai/v1/chat/completions', $s['featherless_api_key'] ?? '', $model_over ?: ( $s['featherless_model'] ?? 'meta-llama/Llama-3.3-70B-Instruct' ), $system, $prompt, $tokens ),
		'huggingface' => serve_ai_call_openai_compat( 'https://api-inference.huggingface.co/v1/chat/completions', $s['hf_api_token'] ?? '', $model_over ?: ( $s['hf_model'] ?? 'mistralai/Mistral-7B-Instruct-v0.2' ), $system, $prompt, $tokens ),
		'google'      => serve_ai_call_openai_compat( 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', $s['google_api_key'] ?? '', $model_over ?: ( $s['google_model'] ?? 'gemini-2.0-flash' ), $system, $prompt, $tokens ),
		default       => serve_ai_call_claude( $s['claude_api_key'] ?? '', $model_over ?: ( $s['claude_model'] ?? 'claude-sonnet-4-6' ), $tokens, $system, $prompt ),
	};

	serve_ai_job_pop( $job_id );
	return $result;
}

function serve_ai_sleep( int $seconds ): bool {
	$limit = (int) ini_get( 'max_execution_time' );
	if ( $limit > 0 && ( microtime( true ) + $seconds ) > ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ) + $limit - 2 ) {
		return false;
	}
	sleep( $seconds );
	return true;
}

function serve_ai_retry_after( $response, int $fallback = 5 ): int {
	$header = wp_remote_retrieve_header( $response, 'retry-after' );
	if ( $header === '' ) return $fallback;
	if ( is_numeric( $header ) ) return max( 1, (int) $header );
	$ts = strtotime( $header );
	return $ts ? max( 1, $ts - time() ) : $fallback;
}

function serve_ai_format_error( \WP_Error $e ): string {
	$msg  = $e->get_error_message();
	$code = $e->get_error_code();
	if ( $code === 'rate_limited' || str_contains( $msg, '429' ) || str_contains( $msg, 'rate limit' ) ) {
		return 'Rate limit reached — please wait a moment, then try again. (' . $msg . ')';
	}
	if ( $code === 'no_key' || str_contains( $msg, 'API key' ) ) {
		return $msg . ' — go to Settings → AI Settings to add your key.';
	}
	if ( $code === 'killed' ) return $msg;
	return $msg ?: 'AI generation failed. Check Settings → AI Settings.';
}

function serve_ai_call_claude( string $api_key, string $model, int $max_tokens, string $system, string $prompt ): string|\WP_Error {
	if ( empty( $api_key ) ) {
		return new \WP_Error( 'no_key', __( 'Claude API key not set. Go to Settings → AI Settings.', 'apollo' ) );
	}

	$body = [ 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => [ [ 'role' => 'user', 'content' => $prompt ] ] ];
	if ( $system !== '' ) $body['system'] = $system;
	$encoded = wp_json_encode( $body );

	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'headers' => [ 'x-api-key' => $api_key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ],
			'body'    => $encoded,
			'timeout' => 30,
		] );

		if ( is_wp_error( $response ) ) return new \WP_Error( 'api_error', $response->get_error_message() );
		$code = wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, [ 429, 529 ], true ) && $attempt < 3 ) { serve_ai_sleep( serve_ai_retry_after( $response, 2 * $attempt ) ); continue; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) return new \WP_Error( 'api_error', 'Claude error: ' . ( $data['error']['message'] ?? 'HTTP ' . $code ) );
		$text = '';
		foreach ( $data['content'] ?? [] as $block ) { if ( ( $block['type'] ?? '' ) === 'text' ) $text .= $block['text']; }
		return $text;
	}

	return new \WP_Error( 'rate_limited', __( 'Claude API rate limit reached after retries.', 'apollo' ) );
}

function serve_ai_call_openai_compat( string $url, string $api_key, string $model, string $system, string $prompt, int $max_tokens ): string|\WP_Error {
	if ( empty( $api_key ) ) {
		return new \WP_Error( 'no_key', __( 'API key/token not set. Go to Settings → AI Settings.', 'apollo' ) );
	}

	$messages = [];
	if ( $system !== '' ) $messages[] = [ 'role' => 'system', 'content' => $system ];
	$messages[] = [ 'role' => 'user', 'content' => $prompt ];
	$encoded    = wp_json_encode( [ 'model' => $model, 'max_tokens' => $max_tokens, 'messages' => $messages ] );

	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$response = wp_remote_post( $url, [
			'headers' => [ 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json' ],
			'body'    => $encoded,
			'timeout' => 45,
		] );

		if ( is_wp_error( $response ) ) return new \WP_Error( 'api_error', $response->get_error_message() );
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 429 && $attempt < 3 ) { serve_ai_sleep( serve_ai_retry_after( $response, 2 * $attempt ) ); continue; }
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code !== 200 ) { $err = $data['error']['message'] ?? ( $data['error'] ?? 'HTTP ' . $code ); return new \WP_Error( 'api_error', 'AI error: ' . ( is_string( $err ) ? $err : wp_json_encode( $err ) ) ); }
		return $data['choices'][0]['message']['content'] ?? '';
	}

	return new \WP_Error( 'rate_limited', __( 'AI API rate limit reached after retries.', 'apollo' ) );
}

function serve_claude_call( $api_key_ignored, string $prompt ): string|\WP_Error {
	return serve_ai_call( $prompt );
}

// ---------------------------------------------------------------------------
// AJAX — on-demand generation from block editor.
// ---------------------------------------------------------------------------

function serve_claude_ajax_generate(): void {
	check_ajax_referer( 'serve_claude_gen' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized' );

	$post_id = absint( $_POST['post_id'] ?? 0 );
	$task    = sanitize_text_field( $_POST['task'] ?? '' );

	if ( ! $post_id || ! in_array( $task, [ 'seo', 'og', 'keywords', 'excerpt', 'alt_text', 'all' ], true ) ) {
		wp_send_json_error( 'Invalid request.' );
	}
	if ( ! serve_ai_has_key() ) wp_send_json_error( 'API key not configured. Go to Settings → AI Settings.' );

	$post = get_post( $post_id );
	if ( ! $post ) wp_send_json_error( 'Post not found.' );

	$content  = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 );
	$title    = get_the_title( $post );
	$cats     = wp_strip_all_tags( get_the_category_list( ', ', '', $post_id ) );
	$tone     = serve_ai_get( 'tone', 'professional' );
	$language = serve_ai_get( 'language', 'en' );
	$result   = [ 'message' => '' ];

	$ai = function( string $feat, string $prompt ) use ( $post_id ): string|\WP_Error {
		return serve_ai_call_with_system( '', $prompt, [ 'task' => $feat, 'post_id' => $post_id ] );
	};

	if ( $task === 'seo' || $task === 'all' ) {
		$seo = $ai( 'seo', serve_build_seo_prompt( $title, $content, $cats, $tone, $language ) );
		if ( is_wp_error( $seo ) ) wp_send_json_error( serve_ai_format_error( $seo ) );
		$p = serve_parse_claude_json( $seo );
		if ( ! empty( $p['seo_title'] ) )       { update_post_meta( $post_id, 'serve_seo_title', sanitize_text_field( $p['seo_title'] ) ); $result['seo_title'] = $p['seo_title']; }
		if ( ! empty( $p['seo_description'] ) )  { update_post_meta( $post_id, 'serve_seo_description', sanitize_text_field( $p['seo_description'] ) ); $result['seo_description'] = $p['seo_description']; if ( empty( $post->post_excerpt ) ) { wp_update_post( [ 'ID' => $post_id, 'post_excerpt' => sanitize_text_field( $p['seo_description'] ) ] ); $result['excerpt'] = $p['seo_description']; } }
		$result['message'] .= 'SEO generated. ';
	}

	if ( $task === 'og' || $task === 'all' ) {
		$og = $ai( 'og', serve_build_og_prompt( $title, $content, $cats, $tone, $language ) );
		if ( is_wp_error( $og ) ) wp_send_json_error( serve_ai_format_error( $og ) );
		$p = serve_parse_claude_json( $og );
		if ( ! empty( $p['og_title'] ) )       { update_post_meta( $post_id, 'serve_og_title',       sanitize_text_field( $p['og_title'] ) ); $result['og_title'] = $p['og_title']; }
		if ( ! empty( $p['og_description'] ) ) { update_post_meta( $post_id, 'serve_og_description', sanitize_text_field( $p['og_description'] ) ); $result['og_desc'] = $p['og_description']; }
		$result['message'] .= 'Open Graph generated. ';
	}

	if ( $task === 'keywords' || $task === 'all' ) {
		$kw = $ai( 'keywords', serve_build_keywords_prompt( $title, $content, $cats, $language ) );
		if ( is_wp_error( $kw ) ) wp_send_json_error( serve_ai_format_error( $kw ) );
		$p = serve_parse_claude_json( $kw );
		if ( ! empty( $p['keywords'] ) && is_array( $p['keywords'] ) ) { $tags = array_map( 'sanitize_text_field', $p['keywords'] ); wp_set_post_tags( $post_id, $tags, true ); update_post_meta( $post_id, 'serve_seo_keywords', $tags ); $result['keywords'] = $tags; $result['message'] .= count( $tags ) . ' keywords added. '; }
		if ( ! empty( $p['focus_keyword'] ) ) { update_post_meta( $post_id, 'serve_seo_keywords', sanitize_text_field( $p['focus_keyword'] ) ); $result['focus_keyword'] = $p['focus_keyword']; $result['message'] .= 'Focus keyword set. '; }
	}

	if ( $task === 'excerpt' || $task === 'all' ) {
		$exc = $ai( 'excerpt', serve_build_excerpt_prompt( $title, $content, $tone, $language ) );
		if ( ! is_wp_error( $exc ) ) { $p = serve_parse_claude_json( $exc ); if ( ! empty( $p['excerpt'] ) ) { $clean = sanitize_text_field( $p['excerpt'] ); wp_update_post( [ 'ID' => $post_id, 'post_excerpt' => $clean ] ); $result['excerpt'] = $clean; $result['message'] .= 'Excerpt generated. '; } }
	}

	if ( $task === 'alt_text' ) {
		$images  = serve_get_post_images_without_alt( $post_id );
		$updated = 0;
		if ( empty( $images ) ) {
			$result['message'] = 'All images already have alt text, or no images found.';
		} else {
			foreach ( $images as $att_id => $filename ) {
				$resp = $ai( 'alt_text', serve_build_alt_text_prompt( $filename, $title, $language ) );
				if ( ! is_wp_error( $resp ) ) { $p = serve_parse_claude_json( $resp ); if ( ! empty( $p['alt_text'] ) ) { update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt_text'] ) ); $updated++; } }
			}
			$result['message'] .= $updated . ' alt text(s) generated. ';
		}
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_serve_claude_generate', 'serve_claude_ajax_generate' );

// ---------------------------------------------------------------------------
// AJAX — return current AI config for block editor sidebar.
// ---------------------------------------------------------------------------

add_action( 'wp_ajax_serve_ai_config', function(): void {
	check_ajax_referer( 'serve_claude_gen', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( 'Unauthorized' );

	$s        = serve_ai_settings();
	$features = serve_ai_registered_features();
	$routing  = [];
	foreach ( array_keys( $features ) as $feat ) {
		$r             = serve_ai_get_provider_for_feature( $feat );
		$routing[$feat] = [ 'provider' => $r['provider'], 'model' => $r['model'] ?: serve_ai_default_model_for_provider( $r['provider'], $s ) ];
	}

	wp_send_json_success( [
		'has_key'           => serve_ai_has_key(),
		'enabled_providers' => $s['enabled_providers'] ?? [],
		'provider_names'    => [ 'claude' => 'Claude AI', 'openai' => 'OpenAI', 'featherless' => 'Featherless.ai', 'huggingface' => 'HuggingFace', 'google' => 'Google Gemini' ],
		'feature_routing'   => $routing,
		'features'          => $features,
		'settings_url'      => admin_url( 'options-general.php?page=serve-ai-settings' ),
	] );
} );

function serve_ai_default_model_for_provider( string $provider, array $s ): string {
	return match( $provider ) {
		'openai'      => $s['openai_model']     ?? 'gpt-4o-mini',
		'featherless' => $s['featherless_model'] ?? '',
		'huggingface' => $s['hf_model']          ?? '',
		'google'      => $s['google_model']      ?? 'gemini-2.0-flash',
		default       => $s['claude_model']      ?? 'claude-sonnet-4-6',
	};
}

// ---------------------------------------------------------------------------
// Auto-generate on publish.
// ---------------------------------------------------------------------------

function serve_claude_auto_generate( string $new_status, string $old_status, \WP_Post $post ): void {
	if ( ! serve_ai_get( 'auto_generate', false ) || ! serve_ai_has_key() ) return;
	if ( $new_status !== 'publish' || $old_status === 'publish' ) return;
	if ( $post->post_type !== 'post' ) return;

	$content  = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 );
	$title    = get_the_title( $post );
	$cats     = wp_strip_all_tags( get_the_category_list( ', ', '', $post->ID ) );
	$tone     = serve_ai_get( 'tone', 'professional' );
	$language = serve_ai_get( 'language', 'en' );

	if ( empty( get_post_meta( $post->ID, 'serve_seo_title', true ) ) ) {
		$seo = serve_ai_call( serve_build_seo_prompt( $title, $content, $cats, $tone, $language ) );
		if ( ! is_wp_error( $seo ) ) {
			$p = serve_parse_claude_json( $seo );
			if ( ! empty( $p['seo_title'] ) )      update_post_meta( $post->ID, 'serve_seo_title',       sanitize_text_field( $p['seo_title'] ) );
			if ( ! empty( $p['seo_description'] ) ) { update_post_meta( $post->ID, 'serve_seo_description', sanitize_text_field( $p['seo_description'] ) ); if ( empty( $post->post_excerpt ) ) wp_update_post( [ 'ID' => $post->ID, 'post_excerpt' => sanitize_text_field( $p['seo_description'] ) ] ); }
		}
	}

	if ( empty( get_post_meta( $post->ID, 'serve_og_title', true ) ) ) {
		$og = serve_ai_call( serve_build_og_prompt( $title, $content, $cats, $tone, $language ) );
		if ( ! is_wp_error( $og ) ) {
			$p = serve_parse_claude_json( $og );
			if ( ! empty( $p['og_title'] ) )       update_post_meta( $post->ID, 'serve_og_title',       sanitize_text_field( $p['og_title'] ) );
			if ( ! empty( $p['og_description'] ) ) update_post_meta( $post->ID, 'serve_og_description', sanitize_text_field( $p['og_description'] ) );
		}
	}
}
add_action( 'transition_post_status', 'serve_claude_auto_generate', 10, 3 );

// ---------------------------------------------------------------------------
// Front-end SEO output.
// ---------------------------------------------------------------------------

function serve_ai_seo_title( array $title_parts ): array {
	if ( ! is_singular( 'post' ) ) return $title_parts;
	$ai_title = get_post_meta( get_the_ID(), 'serve_seo_title', true );
	if ( $ai_title ) $title_parts['title'] = $ai_title;
	return $title_parts;
}
add_filter( 'document_title_parts', 'serve_ai_seo_title' );

function serve_ai_meta_description(): void {
	if ( ! is_singular( 'post' ) ) return;
	if ( has_action( 'wp_head', 'wpseo_head' ) || defined( 'RANK_MATH_VERSION' ) ) return;
	$desc = get_post_meta( get_the_ID(), 'serve_seo_description', true ) ?: get_the_excerpt();
	if ( $desc ) echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $desc ) ) . '">' . "\n";
}
add_action( 'wp_head', 'serve_ai_meta_description', 4 );

// ---------------------------------------------------------------------------
// Block editor — enqueue Social Optimization panel JS.
// ---------------------------------------------------------------------------

add_action( 'enqueue_block_editor_assets', function (): void {
	if ( ! current_user_can( 'edit_posts' ) ) return;

	wp_register_script( 'serve-ai-sidebar', false, [ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'jquery' ], false, true );
	wp_enqueue_script( 'serve-ai-sidebar' );
	wp_add_inline_script( 'serve-ai-sidebar', serve_ai_sidebar_js( wp_create_nonce( 'serve_claude_gen' ), serve_ai_has_key() ) );
} );

// ---------------------------------------------------------------------------
// Prompt builders.
// ---------------------------------------------------------------------------

function serve_build_seo_prompt( $title, $content, $categories, $tone, $language ) {
	$t = [ 'professional' => 'professional and journalistic', 'casual' => 'casual and conversational', 'formal' => 'formal and academic', 'engaging' => 'engaging and marketing-oriented' ][ $tone ] ?? 'professional';
	return "You are an SEO expert. Analyze this article and generate optimized SEO metadata.\n\nTitle: {$title}\nCategories: {$categories}\nContent: {$content}\n\nRequirements:\n- Output language: {$language}\n- Tone: {$t}\n- seo_title: max 60 characters\n- seo_description: max 155 characters\n\nRespond with ONLY a JSON object:\n{\"seo_title\": \"...\", \"seo_description\": \"...\"}";
}

function serve_build_og_prompt( $title, $content, $categories, $tone, $language ) {
	$t = [ 'professional' => 'professional and authoritative', 'casual' => 'casual and relatable', 'formal' => 'formal and informative', 'engaging' => 'attention-grabbing and shareable' ][ $tone ] ?? 'professional';
	return "You are a social media expert. Generate Open Graph metadata optimized for Facebook, LinkedIn, WhatsApp, and Threads.\n\nTitle: {$title}\nCategories: {$categories}\nContent: {$content}\n\nRequirements:\n- Output language: {$language}\n- Tone: {$t}\n- og_title: max 70 characters\n- og_description: max 200 characters\n\nRespond with ONLY a JSON object:\n{\"og_title\": \"...\", \"og_description\": \"...\"}";
}

function serve_build_keywords_prompt( $title, $content, $categories, $language ) {
	return "You are an SEO keyword analyst. Extract the most relevant keywords and tags from this article.\n\nTitle: {$title}\nCategories: {$categories}\nContent: {$content}\n\nRequirements:\n- Output language: {$language}\n- Extract 5-10 highly relevant keywords as short phrases (1-3 words each)\n- Identify the single best focus keyword for SEO\n\nRespond with ONLY a JSON object:\n{\"focus_keyword\": \"...\", \"keywords\": [\"keyword1\", \"keyword2\", ...]}";
}

function serve_build_excerpt_prompt( $title, $content, $tone, $language ) {
	$t = [ 'professional' => 'professional and informative', 'casual' => 'casual and approachable', 'formal' => 'formal and academic', 'engaging' => 'engaging and compelling' ][ $tone ] ?? 'professional';
	return "You are an editor. Write a compelling excerpt for this article.\n\nTitle: {$title}\nContent: {$content}\n\nRequirements:\n- Output language: {$language}\n- Tone: {$t}\n- 1-2 sentences, max 160 characters\n- Do NOT start with the article title\n\nRespond with ONLY a JSON object:\n{\"excerpt\": \"...\"}";
}

function serve_build_alt_text_prompt( $filename, $post_title, $language ) {
	return "You are an accessibility expert. Generate descriptive alt text for an image.\n\nFilename: {$filename}\nArticle: {$post_title}\n\nRequirements:\n- Output language: {$language}\n- Max 125 characters\n- Do NOT start with 'Image of' or 'Photo of'\n\nRespond with ONLY a JSON object:\n{\"alt_text\": \"...\"}";
}

// ---------------------------------------------------------------------------
// JSON parser helper.
// ---------------------------------------------------------------------------

function serve_parse_claude_json( $text ): array {
	$text = trim( preg_replace( '/```(?:json)?\s*/i', '', $text ?? '' ) );
	$data = json_decode( $text, true );
	if ( json_last_error() !== JSON_ERROR_NONE && preg_match( '/\{[^{}]*\}/s', $text, $m ) ) {
		$data = json_decode( $m[0], true );
	}
	return is_array( $data ) ? $data : [];
}

// ---------------------------------------------------------------------------
// Images without alt text helper.
// ---------------------------------------------------------------------------

function serve_get_post_images_without_alt( int $post_id ): array {
	$images   = [];
	$thumb_id = get_post_thumbnail_id( $post_id );
	if ( $thumb_id && empty( trim( get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) ) ) {
		$images[ $thumb_id ] = basename( get_attached_file( $thumb_id ) );
	}
	$post = get_post( $post_id );
	if ( $post && preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches ) ) {
		foreach ( $matches[1] as $att_id ) {
			$att_id = absint( $att_id );
			if ( $att_id && ! isset( $images[ $att_id ] ) && empty( trim( get_post_meta( $att_id, '_wp_attachment_image_alt', true ) ) ) ) {
				$file = get_attached_file( $att_id );
				if ( $file ) $images[ $att_id ] = basename( $file );
			}
		}
	}
	return array_slice( $images, 0, 10, true );
}

// ---------------------------------------------------------------------------
// Block editor JS — Social Optimization sidebar panel.
// ---------------------------------------------------------------------------

function serve_ai_sidebar_js( string $nonce, bool $has_key = true ): string {
	$nj          = json_encode( $nonce );
	$aj          = json_encode( admin_url( 'admin-ajax.php' ) );
	$hk          = $has_key ? 'true' : 'false';
	$ai_url      = json_encode( admin_url( 'options-general.php?page=serve-ai-settings' ) );
	$features_json = json_encode( serve_ai_registered_features() );
	return <<<JS
(function(){
    'use strict';
    var el=wp.element.createElement,useState=wp.element.useState,useEffect=wp.element.useEffect,Fragment=wp.element.Fragment;
    var useSelect=wp.data.useSelect,useDispatch=wp.data.useDispatch;
    var PluginDocumentSettingPanel=wp.editPost.PluginDocumentSettingPanel;
    var Button=wp.components.Button,Notice=wp.components.Notice,Spinner=wp.components.Spinner;
    var NONCE={$nj},AJAXURL={$aj},HAS_KEY={$hk},AI_URL={$ai_url};
    var FEATURES={$features_json};

    if(wp.data&&wp.data.dispatch&&wp.data.dispatch('core/edit-post')){
        wp.data.dispatch('core/edit-post').removeEditorPanel('post-excerpt');
    }

    var inp={width:'100%',margin:'0 0 10px',padding:'6px 8px',border:'1px solid #ddd',borderRadius:'4px',fontSize:'12px',fontFamily:'inherit',boxSizing:'border-box'};
    var lbl={display:'block',fontSize:'11px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:'#555',marginBottom:'3px'};

    function row(label,val,onChange,multi,ph){
        return el('div',{style:{marginBottom:'12px'}},
            el('label',{style:lbl},label),
            multi?el('textarea',{style:Object.assign({},inp,{minHeight:'60px',resize:'vertical'}),value:val||'',placeholder:ph||'',onChange:function(e){onChange(e.target.value);}}):el('input',{type:'text',style:inp,value:val||'',placeholder:ph||'',onChange:function(e){onChange(e.target.value);}})
        );
    }

    function SponsoredToggle(){
        var meta=useSelect(function(s){return s('core/editor').getEditedPostAttribute('meta')||{};});
        var editPost=useDispatch('core/editor').editPost;
        function setMeta(k,v){var m={};m[k]=v;editPost({meta:m});}
        var on=meta['_serve_sponsored']==='1';
        return el('div',{style:{display:'flex',alignItems:'center',gap:'8px',padding:'8px 10px',background:on?'#fff8e1':'#f9f9f9',border:'1px solid '+(on?'#f5c400':'#e0e0e0'),borderRadius:'4px',marginBottom:'12px',cursor:'pointer'},onClick:function(){setMeta('_serve_sponsored',on?'':'1');}},
            el('span',{style:{fontSize:'18px',lineHeight:1}},'$'),
            el('div',{style:{flex:1}},el('div',{style:{fontSize:'12px',fontWeight:700,color:on?'#b8860b':'#555'}},on?'✓ Sponsored Content':'Mark as Sponsored'),el('div',{style:{fontSize:'11px',color:'#888',marginTop:'1px'}},on?'Sponsored label shown at end of title':'Adds "Sponsored" label to article title')),
            el('div',{style:{width:'32px',height:'18px',borderRadius:'9px',flexShrink:0,background:on?'#f5c400':'#ccc',position:'relative',transition:'background .2s'}},el('div',{style:{position:'absolute',top:'2px',left:on?'16px':'2px',width:'14px',height:'14px',borderRadius:'50%',background:'#fff',boxShadow:'0 1px 2px rgba(0,0,0,.3)',transition:'left .2s'}}))
        );
    }

    function SocialOptFields(){
        var meta=useSelect(function(s){return s('core/editor').getEditedPostAttribute('meta')||{};});
        var excerpt=useSelect(function(s){return s('core/editor').getEditedPostAttribute('excerpt')||'';});
        var editPost=useDispatch('core/editor').editPost;
        function setMeta(k,v){var m={};m[k]=v;editPost({meta:m});}
        return el(Fragment,null,
            el(SponsoredToggle),
            el('p',{style:{fontSize:'11px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:'#888',margin:'0 0 6px'}},'SEO'),
            row('Title',meta['serve_seo_title'],function(v){setMeta('serve_seo_title',v);},false,'Auto-generated from post title'),
            row('Meta Description',meta['serve_seo_description'],function(v){setMeta('serve_seo_description',v);},true,'Max 160 characters…'),
            row('Focus Keywords',meta['serve_seo_keywords'],function(v){setMeta('serve_seo_keywords',v);},false,'news, journalism, local…'),
            el('hr',{style:{margin:'8px 0',border:'none',borderTop:'1px solid #f0f0f0'}}),
            el('p',{style:{fontSize:'11px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:'#888',margin:'0 0 6px'}},'Open Graph / Social'),
            row('OG Title',meta['serve_og_title'],function(v){setMeta('serve_og_title',v);},false,'Defaults to SEO title'),
            row('OG Description',meta['serve_og_description'],function(v){setMeta('serve_og_description',v);},true,'Social share description…'),
            row('OG Image URL',meta['serve_og_image_url'],function(v){setMeta('serve_og_image_url',v);},false,'https://…'),
            el('hr',{style:{margin:'8px 0',border:'none',borderTop:'1px solid #f0f0f0'}}),
            el('p',{style:{fontSize:'11px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:'#888',margin:'0 0 6px'}},'Excerpt'),
            row('Excerpt',excerpt,function(v){editPost({excerpt:v});},true,'Short summary shown in feeds and social cards…')
        );
    }

    function useAiConfig(){
        var cr=useState(null),cfg=cr[0],setCfg=cr[1];
        useEffect(function(){jQuery.get(AJAXURL,{action:'serve_ai_config',nonce:NONCE},function(r){if(r&&r.success)setCfg(r.data);});},[]);
        return cfg;
    }

    function ProviderBadge({config}){
        if(!config||!config.has_key)return null;
        var ep=config.enabled_providers||[];
        if(!ep.length)return null;
        var names=ep.map(function(p){return config.provider_names[p]||p;});
        return el('div',{style:{fontSize:'10px',color:'#666',background:'#f0f4ff',border:'1px solid #c7d7ff',borderRadius:'4px',padding:'4px 8px',marginBottom:'10px',lineHeight:'1.4'}},el('strong',null,'Active: '),names.join(' + '),el('br'),el('a',{href:config.settings_url,target:'_blank',style:{color:'#3b82f6',fontSize:'10px'}},'Change in AI Settings'));
    }

    function AiGenPanel(){
        var sr=useState('idle'),status=sr[0],setStatus=sr[1];
        var mr=useState(''),msg=mr[0],setMsg=mr[1];
        var er=useState(false),err=er[0],setErr=er[1];
        var postId=useSelect(function(s){return s('core/editor').getCurrentPostId();});
        var editPost=useDispatch('core/editor').editPost;
        var config=useAiConfig();

        function run(task){
            if(!postId)return;
            setStatus('loading');setMsg('Generating…');setErr(false);
            jQuery.post(AJAXURL,{action:'serve_claude_generate',task:task,post_id:postId,_wpnonce:NONCE},function(r){
                setStatus('done');
                if(r.success){
                    var d=r.data,meta={};
                    if(d.seo_title)       meta['serve_seo_title']      =d.seo_title;
                    if(d.seo_description) meta['serve_seo_description'] =d.seo_description;
                    if(d.og_title)        meta['serve_og_title']        =d.og_title;
                    if(d.og_desc)         meta['serve_og_description']  =d.og_desc;
                    if(d.focus_keyword)   meta['serve_seo_keywords']    =d.focus_keyword;
                    if(Object.keys(meta).length) editPost({meta:meta});
                    if(d.excerpt)         editPost({excerpt:d.excerpt});
                    var m={seo:'SEO title + description applied.',og:'Open Graph tags applied.',keywords:'Keywords applied.',excerpt:'Excerpt applied.',alt_text:'Alt text applied.',all:'All metadata applied.'};
                    setErr(false);setMsg(m[task]||'Done.');
                }else{
                    setErr(true);setMsg(typeof r.data==='string'?r.data:'Generation failed.');
                }
            }).fail(function(xhr){
                setStatus('done');setErr(true);
                var code=xhr.status;
                if(code===429)setMsg('Rate limit reached — please wait a moment and try again.');
                else if(code===404)setMsg('AI endpoint not found. Check Settings → AI Settings.');
                else if(code===0)setMsg('Network error — check your internet connection.');
                else setMsg('Request failed (HTTP '+code+'). Check Settings → AI Settings.');
            });
        }

        var loading=status==='loading';
        var bs={width:'100%',marginBottom:'5px',justifyContent:'flex-start',fontSize:'11px'};

        if(!HAS_KEY){
            return el('p',{style:{fontSize:'11px',color:'#888',lineHeight:'1.5'}},'Configure your AI provider in ',el('a',{href:AI_URL,target:'_blank'},'Settings → AI Settings'),' to enable generation.');
        }
        return el(Fragment,null,
            el(ProviderBadge,{config:config}),
            loading&&el('div',{style:{display:'flex',alignItems:'center',gap:'6px',marginBottom:'8px',fontSize:'11px',color:'#1e40af'}},el(Spinner,{style:{width:16,height:16}}),msg),
            !loading&&status==='done'&&el(Notice,{status:err?'error':'success',isDismissible:true,onRemove:function(){setStatus('idle');setMsg('');}},el('span',{style:{fontSize:'11px'}},msg)),
            el(Button,{variant:'secondary',style:bs,onClick:function(){run('seo');},disabled:loading},'SEO Title + Description'),
            el(Button,{variant:'secondary',style:bs,onClick:function(){run('og');},disabled:loading},'Open Graph / Social'),
            el(Button,{variant:'secondary',style:bs,onClick:function(){run('keywords');},disabled:loading},'Keywords + Tags'),
            el(Button,{variant:'secondary',style:bs,onClick:function(){run('excerpt');},disabled:loading},'Excerpt'),
            el(Button,{variant:'secondary',style:bs,onClick:function(){run('alt_text');},disabled:loading},'Alt Text for Images'),
            el('hr',{style:{margin:'6px 0',border:'none',borderTop:'1px solid #f0f0f0'}}),
            el(Button,{variant:'primary',style:Object.assign({},bs,{background:'#c62828',color:'#fff',border:'none',fontSize:'11px',fontWeight:700}),onClick:function(){run('all');},disabled:loading},'Generate Everything')
        );
    }

    wp.plugins.registerPlugin('serve-ai-seo',{
        render:function(){
            return el(PluginDocumentSettingPanel,{name:'serve-seo-panel',title:'Social Optimization',className:'serve-seo-panel',initialOpen:false,order:20},
                el(SocialOptFields),
                el('div',{style:{marginTop:'8px',padding:'8px 0',borderTop:'1px solid #f0f0f0'}},
                    el('p',{style:{fontSize:'11px',fontWeight:700,textTransform:'uppercase',letterSpacing:'.04em',color:'#888',margin:'0 0 6px'}},'AI Generate'),
                    el(AiGenPanel)
                )
            );
        }
    });
})();
JS;
}
