<?php

defined( 'ABSPATH' ) || exit;

define( 'SERVE_FUTURE_META',      '_serve_future' );
define( 'SERVE_FUTURE_CRON_HOOK', 'serve_future_execute' );
define( 'SERVE_FUTURE_LOG_MAX',   500 );
define( 'SERVE_FUTURE_INDEX_OPT', 'serve_future_index' );

function serve_future_maybe_create_table(): void {
    if ( get_option( 'serve_future_db_ver' ) === '2' ) return;
    global $wpdb;
    $t = $wpdb->prefix . 'serve_future_log';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE IF NOT EXISTS {$t} (
        id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        title   VARCHAR(400)    NOT NULL DEFAULT '',
        action  VARCHAR(80)     NOT NULL DEFAULT '',
        detail  VARCHAR(600)    NOT NULL DEFAULT '',
        ts      DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
        PRIMARY KEY (id),
        KEY post_id (post_id),
        KEY ts (ts)
    ) {$wpdb->get_charset_collate()};" );
    update_option( 'serve_future_db_ver', '2', false );
}
add_action( 'init', 'serve_future_maybe_create_table', 1 );

function serve_future_defaults(): array {
    return [
        'enabled'      => false,
        'action'       => 'draft',
        'date_ts'      => 0,
        'new_status'   => '',
        'taxonomy'     => '',
        'terms'        => '',
        'redirect_url' => '',
        'notify_email' => '',
        'relative'     => '',
    ];
}

function serve_future_get( int $post_id ): array {
    $saved = get_post_meta( $post_id, SERVE_FUTURE_META, true );
    return is_array( $saved ) ? array_merge( serve_future_defaults(), $saved ) : serve_future_defaults();
}

function serve_future_save( int $post_id, array $data ): void {
    $data['enabled'] = ! empty( $data['enabled'] );
    $data['date_ts'] = absint( $data['date_ts'] ?? 0 );
    update_post_meta( $post_id, SERVE_FUTURE_META, $data );
    serve_future_reschedule( $post_id, $data );
    serve_future_index_update( $post_id, $data );
}

function serve_future_clear( int $post_id ): void {
    delete_post_meta( $post_id, SERVE_FUTURE_META );
    wp_clear_scheduled_hook( SERVE_FUTURE_CRON_HOOK, [ $post_id ] );
    serve_future_index_remove( $post_id );
}

function serve_future_index_update( int $post_id, array $data ): void {
    $idx = (array) get_option( SERVE_FUTURE_INDEX_OPT, [] );
    if ( ! empty( $data['enabled'] ) && ! empty( $data['date_ts'] ) && $data['date_ts'] > time() ) {
        $idx[ $post_id ] = [ 'ts' => $data['date_ts'], 'action' => $data['action'] ];
    } else {
        unset( $idx[ $post_id ] );
    }
    update_option( SERVE_FUTURE_INDEX_OPT, $idx, false );
}

function serve_future_index_remove( int $post_id ): void {
    $idx = (array) get_option( SERVE_FUTURE_INDEX_OPT, [] );
    unset( $idx[ $post_id ] );
    update_option( SERVE_FUTURE_INDEX_OPT, $idx, false );
}

function serve_future_reschedule( int $post_id, array $data ): void {
    wp_clear_scheduled_hook( SERVE_FUTURE_CRON_HOOK, [ $post_id ] );
    if ( empty( $data['enabled'] ) || empty( $data['date_ts'] ) ) return;
    if ( $data['date_ts'] <= time() ) return;
    wp_schedule_single_event( (int) $data['date_ts'], SERVE_FUTURE_CRON_HOOK, [ $post_id ] );
}

add_action( 'transition_post_status', 'serve_future_handle_relative', 10, 3 );

function serve_future_handle_relative( string $new_status, string $old_status, WP_Post $post ): void {
    if ( $new_status !== 'publish' ) return;
    $cfg = serve_future_get( $post->ID );
    if ( empty( $cfg['enabled'] ) || empty( $cfg['relative'] ) ) return;

    $offset = sanitize_text_field( $cfg['relative'] );
    $base   = strtotime( $post->post_date_gmt . ' UTC' ) ?: time();
    $ts     = strtotime( $offset, $base );
    if ( ! $ts || $ts <= time() ) return;

    $cfg['date_ts'] = $ts;
    serve_future_save( $post->ID, $cfg );
}

add_action( SERVE_FUTURE_CRON_HOOK, 'serve_future_execute_action' );

