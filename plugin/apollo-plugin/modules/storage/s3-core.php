<?php
/**
 * Apollo — Amazon S3 + Cloudflare R2 Core Library
 *
 * Provides SigV4 signing, config resolution, presigned PUT/GET URL generation,
 * and server-side multipart upload (MPU) helpers for AWS S3 and Cloudflare R2.
 *
 * Used for VIDEO and AUDIO storage. PDFs/documents use media-library.php.
 *
 * wp-config.php constants (optional — take priority over DB options):
 *   define( 'APOLLO_S3_ACCESS_KEY', 'AKIAIOSFODNN7EXAMPLE' );
 *   define( 'APOLLO_S3_SECRET_KEY', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' );
 *   define( 'APOLLO_S3_REGION',     'us-east-1' );
 *   define( 'APOLLO_S3_BUCKET',     'pennytribune-media' );
 *   define( 'APOLLO_S3_CF_URL',     'https://d1abc123.cloudfront.net' );
 *
 * Bucket layout (one bucket, prefix-based):
 *   video/YYYY/MM/slug-timestamp.mp4
 *   podcast/YYYY/MM/slug-timestamp.mp3
 *   images/YYYY/MM/filename.jpg
 *
 * @package Apollo\Serve
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// 1. CONFIGURATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the S3 configuration array.
 *
 * Keys: access_key, secret_key, region, bucket, cf_url,
 *       video_backend, audio_backend, images_backend
 *
 * @param bool $reset  Pass true to flush the static cache after a settings save.
 * @return array<string, string>
 */
function apollo_s3_config( bool $reset = false ): array {
	static $c = null;
	if ( $reset ) {
		$c = null;
	}
	if ( $c !== null ) {
		return $c;
	}

	$c = [
		'access_key' => defined( 'APOLLO_S3_ACCESS_KEY' )
			? APOLLO_S3_ACCESS_KEY
			: (string) get_option( 'apollo_s3_access_key', '' ),

		'secret_key' => defined( 'APOLLO_S3_SECRET_KEY' )
			? APOLLO_S3_SECRET_KEY
			: (string) get_option( 'apollo_s3_secret_key', '' ),

		'region' => defined( 'APOLLO_S3_REGION' )
			? APOLLO_S3_REGION
			: ( (string) get_option( 'apollo_s3_region', '' ) ?: 'us-east-1' ),

		'bucket' => defined( 'APOLLO_S3_BUCKET' )
			? APOLLO_S3_BUCKET
			: (string) get_option( 'apollo_s3_bucket', '' ),

		'cf_url' => rtrim(
			defined( 'APOLLO_S3_CF_URL' )
				? APOLLO_S3_CF_URL
				: (string) get_option( 'apollo_s3_cf_url', '' ),
			'/'
		),

		// Per-hub storage backend: 'r2' (default) or 's3'
		'video_backend'  => (string) get_option( 'apollo_storage_video',  'r2' ),
		'audio_backend'  => (string) get_option( 'apollo_storage_audio',  'r2' ),
		'images_backend' => (string) get_option( 'apollo_storage_images', 'r2' ),
	];

	return $c;
}

/** Flush the static config cache after a settings save. */
function apollo_s3_reset_static_cache(): void {
	apollo_s3_config( true );
}

/**
 * Returns true when S3 credentials + bucket are fully configured.
 *
 * @param array<string,string>|null $cfg
 */
