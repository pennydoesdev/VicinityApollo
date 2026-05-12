<?php
/**
 * Apollo — Features 30 & 31: Fact-Checking + Legal/Sensitive Review
 *
 * Fact-check workflow and legal sensitivity metadata.
 * Legal review metadata is NEVER rendered publicly.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    $fact_meta = [
        '_apollo_fc_claims'          => 'string',
        '_apollo_fc_sources'         => 'string',
        '_apollo_fc_docs'            => 'string',
        '_apollo_fc_status'          => 'string',
        '_apollo_fc_checker'         => 'string',
        '_apollo_fc_date'            => 'string',
        '_apollo_fc_risk_level'      => 'string',
        '_apollo_fc_legal_flag'      => 'boolean',
    ];
    $legal_meta = [
        '_apollo_lr_defamation'      => 'boolean',
        '_apollo_lr_privacy'         => 'boolean',
        '_apollo_lr_minor'           => 'boolean',
        '_apollo_lr_crime_allegation'=> 'boolean',
        '_apollo_lr_anon_source'     => 'boolean',
        '_apollo_lr_graphic'         => 'boolean',
        '_apollo_lr_review_required' => 'boolean',
        '_apollo_lr_review_complete' => 'boolean',
        '_apollo_lr_editor_approved' => 'boolean',
    ];
    foreach ( array_merge( $fact_meta, $legal_meta ) as $key => $type ) {
        register_post_meta( 'post', $key, [
            'show_in_rest'  => false,
            'single'        => true,
            'type'          => $type,
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-factcheck', __( '✅ Fact-Check & Legal', 'apollo-plugin' ), 'apollo_factcheck_meta_box', 'post', 'normal', 'low' );
} );

function apollo_factcheck_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_fc_' . $post->ID, 'apollo_fc_nonce' );

    $fc_status  = (string) get_post_meta( $post->ID, '_apollo_fc_status', true );
    $fc_checker = (string) get_post_meta( $post->ID, '_apollo_fc_checker', true );
    $fc_date    = (string) get_post_meta( $post->ID, '_apollo_fc_date', true );
    $fc_risk    = (string) get_post_meta( $post->ID, '_apollo_fc_risk_level', true );
    $fc_legal   = (bool)   get_post_meta( $post->ID, '_apollo_fc_legal_flag', true );
    $fc_claims  = (string) get_post_meta( $post->ID, '_apollo_fc_claims', true );
    $fc_sources = (string) get_post_meta( $post->ID, '_apollo_fc_sources', true );

    echo '<h4 style="margin-top:0">' . esc_html__( 'Fact-Check', 'apollo-plugin' ) . '</h4>';
    echo '<table class="form-table" style="margin:0"><tbody>';
    echo '<tr><th>' . esc_html__( 'Status', 'apollo-plugin' ) . '</th><td><select name="_apollo_fc_status">';
    foreach ( [ '' => '— Select —', 'pending' => 'Pending', 'in-progress' => 'In Progress', 'complete' => 'Complete', 'not-required' => 'Not Required' ] as $k => $v ) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($fc_status,$k,false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>' . esc_html__( 'Fact-Checker', 'apollo-plugin' ) . '</th><td><input type="text" name="_apollo_fc_checker" value="' . esc_attr($fc_checker) . '" style="width:100%"></td></tr>';
    echo '<tr><th>' . esc_html__( 'Date Complete', 'apollo-plugin' ) . '</th><td><input type="date" name="_apollo_fc_date" value="' . esc_attr($fc_date) . '"></td></tr>';
    echo '<tr><th>' . esc_html__( 'Risk Level', 'apollo-plugin' ) . '</th><td><select name="_apollo_fc_risk_level">';
    foreach ( [ '' => '—', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High' ] as $k => $v ) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($fc_risk,$k,false) . '>' . esc_html($v) . '</option>';
    }
    echo '</select></td></tr>';
    echo '<tr><th>' . esc_html__( 'Claims Checked', 'apollo-plugin' ) . '</th><td><textarea name="_apollo_fc_claims" rows="2" style="width:100%">' . esc_textarea($fc_claims) . '</textarea></td></tr>';
    echo '<tr><th>' . esc_html__( 'Source Links', 'apollo-plugin' ) . '</th><td><textarea name="_apollo_fc_sources" rows="2" style="width:100%">' . esc_textarea($fc_sources) . '</textarea></td></tr>';
    echo '<tr><th></th><td><label><input type="checkbox" name="_apollo_fc_legal_flag" value="1" ' . checked($fc_legal,true,false) . '> ' . esc_html__( 'Legal sensitivity flag', 'apollo-plugin' ) . '</label></td></tr>';
    echo '</tbody></table>';

    echo '<h4>' . esc_html__( '🔒 Legal Review (Internal Only — Never Public)', 'apollo-plugin' ) . '</h4>';
    echo '<table class="form-table" style="margin:0"><tbody><tr><td colspan="2">';
    $legal_fields = [
        '_apollo_lr_defamation'       => __( 'Defamation risk', 'apollo-plugin' ),
        '_apollo_lr_privacy'          => __( 'Privacy risk', 'apollo-plugin' ),
        '_apollo_lr_minor'            => __( 'Minor involved', 'apollo-plugin' ),
        '_apollo_lr_crime_allegation' => __( 'Crime allegation', 'apollo-plugin' ),
        '_apollo_lr_anon_source'      => __( 'Anonymous source', 'apollo-plugin' ),
        '_apollo_lr_graphic'          => __( 'Graphic content', 'apollo-plugin' ),
        '_apollo_lr_review_required'  => __( 'Legal review required', 'apollo-plugin' ),
        '_apollo_lr_review_complete'  => __( 'Legal review complete', 'apollo-plugin' ),
        '_apollo_lr_editor_approved'  => __( 'Editor approval obtained', 'apollo-plugin' ),
    ];
    foreach ( $legal_fields as $key => $label ) {
        $val = (bool) get_post_meta( $post->ID, $key, true );
        echo '<label style="display:inline-block;margin-right:16px;margin-bottom:6px"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($val,true,false) . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr></tbody></table>';
}

add_action( 'save_post_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_fc_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_fc_nonce'] ) ), 'apollo_fc_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    foreach ( [ '_apollo_fc_status', '_apollo_fc_checker', '_apollo_fc_date', '_apollo_fc_risk_level', '_apollo_fc_claims', '_apollo_fc_sources' ] as $k ) {
        update_post_meta( $post_id, $k, sanitize_text_field( wp_unslash( $_POST[$k] ?? '' ) ) );
    }
    update_post_meta( $post_id, '_apollo_fc_legal_flag', ! empty( $_POST['_apollo_fc_legal_flag'] ) );

    foreach ( [ '_apollo_lr_defamation', '_apollo_lr_privacy', '_apollo_lr_minor', '_apollo_lr_crime_allegation', '_apollo_lr_anon_source', '_apollo_lr_graphic', '_apollo_lr_review_required', '_apollo_lr_review_complete', '_apollo_lr_editor_approved' ] as $k ) {
        update_post_meta( $post_id, $k, ! empty( $_POST[$k] ) );
    }
} );
