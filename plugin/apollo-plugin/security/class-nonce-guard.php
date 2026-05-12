<?php
/**
 * Centralised nonce verification for AJAX + REST.
 *
 * @package Apollo\Serve\Security
 */

namespace Apollo\Serve\Security;

defined( 'ABSPATH' ) || exit;

final class NonceGuard {

	public static function verify_ajax( string $action, string $field = '_nonce' ): void {
		$nonce = isset( $_REQUEST[ $field ] )
			? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) )
			: '';
		if ( ! $nonce || ! wp_verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				[ 'code' => 'invalid_nonce', 'message' => 'Request could not be verified.' ],
				403
			);
		}
	}

	public static function rest_permission( string $action, ?string $cap = null ): callable {
		return static function ( \WP_REST_Request $req ) use ( $action, $cap ) {
			$nonce = $req->get_header( 'x_wp_nonce' ) ?: $req->get_param( '_wpnonce' );
			if ( ! $nonce || ! wp_verify_nonce( (string) $nonce, $action ) ) {
				return new \WP_Error( 'apollo_invalid_nonce', 'Invalid nonce.', [ 'status' => 403 ] );
			}
			if ( $cap && ! current_user_can( $cap ) ) {
				return new \WP_Error( 'apollo_forbidden', 'Forbidden.', [ 'status' => 403 ] );
			}
			return true;
		};
	}
}