function apollo_s3_ready( ?array $cfg = null ): bool {
	$cfg = $cfg ?? apollo_s3_config();
	return ! empty( $cfg['access_key'] )
		&& ! empty( $cfg['secret_key'] )
		&& ! empty( $cfg['bucket'] );
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. URL HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the virtual-hosted-style S3 host for the configured bucket.
 * e.g. "pennytribune-media.s3.us-east-1.amazonaws.com"
 *
 * @param array<string,string>|null $cfg
 */
function apollo_s3_host( ?array $cfg = null ): string {
	$cfg = $cfg ?? apollo_s3_config();
	return trim( $cfg['bucket'] ) . '.s3.' . ( $cfg['region'] ?: 'us-east-1' ) . '.amazonaws.com';
}

/**
 * Returns the public URL for a stored object.
 * Uses the CloudFront distribution URL if configured, otherwise falls back
 * to the direct S3 virtual-hosted URL.
 *
 * @param string                    $key  Object key, e.g. "video/2024/01/my-video.mp4"
 * @param array<string,string>|null $cfg
 */
function apollo_s3_public_url( string $key, ?array $cfg = null ): string {
	$cfg = $cfg ?? apollo_s3_config();
	$key = ltrim( $key, '/' );

	if ( $cfg['cf_url'] ) {
		return $cfg['cf_url'] . '/' . $key;
	}

	return 'https://' . apollo_s3_host( $cfg ) . '/' . $key;
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. SIGV4 — SERVER-SIDE AUTH HEADERS  (PHP → S3 direct)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Build AWS SigV4 Authorization headers for server-side S3 requests.
 *
 * Uses virtual-hosted-style URLs: {bucket}.s3.{region}.amazonaws.com/{key}
 *
 * @param string                    $method       HTTP method (GET, POST, PUT, DELETE)
 * @param string                    $key          Object key (without leading slash)
 * @param string                    $content_type Content-Type of the request body
 * @param string                    $body_sha256  sha256 hex digest of the request body (or UNSIGNED-PAYLOAD)
 * @param array<string,string>      $extra_query  Additional query params (already decoded; will be encoded here)
 * @param array<string,string>|null $cfg
 *
 * @return array<string,string>  Headers + 'url' key with the full request URL.
 */
function apollo_s3_auth_headers(
	string $method,
	string $key,
	string $content_type,
	string $body_sha256,
	array  $extra_query = [],
	?array $cfg = null
): array {
	$cfg       = $cfg ?? apollo_s3_config();
	$key_id    = trim( $cfg['access_key'] );
	$secret    = trim( $cfg['secret_key'] );
	$region    = $cfg['region'] ?: 'us-east-1';
	$service   = 's3';
	$host      = apollo_s3_host( $cfg );

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	// Virtual-hosted style: no bucket in the URI path
	$uri_path = '/' . implode(
		'/',
		array_map( 'rawurlencode', explode( '/', ltrim( $key, '/' ) ) )
	);
	// Root-level calls (e.g. CreateMultipartUpload at /) produce just "/"
	if ( $uri_path === '//' ) {
		$uri_path = '/';
	}

	// Canonical query string — keys and values URI-encoded, sorted by encoded key
	ksort( $extra_query );
	$qs_parts = [];
	foreach ( $extra_query as $k => $v ) {
		$qs_parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
	}
	$query_string = implode( '&', $qs_parts );

	// Canonical headers (sorted alphabetically)
	$canonical_headers = "content-type:{$content_type}\n"
		. "host:{$host}\n"
		. "x-amz-content-sha256:{$body_sha256}\n"
		. "x-amz-date:{$amz_date}\n";
	$signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

	$canonical = $method . "\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. $canonical_headers . "\n"
		. $signed_headers . "\n"
		. $body_sha256;

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature  = hash_hmac( 'sha256', $str_to_sign, $k_signing );
	$credential = "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request";
	$auth       = "AWS4-HMAC-SHA256 Credential={$credential},SignedHeaders={$signed_headers},Signature={$signature}";

	return [
		'Authorization'        => $auth,
		'Content-Type'         => $content_type,
		'x-amz-content-sha256' => $body_sha256,
		'x-amz-date'           => $amz_date,
		'url'                  => 'https://' . $host . $uri_path . ( $query_string ? "?{$query_string}" : '' ),
	];
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. PRESIGNED URLS  (browser → S3 direct, no PHP data proxy)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate a presigned PUT URL for one multipart upload part on S3.
 *
 * @param string                    $object_key  Object key in S3
 * @param string                    $upload_id   UploadId from CreateMultipartUpload
 * @param int                       $part_num    1-based part number
 * @param int                       $expires     URL validity in seconds (default 3600)
 * @param array<string,string>|null $cfg
 * @return string  Complete presigned URL the browser can PUT to
 */
function apollo_s3_presign_part_url(
	string $object_key,
	string $upload_id,
	int    $part_num,
	int    $expires = 3600,
	?array $cfg = null
): string {
	$cfg       = $cfg ?? apollo_s3_config();
	$key_id    = trim( $cfg['access_key'] );
	$secret    = trim( $cfg['secret_key'] );
	$region    = $cfg['region'] ?: 'us-east-1';
	$service   = 's3';
	$host      = apollo_s3_host( $cfg );

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	$uri_path = '/' . implode(
		'/',
		array_map( 'rawurlencode', explode( '/', ltrim( $object_key, '/' ) ) )
	);

	$params = [
		'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'    => "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request",
		'X-Amz-Date'          => $amz_date,
		'X-Amz-Expires'       => (string) $expires,
		'X-Amz-SignedHeaders'  => 'host',
		'partNumber'           => (string) $part_num,
		'uploadId'             => $upload_id,
	];
	ksort( $params );

	$qs_parts = [];
	foreach ( $params as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$canonical = "PUT\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. "host:{$host}\n\n"
		. "host\n"
		. 'UNSIGNED-PAYLOAD';

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature = hash_hmac( 'sha256', $str_to_sign, $k_signing );

	return 'https://' . $host . $uri_path . '?' . $query_string . '&X-Amz-Signature=' . rawurlencode( $signature );
}

/**
 * Generate a presigned GET URL for an S3 object.
 *
 * @param string                    $object_key
 * @param int                       $expires     URL validity in seconds (default 3600)
 * @param array<string,string>|null $cfg
 * @return string
 */
function apollo_s3_presign_get_url(
	string $object_key,
	int    $expires = 3600,
	?array $cfg = null
): string {
	$cfg       = $cfg ?? apollo_s3_config();
	$key_id    = trim( $cfg['access_key'] );
	$secret    = trim( $cfg['secret_key'] );
	$region    = $cfg['region'] ?: 'us-east-1';
	$service   = 's3';
	$host      = apollo_s3_host( $cfg );

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	$uri_path = '/' . implode(
		'/',
		array_map( 'rawurlencode', explode( '/', ltrim( $object_key, '/' ) ) )
	);

	$params = [
		'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'   => "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request",
		'X-Amz-Date'         => $amz_date,
		'X-Amz-Expires'      => (string) $expires,
		'X-Amz-SignedHeaders' => 'host',
	];
	ksort( $params );

	$qs_parts = [];
	foreach ( $params as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$canonical = "GET\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. "host:{$host}\n\n"
		. "host\n"
		. 'UNSIGNED-PAYLOAD';

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature = hash_hmac( 'sha256', $str_to_sign, $k_signing );

	return 'https://' . $host . $uri_path . '?' . $query_string . '&X-Amz-Signature=' . rawurlencode( $signature );
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. R2 PRESIGNED GET  (used by sync tool to download from R2)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate a presigned GET URL for an R2 object.
 *
 * @param string                    $object_key  R2 object key
 * @param int                       $expires     URL validity in seconds
 * @param array<string,string>|null $cfg         R2 config (defaults to svh_r2_config())
 * @return string
 */
function svh_r2_presign_get_url(
	string $object_key,
	int    $expires = 3600,
	?array $cfg = null
): string {
	$cfg        = $cfg ?? ( function_exists( 'svh_r2_config' ) ? svh_r2_config() : [] );
	$key_id     = trim( $cfg['access_key'] ?? '' );
	$secret     = trim( $cfg['secret_key'] ?? '' );
	$bucket     = trim( $cfg['bucket'] ?? '' );
	$acct       = trim( $cfg['account_id'] ?? '' );
	$region     = 'auto';
	$service    = 's3';
	$host       = "{$acct}.r2.cloudflarestorage.com";

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	// R2 uses path-style: /{bucket}/{key}
	$uri_path = '/' . $bucket . '/' .
		implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $object_key, '/' ) ) ) );

	$params = [
		'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'   => "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request",
		'X-Amz-Date'         => $amz_date,
		'X-Amz-Expires'      => (string) $expires,
		'X-Amz-SignedHeaders' => 'host',
	];
	ksort( $params );

	$qs_parts = [];
	foreach ( $params as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$canonical = "GET\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. "host:{$host}\n\n"
		. "host\n"
		. 'UNSIGNED-PAYLOAD';

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature = hash_hmac( 'sha256', $str_to_sign, $k_signing );

	return "https://{$host}{$uri_path}?{$query_string}&X-Amz-Signature=" . rawurlencode( $signature );
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. SERVER-SIDE MULTIPART UPLOAD OPERATIONS  (S3)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Initiate a multipart upload on S3 (CreateMultipartUpload).
 *
 * @param string                    $key          Object key in the S3 bucket
 * @param string                    $content_type MIME type of the final object
 * @param array<string,string>|null $cfg
 * @return string|\WP_Error  UploadId on success, WP_Error on failure
 */
function apollo_s3_mpu_init( string $key, string $content_type, ?array $cfg = null ): string|\WP_Error {
	$cfg = $cfg ?? apollo_s3_config();
	if ( ! apollo_s3_ready( $cfg ) ) {
		return new \WP_Error( 's3_not_configured', 'S3 credentials not fully configured.' );
	}

	$body_sha256 = hash( 'sha256', '' );
	$headers     = apollo_s3_auth_headers( 'POST', $key, $content_type, $body_sha256, [ 'uploads' => '' ], $cfg );
	$url         = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 30,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		error_log( "[apollo_s3] MPU init failed HTTP {$code}: {$msg} | key={$key}" );
		return new \WP_Error( 's3_init_failed', "S3 MPU init failed (HTTP {$code}): {$msg}" );
	}

	preg_match( '/<UploadId>([^<]+)<\/UploadId>/', $body, $m );
	$upload_id = $m[1] ?? '';

	if ( ! $upload_id ) {
		error_log( "[apollo_s3] MPU init: no UploadId in response: " . substr( $body, 0, 500 ) );
		return new \WP_Error( 's3_no_upload_id', 'No UploadId returned from S3.' );
	}

	return $upload_id;
}

/**
 * Complete a multipart upload on S3 (CompleteMultipartUpload).
 *
 * @param string                    $key       Object key
 * @param string                    $upload_id UploadId from apollo_s3_mpu_init()
 * @param array<int,string>         $parts     Map of part_number => ETag
 * @param array<string,string>|null $cfg
 * @return true|\WP_Error
 */
function apollo_s3_mpu_complete(
	string $key,
	string $upload_id,
	array  $parts,
	?array $cfg = null
): true|\WP_Error {
	$cfg = $cfg ?? apollo_s3_config();

	ksort( $parts );
	$xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
	foreach ( $parts as $num => $etag ) {
		$xml .= '<Part><PartNumber>' . (int) $num . '</PartNumber><ETag>'
			. htmlspecialchars( (string) $etag, ENT_XML1, 'UTF-8' )
			. '</ETag></Part>';
	}
	$xml .= '</CompleteMultipartUpload>';

	$body_sha256 = hash( 'sha256', $xml );
	$headers     = apollo_s3_auth_headers(
		'POST', $key, 'application/xml', $body_sha256,
		[ 'uploadId' => $upload_id ], $cfg
	);
	$url = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => $xml,
		'timeout' => 60,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		error_log( "[apollo_s3] MPU complete failed HTTP {$code}: {$msg} | key={$key} uploadId={$upload_id}" );
		return new \WP_Error( 's3_complete_failed', "S3 MPU complete failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

/**
 * Abort a multipart upload on S3.
 */
function apollo_s3_mpu_abort( string $key, string $upload_id, ?array $cfg = null ): true|\WP_Error {
	$cfg         = $cfg ?? apollo_s3_config();
	$body_sha256 = hash( 'sha256', '' );
	$headers     = apollo_s3_auth_headers(
		'DELETE', $key, 'application/xml', $body_sha256,
		[ 'uploadId' => $upload_id ], $cfg
	);
	$url = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'DELETE',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 15,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	return ( $code === 204 || $code === 200 ) ? true : new \WP_Error( 's3_abort_failed', "S3 abort failed (HTTP {$code})" );
}

/**
 * Upload a single object to S3 via PutObject (for small files only — < 5 MB).
 */
function apollo_s3_put_object( string $key, string $body, string $content_type, ?array $cfg = null ): true|\WP_Error {
	$cfg         = $cfg ?? apollo_s3_config();
	$body_sha256 = hash( 'sha256', $body );
	$headers     = apollo_s3_auth_headers( 'PUT', $key, $content_type, $body_sha256, [], $cfg );
	$url         = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'PUT',
		'headers' => $headers,
		'body'    => $body,
		'timeout' => 60,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		$body_resp = wp_remote_retrieve_body( $resp );
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body_resp, $m ) ) {
			$msg = $m[1];
		}
		return new \WP_Error( 's3_put_failed', "S3 PutObject failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. R2 LIST OBJECTS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * List a single page of R2 objects.
 *
 * @return array{objects: list<array{key:string,size:int,content_type:string}>, next_token: string}|\WP_Error
 */
function apollo_r2_list_page( ?array $r2_cfg = null, int $page_size = 100, string $cont_token = '' ): array|\WP_Error {
	$r2_cfg = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );

	$acct    = trim( $r2_cfg['account_id'] ?? '' );
	$key_id  = trim( $r2_cfg['access_key'] ?? '' );
	$secret  = trim( $r2_cfg['secret_key'] ?? '' );
	$bucket  = trim( $r2_cfg['bucket']     ?? '' );
	$region  = 'auto';
	$service = 's3';
	$host    = "{$acct}.r2.cloudflarestorage.com";

	if ( ! $acct || ! $key_id || ! $secret || ! $bucket ) {
		return new \WP_Error( 'r2_not_configured', 'R2 credentials incomplete.' );
	}

	$page_size = max( 1, min( 1000, $page_size ) );

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );
	$uri_path   = '/' . rawurlencode( $bucket );

	$qp = [ 'list-type' => '2', 'max-keys' => (string) $page_size ];
	if ( $cont_token ) {
		$qp['continuation-token'] = $cont_token;
	}
	ksort( $qp );
	$qs_parts = [];
	foreach ( $qp as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$body_sha256       = hash( 'sha256', '' );
	$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$body_sha256}\nx-amz-date:{$amz_date}\n";
	$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

	$canonical   = "GET\n{$uri_path}\n{$query_string}\n{$canonical_headers}\n{$signed_headers}\n{$body_sha256}";
	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );
	$signature = hash_hmac( 'sha256', $str_to_sign,   $k_signing );

	$credential = "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request";
	$auth       = "AWS4-HMAC-SHA256 Credential={$credential},SignedHeaders={$signed_headers},Signature={$signature}";

	$resp = wp_remote_get(
		"https://{$host}{$uri_path}?{$query_string}",
		[
			'headers' => [
				'Authorization'        => $auth,
				'x-amz-content-sha256' => $body_sha256,
				'x-amz-date'           => $amz_date,
			],
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $mm ) ) {
			$msg = $mm[1];
		}
		return new \WP_Error( 'r2_list_failed', "R2 ListObjects failed (HTTP {$code}): {$msg}" );
	}

	$objects = [];
	preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $items );
	foreach ( $items[1] as $item ) {
		preg_match( '/<Key>([^<]+)<\/Key>/', $item, $km );
		preg_match( '/<Size>([^<]+)<\/Size>/', $item, $sm );
		$k = $km[1] ?? '';
		$s = (int) ( $sm[1] ?? 0 );
		if ( $k ) {
			$ext    = strtolower( (string) pathinfo( $k, PATHINFO_EXTENSION ) );
			$mime   = wp_check_filetype( 'file.' . $ext )['type'] ?: 'application/octet-stream';
			$objects[] = [ 'key' => $k, 'size' => $s, 'content_type' => $mime ];
		}
	}

	$next_token = '';
	if ( preg_match( '/<IsTruncated>true<\/IsTruncated>/', $body ) ) {
		preg_match( '/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $body, $nt );
		$next_token = $nt[1] ?? '';
	}

	return [ 'objects' => $objects, 'next_token' => $next_token ];
}

/**
 * List ALL R2 objects (full pagination).
 *
 * @return list<array{key:string,size:int,content_type:string}>|\WP_Error
 */
function apollo_r2_list_objects( ?array $r2_cfg = null, int $max = 0 ): array|\WP_Error {
	$r2_cfg = $r2_cfg ?? ( function_exists( 'svh_r2_config' ) ? svh_r2_config() : [] );

	$acct    = trim( $r2_cfg['account_id'] ?? '' );
	$key_id  = trim( $r2_cfg['access_key'] ?? '' );
	$secret  = trim( $r2_cfg['secret_key'] ?? '' );
	$bucket  = trim( $r2_cfg['bucket']     ?? '' );
	$region  = 'auto';
	$service = 's3';
	$host    = "{$acct}.r2.cloudflarestorage.com";

	if ( ! $acct || ! $key_id || ! $secret || ! $bucket ) {
		return new \WP_Error( 'r2_not_configured', 'R2 credentials incomplete.' );
	}

	$objects           = [];
	$continuation      = '';
	$page_size         = 1000;

	do {
		$now        = time();
		$date_stamp = gmdate( 'Ymd', $now );
		$amz_date   = gmdate( 'Ymd\THis\Z', $now );
		$uri_path   = '/' . rawurlencode( $bucket );

		$qp = [ 'list-type' => '2', 'max-keys' => (string) $page_size ];
		if ( $continuation ) {
			$qp['continuation-token'] = $continuation;
		}
		ksort( $qp );

		$qs_parts = [];
		foreach ( $qp as $k => $v ) {
			$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$query_string = implode( '&', $qs_parts );

		$body_sha256       = hash( 'sha256', '' );
		$canonical_headers = "host:{$host}\n"
			. "x-amz-content-sha256:{$body_sha256}\n"
			. "x-amz-date:{$amz_date}\n";
		$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

		$canonical = "GET\n{$uri_path}\n{$query_string}\n"
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $body_sha256;

		$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
		$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

		$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
		$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );
		$signature = hash_hmac( 'sha256', $str_to_sign,   $k_signing );

		$credential = "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request";
		$auth       = "AWS4-HMAC-SHA256 Credential={$credential},SignedHeaders={$signed_headers},Signature={$signature}";

		$resp = wp_remote_get(
			"https://{$host}{$uri_path}?{$query_string}",
			[
				'headers' => [
					'Authorization'        => $auth,
					'x-amz-content-sha256' => $body_sha256,
					'x-amz-date'           => $amz_date,
				],
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );

		if ( $code !== 200 ) {
			$msg = '';
			if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $mm ) ) {
				$msg = $mm[1];
			}
			return new \WP_Error( 'r2_list_failed', "R2 ListObjects failed (HTTP {$code}): {$msg}" );
		}

		preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $items );
		foreach ( $items[1] as $item ) {
			preg_match( '/<Key>([^<]+)<\/Key>/', $item, $km );
			preg_match( '/<Size>([^<]+)<\/Size>/', $item, $sm );
			$k = $km[1] ?? '';
			$s = (int) ( $sm[1] ?? 0 );
			if ( $k ) {
				$ext  = strtolower( (string) pathinfo( $k, PATHINFO_EXTENSION ) );
				$mime = wp_check_filetype( 'file.' . $ext )['type'] ?: 'application/octet-stream';
				$objects[] = [ 'key' => $k, 'size' => $s, 'content_type' => $mime ];
			}
			if ( $max > 0 && count( $objects ) >= $max ) {
				break 2;
			}
		}

		$continuation = '';
		if ( preg_match( '/<IsTruncated>true<\/IsTruncated>/', $body ) ) {
			preg_match( '/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $body, $nt );
			$continuation = $nt[1] ?? '';
		}

	} while ( $continuation );

	return $objects;
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. S3 LIST PAGE + R2 WRITE OPERATIONS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * List a single page of S3 objects.
 *
 * @return array{objects: list<array{key:string,size:int,content_type:string}>, next_token: string}|\WP_Error
 */
function apollo_s3_list_page( ?array $cfg = null, int $page_size = 100, string $cont_token = '' ): array|\WP_Error {
	$cfg     = $cfg ?? apollo_s3_config();
	$key_id  = trim( $cfg['access_key'] ?? '' );
	$secret  = trim( $cfg['secret_key'] ?? '' );
	$bucket  = trim( $cfg['bucket']     ?? '' );
	$region  = $cfg['region'] ?: 'us-east-1';
	$service = 's3';
	$host    = "{$bucket}.s3.{$region}.amazonaws.com";

	if ( ! $key_id || ! $secret || ! $bucket ) {
		return new \WP_Error( 's3_not_configured', 'S3 credentials incomplete.' );
	}

	$page_size = max( 1, min( 1000, $page_size ) );

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );
	$uri_path   = '/';

	$qp = [ 'list-type' => '2', 'max-keys' => (string) $page_size ];
	if ( $cont_token ) {
		$qp['continuation-token'] = $cont_token;
	}
	ksort( $qp );
	$qs_parts = [];
	foreach ( $qp as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$body_sha256       = hash( 'sha256', '' );
	$canonical_headers = "host:{$host}\nx-amz-content-sha256:{$body_sha256}\nx-amz-date:{$amz_date}\n";
	$signed_headers    = 'host;x-amz-content-sha256;x-amz-date';

	$canonical   = "GET\n{$uri_path}\n{$query_string}\n{$canonical_headers}\n{$signed_headers}\n{$body_sha256}";
	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );
	$signature = hash_hmac( 'sha256', $str_to_sign,   $k_signing );

	$credential = "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request";
	$auth       = "AWS4-HMAC-SHA256 Credential={$credential},SignedHeaders={$signed_headers},Signature={$signature}";

	$resp = wp_remote_get(
		"https://{$host}{$uri_path}?{$query_string}",
		[
			'headers' => [
				'Authorization'        => $auth,
				'x-amz-content-sha256' => $body_sha256,
				'x-amz-date'           => $amz_date,
			],
			'timeout' => 30,
		]
	);

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $mm ) ) {
			$msg = $mm[1];
		}
		return new \WP_Error( 's3_list_failed', "S3 ListObjects failed (HTTP {$code}): {$msg}" );
	}

	$objects = [];
	preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $items );
	foreach ( $items[1] as $item ) {
		preg_match( '/<Key>([^<]+)<\/Key>/', $item, $km );
		preg_match( '/<Size>([^<]+)<\/Size>/', $item, $sm );
		$k = $km[1] ?? '';
		$s = (int) ( $sm[1] ?? 0 );
		if ( $k ) {
			$ext       = strtolower( (string) pathinfo( $k, PATHINFO_EXTENSION ) );
			$mime      = wp_check_filetype( 'file.' . $ext )['type'] ?: 'application/octet-stream';
			$objects[] = [ 'key' => $k, 'size' => $s, 'content_type' => $mime ];
		}
	}

	$next_token = '';
	if ( preg_match( '/<IsTruncated>true<\/IsTruncated>/', $body ) ) {
		preg_match( '/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $body, $nt );
		$next_token = $nt[1] ?? '';
	}

	return [ 'objects' => $objects, 'next_token' => $next_token ];
}

