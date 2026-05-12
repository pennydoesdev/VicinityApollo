<?php

defined( 'ABSPATH' ) || exit;

function serve_shortlink_edit_display() {
    add_action( 'edit_form_before_permalink', 'serve_shortlink_permalink_row' );
}
add_action( 'admin_init', 'serve_shortlink_edit_display' );

function serve_shortlink_permalink_row( $post ) {
    if ( $post->post_type !== 'post' && $post->post_type !== 'page' ) return;
    $link = get_post_meta( $post->ID, '_serve_shortlink', true );
    if ( ! $link ) return;
    ?>
    <div class="inside" style="padding:4px 0 8px;font-size:13px;">
        <span style="color:#666;">Short URL:</span>
        <input type="text" value="<?php echo esc_url( $link ); ?>" readonly
               onclick="this.select();document.execCommand('copy');"
               style="width:260px;font-size:12px;padding:2px 6px;border:1px solid #ddd;background:#f9f9f9;cursor:pointer;" />
        <span class="serve-copy-msg" style="color:#2e7d32;font-size:11px;display:none;margin-left:4px;">Copied!</span>
    </div>
    <script>
    jQuery(function($){
        $('input[readonly]').on('click',function(){
            var $msg=$(this).next('.serve-copy-msg');
            $msg.fadeIn(200).delay(1500).fadeOut(400);
        });
    });
    </script>
    <?php
}

function serve_shortlink_rest_meta() {
    register_post_meta( 'post', '_serve_shortlink', array(
        'show_in_rest'  => true,
        'single'        => true,
        'type'          => 'string',
        'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
    ) );
}
add_action( 'init', 'serve_shortlink_rest_meta' );

function serve_og_use_shortlink( $tags ) {
    if ( ! is_singular() ) return $tags;

    $shortlink = get_post_meta( get_the_ID(), '_serve_shortlink', true );
    if ( $shortlink ) {
        if ( isset( $tags['og:url'] ) ) {
            $tags['og:url'] = $shortlink;
        }
    }
    return $tags;
}

function serve_shortlink_og_url( $content ) {
    return $content;
}

function serve_inject_shortlink_into_og() {
    add_filter( 'serve_og_tags_built', function( $tags ) {
        if ( is_singular() ) {
            $shortlink = get_post_meta( get_the_ID(), '_serve_shortlink', true );
            if ( $shortlink ) {
                $tags['og:url'] = $shortlink;
            }
        }
        return $tags;
    } );
}
add_action( 'init', 'serve_inject_shortlink_into_og' );

function serve_global_shortlink( $shortlink, $id, $context, $allow_slugs ) {
    if ( ! $id ) return $shortlink;
    $custom = get_post_meta( $id, '_serve_shortlink', true );
    return $custom ? $custom : $shortlink;
}
add_filter( 'get_shortlink', 'serve_global_shortlink', 10, 4 );

function serve_adblocker_customizer( $wp_customize ) {
    if ( ! $wp_customize->get_section( 'flavor_google_ads' ) ) return;

    $wp_customize->add_setting( 'serve_adblocker_detect', [
        'default' => false, 'sanitize_callback' => 'flavor_sanitize_checkbox',
    ] );
    $wp_customize->add_control( 'serve_adblocker_detect', array(
        'label'       => esc_html__( 'Detect Ad Blockers', 'serve' ),
        'description' => esc_html__( 'Show a polite message asking visitors to whitelist your site when an ad blocker is detected.', 'serve' ),
        'section'     => 'flavor_google_ads',
        'type'        => 'checkbox',
    ) );

    $wp_customize->add_setting( 'serve_adblocker_message', [
        'default'           => "We rely on ads to keep our journalism free. Please consider whitelisting us in your ad blocker.",
        'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'serve_adblocker_message', array(
        'label'   => esc_html__( 'Ad Blocker Message', 'serve' ),
        'section' => 'flavor_google_ads',
        'type'    => 'textarea',
    ) );

    $wp_customize->add_setting( 'serve_adblocker_style', [
        'default' => 'banner', 'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'serve_adblocker_style', array(
        'label'   => esc_html__( 'Display Style', 'serve' ),
        'section' => 'flavor_google_ads',
        'type'    => 'select',
        'choices' => array(
            'banner' => esc_html__( 'Top Banner', 'serve' ),
            'inline' => esc_html__( 'Inline (where ads would appear)', 'serve' ),
        ),
    ) );
}
add_action( 'customize_register', 'serve_adblocker_customizer' );

