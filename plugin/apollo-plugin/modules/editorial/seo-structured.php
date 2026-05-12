<?php
/**
 * Apollo — Feature 7: SEO & Structured Data
 *
 * SEO fields + JSON-LD structured data (NewsArticle, VideoObject, AudioObject,
 * BreadcrumbList, Organization, Person).
 * Includes a toggle to disable Apollo SEO output if Yoast/RankMath is active.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ── Disable if another SEO plugin is active
function apollo_seo_active(): bool {
    if ( get_option( 'apollo_disable_seo', false ) ) return false;
    $others = [
        'wordpress-seo/wp-seo.php',
        'wordpress-seo-premium/wp-seo-premium.php',
        'seo-by-rank-math/rank-math.php',
        'all-in-one-seo-pack/all_in_one_seo_pack.php',
    ];
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    foreach ( $others as $p ) {
        if ( is_plugin_active( $p ) ) return false;
    }
    return true;
}

// ── Register meta
add_action( 'init', function(): void {
    $metas = [
        '_apollo_seo_title'       => 'string',
        '_apollo_seo_description' => 'string',
        '_apollo_og_title'        => 'string',
        '_apollo_og_description'  => 'string',
        '_apollo_canonical_url'   => 'string',
        '_apollo_noindex'         => 'boolean',
        '_apollo_news_keywords'   => 'string',
        '_apollo_article_section' => 'string',
        '_apollo_is_free'         => 'boolean',
        '_apollo_primary_image_id'=> 'integer',
    ];
    foreach ( [ 'post', 'page', 'serve_video', 'serve_episode', 'serve_podcast' ] as $pt ) {
        foreach ( $metas as $key => $type ) {
            register_post_meta( $pt, $key, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => $type,
                'auth_callback' => fn() => current_user_can( 'edit_posts' ),
            ] );
        }
    }
} );

// ── Meta box
add_action( 'add_meta_boxes', function(): void {
    if ( ! apollo_seo_active() ) return;
    $screens = [ 'post', 'page', 'serve_video', 'serve_episode', 'serve_podcast' ];
    add_meta_box( 'apollo-seo', __( '🔍 SEO & Social', 'apollo-plugin' ), 'apollo_seo_meta_box', $screens, 'normal', 'low' );
} );

function apollo_seo_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_seo_' . $post->ID, 'apollo_seo_nonce' );
    $fields = [
        '_apollo_seo_title'       => __( 'SEO Title', 'apollo-plugin' ),
        '_apollo_seo_description' => __( 'SEO Description', 'apollo-plugin' ),
        '_apollo_og_title'        => __( 'Social (OG) Title', 'apollo-plugin' ),
        '_apollo_og_description'  => __( 'Social Description', 'apollo-plugin' ),
        '_apollo_canonical_url'   => __( 'Canonical URL', 'apollo-plugin' ),
        '_apollo_news_keywords'   => __( 'News Keywords (comma-separated)', 'apollo-plugin' ),
        '_apollo_article_section' => __( 'Article Section', 'apollo-plugin' ),
    ];
    echo '<table class="form-table" style="margin:0"><tbody>';
    foreach ( $fields as $key => $label ) {
        $val = (string) get_post_meta( $post->ID, $key, true );
        $input = str_contains( $key, 'description' ) || str_contains( $key, 'keywords' )
            ? '<textarea name="' . esc_attr($key) . '" rows="2" style="width:100%">' . esc_textarea($val) . '</textarea>'
            : '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%">';
        echo '<tr><th style="width:160px">' . esc_html($label) . '</th><td>' . $input . '</td></tr>';
    }
    $noindex = get_post_meta( $post->ID, '_apollo_noindex', true );
    $is_free = get_post_meta( $post->ID, '_apollo_is_free', true );
    echo '<tr><th></th><td>';
    echo '<label><input type="checkbox" name="_apollo_noindex" value="1" ' . checked($noindex,true,false) . '> ' . esc_html__('Noindex','apollo-plugin') . '</label> &nbsp; ';
    echo '<label><input type="checkbox" name="_apollo_is_free" value="1" ' . checked($is_free,true,false) . '> ' . esc_html__('Free article (no paywall)','apollo-plugin') . '</label>';
    echo '</td></tr></tbody></table>';
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_seo_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_seo_nonce'] ) ), 'apollo_seo_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $string_fields = [ '_apollo_seo_title', '_apollo_seo_description', '_apollo_og_title', '_apollo_og_description', '_apollo_canonical_url', '_apollo_news_keywords', '_apollo_article_section' ];
    foreach ( $string_fields as $key ) {
        $val = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) );
        update_post_meta( $post_id, $key, $val );
    }
    update_post_meta( $post_id, '_apollo_noindex', ! empty( $_POST['_apollo_noindex'] ) );
    update_post_meta( $post_id, '_apollo_is_free', ! empty( $_POST['_apollo_is_free'] ) );
} );

// ── Output meta tags
add_action( 'wp_head', function(): void {
    if ( ! apollo_seo_active() ) return;
    if ( ! is_singular() ) return;

    $post_id = get_the_ID();
    if ( ! $post_id ) return;

    $seo_title = (string) get_post_meta( $post_id, '_apollo_seo_title', true );
    $seo_desc  = (string) get_post_meta( $post_id, '_apollo_seo_description', true );
    $og_title  = (string) get_post_meta( $post_id, '_apollo_og_title', true ) ?: ( $seo_title ?: get_the_title( $post_id ) );
    $og_desc   = (string) get_post_meta( $post_id, '_apollo_og_description', true ) ?: $seo_desc;
    $canonical = (string) get_post_meta( $post_id, '_apollo_canonical_url', true ) ?: get_permalink( $post_id );
    $noindex   = (bool)   get_post_meta( $post_id, '_apollo_noindex', true );
    $thumb_id  = (int)    get_post_meta( $post_id, '_apollo_primary_image_id', true ) ?: get_post_thumbnail_id( $post_id );
    $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

    if ( $seo_desc ) echo '<meta name="description" content="' . esc_attr( $seo_desc ) . '">' . "\n";
    if ( $noindex )  echo '<meta name="robots" content="noindex,nofollow">' . "\n";
    if ( $canonical ) echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";

    // OG tags
    echo '<meta property="og:type" content="article">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
    if ( $og_desc )   echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
    if ( $canonical ) echo '<meta property="og:url" content="' . esc_url( $canonical ) . '">' . "\n";
    if ( $thumb_url ) echo '<meta property="og:image" content="' . esc_url( $thumb_url ) . '">' . "\n";

    // Twitter card
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '">' . "\n";
    if ( $og_desc )   echo '<meta name="twitter:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
    if ( $thumb_url ) echo '<meta name="twitter:image" content="' . esc_url( $thumb_url ) . '">' . "\n";
}, 1 );

// ── JSON-LD Structured Data
add_action( 'wp_head', function(): void {
    if ( ! apollo_seo_active() ) return;
    if ( ! is_singular() ) return;

    $post_id   = get_the_ID();
    $post      = get_post( $post_id );
    if ( ! $post ) return;

    $post_type = get_post_type( $post_id );
    $schema    = [];

    // Organization
    $org = [
        '@type' => 'Organization',
        'name'  => get_bloginfo( 'name' ),
        'url'   => home_url( '/' ),
        'logo'  => [ '@type' => 'ImageObject', 'url' => esc_url( get_site_icon_url( 512 ) ) ],
    ];

    if ( in_array( $post_type, [ 'post', 'page' ], true ) ) {
        $thumb_id  = get_post_thumbnail_id( $post_id );
        $thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';
        $section   = (string) get_post_meta( $post_id, '_apollo_article_section', true );
        $keywords  = (string) get_post_meta( $post_id, '_apollo_news_keywords', true );
        $is_free   = (bool)   get_post_meta( $post_id, '_apollo_is_free', true );

        $schema = [
            '@context'         => 'https://schema.org',
            '@type'            => 'NewsArticle',
            'headline'         => get_the_title( $post_id ),
            'description'      => (string) get_post_meta( $post_id, '_apollo_seo_description', true ) ?: wp_trim_words( strip_tags( $post->post_content ), 30 ),
            'url'              => get_permalink( $post_id ),
            'datePublished'    => get_post_time( 'c', true, $post ),
            'dateModified'     => get_post_modified_time( 'c', true, $post ),
            'publisher'        => $org,
            'isAccessibleForFree' => $is_free,
        ];
        if ( $thumb_url ) $schema['image'] = [ '@type' => 'ImageObject', 'url' => $thumb_url ];
        if ( $section )   $schema['articleSection'] = $section;
        if ( $keywords )  $schema['keywords'] = $keywords;

        $author_id = (int) $post->post_author;
        if ( $author_id ) {
            $schema['author'] = [
                '@type' => 'Person',
                'name'  => get_the_author_meta( 'display_name', $author_id ),
                'url'   => get_author_posts_url( $author_id ),
            ];
        }
    } elseif ( $post_type === 'serve_video' ) {
        $media_url = apollo_media_url( $post_id, 'video' );
        $duration  = (string) get_post_meta( $post_id, '_svh_duration', true );
        $thumb_url = get_the_post_thumbnail_url( $post_id, 'large' );
        $schema = [
            '@context'     => 'https://schema.org',
            '@type'        => 'VideoObject',
            'name'         => get_the_title( $post_id ),
            'description'  => get_the_excerpt( $post_id ) ?: '',
            'uploadDate'   => get_post_time( 'c', true, $post ),
            'publisher'    => $org,
        ];
        if ( $media_url ) $schema['contentUrl'] = $media_url;
        if ( $thumb_url ) $schema['thumbnailUrl'] = $thumb_url;
        if ( $duration )  $schema['duration'] = 'PT' . strtoupper( str_replace( ':', 'M', $duration ) ) . 'S';
    } elseif ( $post_type === 'serve_episode' ) {
        $audio_url = apollo_media_url( $post_id, 'audio' );
        $duration  = (string) get_post_meta( $post_id, '_ep_duration', true );
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'PodcastEpisode',
            'name'       => get_the_title( $post_id ),
            'description'=> get_the_excerpt( $post_id ) ?: '',
            'datePublished' => get_post_time( 'c', true, $post ),
            'publisher'  => $org,
        ];
        if ( $audio_url ) $schema['associatedMedia'] = [ '@type' => 'AudioObject', 'contentUrl' => $audio_url ];
    }

    if ( $schema ) {
        echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
    }

    // BreadcrumbList
    if ( is_singular( 'post' ) ) {
        $cats = get_the_category( $post_id );
        if ( $cats ) {
            $cat = $cats[0];
            $breadcrumb = [
                '@context' => 'https://schema.org',
                '@type'    => 'BreadcrumbList',
                'itemListElement' => [
                    [ '@type' => 'ListItem', 'position' => 1, 'name' => get_bloginfo('name'), 'item' => home_url('/') ],
                    [ '@type' => 'ListItem', 'position' => 2, 'name' => $cat->name, 'item' => get_category_link($cat->term_id) ],
                    [ '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title($post_id), 'item' => get_permalink($post_id) ],
                ],
            ];
            echo '<script type="application/ld+json">' . wp_json_encode( $breadcrumb, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
        }
    }
}, 5 );

// ── Setting to disable
add_action( 'admin_init', function(): void {
    register_setting( 'general', 'apollo_disable_seo', [ 'type' => 'boolean', 'sanitize_callback' => 'boolval' ] );
    add_settings_field( 'apollo_disable_seo', __( 'Apollo SEO Output', 'apollo-plugin' ),
        function(): void {
            echo '<label><input type="checkbox" name="apollo_disable_seo" value="1" ' . checked( get_option('apollo_disable_seo'), true, false ) . '> ' . esc_html__('Disable Apollo SEO output (use if Yoast/RankMath is active)', 'apollo-plugin') . '</label>';
        },
        'general', 'default'
    );
} );