/**
 * Build SigV4 Authorization headers for server-side R2 write requests (path-style).
 */
function apollo_r2_auth_headers(
	string $method,
	string $key,
	string $content_type,
	string $body_sha256,
	array  $extra_query = [],
	?array $r2_cfg = null
): array {
	$r2_cfg  = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );
	$key_id  = trim( $r2_cfg['access_key'] ?? '' );
	$secret  = trim( $r2_cfg['secret_key'] ?? '' );
	$bucket  = trim( $r2_cfg['bucket']     ?? '' );
	$acct    = trim( $r2_cfg['account_id'] ?? '' );
	$region  = 'auto';
	$service = 's3';
	$host    = "{$acct}.r2.cloudflarestorage.com";

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	$key_clean = ltrim( $key, '/' );
	$uri_path  = '/' . rawurlencode( $bucket );
	if ( $key_clean !== '' ) {
		$uri_path .= '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key_clean ) ) );
	}

	ksort( $extra_query );
	$qs_parts = [];
	foreach ( $extra_query as $k => $v ) {
		$qs_parts[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
	}
	$query_string = implode( '&', $qs_parts );

	$canonical_headers = "content-type:{$content_type}\n"
		. "host:{$host}\n"
		. "x-amz-content-sha256:{$body_sha256}\n"
		. "x-amz-date:{$amz_date}\n";
	$signed_headers = 'content-type;host;x-amz-content-sha256;x-amz-date';

	$canonical = $method . "\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. $canonical_headers . "\n"
		. $signed_headers . "\n"
		. $body_sha256;

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature  = hash_hmac( 'sha256', $str_to_sign, $k_signing );
	$credential = "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request";
	$auth       = "AWS4-HMAC-SHA256 Credential={$credential},SignedHeaders={$signed_headers},Signature={$signature}";

	return [
		'Authorization'        => $auth,
		'Content-Type'         => $content_type,
		'x-amz-content-sha256' => $body_sha256,
		'x-amz-date'           => $amz_date,
		'url'                  => "https://{$host}{$uri_path}" . ( $query_string ? "?{$query_string}" : '' ),
	];
}