function serve_future_execute_action( int $post_id ): void {
    $post = get_post( $post_id );
    if ( ! $post || $post->post_status === 'trash' ) return;

    $cfg    = serve_future_get( $post_id );
    $action = $cfg['action'] ?? 'draft';
    $detail = '';

    switch ( $action ) {
        case 'draft':
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            $detail = 'Changed to Draft';
            break;
        case 'trash':
            wp_trash_post( $post_id );
            $detail = 'Moved to Trash';
            break;
        case 'delete':
            serve_future_log( $post_id, $post->post_title, $action, 'Permanently deleted' );
            serve_future_send_notify( $post, $cfg, 'Permanently deleted' );
            delete_post_meta( $post_id, SERVE_FUTURE_META );
            serve_future_index_remove( $post_id );
            wp_delete_post( $post_id, true );
            return;
        case 'status':
            $new = sanitize_key( $cfg['new_status'] ?? '' ) ?: 'draft';
            wp_update_post( [ 'ID' => $post_id, 'post_status' => $new ] );
            $detail = 'Status → ' . $new;
            break;
        case 'terms_add':
        case 'terms_remove':
        case 'terms_replace':
            $detail = serve_future_apply_terms( $post_id, $action, $cfg );
            break;
        case 'redirect':
            $url = esc_url_raw( $cfg['redirect_url'] ?? '' );
            if ( $url ) {
                update_post_meta( $post_id, '_serve_future_redirect', $url );
                wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
                $detail = 'Redirect → ' . $url;
            }
            break;
    }

    $cfg['enabled'] = false;
    update_post_meta( $post_id, SERVE_FUTURE_META, $cfg );
    serve_future_index_remove( $post_id );

    serve_future_log( $post_id, $post->post_title, $action, $detail ?: $action );
    serve_future_send_notify( $post, $cfg, $detail ?: $action );
}

function serve_future_apply_terms( int $post_id, string $action, array $cfg ): string {
    $tax   = sanitize_text_field( $cfg['taxonomy'] ?? '' );
    $slugs = array_filter( array_map( 'trim', explode( ',', $cfg['terms'] ?? '' ) ) );
    if ( ! $tax || ! $slugs || ! taxonomy_exists( $tax ) ) return '';

    $term_ids = [];
    foreach ( $slugs as $s ) {
        $t = get_term_by( 'slug', $s, $tax );
        if ( $t ) $term_ids[] = $t->term_id;
    }
    if ( ! $term_ids ) return '';

    if ( $action === 'terms_add' ) {
        wp_set_post_terms( $post_id, $term_ids, $tax, true );
    } elseif ( $action === 'terms_remove' ) {
        $existing  = wp_get_post_terms( $post_id, $tax, [ 'fields' => 'ids' ] );
        $remaining = array_diff( (array) $existing, $term_ids );
        wp_set_post_terms( $post_id, array_values( $remaining ), $tax );
    } else {
        wp_set_post_terms( $post_id, $term_ids, $tax );
    }

    $verb = [ 'terms_add' => 'Added', 'terms_remove' => 'Removed', 'terms_replace' => 'Replaced' ][ $action ] ?? 'Updated';
    return "{$verb} [{$tax}]: " . implode( ', ', $slugs );
}

add_action( 'template_redirect', function (): void {
    if ( ! is_singular() ) return;
    $pid = get_queried_object_id();
    $url = $pid ? get_post_meta( $pid, '_serve_future_redirect', true ) : '';
    if ( $url ) { wp_redirect( esc_url( $url ), 301 ); exit; }
} );

function serve_future_log( int $post_id, string $title, string $action, string $detail ): void {
    global $wpdb;
    $t = $wpdb->prefix . 'serve_future_log';
    $wpdb->insert( $t, [
        'post_id' => $post_id,
        'title'   => mb_substr( $title,  0, 400 ),
        'action'  => mb_substr( $action, 0, 80  ),
        'detail'  => mb_substr( $detail, 0, 600 ),
        'ts'      => current_time( 'mysql', true ),
    ], [ '%d', '%s', '%s', '%s', '%s' ] );

    $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
    if ( $count > SERVE_FUTURE_LOG_MAX ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} ORDER BY id ASC LIMIT %d", $count - SERVE_FUTURE_LOG_MAX ) );
    }
}

function serve_future_get_log( int $limit = 100, int $offset = 0 ): array {
    global $wpdb;
    $t = $wpdb->prefix . 'serve_future_log';
    return $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$t} ORDER BY ts DESC LIMIT %d OFFSET %d", $limit, $offset ),
        ARRAY_A
    ) ?: [];
}

