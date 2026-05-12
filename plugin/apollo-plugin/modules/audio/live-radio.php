<?php
/**
 * Live Radio — Penny Tribune
 * Streams music from SoundCloud and YouTube playlists, shuffled.
 * Renders on the /listen/ archive page above the podcast carousel.
 *
 * Admin: Podcasts → 📻 Live Radio
 * Options:
 *   slr_playlists    — JSON array of {type,url,label}
 *   slr_enabled      — '1'|'0'
 *   slr_show_ads     — '1'|'0'
 *   slr_ad_type      — 'wordads'|'adsense'|'both'|'none'
 *   slr_adsense_slot — AdSense data-ad-slot value
 *   slr_adsense_client — AdSense data-ad-client value
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════════════════
// 1. ADMIN MENU — Podcasts → 📻 Live Radio
// ═══════════════════════════════════════════════════════════════════════════

add_action('admin_menu', function(): void {
    add_submenu_page(
        'edit.php?post_type=serve_podcast',
        'Live Radio',
        '📻 Live Radio',
        'manage_options',
        'slr-settings',
        'slr_settings_page'
    );
}, 12);

// ═══════════════════════════════════════════════════════════════════════════
// 2. SETTINGS PAGE
// ═══════════════════════════════════════════════════════════════════════════

function slr_settings_page(): void {
    if (!current_user_can('manage_options')) return;

    // ── Save ──────────────────────────────────────────────────────────────
    if (isset($_POST['slr_nonce']) &&
        wp_verify_nonce(sanitize_key($_POST['slr_nonce']), 'slr_save')) {

        update_option('slr_enabled',      isset($_POST['slr_enabled']) ? '1' : '0');
        update_option('slr_show_ads',     isset($_POST['slr_show_ads']) ? '1' : '0');
        update_option('slr_ad_type',      sanitize_text_field(wp_unslash($_POST['slr_ad_type'] ?? 'none')));
        update_option('slr_adsense_slot', sanitize_text_field(wp_unslash($_POST['slr_adsense_slot'] ?? '')));
        update_option('slr_adsense_client', sanitize_text_field(wp_unslash($_POST['slr_adsense_client'] ?? '')));

        // Playlists — decode the JSON textarea
        $raw = wp_unslash($_POST['slr_playlists_json'] ?? '[]');
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            // Sanitize each entry
            $clean = [];
            foreach ($decoded as $pl) {
                $type  = sanitize_text_field($pl['type'] ?? '');
                $url   = esc_url_raw($pl['url'] ?? '');
                $label = sanitize_text_field($pl['label'] ?? '');
                if ($type && $url) {
                    $clean[] = compact('type', 'url', 'label');
                }
            }
            update_option('slr_playlists', wp_json_encode($clean));
        }

        echo '<div class="notice notice-success is-dismissible"><p>✅ Live Radio settings saved.</p></div>';
    }

    // ── Read current settings ─────────────────────────────────────────────
    $enabled        = get_option('slr_enabled', '1');
    $show_ads       = get_option('slr_show_ads', '0');
    $ad_type        = get_option('slr_ad_type', 'none');
    $adsense_slot   = get_option('slr_adsense_slot', '');
    $adsense_client = get_option('slr_adsense_client', '');
    $playlists_json = get_option('slr_playlists', '[]');
    ?>
    <div class="wrap" style="max-width:800px">
        <h1>📻 Live Radio</h1>
        <p style="color:#555;margin-bottom:24px">Add SoundCloud and YouTube playlists. The radio player will fetch all tracks and shuffle through them continuously on the <strong>/listen/</strong> page.</p>

        <form method="post">
            <?php wp_nonce_field('slr_save', 'slr_nonce'); ?>

            <!-- Enable toggle -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-weight:600;font-size:14px">
                    <input type="checkbox" name="slr_enabled" value="1" <?php checked($enabled, '1'); ?> style="width:18px;height:18px">
                    Show Live Radio player on /listen/ page
                </label>
            </div>

            <!-- Playlists -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px">
                <h2 style="margin:0 0 6px;font-size:15px;font-weight:700">Playlists</h2>
                <p style="color:#666;font-size:13px;margin:0 0 16px">Add as many SoundCloud or YouTube playlists as you want. All tracks are fetched and shuffled together.</p>

                <div id="slr-playlist-list" style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px"></div>

                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="button" id="slr-add-sc" class="button" style="background:#f26f23;border-color:#f26f23;color:#fff">
                        + Add SoundCloud Playlist
                    </button>
                    <button type="button" id="slr-add-yt" class="button" style="background:#ff0000;border-color:#c00;color:#fff">
                        + Add YouTube Playlist
                    </button>
                </div>

                <!-- Hidden field holds JSON -->
                <input type="hidden" name="slr_playlists_json" id="slr_playlists_json" value="<?php echo esc_attr($playlists_json); ?>">

                <div style="margin-top:14px;padding:12px;background:#f8faff;border-radius:6px;font-size:12px;color:#555">
                    <strong>SoundCloud:</strong> Use the playlist page URL, e.g. <code>https://soundcloud.com/user/sets/playlist-name</code><br>
                    <strong>YouTube:</strong> Use the playlist URL, e.g. <code>https://www.youtube.com/playlist?list=PLxxxxxxx</code>
                </div>
            </div>

            <!-- Ad settings -->
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin-bottom:20px">
                <h2 style="margin:0 0 14px;font-size:15px;font-weight:700">Ads in Player</h2>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="padding:8px 0;font-size:13px">Show ads</th>
                        <td><label><input type="checkbox" name="slr_show_ads" value="1" <?php checked($show_ads, '1'); ?>> Show an ad unit alongside the radio player</label></td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;font-size:13px">Ad type</th>
                        <td>
                            <select name="slr_ad_type" style="min-width:180px">
                                <?php foreach (['none'=>'None','wordads'=>'WordAds','adsense'=>'Google AdSense','both'=>'Both (WordAds + AdSense)'] as $v => $l): ?>
                                <option value="<?php echo esc_attr($v); ?>" <?php selected($ad_type, $v); ?>><?php echo esc_html($l); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;font-size:13px">AdSense client</th>
                        <td>
                            <input type="text" name="slr_adsense_client" value="<?php echo esc_attr($adsense_client); ?>"
                                   class="regular-text" placeholder="ca-pub-XXXXXXXXXXXXXXXX">
                            <p class="description">Your AdSense publisher ID (data-ad-client value)</p>
                        </td>
                    </tr>
                    <tr>
                        <th style="padding:8px 0;font-size:13px">AdSense slot</th>
                        <td>
                            <input type="text" name="slr_adsense_slot" value="<?php echo esc_attr($adsense_slot); ?>"
                                   class="regular-text" placeholder="1234567890">
                            <p class="description">The data-ad-slot value for the ad unit you want shown next to the radio player</p>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button('Save Live Radio Settings', 'primary large'); ?>
        </form>
    </div>

    <script>
    (function(){
        var list  = document.getElementById('slr-playlist-list');
        var field = document.getElementById('slr_playlists_json');
        var data  = [];

        try { data = JSON.parse(field.value) || []; } catch(e) { data = []; }

        function render() {
            field.value = JSON.stringify(data);
            list.innerHTML = '';
            if (!data.length) {
                list.innerHTML = '<p style="color:#888;font-size:13px;margin:0">No playlists added yet.</p>';
                return;
            }
            data.forEach(function(pl, i) {
                var row = document.createElement('div');
                row.style.cssText = 'display:flex;gap:8px;align-items:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px 12px';
                var badge = pl.type === 'soundcloud'
                    ? '<span style="background:#f26f23;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;flex-shrink:0">SC</span>'
                    : '<span style="background:#ff0000;color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;flex-shrink:0">YT</span>';
                row.innerHTML = badge
                    + '<input type="text" value="' + pl.url.replace(/"/g,'&quot;') + '" placeholder="Playlist URL" '
                    + 'style="flex:1;border:1px solid #d1d5db;border-radius:4px;padding:5px 8px;font-size:13px" '
                    + 'data-idx="' + i + '" data-field="url">'
                    + '<input type="text" value="' + (pl.label||'').replace(/"/g,'&quot;') + '" placeholder="Label (optional)" '
                    + 'style="width:160px;border:1px solid #d1d5db;border-radius:4px;padding:5px 8px;font-size:13px" '
                    + 'data-idx="' + i + '" data-field="label">'
                    + '<button type="button" data-remove="' + i + '" style="background:#fee2e2;border:none;color:#991b1b;border-radius:4px;padding:4px 10px;cursor:pointer;font-size:13px">✕</button>';
                list.appendChild(row);
            });

            list.querySelectorAll('input[data-idx]').forEach(function(inp) {
                inp.addEventListener('input', function() {
                    var idx = parseInt(this.dataset.idx);
                    data[idx][this.dataset.field] = this.value;
                    field.value = JSON.stringify(data);
                });
            });
            list.querySelectorAll('[data-remove]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    data.splice(parseInt(this.dataset.remove), 1);
                    render();
                });
            });
        }

        render();

        document.getElementById('slr-add-sc').addEventListener('click', function() {
            data.push({type:'soundcloud', url:'', label:''});
            render();
            list.querySelectorAll('input[data-field="url"]').pop().focus();
        });
        document.getElementById('slr-add-yt').addEventListener('click', function() {
            data.push({type:'youtube', url:'', label:''});
            render();
            list.querySelectorAll('input[data-field="url"]').pop().focus();
        });
    })();
    </script>
    <?php
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. AJAX: FETCH PLAYLIST TRACKS (SERVER-SIDE PROXY)
//    SoundCloud oEmbed + YouTube Data API v3 playlist items
//    Both are public calls — no auth needed for public playlists
// ═══════════════════════════════════════════════════════════════════════════

add_action('wp_ajax_slr_fetch_tracks',        'slr_ajax_fetch_tracks');
add_action('wp_ajax_nopriv_slr_fetch_tracks', 'slr_ajax_fetch_tracks');

function slr_ajax_fetch_tracks(): void {
    check_ajax_referer('slr_public', 'nonce');

    $url  = esc_url_raw(wp_unslash($_POST['url'] ?? ''));
    $type = sanitize_text_field(wp_unslash($_POST['type'] ?? ''));
    if (!$url || !in_array($type, ['soundcloud','youtube'], true)) {
        wp_send_json_error('Invalid request');
    }

    // Cache per playlist URL — 6 hours
    $cache_key = 'slr_tracks_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json_success($cached);
    }

    $tracks = [];

    if ($type === 'soundcloud') {
        $tracks = slr_fetch_soundcloud_tracks($url);
    } elseif ($type === 'youtube') {
        $tracks = slr_fetch_youtube_tracks($url);
    }

    if (is_wp_error($tracks)) {
        wp_send_json_error($tracks->get_error_message());
    }

    set_transient($cache_key, $tracks, 6 * HOUR_IN_SECONDS);
    wp_send_json_success($tracks);
}

/**
 * Fetch SoundCloud playlist tracks via oEmbed API (no API key needed).
 * Returns array of {id, title, embed_url, type:'soundcloud'}
 */