/**
 * Upload a single object to R2 via PutObject (for small files — < 5 MB).
 */
function apollo_r2_put_object( string $key, string $body, string $content_type, ?array $r2_cfg = null ): true|\WP_Error {
	$r2_cfg = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );

	if ( empty( $r2_cfg['account_id'] ) || empty( $r2_cfg['access_key'] )
		|| empty( $r2_cfg['secret_key'] ) || empty( $r2_cfg['bucket'] ) ) {
		return new \WP_Error( 'r2_not_configured', 'R2 credentials not fully configured.' );
	}

	$body_sha256 = hash( 'sha256', $body );
	$headers     = apollo_r2_auth_headers( 'PUT', $key, $content_type, $body_sha256, [], $r2_cfg );
	$url         = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'PUT',
		'headers' => $headers,
		'body'    => $body,
		'timeout' => 60,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		$body_resp = wp_remote_retrieve_body( $resp );
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body_resp, $m ) ) {
			$msg = $m[1];
		}
		return new \WP_Error( 'r2_put_failed', "R2 PutObject failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

/**
 * Initiate a multipart upload on R2 (CreateMultipartUpload).
 */
function apollo_r2_mpu_init( string $key, string $content_type, ?array $r2_cfg = null ): string|\WP_Error {
	$r2_cfg = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );

	if ( empty( $r2_cfg['account_id'] ) || empty( $r2_cfg['access_key'] )
		|| empty( $r2_cfg['secret_key'] ) || empty( $r2_cfg['bucket'] ) ) {
		return new \WP_Error( 'r2_not_configured', 'R2 credentials not fully configured.' );
	}

	$body_sha256 = hash( 'sha256', '' );
	$headers     = apollo_r2_auth_headers( 'POST', $key, $content_type, $body_sha256, [ 'uploads' => '' ], $r2_cfg );
	$url         = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 30,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		error_log( "[apollo_r2] MPU init failed HTTP {$code}: {$msg} | key={$key}" );
		return new \WP_Error( 'r2_init_failed', "R2 MPU init failed (HTTP {$code}): {$msg}" );
	}

	preg_match( '/<UploadId>([^<]+)<\/UploadId>/', $body, $m );
	$upload_id = $m[1] ?? '';

	if ( ! $upload_id ) {
		error_log( "[apollo_r2] MPU init: no UploadId in response: " . substr( $body, 0, 500 ) );
		return new \WP_Error( 'r2_no_upload_id', 'No UploadId returned from R2.' );
	}

	return $upload_id;
}

