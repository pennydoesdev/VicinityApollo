<?php
/**
 * Unit tests for Security\RateLimiter.
 */
namespace Apollo\Serve\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;
use Apollo\Serve\Security\RateLimiter;

require_once __DIR__ . '/../../security/class-rate-limiter.php';

final class RateLimiterTest extends TestCase {

	protected function setUp(): void  { WP_Mock::setUp(); }
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_allows_under_quota(): void {
		WP_Mock::userFunction( 'wp_salt', [ 'return' => 'salt' ] );
		WP_Mock::userFunction( 'get_transient', [ 'return' => 0 ] );
		WP_Mock::userFunction( 'set_transient', [ 'return' => true ] );
		$this->assertTrue( RateLimiter::hit( 'test', 5, 60, 'sub' ) );
	}

	public function test_rejects_over_quota(): void {
		WP_Mock::userFunction( 'wp_salt', [ 'return' => 'salt' ] );
		WP_Mock::userFunction( 'get_transient', [ 'return' => 5 ] );
		$this->assertFalse( RateLimiter::hit( 'test', 5, 60, 'sub' ) );
	}

	public function test_zero_max_always_allows(): void {
		$this->assertTrue( RateLimiter::hit( 'test', 0, 60 ) );
	}
}
