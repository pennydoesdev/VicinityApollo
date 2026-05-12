<?php
/**
 * Apollo — Feature 28: Tip Submission System
 * Feature 29: Public Records / Document Library
 *
 * Tips are always private. Documents have public/private toggle.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ──────────────────────────────────────────────────────
// TIP SUBMISSION CPT (Feature 28)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    register_post_type( 'apollo_tip', [
        'labels'        => [ 'name' => __('Tips','apollo-plugin'), 'singular_name' => __('Tip','apollo-plugin'), 'menu_name' => __('Tip Inbox','apollo-plugin') ],
        'public'        => false,
        'show_ui'       => true,
        'show_in_menu'  => true,
        'show_in_rest'  => false, // Tips are NEVER public
        'supports'      => [ 'title', 'editor', 'custom-fields' ],
        'capability_type' => 'post',
        'capabilities' => [ 'create_posts' => 'do_not_allow' ],
        'map_meta_cap' => true,
        'menu_icon'    => 'dashicons-email-alt',
        'menu_position'=> 25,
    ] );

    $tip_meta = [
        '_tip_location'      => 'string',
        '_tip_source_contact'=> 'string',  // Private — never public
        '_tip_urgency'       => 'string',
        '_tip_verified'      => 'string',
        '_tip_assigned_to'   => 'integer',
        '_tip_status'        => 'string',
        '_tip_anon'          => 'boolean',
        '_tip_topic'         => 'string',
    ];
    foreach ( $tip_meta as $key => $type ) {
        register_post_meta( 'apollo_tip', $key, [
            'show_in_rest'  => false, // Never expose
            'single'        => true,
            'type'          => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

// Tip submission shortcode
add_shortcode( 'apollo_tip_form', 'apollo_tip_form_shortcode' );
function apollo_tip_form_shortcode( array $atts = [] ): string {
    if ( isset( $_POST['apollo_tip_submit'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['apollo_tip_nonce'] ?? '' ) );
        if ( wp_verify_nonce( $nonce, 'apollo_submit_tip' ) ) {
            $title   = sanitize_text_field( wp_unslash( $_POST['tip_title'] ?? '' ) ) ?: __( 'Tip submitted', 'apollo-plugin' );
            $body    = sanitize_textarea_field( wp_unslash( $_POST['tip_body'] ?? '' ) );
            $contact = sanitize_text_field( wp_unslash( $_POST['tip_contact'] ?? '' ) );
            $loc     = sanitize_text_field( wp_unslash( $_POST['tip_location'] ?? '' ) );
            $topic   = sanitize_key( wp_unslash( $_POST['tip_topic'] ?? '' ) );
            $urgency = sanitize_key( wp_unslash( $_POST['tip_urgency'] ?? 'normal' ) );
            $anon    = ! empty( $_POST['tip_anon'] );

            $post_id = wp_insert_post( [
                'post_type'    => 'apollo_tip',
                'post_title'   => $title,
                'post_content' => $body,
                'post_status'  => 'private',
            ] );
            if ( $post_id && ! is_wp_error( $post_id ) ) {
                if ( ! $anon ) update_post_meta( $post_id, '_tip_source_contact', $contact );
                update_post_meta( $post_id, '_tip_location', $loc );
                update_post_meta( $post_id, '_tip_topic', $topic );
                update_post_meta( $post_id, '_tip_urgency', $urgency );
                update_post_meta( $post_id, '_tip_anon', $anon );
                update_post_meta( $post_id, '_tip_status', 'new' );
                update_post_meta( $post_id, '_tip_verified', 'unverified' );

                // Notify editors
                $editors = get_users( [ 'role__in' => [ 'editor', 'administrator' ], 'number' => 5 ] );
                foreach ( $editors as $editor ) {
                    wp_mail( $editor->user_email, __( 'New Tip Received', 'apollo-plugin' ), sprintf( __( 'A new tip was submitted: %s', 'apollo-plugin' ), admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) );
                }
                return '<div class="apollo-tip-success"><p>' . esc_html__( 'Thank you. Your tip has been securely submitted.', 'apollo-plugin' ) . '</p></div>';
            }
        }
    }

    ob_start();
    ?>
    <form class="apollo-tip-form" method="post">
        <?php wp_nonce_field( 'apollo_submit_tip', 'apollo_tip_nonce' ); ?>
        <p><label><?php esc_html_e( 'Tip Headline', 'apollo-plugin' ); ?><br>
            <input type="text" name="tip_title" style="width:100%" required></label></p>
        <p><label><?php esc_html_e( 'Tell us what you know', 'apollo-plugin' ); ?><br>
            <textarea name="tip_body" rows="5" style="width:100%" required></textarea></label></p>
        <p><label><?php esc_html_e( 'Location (optional)', 'apollo-plugin' ); ?><br>
            <input type="text" name="tip_location" style="width:100%"></label></p>
        <p><label><?php esc_html_e( 'Topic', 'apollo-plugin' ); ?><br>
            <select name="tip_topic" style="width:100%">
                <option value=""><?php esc_html_e( '— Select topic —', 'apollo-plugin' ); ?></option>
                <?php foreach ( [ 'crime' => 'Crime/Public Safety', 'government' => 'Government', 'business' => 'Business', 'environment' => 'Environment', 'education' => 'Education', 'health' => 'Health', 'other' => 'Other' ] as $k => $v ) : ?>
                    <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></option>
                <?php endforeach; ?>
            </select></label></p>
        <p><label><?php esc_html_e( 'Urgency', 'apollo-plugin' ); ?><br>
            <select name="tip_urgency" style="width:100%">
                <option value="normal"><?php esc_html_e( 'Normal', 'apollo-plugin' ); ?></option>
                <option value="urgent"><?php esc_html_e( 'Urgent / Breaking', 'apollo-plugin' ); ?></option>
                <option value="low"><?php esc_html_e( 'Low / Background', 'apollo-plugin' ); ?></option>
            </select></label></p>
        <p style="border:1px solid #dee2e6;padding:12px;background:#f8f9fa;">
            <strong><?php esc_html_e( 'Contact Information (optional)', 'apollo-plugin' ); ?></strong><br>
            <label><?php esc_html_e( 'Your name and email or phone:', 'apollo-plugin' ); ?><br>
                <input type="text" name="tip_contact" style="width:100%" placeholder="<?php esc_attr_e( 'Only seen by editors — never published', 'apollo-plugin' ); ?>"></label><br>
            <label style="margin-top:6px;display:block"><input type="checkbox" name="tip_anon" value="1"> <?php esc_html_e( 'Submit anonymously (omit contact info)', 'apollo-plugin' ); ?></label>
        </p>
        <input type="submit" name="apollo_tip_submit" value="<?php esc_attr_e( 'Submit Tip Securely', 'apollo-plugin' ); ?>" class="button button-primary">
        <p style="font-size:12px;color:#666;margin-top:8px"><?php esc_html_e( 'Tips are received securely and reviewed only by editors. They are never published without verification.', 'apollo-plugin' ); ?></p>
    </form>
    <?php
    return ob_get_clean();
}

// ──────────────────────────────────────────────────────
// DOCUMENT LIBRARY CPT (Feature 29)
// ──────────────────────────────────────────────────────

add_action( 'init', function(): void {
    register_post_type( 'serve_document', [
        'labels'   => [
            'name'          => __( 'Documents', 'apollo-plugin' ),
            'singular_name' => __( 'Document', 'apollo-plugin' ),
            'menu_name'     => __( 'Documents', 'apollo-plugin' ),
            'add_new_item'  => __( 'Add Document', 'apollo-plugin' ),
        ],
        'public'        => true,
        'show_in_rest'  => true,
        'show_ui'       => true,
        'supports'      => [ 'title', 'editor', 'thumbnail', 'custom-fields' ],
        'menu_icon'     => 'dashicons-media-document',
        'menu_position' => 26,
        'rewrite'       => [ 'slug' => 'documents', 'with_front' => false ],
        'has_archive'   => true,
    ] );

    foreach ( [
        '_doc_source'        => 'string',
        '_doc_date_obtained' => 'string',
        '_doc_related_post'  => 'integer',
        '_doc_is_public'     => 'boolean',
        '_doc_citation'      => 'string',
        '_doc_file_url'      => 'string',
        '_doc_wp_media_id'   => 'integer',
        '_doc_doc_type'      => 'string',  // court-filing | foia | police-report | government | contract | dataset | investigation
    ] as $key => $type ) {
        register_post_meta( 'serve_document', $key, [
            'show_in_rest'  => true, 'single' => true, 'type' => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-document', __( '📄 Document Details', 'apollo-plugin' ), 'apollo_document_meta_box', 'serve_document', 'side', 'high' );
} );

function apollo_document_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_doc_' . $post->ID, 'apollo_doc_nonce' );
    $fields = [
        '_doc_source'        => [ 'label' => __('Source','apollo-plugin'), 'type'=>'text' ],
        '_doc_date_obtained' => [ 'label' => __('Date Obtained','apollo-plugin'), 'type'=>'date' ],
        '_doc_citation'      => [ 'label' => __('Citation Label','apollo-plugin'), 'type'=>'text' ],
        '_doc_file_url'      => [ 'label' => __('File URL','apollo-plugin'), 'type'=>'url' ],
        '_doc_doc_type'      => [ 'label' => __('Document Type','apollo-plugin'), 'type'=>'select', 'options' => [ '' => '—', 'court-filing'=>'Court Filing', 'foia'=>'FOIA Record', 'police-report'=>'Police Report', 'government'=>'Government', 'contract'=>'Contract', 'dataset'=>'Dataset', 'investigation'=>'Investigation' ] ],
    ];
    foreach ( $fields as $key => $f ) {
        $val = (string) get_post_meta( $post->ID, $key, true );
        if ( $f['type'] === 'select' ) {
            echo '<p><label>' . esc_html($f['label']) . '<br><select name="' . esc_attr($key) . '" style="width:100%">';
            foreach ( $f['options'] as $k => $v ) echo '<option value="' . esc_attr($k) . '" ' . selected($val,$k,false) . '>' . esc_html($v) . '</option>';
            echo '</select></label></p>';
        } else {
            echo '<p><label>' . esc_html($f['label']) . '<br><input type="' . esc_attr($f['type']) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%"></label></p>';
        }
    }
    $is_public = (bool) get_post_meta( $post->ID, '_doc_is_public', true );
    echo '<p><label><input type="checkbox" name="_doc_is_public" value="1" ' . checked($is_public,true,false) . '> ' . esc_html__( 'Public (show download button)', 'apollo-plugin' ) . '</label></p>';
}

add_action( 'save_post_serve_document', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_doc_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_doc_nonce'] ) ), 'apollo_doc_' . $post_id ) ) return;
    foreach ( [ '_doc_source', '_doc_date_obtained', '_doc_citation', '_doc_file_url', '_doc_doc_type' ] as $k ) {
        update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[$k] ?? '' ) ) );
    }
    update_post_meta( $post_id, '_doc_is_public', ! empty( $_POST['_doc_is_public'] ) );
} );