/**
 * Complete a multipart upload on R2 (CompleteMultipartUpload).
 */
function apollo_r2_mpu_complete(
	string $key,
	string $upload_id,
	array  $parts,
	?array $r2_cfg = null
): true|\WP_Error {
	$r2_cfg = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );

	ksort( $parts );
	$xml = '<CompleteMultipartUpload xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
	foreach ( $parts as $num => $etag ) {
		$xml .= '<Part><PartNumber>' . (int) $num . '</PartNumber><ETag>'
			. htmlspecialchars( (string) $etag, ENT_XML1, 'UTF-8' )
			. '</ETag></Part>';
	}
	$xml .= '</CompleteMultipartUpload>';

	$body_sha256 = hash( 'sha256', $xml );
	$headers     = apollo_r2_auth_headers(
		'POST', $key, 'application/xml', $body_sha256,
		[ 'uploadId' => $upload_id ], $r2_cfg
	);
	$url = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'POST',
		'headers' => $headers,
		'body'    => $xml,
		'timeout' => 60,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = wp_remote_retrieve_body( $resp );

	if ( $code !== 200 ) {
		$msg = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		error_log( "[apollo_r2] MPU complete failed HTTP {$code}: {$msg} | key={$key} uploadId={$upload_id}" );
		return new \WP_Error( 'r2_complete_failed', "R2 MPU complete failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

/**
 * Abort a multipart upload on R2 (AbortMultipartUpload).
 */
function apollo_r2_mpu_abort( string $key, string $upload_id, ?array $r2_cfg = null ): true|\WP_Error {
	$r2_cfg      = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );
	$body_sha256 = hash( 'sha256', '' );
	$headers     = apollo_r2_auth_headers(
		'DELETE', $key, 'application/xml', $body_sha256,
		[ 'uploadId' => $upload_id ], $r2_cfg
	);
	$url = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'DELETE',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 15,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	return ( $code === 204 || $code === 200 )
		? true
		: new \WP_Error( 'r2_abort_failed', "R2 abort failed (HTTP {$code})" );
}

/**
 * Generate a presigned PUT URL for one multipart upload part on R2.
 */
function apollo_r2_presign_part_url(
	string $object_key,
	string $upload_id,
	int    $part_num,
	int    $expires = 3600,
	?array $r2_cfg = null
): string {
	$r2_cfg  = $r2_cfg ?? ( function_exists( 'apollo_cf_config' ) ? apollo_cf_config() : [] );
	$key_id  = trim( $r2_cfg['access_key'] ?? '' );
	$secret  = trim( $r2_cfg['secret_key'] ?? '' );
	$bucket  = trim( $r2_cfg['bucket']     ?? '' );
	$acct    = trim( $r2_cfg['account_id'] ?? '' );
	$region  = 'auto';
	$service = 's3';
	$host    = "{$acct}.r2.cloudflarestorage.com";

	$now        = time();
	$date_stamp = gmdate( 'Ymd', $now );
	$amz_date   = gmdate( 'Ymd\THis\Z', $now );

	$key_clean = ltrim( $object_key, '/' );
	$uri_path  = '/' . rawurlencode( $bucket ) . '/'
		. implode( '/', array_map( 'rawurlencode', explode( '/', $key_clean ) ) );

	$params = [
		'X-Amz-Algorithm'    => 'AWS4-HMAC-SHA256',
		'X-Amz-Credential'   => "{$key_id}/{$date_stamp}/{$region}/{$service}/aws4_request",
		'X-Amz-Date'         => $amz_date,
		'X-Amz-Expires'      => (string) $expires,
		'X-Amz-SignedHeaders' => 'host',
		'partNumber'          => (string) $part_num,
		'uploadId'            => $upload_id,
	];
	ksort( $params );

	$qs_parts = [];
	foreach ( $params as $k => $v ) {
		$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
	}
	$query_string = implode( '&', $qs_parts );

	$canonical = "PUT\n"
		. $uri_path . "\n"
		. $query_string . "\n"
		. "host:{$host}\n\n"
		. "host\n"
		. 'UNSIGNED-PAYLOAD';

	$scope       = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$str_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );

	$k_date    = hash_hmac( 'sha256', $date_stamp,    'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', $region,        $k_date,          true );
	$k_service = hash_hmac( 'sha256', $service,       $k_region,        true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service,       true );

	$signature = hash_hmac( 'sha256', $str_to_sign, $k_signing );

	return "https://{$host}{$uri_path}?{$query_string}&X-Amz-Signature=" . rawurlencode( $signature );
}

