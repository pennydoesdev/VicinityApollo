<?php

defined( 'ABSPATH' ) || exit;

function serve_preload_lcp_image(): void {
    if ( is_admin() ) return;

    $thumb_id   = null;
    $image_size = 'flavor-hero';

    if ( is_singular() && has_post_thumbnail() ) {
        $thumb_id   = get_post_thumbnail_id();
        $image_size = 'flavor-wide';
    } elseif ( is_front_page() ) {
        $lead_cat = get_theme_mod( 'serve_th_left_lead_cat', '' );
        $args     = [ 'posts_per_page' => 1, 'no_found_rows' => true, 'fields' => 'ids' ];
        if ( $lead_cat ) {
            $term = get_term_by( 'slug', $lead_cat, 'category' );
            if ( $term ) $args['cat'] = $term->term_id;
        }
        $latest  = get_posts( $args );
        $hero_id = $latest[0] ?? null;
        if ( $hero_id && has_post_thumbnail( $hero_id ) ) {
            $thumb_id = get_post_thumbnail_id( $hero_id );
        }
    }

    if ( ! $thumb_id ) return;

    $tag = wp_get_attachment_image( $thumb_id, $image_size, false, [
        'fetchpriority' => 'high',
        'decoding'      => 'sync',
    ] );
    if ( ! $tag ) return;

    $src    = '';
    $srcset = '';
    $sizes  = '';
    if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/', $tag, $m ) )    $src    = $m[1];
    if ( preg_match( '/\bsrcset=["\']([^"\']+)["\']/', $tag, $m ) ) $srcset = $m[1];
    if ( preg_match( '/\bsizes=["\']([^"\']+)["\']/', $tag, $m ) )  $sizes  = $m[1];

    if ( ! $src ) return;

    $preload  = '<link rel="preload" as="image" fetchpriority="high"';
    $preload .= ' href="'    . esc_url( $src )        . '"';
    if ( $srcset ) $preload .= ' imagesrcset="' . esc_attr( $srcset ) . '"';
    if ( $sizes  ) $preload .= ' imagesizes="'  . esc_attr( $sizes  ) . '"';
    $preload .= '>' . "\n";

    echo $preload;
}
add_action( 'wp_head', 'serve_preload_lcp_image', 1 );

function serve_above_fold_critical_css(): void {
    if ( is_admin() ) return;
    static $done = false;
    if ( $done ) return;
    $done = true;

    $accent    = esc_attr( get_theme_mod( 'flavor_accent_color', '#C62828' ) );
    $text      = '#121212';
    $bg        = '#FFFFFF';
    $bg_alt    = '#F5F5F3';
    $border    = '#E0E0E0';
    $text_sec  = '#5A5A5A';
    $text_lt   = '#999999';
    $headline  = esc_attr( get_theme_mod( 'flavor_headline_font', 'Playfair Display' ) );
    $body      = esc_attr( get_theme_mod( 'flavor_body_font', 'Libre Franklin' ) );
    $nav_text  = esc_attr( get_theme_mod( 'flavor_nav_text_color', $text ) );
    $header_bg = esc_attr( get_theme_mod( 'flavor_header_bg', $bg ) );

    $critical = "
*,*::before,*::after{box-sizing:border-box}
html{font-size:16px;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}
html{overflow-x:hidden}body{margin:0;padding:0;font-family:'{$body}',system-ui,sans-serif;font-size:1rem;line-height:1.6;color:{$text};background:{$bg}}
a{color:{$accent};text-decoration:none}
img{max-width:100%;height:auto;display:block}
h1,h2,h3,h4,h5,h6{font-family:'{$headline}',Georgia,serif;font-weight:700;line-height:1.1;margin:0 0 .5em;color:{$text}}
.flavor-container{max-width:1280px;margin:0 auto;padding:0 1.25rem}
.site-header{background:{$header_bg};border-bottom:2px solid {$text};position:sticky;top:0;z-index:500;width:100%;left:0;right:0}
.site-title{font-family:'{$headline}',Georgia,serif;font-size:var(--serve-site-title-size,clamp(2rem,6vw,3.5rem));font-weight:var(--serve-site-title-weight,900);text-transform:var(--serve-site-title-transform,none);text-align:center;margin:0;line-height:1;letter-spacing:-.02em}
.site-title a{color:var(--serve-site-title-color,{$text});text-decoration:none}
.nav-menu{display:flex;list-style:none;margin:0;padding:0;justify-content:center;flex-wrap:wrap;gap:0;width:100%}
.nav-menu li a{display:block;padding:.75rem 1rem;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:{$nav_text};text-decoration:none}
.nav-menu li a:hover{color:{$accent}}
body.single{--flavor-narrow-width:960px}
.single-post-header{max-width:960px;margin:0 auto;padding:3rem 2rem 2rem;text-align:center}
.entry-content{max-width:960px;margin:0 auto;padding:0 2rem;font-size:1.1875rem;line-height:1.8}
.skip-link{position:absolute;top:-100%;left:0;z-index:10000;background:{$accent};color:#fff;padding:.75rem 1.5rem;font-size:.8125rem;font-weight:700}
.skip-link:focus{top:0}
.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}
@media(max-width:768px){
  .entry-content{padding:0 var(--flavor-gap,1.25rem)}
}
";
    echo '<style id="serve-critical-css">' . $critical . '</style>' . "\n";
}
add_action( 'wp_head', 'serve_above_fold_critical_css', 0 );
