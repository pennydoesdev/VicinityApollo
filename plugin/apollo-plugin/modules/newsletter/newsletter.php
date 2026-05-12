<?php

defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    register_term_meta( 'category', 'serve_category_icon', [
        'type'              => 'string',
        'single'            => true,
        'sanitize_callback' => 'sanitize_text_field',
        'show_in_rest'      => true,
    ] );
} );

add_action( 'category_add_form_fields', function(): void {
    ?>
    <div class="form-field">
        <label for="serve_category_icon"><?php esc_html_e( 'Category Icon (emoji)', 'serve' ); ?></label>
        <input type="text" id="serve_category_icon" name="serve_category_icon" value="" maxlength="10" style="width:80px;font-size:1.5rem;">
        <p class="description"><?php esc_html_e( 'Paste an emoji (e.g. 📰 🏛️ 🌍). Shown on the Newsletters page next to this category.', 'serve' ); ?></p>
    </div>
    <?php
} );

add_action( 'category_edit_form_fields', function( WP_Term $term ): void {
    $icon = (string) get_term_meta( $term->term_id, 'serve_category_icon', true );
    ?>
    <tr class="form-field">
        <th scope="row">
            <label for="serve_category_icon"><?php esc_html_e( 'Category Icon (emoji)', 'serve' ); ?></label>
        </th>
        <td>
            <input type="text" id="serve_category_icon" name="serve_category_icon"
                   value="<?php echo esc_attr( $icon ); ?>" maxlength="10"
                   style="width:80px;font-size:1.5rem;">
            <p class="description"><?php esc_html_e( 'Paste an emoji (e.g. 📰 🏛️ 🌍). Shown on the Newsletters page next to this category.', 'serve' ); ?></p>
        </td>
    </tr>
    <?php
} );

add_action( 'created_category', 'serve_save_category_icon_meta' );
add_action( 'edited_category',  'serve_save_category_icon_meta' );
function serve_save_category_icon_meta( int $term_id ): void {
    if ( ! isset( $_POST['serve_category_icon'] ) ) return;
    $icon = sanitize_text_field( wp_unslash( $_POST['serve_category_icon'] ) );
    if ( $icon !== '' ) {
        update_term_meta( $term_id, 'serve_category_icon', $icon );
    } else {
        delete_term_meta( $term_id, 'serve_category_icon' );
    }
}

