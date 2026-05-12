<?php
/**
 * Apollo — Feature 32: Content Warning / Sensitivity Labels
 *
 * Warning labels with accessible frontend display.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

function apollo_content_warning_types(): array {
    return [
        'graphic'         => __( 'Graphic content', 'apollo-plugin' ),
        'violence'        => __( 'Violence', 'apollo-plugin' ),
        'sexual-violence' => __( 'Sexual violence', 'apollo-plugin' ),
        'death'           => __( 'Death', 'apollo-plugin' ),
        'self-harm'       => __( 'Self-harm', 'apollo-plugin' ),
        'disturbing'      => __( 'Disturbing details', 'apollo-plugin' ),
        'sensitive-image' => __( 'Sensitive image', 'apollo-plugin' ),
        'court-document'  => __( 'Court document', 'apollo-plugin' ),
        'police-report'   => __( 'Police report', 'apollo-plugin' ),
    ];
}

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page', 'serve_video' ] as $pt ) {
        register_post_meta( $pt, '_apollo_content_warnings', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

function apollo_get_content_warnings( int $post_id = 0 ): array {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $raw = get_post_meta( $post_id, '_apollo_content_warnings', true );
    if ( ! $raw ) return [];
    $decoded = json_decode( $raw, true );
    return is_array( $decoded ) ? $decoded : [];
}

function apollo_content_warning_html( int $post_id = 0 ): string {
    $warnings = apollo_get_content_warnings( $post_id );
    if ( empty( $warnings ) ) return '';

    $types = apollo_content_warning_types();
    $labels = array_filter( array_map( fn($w) => $types[$w] ?? null, $warnings ) );
    if ( empty( $labels ) ) return '';

    $out  = '<div class="apollo-content-warning" role="note" aria-label="' . esc_attr__( 'Content warning', 'apollo-plugin' ) . '">';
    $out .= '<strong class="apollo-content-warning__header">⚠️ ' . esc_html__( 'Content Warning:', 'apollo-plugin' ) . '</strong> ';
    $out .= esc_html( implode( ', ', $labels ) );
    $out .= '</div>';
    return $out;
}

add_filter( 'apollo_render_content-warning', function( $html, array $args ): string {
    return apollo_content_warning_html( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-content-warning', __( '⚠️ Content Warnings', 'apollo-plugin' ), 'apollo_content_warning_meta_box', [ 'post', 'page', 'serve_video' ], 'side', 'low' );
} );

function apollo_content_warning_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_cw_' . $post->ID, 'apollo_cw_nonce' );
    $warnings = apollo_get_content_warnings( $post->ID );
    foreach ( apollo_content_warning_types() as $k => $label ) {
        echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="apollo_cw[]" value="' . esc_attr($k) . '" ' . checked( in_array($k,$warnings,true), true, false ) . '> ' . esc_html($label) . '</label>';
    }
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_cw_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_cw_nonce'] ) ), 'apollo_cw_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    $raw   = (array) ( $_POST['apollo_cw'] ?? [] );
    $clean = array_filter( array_map( 'sanitize_key', $raw ) );
    update_post_meta( $post_id, '_apollo_content_warnings', wp_json_encode( array_values( $clean ) ) );
} );

add_action( 'wp_head', function(): void {
    echo '<style>
.apollo-content-warning{background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #fd7e14;padding:10px 14px;margin:0 0 16px;font-size:14px;border-radius:0 4px 4px 0;}
</style>';
} );
