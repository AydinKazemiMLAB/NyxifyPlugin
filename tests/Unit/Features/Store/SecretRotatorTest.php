<?php
/**
 * Tests for the shared secret rotation.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Store\SecretRotator;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Shared\Security\HmacSigner;
use Nyxify\Tests\Unit\TestCase;

/**
 * The whole rotation over a mocked wp_remote_request() and WooCommerce logger, with the real client and secret store.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class SecretRotatorTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Whether update_option() fails.
	 *
	 * @var bool
	 */
	private bool $update_fails = false;

	/**
	 * Calls to wp_remote_request(): url and args.
	 *
	 * @var list<array{url: string, args: array<string, mixed>}>
	 */
	private array $requests = array();

	/**
	 * What the next wp_remote_request() returns.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $next_response;

	/**
	 * Whether the cached store status is still there.
	 *
	 * @var bool
	 */
	private bool $status_cached = true;

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::rotated( self::new_secret() );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				if ( $this->update_fails ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				if ( StatusService::TRANSIENT === $name ) {
					$this->status_cached = false;
				}
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_request' )->alias(
			function ( string $url, array $args ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				return $this->next_response;
			}
		);
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
	}

	/**
	 * A fake HTTP response.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => array( 'x-request-id' => 'req-core-0001' ),
		);
	}

	/**
	 * JSON of a value (wp_json_encode() is only stubbed inside a test).
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private static function old_secret(): string {
		return 'sec_' . str_repeat( '01', 32 );
	}

	private static function new_secret(): string {
		return 'sec_' . str_repeat( 'cd', 32 );
	}

	/**
	 * The core's 200 answer.
	 *
	 * @param mixed $secret The new secret.
	 * @return array<string, mixed>
	 */
	private static function rotated( $secret ): array {
		return self::response(
			200,
			self::json(
				array(
					'shared_secret'              => $secret,
					'previous_secret_expires_at' => '2026-10-08T10:00:00',
				)
			)
		);
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( self::STORE_ID, self::old_secret() ) );
	}

	private function rotate(): ?CoreError {
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		);

		$store = $this->store();

		return ( new SecretRotator( new CoreClient( $store, self::BASE_URL ), $store, new Logger( $wc_logger ) ) )->rotate();
	}

	/**
	 * The secret saved now.
	 */
	private function saved_secret(): ?string {
		return $this->store()->get_connection()?->shared_secret;
	}

	/**
	 * The signature of a request with an empty body.
	 *
	 * @param array<string, string> $headers The request's headers.
	 * @param string                $secret  Shared secret.
	 */
	private static function signature( array $headers, string $secret ): string {
		$message = $headers[ HmacSigner::HEADER_TIMESTAMP ] . '.' . $headers[ HmacSigner::HEADER_NONCE ] . '.';

		return 'sha256=' . hash_hmac( 'sha256', $message, $secret );
	}

	/**
	 * Level and code of each failure logged.
	 *
	 * @return list<string>
	 */
	private function logged_codes(): array {
		$codes = array();
		foreach ( $this->entries as $entry ) {
			$codes[] = $entry['level'] . ':' . (string) ( $entry['context']['error_code'] ?? '' );
		}

		return $codes;
	}

	/**
	 * No shared secret in any log entry.
	 */
	private function assert_logs_hold_no_secrets(): void {
		$logged = self::json( $this->entries );

		self::assertStringNotContainsString( 'sec_', $logged );
		self::assertStringNotContainsString( str_repeat( 'cd', 8 ), $logged );
		self::assertStringNotContainsString( str_repeat( '01', 8 ), $logged );
	}

	public function test_sends_a_signed_post_with_an_empty_body(): void {
		$this->connect();

		self::assertNull( $this->rotate() );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/store/secret/rotate', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );
		self::assertArrayNotHasKey( 'Content-Type', $request['args']['headers'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame(
			self::signature( $headers, self::old_secret() ),
			$headers[ HmacSigner::HEADER_SIGNATURE ]
		);
	}

	public function test_saves_the_new_secret_with_the_same_store_id(): void {
		$this->connect();

		self::assertNull( $this->rotate() );

		$connection = $this->store()->get_connection();
		self::assertNotNull( $connection );
		self::assertSame( self::STORE_ID, $connection->store_id );
		self::assertSame( self::new_secret(), $connection->shared_secret );
	}

	public function test_the_next_request_is_signed_with_the_new_secret(): void {
		$this->connect();
		$this->rotate();

		$this->next_response = self::response( 200, '{}' );
		( new CoreClient( $this->store(), self::BASE_URL ) )->get( '/store/status' );

		$headers = $this->requests[1]['args']['headers'];
		self::assertSame(
			self::signature( $headers, self::new_secret() ),
			$headers[ HmacSigner::HEADER_SIGNATURE ]
		);
	}

	public function test_success_clears_the_cached_status_and_logs_without_secrets(): void {
		$this->connect();

		$this->rotate();

		self::assertFalse( $this->status_cached );
		self::assertCount( 1, $this->entries );
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame( 'Shared secret rotated.', $this->entries[0]['message'] );
		self::assertSame( self::STORE_ID, $this->entries[0]['context']['store_id'] );
		self::assertSame( 'req-core-0001', $this->entries[0]['context']['request_id'] );
		$this->assert_logs_hold_no_secrets();
	}

	public function test_not_connected_sends_nothing(): void {
		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::NOT_ACTIVATED, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
		self::assertTrue( $this->status_cached );
	}

	public function test_an_unreadable_connection_sends_nothing(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( 'v1:garbage', $this->options[ SecretStore::OPTION_CONNECTION ] );
	}

	/**
	 * Errors of the core (openapi.json) that keep the old secret.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function core_errors(): array {
		return array(
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'clock'           => array( 401, 'request_timestamp_expired' ),
			'replayed'        => array( 401, 'replayed_request' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'server error'    => array( 500, 'internal_server_error' ),
		);
	}

	#[DataProvider( 'core_errors' )]
	public function test_a_core_error_keeps_the_old_secret( int $status, string $code ): void {
		$this->connect();
		$this->next_response = self::response(
			$status,
			self::json(
				array(
					'message'    => 'Rejected.',
					'error_code' => $code,
				)
			)
		);

		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );
		self::assertSame( 'req-core-0001', $error->request_id );
		self::assertSame( self::old_secret(), $this->saved_secret() );
		self::assertTrue( $this->status_cached );
		self::assertCount( 1, $this->entries );
		$this->assert_logs_hold_no_secrets();
	}

	public function test_a_lost_answer_keeps_the_old_secret(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( self::old_secret(), $this->saved_secret() );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged_codes() );
	}

	/**
	 * Answers without a usable secret.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_answers(): array {
		return array(
			'no body'        => array( self::response( 200, '' ) ),
			'not json'       => array( self::response( 200, '<html>' ) ),
			'no secret'      => array( self::response( 200, '{"previous_secret_expires_at":"2026-10-08T10:00:00"}' ) ),
			'not a string'   => array( self::rotated( 42 ) ),
			'wrong prefix'   => array( self::rotated( 'key_' . str_repeat( 'cd', 32 ) ) ),
			'too short'      => array( self::rotated( 'sec_' . str_repeat( 'cd', 31 ) ) ),
			'uppercase hex'  => array( self::rotated( 'sec_' . str_repeat( 'CD', 32 ) ) ),
			'trailing space' => array( self::rotated( self::new_secret() . ' ' ) ),
			'a list'         => array( self::response( 200, '[]' ) ),
		);
	}

	/**
	 * An answer without a usable secret.
	 *
	 * @param array<string, mixed> $response The core's answer.
	 */
	#[DataProvider( 'invalid_answers' )]
	public function test_an_invalid_answer_keeps_the_old_secret( array $response ): void {
		$this->connect();
		$this->next_response = $response;

		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::INVALID_RESPONSE, $error->code );
		self::assertSame( self::old_secret(), $this->saved_secret() );
		self::assertTrue( $this->status_cached );
		self::assertSame( array( 'warning:' . CoreError::INVALID_RESPONSE ), $this->logged_codes() );
		$this->assert_logs_hold_no_secrets();
	}

	public function test_a_failed_save_keeps_the_old_secret_and_says_so(): void {
		$this->connect();
		$this->update_fails = true;

		$error = $this->rotate();

		self::assertNotNull( $error );
		self::assertSame( CoreError::SECRET_NOT_SAVED, $error->code );
		self::assertSame( 200, $error->status );
		self::assertSame( 'req-core-0001', $error->request_id );
		self::assertSame( self::old_secret(), $this->saved_secret() );
		self::assertTrue( $this->status_cached );
		self::assertCount( 1, $this->entries );
		$this->assert_logs_hold_no_secrets();
	}

	public function test_rotating_again_after_a_failure_signs_with_the_old_secret(): void {
		$this->connect();
		$this->next_response = new \stdClass();
		$this->rotate();

		$this->next_response = self::rotated( self::new_secret() );
		self::assertNull( $this->rotate() );

		$headers = $this->requests[1]['args']['headers'];
		self::assertSame(
			self::signature( $headers, self::old_secret() ),
			$headers[ HmacSigner::HEADER_SIGNATURE ]
		);
		self::assertSame( self::new_secret(), $this->saved_secret() );
	}
}
