<?php
/**
 * Apollo — Feature 6: Editorial Workflow
 *
 * Newsroom workflow metadata on posts: statuses, checklists, assignment info.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

function apollo_workflow_statuses(): array {
    return [
        'pitch'          => __( 'Pitch', 'apollo-plugin' ),
        'assigned'       => __( 'Assigned', 'apollo-plugin' ),
        'drafting'       => __( 'Drafting', 'apollo-plugin' ),
        'needs-edit'     => __( 'Needs Edit', 'apollo-plugin' ),
        'needs-factcheck'=> __( 'Needs Fact Check', 'apollo-plugin' ),
        'needs-legal'    => __( 'Needs Legal Review', 'apollo-plugin' ),
        'ready'          => __( 'Ready to Publish', 'apollo-plugin' ),
        'scheduled'      => __( 'Scheduled', 'apollo-plugin' ),
        'published'      => __( 'Published', 'apollo-plugin' ),
        'needs-update'   => __( 'Needs Update', 'apollo-plugin' ),
        'archived'       => __( 'Archived', 'apollo-plugin' ),
    ];
}

function apollo_workflow_checklist(): array {
    return [
        'headline_checked'     => __( 'Headline checked', 'apollo-plugin' ),
        'seo_checked'          => __( 'SEO checked', 'apollo-plugin' ),
        'image_rights_checked' => __( 'Image rights checked', 'apollo-plugin' ),
        'sources_checked'      => __( 'Sources checked', 'apollo-plugin' ),
        'factcheck_complete'   => __( 'Fact check complete', 'apollo-plugin' ),
        'transcript_attached'  => __( 'Transcript attached', 'apollo-plugin' ),
        'captions_attached'    => __( 'Captions attached', 'apollo-plugin' ),
        'social_copy_written'  => __( 'Social copy written', 'apollo-plugin' ),
        'newsletter_copy'      => __( 'Newsletter copy written', 'apollo-plugin' ),
    ];
}

add_action( 'init', function(): void {
    foreach ( [ 'post', 'page' ] as $pt ) {
        register_post_meta( $pt, '_apollo_workflow_status', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
        register_post_meta( $pt, '_apollo_workflow_checklist', [
            'show_in_rest' => true, 'single' => true, 'type' => 'string', // JSON
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

add_action( 'add_meta_boxes', function(): void {
    add_meta_box( 'apollo-workflow', __( '📋 Editorial Workflow', 'apollo-plugin' ), 'apollo_workflow_meta_box', [ 'post', 'page' ], 'side', 'high' );
} );

function apollo_workflow_meta_box( \WP_Post $post ): void {
    wp_nonce_field( 'apollo_workflow_' . $post->ID, 'apollo_workflow_nonce' );
    $status    = (string) get_post_meta( $post->ID, '_apollo_workflow_status', true );
    $raw_check = get_post_meta( $post->ID, '_apollo_workflow_checklist', true );
    $checklist = $raw_check ? (array) json_decode( $raw_check, true ) : [];
    ?>
    <p>
        <label><strong><?php esc_html_e( 'Status', 'apollo-plugin' ); ?></strong></label><br>
        <select name="apollo_workflow_status" style="width:100%">
            <option value=""><?php esc_html_e( '— Select status —', 'apollo-plugin' ); ?></option>
            <?php foreach ( apollo_workflow_statuses() as $k => $v ) : ?>
                <option value="<?php echo esc_attr($k); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html($v); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p><strong><?php esc_html_e( 'Checklist', 'apollo-plugin' ); ?></strong></p>
    <?php foreach ( apollo_workflow_checklist() as $k => $label ) : ?>
        <label style="display:block;margin-bottom:4px">
            <input type="checkbox" name="apollo_workflow_checklist[]" value="<?php echo esc_attr($k); ?>" <?php checked( in_array( $k, $checklist, true ) ); ?>>
            <?php echo esc_html( $label ); ?>
        </label>
    <?php endforeach;
}

add_action( 'save_post', function( int $post_id ): void {
    if ( ! isset( $_POST['apollo_workflow_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_workflow_nonce'] ) ), 'apollo_workflow_' . $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;
    if ( wp_is_post_revision( $post_id ) ) return;

    $status = sanitize_key( wp_unslash( $_POST['apollo_workflow_status'] ?? '' ) );
    update_post_meta( $post_id, '_apollo_workflow_status', $status );

    $raw  = (array) ( $_POST['apollo_workflow_checklist'] ?? [] );
    $clean = array_map( 'sanitize_key', $raw );
    update_post_meta( $post_id, '_apollo_workflow_checklist', wp_json_encode( $clean ) );
} );

// ── Admin column
add_filter( 'manage_post_posts_columns', function( array $cols ): array {
    $cols['apollo_workflow'] = __( 'Status', 'apollo-plugin' );
    return $cols;
} );
add_action( 'manage_post_posts_custom_column', function( string $col, int $post_id ): void {
    if ( $col !== 'apollo_workflow' ) return;
    $status   = (string) get_post_meta( $post_id, '_apollo_workflow_status', true );
    $statuses = apollo_workflow_statuses();
    $label    = $statuses[ $status ] ?? '—';
    $color    = match( $status ) {
        'published'  => '#2ecc71', 'ready' => '#3498db', 'needs-legal' => '#e74c3c',
        'drafting'   => '#f39c12', 'pitch' => '#95a5a6',
        default      => '#bdc3c7',
    };
    echo '<span style="background:' . esc_attr( $color ) . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px">' . esc_html( $label ) . '</span>';
}, 10, 2 );
