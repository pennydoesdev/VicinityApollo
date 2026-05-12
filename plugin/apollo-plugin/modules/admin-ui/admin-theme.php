<?php

defined( 'ABSPATH' ) || exit;

add_action( 'admin_head', function(): void {
    $accent    = '#c62828'; // Penny Tribune red
    $dark_bg   = '#0f0f0f';
    $dark_mid  = '#1a1a1a';
    $dark_item = '#242424';
    $text_muted= '#888';
    $text_main = '#e8e8e8';
    $text_dim  = '#aaa';
    $border    = '#2e2e2e';
    ?>
<style id="serve-admin-theme">
/* ── Base ─────────────────────────────────────────────────── */
#adminmenuback,
#adminmenuwrap {
    background: <?php echo $dark_bg; ?> !important;
}
#adminmenu {
    background: <?php echo $dark_bg; ?> !important;
    margin-top: 0 !important;
}
#adminmenu .wp-has-current-submenu > a,
#adminmenu .current > a,
#adminmenu a {
    color: <?php echo $text_dim; ?> !important;
    font-size: 12px !important;
    font-weight: 500 !important;
    letter-spacing: 0.01em !important;
}
#adminmenu a:hover,
#adminmenu li.menu-top:hover > a {
    color: #fff !important;
    background: <?php echo $dark_item; ?> !important;
}
#adminmenu .wp-has-current-submenu > a,
#adminmenu .current > a,
#adminmenu .wp-menu-open > a {
    color: #fff !important;
    background: <?php echo $dark_item; ?> !important;
}
#adminmenu .wp-menu-arrow,
#adminmenu .wp-menu-arrow div {
    background: <?php echo $dark_item; ?> !important;
}
#adminmenu .current > a,
#adminmenu .wp-has-current-submenu > a {
    border-left: 3px solid <?php echo $accent; ?> !important;
    padding-left: 13px !important;
}
#adminmenu li > a {
    border-left: 3px solid transparent !important;
    transition: border-color 0.15s, color 0.15s, background 0.15s !important;
}
#adminmenu li > a:hover {
    border-left-color: <?php echo $accent; ?>66 !important;
}
#adminmenu .wp-menu-separator {
    background: <?php echo $border; ?> !important;
    height: 1px !important;
    margin: 6px 16px !important;
}
#adminmenu .wp-submenu {
    background: <?php echo $dark_mid; ?> !important;
    border: none !important;
    padding: 4px 0 !important;
}
#adminmenu .wp-submenu a {
    font-size: 11.5px !important;
    color: <?php echo $text_muted; ?> !important;
    padding: 5px 10px 5px 28px !important;
}
#adminmenu .wp-submenu a:hover,
#adminmenu .wp-submenu .current a {
    color: #fff !important;
    background: none !important;
}
#adminmenu .wp-submenu .current a {
    color: <?php echo $accent; ?> !important;
}
#adminmenu .menu-icon-generic div.wp-menu-image::before,
#adminmenu .dashicons-before div.wp-menu-image::before {
    color: <?php echo $text_muted; ?> !important;
}
#adminmenu .current div.wp-menu-image::before,
#adminmenu .wp-has-current-submenu div.wp-menu-image::before,
#adminmenu li > a:hover div.wp-menu-image::before {
    color: <?php echo $accent; ?> !important;
}
#collapse-button,
#collapse-menu {
    color: <?php echo $text_muted; ?> !important;
    background: none !important;
}
#collapse-button:hover { color: #fff !important; }
#wpadminbar {
    background: <?php echo $dark_bg; ?> !important;
    border-bottom: 1px solid <?php echo $border; ?> !important;
}
#wpadminbar .ab-top-menu > li > .ab-item,
#wpadminbar .ab-top-menu > li.hover > .ab-item,
#wpadminbar .ab-top-menu > li > .ab-item:focus {
    color: <?php echo $text_dim; ?> !important;
}
#wpadminbar .ab-top-menu > li:hover > .ab-item,
#wpadminbar .ab-top-menu > li.hover > .ab-item {
    color: #fff !important;
    background: <?php echo $dark_item; ?> !important;
}
#wpadminbar #wp-admin-bar-site-name > .ab-item::before { color: <?php echo $accent; ?> !important; }
#wpadminbar #wp-admin-bar-new-content .ab-icon::before  { color: <?php echo $accent; ?> !important; }
#wpadminbar .ab-submenu {
    background: <?php echo $dark_mid; ?> !important;
    border: 1px solid <?php echo $border; ?> !important;
}
#wpadminbar .ab-submenu .ab-item { color: <?php echo $text_dim; ?> !important; }
#wpadminbar .ab-submenu .ab-item:hover { color: #fff !important; background: <?php echo $dark_item; ?> !important; }
#wpcontent, #wpfooter { background: #fafafa !important; }
.wrap h1, .wrap h2 { font-weight: 700 !important; letter-spacing: -0.02em !important; }
.wrap h1 { font-size: 22px !important; }
.button-primary {
    background: <?php echo $accent; ?> !important;
    border-color: <?php echo $accent; ?> !important;
    color: #fff !important;
    font-weight: 600 !important;
    letter-spacing: 0.01em !important;
    border-radius: 5px !important;
    box-shadow: none !important;
    text-shadow: none !important;
}
.button-primary:hover { background: #a91e1e !important; border-color: #a91e1e !important; }
.button { border-radius: 5px !important; font-weight: 500 !important; box-shadow: none !important; text-shadow: none !important; }
.wp-list-table th {
    font-size: 11px !important;
    text-transform: uppercase !important;
    letter-spacing: 0.05em !important;
    color: #888 !important;
    font-weight: 600 !important;
}
.wp-list-table .column-title .row-title { font-size: 14px !important; font-weight: 600 !important; }
tr.type-post:hover td, tr.type-post:hover th { background: #f5f5f5 !important; }
.notice, div.updated, div.error {
    border-radius: 6px !important;
    border-left-width: 4px !important;
    box-shadow: none !important;
}
.notice-success { border-left-color: #16a34a !important; }
.notice-error   { border-left-color: <?php echo $accent; ?> !important; }
.notice-warning { border-left-color: #d97706 !important; }
.notice-info    { border-left-color: #2563eb !important; }
.postbox { border-radius: 8px !important; border: 1px solid #e5e7eb !important; box-shadow: none !important; overflow: hidden !important; }
.postbox .postbox-header { background: #fff !important; border-bottom: 1px solid #e5e7eb !important; }
.postbox h2.hndle, .postbox .hndle {
    font-size: 12px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.06em !important;
    color: #555 !important;
    padding: 10px 12px !important;
}
.postbox .inside { padding: 12px !important; }
#serve-admin-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 18px 14px 14px;
    border-bottom: 1px solid <?php echo $border; ?>;
    margin-bottom: 4px;
}
#serve-admin-brand .brand-name { font-size: 13px; font-weight: 800; color: #fff; letter-spacing: -0.01em; line-height: 1.2; }
#serve-admin-brand .brand-sub  { font-size: 10px; color: <?php echo $text_muted; ?>; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase; }
.folded #serve-admin-brand .brand-name,
.folded #serve-admin-brand .brand-sub { display: none !important; }
.folded #serve-admin-brand { justify-content: center !important; padding: 14px 8px !important; }
input[type="text"],input[type="email"],input[type="url"],input[type="password"],
input[type="number"],input[type="search"],textarea,select {
    border-radius: 5px !important; border-color: #d1d5db !important;
    box-shadow: none !important; transition: border-color 0.15s !important;
}
input[type="text"]:focus,input[type="email"]:focus,input[type="url"]:focus,textarea:focus,select:focus {
    border-color: <?php echo $accent; ?> !important;
    box-shadow: 0 0 0 2px <?php echo $accent; ?>22 !important;
    outline: none !important;
}
#wpfooter { border-top: 1px solid #e5e7eb !important; padding: 12px 20px !important; }
</style>
<?php
} );

add_action( 'admin_footer', function(): void {
    $site_name = get_bloginfo( 'name' );
    $logo_url  = get_theme_mod( 'custom_logo' )
        ? wp_get_attachment_image_url( get_theme_mod( 'custom_logo' ), 'full' )
        : '';
    ?>
    <script>
    (function(){
        var menu = document.getElementById('adminmenuback');
        if (!menu) return;
        var brand = document.createElement('div');
        brand.id = 'serve-admin-brand';
        brand.innerHTML = <?php echo wp_json_encode(
            ( $logo_url
                ? '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $site_name ) . '" style="height:28px;width:auto;border-radius:4px;object-fit:contain;">'
                : '<span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;background:#c62828;border-radius:6px;font-size:13px;font-weight:900;color:#fff;flex-shrink:0;">P</span>'
            )
            . '<div>'
            . '<div class="brand-name">' . esc_html( $site_name ) . '</div>'
            . '<div class="brand-sub">Publication</div>'
            . '</div>'
        ); ?>;
        var adminmenu = document.getElementById('adminmenu');
        if (adminmenu && adminmenu.parentNode) {
            adminmenu.parentNode.insertBefore(brand, adminmenu);
        }
    })();
    </script>
    <?php
} );

add_action( 'login_enqueue_scripts', function(): void { ?>
<style>
body.login { background: #fafaf8 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, sans-serif !important; }
body.login #login { width: 360px !important; padding: 0 !important; }
body.login #loginform {
    background: #fff !important; border: 1px solid #e5e7eb !important;
    border-radius: 12px !important; box-shadow: 0 4px 24px rgba(0,0,0,.08) !important;
    padding: 28px 32px !important; margin-top: 20px !important;
}
body.login .button-primary.wp-element-button {
    background: #c62828 !important; border-color: #c62828 !important;
    border-radius: 7px !important; font-weight: 700 !important;
    font-size: 14px !important; height: 42px !important;
    box-shadow: none !important; text-shadow: none !important;
}
body.login .button-primary.wp-element-button:hover { background: #a91e1e !important; border-color: #a91e1e !important; }
body.login input[type="text"], body.login input[type="password"] {
    border-radius: 6px !important; border-color: #d1d5db !important;
    font-size: 14px !important; padding: 10px 12px !important; height: auto !important;
}
body.login input[type="text"]:focus, body.login input[type="password"]:focus {
    border-color: #c62828 !important; box-shadow: 0 0 0 2px #c6282822 !important; outline: none !important;
}
body.login label { font-size: 12px !important; font-weight: 600 !important; color: #374151 !important; }
body.login #backtoblog a, body.login #nav a { color: #888 !important; font-size: 12px !important; }
body.login #backtoblog a:hover, body.login #nav a:hover { color: #c62828 !important; }
</style>
<?php } );

add_filter( 'login_headerurl',  fn() => home_url() );
add_filter( 'login_headertext', fn() => get_bloginfo( 'name' ) );

add_action( 'admin_menu', function(): void {
    add_menu_page(
        'Penny Tribune', '🗞 Penny Tribune', 'edit_posts',
        'serve-hub', 'serve_hub_dashboard', '', 3
    );
    add_submenu_page( 'serve-hub', 'Dashboard',    'Dashboard',    'edit_posts',    'serve-hub',         'serve_hub_dashboard' );
    add_submenu_page( 'serve-hub', 'AI Settings',  '🤖 AI Settings','manage_options','serve-ai-settings', 'serve_ai_settings_page' );
}, 99 );

function apollo_menu_brand_map(): array {
    return [
        'edit.php' => [
            'top' => '✍ Writers Hub',
            'sub' => [ 'All Posts' => 'All Stories', 'Add New Post' => 'New Story' ],
        ],
        'edit.php?post_type=serve_podcast' => [
            'top' => '🎙 Audio Hub',
            'sub' => [ 'All Podcasts' => 'All Shows', 'Add New Podcast' => 'New Show' ],
        ],
        'edit.php?post_type=serve_election' => [ 'top' => '🗳 Election Hub' ],
        'upload.php'                         => [ 'top' => '📷 Media Hub' ],
    ];
}

add_action( 'admin_menu', function(): void {
    global $menu, $submenu;
    $map = apollo_menu_brand_map();
    if ( is_array( $menu ) ) {
        foreach ( $menu as $i => $item ) {
            if ( isset( $item[2], $map[ $item[2] ]['top'] ) ) $menu[ $i ][0] = $map[ $item[2] ]['top'];
        }
    }
    if ( is_array( $submenu ) ) {
        foreach ( $map as $slug => $entry ) {
            if ( empty( $entry['sub'] ) || ! isset( $submenu[ $slug ] ) ) continue;
            foreach ( $submenu[ $slug ] as $i => $sm ) {
                if ( isset( $sm[0], $entry['sub'][ $sm[0] ] ) ) $submenu[ $slug ][ $i ][0] = $entry['sub'][ $sm[0] ];
            }
        }
    }
}, 9999 );

add_filter( 'custom_menu_order', '__return_true' );
add_filter( 'menu_order', function( $menu_ord ) {
    if ( ! is_array( $menu_ord ) ) return $menu_ord;
    $head = [
        'edit.php?post_type=serve_video',
        'edit.php?post_type=serve_podcast',
        'edit.php',
        'edit.php?post_type=serve_election',
        'upload.php',
    ];
    $present_head = array_filter( $head, fn($s) => in_array( $s, $menu_ord, true ) );
    $tail = array_filter( $menu_ord, fn($s) => ! in_array( $s, $present_head, true ) );
    return array_merge( array_values( $present_head ), array_values( $tail ) );
} );

function serve_hub_dashboard(): void {
    if ( ! current_user_can( 'edit_posts' ) ) return;
    $post_counts = wp_count_posts( 'post' );
    $published   = (int) ( $post_counts->publish ?? 0 );
    $drafts      = (int) ( $post_counts->draft   ?? 0 );
    $scheduled   = (int) ( $post_counts->future  ?? 0 );
    $stages      = function_exists( 'serve_wf_stages' ) ? serve_wf_stages() : [];
    $recent      = get_posts([ 'numberposts' => 8, 'post_status' => ['publish','draft','future'], 'orderby' => 'modified', 'order' => 'DESC' ]);
    ?>
    <div class="wrap" style="max-width:1100px;">
        <h1 style="font-size:22px;font-weight:800;letter-spacing:-.02em;margin-bottom:24px;">
            🗞 Penny Tribune <span style="font-size:12px;font-weight:500;color:#888;letter-spacing:.04em;text-transform:uppercase;">Newsroom Dashboard</span>
        </h1>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:28px;">
            <?php foreach ( [
                [ 'Published', $published, '#16a34a', admin_url('edit.php?post_status=publish') ],
                [ 'Drafts',    $drafts,    '#d97706', admin_url('edit.php?post_status=draft')   ],
                [ 'Scheduled', $scheduled, '#2563eb', admin_url('edit.php?post_status=future')  ],
            ] as [$label,$val,$color,$url] ): ?>
            <a href="<?php echo esc_url($url); ?>" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;text-decoration:none;display:block;">
                <div style="font-size:28px;font-weight:800;color:<?php echo esc_attr($color); ?>;line-height:1;"><?php echo number_format_i18n($val); ?></div>
                <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#888;margin-top:4px;"><?php echo esc_html($label); ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
            <div style="padding:14px 18px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                <strong style="font-size:13px;font-weight:700;">Recent Posts</strong>
                <a href="<?php echo esc_url( admin_url('post-new.php') ); ?>" style="font-size:12px;font-weight:700;color:#c62828;text-decoration:none;">+ New post</a>
            </div>
            <table style="width:100%;border-collapse:collapse;">
                <?php foreach ( $recent as $p ):
                    $sc = [ 'publish'=>'#16a34a','draft'=>'#d97706','future'=>'#2563eb','pending'=>'#9333ea' ][$p->post_status] ?? '#888';
                ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:10px 18px;">
                        <a href="<?php echo esc_url( get_edit_post_link($p->ID) ); ?>" style="font-size:13px;font-weight:600;color:#111;text-decoration:none;display:block;margin-bottom:2px;">
                            <?php echo esc_html( get_the_title($p) ?: '(untitled)' ); ?>
                        </a>
                        <span style="font-size:11px;color:#888;"><?php echo esc_html( human_time_diff( strtotime($p->post_modified), time() ) . ' ago' ); ?></span>
                    </td>
                    <td style="padding:10px 18px 10px 0;text-align:right;">
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:<?php echo esc_attr($sc); ?>;"><?php echo esc_html($p->post_status); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <?php
}

add_action( 'admin_menu', function(): void {
    if ( current_user_can( 'manage_options' ) ) return;
    remove_menu_page( 'tools.php' );
    remove_submenu_page( 'themes.php', 'themes.php' );
    remove_submenu_page( 'themes.php', 'widgets.php' );
    remove_submenu_page( 'options-general.php', 'options-general.php' );
}, 999 );

add_filter( 'admin_footer_text', fn() => '<span style="font-size:12px;color:#aaa;">🗞 <strong style="color:#555;">Penny Tribune</strong> Newsroom &mdash; Powered by Apollo</span>' );
add_filter( 'update_footer',     fn() => '', 99 );

add_action( 'wp_dashboard_setup', function(): void {
    remove_meta_box( 'dashboard_primary',     'dashboard', 'side'   );
    remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side'   );
    remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
} );

add_filter( 'manage_post_posts_columns', function( array $cols ): array {
    $new = [];
    foreach ( $cols as $k => $v ) {
        $new[$k] = $v;
        if ( $k === 'title' ) $new['serve_read_time'] = '⏱ Read';
    }
    return $new;
} );

add_action( 'manage_post_posts_custom_column', function( string $col, int $post_id ): void {
    if ( $col !== 'serve_read_time' ) return;
    $words = str_word_count( wp_strip_all_tags( get_post_field( 'post_content', $post_id ) ) );
    echo '<span style="font-size:11px;color:#888;">' . esc_html( max(1,(int)ceil($words/238)) . ' min' ) . '</span>';
}, 10, 2 );
