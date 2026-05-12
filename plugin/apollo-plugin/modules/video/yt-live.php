<?php
/**
 * YouTube Live Detection & Embed
 *
 * Checks the YouTube Data API v3 every 15 minutes to see if the channel
 * is currently live. Caches result in a transient. Provides a manual
 * refresh AJAX endpoint. Renders an embedded live banner on the homepage
 * and Video Hub when a live stream is detected.
 *
 * Options:
 *   svh_yt_channel_id  — YouTube channel ID (e.g. UCxxxxxxxxx)
 *   svh_yt_api_key     — YouTube Data API v3 key
 *
 * Transient:
 *   svh_yt_live_status — cached API result, expires in 15 min
 *
 * @package Apollo
 */

defined( 'ABSPATH' ) || exit;

// ─── CONSTANTS ────────────────────────────────────────────────────────────

define( 'SVH_YT_TRANSIENT',     'svh_yt_live_status' );
define( 'SVH_YT_CRON_HOOK',    'svh_yt_cron_check' );
define( 'SVH_YT_CACHE_SECS',   15 * MINUTE_IN_SECONDS );

// ─── CRON SCHEDULE ────────────────────────────────────────────────────────

add_filter( 'cron_schedules', function( array $s ): array {
    $s['svh_15min'] = [
        'interval' => SVH_YT_CACHE_SECS,
        'display'  => __( 'Every 15 Minutes', 'serve' ),
    ];
    return $s;
} );

add_action( 'init', function(): void {
    if ( ! wp_next_scheduled( SVH_YT_CRON_HOOK ) ) {
        wp_schedule_event( time(), 'svh_15min', SVH_YT_CRON_HOOK );
    }
} );

add_action( SVH_YT_CRON_HOOK, 'svh_yt_fetch_live_status' );

register_deactivation_hook( __FILE__, function(): void {
    wp_clear_scheduled_hook( SVH_YT_CRON_HOOK );
} );

// ─── API ──────────────────────────────────────────────────────────────────

/**
 * Fetch live status from YouTube API and cache in transient.
 * Returns the live video data array or false if not live / no credentials.
 */
function svh_yt_fetch_live_status(): array|false {
    $channel_id = (string) get_option( 'svh_yt_channel_id', '' );
    $api_key    = (string) get_option( 'svh_yt_api_key', '' );

    if ( ! $channel_id || ! $api_key ) {
        delete_transient( SVH_YT_TRANSIENT );
        return false;
    }

    $url = add_query_arg( [
        'part'        => 'snippet',
        'channelId'   => $channel_id,
        'eventType'   => 'live',
        'type'        => 'video',
        'maxResults'  => 1,
        'key'         => $api_key,
    ], 'https://www.googleapis.com/youtube/v3/search' );

    $response = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => true ] );

    if ( is_wp_error( $response ) ) {
        // Cache a short failure so we don't hammer API on errors
        set_transient( SVH_YT_TRANSIENT, [ 'live' => false, 'error' => $response->get_error_message() ], 5 * MINUTE_IN_SECONDS );
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $code = wp_remote_retrieve_response_code( $response );

    if ( $code !== 200 || empty( $body['items'] ) ) {
        $error = $body['error']['message'] ?? 'No live stream found';
        $data  = [ 'live' => false, 'error' => $error, 'checked_at' => time() ];
        set_transient( SVH_YT_TRANSIENT, $data, SVH_YT_CACHE_SECS );
        return false;
    }

    $item     = $body['items'][0];
    $video_id = $item['id']['videoId'] ?? '';
    $title    = $item['snippet']['title'] ?? '';
    $thumb    = $item['snippet']['thumbnails']['high']['url'] ?? ( $item['snippet']['thumbnails']['medium']['url'] ?? '' );
    $desc     = $item['snippet']['description'] ?? '';

    $data = [
        'live'       => true,
        'video_id'   => $video_id,
        'title'      => $title,
        'thumb'      => $thumb,
        'desc'       => wp_trim_words( $desc, 20 ),
        'channel_id' => $channel_id,
        'checked_at' => time(),
    ];

    set_transient( SVH_YT_TRANSIENT, $data, SVH_YT_CACHE_SECS );
    return $data;
}

/**
 * Get the cached live status, fetching fresh if transient is expired.
 */
function svh_yt_get_live_status(): array|false {
    $cached = get_transient( SVH_YT_TRANSIENT );
    if ( $cached !== false ) return $cached;
    return svh_yt_fetch_live_status();
}

/**
 * Returns true if the channel is currently live.
 */
