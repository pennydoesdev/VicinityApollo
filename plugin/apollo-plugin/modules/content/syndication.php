<?php
/**
 * Apollo — Feature 40: Syndication & Wire/Source Attribution
 * Feature 39: Embargo & Scheduled Release Controls
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    $metas = [
        '_apollo_external_attribution' => 'string',
        '_apollo_wire_label'           => 'string',
        '_apollo_partner_label'        => 'string',
        '_apollo_original_reporting'   => 'boolean',
        '_apollo_republished'          => 'boolean',
        '_apollo_canonical_source_url' => 'string',
        '_apollo_syndication_notes'    => 'string',
        '_apollo_embargo_until'        => 'string',
        '_apollo_embargo_active'       => 'boolean',
        '_apollo_scheduled_hp_slot'    => 'string',
        '_apollo_embargo_warning'      => 'string',
    ];
    foreach ( [ 'post', 'page', 'serve_video', 'serve_episode' ] as $pt ) {
        foreach ( $metas as $key => $type ) {
            register_post_meta( $pt, $key, [
                'show_in_rest'  => true, 'single' => true, 'type' => $type,
                'auth_callback' => fn() => current_user_can( 'edit_posts' ),
            ] );
        }
    }
} );

function apollo_attribution_badge_html( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $wire     = (string) get_post_meta( $post_id, '_apollo_wire_label', true );
    $partner  = (string) get_post_meta( $post_id, '_apollo_partner_label', true );
    $ext_attr = (string) get_post_meta( $post_id, '_apollo_external_attribution', true );
    $canon    = (string) get_post_meta( $post_id, '_apollo_canonical_source_url', true );
    $is_repub = (bool)   get_post_meta( $post_id, '_apollo_republished', true );
    $is_orig  = (bool)   get_post_meta( $post_id, '_apollo_original_reporting', true );

    $out = '';
    if ( $is_orig )  $out .= '<span class="apollo-attribution apollo-attribution--original">' . esc_html__( 'Original Reporting', 'apollo-plugin' ) . '</span>';
    if ( $wire )     $out .= '<span class="apollo-attribution apollo-attribution--wire">' . esc_html( $wire ) . '</span>';
    if ( $partner )  $out .= '<span class="apollo-attribution apollo-attribution--partner">' . esc_html__( 'In partnership with', 'apollo-plugin' ) . ' ' . esc_html( $partner ) . '</span>';
    if ( $ext_attr ) {
        $inner = $canon
            ? '<a href="' . esc_url($canon) . '" target="_blank" rel="noopener">' . esc_html($ext_attr) . '</a>'
            : esc_html( $ext_attr );
        $out .= '<span class="apollo-attribution apollo-attribution--external">' . esc_html__('Source:', 'apollo-plugin') . ' ' . $inner . '</span>';
    }
    if ( $is_repub ) $out .= '<span class="apollo-attribution apollo-attribution--republished">' . esc_html__( 'Republished', 'apollo-plugin' ) . '</span>';

    return $out ? '<div class="apollo-attribution-row">' . $out . '</div>' : '';
}

add_filter( 'apollo_render_attribution', function( $html, array $args ): string {
    return apollo_attribution_badge_html( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-syndication', __( '🌐 Source Attribution & Embargo', 'apollo-plugin' ), 'apollo_syndication_meta_box', [ 'post', 'page', 'serve_video', 'serve_episode' ], 'side', 'low' );
} );

function apollo_syndication_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_synd_' . $post->ID, 'apollo_synd_nonce' );
    $fields = [
        '_apollo_wire_label'           => __( 'Wire Service Label (e.g. AP, Reuters)', 'apollo-plugin' ),
        '_apollo_partner_label'        => __( 'Partner Content Label', 'apollo-plugin' ),
        '_apollo_external_attribution' => __( 'External Source Name', 'apollo-plugin' ),
        '_apollo_canonical_source_url' => __( 'Original Source URL', 'apollo-plugin' ),
        '_apollo_embargo_until'        => __( 'Embargo Until (datetime)', 'apollo-plugin' ),
    ];
    foreach ( $fields as $key => $label ) {
        $val  = (string) get_post_meta( $post->ID, $key, true );
        $type = str_contains( $key, 'url' ) ? 'url' : ( str_contains( $key, 'until' ) ? 'datetime-local' : 'text' );
        echo '<p><label>' . esc_html($label) . '<br><input type="' . esc_attr($type) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%"></label></p>';
    }
    $is_orig = (bool) get_post_meta( $post->ID, '_apollo_original_reporting', true );
    $is_repub= (bool) get_post_meta( $post->ID, '_apollo_republished', true );
    $embargo = (bool) get_post_meta( $post->ID, '_apollo_embargo_active', true );
    echo '<p>';
    echo '<label><input type="checkbox" name="_apollo_original_reporting" value="1" ' . checked($is_orig,true,false) . '> ' . esc_html__('Original Reporting','apollo-plugin') . '</label><br>';
    echo '<label><input type="checkbox" name="_apollo_republished" value="1" ' . checked($is_repub,true,false) . '> ' . esc_html__('Republished Article','apollo-plugin') . '</label><br>';
    echo '<label><input type="checkbox" name="_apollo_embargo_active" value="1" ' . checked($embargo,true,false) . '> ' . esc_html__('⛔ Embargo active','apollo-plugin') . '</label>';
    echo '</p>';
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_synd_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_synd_nonce'] ) ), 'apollo_synd_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    foreach ( [ '_apollo_wire_label', '_apollo_partner_label', '_apollo_external_attribution', '_apollo_canonical_source_url', '_apollo_embargo_until' ] as $k ) {
        update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[$k] ?? '' ) ) );
    }
    update_post_meta( $post_id, '_apollo_original_reporting', ! empty( $_POST['_apollo_original_reporting'] ) );
    update_post_meta( $post_id, '_apollo_republished',        ! empty( $_POST['_apollo_republished'] ) );
    update_post_meta( $post_id, '_apollo_embargo_active',     ! empty( $_POST['_apollo_embargo_active'] ) );
} );

add_filter( 'wp_insert_post_data', function( array $data, array $postarr ): array {
    if ( ! isset( $postarr['ID'] ) ) return $data;
    $post_id = (int) $postarr['ID'];
    if ( $data['post_status'] !== 'publish' ) return $data;
    if ( ! (bool) get_post_meta( $post_id, '_apollo_embargo_active', true ) ) return $data;
    $embargo_until = (string) get_post_meta( $post_id, '_apollo_embargo_until', true );
    if ( ! $embargo_until || strtotime( $embargo_until ) > time() ) {
        $data['post_status'] = 'draft';
        add_filter( 'redirect_post_location', function( string $loc ): string {
            return add_query_arg( 'apollo_embargo_blocked', '1', $loc );
        } );
    }
    return $data;
}, 10, 2 );

add_action( 'admin_notices', function(): void {
    if ( ! empty( $_GET['apollo_embargo_blocked'] ) && current_user_can( 'edit_posts' ) ) {
        echo '<div class="notice notice-error"><p>⛔ <strong>' . esc_html__( 'Embargo active.', 'apollo-plugin' ) . '</strong> ' . esc_html__( 'This post cannot be published yet. Remove the embargo flag to publish.', 'apollo-plugin' ) . '</p></div>';
    }
} );

add_action( 'wp_head', function(): void {
    echo '<style>
.apollo-attribution-row{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0;}
.apollo-attribution{font-size:12px;padding:2px 8px;border-radius:3px;font-weight:600;}
.apollo-attribution--original{background:#d4edda;color:#155724;}
.apollo-attribution--wire{background:#cce5ff;color:#004085;}
.apollo-attribution--partner{background:#e2e3e5;color:#383d41;}
.apollo-attribution--republished{background:#fff3cd;color:#856404;}
.apollo-attribution--external{background:#f8d7da;color:#721c24;}
</style>';
} );
