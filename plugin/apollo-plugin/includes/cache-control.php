<?php

defined( 'ABSPATH' ) || exit;

function serve_cc_is_frontend(): bool {
    return ! ( is_admin() || is_feed() || is_preview() || wp_doing_ajax() || wp_doing_cron() );
}

function serve_cc_cf_ready(): bool {
    $c = function_exists( 'serve_cf_get_config' ) ? serve_cf_get_config() : [];
    return ! empty( $c['api_token'] ) && ! empty( $c['zone_id'] );
}

function serve_cc_resolve_ttl( ?int $post_id = null ): array {
    $perf     = function_exists( 'serve_perf_get' ) ? serve_perf_get() : [];
    $cf       = function_exists( 'serve_cf_get_config' ) ? serve_cf_get_config() : [];

    $homepage_ttl = (int) ( $perf['cache_homepage_ttl'] ?? 300 );
    $page_ttl     = (int) ( $perf['cache_page_ttl']     ?? 3600 );
    $edge_ttl     = (int) ( $perf['cf_edge_ttl']        ?? 86400 );
    $browser_ttl  = (int) ( $cf['browser_ttl']          ?? 14400 );

    if ( $post_id ) {
        $custom = (int) get_post_meta( $post_id, '_serve_cache_ttl', true );
        if ( $custom > 0 ) {
            return [
                'browser'  => $custom,
                'edge'     => $custom,
                'swr'      => min( $custom * 2, 86400 ),
                'source'   => 'post',
            ];
        }
    }

    if ( is_front_page() ) {
        return [
            'browser' => $homepage_ttl,
            'edge'    => $edge_ttl,
            'swr'     => min( $homepage_ttl * 2, 86400 ),
            'source'  => 'homepage',
        ];
    }

    if ( is_singular() ) {
        return [
            'browser' => $browser_ttl,
            'edge'    => $edge_ttl,
            'swr'     => min( $page_ttl * 2, 86400 ),
            'source'  => 'singular',
        ];
    }

    return [
        'browser' => $browser_ttl,
        'edge'    => $edge_ttl,
        'swr'     => min( $page_ttl * 2, 86400 ),
        'source'  => 'default',
    ];
}

function serve_cc_build_tags( ?int $post_id = null ): array {
    $tags = [ 'serve', 'apollo-plugin' ];

    if ( is_front_page() ) {
        $tags[] = 'homepage';
        $tags[] = 'footer';
    }

    if ( is_singular() ) {
        $pid = $post_id ?: get_the_ID();
        if ( $pid ) {
            $tags[] = 'post-' . $pid;
            $tags[] = 'type-' . get_post_type( $pid );
            foreach ( get_the_category( $pid ) as $cat ) {
                $tags[] = 'cat-' . $cat->term_id;
            }
            $author = (int) get_post_field( 'post_author', $pid );
            if ( $author ) $tags[] = 'author-' . $author;
        }
    }

    if ( is_category() ) {
        $tags[] = 'cat-' . get_queried_object_id();
        $tags[] = 'category-page';
    }
    if ( is_tag() )    $tags[] = 'tag-'    . get_queried_object_id();
    if ( is_author() ) $tags[] = 'author-' . get_queried_object_id();
    if ( is_archive() ) $tags[] = 'archive';
    if ( is_search() )  $tags[] = 'search';

    return array_unique( $tags );
}

add_action( 'send_headers', 'serve_cc_emit_headers', 5 );

