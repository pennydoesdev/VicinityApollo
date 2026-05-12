<?php
/**
 * Apollo — Feature 3: Breaking News Bar
 *
 * Sitewide breaking/developing alert system rendered through the theme header.
 * Labels: Breaking | Developing | Live | Election Alert | Weather Alert |
 *         Traffic Alert | Public Safety Alert
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ── Labels
function apollo_breaking_labels(): array {
    return [
        'breaking'      => __( 'Breaking', 'apollo-plugin' ),
        'developing'    => __( 'Developing', 'apollo-plugin' ),
        'live'          => __( 'Live', 'apollo-plugin' ),
        'election'      => __( 'Election Alert', 'apollo-plugin' ),
        'weather'       => __( 'Weather Alert', 'apollo-plugin' ),
        'traffic'       => __( 'Traffic Alert', 'apollo-plugin' ),
        'public-safety' => __( 'Public Safety Alert', 'apollo-plugin' ),
    ];
}

// ── Get active breaking bar data
function apollo_breaking_bar_data(): ?array {
    $bar = get_option( 'apollo_breaking_bar', [] );
    if ( empty( $bar ) || empty( $bar['enabled'] ) ) return null;

    $now = current_time( 'timestamp' );
    if ( ! empty( $bar['start_time'] ) && strtotime( $bar['start_time'] ) > $now ) return null;
    if ( ! empty( $bar['end_time'] )   && strtotime( $bar['end_time'] )   < $now ) return null;

    return $bar;
}

// ── Render the bar
function apollo_breaking_bar_html(): string {
    $bar = apollo_breaking_bar_data();
    if ( ! $bar ) return '';

    $label    = sanitize_key( $bar['label'] ?? 'breaking' );
    $labels   = apollo_breaking_labels();
    $label_text = $labels[ $label ] ?? __( 'Breaking', 'apollo-plugin' );
    $headline = wp_kses_post( $bar['headline'] ?? '' );
    $link     = esc_url( $bar['link'] ?? '' );
    $style    = sanitize_key( $bar['style'] ?? 'red' );

    if ( ! $headline ) return '';

    $inner = $link
        ? '<a href="' . $link . '" class="apollo-breaking-bar__link">' . $headline . '</a>'
        : '<span class="apollo-breaking-bar__text">' . $headline . '</span>';

    return '<div class="apollo-breaking-bar apollo-breaking-bar--' . esc_attr( $style ) . '" role="alert" aria-live="polite">'
        . '<span class="apollo-breaking-bar__label apollo-breaking-bar__label--' . esc_attr( $label ) . '">' . esc_html( $label_text ) . '</span>'
        . $inner
        . '</div>';
}

// ── Hook into theme header
add_action( 'apollo_breaking_bar', function(): void {
    echo apollo_breaking_bar_html(); // phpcs:ignore
} );

// ── Admin settings page
add_action( 'admin_menu', function(): void {
    add_menu_page(
        __( 'Breaking News', 'apollo-plugin' ),
        __( 'Breaking News', 'apollo-plugin' ),
        'edit_posts',
        'apollo-breaking-news',
        'apollo_breaking_news_page',
        'dashicons-megaphone',
        4
    );
} );

add_action( 'admin_post_apollo_save_breaking_bar', function(): void {
    check_admin_referer( 'apollo_breaking_bar_save' );
    if ( ! current_user_can( 'edit_posts' ) ) wp_die( 'Forbidden' );

    $data = [
        'enabled'    => ! empty( $_POST['enabled'] ),
        'label'      => sanitize_key( $_POST['label'] ?? 'breaking' ),
        'headline'   => sanitize_text_field( wp_unslash( $_POST['headline'] ?? '' ) ),
        'link'       => esc_url_raw( wp_unslash( $_POST['link'] ?? '' ) ),
        'start_time' => sanitize_text_field( wp_unslash( $_POST['start_time'] ?? '' ) ),
        'end_time'   => sanitize_text_field( wp_unslash( $_POST['end_time'] ?? '' ) ),
        'priority'   => absint( $_POST['priority'] ?? 5 ),
        'location'   => sanitize_key( $_POST['location'] ?? 'sitewide' ),
        'style'      => sanitize_key( $_POST['style'] ?? 'red' ),
    ];
    update_option( 'apollo_breaking_bar', $data );
    wp_redirect( admin_url( 'admin.php?page=apollo-breaking-news&saved=1' ) );
    exit;
} );

function apollo_breaking_news_page(): void {
    if ( ! current_user_can( 'edit_posts' ) ) return;
    $bar    = get_option( 'apollo_breaking_bar', [] );
    $labels = apollo_breaking_labels();
    $saved  = ! empty( $_GET['saved'] );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Breaking News Bar', 'apollo-plugin' ); ?></h1>
        <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'apollo-plugin' ); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'apollo_breaking_bar_save' ); ?>
            <input type="hidden" name="action" value="apollo_save_breaking_bar">
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Enable Bar', 'apollo-plugin' ); ?></th>
                    <td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $bar['enabled'] ) ); ?>> <?php esc_html_e( 'Show breaking news bar', 'apollo-plugin' ); ?></label></td></tr>
                <tr><th><?php esc_html_e( 'Label', 'apollo-plugin' ); ?></th>
                    <td><select name="label"><?php foreach ( $labels as $k => $v ) echo '<option value="' . esc_attr($k) . '"' . selected( $bar['label'] ?? 'breaking', $k, false ) . '>' . esc_html($v) . '</option>'; ?></select></td></tr>
                <tr><th><?php esc_html_e( 'Headline', 'apollo-plugin' ); ?></th>
                    <td><input type="text" name="headline" value="<?php echo esc_attr( $bar['headline'] ?? '' ); ?>" class="large-text"></td></tr>
                <tr><th><?php esc_html_e( 'Link URL', 'apollo-plugin' ); ?></th>
                    <td><input type="url" name="link" value="<?php echo esc_attr( $bar['link'] ?? '' ); ?>" class="large-text"></td></tr>
                <tr><th><?php esc_html_e( 'Start Time', 'apollo-plugin' ); ?></th>
                    <td><input type="datetime-local" name="start_time" value="<?php echo esc_attr( $bar['start_time'] ?? '' ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'End Time', 'apollo-plugin' ); ?></th>
                    <td><input type="datetime-local" name="end_time" value="<?php echo esc_attr( $bar['end_time'] ?? '' ); ?>"></td></tr>
                <tr><th><?php esc_html_e( 'Style', 'apollo-plugin' ); ?></th>
                    <td><select name="style">
                        <option value="red" <?php selected( $bar['style'] ?? 'red', 'red' ); ?>><?php esc_html_e( 'Red (Breaking)', 'apollo-plugin' ); ?></option>
                        <option value="orange" <?php selected( $bar['style'] ?? '', 'orange' ); ?>><?php esc_html_e( 'Orange (Developing)', 'apollo-plugin' ); ?></option>
                        <option value="blue" <?php selected( $bar['style'] ?? '', 'blue' ); ?>><?php esc_html_e( 'Blue (Alert)', 'apollo-plugin' ); ?></option>
                        <option value="green" <?php selected( $bar['style'] ?? '', 'green' ); ?>><?php esc_html_e( 'Green (Resolved)', 'apollo-plugin' ); ?></option>
                    </select></td></tr>
            </table>
            <?php submit_button( __( 'Save Breaking Bar', 'apollo-plugin' ) ); ?>
        </form>
    </div>
    <?php
}

// ── Inline CSS for bar
add_action( 'wp_head', function(): void {
    if ( ! apollo_breaking_bar_data() ) return;
    echo '<style>.apollo-breaking-bar{display:flex;align-items:center;gap:10px;padding:10px 20px;font-size:14px;font-weight:600;line-height:1.4;z-index:9999;width:100%;box-sizing:border-box;}
.apollo-breaking-bar--red{background:#c0392b;color:#fff;}
.apollo-breaking-bar--orange{background:#e67e22;color:#fff;}
.apollo-breaking-bar--blue{background:#2980b9;color:#fff;}
.apollo-breaking-bar--green{background:#27ae60;color:#fff;}
.apollo-breaking-bar__label{text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;padding:2px 8px;background:rgba(0,0,0,.2);border-radius:3px;font-size:12px;}
.apollo-breaking-bar__link,.apollo-breaking-bar__text{flex:1;}
.apollo-breaking-bar__link{color:inherit;text-decoration:underline;}</style>';
} );

// ── apollo_render bridge ─────────────────────────────────────────────────────
// Allows theme to call apollo_render('breaking-bar') and get HTML as a string.
add_filter( 'apollo_render_breaking-bar', function( $html, array $args ): string {
    return apollo_breaking_bar_html();
}, 10, 2 );
