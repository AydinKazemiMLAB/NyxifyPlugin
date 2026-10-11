<?php
/**
 * Tests for the error messages.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Shared\Http;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\ErrorMessages;
use Nyxify\Tests\Unit\TestCase;

/**
 * Every code has a message, and the code list follows the core's OpenAPI spec.
 */
final class ErrorMessagesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Every `error_code` enum value of the core's spec (docs/core-api/openapi.json).
	 *
	 * @return list<string>
	 */
	private static function spec_codes(): array {
		$json = file_get_contents( dirname( __DIR__, 4 ) . '/docs/core-api/openapi.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertIsString( $json );

		$spec = json_decode( $json, true );
		self::assertIsArray( $spec );

		// An error schema's `error_code` is a $ref to an enum schema (one per endpoint and status).
		$schemas = $spec['components']['schemas'] ?? array();
		$codes   = array();
		foreach ( $schemas as $schema ) {
			$property = $schema['properties']['error_code'] ?? null;
			if ( ! is_array( $property ) ) {
				continue;
			}

			if ( isset( $property['$ref'] ) ) {
				$property = $schemas[ basename( (string) $property['$ref'] ) ] ?? array();
			}

			foreach ( $property['enum'] ?? array() as $code ) {
				$codes[] = (string) $code;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	public function test_the_spec_has_error_codes(): void {
		self::assertContains( 'invalid_request_signature', self::spec_codes() );
	}

	public function test_every_code_of_the_spec_is_listed(): void {
		self::assertSame( array(), array_values( array_diff( self::spec_codes(), ErrorMessages::CORE_CODES ) ) );
	}

	public function test_every_code_has_a_message(): void {
		$messages = ErrorMessages::messages();

		foreach ( array_merge( ErrorMessages::CORE_CODES, ErrorMessages::PLUGIN_CODES ) as $code ) {
			self::assertArrayHasKey( $code, $messages, "No message for {$code}." );
			self::assertNotSame( '', trim( $messages[ $code ] ) );
		}
	}

	public function test_no_message_without_a_listed_code(): void {
		self::assertEqualsCanonicalizing(
			array_merge( ErrorMessages::CORE_CODES, ErrorMessages::PLUGIN_CODES ),
			array_keys( ErrorMessages::messages() )
		);
	}

	public function test_codes_are_unique(): void {
		$codes = array_merge( ErrorMessages::CORE_CODES, ErrorMessages::PLUGIN_CODES );

		self::assertSame( $codes, array_values( array_unique( $codes ) ) );
	}

	public function test_known_code(): void {
		self::assertSame(
			ErrorMessages::messages()['store_not_active'],
			ErrorMessages::for_error( new CoreError( 'store_not_active', 403, 'nx-1234567890' ) )
		);
	}

	/**
	 * Unknown codes fall back on the HTTP status.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function fallbacks(): array {
		return array(
			'no response' => array( 0, CoreError::NETWORK_ERROR ),
			'status 413'  => array( 413, 'request_too_large' ),
			'status 429'  => array( 429, 'too_many_attempts' ),
			'status 500'  => array( 500, 'internal_server_error' ),
			'status 503'  => array( 503, 'internal_server_error' ),
			'status 404'  => array( 404, CoreError::UNKNOWN_ERROR ),
			'status 400'  => array( 400, CoreError::UNKNOWN_ERROR ),
		);
	}

	/**
	 * A code the plugin doesn't know yet gets the message of its status.
	 *
	 * @param int    $status   HTTP status.
	 * @param string $expected Code whose message is used.
	 */
	#[DataProvider( 'fallbacks' )]
	public function test_unknown_code_falls_back_on_the_status( int $status, string $expected ): void {
		self::assertSame( ErrorMessages::messages()[ $expected ], ErrorMessages::message( 'a_code_added_later', $status ) );
	}

	public function test_messages_never_contain_the_raw_code_or_markup(): void {
		foreach ( ErrorMessages::messages() as $code => $message ) {
			self::assertStringNotContainsString( $code, $message );
			self::assertDoesNotMatchRegularExpression( '/[<>]/', $message );
		}
	}
}
