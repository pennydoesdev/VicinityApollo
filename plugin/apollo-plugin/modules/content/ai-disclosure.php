<?php
/**
 * Apollo — Feature 27: AI Disclosure System
 *
 * Per-article AI usage disclosure with public display options.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

function apollo_ai_disclosure_levels(): array {
    return [
        'none'          => __( 'No AI used', 'apollo-plugin' ),
        'tags-seo'      => __( 'AI assisted with tags/SEO only', 'apollo-plugin' ),
        'transcription' => __( 'AI assisted with transcription', 'apollo-plugin' ),
        'summary-social'=> __( 'AI assisted with summary/social copy', 'apollo-plugin' ),
        'translation'   => __( 'AI assisted with translation', 'apollo-plugin' ),
        'human-reviewed'=> __( 'Human reviewed', 'apollo-plugin' ),
        'human-editor'  => __( 'Human editor responsible', 'apollo-plugin' ),
    ];
}

function apollo_ai_display_options(): array {
    return [
        'hidden'   => __( 'Hidden (internal only)', 'apollo-plugin' ),
        'small'    => __( 'Small public disclosure', 'apollo-plugin' ),
        'full'     => __( 'Full public AI note', 'apollo-plugin' ),
    ];
}

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page', 'serve_video', 'serve_episode' ] as $pt ) {
        register_post_meta( $pt, '_apollo_ai_level', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_apollo_ai_display', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_apollo_ai_note', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

function apollo_ai_disclosure_html( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    if ( ! $post_id ) return '';

    $level   = (string) get_post_meta( $post_id, '_apollo_ai_level', true );
    $display = (string) get_post_meta( $post_id, '_apollo_ai_display', true );
    $note    = (string) get_post_meta( $post_id, '_apollo_ai_note', true );

    if ( ! $level || $display === 'hidden' || $display === '' ) return '';

    $levels  = apollo_ai_disclosure_levels();
    $label   = $levels[ $level ] ?? $level;

    if ( $display === 'small' ) {
        return '<p class="apollo-ai-disclosure apollo-ai-disclosure--small">🤖 ' . esc_html( $label ) . '</p>';
    }

    $out = '<div class="apollo-ai-disclosure apollo-ai-disclosure--full" role="note">';
    $out .= '<strong>' . esc_html__( 'AI Usage Disclosure:', 'apollo-plugin' ) . '</strong> ' . esc_html( $label );
    if ( $note ) $out .= '<br><em>' . esc_html( $note ) . '</em>';
    $out .= '</div>';
    return $out;
}

add_filter( 'apollo_render_ai-disclosure', function( $html, array $args ): string {
    return apollo_ai_disclosure_html( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-ai-disclosure', __( '🤖 AI Disclosure', 'apollo-plugin' ), 'apollo_ai_disclosure_meta_box', [ 'post', 'page', 'serve_video', 'serve_episode' ], 'side', 'low' );
} );

function apollo_ai_disclosure_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_ai_disc_' . $post->ID, 'apollo_ai_disc_nonce' );
    $level   = (string) get_post_meta( $post->ID, '_apollo_ai_level', true );
    $display = (string) get_post_meta( $post->ID, '_apollo_ai_display', true );
    $note    = (string) get_post_meta( $post->ID, '_apollo_ai_note', true );
    ?>
    <p><label><?php esc_html_e( 'AI Level', 'apollo-plugin' ); ?><br>
        <select name="_apollo_ai_level" style="width:100%">
            <option value=""><?php esc_html_e( '— Select —', 'apollo-plugin' ); ?></option>
            <?php foreach ( apollo_ai_disclosure_levels() as $k => $v ) : ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($level,$k); ?>><?php echo esc_html($v); ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label><?php esc_html_e( 'Display', 'apollo-plugin' ); ?><br>
        <select name="_apollo_ai_display" style="width:100%">
            <?php foreach ( apollo_ai_display_options() as $k => $v ) : ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected($display,$k); ?>><?php echo esc_html($v); ?></option>
            <?php endforeach; ?>
        </select>
    </label></p>
    <p><label><?php esc_html_e( 'Additional note', 'apollo-plugin' ); ?><br>
        <textarea name="_apollo_ai_note" rows="2" style="width:100%"><?php echo esc_textarea($note); ?></textarea>
    </label></p>
    <?php
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_ai_disc_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_ai_disc_nonce'] ) ), 'apollo_ai_disc_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    update_post_meta( $post_id, '_apollo_ai_level',   sanitize_key( wp_unslash( $_POST['_apollo_ai_level'] ?? '' ) ) );
    update_post_meta( $post_id, '_apollo_ai_display',  sanitize_key( wp_unslash( $_POST['_apollo_ai_display'] ?? '' ) ) );
    update_post_meta( $post_id, '_apollo_ai_note',    sanitize_textarea_field( wp_unslash( $_POST['_apollo_ai_note'] ?? '' ) ) );
} );

add_action( 'wp_head', function(): void {
    echo '<style>
.apollo-ai-disclosure{font-size:13px;color:#555;margin:12px 0;}
.apollo-ai-disclosure--small{display:inline-flex;align-items:center;gap:4px;}
.apollo-ai-disclosure--full{background:#f8f9fa;border:1px solid #dee2e6;padding:10px 14px;border-radius:4px;}
</style>';
} );
