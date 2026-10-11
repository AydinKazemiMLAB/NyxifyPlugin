<?php
/**
 * Tests for the HMAC signer.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Shared\Security\HmacSigner;
use Nyxify\Tests\Unit\TestCase;

/**
 * The reference vectors of nyxify-security §1 and the checks of an incoming request.
 */
final class HmacSignerTest extends TestCase {

	private const SECRET    = 'sec_test_secret';
	private const TIMESTAMP = '1789234567';
	private const NONCE     = '6b1e9d3f0a4c4e2b8d7f1a2b3c4d5e6f';
	private const SHOP_ID   = 'shop_8f2a0c1d3e4b5a69';

	/**
	 * The core's reference vectors (`tests/core/security/test_hmac_signature.py`).
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function reference_vectors(): array {
		return array(
			'json body'  => array( '{"product_id":42,"stock":7}', 'sha256=1b386d1e44e9b434b0ab7c68005c7ded45a1f70bcae6df8c07b438e187a068c3' ),
			'empty body' => array( '', 'sha256=d20e439b56a1d067551f08d7c5ecac102962ab5e2eb0931f33a93ee0448e110a' ),
		);
	}

	/**
	 * The signature matches the core's byte for byte.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Expected signature.
	 */
	#[DataProvider( 'reference_vectors' )]
	public function test_reference_vectors( string $body, string $signature ): void {
		self::assertSame( $signature, ( new HmacSigner( self::SECRET ) )->sign( self::TIMESTAMP, self::NONCE, $body ) );
	}

	/**
	 * Headers built with a fixed timestamp and nonce carry the reference signature.
	 *
	 * @param string $body      Raw body.
	 * @param string $signature Expected signature.
	 */
	#[DataProvider( 'reference_vectors' )]
	public function test_build_headers_with_reference_values( string $body, string $signature ): void {
		$headers = ( new HmacSigner( self::SECRET ) )->build_headers( self::SHOP_ID, $body, (int) self::TIMESTAMP, self::NONCE );

		self::assertSame(
			array(
				'X-Nyxify-Shop-Id'   => self::SHOP_ID,
				'X-Nyxify-Timestamp' => self::TIMESTAMP,
				'X-Nyxify-Nonce'     => self::NONCE,
				'X-Nyxify-Signature' => $signature,
			),
			$headers
		);
	}

	public function test_message_format(): void {
		self::assertSame( '1789234567.abc.', HmacSigner::build_message( '1789234567', 'abc', '' ) );
		self::assertSame( '1789234567.abc.{"a":1}', HmacSigner::build_message( '1789234567', 'abc', '{"a":1}' ) );
	}

	public function test_differently_serialized_json_signs_differently(): void {
		$signer = new HmacSigner( self::SECRET );

		self::assertNotSame(
			$signer->sign( self::TIMESTAMP, self::NONCE, '{"a":1}' ),
			$signer->sign( self::TIMESTAMP, self::NONCE, '{"a": 1}' )
		);
	}

