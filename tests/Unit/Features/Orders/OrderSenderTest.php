<?php
/**
 * Tests for OrderSender.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Orders;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Orders\OrderSender;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\CoreResponse;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\TestCase;

/**
 * The order status endpoint over a mocked wp_remote_request(), with the real client and secret store.
 */
final class OrderSenderTest extends TestCase {

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

	/**
	 * Sends an order and a delete item.
	 */
	private function send(): CoreResponse {
		$sender = new OrderSender( new CoreClient( new SecretStore( Encryptor::from_secret( 'site secret' ) ), self::BASE_URL ) );

		return $sender->status(
			array(
				array(
					'id'                => 501,
					'status'            => 'processing',
					'number'            => '501',
					'currency'          => 'IRT',
					'total'             => '2400000.00',
					'date_created_gmt'  => '2026-09-14T10:00:00',
					'date_modified_gmt' => '2026-09-14T11:00:00',
					'date_paid_gmt'     => null,
					'line_items'        => array(
						array(
							'name'         => 'پیراهن آبی',
							'product_id'   => 42,
							'variation_id' => 0,
							'quantity'     => 2,
							'total'        => '2400000.00',
						),
					),
				),
				array(
					'id'     => 502,
					'status' => 'trash',
				),
			)
		);
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

	public function test_one_signed_post_with_the_exact_body(): void {
		$response = $this->send();

		self::assertTrue( $response->is_success() );
		self::assertSame( array( 'affected' => 1 ), $response->data );
		self::assertCount( 1, $this->requests );
		self::assertSame( self::BASE_URL . '/api/v1/orders/webhooks/status', $this->requests[0]['url'] );
		self::assertSame( 'POST', $this->requests[0]['args']['method'] );
		self::assertSame(
			'{"orders":[{"id":501,"status":"processing","number":"501","currency":"IRT","total":"2400000.00",'
			. '"date_created_gmt":"2026-09-14T10:00:00","date_modified_gmt":"2026-09-14T11:00:00","date_paid_gmt":null,'
			. '"line_items":[{"name":"پیراهن آبی","product_id":42,"variation_id":0,"quantity":2,"total":"2400000.00"}]},'
			. '{"id":502,"status":"trash"}]}',
			$this->requests[0]['args']['body']
		);
		self::assert_signed( $this->requests[0] );
	}

	public function test_every_attempt_is_signed_with_a_new_nonce_and_request_id(): void {
		$this->send();
		$this->send();

		self::assertCount( 2, $this->requests );
		self::assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ HmacSigner::HEADER_NONCE ], $this->requests[1]['args']['headers'][ HmacSigner::HEADER_NONCE ] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ], $this->requests[1]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ] );
		self::assert_signed( $this->requests[0] );
		self::assert_signed( $this->requests[1] );
	}

	public function test_zero_affected_is_a_valid_answer(): void {
		$this->next_response = self::response( 200, '{"affected":0}' );

		$response = $this->send();

		self::assertTrue( $response->is_success() );
		self::assertSame( array( 'affected' => 0 ), $response->data );
	}

	/**
	 * Answers that aren't `{"affected": int}`.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_answers(): array {
		return array(
			'empty object'        => array( '{}' ),
			'affected as text'    => array( '{"affected":"1"}' ),
			'affected as float'   => array( '{"affected":1.5}' ),
			'affected null'       => array( '{"affected":null}' ),
			'affected as boolean' => array( '{"affected":true}' ),
			'other key'           => array( '{"applied":1}' ),
			'a list'              => array( '[1]' ),
			'not json'            => array( 'ok' ),
		);
	}

	/**
	 * An answer that doesn't match becomes invalid_response with its 2xx status.
	 *
	 * @param string $answer Answer body.
	 */
	#[DataProvider( 'invalid_answers' )]
	public function test_an_unexpected_answer_is_invalid_response( string $answer ): void {
		$this->next_response = self::response( 200, $answer );

		$response = $this->send();

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::INVALID_RESPONSE, $response->error->code );
		self::assertSame( 200, $response->error->status );
		self::assertSame( 'req-core-0001', $response->error->request_id );
		self::assertSame( array(), $response->data );
	}

	public function test_errors_pass_through_with_retry_after(): void {
		$this->next_response = self::response( 429, '{"message":"Slow down","error_code":"rate_limited"}', array( 'retry-after' => '90' ) );

		$response = $this->send();

		self::assertFalse( $response->is_success() );
		self::assertSame( 'rate_limited', $response->error->code );
		self::assertSame( 429, $response->error->status );
		self::assertSame( 90, $response->error->retry_after );
	}

	public function test_a_validation_error_keeps_field_names(): void {
		$this->next_response = self::response( 422, '{"message":"Validation error","error_code":"validation_error","details":[{"field":"body.orders.0.status","message":"bad"}]}' );

		$response = $this->send();

		self::assertFalse( $response->is_success() );
		self::assertSame( 'validation_error', $response->error->code );
		self::assertSame( array( 'body.orders.0.status' ), $response->error->fields );
	}

	public function test_a_network_failure_passes_through(): void {
		$this->next_response = new \stdClass();

		$response = $this->send();

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NETWORK_ERROR, $response->error->code );
	}

	public function test_without_a_connection_nothing_is_sent(): void {
		$this->options = array();

		$response = $this->send();

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NOT_ACTIVATED, $response->error->code );
		self::assertSame( array(), $this->requests );
	}
}
