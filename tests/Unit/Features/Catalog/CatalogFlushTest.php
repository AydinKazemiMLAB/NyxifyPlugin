<?php
/**
 * Tests for CatalogFlush.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\CatalogFlush;
use Nyxify\Features\Catalog\CatalogQueue;
use Nyxify\Features\Catalog\CatalogSender;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PayloadBuilder;
use Nyxify\Features\Orders\OrderPayloadBuilder;
use Nyxify\Features\Orders\OrderSender;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\Connection;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;
use Nyxify\Tests\Unit\TestCase;

/**
 * The flush over an in-memory queue, the real builders (fake WooCommerce controllers and orders), senders and client (a
 * mocked wp_remote_request()), the real scheduler (Action Scheduler functions and options in memory) and a recording
 * logger.
 */
final class CatalogFlushTest extends TestCase {

	private const NOW      = 1791892800;
	private const BASE_URL = 'https://core.example.com';
	private const SECRET   = 'Secret product name';

	/**
	 * Current time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Pending flushes: their due times.
	 *
	 * @var list<int>
	 */
	private array $pending = array();

	/**
	 * Requests sent: path => decoded body, in order.
	 *
	 * @var list<array{path: string, body: mixed}>
	 */
	private array $sent = array();

	/**
	 * Answers per path; the default answer is a success.
	 *
	 * @var array<string, list<array<string, mixed>|\stdClass|\Throwable>>
	 */
	private array $answers = array();

	/**
	 * Runs inside the next request(s), before the answer: path => callable.
	 *
	 * @var list<callable(string): void>
	 */
	private array $during_send = array();

	/**
	 * Seconds each request takes.
	 *
	 * @var int
	 */
	private int $request_seconds = 0;

	/**
	 * Products and categories that don't exist.
	 *
	 * @var list<int>
	 */
	private array $missing = array();

	/**
	 * Orders that exist but weren't created by Nyxify (no marker).
	 *
	 * @var list<int>
	 */
	private array $foreign = array();

	/**
	 * Whether the site has a readable connection.
	 *
	 * @var bool
	 */
	private bool $connected = true;

	/**
	 * Times the status cache was cleared.
	 *
	 * @var int
	 */
	private int $status_cleared = 0;

	/**
	 * Plan-limit skips passed on, per delivered product batch.
	 *
	 * @var list<int>
	 */
	private array $plan_limits = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * The queue.
	 *
	 * @var InMemoryQueue
	 */
	private InMemoryQueue $queue;

