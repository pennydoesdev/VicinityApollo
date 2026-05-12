<?php
/**
 * Apollo — Document Library archive template + search
 * (CPT serve_document registered in tip-submission.php)
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// Shortcode: [apollo_document_library type="court-filing" count="10"]
add_shortcode( 'apollo_document_library', function( array $atts ): string {
    $atts = shortcode_atts( [ 'type' => '', 'count' => 10 ], $atts );
    $args = [
        'post_type'      => 'serve_document',
        'posts_per_page' => absint($atts['count']),
        'post_status'    => 'publish',
        'meta_query'     => [ [ 'key' => '_doc_is_public', 'value' => '1' ] ],
    ];
    if ( $atts['type'] ) {
        $args['meta_query'][] = [ 'key' => '_doc_doc_type', 'value' => sanitize_key($atts['type']) ];
    }
    $docs = get_posts( $args );
    if ( empty($docs) ) return '<p>' . esc_html__('No documents available.','apollo-plugin') . '</p>';

    $out = '<div class="apollo-document-library"><ul class="apollo-doc-list">';
    foreach ( $docs as $doc ) {
        $type      = (string) get_post_meta($doc->ID,'_doc_doc_type',true);
        $source    = (string) get_post_meta($doc->ID,'_doc_source',true);
        $date_got  = (string) get_post_meta($doc->ID,'_doc_date_obtained',true);
        $file_url  = apollo_media_url($doc->ID,'pdf');
        $citation  = (string) get_post_meta($doc->ID,'_doc_citation',true);

        $out .= '<li class="apollo-doc-list__item">';
        $out .= '<span class="apollo-doc-list__type">' . esc_html(ucwords(str_replace('-',' ',$type))) . '</span>';
        $out .= '<a href="' . esc_url(get_permalink($doc->ID)) . '" class="apollo-doc-list__title">' . esc_html(get_the_title($doc->ID)) . '</a>';
        if ( $source )   $out .= ' <span class="apollo-doc-list__source">— ' . esc_html($source) . '</span>';
        if ( $date_got ) $out .= ' <time class="apollo-doc-list__date">' . esc_html($date_got) . '</time>';
        if ( $citation ) $out .= ' <span class="apollo-doc-list__citation">' . esc_html($citation) . '</span>';
        if ( $file_url ) $out .= ' <a href="' . esc_url($file_url) . '" class="apollo-doc-list__download" target="_blank" rel="noopener">⬇ ' . esc_html__('Download','apollo-plugin') . '</a>';
        $out .= '</li>';
    }
    $out .= '</ul></div>';
    return $out;
} );

// REST endpoint for document search
add_action( 'rest_api_init', function(): void {
    register_rest_route( 'apollo/v1', '/documents', [
        'methods'             => 'GET',
        'callback'            => function( \WP_REST_Request $request ): \WP_REST_Response {
            $args = [
                'post_type'      => 'serve_document',
                'posts_per_page' => min(absint($request->get_param('per_page') ?: 10), 50),
                'post_status'    => 'publish',
                'meta_query'     => [ [ 'key' => '_doc_is_public', 'value' => '1' ] ],
            ];
            if ( $type = sanitize_key($request->get_param('type') ?: '') ) {
                $args['meta_query'][] = [ 'key' => '_doc_doc_type', 'value' => $type ];
            }
            if ( $s = sanitize_text_field($request->get_param('s') ?: '') ) {
                $args['s'] = $s;
            }
            $posts = get_posts($args);
            $data  = array_map( fn($p) => [
                'id'       => $p->ID,
                'title'    => get_the_title($p->ID),
                'url'      => get_permalink($p->ID),
                'type'     => get_post_meta($p->ID,'_doc_doc_type',true),
                'source'   => get_post_meta($p->ID,'_doc_source',true),
                'file_url' => apollo_media_url($p->ID,'pdf'),
            ], $posts );
            return new \WP_REST_Response($data, 200);
        },
        'permission_callback' => '__return_true',
    ]);
} );