function serve_cc_emit_headers(): void {
    if ( ! serve_cc_is_frontend() ) return;

    if ( ! get_theme_mod( 'flavor_cache_enabled', true ) ) return;

    $cf = function_exists( 'serve_cf_get_config' ) ? serve_cf_get_config() : [];

    if ( ! empty( $cf['dev_mode'] ) ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'X-Serve-Cache-Reason: dev-mode' );
        return;
    }

    if ( is_user_logged_in() ) {
        header( 'Cache-Control: private, no-cache, must-revalidate, max-age=0' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'Surrogate-Control: no-store' );
        header( 'X-Serve-Cache-Reason: logged-in' );
        return;
    }

    if ( is_search() ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'X-Serve-Cache-Reason: search' );
        return;
    }
    if ( is_404() ) {
        header( 'Cache-Control: public, max-age=60, s-maxage=300, stale-if-error=600' );
        header( 'Cloudflare-CDN-Cache-Control: public, max-age=300' );
        header( 'X-Serve-Cache-Reason: 404' );
        return;
    }

    $post_id = is_singular() ? (int) get_the_ID() : null;
    if ( $post_id && get_post_meta( $post_id, '_serve_cache_exclude', true ) ) {
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'Surrogate-Control: no-store' );
        header( 'X-Serve-Cache-Reason: post-excluded' );
        return;
    }
    if ( $post_id && post_password_required( $post_id ) ) {
        header( 'Cache-Control: private, no-cache, must-revalidate' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'X-Serve-Cache-Reason: password-protected' );
        return;
    }

    $ttl  = serve_cc_resolve_ttl( $post_id );
    $tags = serve_cc_build_tags( $post_id );

    if ( is_front_page() )          header( 'X-Serve-Page-Type: homepage' );
    elseif ( is_singular( 'post' ) ) header( 'X-Serve-Page-Type: post' );
    elseif ( is_singular( 'page' ) ) header( 'X-Serve-Page-Type: page' );
    elseif ( is_archive() )          header( 'X-Serve-Page-Type: archive' );

    header( 'Vary: Accept-Encoding' );

    $browser = $ttl['browser'];
    $edge    = $ttl['edge'];
    $swr = 86400;

    header( 'Cache-Control: public, max-age=' . $browser
        . ', s-maxage=' . $edge
        . ', stale-while-revalidate=' . $swr
        . ', stale-if-error=86400'
    );

    header( 'Cloudflare-CDN-Cache-Control: public, max-age=' . $edge
        . ', stale-while-revalidate=' . $swr );

    header( 'Surrogate-Control: max-age=' . $edge );

    header( 'Cache-Tag: ' . implode( ',', $tags ) );
    header( 'Surrogate-Key: ' . implode( ' ', $tags ) );

    header( 'X-Serve-Cache-Reason: ' . $ttl['source']
        . ' browser=' . $browser . 's edge=' . $edge . 's swr=' . $swr . 's' );
}

add_action( 'send_headers', function (): void {
    if ( is_admin() ) return;

    $cf = function_exists( 'serve_cf_get_config' ) ? serve_cf_get_config() : [];
    if ( ! empty( $cf['dev_mode'] ) ) return;

    if ( ! is_404() && ! is_singular() && ! is_front_page() && ! is_archive() ) return;

    header( 'Accept-Ranges: bytes' );
} );

