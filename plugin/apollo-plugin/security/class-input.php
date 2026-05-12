<?php
/**
 * Input sanitization helpers.
 *
 * @package Apollo\Serve\Security
 */

namespace Apollo\Serve\Security;

defined( 'ABSPATH' ) || exit;

final class Input {

	public static function text( string $key, $source = null, string $default = '' ): string {
		$src = self::source( $source );
		return isset( $src[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $src[ $key ] ) ) : $default;
	}

	public static function int( string $key, $source = null, int $default = 0 ): int {
		$src = self::source( $source );
		return isset( $src[ $key ] ) ? (int) absint( $src[ $key ] ) : $default;
	}

	public static function key( string $key, $source = null, string $default = '' ): string {
		$src = self::source( $source );
		return isset( $src[ $key ] ) ? sanitize_key( (string) $src[ $key ] ) : $default;
	}

	public static function email( string $key, $source = null, string $default = '' ): string {
		$src = self::source( $source );
		return isset( $src[ $key ] ) ? sanitize_email( wp_unslash( (string) $src[ $key ] ) ) : $default;
	}

	public static function url( string $key, $source = null, string $default = '' ): string {
		$src = self::source( $source );
		return isset( $src[ $key ] ) ? esc_url_raw( wp_unslash( (string) $src[ $key ] ) ) : $default;
	}

	public static function json( string $raw, int $max_depth = 16 ): mixed {
		if ( strlen( $raw ) > 512 * 1024 ) return null;
		try {
			return json_decode( $raw, true, $max_depth, JSON_THROW_ON_ERROR );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public static function safe_return_url( string $candidate ): string {
		$candidate = wp_validate_redirect( $candidate, '' );
		return $candidate === '' ? home_url( '/' ) : $candidate;
	}

	public static function verify_upload( array $file, array $allowed_mimes, int $max_bytes ): array|\WP_Error {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'apollo_upload_invalid', 'No valid upload.' );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > $max_bytes ) {
			return new \WP_Error( 'apollo_upload_size', 'File too large.' );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], (string) ( $file['name'] ?? '' ), $allowed_mimes );
		if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
			return new \WP_Error( 'apollo_upload_mime', 'File type not allowed.' );
		}
		if ( ! in_array( $check['type'], $allowed_mimes, true ) ) {
			return new \WP_Error( 'apollo_upload_mime', 'File type not allowed.' );
		}
		$safe_name = wp_unique_filename( get_temp_dir(), sanitize_file_name( wp_generate_uuid4() . '.' . $check['ext'] ) );
		return [ 'tmp' => $file['tmp_name'], 'name' => $safe_name, 'mime' => $check['type'], 'ext' => $check['ext'], 'size' => $size ];
	}

	private static function source( $source ): array {
		if ( is_array( $source ) ) return $source;
		return match ( $source ) {
			'GET'     => $_GET,
			'POST'    => $_POST,
			'REQUEST' => $_REQUEST,
			default   => $_REQUEST,
		};
	}
}
