<?php
/**
 * Election Hub — External API Integrations
 * ├── CivicAPI.org          : US election results
 * ├── Google Civic Info v2  : voterInfo (polling locations, contests, officials)
 * ├── Kalshi Prediction API : live market odds (no auth required for market data)
 * └── On-site Reader Poll   : CPT-powered site polls with live results
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════════════════
// 1. SETTINGS — API keys stored in wp_options + customizer
// ═══════════════════════════════════════════════════════════════════════════

add_action('customize_register', function(WP_Customize_Manager $wpc): void {
    // Section already added by election-hub.php; just add settings here
    $s = 'sev_settings';
    foreach([
        'sev_google_civic_key'  => 'Google Civic Info API Key',
        'sev_civicapi_key'      => 'CivicAPI.org API Key (optional)',
        'sev_kalshi_tag'        => 'Kalshi Tag Filter (e.g. elections)',
    ] as $id => $label) {
        $wpc->add_setting($id, ['default'=>'','sanitize_callback'=>'sanitize_text_field','transport'=>'refresh']);
        $wpc->add_control($id,  ['label'=>$label,'section'=>$s,'type'=>'text']);
    }
});

function sev_google_key(): string {
    return get_theme_mod('sev_google_civic_key','') ?: get_option('sev_google_civic_key','');
}
function sev_civicapi_key(): string {
    return get_theme_mod('sev_civicapi_key','') ?: get_option('sev_civicapi_key','');
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. CIVICAPI.ORG — US Election Results
//    Base: https://civicapi.org/api/results
// ═══════════════════════════════════════════════════════════════════════════

function sev_civicapi_fetch(array $params = []): array {
    $key = sev_civicapi_key();
    $base_url = 'https://civicapi.org/api/results';
    $query = array_filter(array_merge(['api_key' => $key], $params));
    $url   = add_query_arg($query, $base_url);

    $cache_key = 'sev_civicapi_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $r = wp_remote_get($url, ['timeout' => 15, 'sslverify' => true]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
        return ['error' => is_wp_error($r) ? $r->get_error_message() : 'CivicAPI HTTP ' . wp_remote_retrieve_response_code($r)];
    }

    $data = json_decode(wp_remote_retrieve_body($r), true) ?: [];
    set_transient($cache_key, $data, 90); // 90s cache for live results
    return $data;
}

// AJAX: fetch CivicAPI results for a race (admin + frontend)
add_action('wp_ajax_sev_civicapi_race',        'sev_ajax_civicapi_race');
add_action('wp_ajax_nopriv_sev_civicapi_race', 'sev_ajax_civicapi_race');
function sev_ajax_civicapi_race(): void {
    check_ajax_referer('sev_public_nonce', 'nonce');
    $office = sanitize_text_field($_POST['office'] ?? '');
    $state  = sanitize_text_field($_POST['state']  ?? '');
    $year   = absint($_POST['year'] ?? date('Y'));

    $data = sev_civicapi_fetch(array_filter([
        'office'     => $office,
        'state'      => $state,
        'year'       => $year,
        'limit'      => 20,
    ]));

    if (!empty($data['error'])) {
        wp_send_json_error($data['error']);
    }
    wp_send_json_success($data);
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. GOOGLE CIVIC INFORMATION API v2
//    Available endpoints: elections (list), voterInfoQuery (by address)
//    Note: Representatives API was shut down April 30, 2025
// ═══════════════════════════════════════════════════════════════════════════

defined( 'SEV_GOOGLE_BASE' ) || define( 'SEV_GOOGLE_BASE', 'https://www.googleapis.com/civicinfo/v2' );

function sev_google_elections_list(): array {
    $key = sev_google_key();
    if (!$key) return ['error' => 'Google Civic API key not configured'];

    $cached = get_transient('sev_google_elections');
    if ($cached !== false) return $cached;

    $r = wp_remote_get(SEV_GOOGLE_BASE . '/elections?key=' . urlencode($key), ['timeout' => 10]);
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
        return ['error' => 'Google Civic API error: ' . wp_remote_retrieve_response_code($r)];
    }

    $data = json_decode(wp_remote_retrieve_body($r), true) ?: [];
    set_transient('sev_google_elections', $data, 3600);
    return $data;
}

function sev_google_voter_info(string $address, string $election_id = ''): array {
    $key = sev_google_key();
    if (!$key) return ['error' => 'Google Civic API key not configured'];

    $params = ['key' => $key, 'address' => $address];
    if ($election_id) $params['electionId'] = $election_id;

    $url       = SEV_GOOGLE_BASE . '/voterinfo?' . http_build_query($params);
    $cache_key = 'sev_gvoter_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $r = wp_remote_get($url, ['timeout' => 12]);
    if (is_wp_error($r)) return ['error' => $r->get_error_message()];
    if (wp_remote_retrieve_response_code($r) !== 200) {
        $body = json_decode(wp_remote_retrieve_body($r), true);
        return ['error' => $body['error']['message'] ?? 'Google Civic API error'];
    }

    $data = json_decode(wp_remote_retrieve_body($r), true) ?: [];
    set_transient($cache_key, $data, 1800); // 30 min cache
    return $data;
}

// Public AJAX: voter info lookup by address
add_action('wp_ajax_sev_voter_info',        'sev_ajax_voter_info');
add_action('wp_ajax_nopriv_sev_voter_info', 'sev_ajax_voter_info');
function sev_ajax_voter_info(): void {
    check_ajax_referer('sev_public_nonce', 'nonce');
    $address     = sanitize_text_field(wp_unslash($_POST['address'] ?? ''));
    $election_id = sanitize_text_field($_POST['election_id'] ?? '');

    if (!$address) { wp_send_json_error('Address required'); }

    $data = sev_google_voter_info($address, $election_id);
    if (!empty($data['error'])) { wp_send_json_error($data['error']); }
    wp_send_json_success($data);
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. KALSHI PREDICTION MARKETS — Public, no auth required
//    Base: https://api.elections.kalshi.com/trade-api/v2
// ═══════════════════════════════════════════════════════════════════════════

defined( 'SEV_KALSHI_BASE' ) || define( 'SEV_KALSHI_BASE', 'https://api.elections.kalshi.com/trade-api/v2' );

function sev_kalshi_fetch(string $endpoint, array $params = []): array {
    $url       = SEV_KALSHI_BASE . $endpoint . ($params ? '?' . http_build_query($params) : '');
    $cache_key = 'sev_kalshi_' . md5($url);
    $cached    = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $r = wp_remote_get($url, [
        'timeout' => 12,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($r)) {
        return ['error' => 'Kalshi connection error: ' . $r->get_error_message()];
    }
    if (wp_remote_retrieve_response_code($r) !== 200) {
        return ['error' => 'Kalshi API error: HTTP ' . wp_remote_retrieve_response_code($r)];
    }

    $data = json_decode(wp_remote_retrieve_body($r), true) ?: [];
    set_transient($cache_key, $data, 60); // 60s cache for live odds
    return $data;
}

function sev_kalshi_election_markets(string $tag = 'elections', int $limit = 10): array {
    return sev_kalshi_fetch('/markets', [
        'status' => 'open',
        'limit'  => $limit,
        'tag'    => $tag,
    ]);
}

function sev_kalshi_market(string $ticker): array {
    return sev_kalshi_fetch('/markets/' . urlencode($ticker));
}

function sev_kalshi_event(string $event_ticker): array {
    return sev_kalshi_fetch('/events/' . urlencode($event_ticker));
}

// Public AJAX: fetch Kalshi markets
add_action('wp_ajax_sev_kalshi_markets',        'sev_ajax_kalshi_markets');
add_action('wp_ajax_nopriv_sev_kalshi_markets', 'sev_ajax_kalshi_markets');
function sev_ajax_kalshi_markets(): void {
    check_ajax_referer('sev_public_nonce', 'nonce');
    $tag    = sanitize_text_field($_POST['tag']    ?? get_theme_mod('sev_kalshi_tag','elections'));
    $ticker = sanitize_text_field($_POST['ticker'] ?? '');

    if ($ticker) {
        $data = sev_kalshi_market($ticker);
    } else {
        $data = sev_kalshi_election_markets($tag, 12);
    }

    if (!empty($data['error'])) { wp_send_json_error($data['error']); }
    wp_send_json_success($data);
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. SITE POLL CPT — Reader polls on any topic
// ═══════════════════════════════════════════════════════════════════════════

add_action('init', function(): void {
    register_post_type('serve_poll', [
        'labels'       => [
            'name'          => 'Site Polls',
            'singular_name' => 'Poll',
            'add_new_item'  => 'Add New Poll',
            'menu_name'     => 'Polls',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_rest' => true,
        'supports'     => ['title', 'custom-fields'],
        'show_in_menu' => 'edit.php?post_type=serve_election',
        'menu_icon'    => 'dashicons-chart-pie',
    ]);
});

add_action('add_meta_boxes', function(): void {
    add_meta_box('sev-poll-options', '📊 Poll Options & Results',
        'sev_render_poll_metabox', 'serve_poll', 'normal', 'high');
});

function sev_render_poll_metabox(WP_Post $post): void {
    wp_nonce_field('sev_save_poll', 'sev_poll_nonce');
    $question  = get_post_meta($post->ID, '_poll_question', true) ?: get_the_title($post);
    $options   = json_decode(get_post_meta($post->ID, '_poll_options', true) ?: '[]', true);
    $allow_multi = get_post_meta($post->ID, '_poll_multi', true);
    $closes    = get_post_meta($post->ID, '_poll_closes', true);
    $total     = array_sum(array_column($options, 'votes'));
    ?>
    <style>
    .sev-poll-opt{display:grid;grid-template-columns:1fr 80px 80px auto;gap:8px;align-items:center;margin-bottom:6px;padding:6px;background:#f9f9f9;border:1px solid #e5e5e5;border-radius:4px}
    </style>
    <table style="width:100%;border-collapse:collapse;margin-bottom:12px">
        <tr><td style="width:120px;padding:4px 0;font-size:12px;font-weight:700;color:#555">Question</td>
            <td><input type="text" name="poll_question" value="<?php echo esc_attr($question); ?>" style="width:100%"></td></tr>
        <tr><td style="padding:4px 0;font-size:12px;font-weight:700;color:#555">Closes</td>
            <td><input type="datetime-local" name="poll_closes" value="<?php echo esc_attr($closes); ?>"></td></tr>
        <tr><td style="padding:4px 0;font-size:12px;font-weight:700;color:#555">Multi-select</td>
            <td><label><input type="checkbox" name="poll_multi" value="1" <?php checked($allow_multi, '1'); ?>> Allow multiple choices</label></td></tr>
    </table>
    <p style="font-size:12px;font-weight:700;color:#555;margin:0 0 6px">Options (label + vote count):</p>
    <div id="sev-poll-opts">
        <?php foreach ($options as $i => $opt): ?>
        <div class="sev-poll-opt">
            <input type="text" name="poll_opt_label[]" value="<?php echo esc_attr($opt['label']??''); ?>" placeholder="Option label">
            <input type="number" name="poll_opt_votes[]" value="<?php echo esc_attr($opt['votes']??0); ?>" min="0" placeholder="Votes">
            <span style="font-size:12px;color:#888"><?php echo $total > 0 ? round(($opt['votes']??0)/$total*100,1) . '%' : '0%'; ?></span>
            <button type="button" class="button-link sev-rm-opt" style="color:#d63638">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="button" id="sev-add-opt" style="margin-top:8px">+ Add Option</button>
    <p style="font-size:12px;color:#888;margin-top:8px">Total votes: <strong><?php echo number_format($total); ?></strong></p>
    <input type="hidden" name="poll_options_json" id="sev-poll-json">
    <p style="font-size:11px;color:#888;margin-top:12px">Shortcode: <code>[site_poll id="<?php echo $post->ID; ?>"]</code></p>
    <script>
    (function(){
        document.getElementById('sev-add-opt').onclick = function(){
            var d = document.createElement('div');
            d.className = 'sev-poll-opt';
            d.innerHTML = '<input type="text" name="poll_opt_label[]" placeholder="Option label" style="width:100%">'
                        + '<input type="number" name="poll_opt_votes[]" value="0" min="0" placeholder="Votes">'
                        + '<span style="font-size:12px;color:#888">0%</span>'
                        + '<button type="button" class="button-link sev-rm-opt" style="color:#d63638">✕</button>';
            document.getElementById('sev-poll-opts').appendChild(d);
        };
        document.addEventListener('click', function(e){
            if (e.target.classList.contains('sev-rm-opt')) e.target.closest('.sev-poll-opt').remove();
        });
        document.querySelector('form#post').addEventListener('submit', function(){
            var opts = [];
            document.querySelectorAll('.sev-poll-opt').forEach(function(row,i){
                var label = row.querySelector('[name="poll_opt_label[]"]').value;
                var votes = parseInt(row.querySelector('[name="poll_opt_votes[]"]').value||0,10);
                if (label) opts.push({id:'o'+i, label:label, votes:votes});
            });
            document.getElementById('sev-poll-json').value = JSON.stringify(opts);
        });
    })();
    </script>
    <?php
}

add_action('save_post_serve_poll', function(int $id, WP_Post $post): void {
    if (wp_is_post_revision($id) || !isset($_POST['sev_poll_nonce'])) return;
    if (!wp_verify_nonce(sanitize_key($_POST['sev_poll_nonce']), 'sev_save_poll')) return;
    if (!current_user_can('edit_post', $id)) return;

    if (isset($_POST['poll_question']))
        update_post_meta($id, '_poll_question', sanitize_text_field(wp_unslash($_POST['poll_question'])));
    if (isset($_POST['poll_closes']))
        update_post_meta($id, '_poll_closes', sanitize_text_field(wp_unslash($_POST['poll_closes'])));
    update_post_meta($id, '_poll_multi', isset($_POST['poll_multi']) ? '1' : '0');
    if (isset($_POST['poll_options_json'])) {
        $decoded = json_decode(wp_unslash($_POST['poll_options_json']), true);
        if (is_array($decoded)) update_post_meta($id, '_poll_options', wp_json_encode($decoded));
    }
}, 10, 2);

// Poll vote AJAX
add_action('wp_ajax_sev_poll_vote',        'sev_ajax_poll_vote');
add_action('wp_ajax_nopriv_sev_poll_vote', 'sev_ajax_poll_vote');
function sev_ajax_poll_vote(): void {
    check_ajax_referer('sev_public_nonce', 'nonce');

    // PFS-FIX: rate-limit this public endpoint (bugfixes.php only covers
    // a global RPM; per-bucket limit prevents ballot stuffing).
    if ( class_exists( '\\Apollo\\Serve\\Security\\RateLimiter' ) ) {
        if ( ! \Apollo\Serve\Security\RateLimiter::hit( 'poll_vote', 10, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limited', 'message' => 'Too many requests.' ], 429 );
        }
    }

    $poll_id  = absint($_POST['poll_id'] ?? 0);
    $option   = sanitize_text_field( wp_unslash( $_POST['option_id'] ?? '' ) );
    // PFS-FIX: hard length cap to prevent oversize keys.
    if ( strlen( $option ) > 64 ) { $option = substr( $option, 0, 64 ); }

    if (!$poll_id || !$option) wp_send_json_error('Invalid params');

    // PFS-FIX: validate this is actually a poll post, not an arbitrary ID.
    if ( get_post_type( $poll_id ) !== 'serve_poll' ) {
        wp_send_json_error( 'Invalid poll' );
    }

    // Check cookie — one vote per browser per poll
    $cookie_key = 'sev_poll_' . $poll_id;
    if (!empty($_COOKIE[$cookie_key])) {
        // Already voted — just return current results
        $raw = get_post_meta($poll_id, '_poll_options', true) ?: '[]';
        $options = is_string( $raw ) ? ( json_decode( $raw, true ) ?: [] ) : [];
        wp_send_json_success(['results' => $options, 'already_voted' => true]);
    }

    // Record vote
    $raw = get_post_meta($poll_id, '_poll_options', true) ?: '[]';
    $options = is_string( $raw ) ? ( json_decode( $raw, true ) ?: [] ) : [];
    if ( ! is_array( $options ) ) { $options = []; }
    $found = false;
    foreach ($options as &$opt) {
        if ( is_array( $opt ) && ($opt['id'] ?? '') === $option) {
            $opt['votes'] = (int) ($opt['votes'] ?? 0) + 1;
            $found = true;
            break;
        }
    }
    unset($opt);
    // PFS-FIX: don't silently accept votes for unknown option ids.
    if ( ! $found ) {
        wp_send_json_error( 'Unknown option' );
    }
    update_post_meta($poll_id, '_poll_options', wp_json_encode($options));

    setcookie($cookie_key, '1', time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
    wp_send_json_success(['results' => $options, 'already_voted' => false]);
}

// Poll results AJAX
add_action('wp_ajax_sev_poll_results',        'sev_ajax_poll_results');
add_action('wp_ajax_nopriv_sev_poll_results', 'sev_ajax_poll_results');
function sev_ajax_poll_results(): void {
    check_ajax_referer('sev_public_nonce', 'nonce');
    $poll_id = absint($_POST['poll_id'] ?? 0);
    if (!$poll_id) wp_send_json_error('Invalid poll ID');
    $options = json_decode(get_post_meta($poll_id, '_poll_options', true) ?: '[]', true);
    wp_send_json_success(['results' => $options]);
}

// ── Poll shortcode ─────────────────────────────────────────────────────────
add_shortcode('site_poll', function(array $atts): string {
    $atts = shortcode_atts(['id' => 0, 'show_results' => 'after_vote'], $atts);
    $id   = absint($atts['id']);
    if (!$id) return '';

    $post = get_post($id);
    if (!$post || $post->post_type !== 'serve_poll') return '';

    $question    = get_post_meta($id, '_poll_question', true) ?: get_the_title($id);
    $options     = json_decode(get_post_meta($id, '_poll_options', true) ?: '[]', true);
    $allow_multi = get_post_meta($id, '_poll_multi', true) === '1';
    $closes      = get_post_meta($id, '_poll_closes', true);
    $closed      = $closes && strtotime($closes) < time();
    $voted       = !empty($_COOKIE['sev_poll_' . $id]);
    $total       = array_sum(array_column($options, 'votes'));
    $nonce       = wp_create_nonce('sev_public_nonce');
    $ajax_url    = admin_url('admin-ajax.php');

    ob_start(); ?>
    <div class="sev-poll" id="sev-poll-<?php echo $id; ?>" data-poll-id="<?php echo $id; ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>" data-ajax="<?php echo esc_url($ajax_url); ?>">
        <div class="sev-poll__header">
            <span class="sev-poll__icon">📊</span>
            <h3 class="sev-poll__question"><?php echo esc_html($question); ?></h3>
            <?php if ($closed): ?><span class="sev-poll__closed-badge">CLOSED</span><?php endif; ?>
        </div>

        <?php if (!$voted && !$closed): ?>
        <div class="sev-poll__options" id="sev-poll-opts-<?php echo $id; ?>">
            <?php foreach ($options as $opt): ?>
            <button type="button" class="sev-poll__opt-btn" data-opt="<?php echo esc_attr($opt['id']??''); ?>">
                <?php echo esc_html($opt['label']??''); ?>
            </button>
            <?php endforeach; ?>
            <?php if ($allow_multi): ?>
            <button type="button" class="sev-poll__submit-btn" id="sev-poll-submit-<?php echo $id; ?>">Submit Vote</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="sev-poll__results" id="sev-poll-results-<?php echo $id; ?>"
             style="<?php echo ($voted || $closed) ? '' : 'display:none'; ?>">
            <?php foreach ($options as $opt):
                $pct = $total > 0 ? round(($opt['votes']??0)/$total*100,1) : 0;
                $bar = min(100, $pct);
            ?>
            <div class="sev-poll__result-row">
                <div class="sev-poll__result-label"><?php echo esc_html($opt['label']??''); ?></div>
                <div class="sev-poll__result-bar-wrap">
                    <div class="sev-poll__result-bar" style="width:<?php echo $bar; ?>%"></div>
                </div>
                <div class="sev-poll__result-pct"><?php echo $pct; ?>%</div>
            </div>
            <?php endforeach; ?>
            <div class="sev-poll__total"><?php echo number_format($total); ?> votes<?php if ($closes): ?> · closes <?php echo date('M j, Y g:i a', strtotime($closes)); endif; ?></div>
        </div>
    </div>
    <style>
    .sev-poll{border:1px solid #e5e7eb;border-radius:12px;padding:1.25rem;margin:1.5rem 0;background:#fff;font-family:var(--flavor-font-ui,system-ui,sans-serif);max-width:560px}
    .sev-poll__header{display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem}
    .sev-poll__icon{font-size:1.3rem;flex-shrink:0;margin-top:1px}
    .sev-poll__question{font-family:var(--flavor-font-headline,Georgia,serif);font-size:1.05rem;font-weight:800;margin:0;line-height:1.3;color:var(--flavor-text,#111);flex:1}
    .sev-poll__closed-badge{flex-shrink:0;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;background:#f3f4f6;color:#888;padding:2px 8px;border-radius:99px;align-self:center}
    .sev-poll__options{display:flex;flex-direction:column;gap:.5rem}
    .sev-poll__opt-btn{text-align:left;padding:.65rem 1rem;border:2px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-size:.9rem;font-weight:600;color:var(--flavor-text,#111);transition:all .15s;font-family:inherit}
    .sev-poll__opt-btn:hover{border-color:var(--flavor-accent,#c62828);background:#fff8f8}
    .sev-poll__opt-btn.is-selected{border-color:var(--flavor-accent,#c62828);background:#fff8f8;color:var(--flavor-accent,#c62828)}
    .sev-poll__submit-btn{margin-top:.35rem;padding:.6rem 1.25rem;background:var(--flavor-accent,#c62828);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;align-self:flex-start;font-family:inherit;transition:opacity .15s}
    .sev-poll__submit-btn:hover{opacity:.85}
    .sev-poll__result-row{display:grid;grid-template-columns:1fr auto;gap:.5rem;align-items:center;margin-bottom:.6rem}
    .sev-poll__result-label{font-size:.85rem;font-weight:600;color:var(--flavor-text,#111)}
    .sev-poll__result-bar-wrap{grid-column:1/-1;height:8px;background:#f3f4f6;border-radius:4px;overflow:hidden;margin-top:-4px}
    .sev-poll__result-bar{height:100%;background:var(--flavor-accent,#c62828);border-radius:4px;transition:width .5s ease}
    .sev-poll__result-pct{font-size:.82rem;font-weight:800;color:var(--flavor-accent,#c62828);white-space:nowrap}
    .sev-poll__total{font-size:.7rem;color:#aaa;margin-top:.75rem;text-align:right}
    </style>
    <script>
    (function(){
        var wrap = document.getElementById('sev-poll-<?php echo $id; ?>');
        if (!wrap) return;
        var opts    = wrap.querySelectorAll('.sev-poll__opt-btn');
        var results = document.getElementById('sev-poll-results-<?php echo $id; ?>');
        var multi   = <?php echo $allow_multi ? 'true' : 'false'; ?>;
        var selected = [];
        var nonce   = wrap.dataset.nonce;
        var ajax    = wrap.dataset.ajax;
        var pollId  = wrap.dataset.pollId;

        function vote(optId) {
            var fd = new FormData();
            fd.append('action',    'sev_poll_vote');
            fd.append('nonce',     nonce);
            fd.append('poll_id',   pollId);
            fd.append('option_id', optId);
            fetch(ajax, {method:'POST',body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.success) showResults(d.data.results);
            });
        }

        function showResults(data) {
            var total = data.reduce(function(s,o){ return s+(o.votes||0); },0);
            var rows  = results.querySelectorAll('.sev-poll__result-row');
            data.forEach(function(o,i){
                if (!rows[i]) return;
                var pct = total > 0 ? Math.round((o.votes||0)/total*1000)/10 : 0;
                var bar = rows[i].querySelector('.sev-poll__result-bar');
                var pctEl = rows[i].querySelector('.sev-poll__result-pct');
                if (bar) bar.style.width = Math.min(100,pct)+'%';
                if (pctEl) pctEl.textContent = pct+'%';
            });
            var tot = results.querySelector('.sev-poll__total');
            if (tot) tot.textContent = total.toLocaleString() + ' votes';
            results.style.display = 'block';
            var optsWrap = document.getElementById('sev-poll-opts-'+pollId);
            if (optsWrap) optsWrap.style.display = 'none';
        }

        opts.forEach(function(btn){
            btn.addEventListener('click', function(){
                if (!multi) {
                    opts.forEach(function(b){ b.classList.remove('is-selected'); });
                    btn.classList.add('is-selected');
                    vote(btn.dataset.opt);
                } else {
                    btn.classList.toggle('is-selected');
                    var id = btn.dataset.opt;
                    var idx = selected.indexOf(id);
                    if (idx > -1) selected.splice(idx,1); else selected.push(id);
                }
            });
        });

        var submitBtn = document.getElementById('sev-poll-submit-'+pollId);
        if (submitBtn) submitBtn.addEventListener('click', function(){
            if (selected.length) vote(selected[0]);
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. KALSHI ODDS WIDGET SHORTCODE
//    [kalshi_odds tag="elections" limit="6"]
//    [kalshi_odds ticker="TRUMPAPPROVE"]
// ═══════════════════════════════════════════════════════════════════════════

add_shortcode('kalshi_odds', function(array $atts): string {
    $atts = shortcode_atts([
        'tag'    => get_theme_mod('sev_kalshi_tag', 'elections'),
        'ticker' => '',
        'limit'  => 6,
        'title'  => 'Live Prediction Markets',
    ], $atts);

    $nonce    = wp_create_nonce('sev_public_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $id       = 'kalshi-' . wp_rand(1000, 9999);

    ob_start(); ?>
    <div class="sev-kalshi" id="<?php echo esc_attr($id); ?>"
         data-tag="<?php echo esc_attr($atts['tag']); ?>"
         data-ticker="<?php echo esc_attr($atts['ticker']); ?>"
         data-limit="<?php echo absint($atts['limit']); ?>"
         data-nonce="<?php echo esc_attr($nonce); ?>"
         data-ajax="<?php echo esc_url($ajax_url); ?>">
        <div class="sev-kalshi__header">
            <span class="sev-kalshi__logo">📈</span>
            <span class="sev-kalshi__title"><?php echo esc_html($atts['title']); ?></span>
            <span class="sev-kalshi__source">Live odds · <a href="https://kalshi.com" target="_blank" rel="noopener" style="color:inherit">Kalshi</a></span>
            <span class="sev-kalshi__live"><span class="sev-live-dot" style="--dot-color:#059669"></span>LIVE</span>
        </div>
        <div class="sev-kalshi__grid" id="<?php echo esc_attr($id); ?>-grid">
            <div class="sev-kalshi__loading">⏳ Loading live odds…</div>
        </div>
    </div>
    <style>
    .sev-kalshi{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin:1.5rem 0;background:#fff;font-family:var(--flavor-font-ui,system-ui,sans-serif)}
    .sev-kalshi__header{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;background:#f9fafb;border-bottom:1px solid #e5e7eb;font-size:.78rem;flex-wrap:wrap}
    .sev-kalshi__logo{font-size:1.1rem}
    .sev-kalshi__title{font-weight:800;color:var(--flavor-text,#111);font-size:.88rem}
    .sev-kalshi__source{margin-left:auto;color:#aaa;font-size:.68rem}
    .sev-kalshi__source a{color:#aaa;text-decoration:none}
    .sev-kalshi__live{display:inline-flex;align-items:center;gap:4px;font-size:.65rem;font-weight:800;color:#059669}
    .sev-kalshi__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0}
    .sev-kalshi__card{padding:1rem;border-right:1px solid #f3f4f6;border-bottom:1px solid #f3f4f6}
    .sev-kalshi__card:last-child{border-right:none}
    .sev-kalshi__card-q{font-size:.8rem;font-weight:600;color:var(--flavor-text,#111);line-height:1.35;margin-bottom:.75rem}
    .sev-kalshi__odds-row{display:flex;gap:.5rem}
    .sev-kalshi__odds-box{flex:1;text-align:center;padding:.5rem .25rem;border-radius:8px;border:1.5px solid}
    .sev-kalshi__odds-box--yes{border-color:#bbf7d0;background:#f0fdf4;color:#16a34a}
    .sev-kalshi__odds-box--no{border-color:#fce7f3;background:#fdf4ff;color:#9333ea}
    .sev-kalshi__odds-pct{font-size:1.4rem;font-weight:900;display:block;line-height:1}
    .sev-kalshi__odds-label{font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;opacity:.8}
    .sev-kalshi__loading{padding:2rem;text-align:center;color:#888;font-size:.85rem;grid-column:1/-1}
    .sev-kalshi__vol{font-size:.62rem;color:#aaa;text-align:right;margin-top:.5rem}
    </style>
    <script>
    (function(){
        var wrap = document.getElementById('<?php echo esc_js($id); ?>');
        if (!wrap) return;
        var grid   = document.getElementById('<?php echo esc_js($id); ?>-grid');
        var tag    = wrap.dataset.tag;
        var ticker = wrap.dataset.ticker;
        var limit  = parseInt(wrap.dataset.limit||6,10);
        var nonce  = wrap.dataset.nonce;
        var ajax   = wrap.dataset.ajax;

        function fmt(val) {
            // Kalshi yes_bid is in cents (0-100)
            if (typeof val === 'number') return Math.round(val) + '%';
            return val || '—';
        }

        function renderCard(m) {
            var yes = Math.round((m.yes_bid || m.last_price || 50));
            var no  = 100 - yes;
            var vol = m.volume ? (m.volume > 1000000 ? (m.volume/1000000).toFixed(1)+'M' : (m.volume/1000).toFixed(0)+'K') : '';
            return '<div class="sev-kalshi__card">'
                 +   '<div class="sev-kalshi__card-q">' + (m.title||m.ticker||'') + '</div>'
                 +   '<div class="sev-kalshi__odds-row">'
                 +     '<div class="sev-kalshi__odds-box sev-kalshi__odds-box--yes">'
                 +       '<span class="sev-kalshi__odds-pct">' + yes + '%</span>'
                 +       '<span class="sev-kalshi__odds-label">YES</span>'
                 +     '</div>'
                 +     '<div class="sev-kalshi__odds-box sev-kalshi__odds-box--no">'
                 +       '<span class="sev-kalshi__odds-pct">' + no + '%</span>'
                 +       '<span class="sev-kalshi__odds-label">NO</span>'
                 +     '</div>'
                 +   '</div>'
                 +   (vol ? '<div class="sev-kalshi__vol">Vol: ' + vol + '</div>' : '')
                 + '</div>';
        }

        function load() {
            var fd = new FormData();
            fd.append('action', 'sev_kalshi_markets');
            fd.append('nonce',  nonce);
            if (ticker) fd.append('ticker', ticker);
            else        fd.append('tag', tag);

            fetch(ajax, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d.success) { grid.innerHTML = '<div class="sev-kalshi__loading">Unable to load odds.</div>'; return; }
                var markets = d.data.markets || (d.data.market ? [d.data.market] : []);
                markets = markets.slice(0, limit);
                if (!markets.length) { grid.innerHTML = '<div class="sev-kalshi__loading">No markets found.</div>'; return; }
                grid.innerHTML = markets.map(renderCard).join('');
            })
            .catch(function(){ grid.innerHTML = '<div class="sev-kalshi__loading">Network error.</div>'; });
        }

        load();
        setInterval(load, 60000); // refresh every 60s
    })();
    </script>
    <?php
    return ob_get_clean();
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. VOTER INFO LOOKUP SHORTCODE
//    [voter_info title="Find Your Polling Place"]
// ═══════════════════════════════════════════════════════════════════════════

add_shortcode('voter_info', function(array $atts): string {
    $atts  = shortcode_atts(['title' => 'Find Your Polling Place & Ballot Info'], $atts);
    $nonce = wp_create_nonce('sev_public_nonce');
    $ajax  = admin_url('admin-ajax.php');
    $key   = sev_google_key();
    $id    = 'vi-' . wp_rand(1000, 9999);

    if (!$key) {
        return '<p style="color:#888;font-size:.85rem;border:1px dashed #e5e7eb;padding:1rem;border-radius:8px">⚙ Add your Google Civic Info API key in Customize → Election Hub to enable voter information lookup.</p>';
    }

    ob_start(); ?>
    <div class="sev-voter" id="<?php echo esc_attr($id); ?>">
        <h3 class="sev-voter__title">🗳 <?php echo esc_html($atts['title']); ?></h3>
        <div class="sev-voter__form">
            <input type="text" id="<?php echo esc_attr($id); ?>-addr" class="sev-voter__input"
                   placeholder="Enter your registered address…" autocomplete="street-address">
            <button type="button" class="sev-voter__btn" id="<?php echo esc_attr($id); ?>-btn">Look Up</button>
        </div>
        <div id="<?php echo esc_attr($id); ?>-result" class="sev-voter__result" style="display:none"></div>
    </div>
    <style>
    .sev-voter{border:1px solid #e5e7eb;border-radius:12px;padding:1.25rem;margin:1.5rem 0;background:#fff;font-family:var(--flavor-font-ui,system-ui,sans-serif);max-width:640px}
    .sev-voter__title{font-family:var(--flavor-font-headline,Georgia,serif);font-size:1.1rem;font-weight:800;margin:0 0 1rem;color:var(--flavor-text,#111)}
    .sev-voter__form{display:flex;gap:.5rem;flex-wrap:wrap}
    .sev-voter__input{flex:1;min-width:240px;padding:.65rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.9rem;font-family:inherit;outline:none;transition:border-color .15s}
    .sev-voter__input:focus{border-color:var(--flavor-accent,#c62828)}
    .sev-voter__btn{padding:.65rem 1.25rem;background:var(--flavor-accent,#c62828);color:#fff;border:none;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;transition:opacity .15s}
    .sev-voter__btn:hover{opacity:.85}
    .sev-voter__result{margin-top:1rem}
    .sev-voter__section{margin-bottom:1rem}
    .sev-voter__section-title{font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:0 0 .5rem;padding-bottom:.35rem;border-bottom:1px solid #f3f4f6}
    .sev-voter__loc{padding:.6rem .75rem;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:.4rem;font-size:.82rem;line-height:1.4}
    .sev-voter__loc-name{font-weight:700;color:var(--flavor-text,#111)}
    .sev-voter__loc-addr{color:#888;margin-top:2px}
    .sev-voter__loc-hours{color:#059669;font-size:.75rem;margin-top:2px}
    .sev-voter__contest{padding:.5rem .75rem;border-left:3px solid var(--flavor-accent,#c62828);margin-bottom:.4rem;font-size:.82rem}
    .sev-voter__contest-office{font-weight:700;color:var(--flavor-text,#111)}
    .sev-voter__contest-cands{color:#555;font-size:.78rem;margin-top:3px}
    </style>
    <script>
    (function(){
        var btn   = document.getElementById('<?php echo esc_js($id); ?>-btn');
        var input = document.getElementById('<?php echo esc_js($id); ?>-addr');
        var result= document.getElementById('<?php echo esc_js($id); ?>-result');
        var nonce = '<?php echo esc_js($nonce); ?>';
        var ajax  = '<?php echo esc_js($ajax); ?>';

        function addrLine(a) {
            if (!a) return '';
            return [a.locationName, a.line1, a.line2, a.city, a.state, a.zip].filter(Boolean).join(', ');
        }

        btn.addEventListener('click', function(){
            var addr = input.value.trim();
            if (!addr) { input.focus(); return; }
            btn.disabled = true; btn.textContent = '⏳ Looking up…';
            result.style.display = 'none';

            var fd = new FormData();
            fd.append('action',  'sev_voter_info');
            fd.append('nonce',   nonce);
            fd.append('address', addr);

            fetch(ajax, {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(d){
                btn.disabled = false; btn.textContent = 'Look Up';
                result.style.display = 'block';
                if (!d.success) { result.innerHTML = '<p style="color:#d63638">'+d.data+'</p>'; return; }
                var data = d.data;
                var html = '';

                // Election info
                if (data.election) {
                    html += '<div class="sev-voter__section"><div class="sev-voter__section-title">Election</div>'
                          + '<div class="sev-voter__loc"><span class="sev-voter__loc-name">'+(data.election.name||'')+'</span>'
                          + '<div class="sev-voter__loc-addr">'+( data.election.electionDay ? 'Election Day: '+data.election.electionDay : '')+'</div></div></div>';
                }

                // Polling locations
                if (data.pollingLocations && data.pollingLocations.length) {
                    html += '<div class="sev-voter__section"><div class="sev-voter__section-title">Polling Locations</div>';
                    data.pollingLocations.slice(0,3).forEach(function(loc){
                        html += '<div class="sev-voter__loc">'
                              + '<div class="sev-voter__loc-name">'+(loc.address ? (loc.address.locationName||addrLine(loc.address)) : '')+'</div>'
                              + '<div class="sev-voter__loc-addr">'+( loc.address ? addrLine({line1:loc.address.line1,city:loc.address.city,state:loc.address.state,zip:loc.address.zip}) : '')+'</div>'
                              + (loc.pollingHours ? '<div class="sev-voter__loc-hours">'+loc.pollingHours+'</div>' : '')
                              + '</div>';
                    });
                    html += '</div>';
                }

                // Contests
                if (data.contests && data.contests.length) {
                    html += '<div class="sev-voter__section"><div class="sev-voter__section-title">Your Ballot — Races</div>';
                    data.contests.slice(0,8).forEach(function(c){
                        var cands = (c.candidates||[]).map(function(cn){ return cn.name + (cn.party ? ' ('+cn.party+')' : ''); }).join(', ');
                        html += '<div class="sev-voter__contest">'
                              + '<div class="sev-voter__contest-office">'+(c.office||c.type||'')+'</div>'
                              + (cands ? '<div class="sev-voter__contest-cands">'+cands+'</div>' : '')
                              + '</div>';
                    });
                    html += '</div>';
                }

                if (!html) html = '<p style="color:#888;font-size:.85rem">No election data found for this address. Try entering your full registered address.</p>';
                result.innerHTML = html;
            })
            .catch(function(){ btn.disabled=false; btn.textContent='Look Up'; result.style.display='block'; result.innerHTML='<p style="color:#d63638">Network error. Please try again.</p>'; });
        });

        input.addEventListener('keypress', function(e){ if (e.key==='Enter') btn.click(); });
    })();
    </script>
    <?php
    return ob_get_clean();
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. NONCE FOR ALL PUBLIC AJAX
// ═══════════════════════════════════════════════════════════════════════════

add_action('wp_head', function(): void {
    if (!is_singular() && !is_post_type_archive()) return;
    echo '<script>window.sevPublicNonce=' . wp_json_encode(wp_create_nonce('sev_public_nonce')) . ';</script>' . "\n";
});