	public function test_default_headers_use_now_and_a_random_nonce(): void {
		$signer = new HmacSigner( self::SECRET );
		$before = time();

		$first  = $signer->build_headers( self::SHOP_ID, '' );
		$second = $signer->build_headers( self::SHOP_ID, '' );

		self::assertGreaterThanOrEqual( $before, (int) $first['X-Nyxify-Timestamp'] );
		self::assertLessThanOrEqual( time(), (int) $first['X-Nyxify-Timestamp'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/D', $first['X-Nyxify-Nonce'] );
		self::assertNotSame( $first['X-Nyxify-Nonce'], $second['X-Nyxify-Nonce'] );
		self::assertSame(
			$signer->sign( $first['X-Nyxify-Timestamp'], $first['X-Nyxify-Nonce'], '' ),
			$first['X-Nyxify-Signature']
		);
	}

	public function test_verify_accepts_a_valid_request_with_any_header_case(): void {
		$signer  = new HmacSigner( self::SECRET );
		$headers = array_change_key_case( $signer->build_headers( self::SHOP_ID, '{"x":1}', (int) self::TIMESTAMP ), CASE_LOWER );

		self::assertTrue( $signer->verify( $headers, '{"x":1}', self::SHOP_ID, (int) self::TIMESTAMP ) );
	}

	/**
	 * Timestamps at and just past the tolerance.
	 *
	 * @return array<string, array{int, bool}>
	 */
	public static function clock_offsets(): array {
		return array(
			'300 s old'       => array( 300, true ),
			'301 s old'       => array( 301, false ),
			'300 s in future' => array( -300, true ),
			'301 s in future' => array( -301, false ),
		);
	}

	/**
	 * ±300 seconds are accepted, one more second isn't.
	 *
	 * @param int  $offset   Seconds between the request's timestamp and now.
	 * @param bool $accepted Expected result.
	 */
	#[DataProvider( 'clock_offsets' )]
	public function test_verify_timestamp_tolerance( int $offset, bool $accepted ): void {
		$signer  = new HmacSigner( self::SECRET );
		$headers = $signer->build_headers( self::SHOP_ID, '', (int) self::TIMESTAMP );

		self::assertSame( $accepted, $signer->verify( $headers, '', self::SHOP_ID, (int) self::TIMESTAMP + $offset ) );
	}

	public function test_verify_rejects_an_altered_body(): void {
		$signer  = new HmacSigner( self::SECRET );
		$headers = $signer->build_headers( self::SHOP_ID, '{"stock":7}', (int) self::TIMESTAMP );

		self::assertFalse( $signer->verify( $headers, '{"stock":8}', self::SHOP_ID, (int) self::TIMESTAMP ) );
	}

	public function test_verify_rejects_another_secret(): void {
		$headers = ( new HmacSigner( 'sec_other' ) )->build_headers( self::SHOP_ID, '', (int) self::TIMESTAMP );

		self::assertFalse( ( new HmacSigner( self::SECRET ) )->verify( $headers, '', self::SHOP_ID, (int) self::TIMESTAMP ) );
	}

	public function test_verify_rejects_another_shop(): void {
		$signer  = new HmacSigner( self::SECRET );
		$headers = $signer->build_headers( 'shop_0000000000000000', '', (int) self::TIMESTAMP );

		self::assertFalse( $signer->verify( $headers, '', self::SHOP_ID, (int) self::TIMESTAMP ) );
	}

	/**
	 * Header values the core's format checks refuse.
	 *
	 * @return array<string, array{string, string|null}>
	 */
	public static function malformed_headers(): array {
		return array(
			'missing signature'     => array( 'X-Nyxify-Signature', null ),
			'uppercase signature'   => array( 'X-Nyxify-Signature', 'sha256=' . str_repeat( 'A', 64 ) ),
			'signature without tag' => array( 'X-Nyxify-Signature', str_repeat( 'a', 64 ) ),
			'short nonce'           => array( 'X-Nyxify-Nonce', 'abc' ),
			'nonce with a dot'      => array( 'X-Nyxify-Nonce', str_repeat( 'a', 16 ) . '.' ),
			'negative timestamp'    => array( 'X-Nyxify-Timestamp', '-1' ),
			'integer shop id'       => array( 'X-Nyxify-Shop-Id', '1' ),
		);
	}

	/**
	 * Malformed headers are refused before the signature is computed.
	 *
	 * @param string      $header Header to change.
	 * @param string|null $value  New value, or null to remove it.
	 */
	#[DataProvider( 'malformed_headers' )]
	public function test_verify_rejects_malformed_headers( string $header, ?string $value ): void {
		$signer  = new HmacSigner( self::SECRET );
		$headers = $signer->build_headers( self::SHOP_ID, '', (int) self::TIMESTAMP, self::NONCE );

		if ( null === $value ) {
			unset( $headers[ $header ] );
		} else {
			$headers[ $header ] = $value;
		}

		self::assertFalse( $signer->verify( $headers, '', self::SHOP_ID, (int) self::TIMESTAMP ) );
	}

	public function test_secret_must_not_be_empty(): void {
		$this->expectException( \InvalidArgumentException::class );

		new HmacSigner( '' );
	}

	public function test_debug_output_hides_the_secret(): void {
		$dump = print_r( new HmacSigner( self::SECRET ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringNotContainsString( self::SECRET, $dump );
	}
}
