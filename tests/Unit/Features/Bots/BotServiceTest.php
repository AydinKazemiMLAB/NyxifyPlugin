<?php
/**
 * Tests for the bots service.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Bots\Bot;
use Nyxify\Features\Bots\BotService;
use Nyxify\Features\Bots\BotToken;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Shared\Security\HmacSigner;
use Nyxify\Tests\Unit\TestCase;

/**
 * GET/POST /bots and DELETE /bots/{platform} over a mocked wp_remote_request() and WooCommerce logger, with the real
 * client and secret store.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class BotServiceTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';
	private const TOKEN    = '123456789:AAFsecretTokenValue_xyz';

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
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
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
	 * A `BotView`.
	 *
	 * @param string $platform Platform.
	 * @param string $status   Status.
	 * @return array<string, mixed>
	 */
	private static function view( string $platform = 'telegram', string $status = 'active' ): array {
		return array(
			'platform'       => $platform,
			'username'       => 'nyxify_shop_bot',
			'name'           => 'Nyxify Shop',
			'status'         => $status,
			'webhook_active' => 'active' === $status,
			'last_error'     => 'error' === $status ? 'HTTP 502, response is not JSON' : null,
			'deployed_at'    => 'active' === $status ? '2026-10-07T10:15:00' : null,
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

	private function service(): BotService {
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

		return new BotService( new CoreClient( $this->store(), self::BASE_URL ), new Logger( $wc_logger ) );
	}

	private static function token(): BotToken {
		$token = BotToken::parse( self::TOKEN );
		self::assertNotNull( $token );

		return $token;
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
	 * No token, secret, bot name or core error text in any log entry.
	 */
	private function assert_logs_hold_nothing_sensitive(): void {
		$logged = self::json( $this->entries );

		foreach ( array( 'AAFsecret', '123456789', 'sec_', 'nyxify_shop_bot', 'Nyxify Shop', 'HTTP 502' ) as $sensitive ) {
			self::assertStringNotContainsString( $sensitive, $logged );
		}
	}

	// ------------------------------------------------------------------ list

	public function test_list_sends_a_signed_get(): void {
		$this->connect();

		$this->service()->list();

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots', $request['url'] );
		self::assertSame( 'GET', $request['args']['method'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame( self::signature( $headers, '' ), $headers[ HmacSigner::HEADER_SIGNATURE ] );
	}

	public function test_list_reads_every_bot(): void {
		$this->connect();
		$this->next_response = self::response(
			200,
			self::json( array( self::view( 'telegram' ), self::view( 'bale', 'pending' ), self::view( 'rubika', 'error' ) ) )
		);

		$bots = $this->service()->list();

		self::assertIsArray( $bots );
		self::assertCount( 3, $bots );
		self::assertSame( array( 'telegram', 'bale', 'rubika' ), array_map( fn( Bot $bot ) => $bot->platform, $bots ) );
		self::assertSame( Bot::PENDING, $bots[1]->status );
		self::assertSame( 'HTTP 502, response is not JSON', $bots[2]->last_error );
		self::assertSame( array(), $this->entries );
	}

	public function test_list_without_bots_is_empty(): void {
		$this->connect();

		self::assertSame( array(), $this->service()->list() );
	}

	/**
	 * Answers that aren't a list of valid bots.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_lists(): array {
		return array(
			'not json'        => array( '<html>' ),
			'empty body'      => array( '' ),
			'an object'       => array( '{"platform":"telegram"}' ),
			'a string'        => array( '"telegram"' ),
			'one item broken' => array( self::json( array( self::view(), array( 'platform' => 'bale' ) ) ) ),
			'item not object' => array( '[1]' ),
		);
	}

	#[DataProvider( 'invalid_lists' )]
	public function test_list_refuses_an_invalid_answer( string $body ): void {
		$this->connect();
		$this->next_response = self::response( 200, $body );

		$result = $this->service()->list();

		self::assertInstanceOf( CoreError::class, $result );
		self::assertSame( CoreError::INVALID_RESPONSE, $result->code );
		self::assertSame( array( 'warning:' . CoreError::INVALID_RESPONSE ), $this->logged_codes() );
		self::assertSame( BotService::OPERATION_LIST, $this->entries[0]['context']['operation'] );
	}

	/**
	 * Errors of the core (openapi.json) on GET /bots.
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

	// ------------------------------------------------------------------ register

	public function test_register_posts_the_platform_and_token_signed_with_the_long_timeout(): void {
		$this->connect();
		$this->next_response = self::response( 200, self::json( self::view( 'telegram', 'pending' ) ) );

		self::assertNull( $this->service()->register( 'telegram', self::token() ) );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertSame( CoreClient::LONG_TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertSame( '{"platform":"telegram","token":"' . self::TOKEN . '"}', $request['args']['body'] );

		$headers = $request['args']['headers'];
		self::assertSame( 'application/json', $headers['Content-Type'] );
		self::assertSame( self::signature( $headers, $request['args']['body'] ), $headers[ HmacSigner::HEADER_SIGNATURE ] );
	}

	public function test_register_logs_the_platform_only(): void {
		$this->connect();
		$this->next_response = self::response( 200, self::json( self::view( 'bale', 'pending' ) ) );

		$this->service()->register( 'bale', self::token() );

		self::assertCount( 1, $this->entries );
		self::assertSame( 'info', $this->entries[0]['level'] );
		self::assertSame( 'Bot registered.', $this->entries[0]['message'] );
		self::assertSame( 'bale', $this->entries[0]['context']['platform'] );
		self::assertSame( 'req-core-0001', $this->entries[0]['context']['request_id'] );
		$this->assert_logs_hold_nothing_sensitive();
	}

	/**
	 * Errors of the core (openapi.json) on POST /bots.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function register_errors(): array {
		return array(
			'invalid token'   => array( 400, 'invalid_bot_token' ),
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'not in plan'     => array( 403, 'platform_not_in_plan' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'other store'     => array( 409, 'bot_already_registered' ),
			'server error'    => array( 500, 'internal_server_error' ),
			'messenger down'  => array( 502, 'messenger_api_unavailable' ),
		);
	}

	#[DataProvider( 'register_errors' )]
	public function test_register_returns_and_logs_a_core_error_without_the_token( int $status, string $code ): void {
		$this->connect();
		$this->next_response = self::core_error( $status, $code );

		$error = $this->service()->register( 'telegram', self::token() );

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );
		self::assertCount( 1, $this->entries );
		self::assertSame( BotService::OPERATION_REGISTER, $this->entries[0]['context']['operation'] );
		self::assertSame( $code, $this->entries[0]['context']['error_code'] );
		$this->assert_logs_hold_nothing_sensitive();
	}

	public function test_register_network_error(): void {
		$this->connect();
		$this->next_response = new \stdClass();

		$error = $this->service()->register( 'rubika', self::token() );

		self::assertNotNull( $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( array( 'error:' . CoreError::NETWORK_ERROR ), $this->logged_codes() );
		$this->assert_logs_hold_nothing_sensitive();
	}

	public function test_register_refuses_an_unknown_platform_without_sending(): void {
		$this->connect();

		$error = $this->service()->register( 'whatsapp', self::token() );

		self::assertNotNull( $error );
		self::assertSame( CoreError::INVALID_PAYLOAD, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	// ------------------------------------------------------------------ remove

	public function test_remove_sends_a_signed_delete(): void {
		$this->connect();
		$this->next_response = self::response( 204, '' );

		self::assertNull( $this->service()->remove( 'bale' ) );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/bots/bale', $request['url'] );
		self::assertSame( 'DELETE', $request['args']['method'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );

		$headers = $request['args']['headers'];
		self::assertSame( self::signature( $headers, '' ), $headers[ HmacSigner::HEADER_SIGNATURE ] );

		self::assertSame( 'Bot removed.', $this->entries[0]['message'] );
		self::assertSame( 'bale', $this->entries[0]['context']['platform'] );
	}

	/**
	 * Errors of the core (openapi.json) on DELETE /bots/{platform}.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function remove_errors(): array {
		return array(
			'no bot'          => array( 404, 'bot_not_found' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'server error'    => array( 500, 'internal_server_error' ),
		);
	}

	#[DataProvider( 'remove_errors' )]
	public function test_remove_returns_and_logs_a_core_error( int $status, string $code ): void {
		$this->connect();
		$this->next_response = self::core_error( $status, $code );

		$error = $this->service()->remove( 'telegram' );

		self::assertNotNull( $error );
		self::assertSame( $code, $error->code );
		self::assertSame( BotService::OPERATION_REMOVE, $this->entries[0]['context']['operation'] );
	}

	/**
	 * Platforms that must never reach the URL.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unknown_platforms(): array {
		return array(
			'other'      => array( 'eitaa' ),
			'empty'      => array( '' ),
			'path'       => array( 'telegram/../admins/7' ),
			'upper case' => array( 'Telegram' ),
		);
	}

	#[DataProvider( 'unknown_platforms' )]
	public function test_remove_refuses_an_unknown_platform_without_sending( string $platform ): void {
		$this->connect();

		$error = $this->service()->remove( $platform );

		self::assertNotNull( $error );
		self::assertSame( CoreError::INVALID_PAYLOAD, $error->code );
		self::assertSame( array(), $this->requests );
	}

	// ------------------------------------------------------------------ no connection

	public function test_nothing_is_sent_or_logged_without_a_connection(): void {
		$service = $this->service();

		$list = $service->list();
		self::assertInstanceOf( CoreError::class, $list );
		self::assertSame( CoreError::NOT_ACTIVATED, $list->code );
		self::assertSame( CoreError::NOT_ACTIVATED, $service->register( 'telegram', self::token() )?->code );
		self::assertSame( CoreError::NOT_ACTIVATED, $service->remove( 'telegram' )?->code );

		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	public function test_nothing_is_sent_or_logged_with_an_unreadable_connection(): void {
		$this->options[ SecretStore::OPTION_CONNECTION ] = 'v1:garbage';

		$service = $this->service();

		$list = $service->list();
		self::assertInstanceOf( CoreError::class, $list );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $list->code );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $service->register( 'bale', self::token() )?->code );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $service->remove( 'bale' )?->code );

		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}
}