function svh_yt_is_live(): bool {
    $status = svh_yt_get_live_status();
    return is_array( $status ) && ! empty( $status['live'] );
}

// ─── AJAX: MANUAL REFRESH ─────────────────────────────────────────────────

add_action( 'wp_ajax_svh_yt_refresh',        'svh_yt_ajax_refresh' );
add_action( 'wp_ajax_nopriv_svh_yt_refresh', 'svh_yt_ajax_refresh' );

function svh_yt_ajax_refresh(): void {
    if ( ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ?? '' ), 'svh_yt_refresh' ) ) {
        wp_send_json_error( 'invalid_nonce' );
    }

    // Throttle: don't allow manual refresh more than once per 60 seconds
    if ( get_transient( 'svh_yt_refresh_throttle' ) ) {
        $status = svh_yt_get_live_status();
        wp_send_json_success( [
            'live'       => is_array($status) && !empty($status['live']),
            'throttled'  => true,
            'status'     => $status ?: [],
        ] );
    }

    delete_transient( SVH_YT_TRANSIENT );
    set_transient( 'svh_yt_refresh_throttle', 1, 60 );

    $status = svh_yt_fetch_live_status();

    wp_send_json_success( [
        'live'      => is_array($status) && !empty($status['live']),
        'throttled' => false,
        'status'    => $status ?: [],
    ] );
}

// ─── ENQUEUE ──────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! get_option('svh_yt_channel_id') || ! get_option('svh_yt_api_key') ) return;

    wp_localize_script( 'jquery', 'svhYT', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'svh_yt_refresh' ),
    ] );
} );

// ─── RENDER BANNER ────────────────────────────────────────────────────────

/**
 * Render the live stream embed banner.
 * Call on homepage and video hub archive pages.
 *
 * @param string $context  'homepage' | 'video_hub'
 */