add_action( 'init', function (): void {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
    if ( is_user_logged_in() ) return;
    if ( str_contains( $_SERVER['REQUEST_URI'] ?? '', 'wp-login' ) ) return;
    if ( isset( $_COOKIE['wordpress_test_cookie'] ) ) {
        setcookie( 'wordpress_test_cookie', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
        unset( $_COOKIE['wordpress_test_cookie'] );
    }
}, 1 );

remove_action( 'save_post', 'serve_cf_auto_purge_on_save', 100 );

add_action( 'save_post', function ( int $post_id ): void {
    if ( ! serve_cc_cf_ready() ) return;
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
    if ( get_post_status( $post_id ) !== 'publish' ) return;

    if ( ! function_exists( 'serve_cf_get_config' ) ) return;
    $c = serve_cf_get_config();
    if ( empty( $c['auto_purge'] ) ) return;

    $urls = serve_cc_post_urls( $post_id );
    if ( ! empty( $urls ) ) {
        serve_cf_api_purge_urls( array_unique( $urls ) );
    }
}, 50 );

function serve_cc_post_urls( int $post_id ): array {
    $urls = [];

    $permalink = get_permalink( $post_id );
    if ( $permalink ) $urls[] = $permalink;

    $urls[] = home_url( '/' );
    $urls[] = get_feed_link();

    foreach ( get_the_category( $post_id ) as $cat ) {
        $u = get_category_link( $cat->term_id );
        if ( $u ) $urls[] = $u;
    }
    $tags = get_the_tags( $post_id );
    if ( $tags ) {
        foreach ( $tags as $tag ) {
            $u = get_tag_link( $tag->term_id );
            if ( $u ) $urls[] = $u;
        }
    }

    $author_id = (int) get_post_field( 'post_author', $post_id );
    if ( $author_id ) {
        $u = get_author_posts_url( $author_id );
        if ( $u ) $urls[] = $u;
    }

    $pt_archive = get_post_type_archive_link( get_post_type( $post_id ) );
    if ( $pt_archive ) $urls[] = $pt_archive;

    return array_filter( $urls );
}

add_action( 'init', static function (): void {
    $public_types = array_keys( get_post_types( [ 'public' => true ] ) );
    $auth = static function (): bool { return current_user_can( 'edit_posts' ); };

    foreach ( $public_types as $pt ) {
        register_post_meta( $pt, '_serve_cache_exclude', [
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'string',
            'default'           => '',
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback'     => $auth,
        ] );
        register_post_meta( $pt, '_serve_cache_ttl', [
            'show_in_rest'      => true,
            'single'            => true,
            'type'              => 'integer',
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => $auth,
        ] );
    }
}, 20 );

function serve_cc_meta_box_html( WP_Post $post ): void {
    $exclude   = (bool) get_post_meta( $post->ID, '_serve_cache_exclude', true );
    $custom_ttl = (int) get_post_meta( $post->ID, '_serve_cache_ttl', true );
    $nonce     = wp_create_nonce( 'serve_cc_meta_' . $post->ID );
    $cf_ready  = serve_cc_cf_ready();
    $permalink = get_permalink( $post->ID );
    $is_published = get_post_status( $post->ID ) === 'publish';

    $ttl  = serve_cc_resolve_ttl( $post->ID );
    $effective_browser = $custom_ttl > 0 ? $custom_ttl : $ttl['browser'];
    $effective_edge    = $custom_ttl > 0 ? $custom_ttl : $ttl['edge'];
    ?>
    <input type="hidden" name="serve_cc_nonce" value="<?php echo esc_attr( $nonce ); ?>">
    <div style="font-size:12px;line-height:1.6;">
        <?php if ( $is_published && $cf_ready ) : ?>
        <div style="margin-bottom:10px;padding-bottom:10px;border-bottom:1px solid #eee;">
            <button type="button" class="button button-small" id="serve-cc-purge-btn"
                    data-post-id="<?php echo esc_attr( $post->ID ); ?>"
                    data-nonce="<?php echo esc_attr( wp_create_nonce( 'serve_cc_purge_' . $post->ID ) ); ?>"
                    style="width:100%;justify-content:center;">
                🔄 <?php esc_html_e( 'Purge Cache for This Page', 'serve' ); ?>
            </button>
            <span id="serve-cc-purge-result" style="display:none;font-size:11px;margin-top:4px;display:block;text-align:center;"></span>
        </div>
        <?php elseif ( ! $cf_ready ) : ?>
        <p style="color:#888;margin:0 0 10px;font-size:11px;">
            <?php esc_html_e( 'Set up Cloudflare credentials in Appearance → Cloudflare to enable cache purging.', 'serve' ); ?>
        </p>
        <?php endif; ?>
        <label style="display:flex;align-items:flex-start;gap:6px;cursor:pointer;margin-bottom:10px;">
            <input type="checkbox" name="serve_cache_exclude" value="1"
                   style="margin-top:2px;"
                   <?php checked( $exclude ); ?>>
            <span>
                <strong><?php esc_html_e( 'Exclude from cache', 'serve' ); ?></strong><br>
                <span style="color:#888;"><?php esc_html_e( 'Sends no-cache headers.', 'serve' ); ?></span>
            </span>
        </label>
        <div id="serve-cc-ttl-wrap" style="<?php echo $exclude ? 'display:none;' : ''; ?>">
            <label for="serve_cache_ttl_field" style="font-weight:600;display:block;margin-bottom:3px;">
                <?php esc_html_e( 'Custom TTL (seconds)', 'serve' ); ?>
            </label>
            <input type="number" id="serve_cache_ttl_field" name="serve_cache_ttl"
                   value="<?php echo esc_attr( $custom_ttl ?: '' ); ?>"
                   placeholder="<?php echo esc_attr( $ttl['browser'] ); ?>"
                   min="0" max="31536000"
                   style="width:100%;box-sizing:border-box;">
        </div>
    </div>
    <?php
}

add_action( 'save_post', function ( int $post_id ): void {
    if ( ! isset( $_POST['serve_cc_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_key( $_POST['serve_cc_nonce'] ), 'serve_cc_meta_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;

    $exclude = ! empty( $_POST['serve_cache_exclude'] );
    $exclude
        ? update_post_meta( $post_id, '_serve_cache_exclude', 1 )
        : delete_post_meta( $post_id, '_serve_cache_exclude' );

    $ttl = max( 0, absint( $_POST['serve_cache_ttl'] ?? 0 ) );
    $ttl > 0
        ? update_post_meta( $post_id, '_serve_cache_ttl', $ttl )
        : delete_post_meta( $post_id, '_serve_cache_ttl' );
} );

add_action( 'wp_ajax_serve_cc_purge_post', function (): void {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) wp_send_json_error( __( 'Invalid post ID.', 'serve' ) );

    check_ajax_referer( 'serve_cc_purge_' . $post_id, 'nonce' );

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( __( 'Permission denied.', 'serve' ) );
    }

    if ( ! serve_cc_cf_ready() ) {
        wp_send_json_error( __( 'Cloudflare credentials not configured.', 'serve' ) );
    }

    $urls   = serve_cc_post_urls( $post_id );
    $result = serve_cf_api_purge_urls( array_unique( $urls ) );

    if ( $result === true ) {
        wp_send_json_success( [
            'message' => sprintf(
                _n( 'Purged %d URL from Cloudflare cache.', 'Purged %d URLs from Cloudflare cache.', count( $urls ), 'serve' ),
                count( $urls )
            ),
            'urls'    => $urls,
        ] );
    } else {
        wp_send_json_error( is_string( $result ) ? $result : __( 'Cloudflare API error.', 'serve' ) );
    }
} );

add_filter( 'post_row_actions',  'serve_cc_row_action', 10, 2 );
add_filter( 'page_row_actions',  'serve_cc_row_action', 10, 2 );

function serve_cc_row_action( array $actions, WP_Post $post ): array {
    if ( ! serve_cc_cf_ready() ) return $actions;
    if ( get_post_status( $post->ID ) !== 'publish' ) return $actions;
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return $actions;

    $nonce = wp_create_nonce( 'serve_cc_row_purge_' . $post->ID );
    $url   = admin_url( 'admin-post.php?action=serve_cc_row_purge&post_id=' . $post->ID . '&_wpnonce=' . $nonce );

    $excluded = get_post_meta( $post->ID, '_serve_cache_exclude', true );
    if ( $excluded ) {
        $actions['serve_cc_excluded'] = '<span style="color:#888;font-size:11px;">⊘ ' . esc_html__( 'cache excluded', 'serve' ) . '</span>';
    }

    $actions['serve_cc_purge'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Purge CF Cache', 'serve' ) . '</a>';

    return $actions;
}

add_action( 'admin_post_serve_cc_row_purge', function (): void {
    $post_id = absint( $_GET['post_id'] ?? 0 );
    if ( ! $post_id ) wp_die( 'Invalid post.' );

    check_admin_referer( 'serve_cc_row_purge_' . $post_id );

    if ( ! current_user_can( 'edit_post', $post_id ) ) wp_die( 'Permission denied.' );

    $urls   = serve_cc_post_urls( $post_id );
    $result = serve_cf_api_purge_urls( array_unique( $urls ) );

    $redirect = wp_get_referer() ?: admin_url( 'edit.php' );
    $msg = $result === true ? 'serve_cc_purged' : 'serve_cc_purge_failed';
    wp_redirect( add_query_arg( $msg, '1', $redirect ) );
    exit;
} );

add_action( 'admin_notices', function (): void {
    if ( isset( $_GET['serve_cc_purged'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__( 'Cloudflare cache purged for this post.', 'serve' )
            . '</p></div>';
    }
    if ( isset( $_GET['serve_cc_purge_failed'] ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>'
            . esc_html__( 'Cloudflare cache purge failed. Check credentials in Appearance → Cloudflare.', 'serve' )
            . '</p></div>';
    }
} );

add_action( 'init', function (): void {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) return;
    if ( headers_sent() ) return;
    if ( ini_get( 'zlib.output_compression' ) ) return;
    if ( extension_loaded( 'zlib' ) && function_exists( 'ob_gzhandler' ) ) {
        ob_start( 'ob_gzhandler' );
    }
}, 1 );

add_filter( 'manage_posts_columns',       'serve_cc_add_column' );
add_filter( 'manage_pages_columns',       'serve_cc_add_column' );
add_action( 'manage_posts_custom_column', 'serve_cc_render_column', 10, 2 );
add_action( 'manage_pages_custom_column', 'serve_cc_render_column', 10, 2 );

function serve_cc_add_column( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[ $k ] = $v;
        if ( $k === 'title' ) $new['serve_cc'] = '<abbr title="' . esc_attr__( 'Cache Control', 'serve' ) . '">Cache</abbr>';
    }
    return $new;
}

function serve_cc_render_column( string $col, int $post_id ): void {
    if ( $col !== 'serve_cc' ) return;
    $exclude = get_post_meta( $post_id, '_serve_cache_exclude', true );
    $ttl     = (int) get_post_meta( $post_id, '_serve_cache_ttl',     true );

    if ( $exclude ) {
        echo '<span style="color:#c62828;font-size:11px;" title="' . esc_attr__( 'Excluded from cache', 'serve' ) . '">⊘ no-cache</span>';
    } elseif ( $ttl > 0 ) {
        echo '<span style="color:#0073aa;font-size:11px;" title="' . esc_attr__( 'Custom TTL', 'serve' ) . '">⏱ ' . esc_html( $ttl ) . 's</span>';
    } else {
        echo '<span style="color:#aaa;font-size:11px;">—</span>';
    }
}
