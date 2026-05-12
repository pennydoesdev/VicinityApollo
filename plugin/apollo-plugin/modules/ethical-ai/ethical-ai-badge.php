<?php
/**
 * Ethical AI Badge — Apollo
 *
 * Displays "Created with Ethical AI using Proprietary Penny AI."
 * before content on any enabled post type, with a per-post override toggle.
 *
 * Admin: Customizer → Content → Ethical AI Badge
 *  - Enable globally (checkbox per post type)
 *  - Per-post: override via meta box toggle (force on / force off / use global)
 *
 * @package Apollo
 */
defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// 0. REGISTER POST META (show_in_rest lets Gutenberg read/write the override)
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'init', static function (): void {
    $supported = [ 'post', 'page', 'serve_video', 'serve_podcast', 'serve_episode' ];
    $meta_args  = [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'string',
        'default'           => 'global',
        'sanitize_callback' => static function ( string $v ): string {
            return in_array( $v, [ 'global', 'on', 'off' ], true ) ? $v : 'global';
        },
        'auth_callback' => static function (): bool {
            return current_user_can( 'edit_posts' );
        },
    ];
    foreach ( $supported as $pt ) {
        register_post_meta( $pt, '_eai_override', $meta_args );
    }
}, 20 );

// ═══════════════════════════════════════════════════════════════════════════
// 1. CUSTOMIZER — global enable per post type
// ═══════════════════════════════════════════════════════════════════════════

