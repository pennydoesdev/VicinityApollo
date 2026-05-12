<?php
/**
 * Sensitive Content — Apollo
 *
 * Two layers of protection, both configurable per-post in the Gutenberg editor:
 *
 *  1. apollo/sensitive-content Gutenberg block
 *     Wraps any inner content (images, embeds, galleries) with a blur overlay.
 *     Visitor clicks "I understand — show content" → blur removed immediately.
 *     One-click, no memory — overlay reappears on every page load.
 *
 *  2. Full-page gate (post-level meta: _apollo_sensitive_post)
 *     When enabled on a VideoHub video, podcast episode, podcast show, article,
 *     or page — a fixed full-screen overlay covers the entire page from the
 *     first instant of body render. Visitor must click through to see anything.
 *     Configured via the "⚠ Sensitive Content" sidebar panel in the block editor.
 *
 * @package Apollo
 * @since   1.24
 */
defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// 1. REGISTER POST META (show_in_rest = true so Gutenberg can read/write)
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'init', static function (): void {
    $supported_types = [ 'post', 'page', 'serve_video', 'serve_podcast', 'serve_episode' ];

    $flag_args = [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => static function (): bool {
            return current_user_can( 'edit_posts' );
        },
    ];

    $text_args = [
        'show_in_rest'      => true,
        'single'            => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_textarea_field',
        'auth_callback'     => static function (): bool {
            return current_user_can( 'edit_posts' );
        },
    ];

    foreach ( $supported_types as $pt ) {
        register_post_meta( $pt, '_apollo_sensitive_post',    $flag_args );
        register_post_meta( $pt, '_apollo_sensitive_warning', $text_args );
    }
}, 20 );

// ═══════════════════════════════════════════════════════════════════════════
// 2. REGISTER apollo/sensitive-content BLOCK TYPE
//    Static block — JS handles edit/save, PHP just registers the type so
//    register_block_type is aware of it (allows server-side validation).
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'init', static function (): void {
    if ( ! function_exists( 'register_block_type' ) ) return;

    register_block_type( 'apollo/sensitive-content', [
        'title'       => 'Sensitive Content',
        'description' => 'Wraps any media with a one-click blur overlay requiring confirmation.',
        'category'    => 'media',
        'icon'        => 'hidden',
        'attributes'  => [
            'message'    => [ 'type' => 'string', 'default' => 'This content contains sensitive material. Viewer discretion is advised.' ],
            'label'      => [ 'type' => 'string', 'default' => 'Sensitive Content' ],
            'buttonText' => [ 'type' => 'string', 'default' => 'I understand — show content' ],
        ],
    ] );
}, 25 );

// ═══════════════════════════════════════════════════════════════════════════
// 3. FULL-PAGE GATE
//    Injects immediately after <body> opens so nothing is visible before it.
//    Applies to any post type with _apollo_sensitive_post = '1'.
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'wp_body_open', static function (): void {
    if ( ! is_singular() ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;
    if ( get_post_meta( $post_id, '_apollo_sensitive_post', true ) !== '1' ) return;

    $warning = sanitize_textarea_field(
        get_post_meta( $post_id, '_apollo_sensitive_warning', true )
        ?: 'This content contains sensitive material that may not be suitable for all audiences. Viewer discretion is advised.'
    );

    $post_type = get_post_type( $post_id ) ?: 'post';
    $type_label = match ( $post_type ) {
        'serve_video'   => 'Video',
        'serve_episode' => 'Podcast Episode',
        'serve_podcast' => 'Podcast',
        'page'          => 'Page',
        default         => 'Article',
    };

    $title = esc_html( get_the_title( $post_id ) );
    ?>
<div id="apollo-sensitive-gate"
     role="dialog"
     aria-modal="true"
     aria-labelledby="asg-heading"
     aria-describedby="asg-desc">
  <div class="asg-backdrop" aria-hidden="true"></div>
  <div class="asg-panel">
    <div class="asg-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48">
        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        <line x1="12" y1="9" x2="12" y2="13"/>
        <line x1="12" y1="17" x2="12.01" y2="17"/>
      </svg>
    </div>
    <div class="asg-badge">Sensitive <?php echo esc_html( $type_label ); ?></div>
    <h2 class="asg-heading" id="asg-heading"><?php echo $title; ?></h2>
    <p class="asg-desc" id="asg-desc"><?php echo esc_html( $warning ); ?></p>
    <p class="asg-age-notice">You must be 18 years or older, or have parental guidance, to proceed.</p>
    <div class="asg-actions">
      <button class="asg-confirm" id="asg-confirm-btn" type="button">
        I understand — show me the <?php echo esc_html( strtolower( $type_label ) ); ?>
      </button>
      <a class="asg-back" href="<?php echo esc_url( wp_get_referer() ?: home_url( '/' ) ); ?>">
        ← Go back
      </a>
    </div>
  </div>
</div>
    <?php
}, 1 ); // priority 1 — fires before theme header output