// ─────────────────────────────────────────────────────────────────────────────
// 9. FILENAME ANONYMISATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Generate an anonymised S3/R2 object key.
 *
 * Format: {prefix}/Y/m/media-{T}-{20digits}.{ext}
 *   T = P (photos/images) | V (videos) | A (audio) | F (files/other)
 *
 * @param string $ext    File extension without dot, e.g. "mp4".
 * @param string $type   One of: 'video' | 'audio' | 'image' | 'file'.
 * @param string $prefix Folder prefix, e.g. "videos" (no trailing slash).
 * @return string        Full object key including prefix and date path.
 */
function apollo_s3_anon_key( string $ext, string $type, string $prefix ): string {
	$type_map = [
		'video' => 'V',
		'audio' => 'A',
		'image' => 'P',
		'file'  => 'F',
	];
	$t = $type_map[ $type ] ?? 'F';

	$part1  = str_pad( (string) random_int( 0, 9999999999 ), 10, '0', STR_PAD_LEFT );
	$part2  = str_pad( (string) random_int( 0, 9999999999 ), 10, '0', STR_PAD_LEFT );
	$digits = $part1 . $part2;

	$ext = $ext ? '.' . ltrim( $ext, '.' ) : '';
	return rtrim( $prefix, '/' ) . '/' . gmdate( 'Y/m' ) . '/media-' . $t . '-' . $digits . $ext;
}

