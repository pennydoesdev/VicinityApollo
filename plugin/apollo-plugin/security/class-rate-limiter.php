<?php
/**
 * Token-bucket rate limiter backed by transients.
 *
 * @package Apollo\Serve\Security
 */

namespace Apollo\Serve\Security;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	public static function hit( string $bucket, int $max, int $window, string $subject = '' ): bool {
		if ( $max <= 0 || $window <= 0 ) return true;
		$subject = $subject !== '' ? $subject : self::client_hash();
		$key     = 'apollo_rl_' . md5( $bucket . '|' . $subject );
		$count   = (int) get_transient( $key );
		if ( $count >= $max ) return false;
		set_transient( $key, $count + 1, $window );
		return true;
	}

	public static function client_hash(): string {
		$ip   = self::client_ip();
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 200 ) : '';
		$salt = wp_salt( 'auth' );
		return hash( 'sha256', $ip . '|' . $ua . '|' . $salt );
	}

	public static function client_ip(): string {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) continue;
			$candidate = trim( explode( ',', (string) $_SERVER[ $k ] )[0] );
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) return $candidate;
		}
		return '0.0.0.0';
	}
}