function serve_adblocker_script() {
    if ( ! get_theme_mod( 'serve_adblocker_detect', false ) ) return;
    if ( is_admin() ) return;

    $message = esc_js( get_theme_mod( 'serve_adblocker_message', 'We rely on ads to keep our journalism free. Please consider whitelisting us in your ad blocker.' ) );
    $style   = get_theme_mod( 'serve_adblocker_style', 'banner' );
    ?>
    <script>
    (function(){
        var test=document.createElement('div');
        test.innerHTML='&nbsp;';
        test.className='adsbox ad-unit ad-zone textAd banner-ad';
        test.style.cssText='position:absolute;left:-9999px;height:1px;';
        document.body.appendChild(test);
        setTimeout(function(){
            if(!test.offsetHeight||test.offsetHeight===0||getComputedStyle(test).display==='none'){
                <?php if ( $style === 'banner' ) : ?>
                var b=document.createElement('div');
                b.style.cssText='position:fixed;bottom:0;left:0;right:0;background:#1a1a2e;color:#fff;padding:14px 20px;font-family:var(--flavor-font-ui),sans-serif;font-size:14px;text-align:center;z-index:9999;display:flex;align-items:center;justify-content:center;gap:12px;';
                b.innerHTML='<span><?php echo $message; ?></span><button onclick="this.parentNode.remove()" style="background:none;border:1px solid rgba(255,255,255,.3);color:#fff;padding:4px 14px;cursor:pointer;font-size:12px;border-radius:3px;">Dismiss</button>';
                document.body.appendChild(b);
                <?php else : ?>
                document.querySelectorAll('.flavor-ad-unit,.flavor-google-ad').forEach(function(el){
                    el.innerHTML='<div style="padding:1.5rem;text-align:center;background:#f5f5f5;border:1px dashed #ccc;font-family:var(--flavor-font-ui),sans-serif;font-size:13px;color:#666;"><?php echo $message; ?></div>';
                });
                <?php endif; ?>
            }
            test.remove();
        },200);
    })();
    </script>
    <?php
}
add_action( 'wp_footer', 'serve_adblocker_script', 999 );

function serve_search_customizer( $wp_customize ) {
    $wp_customize->add_section( 'serve_search_options', array(
        'title'       => esc_html__( 'Search', 'serve' ),
        'panel'       => 'flavor_theme_options',
        'priority'    => 200,
        'description' => esc_html__( 'Configure the site search experience.', 'serve' ),
    ) );

    $wp_customize->add_setting( 'serve_search_icon_header', [
        'default' => true, 'sanitize_callback' => 'flavor_sanitize_checkbox',
    ] );
    $wp_customize->add_control( 'serve_search_icon_header', [
        'label'       => esc_html__( 'Show Search Icon in Navigation', 'serve' ),
        'description' => esc_html__( 'Adds a search icon to the right side of the nav bar.', 'serve' ),
        'section'     => 'serve_search_options',
        'type'        => 'checkbox',
    ] );

    $wp_customize->add_setting( 'serve_search_placeholder', [
        'default' => 'Search stories, topics, authors…', 'sanitize_callback' => 'sanitize_text_field',
    ] );
    $wp_customize->add_control( 'serve_search_placeholder', [
        'label'   => esc_html__( 'Search Input Placeholder', 'serve' ),
        'section' => 'serve_search_options',
        'type'    => 'text',
    ] );

    $wp_customize->add_setting( 'serve_search_show_thumbs', [
        'default' => true, 'sanitize_callback' => 'flavor_sanitize_checkbox',
    ] );
    $wp_customize->add_control( 'serve_search_show_thumbs', [
        'label'   => esc_html__( 'Show Thumbnails in Results', 'serve' ),
        'section' => 'serve_search_options',
        'type'    => 'checkbox',
    ] );

    $wp_customize->add_setting( 'serve_search_show_cats', [
        'default' => true, 'sanitize_callback' => 'flavor_sanitize_checkbox',
    ] );
    $wp_customize->add_control( 'serve_search_show_cats', [
        'label'   => esc_html__( 'Show Category Labels in Results', 'serve' ),
        'section' => 'serve_search_options',
        'type'    => 'checkbox',
    ] );

    $wp_customize->add_setting( 'serve_search_results_count', [
        'default' => 6, 'sanitize_callback' => 'absint',
    ] );
    $wp_customize->add_control( 'serve_search_results_count', [
        'label'       => esc_html__( 'Live Results to Show', 'serve' ),
        'description' => esc_html__( 'Number of results in the live overlay (native fallback only).', 'serve' ),
        'section'     => 'serve_search_options',
        'type'        => 'number',
        'input_attrs' => [ 'min' => 2, 'max' => 12, 'step' => 1 ],
    ] );
}
add_action( 'customize_register', 'serve_search_customizer' );

