<?php
/**
 * Apollo — Feature 14: Media Rights Metadata
 * Feature 15: Source Management
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────────────────────────────
// MEDIA RIGHTS (applied to attachments via attachment edit screen)
// ──────────────────────────────────────────────────────────────────────

add_action( 'init', function(): void {
    $meta = [
        '_media_photographer'  => 'string',
        '_media_license_type'  => 'string',
        '_media_usage_notes'   => 'string',
        '_media_expiry_date'   => 'string',
        '_media_credit_line'   => 'string',
        '_media_do_not_reuse'  => 'boolean',
        '_media_source_url'    => 'string',
    ];
    foreach ( $meta as $key => $type ) {
        register_post_meta( 'attachment', $key, [
            'show_in_rest' => true, 'single' => true, 'type' => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

// Extend attachment edit form
add_filter( 'attachment_fields_to_edit', function( array $fields, \WP_Post $post ): array {
    if ( ! current_user_can( 'edit_post', $post->ID ) ) return $fields;

    $rf = function( string $key ) use ( $post ): string {
        return (string) get_post_meta( $post->ID, $key, true );
    };

    $fields['_media_photographer']  = [ 'label' => __( 'Photographer / Source', 'apollo-plugin' ), 'input' => 'text', 'value' => $rf('_media_photographer') ];
    $fields['_media_credit_line']   = [ 'label' => __( 'Credit Line', 'apollo-plugin' ), 'input' => 'text', 'value' => $rf('_media_credit_line') ];
    $fields['_media_license_type']  = [ 'label' => __( 'License Type', 'apollo-plugin' ), 'input' => 'text', 'value' => $rf('_media_license_type'), 'helps' => 'e.g. AP Wire, AFP, CC-BY 4.0, Rights Reserved' ];
    $fields['_media_expiry_date']   = [ 'label' => __( 'License Expiry Date', 'apollo-plugin' ), 'input' => 'text', 'value' => $rf('_media_expiry_date'), 'helps' => 'YYYY-MM-DD' ];
    $fields['_media_usage_notes']   = [ 'label' => __( 'Usage Notes', 'apollo-plugin' ), 'input' => 'textarea', 'value' => $rf('_media_usage_notes') ];
    $fields['_media_source_url']    = [ 'label' => __( 'External Source URL', 'apollo-plugin' ), 'input' => 'text', 'value' => $rf('_media_source_url') ];
    $fields['_media_do_not_reuse']  = [
        'label' => __( 'Do Not Reuse', 'apollo-plugin' ),
        'input' => 'html',
        'html'  => '<label><input type="checkbox" name="attachments[' . $post->ID . '][_media_do_not_reuse]" value="1" ' . checked( $rf('_media_do_not_reuse'), '1', false ) . '> ' . esc_html__( 'Do not reuse this image', 'apollo-plugin' ) . '</label>',
        'value' => '',
    ];
    return $fields;
}, 10, 2 );

add_filter( 'attachment_fields_to_save', function( array $post_data, array $attachment ): array {
    $post_id = $post_data['ID'];
    foreach ( [ '_media_photographer', '_media_credit_line', '_media_license_type', '_media_expiry_date', '_media_usage_notes', '_media_source_url' ] as $key ) {
        if ( isset( $attachment[ $key ] ) ) {
            update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $attachment[ $key ] ) ) );
        }
    }
    update_post_meta( $post_id, '_media_do_not_reuse', ! empty( $attachment['_media_do_not_reuse'] ) );
    return $post_data;
}, 10, 2 );

// ──────────────────────────────────────────────────────────────────────
// SOURCE MANAGEMENT (private — never renders publicly)
// ──────────────────────────────────────────────────────────────────────

add_action( 'init', function(): void {
    register_post_type( 'apollo_source', [
        'labels'        => [ 'name' => __('Sources','apollo-plugin'), 'singular_name' => __('Source','apollo-plugin'), 'menu_name' => __('Sources','apollo-plugin') ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => 'tools.php',
        'show_in_rest'  => false, // Private — never exposed via REST
        'supports'      => [ 'title', 'editor' ],
        'capability_type'=> 'post',
        'capabilities'  => [ 'read' => 'edit_posts' ],
        'menu_icon'     => 'dashicons-id-alt',
    ] );

    foreach ( [
        '_src_type'           => 'string',  // person | organization | document | official
        '_src_url'            => 'string',
        '_src_contact'        => 'string',  // Internal contact info — never public
        '_src_public'         => 'boolean', // Is this source name publicly attributable?
        '_src_reliability'    => 'string',  // Internal reliability notes — never public
        '_src_related_posts'  => 'string',  // JSON array of post IDs
    ] as $key => $type ) {
        register_post_meta( 'apollo_source', $key, [
            'show_in_rest'  => false, // Never expose via REST
            'single'        => true,
            'type'          => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

// Source meta box on posts — link posts to sources
add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-sources', __( '🔒 Source Tracking (Internal)', 'apollo-plugin' ), 'apollo_sources_meta_box', [ 'post', 'page' ], 'side', 'low' );
} );

function apollo_sources_meta_box( \WP_Post $post ): void {
    $linked = (array) json_decode( (string) get_post_meta( $post->ID, '_apollo_linked_sources', true ), true );
    $all_sources = get_posts( [ 'post_type' => 'apollo_source', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ] );
    if ( empty( $all_sources ) ) {
        echo '<p><a href="' . esc_url( admin_url('post-new.php?post_type=apollo_source') ) . '">' . esc_html__( 'Add your first source', 'apollo-plugin' ) . '</a></p>';
        return;
    }
    wp_nonce_field( 'apollo_sources_' . $post->ID, 'apollo_sources_nonce' );
    foreach ( $all_sources as $src ) {
        echo '<label style="display:block;margin-bottom:3px"><input type="checkbox" name="apollo_linked_sources[]" value="' . $src->ID . '" ' . checked( in_array( $src->ID, $linked, true ), true, false ) . '> ' . esc_html( $src->post_title ) . '</label>';
    }
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_sources_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_sources_nonce'] ) ), 'apollo_sources_' . $post_id ) ) return;
    $raw = array_map( 'absint', (array) ( $_POST['apollo_linked_sources'] ?? [] ) );
    update_post_meta( $post_id, '_apollo_linked_sources', wp_json_encode( array_values( array_filter( $raw ) ) ) );
} );
