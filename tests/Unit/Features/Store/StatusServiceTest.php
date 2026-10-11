<?php
/**
 * Tests for the store status and its cache.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Store\StatusService;
use Nyxify\Features\Store\StoreStatus;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * `GET /store/status` over a mocked wp_remote_request(), with in-memory options and transients.
 */
final class StatusServiceTest extends TestCase {

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Lifetimes passed to set_transient().
	 *
	 * @var array<string, int>
	 */
	private array $lifetimes = array();

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

		$this->next_response = self::response( 200, self::json( self::answer() ) );

		Functions\when( 'get_option' )->alias(
			fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn( string $name ) => $this->transients[ $name ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value, int $expiration ): bool {
				$this->transients[ $name ] = $value;
				$this->lifetimes[ $name ]  = $expiration;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
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
	 * JSON of a value.
	 *
	 * @param mixed $value Value.
	 */
	private static function json( $value ): string {
		return (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
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
			'headers'  => array( 'x-request-id' => 'req-core-0003' ),
		);
	}

	/**
	 * The core's answer (contract §1.3).
	 *
	 * @return array<string, mixed>
	 */
	private static function answer(): array {
		return array(
			'store_id'     => self::STORE_ID,
			'website_url'  => 'https://shop.example.com',
			'status'       => 'active',
			'wp_version'   => '7.1.3',
			'woo_version'  => '11.1.2',
			'license'      => array(
				'key_prefix'   => 'NX-ABCDEF',
				'activated_at' => '2026-09-13T09:00:00',
			),
			'subscription' => array(
				'plan'              => 'free',
				'plan_name'         => 'Free',
				'expires_at'        => '2026-10-13T09:00:00',
				'allowed_platforms' => array( 'telegram', 'bale', 'rubika' ),
				'max_products'      => 50,
			),
			'catalog'      => array(
				'status'          => 'succeeded',
				'started_at'      => '2026-09-13T09:00:01',
				'last_synced_at'  => '2026-09-13T09:00:09',
				'error'           => null,
				'limit_reached'   => false,
				'active_products' => 42,
			),
		);
	}

	private function service( bool $connected = true ): StatusService {
		$store = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		if ( $connected ) {
			$store->save_connection( new Connection( self::STORE_ID, 'sec_' . str_repeat( 'cd', 32 ) ) );
		}

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

		return new StatusService( new CoreClient( $store, self::BASE_URL ), new Logger( $wc_logger ) );
	}

	public function test_the_status_is_fetched_signed_and_parsed(): void {
		$before = time();
		$status = $this->service()->get();

		self::assertInstanceOf( StoreStatus::class, $status );
		self::assertSame( self::BASE_URL . '/api/v1/store/status', $this->requests[0]['url'] );
		self::assertSame( 'GET', $this->requests[0]['args']['method'] );
		self::assertSame( self::STORE_ID, $this->requests[0]['args']['headers']['X-Nyxify-Shop-Id'] );

		self::assertSame( self::STORE_ID, $status->store_id );
		self::assertSame( 'https://shop.example.com', $status->website_url );
		self::assertSame( 'active', $status->status );
		self::assertSame( 'NX-ABCDEF', $status->license_prefix );
		self::assertSame( '2026-09-13T09:00:00', $status->license_activated_at );
		self::assertSame( 'Free', $status->plan_name );
		self::assertSame( '2026-10-13T09:00:00', $status->expires_at );
		self::assertSame( array( 'telegram', 'bale', 'rubika' ), $status->allowed_platforms );
		self::assertSame( 50, $status->max_products );
		self::assertSame( 'succeeded', $status->catalog_status );
		self::assertSame( '2026-09-13T09:00:09', $status->catalog_last_synced_at );
		self::assertNull( $status->catalog_error );
		self::assertFalse( $status->limit_reached );
		self::assertSame( 42, $status->active_products );
		self::assertGreaterThanOrEqual( $before, $status->checked_at );
		self::assertSame( array(), $this->entries );
	}

	public function test_a_status_is_cached_for_five_minutes_and_reused(): void {
		$first = $this->service()->get();

		self::assertSame( StatusService::CACHE_SECONDS, $this->lifetimes[ StatusService::TRANSIENT ] );
		self::assertSame( 300, StatusService::CACHE_SECONDS );

		$second = $this->service()->get();

		self::assertCount( 1, $this->requests );
		self::assertInstanceOf( StoreStatus::class, $first );
		self::assertInstanceOf( StoreStatus::class, $second );
		self::assertSame( $first->to_array(), $second->to_array() );
	}

	public function test_clear_makes_the_next_call_ask_the_core(): void {
		$this->service()->get();

		StatusService::clear();
		$this->service()->get();

		self::assertCount( 2, $this->requests );
	}

	public function test_a_malformed_cache_entry_is_ignored(): void {
		$this->transients[ StatusService::TRANSIENT ] = array( 'store_id' => self::STORE_ID );

		self::assertInstanceOf( StoreStatus::class, $this->service()->get() );
		self::assertCount( 1, $this->requests );
	}

	public function test_errors_are_logged_and_not_cached(): void {
		$this->next_response = self::response(
			403,
			self::json(
				array(
					'message'    => 'x',
					'error_code' => 'no_active_subscription',
				)
			)
		);

		$error = $this->service()->get();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( 'no_active_subscription', $error->code );
		self::assertSame( 'req-core-0003', $error->request_id );
		self::assertSame( array(), $this->transients );
		self::assertSame( 'warning', $this->entries[0]['level'] );
		self::assertSame( StatusService::OPERATION, $this->entries[0]['context']['operation'] );

		$this->service()->get();
		self::assertCount( 2, $this->requests );
	}

	public function test_a_network_failure_is_logged_as_an_error_and_not_cached(): void {
		$this->next_response = new \stdClass();

		$error = $this->service()->get();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::NETWORK_ERROR, $error->code );
		self::assertSame( array(), $this->transients );
		self::assertSame( 'error', $this->entries[0]['level'] );
	}

	public function test_without_a_connection_nothing_is_sent_or_logged(): void {
		$error = $this->service( false )->get();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::NOT_ACTIVATED, $error->code );
		self::assertSame( array(), $this->requests );
		self::assertSame( array(), $this->entries );
	}

