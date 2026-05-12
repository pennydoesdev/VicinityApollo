<?php
/**
 * REST route audit.
 *
 * @package Apollo\Serve\Security
 */

namespace Apollo\Serve\Security;

defined( 'ABSPATH' ) || exit;

final class RestAudit {

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'scan' ], 100000 );
	}

	public static function scan(): void {
		$server = rest_get_server();
		if ( ! $server ) return;
		$routes = $server->get_routes();
		$bad    = [];
		foreach ( $routes as $route => $handlers ) {
			if ( strpos( $route, '/pfs/v1/' ) !== 0 ) continue;
			foreach ( $handlers as $h ) {
				$cb = $h['permission_callback'] ?? null;
				if ( ! $cb || $cb === '__return_true' ) $bad[] = $route;
			}
		}
		if ( ! empty( $bad ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Apollo] REST routes without permission_callback: ' . implode( ', ', array_unique( $bad ) ) );
		}
		if ( ! empty( $bad ) && apply_filters( 'apollo_rest_strict_permissions', false ) ) {
			add_filter( 'rest_pre_dispatch', static function ( $result, $server, $request ) use ( $bad ) {
				$route = $request->get_route();
				foreach ( $bad as $b ) {
					if ( $route === $b ) {
						return new \WP_Error( 'apollo_missing_permission_callback', 'Route rejected.', [ 'status' => 403 ] );
					}
				}
				return $result;
			}, 10, 3 );
		}
	}
}

RestAudit::register();
