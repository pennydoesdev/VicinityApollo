<?php
/**
 * Shared helpers used by all plugin modules.
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

function apollo_opt( string $key, $default = null ) {
	static $cache = [];
	if ( array_key_exists( $key, $cache ) ) {
		return $cache[ $key ];
	}
	$cache[ $key ] = get_option( $key, $default );
	return $cache[ $key ];
}

function apollo_bridge( string $slug, callable $renderer, int $priority = 10 ): void {
	add_filter( "apollo_render_{$slug}", static function ( $previous, $args ) use ( $renderer ) {
		if ( null !== $previous && '' !== $previous ) {
			return $previous;
		}
		$out = $renderer( is_array( $args ) ? $args : [] );
		return is_string( $out ) ? $out : '';
	}, $priority, 2 );
}

function apollo_secret( string $key ): string {
	$v = get_option( $key, '' );
	return is_string( $v ) ? $v : '';
}
