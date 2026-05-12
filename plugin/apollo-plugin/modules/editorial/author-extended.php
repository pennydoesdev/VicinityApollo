<?php
/**
 * Apollo — Feature 5: Enhanced Author & Contributor System
 * Feature 25: Archive & Topic Page Upgrades
 *
 * Extends existing author-profile.php with extra fields.
 * Adds topic hub meta for category/archive pages.
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ── Extended author fields
add_action( 'show_user_profile', 'apollo_author_extra_fields' );
add_action( 'edit_user_profile', 'apollo_author_extra_fields' );

function apollo_author_extra_fields( \WP_User $user ): void {
    ?>
    <h2><?php esc_html_e( 'Apollo Author Profile', 'apollo-plugin' ); ?></h2>
    <table class="form-table">
        <?php
        $fields = [
            '_apollo_author_title'     => [ 'label' => __( 'Title / Role', 'apollo-plugin' ), 'type' => 'text', 'help' => 'e.g. "Investigative Reporter", "Senior Editor"' ],
            '_apollo_author_beat'      => [ 'label' => __( 'Beat', 'apollo-plugin' ), 'type' => 'text', 'help' => 'e.g. "Politics, Education"' ],
            '_apollo_author_location'  => [ 'label' => __( 'Location', 'apollo-plugin' ), 'type' => 'text' ],
            '_apollo_author_email_pub' => [ 'label' => __( 'Public Email', 'apollo-plugin' ), 'type' => 'email', 'help' => 'Displayed on author page (separate from WP login email)' ],
            '_apollo_author_twitter'   => [ 'label' => __( 'X / Twitter', 'apollo-plugin' ), 'type' => 'text', 'help' => '@handle or full URL' ],
            '_apollo_author_instagram' => [ 'label' => __( 'Instagram', 'apollo-plugin' ), 'type' => 'text' ],
            '_apollo_author_linkedin'  => [ 'label' => __( 'LinkedIn URL', 'apollo-plugin' ), 'type' => 'url' ],
            '_apollo_author_mastodon'  => [ 'label' => __( 'Mastodon', 'apollo-plugin' ), 'type' => 'text' ],
            '_apollo_author_disclosure'=> [ 'label' => __( 'Disclosure Note', 'apollo-plugin' ), 'type' => 'textarea', 'help' => 'Public conflict-of-interest disclosure' ],
            '_apollo_author_credits'   => [ 'label' => __( 'Credit Types', 'apollo-plugin' ), 'type' => 'text', 'help' => 'e.g. Reporter, Photojournalist, Columnist' ],
        ];
        foreach ( $fields as $key => $f ) :
            $val = (string) get_user_meta( $user->ID, $key, true );
            ?>
            <tr>
                <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($f['label']); ?></label></th>
                <td>
                    <?php if ( $f['type'] === 'textarea' ) : ?>
                        <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="3" class="large-text"><?php echo esc_textarea($val); ?></textarea>
                    <?php else : ?>
                        <input type="<?php echo esc_attr($f['type']); ?>" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($val); ?>" class="regular-text">
                    <?php endif; ?>
                    <?php if ( ! empty($f['help']) ) : ?><p class="description"><?php echo esc_html($f['help']); ?></p><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <tr>
            <th><?php esc_html_e( 'Verified Staff Badge', 'apollo-plugin' ); ?></th>
            <td>
                <label><input type="checkbox" name="_apollo_author_verified" value="1" <?php checked( (bool) get_user_meta($user->ID,'_apollo_author_verified',true) ); ?>>
                <?php esc_html_e( 'Show verified staff badge on bylines and author page', 'apollo-plugin' ); ?></label>
            </td>
        </tr>
    </table>
    <?php
}

add_action( 'personal_options_update', 'apollo_save_author_extra_fields' );
add_action( 'edit_user_profile_update', 'apollo_save_author_extra_fields' );

function apollo_save_author_extra_fields( int $user_id ): void {
    if ( ! current_user_can( 'edit_user', $user_id ) ) return;
    $string_fields = [ '_apollo_author_title', '_apollo_author_beat', '_apollo_author_location', '_apollo_author_email_pub', '_apollo_author_twitter', '_apollo_author_instagram', '_apollo_author_linkedin', '_apollo_author_mastodon', '_apollo_author_credits' ];
    foreach ( $string_fields as $key ) {
        update_user_meta( $user_id, $key, sanitize_text_field( wp_unslash( $_POST[$key] ?? '' ) ) );
    }
    update_user_meta( $user_id, '_apollo_author_disclosure', sanitize_textarea_field( wp_unslash( $_POST['_apollo_author_disclosure'] ?? '' ) ) );
    update_user_meta( $user_id, '_apollo_author_verified', ! empty( $_POST['_apollo_author_verified'] ) );
}

// ── Get extended author card HTML
function apollo_author_card_html( int $user_id ): string {
    $user  = get_userdata( $user_id );
    if ( ! $user ) return '';
    $title      = (string) get_user_meta( $user_id, '_apollo_author_title', true );
    $beat       = (string) get_user_meta( $user_id, '_apollo_author_beat', true );
    $twitter    = (string) get_user_meta( $user_id, '_apollo_author_twitter', true );
    $email_pub  = (string) get_user_meta( $user_id, '_apollo_author_email_pub', true );
    $bio        = (string) get_user_meta( $user_id, 'description', true );
    $verified   = (bool)   get_user_meta( $user_id, '_apollo_author_verified', true );
    $disclosure = (string) get_user_meta( $user_id, '_apollo_author_disclosure', true );
    $avatar     = get_avatar( $user_id, 80, '', '', [ 'class' => 'apollo-author-card__avatar' ] );

    $out  = '<div class="apollo-author-card">';
    $out .= $avatar;
    $out .= '<div class="apollo-author-card__info">';
    $out .= '<a href="' . esc_url(get_author_posts_url($user_id)) . '" class="apollo-author-card__name">' . esc_html($user->display_name) . '</a>';
    if ( $verified ) $out .= ' <span class="apollo-author-card__verified" title="' . esc_attr__('Verified Staff','apollo-plugin') . '">✓</span>';
    if ( $title )    $out .= '<span class="apollo-author-card__title">' . esc_html($title) . '</span>';
    if ( $beat )     $out .= '<span class="apollo-author-card__beat">' . esc_html__('Beat:','apollo-plugin') . ' ' . esc_html($beat) . '</span>';
    if ( $bio )      $out .= '<p class="apollo-author-card__bio">' . wp_kses_post(wpautop($bio)) . '</p>';
    if ( $twitter )  $out .= '<a href="' . esc_url(str_starts_with($twitter,'@') ? 'https://x.com/' . ltrim($twitter,'@') : $twitter) . '" class="apollo-author-card__social" target="_blank" rel="noopener">𝕏</a>';
    if ( $email_pub )$out .= '<a href="mailto:' . esc_attr($email_pub) . '" class="apollo-author-card__email">' . esc_html($email_pub) . '</a>';
    if ( $disclosure )$out .= '<p class="apollo-author-card__disclosure"><em>' . esc_html__('Disclosure:','apollo-plugin') . ' ' . esc_html($disclosure) . '</em></p>';
    $out .= '</div></div>';
    return $out;
}

add_filter( 'apollo_render_author-card', function( $html, array $args ): string {
    $user_id = absint( $args['user_id'] ?? (is_author() ? get_queried_object_id() : get_post_field('post_author', get_the_ID())) );
    return $user_id ? apollo_author_card_html($user_id) : '';
}, 10, 2 );

// ── Topic Hub meta for category/archive pages (Feature 25)
add_action( 'created_category', 'apollo_save_topic_hub_meta' );
add_action( 'edited_category',  'apollo_save_topic_hub_meta' );

function apollo_save_topic_hub_meta( int $term_id ): void {
    if ( ! isset( $_POST['apollo_topic_nonce'] ) ) return;
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['apollo_topic_nonce'] ) ), 'apollo_topic_meta' ) ) return;
    $fields = [ '_apollo_topic_desc', '_apollo_topic_image_id', '_apollo_topic_pinned_post', '_apollo_topic_explainer_id', '_apollo_topic_newsletter_form', '_apollo_topic_related' ];
    foreach ( $fields as $key ) {
        $val = sanitize_text_field( wp_unslash( $_POST[$key] ?? '' ) );
        update_term_meta( $term_id, $key, $val );
    }
}

add_action( 'category_edit_form_fields', function( \WP_Term $term ): void {
    wp_nonce_field( 'apollo_topic_meta', 'apollo_topic_nonce' );
    $fields = [
        '_apollo_topic_desc'          => [ 'label' => __('Topic Description','apollo-plugin'), 'type'=>'textarea' ],
        '_apollo_topic_image_id'      => [ 'label' => __('Topic Image ID','apollo-plugin'), 'type'=>'number' ],
        '_apollo_topic_pinned_post'   => [ 'label' => __('Pinned Story Post ID','apollo-plugin'), 'type'=>'number' ],
        '_apollo_topic_explainer_id'  => [ 'label' => __('Featured Explainer Post ID','apollo-plugin'), 'type'=>'number' ],
        '_apollo_topic_newsletter_form'=> [ 'label' => __('Newsletter Form Embed','apollo-plugin'), 'type'=>'textarea' ],
        '_apollo_topic_related'       => [ 'label' => __('Related Topics (term IDs, comma-separated)','apollo-plugin'), 'type'=>'text' ],
    ];
    foreach ( $fields as $key => $f ) :
        $val = (string) get_term_meta( $term->term_id, $key, true );
        ?>
        <tr class="form-field">
            <th><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($f['label']); ?></label></th>
            <td><?php
                if ( $f['type'] === 'textarea' ) echo '<textarea name="' . esc_attr($key) . '" rows="3" style="width:100%">' . esc_textarea($val) . '</textarea>';
                else echo '<input type="' . esc_attr($f['type']) . '" name="' . esc_attr($key) . '" value="' . esc_attr($val) . '" style="width:100%">';
            ?></td>
        </tr>
    <?php endforeach;
} );
