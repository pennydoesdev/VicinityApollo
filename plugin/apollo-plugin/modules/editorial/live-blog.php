<?php
/**
 * Apollo — Feature 4: Live Updates / Live Blog
 *
 * Timestamped live coverage for breaking news, elections, court hearings,
 * weather events, police/fire/EMS incidents, press conferences.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    foreach ( [ 'post' ] as $pt ) {
        register_post_meta( $pt, '_apollo_live_status', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_apollo_live_auto_refresh', [
            'show_in_rest' => true, 'single' => true, 'type' => 'boolean',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_apollo_live_refresh_interval', [
            'show_in_rest' => true, 'single' => true, 'type' => 'integer',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

add_action( 'init', function(): void {
    if ( get_option( 'apollo_live_blog_db_v1' ) ) return;
    global $wpdb;
    $table   = $wpdb->prefix . 'apollo_live_updates';
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id    BIGINT UNSIGNED NOT NULL,
        ts         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_pinned  TINYINT(1) NOT NULL DEFAULT 0,
        reporter   VARCHAR(255) NOT NULL DEFAULT '',
        title      VARCHAR(500) NOT NULL DEFAULT '',
        body       LONGTEXT NOT NULL,
        source_url VARCHAR(2000) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY post_id_ts (post_id, ts)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'apollo_live_blog_db_v1', 1 );
} );

function apollo_get_live_updates( int $post_id, int $limit = 50 ): array {
    global $wpdb;
    $table = $wpdb->prefix . 'apollo_live_updates';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) return [];
    return (array) $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d ORDER BY is_pinned DESC, ts DESC LIMIT %d",
            $post_id, $limit
        )
    );
}

function apollo_render_live_blog( int $post_id ): string {
    $status = (string) get_post_meta( $post_id, '_apollo_live_status', true );
    if ( ! $status ) return '';

    $updates  = apollo_get_live_updates( $post_id );
    $auto     = (bool) get_post_meta( $post_id, '_apollo_live_auto_refresh', true );
    $interval = max( 30, (int) get_post_meta( $post_id, '_apollo_live_refresh_interval', true ) ?: 60 );

    $status_labels = [
        'live'     => __( 'LIVE', 'apollo-plugin' ),
        'off-air'  => __( 'Off Air', 'apollo-plugin' ),
        'resolved' => __( 'Resolved', 'apollo-plugin' ),
    ];
    $status_label = $status_labels[ $status ] ?? strtoupper( $status );

    $out  = '<div class="apollo-live-blog" id="apollo-live-blog-' . $post_id . '" data-post-id="' . $post_id . '"';
    if ( $auto ) $out .= ' data-auto-refresh="1" data-interval="' . $interval . '"';
    $out .= '>';
    $out .= '<div class="apollo-live-blog__header">';
    $out .= '<span class="apollo-live-blog__status apollo-live-blog__status--' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
    $out .= '<span class="apollo-live-blog__update-count">' . sprintf( _n( '%d update', '%d updates', count( $updates ), 'apollo-plugin' ), count( $updates ) ) . '</span>';
    $out .= '</div>';
    $out .= '<div class="apollo-live-blog__feed">';

    foreach ( $updates as $update ) {
        $ts     = strtotime( $update->ts );
        $pinned = $update->is_pinned ? ' apollo-live-blog__update--pinned' : '';
        $out .= '<article class="apollo-live-blog__update' . $pinned . '" id="live-update-' . (int) $update->id . '">';
        $out .= '<div class="apollo-live-blog__meta">';
        $out .= '<time class="apollo-live-blog__time" datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) . '</time>';
        if ( $update->reporter ) $out .= ' <span class="apollo-live-blog__reporter">' . esc_html( $update->reporter ) . '</span>';
        if ( $update->is_pinned ) $out .= ' <span class="apollo-live-blog__pin">📌 ' . esc_html__( 'Pinned', 'apollo-plugin' ) . '</span>';
        $out .= '</div>';
        if ( $update->title ) $out .= '<h3 class="apollo-live-blog__update-title">' . esc_html( $update->title ) . '</h3>';
        $out .= '<div class="apollo-live-blog__body">' . wp_kses_post( wpautop( $update->body ) ) . '</div>';
        if ( $update->source_url ) $out .= '<p class="apollo-live-blog__source"><a href="' . esc_url( $update->source_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Source', 'apollo-plugin' ) . '</a></p>';
        $out .= '</article>';
    }

    if ( empty( $updates ) ) {
        $out .= '<p class="apollo-live-blog__empty">' . esc_html__( 'Live coverage will appear here.', 'apollo-plugin' ) . '</p>';
    }

    $out .= '</div></div>';
    return $out;
}

add_action( 'wp_ajax_nopriv_apollo_live_updates', 'apollo_ajax_live_updates' );
add_action( 'wp_ajax_apollo_live_updates',        'apollo_ajax_live_updates' );

function apollo_ajax_live_updates(): void {
    $post_id = absint( $_GET['post_id'] ?? 0 );
    $nonce   = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );
    if ( ! $post_id || ! wp_verify_nonce( $nonce, 'apollo_live_' . $post_id ) ) {
        wp_send_json_error( 'invalid' );
    }
    $updates = apollo_get_live_updates( $post_id );
    ob_start();
    foreach ( $updates as $u ) {
        $ts = strtotime( $u->ts );
        echo '<article class="apollo-live-blog__update' . ( $u->is_pinned ? ' apollo-live-blog__update--pinned' : '' ) . '" id="live-update-' . (int)$u->id . '">';
        echo '<div class="apollo-live-blog__meta"><time datetime="' . esc_attr( gmdate('c',$ts) ) . '">' . esc_html( wp_date( get_option('date_format').' '.get_option('time_format'), $ts ) ) . '</time>';
        if ( $u->reporter ) echo ' <span class="apollo-live-blog__reporter">' . esc_html($u->reporter) . '</span>';
        echo '</div>';
        if ( $u->title ) echo '<h3 class="apollo-live-blog__update-title">' . esc_html($u->title) . '</h3>';
        echo '<div class="apollo-live-blog__body">' . wp_kses_post(wpautop($u->body)) . '</div>';
        echo '</article>';
    }
    $html = ob_get_clean();
    wp_send_json_success( [ 'html' => $html ] );
}

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-live-blog', __( '📡 Live Blog', 'apollo-plugin' ), 'apollo_live_blog_meta_box', 'post', 'normal', 'high' );
} );

function apollo_live_blog_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_live_blog_' . $post->ID, 'apollo_live_blog_nonce' );
    $status   = (string) get_post_meta( $post->ID, '_apollo_live_status', true );
    $auto     = (bool)   get_post_meta( $post->ID, '_apollo_live_auto_refresh', true );
    $interval = (int)    get_post_meta( $post->ID, '_apollo_live_refresh_interval', true ) ?: 60;
    ?>
    <p>
        <label><strong><?php esc_html_e( 'Live Status', 'apollo-plugin' ); ?></strong></label><br>
        <select name="apollo_live_status">
            <option value=""><?php esc_html_e( '— Not a live blog —', 'apollo-plugin' ); ?></option>
            <option value="live"    <?php selected( $status, 'live' ); ?>><?php esc_html_e( 'Live', 'apollo-plugin' ); ?></option>
            <option value="off-air" <?php selected( $status, 'off-air' ); ?>><?php esc_html_e( 'Off Air', 'apollo-plugin' ); ?></option>
            <option value="resolved"<?php selected( $status, 'resolved' ); ?>><?php esc_html_e( 'Resolved', 'apollo-plugin' ); ?></option>
        </select>
    </p>
    <p>
        <label><input type="checkbox" name="apollo_live_auto_refresh" value="1" <?php checked( $auto ); ?>>
        <?php esc_html_e( 'Auto-refresh every', 'apollo-plugin' ); ?>
        <input type="number" name="apollo_live_refresh_interval" value="<?php echo esc_attr( $interval ); ?>" min="10" style="width:70px">
        <?php esc_html_e( 'seconds', 'apollo-plugin' ); ?></label>
    </p>
    <hr>
    <h4><?php esc_html_e( 'Add Update', 'apollo-plugin' ); ?></h4>
    <p><input type="text" name="live_update_title" placeholder="<?php esc_attr_e( 'Update headline (optional)', 'apollo-plugin' ); ?>" style="width:100%"></p>
    <p><textarea name="live_update_body" rows="4" style="width:100%" placeholder="<?php esc_attr_e( 'Update text…', 'apollo-plugin' ); ?>"></textarea></p>
    <p>
        <input type="text" name="live_update_reporter" placeholder="<?php esc_attr_e( 'Reporter name', 'apollo-plugin' ); ?>" style="width:49%">
        <input type="url"  name="live_update_source_url" placeholder="<?php esc_attr_e( 'Source URL', 'apollo-plugin' ); ?>" style="width:49%">
    </p>
    <p><label><input type="checkbox" name="live_update_pinned" value="1"> <?php esc_html_e( 'Pin this update', 'apollo-plugin' ); ?></label></p>
    <?php
}

add_action( 'save_post_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_live_blog_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_live_blog_nonce'] ) ), 'apollo_live_blog_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    update_post_meta( $post_id, '_apollo_live_status', sanitize_key( wp_unslash( $_POST['apollo_live_status'] ?? '' ) ) );
    update_post_meta( $post_id, '_apollo_live_auto_refresh', ! empty( $_POST['apollo_live_auto_refresh'] ) );
    update_post_meta( $post_id, '_apollo_live_refresh_interval', absint( $_POST['apollo_live_refresh_interval'] ?? 60 ) );

    $body = sanitize_textarea_field( wp_unslash( $_POST['live_update_body'] ?? '' ) );
    if ( $body ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'apollo_live_updates', [
            'post_id'    => $post_id,
            'ts'         => current_time( 'mysql' ),
            'is_pinned'  => ! empty( $_POST['live_update_pinned'] ) ? 1 : 0,
            'reporter'   => sanitize_text_field( wp_unslash( $_POST['live_update_reporter'] ?? '' ) ),
            'title'      => sanitize_text_field( wp_unslash( $_POST['live_update_title'] ?? '' ) ),
            'body'       => wp_kses_post( wp_unslash( $body ) ),
            'source_url' => esc_url_raw( wp_unslash( $_POST['live_update_source_url'] ?? '' ) ),
        ] );
    }
} );

add_filter( 'apollo_render_live-blog', function( $html, array $args ): string {
    $post_id = absint( $args['post_id'] ?? get_the_ID() );
    return $post_id ? apollo_render_live_blog( $post_id ) : '';
}, 10, 2 );
