<?php
/**
 * Tests for the options encryptor.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Security;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * Round trips, tampering, wrong keys and where the key comes from.
 */
final class EncryptorTest extends TestCase {

	public function test_round_trip(): void {
		$encryptor = Encryptor::from_secret( 'a site secret' );

		$payload = $encryptor->encrypt( 'sec_value' );

		self::assertStringStartsWith( 'v1:', $payload );
		self::assertStringNotContainsString( 'sec_value', $payload );
		self::assertSame( 'sec_value', $encryptor->decrypt( $payload ) );
	}

	public function test_empty_string_round_trips(): void {
		$encryptor = Encryptor::from_secret( 'a site secret' );

		self::assertSame( '', $encryptor->decrypt( $encryptor->encrypt( '' ) ) );
	}

	public function test_every_encryption_uses_a_new_nonce(): void {
		$encryptor = Encryptor::from_secret( 'a site secret' );

		self::assertNotSame( $encryptor->encrypt( 'same' ), $encryptor->encrypt( 'same' ) );
	}

	public function test_another_key_cannot_decrypt(): void {
		$payload = Encryptor::from_secret( 'old salts' )->encrypt( 'secret' );

		self::assertNull( Encryptor::from_secret( 'new salts' )->decrypt( $payload ) );
	}

	public function test_altered_ciphertext_is_rejected(): void {
		$encryptor = Encryptor::from_secret( 'a site secret' );
		$raw       = base64_decode( substr( $encryptor->encrypt( 'secret' ), 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		self::assertIsString( $raw );

		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );

		self::assertNull( $encryptor->decrypt( 'v1:' . base64_encode( $raw ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Values that aren't in the v1 format.
	 *
	 * @return array<string, array{string}>
	 */
	public static function malformed_payloads(): array {
		return array(
			'plaintext'      => array( 'sec_value' ),
			'empty'          => array( '' ),
			'unknown format' => array( 'v2:AAAA' ),
			'not base64'     => array( 'v1:@@@@' ),
			'too short'      => array( 'v1:' . base64_encode( 'short' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/**
	 * Malformed values read as missing.
	 *
	 * @param string $payload Stored value.
	 */
	#[DataProvider( 'malformed_payloads' )]
	public function test_malformed_payloads_are_rejected( string $payload ): void {
		self::assertNull( Encryptor::from_secret( 'a site secret' )->decrypt( $payload ) );
	}

	public function test_key_must_be_32_bytes(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Encryptor( 'too short' );
	}

	public function test_secret_must_not_be_empty(): void {
		$this->expectException( \InvalidArgumentException::class );

		Encryptor::from_secret( '' );
	}

	public function test_debug_output_hides_the_key(): void {
		$dump = print_r( new Encryptor( str_repeat( 'k', 32 ) ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringNotContainsString( str_repeat( 'k', 32 ), $dump );
	}

	public function test_site_key_comes_from_the_salts_without_the_constant(): void {
		Functions\expect( 'wp_salt' )->once()->with( 'auth' )->andReturn( 'the salts' );

		$payload = Encryptor::for_site()->encrypt( 'secret' );

		self::assertSame( 'secret', Encryptor::from_secret( 'the salts' )->decrypt( $payload ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_site_key_comes_from_the_constant_when_defined(): void {
		define( 'NYXIFY_ENCRYPTION_KEY', 'a key from wp-config' );
		Functions\expect( 'wp_salt' )->never();

		$payload = Encryptor::for_site()->encrypt( 'secret' );

		self::assertSame( 'secret', Encryptor::from_secret( 'a key from wp-config' )->decrypt( $payload ) );
	}
}
