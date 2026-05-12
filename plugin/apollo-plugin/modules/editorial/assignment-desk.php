<?php
/**
 * Apollo — Feature 38: Internal Notes & Assignment Desk
 * Feature 8: Newsletter System Hooks
 * Feature 22: Push Notification Hooks
 * Feature 23: Membership / Subscriber Layer
 * Feature 36: Donations / Support Module
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────────────
// ASSIGNMENT DESK (Feature 38)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page' ] as $pt ) {
        foreach ( [
            '_apollo_pitch_notes'       => 'string',
            '_apollo_assigned_reporter' => 'integer',
            '_apollo_assigned_editor'   => 'integer',
            '_apollo_due_date'          => 'string',
            '_apollo_pub_target_date'   => 'string',
            '_apollo_priority'          => 'string',
            '_apollo_beat'              => 'string',
            '_apollo_assignment_status' => 'string',
            '_apollo_desk_notes'        => 'string',
            '_apollo_related_tips'      => 'string',
        ] as $key => $type ) {
            register_post_meta( $pt, $key, [
                'show_in_rest'  => false,
                'single'        => true,
                'type'          => $type,
                'auth_callback' => fn() => current_user_can( 'edit_posts' ),
            ] );
        }
    }
} );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-assignment', __( '🗂 Assignment Desk', 'apollo-plugin' ), 'apollo_assignment_meta_box', [ 'post', 'page' ], 'side', 'low' );
} );

function apollo_assignment_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_assign_' . $post->ID, 'apollo_assign_nonce' );
    $users = get_users( [ 'role__in' => ['administrator','editor','author','contributor'], 'fields' => ['ID','display_name'], 'orderby' => 'display_name', 'number' => 200 ] );

    $rep    = (int)    get_post_meta( $post->ID, '_apollo_assigned_reporter', true );
    $ed     = (int)    get_post_meta( $post->ID, '_apollo_assigned_editor', true );
    $due    = (string) get_post_meta( $post->ID, '_apollo_due_date', true );
    $pub    = (string) get_post_meta( $post->ID, '_apollo_pub_target_date', true );
    $prio   = (string) get_post_meta( $post->ID, '_apollo_priority', true );
    $beat   = (string) get_post_meta( $post->ID, '_apollo_beat', true );
    $notes  = (string) get_post_meta( $post->ID, '_apollo_desk_notes', true );

    $user_options = '<option value="">—</option>';
    foreach ( $users as $u ) {
        $user_options .= '<option value="' . $u->ID . '">' . esc_html($u->display_name) . '</option>';
    }
    $rep_opts = str_replace( 'value="' . $rep . '"', 'value="' . $rep . '" selected', $user_options );
    $ed_opts  = str_replace( 'value="' . $ed  . '"', 'value="' . $ed  . '" selected', $user_options );

    echo '<p><label>' . esc_html__('Reporter','apollo-plugin') . '<br><select name="_apollo_assigned_reporter" style="width:100%">' . $rep_opts . '</select></label></p>';
    echo '<p><label>' . esc_html__('Editor','apollo-plugin') . '<br><select name="_apollo_assigned_editor" style="width:100%">' . $ed_opts . '</select></label></p>';
    echo '<p><label>' . esc_html__('Due Date','apollo-plugin') . '<br><input type="datetime-local" name="_apollo_due_date" value="' . esc_attr($due) . '" style="width:100%"></label></p>';
    echo '<p><label>' . esc_html__('Publish Target','apollo-plugin') . '<br><input type="datetime-local" name="_apollo_pub_target_date" value="' . esc_attr($pub) . '" style="width:100%"></label></p>';
    echo '<p><label>' . esc_html__('Priority','apollo-plugin') . '<br><select name="_apollo_priority" style="width:100%">';
    foreach ( [''=>'—','low'=>'Low','normal'=>'Normal','high'=>'High','breaking'=>'🔴 Breaking'] as $k=>$v ) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($prio,$k,false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label>' . esc_html__('Beat','apollo-plugin') . '<br><input type="text" name="_apollo_beat" value="' . esc_attr($beat) . '" style="width:100%"></label></p>';
    echo '<p><label>' . esc_html__('Desk Notes (internal)','apollo-plugin') . '<br><textarea name="_apollo_desk_notes" rows="3" style="width:100%">' . esc_textarea($notes) . '</textarea></label></p>';
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_assign_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_assign_nonce'] ) ), 'apollo_assign_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    foreach ( [
        '_apollo_assigned_reporter' => 'absint',
        '_apollo_assigned_editor'   => 'absint',
        '_apollo_due_date'          => 'sanitize_text_field',
        '_apollo_pub_target_date'   => 'sanitize_text_field',
        '_apollo_priority'          => 'sanitize_key',
        '_apollo_beat'              => 'sanitize_text_field',
        '_apollo_desk_notes'        => 'sanitize_textarea_field',
    ] as $key => $cb ) {
        $val = wp_unslash( $_POST[$key] ?? '' );
        update_post_meta( $post_id, $key, $cb( $val ) );
    }
} );

// ──────────────────────────────────────────────────────
// NEWSLETTER HOOKS (Feature 8)
// ──────────────────────────────────────────────────────

function apollo_newsletter_form_render( array $args = [] ): string {
    $cfg = (array) get_option( 'apollo_newsletter_config', [] );
    $embed = $cfg['form_embed'] ?? '';
    $heading = $args['heading'] ?? ( $cfg['default_heading'] ?? __( 'Get our newsletter', 'apollo-plugin' ) );
    $desc    = $args['desc']    ?? ( $cfg['default_desc'] ?? '' );
    $topic   = $args['topic']   ?? '';

    if ( $topic && ! empty( $cfg['topics'][ $topic ]['embed'] ) ) {
        $embed = $cfg['topics'][ $topic ]['embed'];
    }

    if ( ! $embed ) {
        return '<div class="apollo-newsletter-signup"><p>' . esc_html( $heading ) . '</p><p><a href="' . esc_url( home_url('/newsletter/') ) . '">' . esc_html__('Sign up','apollo-plugin') . '</a></p></div>';
    }
    return '<div class="apollo-newsletter-signup"><h3>' . esc_html($heading) . '</h3>' . ( $desc ? '<p>' . esc_html($desc) . '</p>' : '' ) . wp_kses_post( $embed ) . '</div>';
}

add_filter( 'apollo_render_newsletter-form', function( $html, array $args ): string {
    return apollo_newsletter_form_render( $args );
}, 10, 2 );

add_action( 'admin_menu', function(): void {
    add_options_page( __('Newsletter Settings','apollo-plugin'), __('Newsletter','apollo-plugin'), 'manage_options', 'apollo-newsletter', function(): void {
        if ( ! current_user_can('manage_options') ) return;
        if ( isset($_POST['apollo_nl_save']) && check_admin_referer('apollo_nl_save') ) {
            update_option( 'apollo_newsletter_config', [
                'provider'        => sanitize_text_field( wp_unslash( $_POST['provider'] ?? '' ) ),
                'form_embed'      => wp_kses_post( wp_unslash( $_POST['form_embed'] ?? '' ) ),
                'list_id'         => sanitize_text_field( wp_unslash( $_POST['list_id'] ?? '' ) ),
                'default_heading' => sanitize_text_field( wp_unslash( $_POST['default_heading'] ?? '' ) ),
                'default_desc'    => sanitize_text_field( wp_unslash( $_POST['default_desc'] ?? '' ) ),
            ] );
        }
        $cfg = (array) get_option( 'apollo_newsletter_config', [] );
        echo '<div class="wrap"><h1>' . esc_html__('Newsletter Settings','apollo-plugin') . '</h1><form method="post">';
        wp_nonce_field('apollo_nl_save');
        echo '<table class="form-table">'
            . '<tr><th>' . esc_html__('Provider','apollo-plugin') . '</th><td><input type="text" name="provider" value="' . esc_attr($cfg['provider']??'') . '" class="regular-text"></td></tr>'
            . '<tr><th>' . esc_html__('List ID','apollo-plugin') . '</th><td><input type="text" name="list_id" value="' . esc_attr($cfg['list_id']??'') . '" class="regular-text"></td></tr>'
            . '<tr><th>' . esc_html__('Form Embed Code','apollo-plugin') . '</th><td><textarea name="form_embed" rows="4" style="width:100%">' . esc_textarea($cfg['form_embed']??'') . '</textarea></td></tr>'
            . '<tr><th>' . esc_html__('Default Heading','apollo-plugin') . '</th><td><input type="text" name="default_heading" value="' . esc_attr($cfg['default_heading']??'') . '" class="large-text"></td></tr>'
            . '</table><input type="submit" name="apollo_nl_save" value="' . esc_attr__('Save','apollo-plugin') . '" class="button-primary"></form></div>';
    } );
} );

// ──────────────────────────────────────────────────────
// MEMBERSHIP LAYER (Feature 23)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page', 'serve_video', 'serve_episode' ] as $pt ) {
        register_post_meta( $pt, '_apollo_access', [
            'show_in_rest'  => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can('edit_posts'),
        ] );
    }
} );

// ──────────────────────────────────────────────────────
// DONATIONS (Feature 36)
// ──────────────────────────────────────────────────────

function apollo_donate_button_html( string $context = 'article' ): string {
    $cfg = (array) get_option( 'apollo_donations_config', [] );
    $url  = esc_url( $cfg['donate_url'] ?? '' );
    $text = esc_html( $cfg['button_text'] ?? __( 'Support Our Newsroom', 'apollo-plugin' ) );
    $desc = esc_html( $cfg['description'] ?? '' );
    if ( ! $url ) return '';
    return '<div class="apollo-donate-cta apollo-donate-cta--' . esc_attr($context) . '">'
        . ( $desc ? '<p class="apollo-donate-cta__desc">' . $desc . '</p>' : '' )
        . '<a href="' . $url . '" class="apollo-donate-cta__btn" target="_blank" rel="noopener">' . $text . '</a>'
        . '</div>';
}

add_shortcode( 'apollo_donate', fn( array $atts ) => apollo_donate_button_html( $atts['context'] ?? 'article' ) );

add_action( 'admin_init', function(): void {
    register_setting( 'general', 'apollo_donations_config', [ 'sanitize_callback' => fn($v) => is_array($v) ? array_map('sanitize_text_field', $v) : [] ] );
} );

// ──────────────────────────────────────────────────────
// PUSH NOTIFICATIONS (Feature 22)
// ──────────────────────────────────────────────────────

add_action( 'publish_post', function( int $post_id, \WP_Post $post ): void {
    do_action( 'apollo_push_breaking_news', $post_id, $post );
}, 10, 2 );

add_action( 'publish_serve_video', function( int $post_id, \WP_Post $post ): void {
    do_action( 'apollo_push_video_release', $post_id, $post );
}, 10, 2 );

add_action( 'publish_serve_episode', function( int $post_id, \WP_Post $post ): void {
    do_action( 'apollo_push_podcast_episode', $post_id, $post );
}, 10, 2 );

// ── apollo_render bridge ─────────────────────────────────────────────────────
add_filter( 'apollo_render_donate-button', function( $html, array $args ): string {
    $context = (string) ( $args['context'] ?? 'article' );
    return apollo_donate_button_html( $context );
}, 10, 2 );