function svh_yt_render_live_banner( string $context = 'homepage' ): void {
    $channel_id = (string) get_option( 'svh_yt_channel_id', '' );
    $api_key    = (string) get_option( 'svh_yt_api_key', '' );

    if ( ! $channel_id || ! $api_key ) return;

    $status   = svh_yt_get_live_status();
    $is_live  = is_array($status) && !empty($status['live']);
    $video_id = $is_live ? ($status['video_id'] ?? '') : '';
    $title    = $is_live ? ($status['title'] ?? 'Live Now') : '';
    $checked  = $is_live ? ($status['checked_at'] ?? 0) : ($status['checked_at'] ?? 0);
    $accent   = get_theme_mod( 'flavor_accent_color', '#c62828' );
    $nonce    = wp_create_nonce( 'svh_yt_refresh' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    $channel_url = "https://www.youtube.com/channel/{$channel_id}/live";

    if ( ! $is_live ) {
        // Not live — show a subtle "check live" button only (no banner)
        svh_yt_render_check_button( $context, $checked, $nonce, $ajax_url, $accent, $channel_url );
        return;
    }

    ?>
    <div class="svh-live-banner svh-live-banner--<?php echo esc_attr($context); ?>"
         id="svh-live-banner-<?php echo esc_attr($context); ?>"
         data-video-id="<?php echo esc_attr($video_id); ?>">

        <div class="svh-live-banner__head">
            <span class="svh-live-badge">
                <span class="svh-live-dot" aria-hidden="true"></span>
                <?php esc_html_e( 'LIVE NOW', 'serve' ); ?>
            </span>
            <span class="svh-live-banner__title"><?php echo esc_html($title); ?></span>
            <div class="svh-live-banner__actions">
                <button class="svh-live-refresh-btn"
                        data-context="<?php echo esc_attr($context); ?>"
                        data-nonce="<?php echo esc_attr($nonce); ?>"
                        data-ajax="<?php echo esc_attr($ajax_url); ?>"
                        title="<?php esc_attr_e('Check live status now','serve'); ?>"
                        aria-label="<?php esc_attr_e('Refresh live status','serve'); ?>">
                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 7A7 7 0 1 1 2 10"/><polyline points="3.5,3 3.5,7 7.5,7"/></svg>
                </button>
                <a href="<?php echo esc_url($channel_url); ?>"
                   target="_blank" rel="noopener noreferrer"
                   class="svh-live-banner__yt-link"
                   title="<?php esc_attr_e('Open on YouTube','serve'); ?>">
                    <svg viewBox="0 0 20 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><rect width="20" height="14" rx="3" fill="#fff" fill-opacity=".2"/><polygon points="8,3 8,11 14.5,7" fill="#fff"/></svg>
                    <?php esc_html_e( 'Open on YouTube', 'serve' ); ?>
                </a>
            </div>
        </div>

        <div class="svh-live-banner__embed">
            <div class="svh-live-banner__player-wrap">
                <iframe
                    src="https://www.youtube-nocookie.com/embed/<?php echo esc_attr($video_id); ?>?autoplay=1&mute=1&rel=0&modestbranding=1&playsinline=1"
                    title="<?php echo esc_attr($title); ?>"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    style="position:absolute;inset:0;width:100%;height:100%;border:0;">
                </iframe>
            </div>
            <?php if ( $status['desc'] ?? '' ) : ?>
                <p class="svh-live-banner__desc"><?php echo esc_html($status['desc']); ?></p>
            <?php endif; ?>
        </div>

    </div>
    <?php
    svh_yt_enqueue_banner_js();
}

function svh_yt_render_check_button( string $context, int $checked, string $nonce, string $ajax_url, string $accent, string $channel_url ): void {
    $ago = $checked ? human_time_diff( $checked, time() ) . ' ago' : 'never';
    ?>
    <div class="svh-live-check svh-live-check--<?php echo esc_attr($context); ?>"
         id="svh-live-check-<?php echo esc_attr($context); ?>">
        <span class="svh-live-check__offline">
            <span class="svh-live-dot svh-live-dot--offline" aria-hidden="true"></span>
            <?php esc_html_e( 'Not currently live', 'serve' ); ?>
            <span class="svh-live-check__time">· <?php echo esc_html__('Checked', 'serve'); ?> <?php echo esc_html($ago); ?></span>
        </span>
        <button class="svh-live-refresh-btn svh-live-refresh-btn--sm"
                data-context="<?php echo esc_attr($context); ?>"
                data-nonce="<?php echo esc_attr($nonce); ?>"
                data-ajax="<?php echo esc_attr($ajax_url); ?>">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="14" height="14"><path d="M3.5 7A7 7 0 1 1 2 10"/><polyline points="3.5,3 3.5,7 7.5,7"/></svg>
            <?php esc_html_e( 'Check now', 'serve' ); ?>
        </button>
        <a href="<?php echo esc_url($channel_url); ?>" target="_blank" rel="noopener noreferrer"
           class="svh-live-check__yt">
           <?php esc_html_e( 'YouTube Channel', 'serve' ); ?> ↗
        </a>
    </div>
    <?php
    svh_yt_enqueue_banner_js();
}

function svh_yt_enqueue_banner_js(): void {
    static $done = false;
    if ( $done ) return;
    $done = true;
    ?>
    <script>
    (function(){
    'use strict';
    function initRefreshBtns(){
        document.querySelectorAll('.svh-live-refresh-btn').forEach(function(btn){
            if(btn.dataset.svhBound) return;
            btn.dataset.svhBound = '1';
            btn.addEventListener('click', function(){
                var ctx  = btn.dataset.context;
                var ajax = btn.dataset.ajax;
                var n    = btn.dataset.nonce;
                btn.classList.add('svh-live-refresh-btn--spinning');
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action','svh_yt_refresh');
                fd.append('nonce', n);
                fetch(ajax, {method:'POST', body:fd})
                .then(function(r){ return r.json(); })
                .then(function(data){
                    btn.classList.remove('svh-live-refresh-btn--spinning');
                    btn.disabled = false;
                    if(!data.success) return;
                    var s = data.data;
                    if(s.live){
                        // Page reload to render the live banner
                        location.reload();
                    } else {
                        // Update the "checked" time
                        var timeEl = document.querySelector('.svh-live-check--'+ctx+' .svh-live-check__time');
                        if(timeEl) timeEl.textContent = '· Checked just now';
                        if(s.throttled){
                            btn.title = 'Checked recently — try again in a moment';
                        }
                    }
                })
                .catch(function(){
                    btn.classList.remove('svh-live-refresh-btn--spinning');
                    btn.disabled = false;
                });
            });
        });
    }
    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded', initRefreshBtns);
    } else {
        initRefreshBtns();
    }
    })();
    </script>
    <?php
}

// ─── CSS ──────────────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function(): void {
    if ( ! get_option('svh_yt_channel_id') || ! get_option('svh_yt_api_key') ) return;
    $accent = get_theme_mod('flavor_accent_color','#c62828');
    serve_add_consolidated_css( 'svh-yt-live', svh_yt_live_css( $accent ) );
} );

