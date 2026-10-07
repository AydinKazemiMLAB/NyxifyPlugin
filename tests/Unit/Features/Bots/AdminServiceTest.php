<?php
/**
 * Tests for the bot admins service.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Bots\AdminService;
use WooNyx\Features\Bots\BotAdmin;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\TestCase;

/**
 * GET /bots/admins and DELETE /bots/admins/{id} over a mocked wp_remote_request() and WooCommerce logger, with the
 * real client and secret store.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class AdminServiceTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

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

		$this->next_response = self::response( 200, '[]' );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
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
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * A core error answer.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 * @return array<string, mixed>
	 */
	private static function core_error( int $status, string $code ): array {
		return self::response(
			$status,
			self::json(
				array(
					'message'    => 'Rejected.',
					'error_code' => $code,
				)
			)
		);
	}

	/**
	 * An `AdminView`.
	 *
	 * @param int    $id       Admin id.
	 * @param string $platform Messenger.
	 * @return array<string, mixed>
	 */
	private static function view( int $id = 7, string $platform = 'telegram' ): array {
		return array(
			'id'                 => $id,
			'messenger_platform' => $platform,
			'messenger_user_id'  => '987654321',
			'first_name'         => 'Sara',
			'last_name'          => 'Ahmadi',
			'username'           => 'sara_shop',
		);
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'ab', 32 );
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function connect(): void {
		$this->store()->save_connection( new Connection( self::STORE_ID, self::secret() ) );
	}

	private function service(): AdminService {
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

		return new AdminService( new CoreClient( $this->store(), self::BASE_URL ), new Logger( $wc_logger ) );
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
	 * No personal data or secret in any log entry.
	 */
	private function assert_logs_hold_no_personal_data(): void {
		$logged = self::json( $this->entries );

		foreach ( array( 'Sara', 'Ahmadi', 'sara_shop', '987654321', 'sec_' ) as $sensitive ) {
			self::assertStringNotContainsString( $sensitive, $logged );
		}
	}

	// ------------------------------------------------------------------ list

	public function test_list_sends_a_signed_get(): void {
		$this->connect();

		$this->service()->list();

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots/admins', $request['url'] );
		self::assertSame( 'GET', $request['args']['method'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame( self::signature( $headers, '' ), $headers[ HmacSigner::HEADER_SIGNATURE ] );
	}

	public function test_list_reads_every_admin_and_logs_nothing(): void {
		$this->connect();
		$this->next_response = self::response( 200, self::json( array( self::view( 7 ), self::view( 12, 'bale' ) ) ) );

		$admins = $this->service()->list();

		self::assertIsArray( $admins );
		self::assertSame( array( 7, 12 ), array_map( fn( BotAdmin $admin ) => $admin->id, $admins ) );
		self::assertSame( 'bale', $admins[1]->platform );
		self::assertSame( 'Sara Ahmadi', $admins[0]->full_name() );
		self::assertSame( array(), $this->entries );
	}

	public function test_list_without_admins_is_empty(): void {
		$this->connect();

		self::assertSame( array(), $this->service()->list() );
	}

	/**
	 * Answers that aren't a list of valid admins.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_lists(): array {
		$broken = self::view();
		unset( $broken['messenger_user_id'] );

		return array(
			'not json'        => array( '<html>' ),
			'empty body'      => array( '' ),
			'an object'       => array( self::json( self::view() ) ),
			'a string'        => array( '"admins"' ),
			'one item broken' => array( self::json( array( self::view(), $broken ) ) ),
			'id as a string'  => array( self::json( array( array_merge( self::view(), array( 'id' => '7' ) ) ) ) ),
			'item not object' => array( '[1]' ),
		);
	}

	#[DataProvider( 'invalid_lists' )]
	public function test_list_refuses_an_invalid_answer_without_logging_its_content( string $body ): void {
		$this->connect();
		$this->next_response = self::response( 200, $body );

		$result = $this->service()->list();

		self::assertInstanceOf( CoreError::class, $result );
		self::assertSame( CoreError::INVALID_RESPONSE, $result->code );
		self::assertSame( array( 'warning:' . CoreError::INVALID_RESPONSE ), $this->logged_codes() );
		self::assertSame( AdminService::OPERATION_LIST, $this->entries[0]['context']['operation'] );
		$this->assert_logs_hold_no_personal_data();
	}

	/**
	 * Errors of the core (openapi.json) on GET /bots/admins.
	 *
	 * @return array<string, array{int, string, string}>
	 */
	public static function list_errors(): array {
		return array(
			'bad signature'   => array( 401, 'invalid_request_signature', 'warning' ),
			'not active'      => array( 403, 'store_not_active', 'warning' ),
			'no subscription' => array( 403, 'no_active_subscription', 'warning' ),
			'server error'    => array( 500, 'internal_server_error', 'error' ),
		);
	}

	#[DataProvider( 'list_errors' )]
	public function test_list_returns_and_logs_a_core_error( int $status, string $code, string $level ): void {
		$this->connect();
		$this->next_response = self::core_error( $status, $code );

		$result = $this->service()->list();

		self::assertInstanceOf( CoreError::class, $result );
		self::assertSame( $code, $result->code );
		self::assertSame( $status, $result->status );
		self::assertSame( 'req-core-0001', $result->request_id );
		self::assertSame( array( $level . ':' . $code ), $this->logged_codes() );
	}

	public function test_list_network_error(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$result = $this->service()->list();

		self::assertInstanceOf( CoreError::class, $result );
		self::assertSame( CoreError::NETWORK_ERROR, $result->code );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged_codes() );
	}

	// ------------------------------------------------------------------ revoke

	public function test_revoke_sends_a_signed_delete_and_logs_the_request_id_only(): void {
		$this->connect();
		$this->next_response = self::response( 204, '' );

		self::assertNull( $this->service()->revoke( 7 ) );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots/admins/7', $request['url'] );
		self::assertSame( 'DELETE', $request['args']['method'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::signature( $headers, '' ), $headers[ HmacSigner::HEADER_SIGNATURE ] );

		self::assertCount( 1, $this->entries );
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame( 'Bot admin removed.', $this->entries[0]['message'] );
		self::assertSame(
			array(
				'request_id' => 'req-core-0001',
				'source'     => 'woonyx',
			),
			$this->entries[0]['context']
		);
	}

	/**
	 * Errors of the core (openapi.json) on DELETE /bots/admins/{id}.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function revoke_errors(): array {
		return array(
			'not an admin'    => array( 404, 'customer_not_found' ),
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'server error'    => array( 500, 'internal_server_error' ),
		);
	}

	#[DataProvider( 'revoke_errors' )]
	public function test_revoke_returns_and_logs_a_core_error( int $status, string $code ): void {
		$this->connect();
		$this->next_response = self::core_error( $status, $code );

		$error = $this->service()->revoke( 7 );

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );
		self::assertCount( 1, $this->entries );
		self::assertSame( AdminService::OPERATION_REVOKE, $this->entries[0]['context']['operation'] );
		self::assertSame( $code, $this->entries[0]['context']['error_code'] );
	}

	public function test_revoke_network_error(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$error = $this->service()->revoke( 7 );

		self::assertNotNull( $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged_codes() );
	}

	/**
	 * Ids that must never reach the URL.
	 *
	 * @return array<string, array{int}>
	 */
	public static function invalid_ids(): array {
		return array(
			'zero'     => array( 0 ),
			'negative' => array( -3 ),
		);
	}

	#[DataProvider( 'invalid_ids' )]
	public function test_revoke_refuses_an_invalid_id_without_sending( int $id ): void {
		$this->connect();

		$error = $this->service()->revoke( $id );

		self::assertNotNull( $error );
		self::assertSame( CoreError::INVALID_PAYLOAD, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	// ------------------------------------------------------------------ no connection

	public function test_nothing_is_sent_or_logged_without_a_connection(): void {
		$service = $this->service();

		$list = $service->list();
		self::assertInstanceOf( CoreError::class, $list );
		self::assertSame( CoreError::NOT_ACTIVATED, $list->code );
		self::assertSame( CoreError::NOT_ACTIVATED, $service->revoke( 7 )?->code );

		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	public function test_nothing_is_sent_or_logged_with_an_unreadable_connection(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		$service = $this->service();

		$list = $service->list();
		self::assertInstanceOf( CoreError::class, $list );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $list->code );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $service->revoke( 7 )?->code );

		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}
}