function serve_nav_search_icon() {
    if ( ! get_theme_mod( 'serve_search_icon_header', true ) ) return;

    add_action( 'wp_footer', 'serve_search_overlay_html', 5 );

    add_action( 'wp_footer', function(): void {
        if ( ! get_theme_mod( 'serve_search_icon_header', true ) ) return;
        ?>
        <script>
        (function(){
            var btn = document.getElementById('serve-search-open');
            if (!btn) return;
            btn.addEventListener('click', function(){
                var overlay = document.getElementById('serve-search-overlay');
                if (overlay) {
                    overlay.classList.toggle('is-active');
                    btn.setAttribute('aria-expanded', overlay.classList.contains('is-active') ? 'true' : 'false');
                    var inp = overlay.querySelector('input[type="search"],input[type="text"]');
                    if (inp) setTimeout(function(){ inp.focus(); }, 80);
                }
            });
        })();
        </script>
        <?php
    }, 30 );
}
add_action( 'init', 'serve_nav_search_icon' );

function serve_search_overlay_html() {
    if ( ! get_theme_mod( 'serve_search_icon_header', true ) ) return;

    $placeholder  = esc_attr( get_theme_mod( 'serve_search_placeholder', 'Search stories, topics, authors…' ) );
    $show_thumbs  = get_theme_mod( 'serve_search_show_thumbs', true );
    $show_cats    = get_theme_mod( 'serve_search_show_cats', true );
    $results_num  = absint( get_theme_mod( 'serve_search_results_count', 6 ) ) ?: 6;
    $home_url     = esc_url( home_url( '/' ) );
    $site_name    = esc_html( get_bloginfo( 'name' ) );
    $logo_id      = get_theme_mod( 'custom_logo' );
    $logo_html    = '';
    if ( $logo_id ) {
        $logo_src = wp_get_attachment_image_url( $logo_id, 'full' );
        if ( $logo_src ) {
            $logo_html = '<a href="' . $home_url . '" class="sso-brand-logo" tabindex="-1"><img src="' . esc_url( $logo_src ) . '" alt="' . $site_name . '" height="32"></a>';
        }
    }

    ?>
    <div id="serve-search-overlay"
         class="sso"
         role="dialog"
         aria-modal="true"
         aria-label="<?php esc_attr_e( 'Search', 'serve' ); ?>"
         aria-hidden="true"
         data-thumbs="<?php echo $show_thumbs ? '1' : '0'; ?>"
         data-cats="<?php echo $show_cats ? '1' : '0'; ?>"
         data-count="<?php echo $results_num; ?>"
    >
        <div class="sso__backdrop" id="serve-search-backdrop" aria-hidden="true"></div>

        <div class="sso__panel" role="document">

            <!-- Top bar -->
            <div class="sso__bar">
                <div class="sso__brand">
                    <?php echo $logo_html; ?>
                    <?php if ( ! $logo_html ) : ?>
                    <a href="<?php echo $home_url; ?>" class="sso__site-name" tabindex="-1"><?php echo $site_name; ?></a>
                    <?php endif; ?>
                </div>
                <button type="button" id="serve-search-close" class="sso__close" aria-label="<?php esc_attr_e( 'Close search', 'serve' ); ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Search form -->
            <div class="sso__input-wrap">
                <label for="sso-input" class="screen-reader-text"><?php esc_html_e( 'Search', 'serve' ); ?></label>
                <svg class="sso__input-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input
                    type="search"
                    id="sso-input"
                    class="sso__input"
                    placeholder="<?php echo $placeholder; ?>"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="off"
                    spellcheck="false"
                    aria-autocomplete="list"
                    aria-controls="serve-search-results"
                    aria-activedescendant=""
                />
                <button type="button" class="sso__clear" id="sso-clear" aria-label="<?php esc_attr_e( 'Clear search', 'serve' ); ?>" hidden>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Live results -->
            <div class="sso__results-wrap">
                <ul id="serve-search-results" class="sso__results" role="listbox" aria-label="<?php esc_attr_e( 'Search results', 'serve' ); ?>"></ul>
                <div id="serve-search-status" class="sso__status screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></div>
                <div class="sso__footer" id="sso-footer" hidden>
                    <form method="get" action="<?php echo $home_url; ?>">
                        <input type="hidden" name="s" id="sso-footer-q" value="">
                        <button type="submit" class="sso__view-all">
                            <?php esc_html_e( 'View all results', 'serve' ); ?>
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </button>
                    </form>
                </div>
            </div>

        </div><!-- /.sso__panel -->
    </div>
    <?php
}