add_action( 'customize_register', function( WP_Customize_Manager $wp_customize ): void {

    $wp_customize->add_section( 'serve_newsletters', [
        'title'       => esc_html__( 'Manage Subscriptions Page', 'serve' ),
        'panel'       => 'flavor_theme_options',
        'priority'    => 140,
        'description' => esc_html__( 'Style the /newsletters page. Uses shortcode [serve_newsletters] or auto-page.', 'serve' ),
    ] );

    $fields = [
        [ 'serve_nl_page_title',    'The Penny Tribune Newsletters',  'text',  'Page Title',         'sanitize_text_field' ],
        [ 'serve_nl_page_subtitle', 'Stay informed. Subscribe to the newsletters that matter to you.', 'textarea', 'Page Subtitle', 'sanitize_textarea_field' ],
        [ 'serve_nl_btn_label',     'Subscribe',                      'text',  'Subscribe Button Text', 'sanitize_text_field' ],
        [ 'serve_nl_manage_label',  'Manage my subscriptions',        'text',  'Manage Link Text',   'sanitize_text_field' ],
        [ 'serve_nl_footer_text',   'Unsubscribe at any time. We respect your privacy.', 'text', 'Footer Fine Print', 'sanitize_text_field' ],
    ];

    foreach ( $fields as [ $id, $default, $type, $label, $sanitize ] ) {
        $wp_customize->add_setting( $id, [
            'default'           => $default,
            'sanitize_callback' => $sanitize,
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control( $id, [
            'label'   => esc_html__( $label, 'serve' ),
            'section' => 'serve_newsletters',
            'type'    => $type,
        ] );
    }

    $colors = [
        [ 'serve_nl_bg',          '#FFFFFF', 'Page Background' ],
        [ 'serve_nl_header_bg',   '#121212', 'Header Background' ],
        [ 'serve_nl_header_text', '#FFFFFF', 'Header Text Color' ],
        [ 'serve_nl_card_bg',     '#F7F7F7', 'Card Background' ],
        [ 'serve_nl_card_border', '#E2E2E2', 'Card Border Color' ],
        [ 'serve_nl_accent',      '',        'Accent Color (blank = theme accent)' ],
        [ 'serve_nl_btn_bg',      '',        'Button Color (blank = theme accent)' ],
        [ 'serve_nl_btn_text',    '#FFFFFF', 'Button Text Color' ],
    ];

    foreach ( $colors as [ $id, $default, $label ] ) {
        $wp_customize->add_setting( $id, [
            'default'           => $default,
            'sanitize_callback' => 'sanitize_hex_color',
            'transport'         => 'refresh',
        ] );
        $wp_customize->add_control(
            new WP_Customize_Color_Control( $wp_customize, $id, [
                'label'   => esc_html__( $label, 'serve' ),
                'section' => 'serve_newsletters',
            ] )
        );
    }

    $wp_customize->add_setting( 'serve_nl_show_frequency', [
        'default'           => true,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ] );
    $wp_customize->add_control( 'serve_nl_show_frequency', [
        'label'   => esc_html__( 'Show delivery frequency on cards', 'serve' ),
        'section' => 'serve_newsletters',
        'type'    => 'checkbox',
    ] );

    $wp_customize->add_setting( 'serve_nl_show_count', [
        'default'           => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
        'transport'         => 'refresh',
    ] );
    $wp_customize->add_control( 'serve_nl_show_count', [
        'label'   => esc_html__( 'Show subscriber count on cards (requires Jetpack)', 'serve' ),
        'section' => 'serve_newsletters',
        'type'    => 'checkbox',
    ] );

} );

function serve_nl_get_frequency(): string {
    $raw = (string) get_option( 'jetpack_subscriptions_email_frequency', '' );
    if ( ! $raw ) {
        $raw = (string) get_option( 'subscription_email_frequency', 'instantly' );
    }
    $labels = [
        'instantly' => __( 'As it happens', 'serve' ),
        'daily'     => __( 'Daily digest', 'serve' ),
        'weekly'    => __( 'Weekly digest', 'serve' ),
    ];
    return $labels[ $raw ] ?? ucfirst( $raw );
}

function serve_nl_get_categories(): array {
    $cats = get_categories( [
        'hide_empty' => false,
        'exclude'    => get_option( 'default_category' ),
        'orderby'    => 'name',
        'order'      => 'ASC',
    ] );

    $result = [];
    foreach ( $cats as $cat ) {
        $result[] = [
            'id'          => $cat->term_id,
            'name'        => $cat->name,
            'slug'        => $cat->slug,
            'description' => $cat->description,
            'count'       => $cat->count,
            'url'         => get_category_link( $cat->term_id ),
            'icon'        => (string) get_term_meta( $cat->term_id, 'serve_category_icon', true ),
        ];
    }
    return $result;
}

function serve_nl_manage_url(): string {
    if ( is_user_logged_in() ) {
        $user  = wp_get_current_user();
        $email = rawurlencode( $user->user_email );
        return 'https://subscribe.wordpress.com/memberships/?email=' . $email;
    }
    return 'https://subscribe.wordpress.com/memberships/';
}

function serve_render_newsletters_page(): string {

    $title         = get_theme_mod( 'serve_nl_page_title',    'The Penny Tribune Newsletters' );
    $subtitle      = get_theme_mod( 'serve_nl_page_subtitle', 'Stay informed. Subscribe to the newsletters that matter to you.' );
    $btn_label     = get_theme_mod( 'serve_nl_btn_label',     'Subscribe' );
    $manage_label  = get_theme_mod( 'serve_nl_manage_label',  'Manage my subscriptions' );
    $footer_text   = get_theme_mod( 'serve_nl_footer_text',   'Unsubscribe at any time. We respect your privacy.' );
    $show_freq     = (bool) get_theme_mod( 'serve_nl_show_frequency', true );
    $show_count    = (bool) get_theme_mod( 'serve_nl_show_count', false );

    $page_bg    = get_theme_mod( 'serve_nl_bg',          '#FFFFFF' );
    $header_bg  = get_theme_mod( 'serve_nl_header_bg',   '#121212' );
    $header_txt = get_theme_mod( 'serve_nl_header_text', '#FFFFFF' );
    $card_bg    = get_theme_mod( 'serve_nl_card_bg',     '#F7F7F7' );
    $card_bdr   = get_theme_mod( 'serve_nl_card_border', '#E2E2E2' );
    $accent     = get_theme_mod( 'serve_nl_accent',      '' ) ?: get_theme_mod( 'flavor_accent_color', '#C62828' );
    $btn_bg     = get_theme_mod( 'serve_nl_btn_bg',      '' ) ?: $accent;
    $btn_txt    = get_theme_mod( 'serve_nl_btn_text',    '#FFFFFF' );

    $frequency  = $show_freq ? serve_nl_get_frequency() : '';
    $categories = serve_nl_get_categories();
    $manage_url = serve_nl_manage_url();
    $site_name  = get_bloginfo( 'name' );

    $subscribe_url = get_option( 'blogname' ) ? home_url( '/?jetpack_subscription_active=1' ) : home_url();

    ob_start();
    ?>
    <div class="serve-nl-page" style="background:<?php echo esc_attr( $page_bg ); ?>;">

        <!-- Header -->
        <header class="serve-nl-header" style="background:<?php echo esc_attr( $header_bg ); ?>;color:<?php echo esc_attr( $header_txt ); ?>;">
            <div class="serve-nl-container">
                <div class="serve-nl-header-inner">
                    <div class="serve-nl-logo"><?php echo esc_html( $site_name ); ?></div>
                    <h1 class="serve-nl-title"><?php echo esc_html( $title ); ?></h1>
                    <?php if ( $subtitle ) : ?>
                    <p class="serve-nl-subtitle"><?php echo esc_html( $subtitle ); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $manage_url ); ?>" class="serve-nl-manage-link" target="_blank" rel="noopener">
                        <?php echo esc_html( $manage_label ); ?> →
                    </a>
                </div>
            </div>
        </header>

        <!-- Newsletter Cards Grid -->
        <main class="serve-nl-main">
            <div class="serve-nl-container">

                <?php if ( empty( $categories ) ) : ?>
                    <p class="serve-nl-empty"><?php esc_html_e( 'No newsletter categories found. Add categories under Posts → Categories.', 'serve' ); ?></p>
                <?php else : ?>

                <div class="serve-nl-grid">
                    <?php foreach ( $categories as $cat ) :
                        $icon = $cat['icon'] ?: '📄';
                        $desc = $cat['description'] ?: sprintf( __( 'Stories from our %s section.', 'serve' ), $cat['name'] );
                    ?>
                    <div class="serve-nl-card" style="background:<?php echo esc_attr( $card_bg ); ?>;border-color:<?php echo esc_attr( $card_bdr ); ?>;">

                        <div class="serve-nl-card-top">
                            <span class="serve-nl-card-icon"><?php echo esc_html( $icon ); ?></span>
                            <div class="serve-nl-card-meta">
                                <?php if ( $show_freq && $frequency ) : ?>
                                <span class="serve-nl-badge" style="color:<?php echo esc_attr( $accent ); ?>;border-color:<?php echo esc_attr( $accent ); ?>;">
                                    <?php echo esc_html( $frequency ); ?>
                                </span>
                                <?php endif; ?>
                                <?php if ( $show_count && $cat['count'] > 0 ) : ?>
                                <span class="serve-nl-count"><?php echo number_format_i18n( $cat['count'] ); ?> <?php esc_html_e( 'stories', 'serve' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <h2 class="serve-nl-card-name">
                            <a href="<?php echo esc_url( $cat['url'] ); ?>"><?php echo esc_html( $cat['name'] ); ?></a>
                        </h2>

                        <p class="serve-nl-card-desc"><?php echo esc_html( wp_trim_words( $desc, 20 ) ); ?></p>

                        <div class="serve-nl-card-footer">
                            <?php
                            if ( class_exists( 'Jetpack' ) && Jetpack::is_module_active( 'subscriptions' ) ) :
                                $form_action = 'https://subscribe.wordpress.com';
                                ?>
                                <form class="serve-nl-subscribe-form" action="<?php echo esc_url( $form_action ); ?>" method="post" target="_blank">
                                    <input type="hidden" name="action" value="subscribe">
                                    <input type="hidden" name="blog_id" value="<?php echo absint( get_current_blog_id() ); ?>">
                                    <input type="hidden" name="source" value="<?php echo esc_url( get_permalink() ); ?>">
                                    <input type="hidden" name="sub-type" value="widget">
                                    <input type="hidden" name="redirect_fragment" value="serve-nl-success">
                                    <?php wp_nonce_field( 'blogsub_subscribe', '_wpnonce' ); ?>
                                    <input type="email" name="email" class="serve-nl-email-input"
                                           placeholder="<?php esc_attr_e( 'Your email address', 'serve' ); ?>" required>
                                    <button type="submit" class="serve-nl-btn"
                                            style="background:<?php echo esc_attr( $btn_bg ); ?>;color:<?php echo esc_attr( $btn_txt ); ?>;">
                                        <?php echo esc_html( $btn_label ); ?>
                                    </button>
                                </form>
                            <?php else : ?>
                                <a href="<?php echo esc_url( $cat['url'] ); ?>" class="serve-nl-btn"
                                   style="background:<?php echo esc_attr( $btn_bg ); ?>;color:<?php echo esc_attr( $btn_txt ); ?>;">
                                    <?php echo esc_html( $btn_label ); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>

                <!-- Footer fine print -->
                <?php if ( $footer_text ) : ?>
                <p class="serve-nl-fine-print"><?php echo esc_html( $footer_text ); ?></p>
                <?php endif; ?>

            </div>
        </main>

    </div>

    <?php
    return ob_get_clean();
}

add_shortcode( 'serve_newsletters', function(): string {
    return serve_render_newsletters_page();
} );

add_action( 'init', function(): void {
    add_rewrite_rule( '^newsletters/?$', 'index.php?serve_newsletters_page=1', 'top' );
} );

add_filter( 'query_vars', function( array $vars ): array {
    $vars[] = 'serve_newsletters_page';
    return $vars;
} );

add_action( 'template_redirect', function(): void {
    if ( ! get_query_var( 'serve_newsletters_page' ) ) return;

    $page = get_page_by_path( 'newsletters' );
    if ( $page && get_post_status( $page ) === 'publish' ) return;

    get_header();
    echo serve_render_newsletters_page();
    get_footer();
    exit;
} );

add_action( 'after_switch_theme', function(): void {
    add_rewrite_rule( '^newsletters/?$', 'index.php?serve_newsletters_page=1', 'top' );
    flush_rewrite_rules();
} );

add_action( 'wp_head', function(): void {
    if ( ! get_query_var( 'serve_newsletters_page' ) &&
         ! is_page( 'newsletters' ) &&
         ! ( is_singular() && has_shortcode( get_post()->post_content ?? '', 'serve_newsletters' ) ) ) return;
    ?>
    <style id="serve-nl-styles">
    .serve-nl-page{font-family:var(--flavor-font-body,'Georgia',serif);min-height:100vh}
    .serve-nl-container{max-width:1200px;margin:0 auto;padding:0 2rem}

    /* Header */
    .serve-nl-header{padding:3rem 0 2.5rem;border-bottom:4px solid #000}
    .serve-nl-header-inner{max-width:720px}
    .serve-nl-logo{font-family:var(--flavor-font-headline,'Georgia',serif);font-size:.875rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;opacity:.7;margin-bottom:.75rem}
    .serve-nl-title{font-family:var(--flavor-font-headline,'Georgia',serif);font-size:clamp(2rem,5vw,3.25rem);font-weight:700;line-height:1.1;margin:0 0 1rem;letter-spacing:-.02em}
    .serve-nl-subtitle{font-size:1.125rem;line-height:1.6;margin:0 0 1.5rem;opacity:.8}
    .serve-nl-manage-link{display:inline-block;font-family:var(--flavor-font-body,sans-serif);font-size:.875rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;text-decoration:underline;opacity:.7;transition:opacity .15s}
    .serve-nl-manage-link:hover{opacity:1}
    .serve-nl-header .serve-nl-manage-link{color:inherit}

    /* Main grid */
    .serve-nl-main{padding:3rem 0 4rem}
    .serve-nl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.5rem;margin-bottom:3rem}

    /* Cards */
    .serve-nl-card{border:1px solid;border-radius:2px;padding:1.75rem;display:flex;flex-direction:column;gap:.75rem;transition:box-shadow .2s}
    .serve-nl-card:hover{box-shadow:0 4px 24px rgba(0,0,0,.1)}
    .serve-nl-card-top{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem}
    .serve-nl-card-icon{font-size:2rem;line-height:1;flex-shrink:0}
    .serve-nl-card-meta{display:flex;flex-direction:column;align-items:flex-end;gap:.4rem}
    .serve-nl-badge{font-family:var(--flavor-font-body,sans-serif);font-size:.6875rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border:1px solid;border-radius:2px;padding:2px 8px}
    .serve-nl-count{font-size:.75rem;color:#666}
    .serve-nl-card-name{font-family:var(--flavor-font-headline,'Georgia',serif);font-size:1.375rem;font-weight:700;line-height:1.2;margin:0}
    .serve-nl-card-name a{color:inherit;text-decoration:none}
    .serve-nl-card-name a:hover{text-decoration:underline}
    .serve-nl-card-desc{font-size:.9375rem;line-height:1.6;color:#444;margin:0;flex:1}
    .serve-nl-card-footer{margin-top:.5rem}

    /* Form */
    .serve-nl-subscribe-form{display:flex;flex-direction:column;gap:.5rem}
    .serve-nl-email-input{width:100%;padding:.625rem .875rem;border:1.5px solid #d1d5db;border-radius:2px;font-size:.9375rem;font-family:inherit;box-sizing:border-box}
    .serve-nl-email-input:focus{outline:none;border-color:#121212}

    /* Button */
    .serve-nl-btn{display:block;width:100%;padding:.75rem 1rem;font-family:var(--flavor-font-body,sans-serif);font-size:.875rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;text-align:center;border:none;border-radius:2px;cursor:pointer;text-decoration:none;transition:opacity .15s;box-sizing:border-box}
    .serve-nl-btn:hover{opacity:.88}

    /* Fine print */
    .serve-nl-fine-print{font-size:.8125rem;color:#888;text-align:center;border-top:1px solid #e5e5e5;padding-top:2rem;margin:0}
    .serve-nl-empty{font-size:1rem;color:#666;padding:3rem 0;text-align:center}

    @media(max-width:640px){
        .serve-nl-grid{grid-template-columns:1fr}
        .serve-nl-container{padding:0 1rem}
        .serve-nl-header{padding:2rem 0 1.75rem}
    }
    </style>
    <?php
} );
