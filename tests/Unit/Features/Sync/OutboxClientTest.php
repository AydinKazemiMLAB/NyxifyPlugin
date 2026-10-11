<?php
/**
 * Tests for OutboxClient and PulledChange.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Sync\ChangeResult;
use WooNyx\Features\Sync\OutboxClient;
use WooNyx\Features\Sync\PullAnswer;
use WooNyx\Features\Sync\PulledChange;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Shared\Security\HmacSigner;
use WooNyx\Tests\Unit\TestCase;

/**
 * GET /sync/outbox and POST /sync/outbox/ack over a mocked wp_remote_request(), with the real client and secret store.
 *
 * A fake response is an array like WordPress's; a network failure is a \stdClass, which the mocked is_wp_error()
 * treats as a WP_Error.
 */
final class OutboxClientTest extends TestCase {

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

		$this->next_response = self::response( 200, '{"changes":[],"claimed_until":null}' );

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

	/**
	 * JSON of a value (wp_json_encode() is only stubbed inside a test).
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	private static function secret(): string {
		return 'sec_' . str_repeat( 'cd', 32 );
	}

	private function client(): OutboxClient {
		return new OutboxClient( new CoreClient( new SecretStore( Encryptor::from_secret( 'site secret' ) ), self::BASE_URL ) );
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
	 * A `PulledChange` of the core.
	 *
	 * @param array<string, mixed> $fields Fields replacing the defaults (a product update).
	 * @return array<string, mixed>
	 */
	private static function change( array $fields = array() ): array {
		return array_merge(
			array(
				'id'               => 41,
				'action'           => 'product.update',
				'woo_product_id'   => 13,
				'woo_variation_id' => null,
				'payload'          => array(
					'name'          => 'کفش راحتی',
					'regular_price' => '1250000',
				),
				'created_at'       => '2026-10-11T08:15:30.123456Z',
			),
			$fields
		);
	}

	/**
	 * A pull answer.
	 *
	 * @param array<array-key, mixed> $changes Changes.
	 */
	private static function pulled( array $changes ): string {
		return self::json(
			array(
				'changes'       => $changes,
				'claimed_until' => array() === $changes ? null : '2026-10-11T08:20:30.123456Z',
			)
		);
	}

	// ------------------------------------------------------------------ pull

	public function test_pull_is_a_signed_get_of_twenty_changes(): void {
		$this->client()->pull();

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/sync/outbox?limit=20', $request['url'] );
		self::assertSame( 'GET', $request['args']['method'] );
		self::assertSame( CoreClient::TIMEOUT_SECONDS, $request['args']['timeout'] );
		self::assertArrayNotHasKey( 'body', $request['args'] );
		self::assertArrayNotHasKey( 'Content-Type', $request['args']['headers'] );
		self::assert_signed( $request );
	}

