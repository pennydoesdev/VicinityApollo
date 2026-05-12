<?php
/**
 * Plugin Name:       Apollo
 * Plugin URI:        https://thepennytribune.com
 * Description:       Business-logic companion plugin for the Apollo Theme. Provides CPTs, integrations, auth, secure file drop, elections, video/audio hubs, AI, creator revenue, REST/AJAX, cron, and admin systems. The theme supplies only presentation.
 * Version:           2.21
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Penny Tribune
 * License:           Proprietary
 * Text Domain:       apollo-plugin
 * Domain Path:       /languages
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guard against double-load.
 */
if ( defined( 'APOLLO_PLUGIN_VERSION' ) ) {
	return;
}

define( 'APOLLO_PLUGIN_VERSION', '2.22' );
define( 'APOLLO_PLUGIN_FILE', __FILE__ );
define( 'APOLLO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'APOLLO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'APOLLO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Legacy aliases used by inherited modules (kept during migration).
if ( ! defined( 'SERVE_VERSION' ) ) {
	define( 'SERVE_VERSION', APOLLO_PLUGIN_VERSION );
}

if ( ! function_exists( 'serve_perf_defaults' ) ) {
	function serve_perf_defaults(): array {
		return [
			'minify_css'              => false,
			'minify_html'             => false,
			'defer_js'                => false,
			'defer_non_essential_js'  => false,
			'concat_js'               => false,
			'concat_css'              => false,
			'remove_jquery_migrate'   => false,
			'jquery_to_footer'        => false,
			'disable_emoji'           => false,
			'clean_head'              => false,
			'dns_prefetch'            => false,
			'lazy_iframes'            => false,
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
		static $cfg = null;
		if ( $cfg === null ) {
			$defaults = function_exists( 'serve_perf_defaults' ) ? serve_perf_defaults() : [];
			$stored   = function_exists( 'get_option' ) ? get_option( 'serve_perf_config', [] ) : [];
			$cfg = array_merge( $defaults, is_array( $stored ) ? $stored : [] );
		}
		if ( $key === null ) {
			return $cfg;
		}
		return $cfg[ $key ] ?? $fallback ?? null;
	}
}

add_action( 'plugins_loaded', static function () {
	if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p><strong>Apollo</strong> requires PHP 8.3 or higher. Current: ' . esc_html( PHP_VERSION ) . '.</p></div>';
		} );
		return;
	}

	require_once APOLLO_PLUGIN_PATH . 'includes/class-plugin.php';
	\Apollo\Serve\Plugin::boot();
}, 1 );

register_activation_hook( __FILE__, static function () {
	require_once APOLLO_PLUGIN_PATH . 'includes/class-activator.php';
	\Apollo\Serve\Activator::activate();
} );

register_deactivation_hook( __FILE__, static function () {
	require_once APOLLO_PLUGIN_PATH . 'includes/class-activator.php';
	\Apollo\Serve\Activator::deactivate();
} );

add_action( 'upgrader_process_complete', static function ( $upgrader, $hook_extra ) {
	if (
		isset( $hook_extra['type'], $hook_extra['plugins'] ) &&
		$hook_extra['type'] === 'plugin' &&
		in_array( plugin_basename( APOLLO_PLUGIN_FILE ), (array) $hook_extra['plugins'], true )
	) {
		set_transient( 'apollo_flush_rewrite_rules', 1, 60 );
	}
}, 10, 2 );

add_action( 'init', static function () {
	if ( get_transient( 'apollo_flush_rewrite_rules' ) ) {
		delete_transient( 'apollo_flush_rewrite_rules' );
		flush_rewrite_rules( false );
	}
}, 9999 );