// ─────────────────────────────────────────────────────────────────────────────
// 10. COPY / DELETE  (used by the S3 Rename Tool)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Server-side copy of one S3 object to a new key (within the same bucket).
 */
function apollo_s3_copy_object( string $source_key, string $dest_key, ?array $cfg = null ): true|\WP_Error {
	$cfg         = $cfg ?? apollo_s3_config();
	$bucket      = $cfg['bucket'];
	$copy_source = '/' . rawurlencode( $bucket ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $source_key ) ) );

	$body_sha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	$extra = [ 'x-amz-copy-source' => $copy_source, 'x-amz-metadata-directive' => 'COPY' ];
	$headers = apollo_s3_auth_headers( 'PUT', $dest_key, '', $body_sha256, $extra, $cfg );

	$url = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'PUT',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 60,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code !== 200 ) {
		$body = wp_remote_retrieve_body( $resp );
		$msg  = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		return new \WP_Error( 's3_copy_failed', "S3 CopyObject failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

/**
 * Delete a single S3 object.
 */
function apollo_s3_delete_object( string $key, ?array $cfg = null ): true|\WP_Error {
	$cfg         = $cfg ?? apollo_s3_config();
	$body_sha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
	$headers     = apollo_s3_auth_headers( 'DELETE', $key, '', $body_sha256, [], $cfg );
	$url         = $headers['url'];
	unset( $headers['url'] );

	$resp = wp_remote_request( $url, [
		'method'  => 'DELETE',
		'headers' => $headers,
		'body'    => '',
		'timeout' => 30,
	] );

	if ( is_wp_error( $resp ) ) {
		return $resp;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code !== 204 && $code !== 200 ) {
		$body = wp_remote_retrieve_body( $resp );
		$msg  = '';
		if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $m ) ) {
			$msg = $m[1];
		}
		return new \WP_Error( 's3_delete_failed', "S3 DeleteObject failed (HTTP {$code}): {$msg}" );
	}

	return true;
}