	public function test_every_pull_is_signed_with_a_new_nonce_and_request_id(): void {
		$this->client()->pull();
		$this->client()->pull();

		self::assertCount( 2, $this->requests );
		self::assertNotSame( $this->requests[0]['args']['headers'][ HmacSigner::HEADER_NONCE ], $this->requests[1]['args']['headers'][ HmacSigner::HEADER_NONCE ] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ], $this->requests[1]['args']['headers'][ CoreClient::HEADER_REQUEST_ID ] );
		self::assert_signed( $this->requests[1] );
	}

	public function test_an_empty_answer_has_no_changes(): void {
		$answer = $this->client()->pull();

		self::assertInstanceOf( PullAnswer::class, $answer );
		self::assertSame( array(), $answer->changes );
		self::assertSame( 'req-core-0001', $answer->request_id );
	}

	public function test_pull_reads_every_change_in_the_cores_order(): void {
		$this->next_response = self::response(
			200,
			self::pulled(
				array(
					self::change(),
					self::change(
						array(
							'id'               => 42,
							'action'           => 'variation.update',
							'woo_variation_id' => 70,
							'payload'          => array( 'stock_quantity' => 3 ),
						)
					),
					self::change(
						array(
							'id'             => 43,
							'action'         => 'product.delete',
							'woo_product_id' => 14,
							'payload'        => new \stdClass(),
						)
					),
					self::change(
						array(
							'id'             => 44,
							'action'         => 'product.create',
							'woo_product_id' => null,
							'payload'        => array(
								'type'      => 'simple',
								'name'      => 'کیف',
								'images'    => array( array( 'src' => 'https://media.example.com/m/abc?exp=1&sig=xyz' ) ),
								'meta_data' => array(
									array(
										'key'   => '_woonyx_outbox_id',
										'value' => '44',
									),
								),
							),
						)
					),
				)
			)
		);

		$answer = $this->client()->pull();

		self::assertInstanceOf( PullAnswer::class, $answer );
		self::assertSame( array( 41, 42, 43, 44 ), array_map( static fn( PulledChange $change ): int => $change->id, $answer->changes ) );
		self::assertSame(
			array( PulledChange::PRODUCT_UPDATE, PulledChange::VARIATION_UPDATE, PulledChange::PRODUCT_DELETE, PulledChange::PRODUCT_CREATE ),
			array_map( static fn( PulledChange $change ): string => $change->action, $answer->changes )
		);

		[ $update, $variation, $delete, $create ] = $answer->changes;
		self::assertSame( 13, $update->woo_product_id );
		self::assertNull( $update->woo_variation_id );
		self::assertSame(
			array(
				'name'          => 'کفش راحتی',
				'regular_price' => '1250000',
			),
			$update->payload
		);
		self::assertSame( 13, $variation->woo_product_id );
		self::assertSame( 70, $variation->woo_variation_id );
		self::assertSame( array( 'stock_quantity' => 3 ), $variation->payload );
		self::assertSame( array(), $delete->payload );
		self::assertNull( $create->woo_product_id );
		self::assertSame( 'simple', $create->payload['type'] );
	}

	public function test_an_action_the_plugin_doesnt_know_is_still_read(): void {
		$this->next_response = self::response( 200, self::pulled( array( self::change( array( 'action' => 'category.update' ) ) ) ) );

		$answer = $this->client()->pull();

		self::assertInstanceOf( PullAnswer::class, $answer );
		self::assertSame( 'category.update', $answer->changes[0]->action );
		self::assertNotContains( 'category.update', PulledChange::ACTIONS );
	}

	public function test_a_change_without_ids_is_read_and_left_to_the_applier(): void {
		$this->next_response = self::response( 200, self::pulled( array( self::change( array( 'woo_product_id' => null ) ) ) ) );

		$answer = $this->client()->pull();

		self::assertInstanceOf( PullAnswer::class, $answer );
		self::assertNull( $answer->changes[0]->woo_product_id );
	}

	/**
	 * Answers that aren't a valid pull answer.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_pull_answers(): array {
		$without_created = self::change();
		unset( $without_created['created_at'] );
		$without_payload = self::change();
		unset( $without_payload['payload'] );

		return array(
			'a list'                   => array( '[]' ),
			'no changes'               => array( '{"claimed_until":null}' ),
			'no claimed_until'         => array( '{"changes":[]}' ),
			'claimed_until a number'   => array( '{"changes":[],"claimed_until":300}' ),
			'changes an object'        => array( '{"changes":{"first":' . self::json( self::change() ) . '},"claimed_until":null}' ),
			'changes null'             => array( '{"changes":null,"claimed_until":null}' ),
			'a change not an object'   => array( self::pulled( array( 41 ) ) ),
			'one change broken'        => array( self::pulled( array( self::change(), $without_payload ) ) ),
			'id missing'               => array( self::pulled( array( array_diff_key( self::change(), array( 'id' => 0 ) ) ) ) ),
			'id as text'               => array( self::pulled( array( self::change( array( 'id' => '41' ) ) ) ) ),
			'id zero'                  => array( self::pulled( array( self::change( array( 'id' => 0 ) ) ) ) ),
			'id a float'               => array( str_replace( '"id":41,', '"id":41.0,', self::pulled( array( self::change() ) ) ) ),
			'action missing'           => array( self::pulled( array( array_diff_key( self::change(), array( 'action' => 0 ) ) ) ) ),
			'action not a name'        => array( self::pulled( array( self::change( array( 'action' => 'Product.Update' ) ) ) ) ),
			'action without entity'    => array( self::pulled( array( self::change( array( 'action' => 'update' ) ) ) ) ),
			'action with a line break' => array( self::pulled( array( self::change( array( 'action' => "product.update\n" ) ) ) ) ),
			'product id as text'       => array( self::pulled( array( self::change( array( 'woo_product_id' => '13' ) ) ) ) ),
			'product id negative'      => array( self::pulled( array( self::change( array( 'woo_product_id' => -13 ) ) ) ) ),
			'variation id zero'        => array( self::pulled( array( self::change( array( 'woo_variation_id' => 0 ) ) ) ) ),
			'payload missing'          => array( self::pulled( array( $without_payload ) ) ),
			'payload null'             => array( self::pulled( array( self::change( array( 'payload' => null ) ) ) ) ),
			'payload a list'           => array( self::pulled( array( self::change( array( 'payload' => array( 'a', 'b' ) ) ) ) ) ),
			'payload a string'         => array( self::pulled( array( self::change( array( 'payload' => '{}' ) ) ) ) ),
			'payload numeric names'    => array( self::pulled( array( self::change( array( 'payload' => array( 5 => 'x' ) ) ) ) ) ),
			'created_at missing'       => array( self::pulled( array( $without_created ) ) ),
			'created_at a number'      => array( self::pulled( array( self::change( array( 'created_at' => 1760170530 ) ) ) ) ),
		);
	}

	#[DataProvider( 'invalid_pull_answers' )]
	public function test_an_invalid_pull_answer_is_invalid_response_with_its_status( string $body ): void {
		$this->next_response = self::response( 200, $body );

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::INVALID_RESPONSE, $error->code );
		self::assertSame( 200, $error->status );
		self::assertSame( 'req-core-0001', $error->request_id );
	}

	public function test_a_pull_answer_that_isnt_json_is_invalid_response(): void {
		$this->next_response = self::response( 200, '<html>' );

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::INVALID_RESPONSE, $error->code );
	}

	/**
	 * Errors of the core (openapi.json) on GET /sync/outbox.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function pull_errors(): array {
		return array(
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'expired'         => array( 401, 'request_timestamp_expired' ),
			'not active'      => array( 403, 'store_not_active' ),
			'no subscription' => array( 403, 'no_active_subscription' ),
			'bad request'     => array( 400, 'bad_request' ),
			'server error'    => array( 500, 'internal_server_error' ),
		);
	}

	#[DataProvider( 'pull_errors' )]
	public function test_pull_errors_pass_through( int $status, string $code ): void {
		$this->next_response = self::response(
			$status,
			self::json(
				array(
					'message'    => 'Rejected.',
					'error_code' => $code,
				)
			)
		);

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( $code, $error->code );
		self::assertSame( $status, $error->status );
		self::assertSame( 'req-core-0001', $error->request_id );
	}

	public function test_a_pull_error_keeps_retry_after(): void {
		$this->next_response = self::response( 503, '{"message":"Busy","error_code":"service_unavailable"}', array( 'retry-after' => '120' ) );

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( 'service_unavailable', $error->code );
		self::assertSame( 120, $error->retry_after );
	}

	public function test_a_pull_network_failure_passes_through(): void {
		$this->next_response = new \stdClass();

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( 0, $error->status );
	}

	public function test_without_a_connection_nothing_is_pulled(): void {
		$this->options = array();

		$error = $this->client()->pull();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::NOT_ACTIVATED, $error->code );
		self::assertSame( array(), $this->requests );
	}

	public function test_a_changes_payload_stays_out_of_debug_output(): void {
		$this->next_response = self::response( 200, self::pulled( array( self::change() ) ) );

		$answer = $this->client()->pull();
		self::assertInstanceOf( PullAnswer::class, $answer );

		$dumped = print_r( $answer->changes[0], true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r

		self::assertStringContainsString( 'product.update', $dumped );
		self::assertStringNotContainsString( 'کفش', $dumped );
		self::assertStringNotContainsString( '1250000', $dumped );
	}

	// ------------------------------------------------------------------ ack

	public function test_ack_is_a_signed_post_of_the_exact_results(): void {
		$this->next_response = self::response( 200, '{"affected":4}' );

		$response = $this->client()->ack(
			array(
				ChangeResult::success( 41, 13 )->with_date( '2026-10-11T08:16:02' ),
				ChangeResult::success( 43 ),
				ChangeResult::success( 44, 77 )->with_date( '2026-10-11T08:16:03' ),
				ChangeResult::failure( 45, 'woocommerce_rest_product_invalid_sku', 'Invalid or duplicated SKU.' ),
				ChangeResult::failure( 46, ChangeResult::INVALID_CHANGE ),
			)
		);

		self::assertTrue( $response->is_success() );
		self::assertSame( 4, $response->data['affected'] );
		self::assertSame( 'req-core-0001', $response->request_id );

		self::assertCount( 1, $this->requests );
		$request = $this->requests[0];
		self::assertSame( self::BASE_URL . '/api/v1/sync/outbox/ack', $request['url'] );
		self::assertSame( 'POST', $request['args']['method'] );
		self::assertSame( 'application/json', $request['args']['headers']['Content-Type'] );
		self::assertSame(
			'{"results":['
				. '{"id":41,"success":true,"woo_product_id":13,"date_modified_gmt":"2026-10-11T08:16:02"},'
				. '{"id":43,"success":true},'
				. '{"id":44,"success":true,"woo_product_id":77,"date_modified_gmt":"2026-10-11T08:16:03"},'
				. '{"id":45,"success":false,"error_code":"woocommerce_rest_product_invalid_sku","error_message":"Invalid or duplicated SKU."},'
				. '{"id":46,"success":false,"error_code":"invalid_change"}'
				. ']}',
			$request['args']['body']
		);
		self::assert_signed( $request );
	}

	public function test_every_ack_is_signed_with_a_new_nonce(): void {
		$this->next_response = self::response( 200, '{"affected":1}' );

		$this->client()->ack( array( ChangeResult::success( 41 ) ) );
		$this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertCount( 2, $this->requests );
		self::assertSame( $this->requests[0]['args']['body'], $this->requests[1]['args']['body'] );
		self::assertNotSame( $this->requests[0]['args']['headers'][ HmacSigner::HEADER_NONCE ], $this->requests[1]['args']['headers'][ HmacSigner::HEADER_NONCE ] );
		self::assert_signed( $this->requests[1] );
	}

	public function test_an_ack_the_core_mostly_ignored_is_still_a_success(): void {
		$this->next_response = self::response( 200, '{"affected":0}' );

		$response = $this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertTrue( $response->is_success() );
		self::assertSame( 0, $response->data['affected'] );
	}

	/**
	 * Counts of results the core refuses.
	 *
	 * @return array<string, array{int}>
	 */
	public static function invalid_result_counts(): array {
		return array(
			'none'     => array( 0 ),
			'over 100' => array( 101 ),
		);
	}

	#[DataProvider( 'invalid_result_counts' )]
	public function test_an_ack_the_core_would_refuse_is_not_sent( int $count ): void {
		$results = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$results[] = ChangeResult::success( $id );
		}

		$response = $this->client()->ack( $results );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::INVALID_PAYLOAD, $response->error->code );
		self::assertSame( array(), $this->requests );
	}

	public function test_an_ack_of_a_hundred_results_is_sent(): void {
		$this->next_response = self::response( 200, '{"affected":100}' );
		$results             = array();
		for ( $id = 1; $id <= OutboxClient::MAX_RESULTS; $id++ ) {
			$results[] = ChangeResult::success( $id );
		}

		self::assertTrue( $this->client()->ack( $results )->is_success() );
		self::assertCount( 1, $this->requests );
	}

	/**
	 * Answers that don't match the ack's response.
	 *
	 * @return array<string, array{string}>
	 */
	public static function invalid_ack_answers(): array {
		return array(
			'empty object'     => array( '{}' ),
			'a list'           => array( '[1]' ),
			'affected as text' => array( '{"affected":"1"}' ),
			'affected a float' => array( '{"affected":1.5}' ),
			'affected below 0' => array( '{"affected":-1}' ),
			'not json'         => array( 'OK' ),
		);
	}

	#[DataProvider( 'invalid_ack_answers' )]
	public function test_an_invalid_ack_answer_is_invalid_response_with_its_status( string $body ): void {
		$this->next_response = self::response( 200, $body );

		$response = $this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::INVALID_RESPONSE, $response->error->code );
		self::assertSame( 200, $response->error->status );
		self::assertSame( 'req-core-0001', $response->error->request_id );
		self::assertSame( array(), $response->data );
	}

	public function test_ack_errors_pass_through_with_retry_after(): void {
		$this->next_response = self::response( 503, '{"message":"Busy","error_code":"service_unavailable"}', array( 'retry-after' => '30' ) );

		$response = $this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertFalse( $response->is_success() );
		self::assertSame( 'service_unavailable', $response->error->code );
		self::assertSame( 503, $response->error->status );
		self::assertSame( 30, $response->error->retry_after );
	}

	public function test_an_ack_network_failure_passes_through(): void {
		$this->next_response = new \stdClass();

		$response = $this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NETWORK_ERROR, $response->error->code );
	}

	public function test_without_a_connection_nothing_is_acknowledged(): void {
		$this->options = array();

		$response = $this->client()->ack( array( ChangeResult::success( 41 ) ) );

		self::assertFalse( $response->is_success() );
		self::assertSame( CoreError::NOT_ACTIVATED, $response->error->code );
		self::assertSame( array(), $this->requests );
	}
}