function serve_search_styles() {
    $css = '
/* ── Search overlay CSS ─────────────────────────────────────────────────── */
.serve-search-toggle { margin-left: auto !important; display: flex; align-items: center; }
.single .entry-meta.entry-meta-header{display:flex;align-items:center;flex-wrap:wrap;gap:.4rem .75rem;max-width:var(--flavor-narrow-width,720px);margin:0 auto .1rem;padding:.1rem 0 0;}
.single .entry-meta.entry-meta-header .byline{display:inline-flex;align-items:center;gap:.5rem}
.single .entry-meta.entry-meta-header .byline-text a{font-size:1rem;font-weight:800;letter-spacing:-.01em}
.single .entry-meta.entry-meta-header .byline-social{display:inline-flex;align-items:center;gap:.4rem;margin-left:.25rem}
.single .entry-meta.entry-meta-header .posted-on{display:none!important}
.nav-menu li.menu-item-listen > a,.nav-menu li.menu-item-watch > a{display:flex!important;flex-direction:row!important;align-items:center!important;gap:5px!important;padding:0.75rem 1rem!important;line-height:1!important;}
.nav-menu li.menu-item-listen,.nav-menu li.menu-item-watch{display:flex!important;align-items:center!important;}
.serve-search-open-btn{display:inline-flex;align-items:center;gap:7px;padding:7px 16px 7px 12px;background:var(--flavor-bg-alt,#f5f5f5);border:1.5px solid var(--flavor-border,#e0e0e0);border-radius:99px;cursor:pointer;color:var(--flavor-text-light,#666);font-family:var(--flavor-font-ui,sans-serif);font-size:.8rem;font-weight:500;transition:border-color .15s,color .15s,background .15s,box-shadow .15s;min-height:36px;white-space:nowrap;-webkit-tap-highlight-color:transparent;letter-spacing:.01em;box-shadow:0 1px 3px rgba(0,0,0,.06);}
.serve-search-open-btn:hover{border-color:var(--flavor-accent,#c62828);color:var(--flavor-accent,#c62828);background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.1);}
.serve-search-open-btn:focus-visible{outline:2px solid var(--flavor-accent);outline-offset:2px;border-radius:99px;}
.serve-search-open-btn__label{font-size:.8rem;}
.serve-search-open-btn__kbd{font-family:var(--flavor-font-ui,sans-serif);font-size:.65rem;background:var(--flavor-border,#e0e0e0);color:var(--flavor-text-light,#888);border-radius:4px;padding:1px 5px;letter-spacing:0;line-height:1.4;border:1px solid rgba(0,0,0,.1);}
@media(max-width:900px){.serve-search-open-btn__label,.serve-search-open-btn__kbd{display:none;}.serve-search-open-btn{padding:7px 10px;background:transparent;border-color:transparent;box-shadow:none;min-width:40px;justify-content:center;}.serve-search-open-btn:hover{background:var(--flavor-bg-alt,#f5f5f5);border-color:var(--flavor-border,#e0e0e0);}}
.sso{position:fixed;inset:0;z-index:9900;display:flex;flex-direction:column;pointer-events:none;visibility:hidden;}
.sso.is-open{pointer-events:all;visibility:visible;}
.sso__backdrop{position:absolute;inset:0;background:rgba(0,0,0,.6);opacity:0;transition:opacity .2s ease;backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);}
.sso.is-open .sso__backdrop{opacity:1;}
.sso__panel{position:relative;background:var(--flavor-bg);width:100%;max-height:85vh;display:flex;flex-direction:column;transform:translateY(-12px);opacity:0;transition:transform .22s cubic-bezier(.4,0,.2,1),opacity .2s ease;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,.18);}
.sso.is-open .sso__panel{transform:translateY(0);opacity:1;}
.sso__bar{display:flex;align-items:center;justify-content:space-between;padding:.75rem var(--flavor-gap);border-bottom:1px solid var(--flavor-border);flex-shrink:0;}
.sso__brand{display:flex;align-items:center;}
.sso__brand-logo img{height:32px;width:auto;display:block;}
.sso__site-name{font-family:var(--flavor-font-headline);font-size:var(--flavor-size-lg);font-weight:var(--flavor-weight-black);color:var(--flavor-text);text-decoration:none;letter-spacing:-.01em;}
.sso__close{background:none;border:none;cursor:pointer;padding:8px;color:var(--flavor-text-secondary);display:flex;align-items:center;justify-content:center;transition:color .15s;border-radius:50%;min-height:44px;min-width:44px;}
.sso__close:hover{color:var(--flavor-text);background:var(--flavor-bg-alt);}
.sso__close:focus-visible{outline:2px solid var(--flavor-accent);outline-offset:2px;}
.sso__input-wrap{display:flex;align-items:center;gap:.75rem;padding:1.25rem var(--flavor-gap);border-bottom:2px solid var(--flavor-text);flex-shrink:0;}
.sso__input-icon{flex-shrink:0;color:var(--flavor-text-light);}
.sso__input{flex:1;border:none;outline:none;background:transparent;font-family:var(--flavor-font-headline);font-size:clamp(1.25rem,3vw,2rem);font-weight:var(--flavor-weight-bold);color:var(--flavor-text);line-height:1.2;min-width:0;}
.sso__input::placeholder{color:var(--flavor-text-light);font-weight:var(--flavor-weight-normal);}
.sso__clear{background:none;border:none;cursor:pointer;padding:6px;color:var(--flavor-text-light);display:flex;align-items:center;border-radius:50%;flex-shrink:0;transition:color .15s,background .15s;}
.sso__clear:hover{color:var(--flavor-text);background:var(--flavor-bg-alt);}
.sso__results-wrap{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}
.sso__results{list-style:none;margin:0;padding:0;}
.sso__result{display:flex;align-items:flex-start;gap:1rem;padding:.9rem var(--flavor-gap);border-bottom:1px solid var(--flavor-border);cursor:pointer;text-decoration:none;color:inherit;transition:background .12s;outline:none;}
.sso__result:hover,.sso__result:focus,.sso__result[aria-selected="true"]{background:var(--flavor-bg-alt);}
.sso__result:last-child{border-bottom:none;}
.sso__result-thumb{flex-shrink:0;width:72px;height:48px;overflow:hidden;background:var(--flavor-bg-alt);}
.sso__result-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.sso__result-body{flex:1;min-width:0;}
.sso__result-cat{display:inline-block;font-family:var(--flavor-font-ui);font-size:10px;font-weight:var(--flavor-weight-bold);text-transform:uppercase;letter-spacing:.07em;color:var(--flavor-accent);margin-bottom:.2rem;}
.sso__result-title{font-family:var(--flavor-font-headline);font-size:var(--flavor-size-md);font-weight:var(--flavor-weight-bold);line-height:var(--flavor-line-height-snug);color:var(--flavor-text);margin:0 0 .25rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sso__result-meta{font-family:var(--flavor-font-ui);font-size:var(--flavor-size-xs);color:var(--flavor-text-light);}
.sso__result-title mark,.sso__result-excerpt mark{background:none;color:var(--flavor-accent);font-weight:var(--flavor-weight-bold);}
.sso__skeleton{display:flex;align-items:flex-start;gap:1rem;padding:.9rem var(--flavor-gap);border-bottom:1px solid var(--flavor-border);animation:sso-pulse 1.4s ease-in-out infinite;}
.sso__skeleton-thumb{flex-shrink:0;width:72px;height:48px;background:var(--flavor-border);border-radius:2px;}
.sso__skeleton-body{flex:1;display:flex;flex-direction:column;gap:.4rem;padding-top:.15rem;}
.sso__skeleton-line{height:14px;background:var(--flavor-border);border-radius:2px;}
.sso__skeleton-line:first-child{width:75%;}
.sso__skeleton-line:last-child{width:40%;height:11px;}
@keyframes sso-pulse{0%,100%{opacity:1}50%{opacity:.45}}
.sso__empty{padding:2.5rem var(--flavor-gap);text-align:center;color:var(--flavor-text-secondary);font-family:var(--flavor-font-ui);font-size:var(--flavor-size-sm);}
.sso__empty strong{display:block;font-family:var(--flavor-font-headline);font-size:var(--flavor-size-lg);color:var(--flavor-text);margin-bottom:.35rem;}
.sso__footer{padding:.85rem var(--flavor-gap);border-top:1px solid var(--flavor-border);background:var(--flavor-bg-alt);flex-shrink:0;}
.sso__view-all{background:none;border:none;cursor:pointer;font-family:var(--flavor-font-ui);font-size:var(--flavor-size-xs);font-weight:var(--flavor-weight-bold);text-transform:uppercase;letter-spacing:.07em;color:var(--flavor-accent);display:inline-flex;align-items:center;gap:.35rem;padding:0;transition:gap .15s;}
.sso__view-all:hover{gap:.55rem;}
.sso__status{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);}
.sso__type-badge{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#fff;border-radius:99px;padding:2px 7px;vertical-align:middle;}
.sso__result-meta-top{display:flex;align-items:center;gap:6px;margin-bottom:.25rem;}
.sso__result-date{font-family:var(--flavor-font-ui);font-size:.7rem;color:var(--flavor-text-light);}
.sso__result-snippet{font-size:.78rem;color:var(--flavor-text-light);line-height:1.5;margin-top:.2rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.sso__result-snippet mark{background:none;color:var(--flavor-accent);font-weight:700;}
.sso__result-reasons{display:flex;flex-wrap:wrap;gap:4px;margin-top:.35rem;}
.sso__reason{display:inline-flex;align-items:center;gap:3px;font-family:var(--flavor-font-ui);font-size:.65rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;border:1px solid;border-radius:4px;padding:1px 6px;white-space:nowrap;}
.sso__suggest-heading{font-family:var(--flavor-font-ui);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--flavor-text-light);padding:.85rem var(--flavor-gap) .4rem;list-style:none;}
.sso__result--suggest{opacity:.92;}
.sso__result--suggest:hover{opacity:1;}
@media(max-width:768px){.serve-search-toggle{position:absolute;right:var(--flavor-gap);top:50%;transform:translateY(-50%);}.main-navigation .flavor-container{position:relative;}.sso__input{font-size:1.35rem;}.sso__panel{max-height:100dvh;max-height:100vh;}}
@media(max-width:480px){.sso__result-thumb{width:56px;height:40px;}.sso__input{font-size:1.1rem;}}
body.sso-open{overflow:hidden;}
@media print{.sso,.serve-search-toggle{display:none!important;}}
';
    serve_add_consolidated_css( 'integrations-1', $css );
}
add_action( 'wp_enqueue_scripts', 'serve_search_styles', 20 );

function serve_search_trigger_script() {
    if ( ! get_theme_mod( 'serve_search_icon_header', true ) ) return;
    if ( is_admin() ) return;

    wp_enqueue_script(
        'serve-search-trigger',
        get_template_directory_uri() . '/assets/js/search-trigger.js',
        [],
        SERVE_VERSION,
        [ 'in_footer' => true, 'strategy' => 'defer' ]
    );

    wp_localize_script( 'serve-search-trigger', 'serveSearch', [
        'restUrl'     => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
        'homeUrl'     => esc_url( home_url( '/' ) ),
        'nonce'       => wp_create_nonce( 'wp_rest' ),
        'perPage'     => absint( get_theme_mod( 'serve_search_results_count', 6 ) ) ?: 6,
        'showThumbs'  => get_theme_mod( 'serve_search_show_thumbs', true ) ? '1' : '0',
        'showCats'    => get_theme_mod( 'serve_search_show_cats', true ) ? '1' : '0',
        'l10n'        => [
            'noResults'  => esc_html__( 'No results found', 'serve' ),
            'noResultsQ' => esc_html__( 'Try different keywords or browse by category.', 'serve' ),
            'searching'  => esc_html__( 'Searching…', 'serve' ),
            'results'    => esc_html__( 'results', 'serve' ),
        ],
    ] );
}
add_action( 'wp_enqueue_scripts', 'serve_search_trigger_script' );

function serve_is_wpcom() {
    return defined( 'IS_WPCOM' ) || defined( 'WPCOM_IS_VIP_ENV' ) || function_exists( 'is_wpcom' );
}

function serve_wpcom_compat() {
    if ( ! serve_is_wpcom() ) return;

    add_action( 'wp_enqueue_scripts', function() {
        $wpcom_css = get_template_directory() . '/assets/css/wpcom.css';
        if ( file_exists( $wpcom_css ) ) {
            wp_enqueue_style( 'serve-wpcom', get_template_directory_uri() . '/assets/css/wpcom.css', [], SERVE_VERSION );
        }
    }, 15 );

    add_action( 'wp_head', function() {
        if ( is_admin_bar_showing() ) {
            echo '<style>.main-navigation.is-sticky{top:32px}@media(max-width:782px){.main-navigation.is-sticky{top:46px}}</style>' . "\n";
        }
    } );
}
add_action( 'after_setup_theme', 'serve_wpcom_compat' );

function serve_wpcom_likes_style() {
    if ( ! is_singular() ) return;
    $css = '
.sharedaddy .sd-like{margin-top:var(--flavor-gap)}
.wpl-likebox{font-family:var(--flavor-font-ui)!important}
#wpnt-notes-panel2{font-family:var(--flavor-font-body)!important}
.widget_blog_subscription input[type="email"]{width:100%;padding:.75rem;border:1px solid var(--flavor-border);font-family:var(--flavor-font-body);margin-bottom:.5rem}
.widget_blog_subscription input[type="submit"]{background:var(--flavor-accent);color:#fff;border:none;padding:.75rem 1.5rem;font-family:var(--flavor-font-ui);font-weight:700;text-transform:uppercase;letter-spacing:.05em;font-size:.8125rem;width:100%;cursor:pointer}
.grofile-thumbnail{border-radius:50%!important}
.reblog-from .reblog-post{font-family:var(--flavor-font-body)}
.reader-full-post__story-content{font-family:var(--flavor-font-body);font-size:var(--flavor-size-md);line-height:var(--flavor-line-height-relaxed)}
';
    serve_add_consolidated_css( 'integrations-2', $css );
}
add_action( 'wp_enqueue_scripts', 'serve_wpcom_likes_style', 20 );

function serve_creator_compat() {
    if ( ! is_singular() ) return;
    $css = '
.wp-block-jetpack-subscriptions{margin:2em 0}
.wp-block-jetpack-subscriptions .wp-block-jetpack-subscriptions__container{max-width:var(--flavor-narrow-width);margin:0 auto}
.wp-block-jetpack-subscriptions input[type="email"]{border:2px solid var(--flavor-border);padding:.75rem 1rem;font-family:var(--flavor-font-body);font-size:var(--flavor-size-base);width:100%}
.wp-block-jetpack-subscriptions input[type="email"]:focus{border-color:var(--flavor-accent);outline:none}
.wp-block-jetpack-subscriptions .wp-block-jetpack-subscriptions__button{background:var(--flavor-accent)!important;font-family:var(--flavor-font-ui);font-weight:var(--flavor-weight-bold);text-transform:uppercase;letter-spacing:.04em}
.wp-block-jetpack-paywall{border:2px solid var(--flavor-accent);padding:2rem;text-align:center;margin:2em 0}
.wp-block-jetpack-paywall .wp-block-jetpack-paywall__subscribe{background:var(--flavor-accent);color:#fff;padding:.75rem 2rem;font-family:var(--flavor-font-ui);font-weight:var(--flavor-weight-bold);text-transform:uppercase}
.wp-block-premium-content-container{margin:2em 0}
';
    serve_add_consolidated_css( 'integrations-3', $css );
}
add_action( 'wp_enqueue_scripts', 'serve_creator_compat', 20 );

function serve_blocks_style() {
    if ( ! is_singular() && ! is_front_page() ) return;
    $css = '
.wp-block-jetpack-rating-star .jetpack-ratings-button{color:var(--flavor-accent)}
.wp-block-jetpack-repeat-visitor{margin:1.5em 0}
.wp-block-jetpack-story{margin:2em 0;border-radius:8px;overflow:hidden}
.wp-block-jetpack-gif{margin:2em 0}
.wp-block-jetpack-gif img{border-radius:4px}
.wp-block-jetpack-map{margin:2em 0;border:1px solid var(--flavor-border)}
.wp-block-jetpack-slideshow{margin:2em 0}
.wp-block-jetpack-image-compare{margin:2em 0}
.wp-block-jetpack-donations{margin:2em 0;border:1px solid var(--flavor-border);padding:1.5rem}
.wp-block-jetpack-donations .donations__amount-value{border-color:var(--flavor-accent)}
.wp-block-jetpack-timeline{margin:2em 0}
.wp-block-jetpack-contact-info{font-family:var(--flavor-font-ui);font-size:var(--flavor-size-sm)}
.wp-block-jetpack-business-hours{font-family:var(--flavor-font-ui);font-size:var(--flavor-size-sm)}
.wp-block-jetpack-business-hours dt{font-weight:var(--flavor-weight-bold)}
.wp-block-jetpack-calendly{margin:2em 0}
.wp-block-jetpack-eventbrite{margin:2em 0}
.wp-block-jetpack-opentable{margin:2em 0}
';
    serve_add_consolidated_css( 'integrations-5', $css );
}
add_action( 'wp_enqueue_scripts', 'serve_blocks_style', 20 );

add_filter( 'wpcom_disable_hovercards', '__return_true' );

add_action( 'wp_enqueue_scripts', function() {
    wp_dequeue_script( 'grofiles-cards' );
    wp_dequeue_script( 'wpcom-hovercards' );
}, 99 );

add_action( 'wp_head', function() {
    echo '<style>.grofile-card,.wpcom-hovercard,.gravatar-hovercard{display:none!important;visibility:hidden!important}</style>' . "\n";
}, 99 );

add_action( 'wp_enqueue_scripts', function(): void {
    if ( is_singular() && ! has_blocks( get_post_field( 'post_content', get_the_ID() ) ) ) {
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'wp-block-library-theme' );
        wp_dequeue_style( 'global-styles' );
    }
    wp_dequeue_script( 'emoji' );
    wp_dequeue_style( 'dashicons' );
}, 100 );

remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'rsd_link' );

add_filter( 'the_content', function( $content ) {
    if ( ! is_string( $content ) ) $content = (string) $content;
    if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) return $content;
    if ( ! function_exists( 'flavor_author_card' ) ) return $content;
    try {
        ob_start();
        echo '<div class="single-author-card-wrap" style="max-width:960px;margin:2rem auto 0;padding:0 2rem">';
        flavor_author_card();
        echo '</div>';
        return $content . ob_get_clean();
    } catch ( \Throwable $e ) {
        if ( ob_get_level() ) ob_end_clean();
        if ( defined('WP_DEBUG') && WP_DEBUG ) error_log( '[Apollo] author-card filter: ' . $e->getMessage() );
        return $content;
    }
}, 5 );

add_filter( 'jetpack_author_bio', '__return_false' );

foreach ( [ 'wp', 'template_redirect', 'the_post' ] as $_hook ) {
    add_action( $_hook, function(): void {
        remove_filter( 'the_content', 'jetpack_author_bio' );
        remove_filter( 'the_content', 'jetpack_author_bio', 10 );
        remove_filter( 'the_content', 'jetpack_author_bio', 40 );
        remove_filter( 'the_content', 'wpcom_author_bio' );
        remove_action( 'loop_end',    'wpcom_author_bio' );
        remove_action( 'loop_end',    'jetpack_author_bio' );
        remove_action( 'wp_footer',   'wpcom_author_bio' );
        remove_filter( 'the_content', 'wpcom_author_bio_display' );
        remove_action( 'serve_single_bottom', 'wpcom_author_bio' );
        remove_action( 'jetpack_subscriptions_author_card', 'jetpack_author_bio' );
    }, 9999 );
}

add_filter( 'the_content', function( $content ) {
    if ( ! is_string( $content ) ) $content = (string) $content;
    if ( ! is_singular( 'post' ) ) return $content;
    if ( strpos( $content, 'jp-author-card' ) === false
      && strpos( $content, 'wpcom-author-card' ) === false
      && strpos( $content, 'jetpack-author-bio' ) === false ) {
        return $content;
    }
    $out = preg_replace(
        '#<div[^>]+class=[^>]*(?:jp-author-card|wpcom-author-card|jetpack-author-bio)[^>]*>.*?</div>#is',
        '',
        $content
    );
    return is_string( $out ) ? $out : $content;
}, 999 );

foreach ( [ 'wp', 'template_redirect', 'the_post' ] as $_ep_hook ) {
    add_action( $_ep_hook, function(): void {
        if ( ! is_singular( 'serve_episode' ) ) return;
        remove_filter( 'the_content', 'jetpack_subscriptions_form_filter', 10 );
        remove_filter( 'the_content', 'jetpack_subscription_form', 10 );
        remove_action( 'loop_end',   'jetpack_subscriptions_display' );
        remove_action( 'wp_footer',  'jetpack_subscriptions_footer_widget' );
        remove_filter( 'the_content', 'wpcom_subscriptions_form', 10 );
        remove_action( 'loop_end',   'wpcom_subscriptions_display' );
        remove_shortcode( 'jetpack-subscribe' );
    }, 9999 );
}

add_filter( 'the_content', function( $content ) {
    if ( ! is_string( $content ) ) $content = (string) $content;
    if ( ! is_singular( 'serve_episode' ) ) return $content;
    $patterns = [
        '#<div[^>]+class=[^>]*(?:jetpack_subscription_widget|jetpack-subscribe|wp-block-jetpack-subscriptions)[^>]*>.*?</div>#is',
        '#<form[^>]+id=[^>]*subscribe[^>]*>.*?</form>#is',
    ];
    foreach ( $patterns as $pattern ) {
        $out = preg_replace( $pattern, '', $content );
        if ( is_string( $out ) ) $content = $out;
    }
    return $content;
}, 999 );
