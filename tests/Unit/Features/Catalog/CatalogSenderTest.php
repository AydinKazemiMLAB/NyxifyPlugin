<?php
/**
 * Tests for CatalogSender.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\CatalogSender;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\TestCase;

/**
 * The catalog endpoints over a mocked wp_remote_request(), with the real client and secret store.
 */
final class CatalogSenderTest extends TestCase {

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

	protected function setUp(): void {
		parent::setUp();

		$this->next_response = self::response( 200, '{"affected":1}' );

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
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

		$store = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		$store->save_connection( new Connection( self::STORE_ID, self::secret() ) );
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
			'headers'  => $headers + array( 'x-request-id' => 'req-core-0001' ),
		);
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'ef', 32 );
	}

	private function sender(): CatalogSender {
		return new CatalogSender( new CoreClient( new SecretStore( Encryptor::from_secret( 'site secret' ) ), self::BASE_URL ) );
	}

	/**
	 * Whether a request carries a valid signature of its body.
	 *
	 * @param array{url: string, args: array<string, mixed>} $request Request.
	 */
	private static function assert_signed( array $request ): void {
		$headers = $request['args']['headers'];
		$body    = $request['args']['body'] ?? '';
		$message = $headers[ HmacSigner::HEADER_TIMESTAMP ] . '.' . $headers[ HmacSigner::HEADER_NONCE ] . '.' . $body;

		self::assertSame( self::STORE_ID, $headers[ HmacSigner::HEADER_SHOP_ID ] );
		self::assertSame( 'sha256=' . hash_hmac( 'sha256', $message, self::secret() ), $headers[ HmacSigner::HEADER_SIGNATURE ] );
	}

	/**
	 * Each endpoint: the call, its path, the exact body and a valid answer.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function endpoints(): array {
		return array(
			'products'          => array( 'products', '/api/v1/catalog/webhooks/products', '{"applied":1,"skipped_stale":[],"skipped_plan_limit":[]}' ),
			'product deletes'   => array( 'delete_products', '/api/v1/catalog/webhooks/products/delete', '{"affected":2}' ),
			'categories'        => array( 'categories', '/api/v1/catalog/webhooks/categories', '{"affected":1}' ),
			'category deletes'  => array( 'delete_categories', '/api/v1/catalog/webhooks/categories/delete', '{"affected":0}' ),
			'full sync request' => array( 'request_sync', '/api/v1/catalog/sync', '{"scheduled":true}' ),
		);
	}

	/**
	 * Calls an endpoint with sample data.
	 *
	 * @param string $call Method name.
	 * @return \WooNyx\Shared\Http\CoreResponse
	 */
	private function call( string $call ) {
		$sender = $this->sender();

		switch ( $call ) {
			case 'products':
				return $sender->products(
					array(
						array(
							'product'    => array(
								'id'   => 13,
								'name' => 'کفش',
							),
							'variations' => array( array( 'id' => 70 ) ),
						),
					)
				);
			case 'delete_products':
				return $sender->delete_products( array( 42, 43 ) );
			case 'categories':
				return $sender->categories( array( array( 'id' => 7 ) ) );
			case 'delete_categories':
				return $sender->delete_categories( array( 7 ) );
			default:
				return $sender->request_sync();
		}
	}

	/**
	 * Each call is one signed POST to its path, with the exact body.
	 *
	 * @param string $call   Method name.
	 * @param string $path   Path.
	 * @param string $answer Valid answer.
	 */
	#[DataProvider( 'endpoints' )]
	public function test_each_call_is_a_signed_post_to_its_path( string $call, string $path, string $answer ): void {
		$this->next_response = self::response( 'request_sync' === $call ? 202 : 200, $answer );

		$response = $this->call( $call );

		self::assertTrue( $response->is_success() );
		self::assertCount( 1, $this->requests );
		self::assertSame( self::BASE_URL . $path, $this->requests[0]['url'] );
		self::assertSame( 'POST', $this->requests[0]['args']['method'] );
		self::assert_signed( $this->requests[0] );
	}

	public function test_exact_bodies(): void {
		foreach ( array( 'products', 'delete_products', 'categories', 'delete_categories', 'request_sync' ) as $call ) {
			$this->call( $call );
		}

		self::assertSame(
			array(
				'{"items":[{"product":{"id":13,"name":"کفش"},"variations":[{"id":70}]}]}',
				'{"product_ids":[42,43]}',
				'{"items":[{"id":7}]}',
				'{"category_ids":[7]}',
				null,
			),
			array_map( static fn( array $request ) => $request['args']['body'] ?? null, $this->requests )
		);
	}

	public function test_every_attempt_is_signed_with_a_new_timestamp_or_nonce(): void {
		$this->call( 'products' );
		$this->call( 'products' );

		self::assertCount( 2, $this->requests );
		self::assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ HmacSigner::HEADER_NONCE ], $this->requests[1]['args']['headers'][ HmacSigner::HEADER_NONCE ] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ], $this->requests[1]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ] );
		self::assert_signed( $this->requests[0] );
		self::assert_signed( $this->requests[1] );
	}

	/**
	 * Answers that don't match their endpoint.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function invalid_answers(): array {
		return array(
			'products without applied'         => array( 'products', '{"skipped_stale":[],"skipped_plan_limit":[]}' ),
			'products applied as text'         => array( 'products', '{"applied":"1","skipped_stale":[],"skipped_plan_limit":[]}' ),
			'products without stale list'      => array( 'products', '{"applied":1,"skipped_plan_limit":[]}' ),
			'products stale not a list'        => array( 'products', '{"applied":1,"skipped_stale":{"a":1},"skipped_plan_limit":[]}' ),
			'products stale id zero'           => array( 'products', '{"applied":1,"skipped_stale":[0],"skipped_plan_limit":[]}' ),
			'products limit id as text'        => array( 'products', '{"applied":1,"skipped_stale":[],"skipped_plan_limit":["5"]}' ),
			'products limit missing'           => array( 'products', '{"applied":1,"skipped_stale":[]}' ),
			'product deletes without affected' => array( 'delete_products', '{"deleted":1}' ),
			'categories affected as float'     => array( 'categories', '{"affected":1.5}' ),
			'category deletes as a list'       => array( 'delete_categories', '[1]' ),
			'sync scheduled as text'           => array( 'request_sync', '{"scheduled":"true"}' ),
			'sync empty'                       => array( 'request_sync', '{}' ),
		);
	}

	/**
	 * An answer that doesn't match becomes invalid_response with its 2xx status.
	 *
	 * @param string $call   Method name.
	 * @param string $answer Answer body.
	 */
	#[DataProvider( 'invalid_answers' )]
	public function test_an_unexpected_answer_is_invalid_response( string $call, string $answer ): void {
		$this->next_response = self::response( 200, $answer );

		$response = $this->call( $call );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::INVALID_RESPONSE, $response->error->code );
		self::assertSame( 200, $response->error->status );
		self::assertSame( 'req-core-0001', $response->error->request_id );
		self::assertSame( array(), $response->data );
	}

	public function test_valid_product_answers_keep_their_data(): void {
		$this->next_response = self::response( 200, '{"applied":2,"skipped_stale":[13],"skipped_plan_limit":[14,15]}' );

		$response = $this->call( 'products' );

		self::assertSame(
			array(
				'applied'            => 2,
				'skipped_stale'      => array( 13 ),
				'skipped_plan_limit' => array( 14, 15 ),
			),
			$response->data
		);
	}

	public function test_errors_pass_through_with_retry_after(): void {
		$this->next_response = self::response( 429, '{"message":"Slow down","error_code":"rate_limited"}', array( 'retry-after' => '90' ) );

		$response = $this->call( 'categories' );

		self::assertFalse( $response->is_success() );
		self::assertSame( 'rate_limited', $response->error->code );
		self::assertSame( 429, $response->error->status );
		self::assertSame( 90, $response->error->retry_after );
	}

	public function test_a_network_failure_passes_through(): void {
		$this->next_response = new \stdClass();

		$response = $this->call( 'delete_products' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NETWORK_ERROR, $response->error->code );
	}

	public function test_without_a_connection_nothing_is_sent(): void {
		$this->options = array();

		$response = $this->call( 'products' );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NOT_ACTIVATED, $response->error->code );
		self::assertSame( array(), $this->requests );
	}
}
