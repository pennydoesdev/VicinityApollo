<?php
/**
 * Sponsored Content Label
 *
 * Marks posts as sponsored via the SEO & Open Graph sidebar panel
 * (toggle stored in _serve_sponsored meta). Appends a "Sponsored"
 * label to the post title on the frontend and shows a $ badge in
 * the admin posts list.
 *
 * @package Apollo
 */

defined( 'ABSPATH' ) || exit;

// ── 1. FRONTEND TITLE LABEL ──────────────────────────────────
add_filter( 'the_title', function( string $title, int $post_id = 0 ): string {
    if ( ! $post_id ) return $title;
    if ( ! get_post_meta( $post_id, '_serve_sponsored', true ) ) return $title;
    if ( is_admin() ) return $title;
    if ( is_feed() )  return $title;
    if ( ! did_action( 'wp' ) ) return $title;

    $label = '<span class="serve-sponsored-label" aria-label="' . esc_attr__( 'Sponsored', 'serve' ) . '">'
           . '<span aria-hidden="true">$</span>'
           . ' ' . esc_html__( 'Sponsored', 'serve' )
           . '</span>';

    return $title . ' ' . $label;
}, 10, 2 );

// ── 2. FRONTEND CSS ───────────────────────────────────────────
add_action( 'wp_head', function(): void {
    global $wp_query;
    $needs_css = false;

    if ( is_singular() ) {
        $needs_css = (bool) get_post_meta( get_queried_object_id(), '_serve_sponsored', true );
    } elseif ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
        foreach ( $wp_query->posts as $p ) {
            if ( get_post_meta( $p->ID, '_serve_sponsored', true ) ) {
                $needs_css = true;
                break;
            }
        }
    }

    if ( ! $needs_css ) return;
    ?>
<style id="serve-sponsored-css">
.serve-sponsored-label{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;font-family:var(--flavor-font-ui,system-ui);font-size:.65em;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#7a5800;background:#fff8dc;border:1px solid #f5c400;border-radius:3px;vertical-align:middle;line-height:1.5;white-space:nowrap;margin-left:.35em}
.serve-sponsored-label [aria-hidden]{font-size:.9em;opacity:.8}
</style>
    <?php
}, 20 );

// ── 3. ADMIN POSTS LIST — $ BADGE ────────────────────────────
add_filter( 'the_title', function( string $title, int $post_id = 0 ): string {
    if ( ! $post_id ) return $title;
    if ( ! is_admin() ) return $title;
    if ( ! get_post_meta( $post_id, '_serve_sponsored', true ) ) return $title;
    $screen = get_current_screen();
    if ( ! $screen || $screen->base !== 'edit' ) return $title;
    return '<span style="display:inline-flex;align-items:center;gap:5px;">'
         . '<span style="display:inline-flex;align-items:center;justify-content:center;'
         . 'width:16px;height:16px;background:#f5c400;color:#7a5800;font-size:10px;'
         . 'font-weight:800;border-radius:3px;flex-shrink:0;line-height:1;" title="Sponsored">$</span>'
         . $title
         . '</span>';
}, 10, 2 );

// ── 4. ADMIN COLUMN INDICATOR ─────────────────────────────────
add_filter( 'manage_posts_columns', function( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) $new['serve_sponsored'] = '<span title="Sponsored" style="cursor:help;">$</span>';
    }
    return $new;
} );

add_action( 'manage_posts_custom_column', function( string $col, int $post_id ): void {
    if ( $col !== 'serve_sponsored' ) return;
    if ( get_post_meta( $post_id, '_serve_sponsored', true ) ) {
        echo '<span style="display:inline-flex;align-items:center;justify-content:center;'
           . 'width:20px;height:20px;background:#f5c400;color:#7a5800;font-size:11px;'
           . 'font-weight:800;border-radius:3px;" title="Sponsored">$</span>';
    }
}, 10, 2 );

add_filter( 'manage_posts_sortable_columns', function( array $cols ): array {
    $cols['serve_sponsored'] = 'serve_sponsored';
    return $cols;
} );

add_action( 'pre_get_posts', function( WP_Query $q ): void {
    if ( ! is_admin() || ! $q->is_main_query() ) return;
    if ( $q->get( 'orderby' ) !== 'serve_sponsored' ) return;
    $q->set( 'meta_key', '_serve_sponsored' );
    $q->set( 'orderby', 'meta_value' );
} );

// ── 5. ADMIN QUICK FILTER ─────────────────────────────────────
add_filter( 'views_edit-post', function( array $views ): array {
    $count = (int) ( new WP_Query( [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'meta_key'       => '_serve_sponsored',
        'meta_value'     => '1',
        'no_found_rows'  => false,
        'fields'         => 'ids',
        'posts_per_page' => -1,
    ] ) )->found_posts;

    if ( $count < 1 ) return $views;

    $current = isset( $_GET['sponsored'] ) && $_GET['sponsored'] === '1';
    $url     = add_query_arg( [ 'post_type' => 'post', 'sponsored' => '1' ], admin_url( 'edit.php' ) );
    $label   = sprintf(
        '<span style="font-weight:%s">$ Sponsored <span class="count">(%d)</span></span>',
        $current ? '700' : '400',
        $count
    );
    $views['serve_sponsored'] = '<a href="' . esc_url( $url ) . '"' . ( $current ? ' class="current"' : '' ) . '>' . $label . '</a>';
    return $views;
} );

add_action( 'pre_get_posts', function( WP_Query $q ): void {
    if ( ! is_admin() || ! $q->is_main_query() ) return;
    if ( empty( $_GET['sponsored'] ) ) return;
    $q->set( 'meta_key',   '_serve_sponsored' );
    $q->set( 'meta_value', '1' );
} );

// ── 6. SAVE FROM CLASSIC EDITOR ──────────────────────────────
add_action( 'save_post', function( int $post_id ): void {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( ! isset( $_POST['serve_sponsored_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['serve_sponsored_nonce'], 'serve_sponsored_save' ) ) return;

    if ( ! empty( $_POST['_serve_sponsored'] ) ) {
        update_post_meta( $post_id, '_serve_sponsored', '1' );
    } else {
        delete_post_meta( $post_id, '_serve_sponsored' );
    }
} );

add_action( 'add_meta_boxes', function(): void {
    if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'post' ) ) return;
    add_meta_box(
        'serve_sponsored_box',
        '$ Sponsored',
        function( WP_Post $post ): void {
            wp_nonce_field( 'serve_sponsored_save', 'serve_sponsored_nonce' );
            $checked = get_post_meta( $post->ID, '_serve_sponsored', true );
            echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;">';
            echo '<input type="checkbox" name="_serve_sponsored" value="1"' . checked( $checked, '1', false ) . '>';
            echo '<span><strong>Mark as Sponsored</strong> — appends a "Sponsored" label to the article title.</span>';
            echo '</label>';
        },
        'post',
        'side',
        'high'
    );
} );
