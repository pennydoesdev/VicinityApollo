<?php
/**
 * Apollo — Feature 19: Local Public Safety Module
 * Feature 20: Weather Coverage Module
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    foreach ( [
        '_apollo_ps_incident_type'  => 'string',
        '_apollo_ps_location'       => 'string',
        '_apollo_ps_agency'         => 'string',
        '_apollo_ps_time_reported'  => 'string',
        '_apollo_ps_status'         => 'string',
        '_apollo_ps_source'         => 'string',
        '_apollo_ps_scanner_ref'    => 'string',
        '_apollo_ps_verified'       => 'string',
        '_apollo_ps_disclaimer'     => 'string',
    ] as $key => $type ) {
        register_post_meta( 'post', $key, [
            'show_in_rest'  => true, 'single' => true, 'type' => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

function apollo_public_safety_badge_html( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $verified = (string) get_post_meta( $post_id, '_apollo_ps_verified', true );
    if ( ! $verified ) return '';

    $labels = [
        'unverified'  => [ 'text' => __( 'Unconfirmed Report', 'apollo-plugin' ), 'color' => '#856404', 'bg' => '#fff3cd' ],
        'confirmed'   => [ 'text' => __( 'Confirmed by Agency', 'apollo-plugin' ), 'color' => '#155724', 'bg' => '#d4edda' ],
        'developing'  => [ 'text' => __( 'Developing', 'apollo-plugin' ),          'color' => '#004085', 'bg' => '#cce5ff' ],
        'resolved'    => [ 'text' => __( 'Resolved', 'apollo-plugin' ),            'color' => '#383d41', 'bg' => '#e2e3e5' ],
    ];

    $info       = $labels[ $verified ] ?? $labels['unverified'];
    $location   = (string) get_post_meta( $post_id, '_apollo_ps_location', true );
    $agency     = (string) get_post_meta( $post_id, '_apollo_ps_agency', true );
    $type       = (string) get_post_meta( $post_id, '_apollo_ps_incident_type', true );
    $disclaimer = (string) get_post_meta( $post_id, '_apollo_ps_disclaimer', true );

    $out  = '<div class="apollo-public-safety" style="border:1px solid ' . esc_attr($info['color']) . ';background:' . esc_attr($info['bg']) . ';padding:12px 16px;margin:16px 0;border-radius:4px;">';
    $out .= '<span class="apollo-ps-badge" style="font-weight:700;color:' . esc_attr($info['color']) . '">' . esc_html( $info['text'] ) . '</span>';
    if ( $type )     $out .= ' · <span class="apollo-ps-type">' . esc_html( $type ) . '</span>';
    if ( $location ) $out .= ' · 📍 <span class="apollo-ps-location">' . esc_html( $location ) . '</span>';
    if ( $agency )   $out .= ' · <span class="apollo-ps-agency">' . esc_html( $agency ) . '</span>';
    if ( $verified === 'unverified' ) {
        $out .= '<br><small style="color:' . esc_attr($info['color']) . '">' . esc_html( $disclaimer ?: __( 'This report has not been independently confirmed. Do not present scanner information as confirmed fact.', 'apollo-plugin' ) ) . '</small>';
    }
    $out .= '</div>';
    return $out;
}

add_filter( 'apollo_render_public-safety-badge', function( $html, array $args ): string {
    return apollo_public_safety_badge_html( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-public-safety', __( '🚨 Public Safety', 'apollo-plugin' ), 'apollo_public_safety_meta_box', 'post', 'side', 'default' );
} );

function apollo_public_safety_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_ps_' . $post->ID, 'apollo_ps_nonce' );
    $fields = [
        '_apollo_ps_incident_type' => [ 'label' => __( 'Incident Type', 'apollo-plugin' ), 'type' => 'text', 'placeholder' => 'Fire, Shooting, Crash…' ],
        '_apollo_ps_location'      => [ 'label' => __( 'Location', 'apollo-plugin' ), 'type' => 'text' ],
        '_apollo_ps_agency'        => [ 'label' => __( 'Agency', 'apollo-plugin' ), 'type' => 'text', 'placeholder' => 'Police, Fire Dept…' ],
        '_apollo_ps_time_reported' => [ 'label' => __( 'Time Reported', 'apollo-plugin' ), 'type' => 'text' ],
        '_apollo_ps_source'        => [ 'label' => __( 'Source', 'apollo-plugin' ), 'type' => 'text' ],
        '_apollo_ps_scanner_ref'   => [ 'label' => __( '🔒 Scanner/Audio Ref (internal)', 'apollo-plugin' ), 'type' => 'text' ],
    ];
    foreach ( $fields as $key => $f ) {
        $val = (string) get_post_meta( $post->ID, $key, true );
        $ph  = isset($f['placeholder']) ? ' placeholder="' . esc_attr($f['placeholder']) . '"' : '';
        echo '<p><label>' . esc_html($f['label']) . '<br><input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%"' . $ph . '></label></p>';
    }
    $verified = (string) get_post_meta( $post->ID, '_apollo_ps_verified', true );
    echo '<p><label>' . esc_html__( 'Verification Status', 'apollo-plugin' ) . '<br><select name="_apollo_ps_verified" style="width:100%">';
    foreach ( [ '' => '— Not a public safety story —', 'unverified' => '⚠️ Unconfirmed Report', 'confirmed' => '✅ Confirmed by Agency', 'developing' => '🔵 Developing', 'resolved' => '✔ Resolved' ] as $k => $v ) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($verified,$k,false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select></label></p>';
}

add_action( 'save_post_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_ps_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_ps_nonce'] ) ), 'apollo_ps_' . $post_id ) ) return;
    foreach ( [ '_apollo_ps_incident_type', '_apollo_ps_location', '_apollo_ps_agency', '_apollo_ps_time_reported', '_apollo_ps_source', '_apollo_ps_scanner_ref', '_apollo_ps_verified' ] as $k ) {
        update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[$k] ?? '' ) ) );
    }
} );

// ──────────────────────────────────────────────────────
// WEATHER (Feature 20)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    foreach ( [
        '_apollo_wx_alert_type'  => 'string',
        '_apollo_wx_source'      => 'string',
        '_apollo_wx_confidence'  => 'string',
        '_apollo_wx_map_embed'   => 'string',
        '_apollo_wx_expires'     => 'string',
    ] as $key => $type ) {
        register_post_meta( 'post', $key, [
            'show_in_rest'  => true, 'single' => true, 'type' => $type,
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ] );
    }
} );

function apollo_weather_alert_html( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $type = (string) get_post_meta( $post_id, '_apollo_wx_alert_type', true );
    if ( ! $type ) return '';

    $source     = (string) get_post_meta( $post_id, '_apollo_wx_source', true );
    $confidence = (string) get_post_meta( $post_id, '_apollo_wx_confidence', true );
    $map_embed  = (string) get_post_meta( $post_id, '_apollo_wx_map_embed', true );

    $out  = '<div class="apollo-weather-alert" role="alert">';
    $out .= '<span class="apollo-weather-alert__label">🌩 ' . esc_html( $type ) . '</span>';
    if ( $source )     $out .= ' · <span class="apollo-weather-alert__source">' . esc_html__('Source:','apollo-plugin') . ' ' . esc_html($source) . '</span>';
    if ( $confidence ) $out .= ' · <span class="apollo-weather-alert__confidence">' . esc_html__('Confidence:','apollo-plugin') . ' ' . esc_html($confidence) . '</span>';
    $out .= '</div>';
    if ( $map_embed ) $out .= '<div class="apollo-weather-map">' . wp_kses_post( $map_embed ) . '</div>';
    return $out;
}

add_filter( 'apollo_render_weather-alert', function( $html, array $args ): string {
    return apollo_weather_alert_html( absint($args['post_id'] ?? get_the_ID()) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-weather', __( '🌩 Weather', 'apollo-plugin' ), function( \WP_Post $post ): void {
        wp_nonce_field( 'apollo_wx_' . $post->ID, 'apollo_wx_nonce' );
        foreach ( [
            '_apollo_wx_alert_type' => __( 'Alert Type', 'apollo-plugin' ),
            '_apollo_wx_source'     => __( 'Weather Source', 'apollo-plugin' ),
            '_apollo_wx_confidence' => __( 'Forecast Confidence', 'apollo-plugin' ),
            '_apollo_wx_expires'    => __( 'Alert Expires', 'apollo-plugin' ),
        ] as $k => $label ) {
            $val = (string) get_post_meta( $post->ID, $k, true );
            echo '<p><label>' . esc_html($label) . '<br><input type="text" name="' . esc_attr($k) . '" value="' . esc_attr($val) . '" style="width:100%"></label></p>';
        }
        $embed = (string) get_post_meta( $post->ID, '_apollo_wx_map_embed', true );
        echo '<p><label>' . esc_html__('Map Embed Code','apollo-plugin') . '<br><textarea name="_apollo_wx_map_embed" rows="2" style="width:100%">' . esc_textarea($embed) . '</textarea></label></p>';
    }, 'post', 'side', 'low' );
} );

add_action( 'save_post_post', function( int $post_id ): void {
    if ( ! isset($_POST['apollo_wx_nonce']) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_wx_nonce'] ) ), 'apollo_wx_' . $post_id ) ) return;
    foreach ( [ '_apollo_wx_alert_type', '_apollo_wx_source', '_apollo_wx_confidence', '_apollo_wx_expires' ] as $k ) {
        update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[$k] ?? '' ) ) );
    }
    update_post_meta( $post_id, '_apollo_wx_map_embed', wp_kses_post( wp_unslash( $_POST['_apollo_wx_map_embed'] ?? '' ) ) );
} );
