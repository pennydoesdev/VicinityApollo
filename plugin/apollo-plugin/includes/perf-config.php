<?php
/**
 * Performance configuration reader.
 *
 * @package Apollo\Plugin
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'serve_perf_defaults' ) ) {
	function serve_perf_defaults(): array {
		return [
			'minify_css'              => true,
			'minify_html'             => false,
			'defer_js'                => true,
			'defer_non_essential_js'  => false,
			'concat_js'               => false,
			'concat_css'              => false,
			'remove_jquery_migrate'   => true,
			'jquery_to_footer'        => true,
			'disable_emoji'           => true,
			'clean_head'              => true,
			'dns_prefetch'            => true,
			'lazy_iframes'            => true,
			'cache_homepage_ttl'      => 300,
			'cache_page_ttl'          => 3600,
			'cache_static_ttl'        => 31536000,
			'cf_edge_ttl'             => 86400,
			'cf_browser_ttl'          => 14400,
			'cf_static_assets'        => false,
			'cf_static_domain'        => '',
			'cf_media_assets'         => false,
		];
	}
}

if ( ! function_exists( 'serve_perf_get' ) ) {
	function serve_perf_get( $key = null, $fallback = null ) {
		static $config = null;
		if ( $config === null ) {
			$config = wp_parse_args( get_option( 'serve_perf_config', [] ), serve_perf_defaults() );
		}
		if ( $key === null ) {
			return $config;
		}
		return $config[ $key ] ?? $fallback ?? serve_perf_defaults()[ $key ] ?? null;
	}
}