function svh_yt_live_css( string $accent ): string {
    return "
/* ─── YouTube Live Banner ────────────────────────────────────────────────── */
.svh-live-banner{background:#0d0d0d;color:#fff;border-bottom:3px solid {$accent};margin:0}
.svh-live-banner--video_hub{border-bottom:none;border-top:3px solid {$accent}}
.svh-live-banner__head{display:flex;align-items:center;gap:.75rem;padding:.65rem max(16px,calc((100vw - 1400px)/2));flex-wrap:wrap;background:#111}
.svh-live-badge{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:.1em;background:{$accent};color:#fff;padding:3px 10px;border-radius:3px;white-space:nowrap;flex-shrink:0}
.svh-live-dot{display:inline-block;width:8px;height:8px;background:#fff;border-radius:50%;animation:svh-live-pulse 1.2s ease-in-out infinite;flex-shrink:0}
.svh-live-dot--offline{background:#666;animation:none}
@keyframes svh-live-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(1.3)}}
.svh-live-banner__title{flex:1;font-weight:700;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.svh-live-banner__actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.svh-live-refresh-btn{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.2);color:#fff;cursor:pointer;padding:5px;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;transition:background .15s}
.svh-live-refresh-btn:hover{background:rgba(255,255,255,.2)}
.svh-live-refresh-btn svg{width:16px;height:16px}
.svh-live-refresh-btn--spinning svg{animation:svh-spin .7s linear infinite}
@keyframes svh-spin{to{transform:rotate(360deg)}}
.svh-live-banner__yt-link{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:700;color:rgba(255,255,255,.75);text-decoration:none;padding:5px 10px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:4px;white-space:nowrap;transition:background .15s}
.svh-live-banner__yt-link:hover{background:rgba(255,255,255,.2);color:#fff}
.svh-live-banner__yt-link svg{width:18px;height:13px}
.svh-live-banner__embed{padding:0 max(16px,calc((100vw - 1400px)/2)) 1rem;background:#0d0d0d}
.svh-live-banner__player-wrap{position:relative;width:100%;max-width:900px;aspect-ratio:16/9;border-radius:4px;overflow:hidden;background:#000}
.svh-live-banner__desc{color:rgba(255,255,255,.6);font-size:.8rem;margin:.75rem 0 0;max-width:900px}

/* ─── Not-live check bar ──────────────────────────────────────────────────── */
.svh-live-check{display:flex;align-items:center;gap:.75rem;padding:.5rem max(16px,calc((100vw - 1400px)/2));font-size:.78rem;color:#888;border-bottom:1px solid var(--flavor-border,#e5e7eb);flex-wrap:wrap}
.svh-live-check--video_hub{background:#0d0d0d;color:rgba(255,255,255,.4);border-bottom:none;border-top:1px solid rgba(255,255,255,.08);padding-top:.6rem;padding-bottom:.6rem}
.svh-live-check__offline{display:inline-flex;align-items:center;gap:5px}
.svh-live-check__time{opacity:.7}
.svh-live-refresh-btn--sm{background:none;border:1px solid currentColor;color:inherit;cursor:pointer;padding:3px 8px;border-radius:3px;display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-family:var(--flavor-font-ui,system-ui);opacity:.7;transition:opacity .15s}
.svh-live-refresh-btn--sm:hover{opacity:1}
.svh-live-refresh-btn--sm.svh-live-refresh-btn--spinning svg{animation:svh-spin .7s linear infinite}
.svh-live-check__yt{color:inherit;font-size:.72rem;opacity:.6;text-decoration:none}
.svh-live-check__yt:hover{opacity:1;text-decoration:underline}

/* Homepage: constrain within flavor-container */
.svh-live-banner--homepage{margin-left:calc(-1 * var(--flavor-gap,20px));margin-right:calc(-1 * var(--flavor-gap,20px));border-radius:0}
.svh-live-check--homepage{margin-left:calc(-1 * var(--flavor-gap,20px));margin-right:calc(-1 * var(--flavor-gap,20px));background:transparent}
@media(max-width:600px){.svh-live-banner__title{display:none}.svh-live-banner__yt-link span{display:none}}
";
}

// Admin force-refresh for YouTube live status
add_action('admin_init', function(): void {
    if (!isset($_GET['action']) || $_GET['action'] !== 'svh_yt_force_refresh') return;
    if (!current_user_can('manage_options')) return;
    if (!wp_verify_nonce(sanitize_key($_GET['_wpnonce']??''),'svh_yt_force')) return;
    delete_transient('svh_yt_live_status');
    delete_transient('svh_yt_refresh_throttle');
    wp_safe_redirect(admin_url('edit.php?post_type=serve_video&page=svh-settings&yt_refreshed=1'));
    exit;
});