	/**
	 * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
	 */
	public static function malformed_answers(): array {
		return array(
			'no subscription'         => array(
				function ( array $a ): array {
					unset( $a['subscription'] );
					return $a;
				},
			),
			'catalog a string'        => array(
				function ( array $a ): array {
					$a['catalog'] = 'ok';
					return $a;
				},
			),
			'no store id'             => array(
				function ( array $a ): array {
					unset( $a['store_id'] );
					return $a;
				},
			),
			'status a number'         => array(
				function ( array $a ): array {
					$a['status'] = 1;
					return $a;
				},
			),
			'max products a string'   => array(
				function ( array $a ): array {
					$a['subscription']['max_products'] = '50';
					return $a;
				},
			),
			'limit reached a string'  => array(
				function ( array $a ): array {
					$a['catalog']['limit_reached'] = 'no';
					return $a;
				},
			),
			'platforms a string'      => array(
				function ( array $a ): array {
					$a['subscription']['allowed_platforms'] = 'telegram';
					return $a;
				},
			),
			'catalog error an object' => array(
				function ( array $a ): array {
					$a['catalog']['error'] = array( 'detail' => 'x' );
					return $a;
				},
			),
			'license prefix a number' => array(
				function ( array $a ): array {
					$a['license']['key_prefix'] = 7;
					return $a;
				},
			),
		);
	}

	/**
	 * @param callable $change Changes the answer.
	 *
	 * @phpstan-param callable(array<string, mixed>): array<string, mixed> $change
	 */
	#[DataProvider( 'malformed_answers' )]
	public function test_a_malformed_answer_is_an_invalid_response( callable $change ): void {
		$this->next_response = self::response( 200, self::json( $change( self::answer() ) ) );

		$error = $this->service()->get();

		self::assertInstanceOf( CoreError::class, $error );
		self::assertSame( CoreError::INVALID_RESPONSE, $error->code );
		self::assertSame( 200, $error->status );
		self::assertSame( array(), $this->transients );
		self::assertSame( CoreError::INVALID_RESPONSE, $this->entries[0]['context']['error_code'] );
	}

	public function test_optional_fields_may_be_null(): void {
		$answer                                      = self::answer();
		$answer['license']                           = null;
		$answer['subscription']['max_products']      = null;
		$answer['catalog']['last_synced_at']         = null;
		$answer['catalog']['error']                  = 'Sync failed';
		$answer['subscription']['allowed_platforms'] = array( 'telegram', 5, null );
		$this->next_response                         = self::response( 200, self::json( $answer ) );

		$status = $this->service()->get();

		self::assertInstanceOf( StoreStatus::class, $status );
		self::assertNull( $status->license_prefix );
		self::assertNull( $status->license_activated_at );
		self::assertNull( $status->max_products );
		self::assertNull( $status->catalog_last_synced_at );
		self::assertSame( 'Sync failed', $status->catalog_error );
		self::assertSame( array( 'telegram' ), $status->allowed_platforms );
	}

	public function test_the_cache_holds_only_the_shown_fields(): void {
		$this->service()->get();

		$cached = $this->transients[ StatusService::TRANSIENT ];
		self::assertIsArray( $cached );
		self::assertArrayNotHasKey( 'wp_version', $cached );
		self::assertStringNotContainsString( 'sec_', self::json( $cached ) );
		self::assertSame( $cached, StoreStatus::from_array( $cached )?->to_array() );
	}
}
