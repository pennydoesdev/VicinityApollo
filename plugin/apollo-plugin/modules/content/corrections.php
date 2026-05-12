<?php
/**
 * Apollo — Features 16 & 17: Corrections, Updates & Version Notes
 *
 * Public correction/update display + internal editor version notes.
 * Internal notes are NEVER rendered publicly.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page' ] as $pt ) {
        foreach ( [
            '_apollo_correction_note'   => 'string',
            '_apollo_correction_ts'     => 'string',
            '_apollo_updated_note'      => 'string',
            '_apollo_original_pub_date' => 'string',
            '_apollo_last_updated_date' => 'string',
            '_apollo_show_correction'   => 'boolean',
            '_apollo_editor_notes'      => 'string',
            '_apollo_change_summary'    => 'string',
            '_apollo_public_update_note'=> 'string',
            '_apollo_is_material_change'=> 'boolean',
        ] as $key => $type ) {
            register_post_meta( $pt, $key, [
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => $type,
                'auth_callback' => fn() => current_user_can( 'edit_posts' ),
            ] );
        }
    }
} );

function apollo_corrections_html( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    if ( ! $post_id ) return '';

    $show       = (bool)   get_post_meta( $post_id, '_apollo_show_correction', true );
    $correction = (string) get_post_meta( $post_id, '_apollo_correction_note', true );
    $corr_ts    = (string) get_post_meta( $post_id, '_apollo_correction_ts', true );
    $updated    = (string) get_post_meta( $post_id, '_apollo_public_update_note', true );
    $last_up    = (string) get_post_meta( $post_id, '_apollo_last_updated_date', true );

    $out = '';

    if ( $show && $correction ) {
        $ts_label = $corr_ts ? ' <small>(' . esc_html( $corr_ts ) . ')</small>' : '';
        $out .= '<div class="apollo-correction-box" role="complementary" aria-label="' . esc_attr__( 'Correction', 'apollo-plugin' ) . '">'
            . '<strong>' . esc_html__( 'Correction:', 'apollo-plugin' ) . '</strong>' . $ts_label . ' '
            . wp_kses_post( $correction )
            . '</div>';
    }

    if ( $updated ) {
        $up_ts = $last_up ? ' <time datetime="' . esc_attr( $last_up ) . '">' . esc_html( $last_up ) . '</time>' : '';
        $out .= '<div class="apollo-update-note">'
            . '<strong>' . esc_html__( 'Editor\'s Note:', 'apollo-plugin' ) . '</strong>' . $up_ts . ' '
            . wp_kses_post( $updated )
            . '</div>';
    }

    return $out;
}

add_filter( 'apollo_render_corrections', function( $html, array $args ): string {
    return apollo_corrections_html( absint( $args['post_id'] ?? get_the_ID() ) );
}, 10, 2 );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-corrections', __( '✏️ Corrections & Updates', 'apollo-plugin' ), 'apollo_corrections_meta_box', [ 'post', 'page' ], 'normal', 'low' );
} );

function apollo_corrections_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_corrections_' . $post->ID, 'apollo_corrections_nonce' );
    $fields = [
        '_apollo_correction_note'    => [ 'label' => __( 'Correction Note (public)', 'apollo-plugin' ), 'type' => 'textarea' ],
        '_apollo_correction_ts'      => [ 'label' => __( 'Correction Timestamp', 'apollo-plugin' ), 'type' => 'text' ],
        '_apollo_public_update_note' => [ 'label' => __( 'Editor\'s Update Note (public)', 'apollo-plugin' ), 'type' => 'textarea' ],
        '_apollo_last_updated_date'  => [ 'label' => __( 'Last Materially Updated Date', 'apollo-plugin' ), 'type' => 'text' ],
        '_apollo_editor_notes'       => [ 'label' => __( '🔒 Internal Editor Notes (never public)', 'apollo-plugin' ), 'type' => 'textarea' ],
        '_apollo_change_summary'     => [ 'label' => __( '🔒 Change Summary (internal)', 'apollo-plugin' ), 'type' => 'text' ],
    ];
    echo '<table class="form-table" style="margin:0"><tbody>';
    foreach ( $fields as $key => $f ) {
        $val = (string) get_post_meta( $post->ID, $key, true );
        $input = $f['type'] === 'textarea'
            ? '<textarea name="' . esc_attr($key) . '" rows="2" style="width:100%">' . esc_textarea($val) . '</textarea>'
            : '<input type="text" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%">';
        echo '<tr><th>' . esc_html($f['label']) . '</th><td>' . $input . '</td></tr>';
    }
    $show       = get_post_meta( $post->ID, '_apollo_show_correction', true );
    $mat_change = get_post_meta( $post->ID, '_apollo_is_material_change', true );
    echo '<tr><th></th><td>';
    echo '<label><input type="checkbox" name="_apollo_show_correction" value="1" ' . checked($show,true,false) . '> ' . esc_html__( 'Show correction box', 'apollo-plugin' ) . '</label> &nbsp; ';
    echo '<label><input type="checkbox" name="_apollo_is_material_change" value="1" ' . checked($mat_change,true,false) . '> ' . esc_html__( 'Material change', 'apollo-plugin' ) . '</label>';
    echo '</td></tr></tbody></table>';
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_corrections_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_corrections_nonce'] ) ), 'apollo_corrections_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    foreach ( [ '_apollo_correction_note', '_apollo_correction_ts', '_apollo_public_update_note', '_apollo_last_updated_date', '_apollo_editor_notes', '_apollo_change_summary' ] as $key ) {
        update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[$key] ?? '' ) ) );
    }
    update_post_meta( $post_id, '_apollo_show_correction', ! empty( $_POST['_apollo_show_correction'] ) );
    update_post_meta( $post_id, '_apollo_is_material_change', ! empty( $_POST['_apollo_is_material_change'] ) );
} );

add_action( 'wp_head', function(): void {
    echo '<style>
.apollo-correction-box{background:#fff3cd;border-left:4px solid #ffc107;padding:12px 16px;margin:16px 0;font-size:14px;}
.apollo-update-note{background:#d1ecf1;border-left:4px solid #17a2b8;padding:12px 16px;margin:16px 0;font-size:14px;}
</style>';
} );
