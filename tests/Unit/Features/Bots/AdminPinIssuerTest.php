<?php
/**
 * Tests for the admin PIN issuer.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\AdminPinIssuer;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Shared\Security\HmacSigner;
use Nyxify\Tests\Unit\TestCase;

/**
 * POST /bots/admin-pin over a mocked wp_remote_request() and WooCommerce logger, with the real client and secret
 * store.
 */
final class AdminPinIssuerTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';
	private const OLD_PIN  = '135790';
	private const NEW_PIN  = '042917';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Option names whose update_option() fails.
	 *
	 * @var list<string>
	 */
	private array $failing_updates = array();

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
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::issued( self::NEW_PIN );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				if ( in_array( $name, $this->failing_updates, true ) ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
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
			'headers'  => array(
				'x-request-id'  => 'req-core-0001',
				'cache-control' => 'no-store',
			),
		);
	}

	/**
	 * JSON of a value (wp_json_encode() is only stubbed inside a test).
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * The core's 200 answer.
	 *
	 * @param mixed $pin The PIN.
	 * @return array<string, mixed>
	 */
	private static function issued( $pin ): array {
		return self::response( 200, self::json( array( 'pin' => $pin ) ) );
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'ab', 32 );
	}

	private function connect( bool $with_old_pin = true ): void {
		$this->store()->save_connection( new Connection( self::STORE_ID, self::secret() ) );
		if ( $with_old_pin ) {
			$this->store()->save_admin_pin( self::OLD_PIN );
		}
	}

	private function issue( bool $revoke_admins = false ): ?CoreError {
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

		return ( new AdminPinIssuer( new CoreClient( $store, self::BASE_URL ), $store, new Logger( $wc_logger ) ) )->issue( $revoke_admins );
	}

	/**
	 * The signature of a request.
	 *
	 * @param array<string, string> $headers The request's headers.
	 * @param string                $body    The exact body sent.
	 */
	private static function signature( array $headers, string $body ): string {
		$message = $headers[ HmacSigner::HEADER_TIMESTAMP ] . '.' . $headers[ HmacSigner::HEADER_NONCE ] . '.' . $body;

		return 'sha256=' . hash_hmac( 'sha256', $message, self::secret() );
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
	 * No PIN and no secret in any log entry.
	 */
	private function assert_logs_hold_no_pin(): void {
		$logged = self::json( $this->entries );

		self::assertStringNotContainsString( self::NEW_PIN, $logged );
		self::assertStringNotContainsString( self::OLD_PIN, $logged );
		self::assertStringNotContainsString( 'sec_', $logged );
	}

	public function test_sends_a_signed_post_without_a_body(): void {
		$this->connect();

		self::assertNull( $this->issue() );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots/admin-pin', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame( self::signature( $headers, '' ), $headers[ HmacSigner::HEADER_SIGNATURE ] );
	}

	public function test_revoking_sends_the_flag_signed(): void {
		$this->connect();

		self::assertNull( $this->issue( true ) );

		$request = $this->requests[0];
		self::assertSame( '{"revoke_admins":true}', $request['args']['body'] );
		self::assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		self::assertSame( self::signature( $request['args']['headers'], '{"revoke_admins":true}' ), $request['args']['headers'][ HmacSigner::HEADER_SIGNATURE ] );
	}

	public function test_the_new_pin_replaces_the_old_one_encrypted(): void {
		$this->connect();

		$this->issue();

		self::assertSame( self::NEW_PIN, $this->store()->get_admin_pin() );
		$stored = $this->options[ SecretStore::OPTION_ADMIN_PIN ];
		self::assertIsString( $stored );
		self::assertStringNotContainsString( self::NEW_PIN, $stored );
	}

	public function test_success_logs_the_flag_without_the_pin(): void {
		$this->connect();

		$this->issue( true );

		self::assertCount( 1, $this->entries );
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame( 'Admin PIN issued.', $this->entries[0]['message'] );
		self::assertTrue( $this->entries[0]['context']['revoke_admins'] );
		self::assertSame( 'req-core-0001', $this->entries[0]['context']['request_id'] );
		$this->assert_logs_hold_no_pin();
	}

	public function test_a_first_pin_is_saved(): void {
		$this->connect( false );

		self::assertNull( $this->issue() );
		self::assertSame( self::NEW_PIN, $this->store()->get_admin_pin() );
	}

	/**
	 * Errors of the core (openapi.json) on POST /bots/admin-pin.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function core_errors(): array {
		return array(
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'replayed'        => array( 401, 'replayed_request' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'bad request'     => array( 400, 'bad_request' ),
			'server error'    => array( 500, 'internal_server_error' ),
		);
	}

	#[DataProvider( 'core_errors' )]
	public function test_a_core_error_keeps_the_old_pin( int $status, string $code ): void {
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

		$error = $this->issue();

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );
		self::assertSame( self::OLD_PIN, $this->store()->get_admin_pin() );
		self::assertCount( 1, $this->entries );
		self::assertSame( AdminPinIssuer::OPERATION, $this->entries[0]['context']['operation'] );
		$this->assert_logs_hold_no_pin();
	}

	public function test_a_lost_answer_keeps_the_old_pin(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$error = $this->issue();

		self::assertNotNull( $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( self::OLD_PIN, $this->store()->get_admin_pin() );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged_codes() );
	}

	/**
	 * Answers without a usable PIN.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_answers(): array {
		return array(
			'no body'         => array( self::response( 200, '' ) ),
			'not json'        => array( self::response( 200, '<html>' ) ),
			'no pin'          => array( self::response( 200, '{}' ) ),
			'a number'        => array( self::issued( 42917 ) ),
			'five digits'     => array( self::issued( '42917' ) ),
			'seven digits'    => array( self::issued( '0429170' ) ),
			'letters'         => array( self::issued( '04291a' ) ),
			'spaces'          => array( self::issued( ' 042917' ) ),
			'trailing break'  => array( self::issued( "042917\n" ) ),
			'Persian digits'  => array( self::issued( '۰۴۲۹۱۷' ) ),
			'a list of pins'  => array( self::issued( array( self::NEW_PIN ) ) ),
			'a list'          => array( self::response( 200, '[]' ) ),
			'markup as a pin' => array( self::issued( '<b>1</b>' ) ),
		);
	}

	/**
	 * An answer without a usable PIN.
	 *
	 * @param array<string, mixed> $response The core's answer.
	 */
	#[DataProvider( 'invalid_answers' )]
	public function test_an_invalid_answer_saves_nothing( array $response ): void {
		$this->connect();
		$this->next_response = $response;

		$error = $this->issue();

		self::assertNotNull( $error );
		self::assertSame( CoreError::INVALID_RESPONSE, $error->code );
		self::assertSame( self::OLD_PIN, $this->store()->get_admin_pin() );
		self::assertSame( array( 'warning:' . CoreError::INVALID_RESPONSE ), $this->logged_codes() );
		$this->assert_logs_hold_no_pin();
	}

	public function test_a_failed_save_forgets_the_old_pin_and_says_so(): void {
		$this->connect();
		$this->failing_updates = array( SecretStore::OPTION_ADMIN_PIN );

		$error = $this->issue();

		self::assertNotNull( $error );
		self::assertSame( CoreError::PIN_NOT_SAVED, $error->code );
		self::assertSame( 'req-core-0001', $error->request_id );
		self::assertNull( $this->store()->get_admin_pin() );
		self::assertArrayNotHasKey( SecretStore::OPTION_ADMIN_PIN, $this->options );
		self::assertSame( array( 'warning:' . CoreError::PIN_NOT_SAVED ), $this->logged_codes() );
		$this->assert_logs_hold_no_pin();
	}

	public function test_nothing_is_sent_or_logged_without_a_connection(): void {
		$this->store()->save_admin_pin( self::OLD_PIN );

		$error = $this->issue();

		self::assertNotNull( $error );
		self::assertSame( CoreError::NOT_ACTIVATED, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
		self::assertSame( self::OLD_PIN, $this->store()->get_admin_pin() );
	}

	public function test_nothing_is_sent_or_logged_with_an_unreadable_connection(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		$error = $this->issue( true );

		self::assertNotNull( $error );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}
}
