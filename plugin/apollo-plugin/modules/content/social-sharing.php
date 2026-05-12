<?php
/**
 * Apollo — Feature 21: Social Sharing Package
 * Feature 37: UTM & Campaign Tracking
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    $metas = [
        '_apollo_social_x'         => 'string',
        '_apollo_social_facebook'  => 'string',
        '_apollo_social_linkedin'  => 'string',
        '_apollo_social_instagram' => 'string',
        '_apollo_social_youtube'   => 'string',
        '_apollo_social_short_hed' => 'string',
        '_apollo_push_copy'        => 'string',
        '_apollo_newsletter_blurb' => 'string',
    ];
    foreach ( [ 'post', 'page', 'serve_video', 'serve_episode', 'serve_podcast' ] as $pt ) {
        foreach ( $metas as $key => $type ) {
            register_post_meta( $pt, $key, [
                'show_in_rest'  => true, 'single' => true, 'type' => $type,
                'auth_callback' => fn() => current_user_can( 'edit_posts' ),
            ] );
        }
    }
} );

add_action( 'add_meta_boxes', function(): void {
    $screens = [ 'post', 'page', 'serve_video', 'serve_episode', 'serve_podcast' ];
    add_meta_box( 'apollo-social-copy', __( '📱 Social Copy', 'apollo-plugin' ), 'apollo_social_copy_meta_box', $screens, 'normal', 'low' );
} );

function apollo_social_copy_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_social_' . $post->ID, 'apollo_social_nonce' );
    $fields = [
        '_apollo_social_x'         => [ 'label' => 'X / Threads (280 chars)', 'rows' => 2 ],
        '_apollo_social_facebook'  => [ 'label' => 'Facebook', 'rows' => 2 ],
        '_apollo_social_linkedin'  => [ 'label' => 'LinkedIn', 'rows' => 2 ],
        '_apollo_social_instagram' => [ 'label' => 'Instagram caption', 'rows' => 3 ],
        '_apollo_social_youtube'   => [ 'label' => 'YouTube description', 'rows' => 3 ],
        '_apollo_social_short_hed' => [ 'label' => 'Short headline', 'rows' => 1 ],
        '_apollo_push_copy'        => [ 'label' => 'Push notification copy', 'rows' => 1 ],
        '_apollo_newsletter_blurb' => [ 'label' => 'Newsletter blurb', 'rows' => 2 ],
    ];
    echo '<table class="form-table" style="margin:0"><tbody>';
    foreach ( $fields as $key => $f ) {
        $val = (string) get_post_meta( $post->ID, $key, true );
        echo '<tr><th style="width:160px">' . esc_html($f['label']) . '</th>';
        echo '<td><textarea name="' . esc_attr($key) . '" rows="' . (int)$f['rows'] . '" style="width:100%">' . esc_textarea($val) . '</textarea></td></tr>';
    }
    echo '</tbody></table>';
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_social_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_social_nonce'] ) ), 'apollo_social_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    foreach ( [ '_apollo_social_x', '_apollo_social_facebook', '_apollo_social_linkedin', '_apollo_social_instagram', '_apollo_social_youtube', '_apollo_social_short_hed', '_apollo_push_copy', '_apollo_newsletter_blurb' ] as $key ) {
        update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[$key] ?? '' ) ) );
    }
} );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-utm', __( '📊 UTM Campaign URLs', 'apollo-plugin' ), 'apollo_utm_meta_box', [ 'post', 'page', 'serve_video', 'serve_episode' ], 'side', 'low' );
} );

function apollo_utm_meta_box( \WP_Post $post ): void {
    if ( $post->post_status !== 'publish' ) {
        echo '<p style="color:#888;font-size:12px">' . esc_html__( 'Publish this post to generate UTM URLs.', 'apollo-plugin' ) . '</p>';
        return;
    }
    $url  = get_permalink( $post->ID );
    $name = sanitize_title( get_the_title( $post->ID ) );
    $campaigns = [
        'social'     => [ 'utm_source' => 'social',     'utm_medium' => 'social',    'utm_campaign' => $name ],
        'newsletter' => [ 'utm_source' => 'newsletter', 'utm_medium' => 'email',      'utm_campaign' => $name ],
        'facebook'   => [ 'utm_source' => 'facebook',   'utm_medium' => 'social',     'utm_campaign' => $name ],
        'twitter'    => [ 'utm_source' => 'twitter',    'utm_medium' => 'social',     'utm_campaign' => $name ],
        'podcast'    => [ 'utm_source' => 'podcast',    'utm_medium' => 'audio',      'utm_campaign' => $name ],
    ];
    foreach ( $campaigns as $label => $params ) {
        $utm_url = add_query_arg( $params, $url );
        echo '<p style="margin-bottom:6px"><strong>' . esc_html( ucfirst($label) ) . '</strong><br>';
        echo '<input type="text" value="' . esc_attr($utm_url) . '" readonly style="width:100%;font-size:11px" onclick="this.select()"></p>';
    }
}

function apollo_share_buttons_render( int $post_id ): string {
    if ( $post_id <= 0 ) return '';
    $url   = esc_url( get_permalink( $post_id ) );
    $title = get_the_title( $post_id );
    $x_url = 'https://twitter.com/intent/tweet?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title );
    $fb_url= 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url );
    $li_url= 'https://www.linkedin.com/shareArticle?mini=true&url=' . rawurlencode( $url );
    return '<div class="apollo-share-buttons" aria-label="' . esc_attr__( 'Share', 'apollo-plugin' ) . '">'
        . '<a href="' . esc_url($x_url) . '" class="apollo-share-btn apollo-share-btn--x" target="_blank" rel="noopener">X</a>'
        . '<a href="' . esc_url($fb_url) . '" class="apollo-share-btn apollo-share-btn--fb" target="_blank" rel="noopener">Facebook</a>'
        . '<a href="' . esc_url($li_url) . '" class="apollo-share-btn apollo-share-btn--li" target="_blank" rel="noopener">LinkedIn</a>'
        . '</div>';
}

add_filter( 'apollo_render_share-buttons', function( $html, array $args ): string {
    return apollo_share_buttons_render( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );
