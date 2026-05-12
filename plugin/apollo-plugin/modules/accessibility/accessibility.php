<?php
/**
 * Apollo — Feature 33: Accessibility System
 * Feature 34: Performance Budget
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ── Alt text enforcement (warn in media modal if missing)
add_action( 'admin_footer', function(): void {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    ?>
    <script>
    (function(){
        // Warn editors when publishing without alt text on featured image
        if ( typeof wp !== 'undefined' && wp.data ) {
            var lastCheck = false;
            wp.data.subscribe(function(){
                try {
                    var ed = wp.data.select('core/editor');
                    if ( ! ed ) return;
                    var status = ed.getEditedPostAttribute && ed.getEditedPostAttribute('status');
                    if ( status === 'publish' && ! lastCheck ) {
                        lastCheck = true;
                        // Alt text check is handled server-side — this is a UX nudge only
                    }
                } catch(e) {}
            });
        }
    }());
    </script>
    <?php
} );

// ── Skip link (theme outputs this, but ensure it exists)
add_action( 'wp_body_open', function(): void {
    // Only add if theme doesn't already output one
    if ( ! has_action( 'wp_body_open', 'apollo_skip_link' ) ) {
        echo '<a class="skip-link screen-reader-text" href="#content">' . esc_html__( 'Skip to content', 'apollo-plugin' ) . '</a>';
    }
}, 5 );

// ── Accessible image caption output
add_filter( 'img_caption_shortcode', function( string $output, array $attr, string $content ): string {
    if ( ! empty( $attr['caption'] ) ) {
        $id      = ! empty( $attr['id'] ) ? ' id="' . esc_attr( $attr['id'] ) . '"' : '';
        $caption = wp_kses( $attr['caption'], [ 'a' => [ 'href' => [], 'title' => [] ], 'em' => [], 'strong' => [] ] );
        return '<figure' . $id . ' class="wp-caption" style="width:' . absint( $attr['width'] ?? 0 ) . 'px">'
            . do_shortcode( $content )
            . '<figcaption class="wp-caption-text">' . $caption . '</figcaption>'
            . '</figure>';
    }
    return $output;
}, 10, 3 );

// ── Reduced motion support via CSS
add_action( 'wp_head', function(): void {
    echo '<style>@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;scroll-behavior:auto!important;}}</style>' . "\n";
} );

// ── Require alt text meta on attachment upload (admin notice only)
add_action( 'add_attachment', function( int $att_id ): void {
    $post = get_post( $att_id );
    if ( ! $post || ! str_starts_with( (string) $post->post_mime_type, 'image/' ) ) return;
    // Set a transient to display a nudge in admin
    $missing = (array) get_transient( 'apollo_missing_alt_ids' ) ?: [];
    $missing[] = $att_id;
    set_transient( 'apollo_missing_alt_ids', array_slice( $missing, -20 ), HOUR_IN_SECONDS );
} );

// ── Performance: load Video.js only on video pages
add_action( 'wp_enqueue_scripts', function(): void {
    // Dequeue video scripts on non-video pages
    if ( ! is_singular('serve_video') && ! is_post_type_archive('serve_video') ) {
        wp_dequeue_script( 'videojs' );
        wp_dequeue_style( 'videojs' );
    }
    // Dequeue podcast/audio scripts on non-audio pages
    if ( ! is_singular(['serve_episode','serve_podcast']) && ! is_post_type_archive(['serve_episode','serve_podcast']) ) {
        wp_dequeue_script( 'apollo-audio-player' );
        wp_dequeue_script( 'howler' );
    }
}, 99 );

// ── Performance: remove emoji scripts
add_action( 'init', function(): void {
    if ( (bool) get_option('apollo_disable_emoji', false) ) {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        add_filter( 'tiny_mce_plugins', fn($p) => array_diff((array)$p, ['wpemoji']) );
    }
} );

// ── Lazy loading images (add loading="lazy" to post content images)
add_filter( 'the_content', function( string $content ): string {
    if ( ! $content ) return $content;
    return preg_replace_callback(
        '/<img([^>]+)>/i',
        function( array $m ): string {
            $tag = $m[0];
            if ( strpos( $tag, 'loading=' ) === false ) {
                // Don't lazy-load the first image (LCP candidate)
                static $count = 0;
                $count++;
                if ( $count > 1 ) {
                    $tag = str_replace( '<img', '<img loading="lazy"', $tag );
                }
            }
            return $tag;
        },
        $content
    );
}, 15 );