function slr_fetch_soundcloud_tracks(string $playlist_url): array|WP_Error {
    // SoundCloud oEmbed — resolves playlist metadata
    $oembed_url = add_query_arg([
        'url'    => rawurlencode($playlist_url),
        'format' => 'json',
    ], 'https://soundcloud.com/oembed');

    $resp = wp_remote_get($oembed_url, ['timeout' => 15, 'sslverify' => true]);
    if (is_wp_error($resp)) return $resp;
    $code = wp_remote_retrieve_response_code($resp);
    if ($code !== 200) return new WP_Error('sc_error', "SoundCloud oEmbed HTTP {$code}");

    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($body)) return new WP_Error('sc_parse', 'Could not parse SoundCloud response');

    // Extract the embed URL from the HTML iframe
    preg_match('/src="([^"]+)"/', $body['html'] ?? '', $m);
    $embed_src = $m[1] ?? '';
    if (!$embed_src) return new WP_Error('sc_embed', 'No embed URL found in SoundCloud response');

    // For playlists the embed URL is the whole set — we return one track entry
    // The JS player will use the SoundCloud Widget API to enumerate tracks
    return [[
        'type'      => 'soundcloud',
        'title'     => $body['title'] ?? 'SoundCloud Playlist',
        'embed_url' => html_entity_decode($embed_src),
        'is_playlist' => true,
    ]];
}