// ═══════════════════════════════════════════════════════════════════════════
// 4. CLASSIC META BOX REMOVED — now handled by Gutenberg "Access Control" panel
//    (blocks.js PluginDocumentSettingPanel, registered in the theme).
//    The save_post hook below still handles non-block-editor saves.
// ═══════════════════════════════════════════════════════════════════════════

function apollo_sensitive_meta_box_render( WP_Post $post ): void {
    wp_nonce_field( 'apollo_sensitive_save', 'apollo_sensitive_nonce' );
    $is_sensitive = get_post_meta( $post->ID, '_apollo_sensitive_post', true ) === '1';
    $warning      = get_post_meta( $post->ID, '_apollo_sensitive_warning', true );
    ?>
    <p style="font-size:12px;color:#555;margin:0 0 10px">
        When enabled, a full-screen gate covers the entire page until the visitor confirms they want to see the content.
        Use the <strong>Sensitive Content block</strong> in the editor to gate individual images or media.
    </p>
    <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:13px;cursor:pointer">
        <input type="checkbox" name="apollo_sensitive_post" value="1" <?php checked( $is_sensitive ); ?>>
        <strong>Full-page sensitive gate</strong>
    </label>
    <div id="asc-warning-wrap" style="<?php echo $is_sensitive ? '' : 'display:none'; ?>">
        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px">Custom warning message</label>
        <textarea name="apollo_sensitive_warning" rows="3" style="width:100%;font-size:12px"
            placeholder="This content contains sensitive material..."><?php echo esc_textarea( $warning ); ?></textarea>
    </div>
    <script>
    document.querySelector('[name="apollo_sensitive_post"]')?.addEventListener('change', function(){
        document.getElementById('asc-warning-wrap').style.display = this.checked ? '' : 'none';
    });
    </script>
    <?php
}

add_action( 'save_post', static function ( int $post_id ): void {
    if ( ! isset( $_POST['apollo_sensitive_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_key( $_POST['apollo_sensitive_nonce'] ), 'apollo_sensitive_save' ) ) return;
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    // REST API (Gutenberg) handles meta directly; this only catches classic editor saves
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;

    $sensitive = isset( $_POST['apollo_sensitive_post'] ) ? '1' : '';
    update_post_meta( $post_id, '_apollo_sensitive_post', $sensitive );

    $warning = sanitize_textarea_field( wp_unslash( $_POST['apollo_sensitive_warning'] ?? '' ) );
    update_post_meta( $post_id, '_apollo_sensitive_warning', $warning );
} );

// ═══════════════════════════════════════════════════════════════════════════
// 5. ENQUEUE FRONTEND ASSETS
//    Only loads CSS + JS on pages that actually use sensitive features.
// ═══════════════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', static function (): void {
    if ( ! is_singular() ) return;

    $post_id   = get_the_ID();
    $has_gate  = $post_id && get_post_meta( $post_id, '_apollo_sensitive_post', true ) === '1';
    $has_block = $post_id && has_block( 'apollo/sensitive-content', $post_id );

    if ( ! $has_gate && ! $has_block ) return;

    $ver = defined( 'APOLLO_THEME_VERSION' ) ? APOLLO_THEME_VERSION : APOLLO_PLUGIN_VERSION;

    wp_enqueue_style(
        'apollo-sensitive-content',
        get_template_directory_uri() . '/assets/css/sensitive-content.css',
        [],
        $ver
    );

    wp_enqueue_script(
        'apollo-sensitive-content',
        get_template_directory_uri() . '/assets/js/sensitive-content.js',
        [],
        $ver,
        [ 'in_footer' => false, 'strategy' => 'blocking' ]
        // blocking so the gate JS runs before first paint — prevents flash of content
    );
} );