function serve_future_send_notify( WP_Post $post, array $cfg, string $detail ): void {
    $email = sanitize_email( $cfg['notify_email'] ?? '' )
          ?: sanitize_email( get_option( 'serve_future_notify_email', '' ) );
    if ( ! $email ) return;

    $site = get_bloginfo( 'name' );
    $url  = get_permalink( $post->ID ) ?: admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
    wp_mail(
        $email,
        "[{$site}] Serve Future: \"{$post->post_title}\"",
        "A scheduled action ran on your site.\n\n"
        . "Post:   {$post->post_title}\n"
        . "Action: {$detail}\n"
        . "URL:    {$url}\n"
        . "Time:   " . current_time( 'mysql' ) . "\n\n"
        . "— {$site}"
    );
}

add_action( 'save_post', 'serve_future_save_post_hook', 20, 2 );

function serve_future_save_post_hook( int $post_id, WP_Post $post ): void {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! isset( $_POST['_serve_future_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['_serve_future_nonce'], 'serve_future_save_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    serve_future_process_input( $post_id, $_POST );
}

function serve_future_process_input( int $post_id, array $in ): void {
    if ( empty( $in['_sfuture_enabled'] ) ) {
        serve_future_clear( $post_id );
        return;
    }

    $ts  = 0;
    $rel = sanitize_text_field( $in['_sfuture_relative'] ?? '' );

    if ( ! $rel ) {
        $d = sanitize_text_field( $in['_sfuture_date'] ?? '' );
        $t = sanitize_text_field( $in['_sfuture_time'] ?? '00:00' );
        if ( $d ) $ts = (int) get_gmt_from_date( $d . ' ' . $t . ':00', 'U' );
    } else {
        $post = get_post( $post_id );
        if ( $post && $post->post_status === 'publish' ) {
            $base = strtotime( $post->post_date_gmt . ' UTC' ) ?: time();
            $ts   = (int) strtotime( $rel, $base );
        }
    }

    serve_future_save( $post_id, [
        'enabled'      => true,
        'action'       => sanitize_key( $in['_sfuture_action']       ?? 'draft' ),
        'date_ts'      => $ts,
        'new_status'   => sanitize_key( $in['_sfuture_new_status']   ?? '' ),
        'taxonomy'     => sanitize_text_field( $in['_sfuture_taxonomy']      ?? '' ),
        'terms'        => sanitize_text_field( $in['_sfuture_terms']         ?? '' ),
        'redirect_url' => esc_url_raw( $in['_sfuture_redirect_url']  ?? '' ),
        'notify_email' => sanitize_email( $in['_sfuture_notify_email'] ?? '' ),
        'relative'     => $rel,
    ] );
}

add_action( 'init', function (): void {
    register_post_meta( '', SERVE_FUTURE_META, [
        'show_in_rest'      => [
            'schema' => [
                'type'                 => 'object',
                'additionalProperties' => true,
                'properties' => [
                    'enabled'      => [ 'type' => 'boolean' ],
                    'action'       => [ 'type' => 'string'  ],
                    'date_ts'      => [ 'type' => 'integer' ],
                    'new_status'   => [ 'type' => 'string'  ],
                    'taxonomy'     => [ 'type' => 'string'  ],
                    'terms'        => [ 'type' => 'string'  ],
                    'redirect_url' => [ 'type' => 'string'  ],
                    'notify_email' => [ 'type' => 'string'  ],
                    'relative'     => [ 'type' => 'string'  ],
                ],
            ],
        ],
        'single'            => true,
        'type'              => 'object',
        'sanitize_callback' => static function( $val ): array {
            if ( ! is_array( $val ) ) {
                $val = (array) $val;
            }
            $defaults = serve_future_defaults();
            return array_merge( $defaults, array_intersect_key( $val, $defaults ) );
        },
        'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
    ] );
}, 20 );

function serve_future_enabled_post_types(): array {
    $saved = get_option( 'serve_future_post_types', [] );
    return empty( $saved )
        ? array_keys( get_post_types( [ 'public' => true ] ) )
        : (array) $saved;
}

function serve_future_action_options(): array {
    return [
        'draft'         => '📄 Unpublish → Draft',
        'trash'         => '🗑 Move to Trash',
        'delete'        => '💥 Delete Permanently',
        'status'        => '🔄 Change Status',
        'terms_add'     => '🏷 Add Taxonomy Terms',
        'terms_remove'  => '🏷 Remove Taxonomy Terms',
        'terms_replace' => '🏷 Replace Taxonomy Terms',
        'redirect'      => '↪️ Redirect to URL',
    ];
}

add_action( 'admin_menu', function (): void {
    add_submenu_page( 'tools.php', 'Serve Future', '⏰ Serve Future', 'manage_options', 'serve-future', 'serve_future_admin_page' );
}, 25 );

function serve_future_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) return;

    if ( isset( $_POST['serve_future_save'] ) && check_admin_referer( 'serve_future_settings' ) ) {
        $types = array_map( 'sanitize_text_field', (array) ( $_POST['serve_future_post_types'] ?? [] ) );
        update_option( 'serve_future_post_types', $types );
        update_option( 'serve_future_notify_email', sanitize_email( $_POST['serve_future_notify_email'] ?? '' ) );
        echo '<div class="notice notice-success is-dismissible"><p>✅ Settings saved.</p></div>';
    }

    if ( isset( $_POST['serve_future_clear_log'] ) && check_admin_referer( 'serve_future_clear_log' ) ) {
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'serve_future_log' );
        echo '<div class="notice notice-success is-dismissible"><p>🗑 Log cleared.</p></div>';
    }

    $all_types    = get_post_types( [ 'public' => true ], 'objects' );
    $saved_types  = (array) get_option( 'serve_future_post_types', [] );
    $notify_email = get_option( 'serve_future_notify_email', '' );
    $log          = serve_future_get_log( 200 );
    $tz           = wp_timezone();

    $raw_idx  = (array) get_option( SERVE_FUTURE_INDEX_OPT, [] );
    $upcoming = [];
    $now      = time();
    foreach ( $raw_idx as $pid => $entry ) {
        if ( empty( $entry['ts'] ) || $entry['ts'] <= $now ) continue;
        $upcoming[] = [ 'post_id' => (int) $pid, 'ts' => (int) $entry['ts'], 'action' => $entry['action'] ?? 'draft' ];
    }
    usort( $upcoming, fn( $a, $b ) => $a['ts'] <=> $b['ts'] );
    ?>
    <div class="wrap" style="max-width:980px;">
    <h1>⏰ Serve Future</h1>
    <p style="color:#666;">Schedule automatic post actions: unpublish, trash, delete, status change, taxonomy updates, or redirect.</p>
    <form method="post">
        <?php wp_nonce_field( 'serve_future_settings' ); ?>
        <h2><?php esc_html_e( 'Enabled Post Types', 'serve' ); ?></h2>
        <div style="display:flex;flex-wrap:wrap;gap:8px 18px;margin-bottom:20px;">
            <?php foreach ( $all_types as $slug => $obj ) : ?>
            <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="serve_future_post_types[]" value="<?php echo esc_attr($slug); ?>"
                    <?php checked( empty($saved_types) || in_array($slug,$saved_types,true) ); ?>>
                <?php echo esc_html( $obj->labels->singular_name ); ?>
                <code style="font-size:11px;"><?php echo esc_html($slug); ?></code>
            </label>
            <?php endforeach; ?>
        </div>
        <h2><?php esc_html_e( 'Global Notification Email', 'serve' ); ?></h2>
        <input type="email" name="serve_future_notify_email"
               value="<?php echo esc_attr($notify_email); ?>"
               placeholder="editor@pennytribune.com"
               style="width:320px;">
        <input type="hidden" name="serve_future_save" value="1">
        <?php submit_button( 'Save Settings', 'primary', '', false ); ?>
    </form>
    </div>
    <?php
}