add_action('customize_register', function(WP_Customize_Manager $wp_customize): void {

    $wp_customize->add_section('serve_ethical_ai', [
        'title'       => '🤖 Ethical AI Badge',
        'description' => 'Show the "Created with Ethical AI using Proprietary Penny AI." badge before content. Enable globally per post type, then override per-post in the editor.',
        'priority'    => 145,
    ]);

    $post_types = [
        'post'          => 'Posts (articles)',
        'serve_podcast' => 'Podcasts',
        'serve_video'   => 'Videos',
        'serve_episode' => 'Podcast Episodes',
        'page'          => 'Pages',
    ];

    foreach ($post_types as $pt => $label) {
        $key = 'eai_enabled_' . $pt;
        $wp_customize->add_setting($key, [
            'default'           => '0',
            'sanitize_callback' => function($v) { return $v === '1' ? '1' : '0'; },
            'transport'         => 'refresh',
        ]);
        $wp_customize->add_control($key, [
            'label'   => 'Enable on ' . $label,
            'section' => 'serve_ethical_ai',
            'type'    => 'checkbox',
        ]);
    }

    // Radio player badge toggle
    $wp_customize->add_setting('eai_enabled_radio', [
        'default'           => '0',
        'sanitize_callback' => function($v) { return $v === '1' ? '1' : '0'; },
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('eai_enabled_radio', [
        'label'   => 'Enable on Live Radio player (/listen/)',
        'section' => 'serve_ethical_ai',
        'type'    => 'checkbox',
    ]);

    // Custom badge text (optional override)
    $wp_customize->add_setting('eai_badge_text', [
        'default'           => 'Created with Ethical AI using Proprietary Penny AI.',
        'sanitize_callback' => 'sanitize_text_field',
        'transport'         => 'refresh',
    ]);
    $wp_customize->add_control('eai_badge_text', [
        'label'       => 'Badge text',
        'section'     => 'serve_ethical_ai',
        'type'        => 'text',
        'description' => 'Customise the badge label shown before content.',
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. PER-POST OVERRIDE — handled by the PluginDocumentSettingPanel in blocks.js
//    (meta key _eai_override is registered show_in_rest above; Gutenberg saves
//    it via the REST API, no meta box or save_post hook needed)
// ═══════════════════════════════════════════════════════════════════════════

// ═══════════════════════════════════════════════════════════════════════════
// 3. HELPER — should badge show for this post?
// ═══════════════════════════════════════════════════════════════════════════

function eai_should_show(int $post_id = 0): bool {
    if (!$post_id) $post_id = get_the_ID() ?: 0;
    if (!$post_id) return false;

    $override = get_post_meta($post_id, '_eai_override', true) ?: 'global';
    if ($override === 'on')  return true;
    if ($override === 'off') return false;

    // Use global setting
    $post_type = get_post_type($post_id) ?: 'post';
    return get_theme_mod('eai_enabled_' . $post_type, '0') === '1';
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. BADGE HTML
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Returns the badge HTML.
 *
 * @param string $variant 'banner' (default, prepended above content)
 *                        'inline' (small corner chip beside the player)
 */
function eai_badge_html( string $variant = 'banner' ): string {
    $text = esc_html( get_theme_mod(
        'eai_badge_text',
        'Created with Ethical AI using Proprietary Penny AI.'
    ) );

    // SVG icon — references the <symbol id="eai-spark"> defined once in wp_head.
    $icon = '<svg class="eai-badge__icon" aria-hidden="true" focusable="false" width="20" height="20">'
          . '<use href="#eai-spark"/>'
          . '</svg>';

    if ( $variant === 'inline' ) {
        return '<div class="eai-badge eai-badge--inline" role="note" aria-label="Ethical AI disclosure" title="' . $text . '">'
            . $icon
            . '<span class="eai-badge__text eai-badge__text--short">Ethical AI</span>'
            . '</div>';
    }

    // Default banner (above content)
    return '<div class="eai-badge" role="note" aria-label="Ethical AI disclosure">'
        . $icon
        . '<span class="eai-badge__text">' . $text . '</span>'
        . '</div>';
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. INJECT INTO the_content FILTER (posts, pages, episodes)
// ═══════════════════════════════════════════════════════════════════════════

add_filter('the_content', function($content){ if (!is_string($content)) $content=(string)$content;
    if (!is_singular()) return $content;

    // Skip serve_episode — the badge is already rendered via sep_after_title action hook
    // (which fires in single-serve_episode.php). Running it here too causes a double render.
    if (is_singular('serve_episode')) return $content;

    $post_id = get_the_ID();
    if (!$post_id) return $content;

    if (!eai_should_show($post_id)) return $content;

    // Prepend badge before content
    return eai_badge_html() . $content;
}, 5); // priority 5 — before TTS (15) and translate (20)

// ═══════════════════════════════════════════════════════════════════════════
// 6. INJECT INTO VIDEO HUB single video page (above the player)
// ═══════════════════════════════════════════════════════════════════════════

add_action('svh_before_player', function(int $post_id): void {
    if (eai_should_show($post_id)) {
        // Inline variant: small badge in the corner of the player
        echo '<div class="eai-player-badge-wrap">' . eai_badge_html('inline') . '</div>';
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 7. INJECT INTO PODCAST archive (/listen/) — Live Radio badge
// ═══════════════════════════════════════════════════════════════════════════

// Called from archive-serve_podcast.php via action hook
add_action('slr_before_player', function(): void {
    if (get_theme_mod('eai_enabled_radio', '0') !== '1') return;
    echo '<div class="eai-player-badge-wrap">' . eai_badge_html('inline') . '</div>';
});

// Podcast episode single pages (before the audio player)
add_action('sep_before_player', function(int $post_id = 0): void {
    if (!$post_id) $post_id = get_the_ID() ?: 0;
    if (eai_should_show($post_id)) {
        echo '<div class="eai-player-badge-wrap">' . eai_badge_html('inline') . '</div>';
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 8. SVG SYMBOL — Penny AI sparkle logo (output ONCE per page)
//
// The symbol is defined once in <head> and referenced with <use href="#eai-spark">
// in every badge instance, so the path data is never repeated in the HTML.
// ═══════════════════════════════════════════════════════════════════════════

function eai_badge_needed_on_page(): bool {
    static $result = null;
    if ( $result !== null ) return $result;

    $types = ['post', 'page', 'serve_podcast', 'serve_video', 'serve_episode'];
    foreach ($types as $pt) {
        if (get_theme_mod('eai_enabled_' . $pt, '0') === '1') { $result = true; return true; }
    }
    if (get_theme_mod('eai_enabled_radio', '0') === '1') { $result = true; return true; }
    if (is_singular()) {
        $pid = get_the_ID();
        if ($pid && get_post_meta($pid, '_eai_override', true) === 'on') { $result = true; return true; }
    }
    $result = false;
    return false;
}

// Output the SVG <symbol> definition once — referenced cheaply by every badge via <use>.
add_action('wp_head', function(): void {
    if (!eai_badge_needed_on_page()) return;
    // Inline hidden SVG: defines the Penny AI sparkle logo mark.
    // Faithfully traced from the approved Penny AI logo PNG:
    //   - Large 4-pointed sparkle: orange (top) → dark brownish-gray (bottom-left) gradient
    //   - Small accent sparkle: upper-left, muted brownish-gray
    //   - Tiny accent sparkle: lower-right, warm gray
    // Gradient uses objectBoundingBox so it scales correctly at any render size.
    echo '<svg style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">'
       . '<defs>'
       // Diagonal gradient: orange top-right → dark brownish-gray bottom-left
       . '<linearGradient id="eai-g" x1="1" y1="0" x2="0" y2="1">'
       . '<stop offset="0%" stop-color="#f07828"/>'
       . '<stop offset="100%" stop-color="#4a3825"/>'
       . '</linearGradient>'
       . '</defs>'
       . '<symbol id="eai-spark" viewBox="0 0 100 100">'
       // White oval background — ensures visibility on dark pages
       . '<ellipse cx="50" cy="50" rx="48" ry="48" fill="white"/>'
       // Large main sparkle — 4-pointed cubic-bezier star, Penny AI logomark
       . '<path d="M44 16C49 16 72 45 72 50C72 55 49 84 44 84C39 84 16 55 16 50C16 45 39 16 44 16Z" fill="url(#eai-g)"/>'
       // Small accent sparkle — upper-left
       . '<path d="M24 12C26 12 33 21 33 23C33 25 26 34 24 34C22 34 15 25 15 23C15 21 22 12 24 12Z" fill="#786555"/>'
       // Tiny accent sparkle — lower-right
       . '<path d="M65 63C66 63 70 67 70 68C70 69 66 73 65 73C64 73 60 69 60 68C60 67 64 63 65 63Z" fill="#887555"/>'
       . '</symbol>'
       . '</svg>' . "\n";
}, 1); // priority 1 — before any badge HTML is echoed

// ═══════════════════════════════════════════════════════════════════════════
// 9. CSS — badge styling
// ═══════════════════════════════════════════════════════════════════════════

add_action('wp_head', function(): void {
    if (!eai_badge_needed_on_page()) return;

    echo '<style id="eai-badge-css">'
        // Banner badge (above article content)
        . '.eai-badge{'
        . 'display:flex;align-items:center;gap:10px;'
        . 'background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%);'
        . 'border:1px solid rgba(129,140,248,.35);'
        . 'border-left:3px solid #818cf8;'
        . 'border-radius:6px;'
        . 'padding:9px 14px;'
        . 'margin:0 0 1.25rem;'
        . 'max-width:var(--flavor-narrow-width,720px);'
        . '}'
        // SVG icon — sized to match surrounding text
        . '.eai-badge__icon{width:20px;height:20px;flex-shrink:0;display:block;overflow:visible}'
        . '.eai-badge__text{'
        . 'font-family:var(--flavor-font-ui,sans-serif);'
        . 'font-size:.75rem;font-weight:600;'
        . 'color:#c7d2fe;letter-spacing:.02em;line-height:1.4;'
        . '}'
        // Inline chip badge (beside video / podcast player)
        . '.eai-player-badge-wrap{'
        . 'display:flex;justify-content:flex-end;margin-bottom:6px;'
        . '}'
        . '.eai-badge--inline{'
        . 'display:inline-flex;align-items:center;gap:5px;'
        . 'background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 100%);'
        . 'border:1px solid rgba(129,140,248,.4);'
        . 'border-radius:100px;'
        . 'padding:4px 10px 4px 7px;'
        . 'margin:0;cursor:default;'
        . '}'
        . '.eai-badge--inline .eai-badge__icon{width:14px;height:14px}'
        . '.eai-badge--inline .eai-badge__text--short{'
        . 'font-family:var(--flavor-font-ui,sans-serif);'
        . 'font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;'
        . 'color:#a5b4fc;white-space:nowrap;'
        . '}'
        . '</style>' . "\n";
}, 20);

// ═══════════════════════════════════════════════════════════════════════════
// 10. PLACEMENT HOOKS — below headline on video + episode single pages
//
// Theme templates fire do_action('svh_after_meta', $post_id) on single video
// pages and do_action('sep_after_title', $ep_id) on single episode pages.
// We hook here so the badge appears in the post meta area rather than
// prepended to the_content (which stays as fallback for posts/pages).
// ═══════════════════════════════════════════════════════════════════════════

// Single video page — below the meta row (author / date / views / duration)
add_action('svh_after_meta', function(int $post_id): void {
    if (eai_should_show($post_id)) {
        echo eai_badge_html();
    }
});

// Single episode page — below the episode title
add_action('sep_after_title', function(int $post_id): void {
    if (!$post_id) $post_id = get_the_ID() ?: 0;
    if (eai_should_show($post_id)) {
        echo eai_badge_html();
    }
});
