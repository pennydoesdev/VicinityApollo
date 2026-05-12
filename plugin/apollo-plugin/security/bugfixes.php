<?php
/**
 * Apollo — Bug Fixes & Hardening
 *
 * @package Apollo
 * @since   3.3
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'flavor_sanitize_raw_html' ) ) :
function flavor_sanitize_raw_html( string $input ): string {
    $input = str_replace( "\0", '', $input );
    return wp_unslash( $input );
}
endif;

remove_action( 'wp_ajax_serve_ei_import', 'serve_ei_ajax_import' );

function serve_ei_ajax_import_hardened(): void {
    check_ajax_referer( 'serve_ei', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $json = wp_unslash( $_POST['json'] ?? '' );
    $data = json_decode( $json, true );
    if ( ! is_array( $data ) || empty( $data['mods'] ) || ! is_array( $data['mods'] ) ) {
        wp_send_json_error( 'Invalid data.' );
    }
    $sanitize = static function ( $val ) use ( &$sanitize ) {
        if ( is_array( $val ) ) return array_map( $sanitize, $val );
        if ( is_bool( $val ) || is_int( $val ) || is_float( $val ) ) return $val;
        if ( is_string( $val ) ) {
            if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $val ) ) return sanitize_hex_color( $val ) ?? '';
            if ( filter_var( $val, FILTER_VALIDATE_URL ) ) return esc_url_raw( $val );
            return sanitize_text_field( $val );
        }
        return null;
    };
    $count = 0;
    foreach ( $data['mods'] as $key => $value ) {
        $clean_key = sanitize_key( $key );
        if ( $clean_key === '' ) continue;
        $clean_val = $sanitize( $value );
        if ( $clean_val === null ) continue;
        set_theme_mod( $clean_key, $clean_val );
        $count++;
    }
    wp_send_json_success( [ 'count' => $count ] );
}
add_action( 'wp_ajax_serve_ei_import', 'serve_ei_ajax_import_hardened' );

function serve_ajax_rate_limit( string $action ): void {
    $ip    = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
    $hash  = substr( md5( $ip ), 0, 12 );
    $tkey  = 'serve_rl_' . $action . '_' . $hash;
    $count = (int) get_transient( $tkey );
    if ( $count >= 30 ) {
        wp_send_json_error( [ 'code' => 'rate_limited', 'message' => 'Too many requests.' ], 429 );
    }
    set_transient( $tkey, $count + 1, 60 );
}

add_action( 'wp_ajax_nopriv_serve_translate',         static fn() => serve_ajax_rate_limit( 'translate' ), 1 );
add_action( 'wp_ajax_nopriv_serve_vast_fetch',        static fn() => serve_ajax_rate_limit( 'vast' ),      1 );
add_action( 'wp_ajax_nopriv_nmb_get_license_pricing', static fn() => serve_ajax_rate_limit( 'nmb' ),       1 );
add_action( 'wp_ajax_nopriv_mlm_request_license',     static fn() => serve_ajax_rate_limit( 'mlm' ),       1 );

add_action( 'admin_init', static function (): void {
    if ( ! is_admin() ) return;
    $page = sanitize_key( $_GET['page'] ?? '' );
    if ( in_array( $page, [ 'serve-ad-settings' ], true ) ) {
        header( 'X-Frame-Options: SAMEORIGIN' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );
    }
} );

add_filter( 'wp_nav_menu_args', static function ( array $args ): array {
    if ( isset( $args['walker'] ) && ! is_object( $args['walker'] ) ) {
        unset( $args['walker'] );
    }
    return $args;
} );

add_action( 'wp_ajax_serve_ei_export', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }
}, 1 );

add_filter( 'the_generator', '__return_empty_string' );

add_filter( 'pre_comment_on_post', static function ( int $post_id ): int {
    if ( isset( $_POST['url'] ) && ! empty( $_POST['url'] ) ) {
        wp_die( esc_html__( 'Comment rejected.', 'serve' ), 403 );
    }
    return $post_id;
} );

function serve_apply_imported_mods( array $mods ): int {
    $skip  = [ '0', 'custom_css_post_id', 'nav_menu_locations' ];
    $count = 0;
    foreach ( $mods as $raw_key => $value ) {
        $key = sanitize_key( (string) $raw_key );
        if ( ! $key || in_array( $key, $skip, true ) ) continue;
        if ( $key === 'header_textcolor' ) {
            set_theme_mod( 'header_textcolor', sanitize_hex_color_no_hash( (string) $value ) );
            $count++; continue;
        }
        if ( $key === 'sidebars_widgets' ) {
            if ( is_array( $value ) && ! empty( $value['data'] ) ) {
                wp_set_sidebars_widgets( $value['data'] );
                $count++;
            }
            continue;
        }
        set_theme_mod( $key, $value );
        $count++;
    }
    return $count;
}