add_shortcode( 'serve_expiry_date', function ( array $atts ): string {
    $a = shortcode_atts( [
        'format' => 'F j, Y \a\t g:i A',
        'before' => '',
        'after'  => '',
        'none'   => '',
    ], $atts, 'serve_expiry_date' );

    $pid = get_the_ID();
    if ( ! $pid ) return esc_html( $a['none'] );

    $cfg = serve_future_get( $pid );
    if ( ! $cfg['enabled'] || ! $cfg['date_ts'] ) return esc_html( $a['none'] );

    $date = ( new DateTimeImmutable() )->setTimestamp( $cfg['date_ts'] )->setTimezone( wp_timezone() )->format( $a['format'] );
    return wp_kses_post( $a['before'] ) . esc_html( $date ) . wp_kses_post( $a['after'] );
} );

add_action( 'updated_post_meta', function ( int $mid, int $pid, string $key, $val ): void {
    if ( $key !== SERVE_FUTURE_META || ! is_array( $val ) ) return;
    if ( empty( $val['enabled'] ) || empty( $val['date_ts'] ) || $val['date_ts'] <= time() ) return;
    if ( ! wp_next_scheduled( SERVE_FUTURE_CRON_HOOK, [ $pid ] ) ) {
        wp_schedule_single_event( (int) $val['date_ts'], SERVE_FUTURE_CRON_HOOK, [ $pid ] );
    }
}, 10, 4 );

add_action( 'trashed_post',       'serve_future_clear' );
add_action( 'before_delete_post', 'serve_future_clear' );
