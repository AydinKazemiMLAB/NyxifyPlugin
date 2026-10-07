<?php
/**
 * Tests for the core client.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Shared\Http;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\TestCase;

/**
 * Requests built, signed and read over a mocked wp_remote_request().
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class CoreClientTest extends TestCase {

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
	 * Environment type returned by wp_get_environment_type().
	 *
	 * @var string
	 */
	private string $environment = 'production';

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::response( 200, '{"ok":true}' );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->alias( fn(): string => $this->environment );
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
	 * @param int                   $status  HTTP status.
	 * @param string                $body    Raw body.
	 * @param array<string, string> $headers Lowercase header names.
	 * @return array<string, mixed>
	 */
	private static function response( int $status, string $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'cd', 32 );
	}

	private function store(): SecretStore {
		return new SecretStore( Encryptor::from_secret( 'site secret' ) );
	}

	private function activated_client( string $base_url = self::BASE_URL ): CoreClient {
		$store = $this->store();
		$store->save_connection( new Connection( self::STORE_ID, self::secret() ) );

		return new CoreClient( $store, $base_url );
	}

	/**
	 * Arguments of the only request sent.
	 *
	 * @return array{url: string, args: array<string, mixed>}
	 */
	private function sent(): array {
		self::assertCount( 1, $this->requests );

		return $this->requests[0];
	}

	/**
	 * Headers of the only request sent.
	 *
	 * @return array<string, string>
	 */
	private function sent_headers(): array {
		$headers = $this->sent()['args']['headers'];
		self::assertIsArray( $headers );

		return $headers;
	}

	public function test_signed_post_sends_and_signs_the_same_bytes(): void {
		$response = $this->activated_client()->post( '/catalog/webhooks/products', array( 'name' => 'چای / سبز' ) );

		self::assertTrue( $response->is_success() );

		$sent = $this->sent();
		self::assertSame( 'https://core.example.com/api/v1/catalog/webhooks/products', $sent['url'] );
		self::assertSame( 'POST', $sent['args']['method'] );
		self::assertSame( '{"name":"چای / سبز"}', $sent['args']['body'] );

		$headers = $this->sent_headers();
		self::assertSame( 'application/json', $headers['Content-Type'] );
		self::assertSame( self::STORE_ID, $headers['X-WooNyx-Shop-Id'] );
		self::assertTrue(
			( new HmacSigner( self::secret() ) )->verify( $headers, '{"name":"چای / سبز"}', self::STORE_ID ),
			'The signature covers exactly the body that was sent.'
		);
	}

	public function test_signed_get_has_an_empty_signed_body_and_a_query(): void {
		$this->activated_client()->get( '/sync/outbox', array( 'limit' => 50 ) );

		$sent    = $this->sent();
		$headers = $this->sent_headers();
		self::assertSame( 'https://core.example.com/api/v1/sync/outbox?limit=50', $sent['url'] );
		self::assertSame( 'GET', $sent['args']['method'] );
		self::assertArrayNotHasKey( 'body', $sent['args'] );
		self::assertArrayNotHasKey( 'Content-Type', $headers );
		self::assertTrue( ( new HmacSigner( self::secret() ) )->verify( $headers, '', self::STORE_ID ) );
	}

	public function test_post_with_an_empty_payload_sends_an_empty_object(): void {
		$this->activated_client()->post( '/bots/admin-pin', array() );

		self::assertSame( '{}', $this->sent()['args']['body'] );
	}

	public function test_transport_options(): void {
		$this->activated_client( self::BASE_URL . '/' )->delete( '/bots/telegram' );

		$sent = $this->sent();
		self::assertSame( 'https://core.example.com/api/v1/bots/telegram', $sent['url'] );
		self::assertSame( 'DELETE', $sent['args']['method'] );
		self::assertSame( 0, $sent['args']['redirection'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $sent['args']['timeout'] );
		self::assertSame( 'WooNyx/' . WOONYX_VERSION, $sent['args']['user-agent'] );
		self::assertSame( 'application/json', $this->sent_headers()['Accept'] );
	}

	public function test_every_request_has_its_own_request_id(): void {
		$client = $this->activated_client();
		$client->get( '/store/status' );
		$client->get( '/store/status' );

		$first  = $this->requests[0]['args']['headers'];
		$second = $this->requests[1]['args']['headers'];
		self::assertIsArray( $first );
		self::assertIsArray( $second );
		self::assertMatchesRegularExpression( '/^wn-[a-f0-9]{24}$/D', $first['X-Request-ID'] );
		self::assertNotSame( $first['X-Request-ID'], $second['X-Request-ID'] );
	}

	public function test_signed_calls_send_nothing_before_activation(): void {
		$response = ( new CoreClient( $this->store(), self::BASE_URL ) )->get( '/store/status' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NOT_ACTIVATED, $response->error->code );
		self::assertSame( 0, $response->status );
		self::assertSame( array(), $this->requests );
	}

	public function test_an_unreadable_connection_asks_for_a_new_activation(): void {
		( new SecretStore( Encryptor::from_secret( 'old salts' ) ) )->save_connection( new Connection( self::STORE_ID, self::secret() ) );

		$response = ( new CoreClient( $this->store(), self::BASE_URL ) )->post( '/catalog/sync' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::CONNECTION_UNREADABLE, $response->error->code );
		self::assertSame( array(), $this->requests );
	}

	public function test_activation_is_unsigned_and_waits_longer(): void {
		$this->next_response = self::response( 200, '{"store_id":"shop_8f2a0c1d3e4b5a69","status":"active"}' );

		$response = ( new CoreClient( $this->store(), self::BASE_URL ) )->post_unsigned( '/store/activate', array( 'site_url' => 'https://shop.example.com' ) );

		self::assertTrue( $response->is_success() );
		self::assertSame( 'active', $response->data['status'] );
		self::assertSame( '{"site_url":"https://shop.example.com"}', $this->sent()['args']['body'] );
		self::assertSame( CoreClient::LONG_TIMEOUT_SECONDS, $this->sent()['args']['timeout'] );
		self::assertArrayNotHasKey( HmacSigner::HEADER_SIGNATURE, $this->sent_headers() );
		self::assertArrayNotHasKey( HmacSigner::HEADER_SHOP_ID, $this->sent_headers() );
	}

	/**
	 * Core addresses that are refused before anything is sent.
	 *
	 * @return array<string, array{string}>
	 */
	public static function unusable_base_urls(): array {
		return array(
			'plain http'   => array( 'http://core.example.com' ),
			'no scheme'    => array( 'core.example.com' ),
			'other scheme' => array( 'ftp://core.example.com' ),
			'credentials'  => array( 'https://user:pass@core.example.com' ),
			'query'        => array( 'https://core.example.com/?a=1' ),
			'fragment'     => array( 'https://core.example.com/#a' ),
			'empty'        => array( '' ),
			'malformed'    => array( 'https://' ),
		);
	}

	/**
	 * Only a clean https address is used in production.
	 *
	 * @param string $base_url Core address.
	 */
	#[DataProvider( 'unusable_base_urls' )]
	public function test_unusable_base_urls_send_nothing( string $base_url ): void {
		$response = $this->activated_client( $base_url )->get( '/store/status' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::INVALID_BASE_URL, $response->error->code );
		self::assertSame( array(), $this->requests );
	}

	/**
	 * Environments where plain http is accepted.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function environments(): array {
		return array(
			'local'       => array( 'local', true ),
			'development' => array( 'development', true ),
			'staging'     => array( 'staging', false ),
			'production'  => array( 'production', false ),
		);
	}

	/**
	 * Plain http only on a developer's machine.
	 *
	 * @param string $environment WP_ENVIRONMENT_TYPE.
	 * @param bool   $allowed     Whether the request goes out.
	 */
	#[DataProvider( 'environments' )]
	public function test_plain_http_depends_on_the_environment( string $environment, bool $allowed ): void {
		$this->environment = $environment;

		$response = $this->activated_client( 'http://localhost:8000' )->get( '/store/status' );

		self::assertSame( $allowed, $response->is_success() );
		self::assertCount( $allowed ? 1 : 0, $this->requests );
	}

	/**
	 * Paths the client refuses (a caller bug, not a core error).
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_paths(): array {
		return array(
			'no leading slash' => array( 'store/status' ),
			'full url'         => array( 'https://evil.example.com/x' ),
			'query in path'    => array( '/sync/outbox?limit=1' ),
			'trailing slash'   => array( '/store/' ),
			'double slash'     => array( '//evil.example.com' ),
			'empty'            => array( '' ),
		);
	}

	/**
	 * Only plain API paths are accepted.
	 *
	 * @param string $path Path.
	 */
	#[DataProvider( 'invalid_paths' )]
	public function test_invalid_paths_throw( string $path ): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->activated_client()->get( $path );
	}

	public function test_core_error_keeps_only_code_status_request_id_and_fields(): void {
		$this->next_response = self::response(
			422,
			'{"message":"Validation error","error_code":"validation_error","details":[{"field":"body.site_url","message":"bad https://secret"},{"field":"bad field!","message":"x"}]}',
			array( 'x-request-id' => 'core-req-12345' )
		);

		$response = $this->activated_client()->post( '/store/activate', array( 'site_url' => 'x' ) );

		self::assertFalse( $response->is_success() );
		self::assertSame( 'validation_error', $response->error->code );
		self::assertSame( 422, $response->error->status );
		self::assertSame( 'core-req-12345', $response->error->request_id );
		self::assertSame( array( 'body.site_url' ), $response->error->fields );
		self::assertSame( array(), $response->data );

		$dump = print_r( $response, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
		self::assertStringNotContainsString( 'Validation error', $dump );
		self::assertStringNotContainsString( 'secret', $dump );
	}

	public function test_a_malformed_core_request_id_falls_back_to_the_one_sent(): void {
		$this->next_response = self::response( 403, '{"error_code":"store_not_active"}', array( 'x-request-id' => "bad\nid" ) );

		$response = $this->activated_client()->get( '/store/status' );

		self::assertSame( 'store_not_active', $response->error?->code );
		self::assertSame( $this->sent_headers()['X-Request-ID'], $response->request_id );
	}

	/**
	 * Answers the client turns into its own error codes.
	 *
	 * @return array<string, array{int, string, string}>
	 */
	public static function unusable_answers(): array {
		return array(
			'html from a proxy'     => array( 502, '<html>Bad Gateway</html>', CoreError::UNKNOWN_ERROR ),
			'error without a code'  => array( 500, '{"message":"x"}', CoreError::UNKNOWN_ERROR ),
			'malformed error code'  => array( 400, '{"error_code":"Not A Code"}', CoreError::UNKNOWN_ERROR ),
			'redirect'              => array( 301, '', CoreError::UNEXPECTED_REDIRECT ),
			'success without json'  => array( 200, 'OK', CoreError::INVALID_RESPONSE ),
			'success with a scalar' => array( 200, 'true', CoreError::INVALID_RESPONSE ),
			'empty 200'             => array( 200, '', CoreError::INVALID_RESPONSE ),
		);
	}

	/**
	 * Unusable answers become errors with the status kept.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @param string $code   Expected error code.
	 */
	#[DataProvider( 'unusable_answers' )]
	public function test_unusable_answers( int $status, string $body, string $code ): void {
		$this->next_response = self::response( $status, $body );

		$response = $this->activated_client()->get( '/store/status' );

		self::assertFalse( $response->is_success() );
		self::assertSame( $code, $response->error->code );
		self::assertSame( $status, $response->status );
	}

	public function test_lists_and_empty_204_are_successes(): void {
		$this->next_response = self::response( 200, '[{"platform":"telegram"}]' );
		$list                = $this->activated_client()->get( '/bots' );

		$this->next_response = self::response( 204, '' );
		$deleted             = $this->activated_client()->delete( '/bots/admins/7' );

		self::assertTrue( $list->is_success() );
		self::assertSame( array( array( 'platform' => 'telegram' ) ), $list->data );
		self::assertTrue( $deleted->is_success() );
		self::assertSame( 204, $deleted->status );
		self::assertSame( array(), $deleted->data );
	}

	public function test_network_failure(): void {
		$this->next_response = new \stdClass();

		$response = $this->activated_client()->get( '/store/status' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NETWORK_ERROR, $response->error->code );
		self::assertSame( 0, $response->status );
		self::assertSame( $this->sent_headers()['X-Request-ID'], $response->request_id );
	}

	public function test_unencodable_payload_sends_nothing(): void {
		$response = $this->activated_client()->post( '/catalog/webhooks/products', array( 'name' => "\xB1\x31" ) );

		self::assertSame( CoreError::INVALID_PAYLOAD, $response->error?->code );
		self::assertSame( array(), $this->requests );
	}

	public function test_default_base_url(): void {
		self::assertSame( CoreClient::DEFAULT_BASE_URL, CoreClient::configured_base_url() );
		self::assertSame( 'https://core.example.com', ( new CoreClient( $this->store(), 'https://core.example.com/' ) )->base_url() );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_base_url_from_the_constant(): void {
		define( 'WOONYX_API_BASE_URL', 'https://api.example.org' );

		self::assertSame( 'https://api.example.org', CoreClient::configured_base_url() );
	}
}
