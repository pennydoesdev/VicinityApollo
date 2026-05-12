<?php
/**
 * Unit tests for Security\Input.
 */
namespace Apollo\Serve\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Apollo\Serve\Security\Input;

require_once __DIR__ . '/../../security/class-input.php';

final class InputTest extends TestCase {

	public function test_json_returns_null_on_oversize(): void {
		$big = str_repeat( 'a', 600 * 1024 );
		$this->assertNull( Input::json( '"' . $big . '"' ) );
	}

	public function test_json_returns_null_on_malformed(): void {
		$this->assertNull( Input::json( '{broken' ) );
	}

	public function test_json_decodes_valid(): void {
		$this->assertSame( [ 'a' => 1 ], Input::json( '{"a":1}' ) );
	}

	public function test_json_rejects_deep_nesting(): void {
		$payload = str_repeat( '[', 50 ) . str_repeat( ']', 50 );
		$this->assertNull( Input::json( $payload, 10 ) );
	}
}