	protected function setUp(): void {
		parent::setUp();

		$this->queue = new InMemoryQueue( fn(): int => $this->now );

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
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
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				self::assertSame( StatusService::TRANSIENT, $name );
				++$this->status_cleared;
				return true;
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias( fn(): array => array_keys( $this->pending ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args, string $group, bool $unique ): int {
				self::assertSame( array( FlushScheduler::HOOK, array(), FlushScheduler::GROUP, false ), array( $hook, $args, $group, $unique ) );
				$this->pending[] = $timestamp;
				return count( $this->pending );
			}
		);

		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
		Functions\when( 'wp_remote_request' )->alias( fn( string $url, array $args ) => $this->respond( $url, $args ) );

		Functions\when( 'wc_get_product' )->alias(
			function ( int $id ) {
				if ( in_array( $id, $this->missing, true ) ) {
					return false;
				}

				$product = Mockery::mock( 'WC_Product' );
				$product->shouldReceive( 'get_id' )->andReturn( $id );
				$product->shouldReceive( 'get_status' )->andReturn( 'publish' );
				$product->shouldReceive( 'is_type' )->andReturn( false );
				return $product;
			}
		);
		Functions\when( 'get_term' )->alias(
			function ( int $id ) {
				if ( in_array( $id, $this->missing, true ) ) {
					return null;
				}

				$term          = Mockery::mock( 'WP_Term' );
				$term->term_id = $id;
				return $term;
			}
		);

		Functions\when( 'wc_get_order' )->alias( fn( int $id ) => in_array( $id, $this->missing, true ) ? false : $this->order( $id ) );
		Functions\when( 'wc_get_price_decimals' )->justReturn( 0 );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $number, $dp ): string => number_format( (float) $number, (int) $dp, '.', '' ) );
		Functions\when( 'wc_rest_prepare_date_response' )->alias(
			static fn( $date ) => $date instanceof \DateTimeInterface ? $date->format( 'Y-m-d\TH:i:s' ) : null
		);

		( new SecretStore( Encryptor::from_secret( 'site secret' ) ) )->save_connection( new Connection( 'shop_8f2a0c1d3e4b5a69', 'sec_' . str_repeat( 'ab', 32 ) ) );
	}

	/**
	 * The fake core: records the request, runs what should happen during it, answers.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>|\stdClass
	 *
	 * @throws \Throwable When the answer queued is an exception.
	 */
	private function respond( string $url, array $args ) {
		$path         = substr( $url, strlen( self::BASE_URL . CoreClient::API_PREFIX ) );
		$this->sent[] = array(
			'path' => $path,
			'body' => json_decode( (string) ( $args['body'] ?? '' ), true ),
		);
		$this->now   += $this->request_seconds;

		$during = array_shift( $this->during_send );
		if ( null !== $during ) {
			$during( $path );
		}

		$answer = isset( $this->answers[ $path ] ) ? array_shift( $this->answers[ $path ] ) : null;
		if ( $answer instanceof \Throwable ) {
			throw $answer;
		}
		if ( null !== $answer ) {
			return $answer;
		}

		return CatalogSender::PATH_PRODUCTS === $path
			? self::response( 200, '{"applied":1,"skipped_stale":[],"skipped_plan_limit":[]}' )
			: self::response( 200, '{"affected":1}' );
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
	 * Queues an answer for a path.
	 *
	 * @param string                                $path   Path after /api/v1.
	 * @param array<string, mixed>|\stdClass|\Throwable $answer Answer.
	 */
	private function answer( string $path, $answer ): void {
		$this->answers[ $path ][] = $answer;
	}

	/**
	 * A fake controller returning a small object per id, with a name the logs must never show.
	 *
	 * @param string $class_name Controller class.
	 */
	private function controller( string $class_name ): object {
		$controller = Mockery::mock( $class_name );
		$respond    = static function ( $item ) {
			$id       = isset( $item->term_id ) ? $item->term_id : $item->get_id();
			$response = Mockery::mock( 'WP_REST_Response' );
			$response->shouldReceive( 'get_data' )->andReturn(
				array(
					'id'                => $id,
					'name'              => self::SECRET,
					'date_modified_gmt' => '2026-10-12T06:30:00',
					'meta_data'         => array(),
				)
			);
			return $response;
		};
		$controller->shouldReceive( 'prepare_object_for_response', 'prepare_item_for_response' )->andReturnUsing( $respond );

		return $controller;
	}

	/**
	 * A strict order mock: the whitelisted getters only, one product line whose name the logs must never show.
	 *
	 * @param int $id Order id.
	 * @return Mockery\MockInterface&\WC_Order
	 */
	private function order( int $id ) {
		$line = Mockery::mock( 'WC_Order_Item_Product' );
		$line->allows( 'get_name' )->andReturn( self::SECRET );
		$line->allows( 'get_product_id' )->andReturn( 5 );
		$line->allows( 'get_variation_id' )->andReturn( 0 );
		$line->allows( 'get_quantity' )->andReturn( 1 );
		$line->allows( 'get_total' )->andReturn( '2400000' );

		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_id' )->andReturn( $id );
		$order->allows( 'get_type' )->andReturn( 'shop_order' );
		$order->allows( 'get_meta' )->with( '_nyxify_order' )->andReturn( in_array( $id, $this->foreign, true ) ? '' : '1' );
		$order->allows( 'get_status' )->andReturn( 'processing' );
		$order->allows( 'get_order_number' )->andReturn( $id );
		$order->allows( 'get_currency' )->andReturn( 'IRT' );
		$order->allows( 'get_total' )->andReturn( '2400000' );
		$order->allows( 'get_date_created' )->andReturn( new \DateTimeImmutable( '2026-10-12 06:00:00' ) );
		$order->allows( 'get_date_modified' )->andReturn( new \DateTimeImmutable( '2026-10-12 06:30:00' ) );
		$order->allows( 'get_date_paid' )->andReturn( null );
		$order->allows( 'get_items' )->with( 'line_item' )->andReturn( array( 9 => $line ) );

		return $order;
	}

	/**
	 * The order the core receives for an order() mock.
	 *
	 * @param int $id Order id.
	 * @return array<string, mixed>
	 */
	private static function order_body( int $id ): array {
		return array(
			'id'                => $id,
			'status'            => 'processing',
			'number'            => (string) $id,
			'currency'          => 'IRT',
			'total'             => '2400000',
			'date_created_gmt'  => '2026-10-12T06:00:00',
			'date_modified_gmt' => '2026-10-12T06:30:00',
			'date_paid_gmt'     => null,
			'line_items'        => array(
				array(
					'name'         => self::SECRET,
					'product_id'   => 5,
					'variation_id' => 0,
					'quantity'     => 1,
					'total'        => '2400000',
				),
			),
		);
	}

	private function flush(): CatalogFlush {
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
		$logger = new Logger( $wc_logger );
		$client = new CoreClient( new SecretStore( Encryptor::from_secret( 'site secret' ) ), self::BASE_URL );

		return new CatalogFlush(
			$this->queue,
			new PayloadBuilder( $logger, fn( string $class_name ): object => $this->controller( $class_name ), static fn(): \WP_REST_Request => Mockery::mock( 'WP_REST_Request' ) ),
			new CatalogSender( $client ),
			new OrderPayloadBuilder( $logger ),
			new OrderSender( $client ),
			new FlushScheduler( fn(): int => $this->now ),
			$logger,
			fn(): bool => $this->connected,
			fn(): int => $this->now,
			function ( int $skipped ): void {
				$this->plan_limits[] = $skipped;
			}
		);
	}

	/**
	 * Paths of the requests sent, in order.
	 *
	 * @return list<string>
	 */
	private function paths(): array {
		return array_column( $this->sent, 'path' );
	}

	/**
	 * The ids a request carried.
	 *
	 * @param int $index Request index.
	 * @return array<int>
	 */
	private function sent_ids( int $index ): array {
		$body = $this->sent[ $index ]['body'];
		if ( isset( $body['product_ids'] ) ) {
			return $body['product_ids'];
		}
		if ( isset( $body['category_ids'] ) ) {
			return $body['category_ids'];
		}
		if ( isset( $body['orders'] ) ) {
			return array_column( $body['orders'], 'id' );
		}

		return array_map( static fn( array $item ): int => $item['product']['id'] ?? $item['id'], $body['items'] );
	}

	/**
	 * Level and message of each log entry.
	 *
	 * @return list<string>
	 */
	private function logged(): array {
		return array_map( static fn( array $entry ): string => $entry['level'] . ':' . $entry['message'], $this->entries );
	}

	/**
	 * No object content in any log entry: no name, status, currency or total.
	 */
	private function assert_logs_hold_no_content(): void {
		$logged = (string) json_encode( $this->entries ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		self::assertStringNotContainsString( self::SECRET, $logged );
		self::assertStringNotContainsString( 'sec_', $logged );
		self::assertStringNotContainsString( 'processing', $logged );
		self::assertStringNotContainsString( 'IRT', $logged );
		self::assertStringNotContainsString( '2400000', $logged );
	}

	public function test_sends_every_kind_in_order_with_exact_bodies(): void {
		$this->queue->enqueue( CatalogQueue::CATEGORY_DELETE, array( 30 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT_DELETE, array( 8 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5, 6 ) );
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );

		$this->flush()->run();

		self::assertSame(
			array( CatalogSender::PATH_CATEGORIES, CatalogSender::PATH_PRODUCTS, CatalogSender::PATH_PRODUCTS_DELETE, CatalogSender::PATH_CATEGORIES_DELETE ),
			$this->paths()
		);
		$item = array(
			'name'              => self::SECRET,
			'date_modified_gmt' => '2026-10-12T06:30:00',
		);
		self::assertSame(
			array(
				'items' => array(
					array(
						'id'   => 22,
						'name' => self::SECRET,
					),
				),
			),
			$this->sent[0]['body']
		);
		self::assertSame(
			array(
				'items' => array(
					array( 'product' => array( 'id' => 5 ) + $item ),
					array( 'product' => array( 'id' => 6 ) + $item ),
				),
			),
			$this->sent[1]['body']
		);
		self::assertSame( array( 'product_ids' => array( 8 ) ), $this->sent[2]['body'] );
		self::assertSame( array( 'category_ids' => array( 30 ) ), $this->sent[3]['body'] );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array(), $this->pending );
		self::assertSame( array( 0 ), $this->plan_limits );
		self::assertSame( 0, $this->status_cleared );
		self::assertSame( array_fill( 0, 4, 'info:Changes sent.' ), $this->logged() );
		self::assertSame(
			array(
				'kind'               => 'product',
				'count'              => 2,
				'request_id'         => 'req-core-0001',
				'applied'            => 1,
				'skipped_stale'      => 0,
				'skipped_plan_limit' => 0,
				'source'             => 'nyxify',
			),
			$this->entries[1]['context']
		);
		$this->assert_logs_hold_no_content();
	}

	public function test_an_empty_queue_sends_nothing(): void {
		$this->flush()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame( array(), $this->pending );
	}

	public function test_without_a_connection_the_queue_is_dropped(): void {
		$this->connected = false;
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->options[ FlushScheduler::OPTION_RETRY_AT ] = self::NOW + 300;

		$this->flush()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame( array(), $this->queue->rows );
		self::assertArrayNotHasKey( FlushScheduler::OPTION_RETRY_AT, $this->options );
	}

	public function test_before_the_backoff_ends_the_run_reschedules_itself_and_sends_nothing(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->options[ FlushScheduler::OPTION_RETRY_AT ] = self::NOW + 300;

		$this->flush()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame( array( self::NOW + 300 ), $this->pending );
		self::assertSame( array( 'product:5' => array( false, 0, 0 ) ), $this->queue->state() );
	}

	public function test_a_success_forgets_the_backoff(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->options[ FlushScheduler::OPTION_RETRY_AT ] = self::NOW;

		$this->flush()->run();

		self::assertArrayNotHasKey( FlushScheduler::OPTION_RETRY_AT, $this->options );
	}

	public function test_plan_limit_skips_are_passed_on_and_clear_the_status_cache(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5, 6, 7 ) );
		$this->answer( CatalogSender::PATH_PRODUCTS, self::response( 200, '{"applied":1,"skipped_stale":[5],"skipped_plan_limit":[6,7]}' ) );

		$this->flush()->run();

		self::assertSame( array( 2 ), $this->plan_limits );
		self::assertSame( 1, $this->status_cleared );
		self::assertSame( 1, $this->entries[0]['context']['skipped_stale'] );
		self::assertSame( 2, $this->entries[0]['context']['skipped_plan_limit'] );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_an_unexpected_2xx_answer_counts_as_delivered(): void {
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );
		$this->answer( CatalogSender::PATH_CATEGORIES, self::response( 200, '{"ok":true}' ) );

		$this->flush()->run();

		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array( 'warning:The core accepted changes, but its answer was unexpected.' ), $this->logged() );
	}

	/**
	 * Retryable failures and the delay they set.
	 *
	 * @return array<string, array{0: array<string, mixed>|\stdClass, 1: int}>
	 */
	public static function retryable_failures(): array {
		return array(
			'network error'         => array( new \stdClass(), 60 ),
			'status 503'            => array( self::response( 503, '<html>busy</html>' ), 60 ),
			'500 with a short wait' => array( self::response( 500, '{"error_code":"internal_error"}', array( 'retry-after' => '10' ) ), 60 ),
			'429 with Retry-After'  => array( self::response( 429, '{"error_code":"rate_limited"}', array( 'retry-after' => '120' ) ), 120 ),
			'status 408'            => array( self::response( 408, '' ), 60 ),
		);
	}

	/**
	 * A retryable failure puts the batch back with one more attempt, sets the backoff, schedules a flush for it and
	 * stops the run.
	 *
	 * @param array<string, mixed>|\stdClass $answer The core's answer.
	 * @param int                            $delay  Expected delay.
	 */
	#[DataProvider( 'retryable_failures' )]
	public function test_a_retryable_failure_backs_off( $answer, int $delay ): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5, 6 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT_DELETE, array( 8 ) );
		$this->answer( CatalogSender::PATH_PRODUCTS, $answer );

		$this->flush()->run();

		self::assertSame( array( CatalogSender::PATH_PRODUCTS ), $this->paths() );
		self::assertSame(
			array(
				'product:5'        => array( false, 0, 1 ),
				'product:6'        => array( false, 0, 1 ),
				'product_delete:8' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( self::NOW + $delay, $this->options[ FlushScheduler::OPTION_RETRY_AT ] );
		self::assertSame( array( self::NOW + $delay ), $this->pending );
		self::assertSame( 'catalog/products', $this->entries[0]['context']['operation'] );
	}

	public function test_the_backoff_follows_the_most_tried_row(): void {
		$this->queue->seed(
			CatalogQueue::PRODUCT,
			array(
				5 => 2,
				6 => 0,
			)
		);
		$this->answer( CatalogSender::PATH_PRODUCTS, self::response( 503, '' ) );

		$this->flush()->run();

		self::assertSame( self::NOW + 900, $this->options[ FlushScheduler::OPTION_RETRY_AT ] );
		self::assertSame(
			array(
				'product:5' => array( false, 0, 3 ),
				'product:6' => array( false, 0, 1 ),
			),
			$this->queue->state()
		);
	}

	/**
	 * The retries of one batch: 1, 5, 15, 60 minutes apart, each attempt a new signed request, then dropped.
	 */
	public function test_a_batch_is_dropped_after_its_fifth_failure(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$waits = array();

		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$this->answer( CatalogSender::PATH_PRODUCTS, self::response( 503, '' ) );
			$this->now = (int) ( $this->options[ FlushScheduler::OPTION_RETRY_AT ] ?? self::NOW );
			$this->flush()->run();
			$waits[] = (int) $this->options[ FlushScheduler::OPTION_RETRY_AT ] - $this->now;
		}

		self::assertSame( array( 60, 300, 900, 3600, 3600 ), $waits );
		self::assertCount( 5, $this->sent );
		self::assertSame( array(), $this->queue->rows );
		self::assertContains( 'warning:Changes were dropped after repeated failures.', $this->logged() );
		self::assertSame(
			array(
				'kind'   => 'product',
				'count'  => 1,
				'source' => 'nyxify',
			),
			$this->entries[ count( $this->entries ) - 1 ]['context']
		);
	}

	/**
	 * Refusals that stop sending.
	 *
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function stopping_refusals(): array {
		return array(
			'no subscription'   => array( 402, 'no_active_subscription' ),
			'store not active'  => array( 403, 'store_not_active' ),
			'bad signature'     => array( 401, 'invalid_request_signature' ),
			'timestamp expired' => array( 401, 'request_timestamp_expired' ),
		);
	}

	/**
	 * A refusal of the store drops the whole queue, clears the status cache and stops the run.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 */
	#[DataProvider( 'stopping_refusals' )]
	public function test_a_refused_store_drops_the_whole_queue( int $status, string $code ): void {
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->queue->enqueue( CatalogQueue::CATEGORY_DELETE, array( 30 ) );
		$this->options[ FlushScheduler::OPTION_RETRY_AT ] = self::NOW - 10;
		$this->answer( CatalogSender::PATH_CATEGORIES, self::response( $status, '{"message":"No.","error_code":"' . $code . '"}' ) );

		$this->flush()->run();

		self::assertSame( array( CatalogSender::PATH_CATEGORIES ), $this->paths() );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( 1, $this->status_cleared );
		self::assertArrayNotHasKey( FlushScheduler::OPTION_RETRY_AT, $this->options );
		self::assertSame( array(), $this->pending );
		self::assertSame( $code, $this->entries[0]['context']['error_code'] );
	}

	public function test_a_refused_batch_is_dropped_with_field_names_only_and_the_run_goes_on(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT_DELETE, array( 8 ) );
		$this->answer(
			CatalogSender::PATH_PRODUCTS,
			self::response( 422, '{"message":"Validation error","error_code":"validation_error","details":[{"field":"body.items.0.product.name","message":"' . self::SECRET . ' too long"}]}' )
		);

		$this->flush()->run();

		self::assertSame( array( CatalogSender::PATH_PRODUCTS, CatalogSender::PATH_PRODUCTS_DELETE ), $this->paths() );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame(
			array(
				'kind'   => 'product',
				'count'  => 1,
				'fields' => 'body.items.0.product.name',
				'source' => 'nyxify',
			),
			$this->entries[1]['context']
		);
		$this->assert_logs_hold_no_content();
	}

	public function test_ids_with_nothing_to_send_complete_without_a_request(): void {
		$this->missing = array( 5, 22 );
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );

		$this->flush()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_more_than_one_batch_is_sent_in_one_run(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, range( 1, 120 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT_DELETE, range( 1001, 1600 ) );

		$this->flush()->run();

		self::assertSame( array( CatalogSender::PATH_PRODUCTS, CatalogSender::PATH_PRODUCTS, CatalogSender::PATH_PRODUCTS_DELETE, CatalogSender::PATH_PRODUCTS_DELETE ), $this->paths() );
		self::assertSame( range( 1, 100 ), $this->sent_ids( 0 ) );
		self::assertSame( range( 101, 120 ), $this->sent_ids( 1 ) );
		self::assertCount( 500, $this->sent_ids( 2 ) );
		self::assertCount( 100, $this->sent_ids( 3 ) );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_a_run_stops_claiming_after_its_budget_and_schedules_the_next(): void {
		$this->request_seconds = 15;
		$this->queue->enqueue( CatalogQueue::PRODUCT, range( 1, 250 ) );

		$this->flush()->run();

		self::assertCount( 2, $this->sent );
		self::assertCount( 50, $this->queue->rows );
		self::assertSame( array( self::NOW + 30 ), $this->pending );
	}

	public function test_an_exception_while_sending_is_logged_by_class_and_the_batch_retried(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception that must not reach the log.
		$this->answer( CatalogSender::PATH_PRODUCTS, new \RuntimeException( self::SECRET ) );

		$this->flush()->run();

		self::assertSame( array( 'error:The flush failed.' ), $this->logged() );
		self::assertSame( 'RuntimeException', $this->entries[0]['context']['exception'] );
		self::assertSame( array( 'product:5' => array( false, 0, 1 ) ), $this->queue->state() );
		self::assertSame( array( self::NOW + 60 ), $this->pending );
		$this->assert_logs_hold_no_content();
	}

	/**
	 * Race 2, success: ids 1 and 2 are claimed; 2 and 3 are queued while the batch is being sent. After the success 1
	 * is gone, 2 (changed after it was read) and 3 wait unclaimed with no attempt, and a flush is scheduled for them.
	 */
	public function test_race_ids_queued_during_a_send_survive_its_success(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 1, 2 ) );
		$this->request_seconds = 25;
		$this->during_send[]   = function (): void {
			$this->queue->enqueue( CatalogQueue::PRODUCT, array( 2, 3 ) );
		};

		$this->flush()->run();

		self::assertSame( array( 1, 2 ), $this->sent_ids( 0 ) );
		self::assertSame(
			array(
				'product:2' => array( false, 0, 0 ),
				'product:3' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( array( self::NOW + 25 ), $this->pending );
	}

	public function test_race_ids_queued_during_a_send_go_in_the_next_batch_of_the_same_run(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 1, 2 ) );
		$this->during_send[] = function (): void {
			$this->queue->enqueue( CatalogQueue::PRODUCT, array( 2, 3 ) );
		};

		$this->flush()->run();

		self::assertSame( array( 1, 2 ), $this->sent_ids( 0 ) );
		self::assertSame( array( 2, 3 ), $this->sent_ids( 1 ) );
		self::assertSame( array(), $this->queue->rows );
	}

	/**
	 * Race 2, retry: the same with a retryable failure: 1, 2 and 3 stay queued, 1 and 2 with one more attempt.
	 */
	public function test_race_ids_queued_during_a_send_survive_its_failure(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 1, 2 ) );
		$this->during_send[] = function (): void {
			$this->queue->enqueue( CatalogQueue::PRODUCT, array( 2, 3 ) );
		};
		$this->answer( CatalogSender::PATH_PRODUCTS, self::response( 503, '' ) );

		$this->flush()->run();

		self::assertSame(
			array(
				'product:1' => array( false, 0, 1 ),
				'product:2' => array( false, 0, 1 ),
				'product:3' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( array( self::NOW + 60 ), $this->pending );
	}

	/**
	 * Race 2, two flushes: a second flush starts while the first is sending. Each claims its own rows: no id is sent
	 * twice and none is left behind.
	 */
	public function test_race_two_flushes_at_once_send_disjoint_ids(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, range( 1, 150 ) );
		$this->during_send[] = function (): void {
			$this->flush()->run();
		};

		$this->flush()->run();

		self::assertCount( 2, $this->sent );
		$first  = $this->sent_ids( 0 );
		$second = $this->sent_ids( 1 );
		self::assertSame( range( 1, 100 ), $first );
		self::assertSame( range( 101, 150 ), $second );
		self::assertSame( array(), array_intersect( $first, $second ) );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_a_claim_abandoned_by_a_dead_flush_is_sent_after_15_minutes(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->queue->claim( CatalogQueue::PRODUCT, 100, CatalogQueue::new_token() );

		$this->flush()->run();
		self::assertSame( array(), $this->sent );

		$this->now += CatalogQueue::CLAIM_TIMEOUT_SECONDS + 1;
		$this->flush()->run();

		self::assertSame( array( 5 ), $this->sent_ids( 0 ) );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_orders_go_first_each_kind_in_its_own_request_with_exact_bodies(): void {
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->queue->enqueue( CatalogQueue::ORDER, array( 61, 59 ) );

		$this->flush()->run();

		self::assertSame(
			array( OrderSender::PATH_STATUS, OrderSender::PATH_STATUS, CatalogSender::PATH_CATEGORIES, CatalogSender::PATH_PRODUCTS ),
			$this->paths()
		);
		self::assertSame( array( 'orders' => array( self::order_body( 61 ), self::order_body( 59 ) ) ), $this->sent[0]['body'] );
		self::assertSame(
			array(
				'orders' => array(
					array(
						'id'     => 70,
						'status' => 'trash',
					),
				),
			),
			$this->sent[1]['body']
		);
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array(), $this->pending );
		self::assertSame( array_fill( 0, 4, 'info:Changes sent.' ), $this->logged() );
		self::assertSame(
			array(
				'kind'       => 'order',
				'count'      => 2,
				'ids'        => '61,59',
				'request_id' => 'req-core-0001',
				'affected'   => 1,
				'source'     => 'nyxify',
			),
			$this->entries[0]['context']
		);
		self::assertSame(
			array(
				'kind'       => 'order_delete',
				'count'      => 1,
				'ids'        => '70',
				'request_id' => 'req-core-0001',
				'affected'   => 1,
				'source'     => 'nyxify',
			),
			$this->entries[1]['context']
		);
		self::assertArrayNotHasKey( 'ids', $this->entries[2]['context'] );
		self::assertArrayNotHasKey( 'ids', $this->entries[3]['context'] );
		$this->assert_logs_hold_no_content();
	}

	public function test_orders_with_nothing_to_send_complete_without_a_request(): void {
		$this->missing = array( 59 );
		$this->foreign = array( 61 );
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59, 61 ) );

		$this->flush()->run();

		self::assertSame( array(), $this->sent );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array(), $this->entries );
	}

	public function test_only_the_orders_with_something_to_send_are_sent_and_logged(): void {
		$this->foreign = array( 61 );
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59, 61, 68 ) );

		$this->flush()->run();

		self::assertSame( array( 59, 68 ), $this->sent_ids( 0 ) );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( '59,68', $this->entries[0]['context']['ids'] );
		self::assertSame( 2, $this->entries[0]['context']['count'] );
	}

	public function test_orders_and_order_deletes_go_100_a_request(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, range( 1, 120 ) );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, range( 1001, 1150 ) );

		$this->flush()->run();

		self::assertSame( array_fill( 0, 4, OrderSender::PATH_STATUS ), $this->paths() );
		self::assertSame( range( 1, 100 ), $this->sent_ids( 0 ) );
		self::assertSame( range( 101, 120 ), $this->sent_ids( 1 ) );
		self::assertSame( range( 1001, 1100 ), $this->sent_ids( 2 ) );
		self::assertSame( range( 1101, 1150 ), $this->sent_ids( 3 ) );
		self::assertSame( array(), $this->queue->rows );
	}

	/**
	 * A retryable failure of an order batch: back with one more attempt, the backoff set, the catalog not sent.
	 *
	 * @param array<string, mixed>|\stdClass $answer The core's answer.
	 * @param int                            $delay  Expected delay.
	 */
	#[DataProvider( 'retryable_failures' )]
	public function test_a_retryable_failure_of_an_order_batch_backs_off( $answer, int $delay ): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59, 61 ) );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );
		$this->answer( OrderSender::PATH_STATUS, $answer );

		$this->flush()->run();

		self::assertSame( array( OrderSender::PATH_STATUS ), $this->paths() );
		self::assertSame(
			array(
				'category:22'     => array( false, 0, 0 ),
				'order:59'        => array( false, 0, 1 ),
				'order:61'        => array( false, 0, 1 ),
				'order_delete:70' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( self::NOW + $delay, $this->options[ FlushScheduler::OPTION_RETRY_AT ] );
		self::assertSame( array( self::NOW + $delay ), $this->pending );
		self::assertSame( 'orders/status', $this->entries[0]['context']['operation'] );
		$this->assert_logs_hold_no_content();
	}

	public function test_an_order_delete_batch_is_retried_and_then_sent(): void {
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->answer( OrderSender::PATH_STATUS, self::response( 503, '' ) );

		$this->flush()->run();
		self::assertSame( array( 'order_delete:70' => array( false, 0, 1 ) ), $this->queue->state() );

		$this->now = (int) $this->options[ FlushScheduler::OPTION_RETRY_AT ];
		$this->flush()->run();

		self::assertSame( array( OrderSender::PATH_STATUS, OrderSender::PATH_STATUS ), $this->paths() );
		self::assertSame( $this->sent[0]['body'], $this->sent[1]['body'] );
		self::assertSame( array(), $this->queue->rows );
		self::assertArrayNotHasKey( FlushScheduler::OPTION_RETRY_AT, $this->options );
	}

	public function test_an_order_batch_is_dropped_after_its_fifth_failure(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59 ) );

		for ( $attempt = 1; $attempt <= 5; $attempt++ ) {
			$this->answer( OrderSender::PATH_STATUS, self::response( 503, '' ) );
			$this->now = (int) ( $this->options[ FlushScheduler::OPTION_RETRY_AT ] ?? self::NOW );
			$this->flush()->run();
		}

		self::assertCount( 5, $this->sent );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame(
			array(
				'kind'   => 'order',
				'count'  => 1,
				'source' => 'nyxify',
			),
			$this->entries[ count( $this->entries ) - 1 ]['context']
		);
	}

	/**
	 * A refusal of the store on an order batch drops the whole queue, the catalog included.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Error code.
	 */
	#[DataProvider( 'stopping_refusals' )]
	public function test_a_refused_store_on_an_order_batch_drops_the_whole_queue( int $status, string $code ): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59 ) );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->answer( OrderSender::PATH_STATUS, self::response( $status, '{"message":"No.","error_code":"' . $code . '"}' ) );

		$this->flush()->run();

		self::assertSame( array( OrderSender::PATH_STATUS ), $this->paths() );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame( 1, $this->status_cleared );
		self::assertSame( array(), $this->pending );
		self::assertSame( 'warning:The core refuses the store; every queued change was dropped.', $this->logged()[1] );
	}

	public function test_a_refused_store_on_a_catalog_batch_drops_the_orders_queued_meanwhile(): void {
		$this->queue->enqueue( CatalogQueue::PRODUCT, array( 5 ) );
		$this->during_send[] = function (): void {
			$this->queue->enqueue( CatalogQueue::ORDER, array( 59 ) );
		};
		$this->answer( CatalogSender::PATH_PRODUCTS, self::response( 402, '{"message":"No.","error_code":"no_active_subscription"}' ) );

		$this->flush()->run();

		self::assertSame( array( CatalogSender::PATH_PRODUCTS ), $this->paths() );
		self::assertSame( array(), $this->queue->rows );
	}

	public function test_a_refused_order_batch_is_dropped_with_field_names_only_and_the_catalog_goes_on(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59 ) );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->queue->enqueue( CatalogQueue::CATEGORY, array( 22 ) );
		$this->answer(
			OrderSender::PATH_STATUS,
			self::response( 422, '{"message":"Validation error","error_code":"validation_error","details":[{"field":"body.orders.0.line_items.0.name","message":"' . self::SECRET . ' too long"}]}' )
		);

		$this->flush()->run();

		self::assertSame( array( OrderSender::PATH_STATUS, OrderSender::PATH_STATUS, CatalogSender::PATH_CATEGORIES ), $this->paths() );
		self::assertSame( array(), $this->queue->rows );
		self::assertSame(
			array(
				'warning:Nyxify core call failed.',
				'warning:The core refused a batch of changes; it was dropped.',
				'info:Changes sent.',
				'info:Changes sent.',
			),
			$this->logged()
		);
		self::assertSame(
			array(
				'kind'   => 'order',
				'count'  => 1,
				'ids'    => '59',
				'fields' => 'body.orders.0.line_items.0.name',
				'source' => 'nyxify',
			),
			$this->entries[1]['context']
		);
		$this->assert_logs_hold_no_content();
	}

	public function test_an_unexpected_2xx_answer_to_an_order_batch_counts_as_delivered(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59 ) );
		$this->answer( OrderSender::PATH_STATUS, self::response( 200, '{"applied":1}' ) );

		$this->flush()->run();

		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array( 'warning:The core accepted changes, but its answer was unexpected.' ), $this->logged() );
		self::assertSame(
			array(
				'kind'       => 'order',
				'count'      => 1,
				'ids'        => '59',
				'request_id' => 'req-core-0001',
				'source'     => 'nyxify',
			),
			$this->entries[0]['context']
		);
	}

	/**
	 * Race 2 for orders, success: 59 and 61 are claimed; 61 (changed again) and 68 are queued while the batch is being
	 * sent. Afterwards 59 is gone, 61 and 68 wait with no attempt, and a flush is scheduled for them.
	 */
	public function test_race_orders_queued_during_a_send_survive_its_success(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59, 61 ) );
		$this->request_seconds = 25;
		$this->during_send[]   = function (): void {
			$this->queue->enqueue( CatalogQueue::ORDER, array( 61, 68 ) );
		};

		$this->flush()->run();

		self::assertSame( array( 59, 61 ), $this->sent_ids( 0 ) );
		self::assertSame(
			array(
				'order:61' => array( false, 0, 0 ),
				'order:68' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( array( self::NOW + 25 ), $this->pending );
	}

	/**
	 * Race 2 for orders, retry: the same with a retryable failure: 59, 61 and 68 stay queued, 59 and 61 with one more
	 * attempt.
	 */
	public function test_race_orders_queued_during_a_send_survive_its_failure(): void {
		$this->queue->enqueue( CatalogQueue::ORDER, array( 59, 61 ) );
		$this->during_send[] = function (): void {
			$this->queue->enqueue( CatalogQueue::ORDER, array( 61, 68 ) );
		};
		$this->answer( OrderSender::PATH_STATUS, new \stdClass() );

		$this->flush()->run();

		self::assertSame(
			array(
				'order:59' => array( false, 0, 1 ),
				'order:61' => array( false, 0, 1 ),
				'order:68' => array( false, 0, 0 ),
			),
			$this->queue->state()
		);
		self::assertSame( array( self::NOW + 60 ), $this->pending );
	}

	public function test_a_deleted_order_queued_as_both_kinds_is_sent_once_as_trash(): void {
		$this->missing = array( 70 );
		$this->queue->enqueue( CatalogQueue::ORDER_DELETE, array( 70 ) );
		$this->queue->enqueue( CatalogQueue::ORDER, array( 70 ) );

		$this->flush()->run();

		self::assertSame( array( OrderSender::PATH_STATUS ), $this->paths() );
		self::assertSame(
			array(
				'orders' => array(
					array(
						'id'     => 70,
						'status' => 'trash',
					),
				),
			),
			$this->sent[0]['body']
		);
		self::assertSame( array(), $this->queue->rows );
	}
}
