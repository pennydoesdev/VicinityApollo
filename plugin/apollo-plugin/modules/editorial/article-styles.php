<?php
/**
 * Apollo — Feature 1: Article Template System
 *
 * Adds article presentation styles to standard WordPress posts via post meta.
 * No new post types — styles render through classes and template parts.
 *
 * Styles: standard | breaking | live-update | magazine | investigation |
 *         opinion | explainer | interview | review | photo-essay |
 *         video-led | podcast-led
 *
 * @package Apollo\Serve
 */
defined( 'ABSPATH' ) || exit;

// ── Article style meta key
define( 'APOLLO_ARTICLE_STYLE_KEY', '_apollo_article_style' );

// ── Register meta
add_action( 'init', function(): void {
    foreach ( [ 'post', 'page' ] as $pt ) {
        register_post_meta( $pt, APOLLO_ARTICLE_STYLE_KEY, [
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }
} );

// ── Available styles
function apollo_article_styles(): array {
    return [
        'standard'     => __( 'Standard Article', 'apollo-plugin' ),
        'breaking'     => __( 'Breaking News', 'apollo-plugin' ),
        'live-update'  => __( 'Live Update', 'apollo-plugin' ),
        'magazine'     => __( 'Magazine Feature', 'apollo-plugin' ),
        'investigation'=> __( 'Investigation', 'apollo-plugin' ),
        'opinion'      => __( 'Opinion Column', 'apollo-plugin' ),
        'explainer'    => __( 'Explainer', 'apollo-plugin' ),
        'interview'    => __( 'Interview', 'apollo-plugin' ),
        'review'       => __( 'Review', 'apollo-plugin' ),
        'photo-essay'  => __( 'Photo Essay', 'apollo-plugin' ),
        'video-led'    => __( 'Video-Led Article', 'apollo-plugin' ),
        'podcast-led'  => __( 'Podcast-Led Article', 'apollo-plugin' ),
    ];
}

// ── Get the style for a post
function apollo_get_article_style( int $post_id = 0 ): string {
    if ( ! $post_id ) $post_id = get_the_ID() ?: 0;
    $style = (string) get_post_meta( $post_id, APOLLO_ARTICLE_STYLE_KEY, true );
    return $style && isset( apollo_article_styles()[ $style ] ) ? $style : 'standard';
}

// ── Add body class
add_filter( 'body_class', function( array $classes ): array {
    if ( is_singular( 'post' ) ) {
        $style = apollo_get_article_style( get_the_ID() ?: 0 );
        $classes[] = 'article-style-' . sanitize_html_class( $style );
    }
    return $classes;
} );

// ── Add post class
add_filter( 'post_class', function( array $classes, array $class, int $post_id ): array {
    if ( get_post_type( $post_id ) === 'post' ) {
        $style = apollo_get_article_style( $post_id );
        $classes[] = 'article-style-' . sanitize_html_class( $style );
    }
    return $classes;
}, 10, 3 );

// ── Gutenberg sidebar panel via inline script
add_action( 'enqueue_block_editor_assets', function(): void {
    $screen = get_current_screen();
    if ( ! $screen || ! in_array( $screen->post_type, [ 'post', 'page' ], true ) ) return;

    $styles = apollo_article_styles();
    $options = array_map( fn( $v, $k ) => [ 'label' => $v, 'value' => $k ], $styles, array_keys( $styles ) );

    wp_add_inline_script( 'wp-editor', '' ); // ensure wp-editor is enqueued
    wp_register_script( 'apollo-article-styles-panel', false, [ 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-data', 'wp-components' ], false, true );
    wp_enqueue_script( 'apollo-article-styles-panel' );
    wp_add_inline_script( 'apollo-article-styles-panel', sprintf(
        '(function(wp){
            if(!wp||!wp.plugins||!wp.element) return;
            var PDSP = (wp.editor&&wp.editor.PluginDocumentSettingPanel)||(wp.editPost&&wp.editPost.PluginDocumentSettingPanel);
            if(!PDSP) return;
            var el=wp.element.createElement, useSelect=wp.data.useSelect, useDispatch=wp.data.useDispatch;
            var SelectControl=wp.components.SelectControl;
            wp.plugins.registerPlugin("apollo-article-styles",{render:function(){
                var postType=useSelect(function(s){var ed=s("core/editor");return ed&&ed.getCurrentPostType?ed.getCurrentPostType():"post";},[]);
                if(postType!=="post"&&postType!=="page") return null;
                var meta=useSelect(function(s){var ed=s("core/editor");return ed&&ed.getEditedPostAttribute?ed.getEditedPostAttribute("meta")||{}:{};},[]);
                var dispatch=useDispatch("core/editor");
                return el(PDSP,{name:"apollo-article-style",title:"📝 Article Style",initialOpen:false},
                    el(SelectControl,{label:"Presentation Style",value:meta._apollo_article_style||"standard",options:%s,onChange:function(v){dispatch.editPost({meta:{_apollo_article_style:v}});}}));
            }});
        }(window.wp));',
        wp_json_encode( array_merge( [ [ 'label' => 'Standard Article', 'value' => 'standard' ] ], $options ) )
    ) );
} );

// ── REST: expose article style on post list
add_filter( 'rest_prepare_post', function( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
    $response->data['apollo_article_style'] = apollo_get_article_style( $post->ID );
    return $response;
}, 10, 2 );
