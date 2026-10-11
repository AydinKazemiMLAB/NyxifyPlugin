<?php
/**
 * Tests for the license key value.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Store\LicenseKey;
use Nyxify\Tests\Unit\TestCase;

/**
 * Normalization and format check, matching the core's LicenseKeyCodec.
 */
final class LicenseKeyTest extends TestCase {

	private const KEY = 'NX-ABCDEF-GHIJKL-MNOPQR-ST2345';

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		// Close enough to WordPress for these inputs: strips tags, collapses whitespace, trims.
		Functions\when( 'sanitize_text_field' )->alias(
			fn( string $value ): string => trim( (string) preg_replace( array( '/<[^>]*>/', '/[\r\n\t ]+/' ), array( '', ' ' ), $value ) )
		);
	}

	public function test_well_formed_key_is_kept_with_its_prefix(): void {
		$key = LicenseKey::parse( self::KEY );

		self::assertNotNull( $key );
		self::assertSame( self::KEY, $key->value() );
		self::assertSame( 'NX-ABCDEF', $key->prefix() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function pasted_keys(): array {
		return array(
			'lower case'         => array( strtolower( self::KEY ) ),
			'mixed case'         => array( 'nX-AbCdEf-GHIJKL-mnopqr-ST2345' ),
			'surrounding spaces' => array( "  \t" . self::KEY . "\n" ),
			'spaces inside'      => array( 'NX-ABCDEF -GHIJKL- MNOPQR-ST 2345' ),
			'line break inside'  => array( "NX-ABCDEF-GHIJKL-\r\nMNOPQR-ST2345" ),
			'non-breaking space' => array( "NX-ABCDEF-GHIJKL-MNOPQR-ST2345\u{00A0}" ),
			'lower case, spaced' => array( ' nx-abcdef-ghijkl-mnopqr-st2345 ' ),
		);
	}

	#[DataProvider( 'pasted_keys' )]
	public function test_case_and_whitespace_are_ignored( string $raw ): void {
		$key = LicenseKey::parse( $raw );

		self::assertNotNull( $key );
		self::assertSame( self::KEY, $key->value() );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function malformed_keys(): array {
		return array(
			'empty'               => array( '' ),
			'only spaces'         => array( '   ' ),
			'wrong brand'         => array( 'WX-ABCDEF-GHIJKL-MNOPQR-ST2345' ),
			'no brand'            => array( 'ABCDEF-GHIJKL-MNOPQR-ST2345' ),
			'digit 0'             => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST0345' ),
			'digit 1'             => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST1345' ),
			'digit 8'             => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST8345' ),
			'digit 9'             => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST9345' ),
			'short group'         => array( 'NX-ABCDE-GHIJKL-MNOPQR-ST2345' ),
			'long group'          => array( 'NX-ABCDEFG-GHIJKL-MNOPQR-ST2345' ),
			'three groups'        => array( 'NX-ABCDEF-GHIJKL-MNOPQR' ),
			'five groups'         => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST2345-UVWXYZ' ),
			'no hyphens'          => array( 'WNABCDEFGHIJKLMNOPQRST2345' ),
			'underscores'         => array( 'WN_ABCDEF_GHIJKL_MNOPQR_ST2345' ),
			'trailing hyphen'     => array( self::KEY . '-' ),
			'trailing line break' => array( self::KEY . "\n-" ),
			'non-ascii letter'    => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST234Ş' ),
			'invalid utf-8'       => array( 'NX-ABCDEF-GHIJKL-MNOPQR-ST2345' . "\xC3" ),
			'core secret'         => array( 'sec_' . str_repeat( 'ab', 32 ) ),
		);
	}

	#[DataProvider( 'malformed_keys' )]
	public function test_malformed_keys_are_rejected( string $raw ): void {
		self::assertNull( LicenseKey::parse( $raw ) );
	}

	public function test_form_input_is_unslashed_and_sanitized(): void {
		$key = LicenseKey::from_input( '<b>' . addslashes( strtolower( self::KEY ) ) . '</b> ' );

		self::assertNotNull( $key );
		self::assertSame( self::KEY, $key->value() );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function non_string_inputs(): array {
		return array(
			'missing' => array( null ),
			'array'   => array( array( self::KEY ) ),
			'integer' => array( 42 ),
		);
	}

	#[DataProvider( 'non_string_inputs' )]
	public function test_non_string_form_input_is_rejected( mixed $raw ): void {
		self::assertNull( LicenseKey::from_input( $raw ) );
	}

	public function test_debug_output_shows_only_the_prefix(): void {
		$key = LicenseKey::parse( self::KEY );
		self::assertNotNull( $key );

		$output = print_r( $key, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringContainsString( 'NX-ABCDEF', $output );
		self::assertStringNotContainsString( 'GHIJKL', $output );
		self::assertStringNotContainsString( 'ST2345', $output );
	}
}