/**
 * List all objects in the S3 bucket (paginated via ListObjectsV2).
 */
function apollo_s3_list_all_objects( ?array $cfg = null, string $prefix = '', int $max = 0 ): array|\WP_Error {
	$cfg      = $cfg ?? apollo_s3_config();
	$objects  = [];
	$token    = '';
	$max_keys = ( $max > 0 && $max <= 1000 ) ? $max : 1000;

	do {
		$params = [ 'list-type' => '2', 'max-keys' => (string) $max_keys ];
		if ( $prefix !== '' ) {
			$params['prefix'] = $prefix;
		}
		if ( $token !== '' ) {
			$params['continuation-token'] = $token;
		}

		$body_sha256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
		$headers     = apollo_s3_auth_headers( 'GET', '', '', $body_sha256, [], $cfg );
		$url         = $headers['url'];

		// Append query params manually
		ksort( $params );
		$qs_parts = [];
		foreach ( $params as $k => $v ) {
			$qs_parts[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$url .= ( strpos( $url, '?' ) !== false ? '&' : '?' ) . implode( '&', $qs_parts );

		unset( $headers['url'] );

		$resp = wp_remote_get( $url, [
			'headers' => $headers,
			'timeout' => 30,
		] );

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = wp_remote_retrieve_response_code( $resp );
		$body = wp_remote_retrieve_body( $resp );

		if ( $code !== 200 ) {
			$msg = '';
			if ( preg_match( '/<Message>([^<]+)<\/Message>/', $body, $mm ) ) {
				$msg = $mm[1];
			}
			return new \WP_Error( 's3_list_failed', "S3 ListObjects failed (HTTP {$code}): {$msg}" );
		}

		preg_match_all( '/<Contents>(.*?)<\/Contents>/s', $body, $items );
		foreach ( $items[1] as $item ) {
			preg_match( '/<Key>([^<]+)<\/Key>/', $item, $km );
			preg_match( '/<Size>([^<]+)<\/Size>/', $item, $sm );
			$k = $km[1] ?? '';
			$s = (int) ( $sm[1] ?? 0 );
			if ( $k ) {
				$ext  = strtolower( (string) pathinfo( $k, PATHINFO_EXTENSION ) );
				$mime = wp_check_filetype( 'file.' . $ext )['type'] ?: 'application/octet-stream';
				$objects[] = [ 'key' => $k, 'size' => $s, 'content_type' => $mime ];
			}
			if ( $max > 0 && count( $objects ) >= $max ) {
				break 2;
			}
		}

		$token = '';
		if ( preg_match( '/<IsTruncated>true<\/IsTruncated>/', $body ) ) {
			preg_match( '/<NextContinuationToken>([^<]+)<\/NextContinuationToken>/', $body, $nt );
			$token = $nt[1] ?? '';
		}

	} while ( $token );

	return $objects;
}
