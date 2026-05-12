<?php
/**
 * Apollo — Feature 26: Trust Center / Editorial Standards Page
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// Shortcodes for trust center sections
add_shortcode( 'apollo_trust_center',    'apollo_trust_center_shortcode' );
add_shortcode( 'apollo_submit_correction', 'apollo_submit_correction_shortcode' );
add_shortcode( 'apollo_submit_tip',      'apollo_tip_form_shortcode' );  // reuses tip form

function apollo_trust_center_shortcode( array $atts ): string {
    $atts = shortcode_atts( [ 'section' => 'all' ], $atts );
    $cfg  = (array) get_option( 'apollo_trust_config', [] );

    $sections = [
        'editorial_standards' => [ 'heading' => __( 'Editorial Standards', 'apollo-plugin' ), 'key' => 'editorial_standards' ],
        'corrections_policy'  => [ 'heading' => __( 'Corrections Policy', 'apollo-plugin' ), 'key' => 'corrections_policy' ],
        'ethics_policy'       => [ 'heading' => __( 'Ethics Policy', 'apollo-plugin' ), 'key' => 'ethics_policy' ],
        'ai_policy'           => [ 'heading' => __( 'AI Usage Policy', 'apollo-plugin' ), 'key' => 'ai_policy' ],
        'ownership_funding'   => [ 'heading' => __( 'Ownership & Funding Disclosure', 'apollo-plugin' ), 'key' => 'ownership_funding' ],
        'contact_newsroom'    => [ 'heading' => __( 'Contact the Newsroom', 'apollo-plugin' ), 'key' => 'contact_newsroom' ],
    ];

    $out = '<div class="apollo-trust-center">';
    foreach ( $sections as $id => $s ) {
        if ( $atts['section'] !== 'all' && $atts['section'] !== $id ) continue;
        $content = $cfg[ $s['key'] ] ?? '';
        if ( ! $content ) continue;
        $out .= '<section class="apollo-trust-center__section" id="trust-' . esc_attr($id) . '">';
        $out .= '<h2>' . esc_html( $s['heading'] ) . '</h2>';
        $out .= wp_kses_post( wpautop( $content ) );
        $out .= '</section>';
    }
    $out .= '</div>';
    return $out;
}

function apollo_submit_correction_shortcode(): string {
    if ( isset( $_POST['apollo_correction_submit'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['apollo_correction_nonce'] ?? '' ) );
        if ( wp_verify_nonce( $nonce, 'apollo_submit_correction' ) ) {
            $post_url   = esc_url_raw( wp_unslash( $_POST['correction_url'] ?? '' ) );
            $desc       = sanitize_textarea_field( wp_unslash( $_POST['correction_desc'] ?? '' ) );
            $contact    = sanitize_text_field( wp_unslash( $_POST['correction_contact'] ?? '' ) );

            // Notify editors
            $editors = get_users( [ 'role__in' => ['editor','administrator'], 'number' => 3 ] );
            foreach ( $editors as $ed ) {
                wp_mail( $ed->user_email,
                    __( 'Correction Request Submitted', 'apollo-plugin' ),
                    sprintf( "URL: %s\nDescription: %s\nContact: %s", $post_url, $desc, $contact )
                );
            }
            return '<div class="apollo-correction-success"><p>' . esc_html__( 'Thank you. Your correction request has been received and will be reviewed by our editors.', 'apollo-plugin' ) . '</p></div>';
        }
    }

    ob_start();
    ?>
    <form class="apollo-correction-form" method="post">
        <?php wp_nonce_field( 'apollo_submit_correction', 'apollo_correction_nonce' ); ?>
        <p><label><?php esc_html_e( 'Article URL', 'apollo-plugin' ); ?><br>
            <input type="url" name="correction_url" value="<?php echo isset($_SERVER['HTTP_REFERER']) ? esc_attr(sanitize_url(wp_unslash($_SERVER['HTTP_REFERER']))) : ''; ?>" style="width:100%" required></label></p>
        <p><label><?php esc_html_e( 'Describe the error', 'apollo-plugin' ); ?><br>
            <textarea name="correction_desc" rows="4" style="width:100%" required></textarea></label></p>
        <p><label><?php esc_html_e( 'Your contact information (optional)', 'apollo-plugin' ); ?><br>
            <input type="text" name="correction_contact" style="width:100%"></label></p>
        <input type="submit" name="apollo_correction_submit" value="<?php esc_attr_e( 'Submit Correction Request', 'apollo-plugin' ); ?>" class="button button-primary">
    </form>
    <?php
    return ob_get_clean();
}

// Staff masthead shortcode
add_shortcode( 'apollo_masthead', function(): string {
    $users = get_users( [
        'role__in' => ['administrator','editor','author'],
        'orderby'  => 'display_name',
        'order'    => 'ASC',
        'number'   => 200,
    ] );
    if ( empty($users) ) return '';
    $out = '<div class="apollo-masthead"><ul class="apollo-masthead__list">';
    foreach ( $users as $u ) {
        $title = (string) get_user_meta( $u->ID, '_apollo_author_title', true );
        $out  .= '<li class="apollo-masthead__person">';
        $out  .= '<strong class="apollo-masthead__name">' . esc_html( $u->display_name ) . '</strong>';
        if ( $title ) $out .= ' <span class="apollo-masthead__title">' . esc_html( $title ) . '</span>';
        $out  .= '</li>';
    }
    $out .= '</ul></div>';
    return $out;
} );

// Admin settings
add_action( 'admin_menu', function(): void {
    add_options_page( __('Trust Center','apollo-plugin'), __('Trust Center','apollo-plugin'), 'manage_options', 'apollo-trust-center', 'apollo_trust_center_admin_page' );
} );

function apollo_trust_center_admin_page(): void {
    if ( ! current_user_can('manage_options') ) return;
    if ( isset($_POST['apollo_trust_save']) && check_admin_referer('apollo_trust_save') ) {
        $cfg = [];
        foreach ( [ 'editorial_standards', 'corrections_policy', 'ethics_policy', 'ai_policy', 'ownership_funding', 'contact_newsroom' ] as $k ) {
            $cfg[$k] = wp_kses_post( wp_unslash( $_POST[$k] ?? '' ) );
        }
        update_option( 'apollo_trust_config', $cfg );
        echo '<div class="notice notice-success"><p>' . esc_html__('Saved.','apollo-plugin') . '</p></div>';
    }
    $cfg = (array) get_option( 'apollo_trust_config', [] );
    $sections = [
        'editorial_standards' => __('Editorial Standards','apollo-plugin'),
        'corrections_policy'  => __('Corrections Policy','apollo-plugin'),
        'ethics_policy'       => __('Ethics Policy','apollo-plugin'),
        'ai_policy'           => __('AI Usage Policy','apollo-plugin'),
        'ownership_funding'   => __('Ownership & Funding Disclosure','apollo-plugin'),
        'contact_newsroom'    => __('Contact the Newsroom','apollo-plugin'),
    ];
    echo '<div class="wrap"><h1>' . esc_html__('Trust Center Settings','apollo-plugin') . '</h1>';
    echo '<p>' . esc_html__('Use [apollo_trust_center] shortcode on any page. [apollo_submit_correction] adds the corrections form.','apollo-plugin') . '</p>';
    echo '<form method="post">';
    wp_nonce_field('apollo_trust_save');
    foreach ( $sections as $k => $label ) {
        $val = $cfg[$k] ?? '';
        echo '<h3>' . esc_html($label) . '</h3>';
        echo '<textarea name="' . esc_attr($k) . '" rows="6" style="width:100%">' . esc_textarea($val) . '</textarea>';
    }
    echo '<input type="submit" name="apollo_trust_save" value="' . esc_attr__('Save','apollo-plugin') . '" class="button-primary"></form></div>';
}
