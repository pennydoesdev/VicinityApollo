<?php
/**
 * Central bridge registrations.
 *
 * The theme calls `apollo_render( $slug, $args )` which runs the
 * filter `apollo_render_{$slug}`. Each registration below wires a
 * slug to the actual render function living inside a plugin module.
 *
 * If a target function is not loaded (module disabled, or feature file
 * missing), the filter returns null and the theme renders a placeholder.
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

/**
 * Slug => callable map. Callable receives the args array from the theme
 * and returns an HTML string (or '' for nothing).
 *
 * The callables are wrapped with function_exists() inside apollo_bridge(),
 * so missing implementations never fatal.
 */
$apollo_bridge_map = [
	'video-player'      => static fn( $a ) => function_exists( 'svh_player_html' )
		? (string) svh_player_html( (int) ( $a['post_id'] ?? 0 ) )
		: '',
	'election-race'     => static fn( $a ) => function_exists( 'sev_render_race' )
		? (string) sev_render_race( (int) ( $a['post_id'] ?? 0 ), is_array( $a['opts'] ?? null ) ? $a['opts'] : [] )
		: '',
	'takeaways'         => static fn( $a ) => function_exists( 'serve_takeaways_render' )
		? (string) serve_takeaways_render( (int) ( $a['post_id'] ?? 0 ) )
		: '',
	'ethical-ai-badge'  => static fn( $a ) => function_exists( 'serve_ethical_ai_badge_html' )
		? (string) serve_ethical_ai_badge_html( (int) ( $a['post_id'] ?? 0 ) )
		: '',
	'comments-hub'      => static fn( $a ) => function_exists( 'pt_comments_render' )
		? (string) pt_comments_render( (int) ( $a['post_id'] ?? 0 ) )
		: '',
	'share-buttons'     => static fn( $a ) => function_exists( 'serve_share_buttons_html' )
		? (string) serve_share_buttons_html( (int) ( $a['post_id'] ?? 0 ) )
		: '',
	'newsletter-form'   => static fn( $a ) => function_exists( 'serve_newsletter_form_html' )
		? (string) serve_newsletter_form_html( is_array( $a ) ? $a : [] )
		: '',
	'live-radio-bar'    => static fn( $a ) => function_exists( 'serve_live_radio_bar' )
		? (string) serve_live_radio_bar()
		: '',
];

foreach ( $apollo_bridge_map as $slug => $renderer ) {
	apollo_bridge( $slug, $renderer );
}

/**
 * Non-render data provider used by theme news ticker.
 */
if ( ! function_exists( 'apollo_news_ticker_items_provider' ) ) {
	function apollo_news_ticker_items_provider(): array {
		if ( function_exists( 'serve_news_ticker_items' ) ) {
			$r = serve_news_ticker_items();
			return is_array( $r ) ? $r : [];
		}
		// Fallback — latest three posts.
		$posts = get_posts( [
			'numberposts' => 3,
			'post_status' => 'publish',
			'suppress_filters' => false,
		] );
		return array_map( static fn( $p ) => [
			'title' => get_the_title( $p ),
			'link'  => get_permalink( $p ),
		], $posts );
	}
}