/**
 * Fetch YouTube playlist tracks via YouTube oEmbed + Data API v3 if key available.
 * Falls back to embed-only if no API key.
 * Returns array of {id, title, embed_url, type:'youtube'}
 */
function slr_fetch_youtube_tracks(string $playlist_url): array|WP_Error {
    // Parse playlist ID from URL
    $playlist_id = '';
    if (preg_match('/[?&]list=([A-Za-z0-9_-]+)/', $playlist_url, $m)) {
        $playlist_id = $m[1];
    }
    if (!$playlist_id) return new WP_Error('yt_parse', 'Could not extract playlist ID from YouTube URL');

    $yt_key = get_option('slr_youtube_api_key', '');

    if ($yt_key) {
        // Use YouTube Data API v3 to get all video IDs
        $tracks = [];
        $page_token = '';
        $iterations = 0;
        do {
            $args = [
                'part'       => 'snippet',
                'playlistId' => $playlist_id,
                'maxResults' => '50',
                'key'        => $yt_key,
            ];
            if ($page_token) $args['pageToken'] = $page_token;

            $api_url = add_query_arg($args, 'https://www.googleapis.com/youtube/v3/playlistItems');
            $resp    = wp_remote_get($api_url, ['timeout' => 15]);
            if (is_wp_error($resp)) break;
            if (wp_remote_retrieve_response_code($resp) !== 200) break;

            $data = json_decode(wp_remote_retrieve_body($resp), true);
            if (!is_array($data)) break;

            foreach (($data['items'] ?? []) as $item) {
                $vid_id = $item['snippet']['resourceId']['videoId'] ?? '';
                $title  = $item['snippet']['title'] ?? '';
                if ($vid_id && $title !== 'Deleted video' && $title !== 'Private video') {
                    $tracks[] = [
                        'type'      => 'youtube',
                        'title'     => $title,
                        'embed_url' => "https://www.youtube.com/embed/{$vid_id}?autoplay=1&enablejsapi=1",
                        'video_id'  => $vid_id,
                        'is_playlist' => false,
                    ];
                }
            }
            $page_token = $data['nextPageToken'] ?? '';
            $iterations++;
        } while ($page_token && $iterations < 10); // max 500 tracks

        return $tracks ?: new WP_Error('yt_empty', 'No tracks found in playlist');
    }

    // No API key — embed the whole playlist as one item
    return [[
        'type'        => 'youtube',
        'title'       => 'YouTube Playlist',
        'embed_url'   => "https://www.youtube.com/embed?listType=playlist&list={$playlist_id}&autoplay=1&shuffle=1",
        'playlist_id' => $playlist_id,
        'is_playlist' => true,
    ]];
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. RENDER FUNCTION — Called from archive-serve_podcast.php
// ═══════════════════════════════════════════════════════════════════════════

function slr_render_player(): void {
    if (get_option('slr_enabled', '1') !== '1') return;

    $playlists_raw = get_option('slr_playlists', '[]');
    $playlists     = json_decode($playlists_raw, true);
    if (!is_array($playlists) || empty($playlists)) {
        // Show placeholder only to admins
        if (!current_user_can('manage_options')) return;
        echo '<div style="background:#1a1a2e;border:2px dashed #374151;padding:24px;text-align:center;color:#9ca3af;margin-bottom:0">'
           . '<p style="margin:0 0 8px;font-size:14px">📻 <strong>Live Radio</strong> — No playlists configured yet.</p>'
           . '<a href="' . esc_url(admin_url('edit.php?post_type=serve_podcast&page=slr-settings')) . '" style="color:#f26f23">Configure playlists →</a>'
           . '</div>';
        return;
    }

    $show_ads       = get_option('slr_show_ads', '0') === '1';
    $ad_type        = get_option('slr_ad_type', 'none');
    $adsense_slot   = get_option('slr_adsense_slot', '');
    $adsense_client = get_option('slr_adsense_client', '');
    $nonce          = wp_create_nonce('slr_public');
    $ajax_url       = admin_url('admin-ajax.php');

    // Build playlist config for JS
    $pl_config = [];
    foreach ($playlists as $pl) {
        $pl_config[] = [
            'type'  => $pl['type'],
            'url'   => $pl['url'],
            'label' => $pl['label'] ?: '',
        ];
    }
    $pl_json = wp_json_encode($pl_config);

    // Ad HTML
    $ad_html = '';
    if ($show_ads && $ad_type !== 'none') {
        $ad_parts = [];
        if (in_array($ad_type, ['wordads', 'both'], true)) {
            $wa = '';
            if (function_exists('wordads_ad_tag')) {
                ob_start(); wordads_ad_tag('sidebar'); $wa = trim((string)ob_get_clean());
            }
            if (!$wa && shortcode_exists('wordads')) {
                $wa = trim(do_shortcode('[wordads]'));
            }
            if ($wa) $ad_parts[] = '<div class="slr-ad-wordads">' . $wa . '</div>';
        }
        if (in_array($ad_type, ['adsense', 'both'], true) && $adsense_client && $adsense_slot) {
            $ad_parts[] = '<div class="slr-ad-adsense">'
                . '<ins class="adsbygoogle" style="display:block" '
                . 'data-ad-client="' . esc_attr($adsense_client) . '" '
                . 'data-ad-slot="' . esc_attr($adsense_slot) . '" '
                . 'data-ad-format="auto" data-full-width-responsive="true"></ins>'
                . '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>'
                . '</div>';
        }
        if ($ad_parts) {
            $ad_html = '<div class="slr-ad-wrap">' . implode('', $ad_parts) . '</div>';
        }
    }
    ?>

<div class="slr-wrap" id="slr-wrap">

    <!-- ── Player bar ─────────────────────────────────────────────────── -->
    <div class="slr-bar">

        <!-- Live dot + title -->
        <div class="slr-live-badge">
            <span class="slr-live-dot" aria-hidden="true"></span>
            <span class="slr-live-text">LIVE RADIO</span>
        </div>

        <!-- Track info -->
        <div class="slr-track-info">
            <span class="slr-track-title" id="slr-title">Loading station…</span>
            <span class="slr-track-source" id="slr-source"></span>
        </div>

        <!-- Controls -->
        <div class="slr-controls">
            <button class="slr-btn" id="slr-prev" aria-label="Previous track" title="Previous">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/></svg>
            </button>
            <button class="slr-btn slr-btn--play" id="slr-play" aria-label="Play / Pause">
                <svg class="slr-icon-play" viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                <svg class="slr-icon-pause" viewBox="0 0 24 24" width="20" height="20" fill="currentColor" style="display:none"><path d="M6 19h4V5H6zm8-14v14h4V5z"/></svg>
            </button>
            <button class="slr-btn" id="slr-next" aria-label="Next track" title="Next">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="m6 18 8.5-6L6 6zm2.5-6L17 6v12z"/><path d="M16 6h2v12h-2z"/></svg>
            </button>
            <button class="slr-btn slr-btn--shuffle" id="slr-shuffle" aria-label="Shuffle" title="Shuffle on">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M10.59 9.17 5.41 4 4 5.41l5.17 5.17zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4zm.33 9.41-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04z"/></svg>
            </button>
        </div>

        <!-- Volume -->
        <div class="slr-vol-wrap">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="opacity:.6"><path d="M3 9v6h4l5 5V4L7 9z"/></svg>
            <input type="range" class="slr-vol" id="slr-vol" min="0" max="1" step="0.05" value="0.8" aria-label="Volume">
        </div>

        <!-- Add music button -->
        <button class="slr-add-btn" id="slr-add-btn" aria-label="Add my music">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/></svg>
            Add my music
        </button>

    </div><!-- .slr-bar -->

    <!-- ── Hidden iframe player ───────────────────────────────────────── -->
    <div class="slr-frame-wrap" id="slr-frame-wrap" aria-hidden="true">
        <iframe id="slr-frame" class="slr-frame"
                allow="autoplay; encrypted-media"
                allowfullscreen
                title="Radio player"
                src="about:blank"></iframe>
    </div>

    <!-- ── Ad slot ────────────────────────────────────────────────────── -->
    <?php if ($ad_html): ?>
    <div class="slr-ad-container">
        <?php echo $ad_html; ?>
    </div>
    <?php endif; ?>

</div><!-- .slr-wrap -->

<!-- ── "Add my music" modal ──────────────────────────────────────────── -->
<div class="slr-modal-overlay" id="slr-modal-overlay" role="dialog" aria-modal="true" aria-label="Add your music" hidden>
    <div class="slr-modal">
        <button class="slr-modal-close" id="slr-modal-close" aria-label="Close">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
        </button>
        <h2 class="slr-modal-title">🎵 Add Your Music</h2>
        <p class="slr-modal-desc">Submit your SoundCloud or YouTube playlist to be featured on our Live Radio station.</p>
        <!-- Deftform embed -->
        <div class="deftform" data-form-id="3152cbda-69ab-4cf7-a4c9-c023edf66d21" data-form-width="100%" data-form-align="center" data-form-auto-height="1"></div>
        <script src="https://cdn.deftform.com/embed.js"></script>
    </div>
</div>

<style>
/* ── Live Radio ────────────────────────────────────────────────────────── */
.slr-wrap{position:relative;background:#0d0d0d;border-bottom:1px solid rgba(198,40,40,.25)}

/* Bar */
.slr-bar{display:flex;align-items:center;gap:12px;padding:10px max(16px,calc((100vw - 1400px)/2 + 16px));flex-wrap:wrap}

/* Live badge */
.slr-live-badge{display:flex;align-items:center;gap:6px;flex-shrink:0}
.slr-live-dot{display:inline-block;width:8px;height:8px;background:#c62828;border-radius:50%;animation:slr-pulse 1.4s ease-in-out infinite}
@keyframes slr-pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8)}}
.slr-live-text{font-family:var(--flavor-font-ui,sans-serif);font-size:.65rem;font-weight:800;letter-spacing:.1em;color:#c62828;white-space:nowrap}

/* Track info */
.slr-track-info{flex:1;min-width:0;overflow:hidden}
.slr-track-title{display:block;font-family:var(--flavor-font-ui,sans-serif);font-size:.82rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3}
.slr-track-source{display:block;font-size:.68rem;color:rgba(255,255,255,.4);margin-top:1px}

/* Controls */
.slr-controls{display:flex;align-items:center;gap:4px;flex-shrink:0}
.slr-btn{background:none;border:none;color:rgba(255,255,255,.75);cursor:pointer;padding:6px;border-radius:50%;transition:background .15s,color .15s;display:flex;align-items:center;justify-content:center;min-width:32px;min-height:32px}
.slr-btn:hover{background:rgba(255,255,255,.1);color:#fff}
.slr-btn--play{background:rgba(198,40,40,.85);color:#fff;width:38px;height:38px;border-radius:50%}
.slr-btn--play:hover{background:#c62828}
.slr-btn--shuffle.is-active{color:#c62828}

/* Volume */
.slr-vol-wrap{display:flex;align-items:center;gap:6px;flex-shrink:0}
.slr-vol{-webkit-appearance:none;appearance:none;width:80px;height:3px;background:rgba(255,255,255,.2);border-radius:2px;outline:none;cursor:pointer}
.slr-vol::-webkit-slider-thumb{-webkit-appearance:none;width:12px;height:12px;border-radius:50%;background:#fff;cursor:pointer}
.slr-vol::-moz-range-thumb{width:12px;height:12px;border-radius:50%;background:#fff;cursor:pointer;border:none}

/* Add music button */
.slr-add-btn{display:flex;align-items:center;gap:5px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.85);border-radius:99px;padding:5px 12px;font-size:.72rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s;letter-spacing:.03em;flex-shrink:0}
.slr-add-btn:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.4)}

/* Hidden iframe — loaded when playing */
.slr-frame-wrap{width:0;height:0;overflow:hidden;position:absolute;pointer-events:none;opacity:0}
.slr-frame{width:1px;height:1px;border:none}

/* Ad container */
.slr-ad-container{padding:8px max(16px,calc((100vw - 1400px)/2 + 16px));background:#111;border-top:1px solid rgba(255,255,255,.06)}
.slr-ad-wrap{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start}
.slr-ad-wordads,.slr-ad-adsense{flex:1;min-width:250px}

/* Modal overlay */
.slr-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.slr-modal-overlay[hidden]{display:none}
.slr-modal{position:relative;background:#1a1a2e;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:32px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto}
.slr-modal-close{position:absolute;top:14px;right:14px;background:rgba(255,255,255,.08);border:none;color:rgba(255,255,255,.7);border-radius:50%;width:36px;height:36px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .15s}
.slr-modal-close:hover{background:rgba(255,255,255,.2);color:#fff}
.slr-modal-title{font-family:var(--flavor-font-headline,Georgia,serif);font-size:1.4rem;font-weight:900;color:#fff;margin:0 0 8px}
.slr-modal-desc{font-size:.88rem;color:rgba(255,255,255,.6);margin:0 0 24px;line-height:1.6}

/* Responsive */
@media(max-width:640px){
    .slr-bar{gap:8px;padding:10px 16px}
    .slr-vol-wrap{display:none}
    .slr-track-info{min-width:80px}
}
</style>

<script>
(function(){
'use strict';

/* ── Config ──────────────────────────────────────────────────────────── */
var AJAX_URL   = <?php echo wp_json_encode($ajax_url); ?>;
var NONCE      = <?php echo wp_json_encode($nonce); ?>;
var PLAYLISTS  = <?php echo $pl_json; ?>;

/* ── State ───────────────────────────────────────────────────────────── */
var allTracks  = [];   // flat shuffled list of all tracks from all playlists
var cur        = -1;   // index into allTracks
var playing    = false;
var shuffle    = true;
var volume     = 0.8;
var loading    = false;
var loadedPlaylists = 0;
var scWidget   = null; // SoundCloud Widget API instance
var ytPlayer   = null; // YouTube IFrame API instance
var currentType = '';  // 'soundcloud'|'youtube'

/* ── DOM refs ─────────────────────────────────────────────────────────── */
var frame     = document.getElementById('slr-frame');
var titleEl   = document.getElementById('slr-title');
var sourceEl  = document.getElementById('slr-source');
var playBtn   = document.getElementById('slr-play');
var prevBtn   = document.getElementById('slr-prev');
var nextBtn   = document.getElementById('slr-next');
var shuffBtn  = document.getElementById('slr-shuffle');
var volEl     = document.getElementById('slr-vol');
var addBtn    = document.getElementById('slr-add-btn');
var overlay   = document.getElementById('slr-modal-overlay');
var closeBtn  = document.getElementById('slr-modal-close');

if (!frame || !playBtn) return; // guard: DOM elements missing

/* ── Fisher-Yates shuffle ──────────────────────────────────────────── */
function shuffleArray(arr) {
    for (var i = arr.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
    }
    return arr;
}

/* ── Fetch all playlists via AJAX ──────────────────────────────────── */
function fetchAllPlaylists() {
    if (!PLAYLISTS.length) {
        titleEl.textContent = 'No playlists configured';
        return;
    }
    titleEl.textContent = 'Loading tracks…';
    loadedPlaylists = 0;

    PLAYLISTS.forEach(function(pl) {
        var fd = new FormData();
        fd.append('action', 'slr_fetch_tracks');
        fd.append('nonce',  NONCE);
        fd.append('type',   pl.type);
        fd.append('url',    pl.url);

        fetch(AJAX_URL, {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.success && Array.isArray(data.data)) {
                data.data.forEach(function(t){ t._playlist_label = pl.label || ''; });
                allTracks = allTracks.concat(data.data);
            }
        })
        .catch(function(){}) // silent — skip failed playlists
        .finally(function() {
            loadedPlaylists++;
            if (loadedPlaylists === PLAYLISTS.length) {
                onAllPlaylistsLoaded();
            }
        });
    });
}

function onAllPlaylistsLoaded() {
    if (!allTracks.length) {
        titleEl.textContent = 'No tracks found';
        return;
    }
    if (shuffle) shuffleArray(allTracks);
    cur = 0;
    titleEl.textContent = 'Ready — press play';
    playBtn.disabled = false;
}

/* ── Playback ──────────────────────────────────────────────────────── */
function playTrack(idx) {
    if (!allTracks.length) return;
    cur = ((idx % allTracks.length) + allTracks.length) % allTracks.length;
    var track = allTracks[cur];

    titleEl.textContent = track.title || 'Unknown track';
    sourceEl.textContent = track._playlist_label || ''; sourceEl.style.display = track._playlist_label ? '' : 'none';

    // Unload previous player
    if (scWidget) { try { scWidget.unbind(window.SC && SC.Widget.Events.FINISH); } catch(e){} scWidget = null; }
    if (ytPlayer) { try { ytPlayer.destroy(); } catch(e){} ytPlayer = null; }

    currentType = track.type;

    if (track.type === 'soundcloud') {
        // Use SC Widget API
        var scSrc = track.embed_url;
        // Append autoplay and hide_related params
        if (scSrc.indexOf('?') === -1) scSrc += '?';
        else scSrc += '&';
        scSrc += 'auto_play=true&hide_related=true&show_comments=false&show_user=true&show_reposts=false&visual=false&buying=false&sharing=false&download=false';
        frame.src = scSrc;
        frame.onload = function() { loadSCWidget(); };
    } else if (track.type === 'youtube') {
        if (track.video_id) {
            // Individual video — use YouTube IFrame API
            loadYTPlayer(track.video_id);
        } else if (track.embed_url) {
            // Playlist embed fallback
            frame.src = track.embed_url;
        }
    }

    setPlaying(true);
}

function setPlaying(val) {
    playing = val;
    playBtn.querySelector('.slr-icon-play').style.display  = val ? 'none' : '';
    playBtn.querySelector('.slr-icon-pause').style.display = val ? ''     : 'none';
}

/* ── SoundCloud Widget API ─────────────────────────────────────────── */
function loadSCWidget() {
    if (!window.SC || !window.SC.Widget) {
        // Load SC Widget API script once
        if (!document.getElementById('sc-widget-api')) {
            var s = document.createElement('script');
            s.id  = 'sc-widget-api';
            s.src = 'https://w.soundcloud.com/player/api.js';
            s.onload = function() { bindSCWidget(); };
            document.head.appendChild(s);
        } else {
            // Script loading — poll until ready
            var poll = setInterval(function(){
                if (window.SC && window.SC.Widget) { clearInterval(poll); bindSCWidget(); }
            }, 200);
        }
    } else {
        bindSCWidget();
    }
}
function bindSCWidget() {
    if (!window.SC) return;
    scWidget = SC.Widget(frame);
    scWidget.bind(SC.Widget.Events.FINISH, function() {
        // Auto-advance to next track
        playTrack(cur + 1);
    });
    scWidget.bind(SC.Widget.Events.PLAY, function() { setPlaying(true); });
    scWidget.bind(SC.Widget.Events.PAUSE, function() { setPlaying(false); });
}

/* ── YouTube IFrame API ────────────────────────────────────────────── */
function loadYTPlayer(videoId) {
    // Put a div in the frame area for YT API
    var container = document.getElementById('slr-yt-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'slr-yt-container';
        container.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;opacity:0;pointer-events:none';
        document.body.appendChild(container);
    }
    container.innerHTML = '<div id="slr-yt-player"></div>';

    function createPlayer() {
        ytPlayer = new YT.Player('slr-yt-player', {
            height: '1', width: '1',
            videoId: videoId,
            playerVars: { autoplay: 1, controls: 0, fs: 0, rel: 0 },
            events: {
                onReady:       function(e) { e.target.setVolume(volume * 100); e.target.playVideo(); },
                onStateChange: function(e) {
                    if (e.data === YT.PlayerState.ENDED) playTrack(cur + 1);
                    if (e.data === YT.PlayerState.PLAYING) setPlaying(true);
                    if (e.data === YT.PlayerState.PAUSED)  setPlaying(false);
                }
            }
        });
    }

    if (window.YT && window.YT.Player) {
        createPlayer();
    } else if (!document.getElementById('yt-iframe-api')) {
        window.onYouTubeIframeAPIReady = createPlayer;
        var s  = document.createElement('script');
        s.id   = 'yt-iframe-api';
        s.src  = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(s);
    } else {
        // Script already loading — wait
        var orig = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function() {
            if (orig) orig();
            createPlayer();
        };
    }
}

/* ── Controls ──────────────────────────────────────────────────────── */
playBtn.addEventListener('click', function() {
    if (!allTracks.length) return;
    if (cur < 0) { playTrack(0); return; }
    if (playing) {
        // Pause
        if (scWidget) scWidget.pause();
        if (ytPlayer && ytPlayer.pauseVideo) ytPlayer.pauseVideo();
        setPlaying(false);
    } else {
        // Resume
        if (scWidget) scWidget.play();
        if (ytPlayer && ytPlayer.playVideo) ytPlayer.playVideo();
        if (!scWidget && !ytPlayer) playTrack(cur); // re-init if lost
        setPlaying(true);
    }
});

nextBtn.addEventListener('click', function() { playTrack(cur + 1); });
prevBtn.addEventListener('click', function() { playTrack(cur - 1); });

shuffBtn.addEventListener('click', function() {
    shuffle = !shuffle;
    shuffBtn.classList.toggle('is-active', shuffle);
    if (shuffle && allTracks.length) shuffleArray(allTracks);
});
shuffBtn.classList.add('is-active'); // on by default

volEl.addEventListener('input', function() {
    volume = parseFloat(this.value);
    if (scWidget) scWidget.setVolume(volume * 100);
    if (ytPlayer && ytPlayer.setVolume) ytPlayer.setVolume(volume * 100);
});

/* ── Modal ─────────────────────────────────────────────────────────── */
addBtn.addEventListener('click', function() {
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    closeBtn.focus();
});
function closeModal() {
    overlay.hidden = true;
    document.body.style.overflow = '';
    addBtn.focus();
}
closeBtn.addEventListener('click', closeModal);
overlay.addEventListener('click', function(e) {
    if (e.target === overlay) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !overlay.hidden) closeModal();
});

/* ── Boot ──────────────────────────────────────────────────────────── */
playBtn.disabled = true;
fetchAllPlaylists();

})();
</script>

    <?php
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. YouTube API Key setting (optional — improves playlist track listing)
// ═══════════════════════════════════════════════════════════════════════════

// Hook into the existing audio-hub settings save to add YT API key field
add_filter('sah_settings_page_extra_fields', function(string $html): string {
    $yt_key = get_option('slr_youtube_api_key', '');
    $html .= '<h2 style="margin:24px 0 14px;font-size:14px;font-weight:700;color:#111">Live Radio — YouTube API</h2>';
    $html .= '<table class="form-table" style="margin:0">';
    $html .= '<tr><th style="padding:8px 0;font-size:13px">YouTube Data API Key</th>';
    $html .= '<td><input type="text" name="slr_youtube_api_key" class="regular-text" value="' . esc_attr($yt_key) . '" placeholder="AIzaSy...">';
    $html .= '<p class="description">Optional. Get a free key from <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a> → YouTube Data API v3. Without it, YouTube playlists embed as a whole rather than individual shuffled tracks.</p></td></tr>';
    $html .= '</table>';
    return $html;
});

// Also save YT API key when the settings form saves
add_action('sah_settings_extra_save', function(): void {
    if (isset($_POST['slr_youtube_api_key'])) {
        update_option('slr_youtube_api_key', sanitize_text_field(wp_unslash($_POST['slr_youtube_api_key'])));
    }
});
