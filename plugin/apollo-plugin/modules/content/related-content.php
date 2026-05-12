<?php
/**
 * Apollo — Features 9 & 10: Related Content + Most Read / Trending
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────────────
// RELATED CONTENT (Feature 9)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    register_post_meta( 'post', '_apollo_related_posts', [
        'show_in_rest' => true, 'single' => true, 'type' => 'string',
        'auth_callback' => fn() => current_user_can( 'edit_posts' ),
    ] );
} );

function apollo_get_related_posts( int $post_id, int $count = 4 ): array {
    $manual = json_decode( (string) get_post_meta( $post_id, '_apollo_related_posts', true ), true );
    if ( is_array( $manual ) && ! empty( $manual ) ) {
        return array_filter( array_map( fn($id) => get_post( absint($id) ), $manual ) );
    }
    $cats = wp_get_post_categories( $post_id, [ 'fields' => 'ids' ] );
    if ( $cats ) {
        $posts = get_posts( [
            'category__in'        => $cats,
            'post__not_in'        => [ $post_id ],
            'posts_per_page'      => $count,
            'orderby'             => 'relevance',
            'ignore_sticky_posts' => true,
        ] );
        if ( $posts ) return $posts;
    }
    $tags = wp_get_post_tags( $post_id, [ 'fields' => 'ids' ] );
    if ( $tags ) {
        return get_posts( [
            'tag__in'             => $tags,
            'post__not_in'        => [ $post_id ],
            'posts_per_page'      => $count,
            'ignore_sticky_posts' => true,
        ] );
    }
    return [];
}

function apollo_related_posts_html( int $post_id = 0, string $heading = '' ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $related = apollo_get_related_posts( $post_id );
    if ( empty( $related ) ) return '';

    $heading = $heading ?: __( 'Related Stories', 'apollo-plugin' );
    $out  = '<section class="apollo-related-posts" aria-label="' . esc_attr( $heading ) . '">';
    $out .= '<h3 class="apollo-related-posts__heading">' . esc_html( $heading ) . '</h3>';
    $out .= '<ul class="apollo-related-posts__list">';
    foreach ( $related as $rp ) {
        $thumb = get_the_post_thumbnail( $rp->ID, 'apollo-thumb' );
        $out  .= '<li class="apollo-related-posts__item">';
        if ( $thumb ) $out .= '<a href="' . get_permalink($rp->ID) . '" class="apollo-related-posts__thumb">' . $thumb . '</a>';
        $out  .= '<a href="' . get_permalink($rp->ID) . '" class="apollo-related-posts__title">' . esc_html(get_the_title($rp->ID)) . '</a>';
        $out  .= '</li>';
    }
    $out .= '</ul></section>';
    return $out;
}

add_filter( 'apollo_render_related-posts', function( $html, array $args ): string {
    return apollo_related_posts_html( absint($args['post_id'] ?? get_the_ID()), $args['heading'] ?? '' );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-related', __( '🔗 Related Content', 'apollo-plugin' ), 'apollo_related_meta_box', 'post', 'side', 'low' );
} );

function apollo_related_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_related_' . $post->ID, 'apollo_related_nonce' );
    $manual = json_decode( (string) get_post_meta( $post->ID, '_apollo_related_posts', true ), true ) ?? [];
    echo '<p style="font-size:12px;color:#666">' . esc_html__( 'Enter post IDs (comma-separated) for manual related posts. Leave blank for automatic.', 'apollo-plugin' ) . '</p>';
    echo '<input type="text" name="apollo_related_ids" value="' . esc_attr( implode( ', ', array_map('intval', $manual) ) ) . '" style="width:100%" placeholder="123, 456, 789">';
}

add_action( 'save_post_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_related_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_related_nonce'] ) ), 'apollo_related_' . $post_id ) ) return;
    $raw  = sanitize_text_field( wp_unslash( $_POST['apollo_related_ids'] ?? '' ) );
    $ids  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
    update_post_meta( $post_id, '_apollo_related_posts', wp_json_encode( array_values( $ids ) ) );
} );

// ──────────────────────────────────────────────────────
// MOST READ / TRENDING (Feature 10)
// ──────────────────────────────────────────────────────

function apollo_record_view( int $post_id ): void {
    if ( is_admin() || wp_is_json_request() ) return;
    $today = gmdate( 'Y-m-d' );
    $week  = gmdate( 'Y-W' );

    $views_today = (int) get_post_meta( $post_id, '_apollo_views_today', true );
    $views_week  = (int) get_post_meta( $post_id, '_apollo_views_week', true );
    $views_all   = (int) get_post_meta( $post_id, '_apollo_views_all', true );
    $today_key   = (string) get_post_meta( $post_id, '_apollo_views_today_date', true );
    $week_key    = (string) get_post_meta( $post_id, '_apollo_views_week_num', true );

    if ( $today_key !== $today ) { $views_today = 0; update_post_meta( $post_id, '_apollo_views_today_date', $today ); }
    if ( $week_key  !== $week  ) { $views_week  = 0; update_post_meta( $post_id, '_apollo_views_week_num',  $week );  }

    update_post_meta( $post_id, '_apollo_views_today', $views_today + 1 );
    update_post_meta( $post_id, '_apollo_views_week',  $views_week + 1 );
    update_post_meta( $post_id, '_apollo_views_all',   $views_all + 1 );
}

add_action( 'wp', function(): void {
    if ( is_singular( 'post' ) && ! is_preview() ) {
        apollo_record_view( get_the_ID() ?: 0 );
    }
} );

function apollo_get_most_read( string $period = 'week', int $count = 5 ): array {
    $meta_key = match( $period ) {
        'today' => '_apollo_views_today',
        'all'   => '_apollo_views_all',
        default => '_apollo_views_week',
    };
    return get_posts( [
        'posts_per_page' => $count,
        'meta_key'       => $meta_key,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'ignore_sticky_posts' => true,
    ] );
}

function apollo_most_read_html( string $period = 'week', int $count = 5, string $heading = '' ): string {
    $posts = apollo_get_most_read( $period, $count );
    if ( empty( $posts ) ) return '';
    $heading = $heading ?: __( 'Most Read', 'apollo-plugin' );
    $out  = '<section class="apollo-most-read" aria-label="' . esc_attr($heading) . '">';
    $out .= '<h3 class="apollo-most-read__heading">' . esc_html($heading) . '</h3>';
    $out .= '<ol class="apollo-most-read__list">';
    foreach ( $posts as $p ) {
        $out .= '<li class="apollo-most-read__item"><a href="' . get_permalink($p->ID) . '">' . esc_html(get_the_title($p->ID)) . '</a></li>';
    }
    $out .= '</ol></section>';
    return $out;
}

add_filter( 'apollo_render_most-read', function( $html, array $args ): string {
    return apollo_most_read_html( $args['period'] ?? 'week', (int)($args['count'] ?? 5), $args['heading'] ?? '' );
}, 10, 2 );

add_shortcode( 'apollo_most_read', function( array $atts ): string {
    $atts = shortcode_atts( [ 'period' => 'week', 'count' => 5, 'heading' => '' ], $atts );
    return apollo_most_read_html( $atts['period'], (int)$atts['count'], $atts['heading'] );
} );
