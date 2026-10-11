<?php
/**
 * Tests for SyncPull.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Store\RestKeys;
use WooNyx\Features\Store\StatusService;
use WooNyx\Features\Sync\ChangeApplier;
use WooNyx\Features\Sync\OutboxClient;
use WooNyx\Features\Sync\PayloadFilter;
use WooNyx\Features\Sync\PullLock;
use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Features\Sync\SyncPull;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;
use WooNyx\Tests\Unit\EmulatesPrepare;
use WooNyx\Tests\Unit\TestCase;

/**
 * Whole runs with the real client, applier, lock and scheduler: the core over a mocked wp_remote_request(), WooCommerce
 * over a fake REST dispatcher, the lock row and the REST key over a mocked $wpdb, Action Scheduler stubbed. Every step
 * that matters for the order (apply, the deferred product sync, a date read, the ack) is written to $events.
 */
final class SyncPullTest extends TestCase {

	use EmulatesPrepare;

	private const BASE_URL = 'https://core.example.com';
	private const STORE_ID = 'shop_8f2a0c1d3e4b5a69';
	private const NOW      = 1_791_000_000;
	private const LINK     = 'https://bot.example.com:88/media/0123456789abcdef0123456789abcdef.jpg?expires=1&signature=9f8e7d';

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array( RestKeys::OPTION_KEY_ID => 17 );

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array( StatusService::TRANSIENT => array( 'cached' ) );

	/**
	 * Whether the site counts as connected.
	 *
	 * @var bool
	 */
	private bool $connected = true;

	/**
	 * Current Unix time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * Seconds each applied change takes.
	 *
	 * @var int
	 */
	private int $apply_seconds = 1;

	/**
	 * The lock row's value, or null.
	 *
	 * @var string|null
	 */
	private ?string $lock_row = null;

	/**
	 * The pull's answer.
	 *
	 * @var array<string, mixed>|\stdClass
	 */
	private $pull_response;

	/**
	 * The ack's answers, in order (the last one repeats).
	 *
	 * @var list<array<string, mixed>|\stdClass>
	 */
	private array $ack_responses = array();

	/**
	 * Bodies of the acks sent.
	 *
	 * @var list<string>
	 */
	private array $acks = array();

	/**
	 * WooCommerce's answers by route; 200 with the product otherwise.
	 *
	 * @var array<string, array{int, mixed}>
	 */
	private array $woo_answers = array();

	/**
	 * Products' dates as the webhook would send them, by product id.
	 *
	 * @var array<int, string|null>
	 */
	private array $dates = array();

	/**
	 * What the date read throws, if anything.
	 *
	 * @var \Throwable|null
	 */
	private ?\Throwable $date_failure = null;

	/**
	 * Steps in order.
	 *
	 * @var list<string>
	 */
	private array $events = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->pull_response = self::response( 200, self::pulled( array() ) );

		$wpdb          = Mockery::mock( \wpdb::class );
		$wpdb->prefix  = 'wp_';
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $query ) {
				if ( str_contains( $query, 'woocommerce_api_keys' ) ) {
					return '5';
				}
				return $this->lock_row;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing( fn( string $query ): int => $this->lock_query( $query ) );
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.

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
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_remote_request' )->alias( fn( string $url, array $args ) => $this->core( $url, $args ) );
		Functions\when( 'is_wp_error' )->alias( fn( $thing ): bool => $thing instanceof \stdClass );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( fn( array $response ) => $response['response']['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( fn( array $response ) => $response['body'] );
		Functions\when( 'wp_remote_retrieve_header' )->alias(
			fn( array $response, string $header ) => $response['headers'][ strtolower( $header ) ] ?? ''
		);
		Functions\when( 'get_userdata' )->alias( fn( int $id ) => (object) array( 'ID' => $id ) );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_set_current_user' )->alias( fn( int $id ) => (object) array( 'ID' => $id ) );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( string $text ): string => strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook ): int {
				$this->events[] = 'schedule ' . $hook;
				return 3;
			}
		);

		( new SecretStore( Encryptor::from_secret( 'site secret' ) ) )->save_connection( new Connection( self::STORE_ID, 'sec_' . str_repeat( 'cd', 32 ) ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ------------------------------------------------------------------ fakes

	/**
	 * The core: the pull and the ack.
	 *
	 * @param string               $url  URL.
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>|\stdClass
	 */
	private function core( string $url, array $args ) {
		if ( self::BASE_URL . '/api/v1/sync/outbox?limit=20' === $url ) {
			$this->events[] = 'pull';
			return $this->pull_response;
		}

		self::assertSame( self::BASE_URL . '/api/v1/sync/outbox/ack', $url );
		$this->events[] = 'ack';
		$this->acks[]   = (string) $args['body'];

		return count( $this->ack_responses ) > 1 ? array_shift( $this->ack_responses ) : ( $this->ack_responses[0] ?? self::response( 200, '{"affected":1}' ) );
	}

	/**
	 * The lock's statements on the in-memory row.
	 *
	 * @param string $query SQL.
	 */
	private function lock_query( string $query ): int {
		if ( 1 === preg_match( "/^INSERT IGNORE .* VALUES \\('woonyx_sync_lock', '([^']+)', 'no'\\)$/", $query, $m ) ) {
			if ( null !== $this->lock_row ) {
				return 0;
			}
			$this->lock_row = $m[1];
			return 1;
		}

		if ( 1 === preg_match( "/^UPDATE .* SET option_value = '([^']+)' .* AND option_value = '([^']+)'$/", $query, $m ) ) {
			if ( $this->lock_row !== $m[2] ) {
				return 0;
			}
			$this->lock_row = $m[1];
			return 1;
		}

		if ( 1 === preg_match( "/^DELETE .* AND option_value = '([^']+)'$/", $query, $m ) ) {
			$this->events[] = 'release';
			if ( $this->lock_row === $m[1] ) {
				$this->lock_row = null;
				return 1;
			}
			return 0;
		}

		self::fail( 'Unexpected query: ' . $query );
	}

	private function pull(): SyncPull {
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
		$store  = new SecretStore( Encryptor::from_secret( 'site secret' ) );
		$clock  = fn(): int => $this->now;

		$applier = new ChangeApplier(
			new PayloadFilter(),
			new RestKeys(),
			$logger,
			function ( string $method, string $route ): array {
				$this->events[] = 'apply ' . $method . ' ' . $route;
				$this->now     += $this->apply_seconds;
				return $this->woo_answers[ $route ] ?? array( 200, array( 'id' => 1 ) );
			}
		);

		return new SyncPull(
			new OutboxClient( new CoreClient( $store, self::BASE_URL ) ),
			$applier,
			new PullLock( $clock ),
			new PullScheduler( $clock ),
			$logger,
			fn(): bool => $this->connected,
			$clock,
			function (): void {
				$this->events[] = 'finish';
			},
			function ( int $product_id ): ?string {
				$this->events[] = 'date ' . $product_id;
				if ( null !== $this->date_failure ) {
					throw $this->date_failure;
				}
				return $this->dates[ $product_id ] ?? null;
			}
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
			'headers'  => $headers + array( 'x-request-id' => 'req-core-' . $status ),
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
	 * A pull answer.
	 *
	 * @param array<array-key, mixed> $changes Changes.
	 */
	private static function pulled( array $changes ): string {
		return self::json(
			array(
				'changes'       => $changes,
				'claimed_until' => array() === $changes ? null : '2026-10-11T08:20:30Z',
			)
		);
	}

	/**
	 * A pulled change.
	 *
	 * @param int                  $id         Outbox id.
	 * @param string               $action     Action.
	 * @param int|null             $product_id Product id.
	 * @param array<string, mixed> $payload    Payload.
	 * @param int|null             $variation  Variation id.
	 * @return array<string, mixed>
	 */
	private static function change( int $id, string $action, ?int $product_id, array $payload, ?int $variation = null ): array {
		return array(
			'id'               => $id,
			'action'           => $action,
			'woo_product_id'   => $product_id,
			'woo_variation_id' => $variation,
			'payload'          => $payload,
			'created_at'       => '2026-10-11T08:15:30Z',
		);
	}

	/**
	 * Updates of product 13 with ids from 1 to $count.
	 *
	 * @param int $count Number of changes.
	 * @return list<array<string, mixed>>
	 */
	private static function updates( int $count ): array {
		$changes = array();
		for ( $id = 1; $id <= $count; $id++ ) {
			$changes[] = self::change( $id, 'product.update', 13, array( 'stock_quantity' => $id ) );
		}

		return $changes;
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
	 * The entry with a message.
	 *
	 * @param string $message Message.
	 * @return array{level: string, message: string, context: array<string, mixed>}
	 */
	private function entry( string $message ): array {
		foreach ( $this->entries as $entry ) {
			if ( $message === $entry['message'] ) {
				return $entry;
			}
		}

		self::fail( 'Not logged: ' . $message );
	}

	/**
	 * No product content, link, message or secret in any log entry.
	 */
	private function assert_logs_hold_no_content(): void {
		$logged = self::json( $this->entries );

		foreach ( array( 'کفش', 'کیف', 'bot.example.com', 'signature', 'duplicated', 'sec_', 'stock_quantity' ) as $content ) {
			self::assertStringNotContainsString( $content, $logged );
		}
	}

	// ------------------------------------------------------------------ when nothing runs

	public function test_a_site_not_connected_pulls_nothing(): void {
		$this->connected = false;

		$this->pull()->run();

		self::assertSame( array(), $this->events );
		self::assertNull( $this->lock_row );
	}

	public function test_while_paused_nothing_is_pulled(): void {
		$this->options[ PullScheduler::OPTION_RETRY_AT ] = self::NOW + 1;

		$this->pull()->run();

		self::assertSame( array(), $this->events );
	}

	public function test_a_pause_that_ended_pulls_again(): void {
		$this->options[ PullScheduler::OPTION_RETRY_AT ] = self::NOW;

		$this->pull()->run();

		self::assertSame( array( 'pull', 'release' ), $this->events );
	}

	public function test_a_held_lock_ends_the_run_at_once(): void {
		$this->lock_row = str_repeat( 'a', 32 ) . ':' . ( self::NOW + 100 );

		$this->pull()->run();

		self::assertSame( array(), $this->events );
		self::assertSame( str_repeat( 'a', 32 ) . ':' . ( self::NOW + 100 ), $this->lock_row );
		self::assertSame( array( 'debug:Another pull is running.' ), $this->logged() );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		$this->lock_row = str_repeat( 'a', 32 ) . ':' . ( self::NOW - 1 );

		$this->pull()->run();

		self::assertSame( array( 'pull', 'release' ), $this->events );
		self::assertNull( $this->lock_row );
	}

	public function test_an_empty_answer_acknowledges_nothing_and_releases_the_lock(): void {
		$this->pull()->run();

		self::assertSame( array( 'pull', 'release' ), $this->events );
		self::assertNull( $this->lock_row );
		self::assertSame( array( 'debug:Nothing to pull.' ), $this->logged() );
		self::assertSame( 'req-core-200', $this->entries[0]['context']['request_id'] );
	}

	// ------------------------------------------------------------------ a run

	public function test_changes_are_applied_in_order_then_dated_then_acknowledged(): void {
		$this->pull_response = self::response(
			200,
			self::pulled(
				array(
					self::change( 41, 'product.update', 13, array( 'name' => 'کفش راحتی' ) ),
					self::change( 42, 'variation.update', 13, array( 'stock_quantity' => 3 ), 70 ),
					self::change( 43, 'product.create', null, array( 'type' => 'simple', 'name' => 'کیف', 'images' => array( array( 'src' => self::LINK ) ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					self::change( 44, 'product.update', 14, array( 'sku' => 'DUP' ) ),
					self::change( 45, 'product.delete', 15, array() ),
				)
			)
		);
		$this->woo_answers   = array(
			'/wc/v3/products'    => array( 201, array( 'id' => 77 ) ),
			'/wc/v3/products/14' => array(
				400,
				array(
					'code'    => 'product_invalid_sku',
					'message' => 'Invalid or duplicated SKU.',
				),
			),
			'/wc/v3/products/15' => array(
				410,
				array(
					'code'    => 'woocommerce_rest_already_trashed',
					'message' => 'The product has already been deleted.',
				),
			),
		);
		$this->dates         = array(
			13 => '2026-10-11T08:16:05',
			77 => '2026-10-11T08:16:06',
			15 => '2026-10-11T08:16:07',
		);
		$this->ack_responses = array( self::response( 200, '{"affected":5}' ) );

		$this->pull()->run();

		self::assertSame(
			array(
				'pull',
				'apply PUT /wc/v3/products/13',
				'apply PUT /wc/v3/products/13/variations/70',
				'apply POST /wc/v3/products',
				'apply PUT /wc/v3/products/14',
				'apply DELETE /wc/v3/products/15',
				'finish',
				'date 13',
				'date 77',
				'date 15',
				'ack',
				'release',
			),
			$this->events
		);
		self::assertSame(
			array(
				'{"results":['
					. '{"id":41,"success":true,"woo_product_id":13,"date_modified_gmt":"2026-10-11T08:16:05"},'
					. '{"id":42,"success":true,"woo_product_id":13,"date_modified_gmt":"2026-10-11T08:16:05"},'
					. '{"id":43,"success":true,"woo_product_id":77,"date_modified_gmt":"2026-10-11T08:16:06"},'
					. '{"id":44,"success":false,"error_code":"product_invalid_sku","error_message":"Invalid or duplicated SKU."},'
					. '{"id":45,"success":true,"woo_product_id":15,"date_modified_gmt":"2026-10-11T08:16:07"}'
					. ']}',
			),
			$this->acks
		);
		self::assertNull( $this->lock_row );

		self::assertSame( array( 'warning:A pulled change was not applied.', 'info:Pulled changes applied.' ), $this->logged() );
		self::assertSame(
			array(
				'change_id'  => 44,
				'action'     => 'product.update',
				'error_code' => 'product_invalid_sku',
				'source'     => 'woonyx',
			),
			$this->entries[0]['context']
		);
		self::assertSame(
			array(
				'pulled'         => 5,
				'applied'        => 4,
				'failed'         => 1,
				'not_started'    => 0,
				'request_id'     => 'req-core-200',
				'ack_request_id' => 'req-core-200',
				'affected'       => 5,
				'source'         => 'woonyx',
			),
			$this->entries[1]['context']
		);
		$this->assert_logs_hold_no_content();
	}

	public function test_a_product_gone_before_its_date_is_read_is_acknowledged_without_one(): void {
		$this->pull_response = self::response( 200, self::pulled( array( self::change( 41, 'product.delete', 13, array() ) ) ) );

		$this->pull()->run();

		self::assertSame( array( '{"results":[{"id":41,"success":true,"woo_product_id":13}]}' ), $this->acks );
	}

	public function test_no_change_starts_after_the_budget(): void {
		$this->apply_seconds = 8;
		$this->pull_response = self::response( 200, self::pulled( self::updates( 5 ) ) );

		$this->pull()->run();

		// Started at 0, 8 and 16 seconds; the fourth would start at 24.
		self::assertCount( 3, array_filter( $this->events, static fn( string $event ): bool => str_starts_with( $event, 'apply' ) ) );
		self::assertSame( array( 1, 2, 3 ), array_column( json_decode( $this->acks[0], true )['results'], 'id' ) );
		self::assertSame( 2, $this->entry( 'Pulled changes applied.' )['context']['not_started'] );
	}

	/**
	 * Answer sizes and whether the next pull is scheduled at once.
	 *
	 * @return array<string, array{int, int, bool}>
	 */
	public static function follow_ups(): array {
		return array(
			'full'                => array( 20, 0, true ),
			'not full'            => array( 19, 0, false ),
			'full, budget passed' => array( 20, 2, false ),
		);
	}

	#[DataProvider( 'follow_ups' )]
	public function test_a_full_answer_schedules_the_next_pull_at_once( int $count, int $apply_seconds, bool $scheduled ): void {
		$this->apply_seconds = $apply_seconds;
		$this->pull_response = self::response( 200, self::pulled( self::updates( $count ) ) );

		$this->pull()->run();

		self::assertSame( $scheduled, in_array( 'schedule ' . PullScheduler::NOW_HOOK, $this->events, true ) );
		self::assertSame( 'release', end( $this->events ) );
	}

	public function test_a_full_answer_whose_ack_failed_schedules_nothing(): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 20 ) ) );
		$this->ack_responses = array( self::response( 422, '{"message":"Invalid","error_code":"validation_error"}' ) );

		$this->pull()->run();

		self::assertNotContains( 'schedule ' . PullScheduler::NOW_HOOK, $this->events );
	}

	// ------------------------------------------------------------------ the ack

	/**
	 * Ack failures retried once.
	 *
	 * @return array<string, array{array<string, mixed>|\stdClass}>
	 */
	public static function transient_ack_failures(): array {
		return array(
			'network' => array( new \stdClass() ),
			'busy'    => array( self::response( 503, '{"message":"Busy","error_code":"service_unavailable"}' ) ),
			'oops'    => array( self::response( 500, '{"message":"Oops","error_code":"internal_server_error"}' ) ),
		);
	}

	#[DataProvider( 'transient_ack_failures' )]
	public function test_the_ack_is_sent_once_more_after_a_transient_failure( mixed $failure ): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 1 ) ) );
		$this->ack_responses = array( $failure, self::response( 200, '{"affected":1}' ) );

		$this->pull()->run();

		self::assertCount( 2, $this->acks );
		self::assertSame( $this->acks[0], $this->acks[1] );
		self::assertSame( array( 'error:WooNyx core call failed.', 'info:Pulled changes applied.' ), $this->logged() );
		self::assertSame( 1, $this->entry( 'Pulled changes applied.' )['context']['affected'] );
	}

	public function test_an_ack_failing_twice_is_logged_and_the_changes_come_back_later(): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 1 ) ) );
		$this->ack_responses = array( new \stdClass() );

		$this->pull()->run();

		self::assertCount( 2, $this->acks );
		self::assertSame( array( 'error:WooNyx core call failed.', 'error:WooNyx core call failed.', 'info:Pulled changes applied.' ), $this->logged() );
		self::assertArrayNotHasKey( 'affected', $this->entry( 'Pulled changes applied.' )['context'] );
		self::assertArrayNotHasKey( PullScheduler::OPTION_RETRY_AT, $this->options );
		self::assertNull( $this->lock_row );
	}

	public function test_an_ack_refused_otherwise_is_not_sent_again(): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 1 ) ) );
		$this->ack_responses = array( self::response( 400, '{"message":"Bad","error_code":"bad_request"}' ) );

		$this->pull()->run();

		self::assertCount( 1, $this->acks );
		self::assertSame( array( 'warning:WooNyx core call failed.', 'info:Pulled changes applied.' ), $this->logged() );
		self::assertArrayNotHasKey( 'affected', $this->entry( 'Pulled changes applied.' )['context'] );
		self::assertArrayNotHasKey( PullScheduler::OPTION_RETRY_AT, $this->options );
	}

	// ------------------------------------------------------------------ the pause

	/**
	 * Refusals that pause pulls.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function stop_codes(): array {
		return array(
			'no subscription' => array( 403, 'no_active_subscription' ),
			'not active'      => array( 403, 'store_not_active' ),
			'bad signature'   => array( 401, 'invalid_request_signature' ),
			'replayed'        => array( 401, 'replayed_request' ),
		);
	}

	#[DataProvider( 'stop_codes' )]
	public function test_a_pull_refused_for_the_store_pauses_pulls_for_an_hour( int $status, string $code ): void {
		$this->pull_response = self::response( $status, self::json( array( 'message' => 'No.', 'error_code' => $code ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->pull()->run();

		self::assertSame( self::NOW + SyncPull::PAUSE_SECONDS, $this->options[ PullScheduler::OPTION_RETRY_AT ] );
		self::assertArrayNotHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertSame( array( 'warning:WooNyx core call failed.', 'warning:The core refuses the store; pulls pause for an hour.' ), $this->logged() );
		self::assertSame( SyncPull::OPERATION_PULL, $this->entries[0]['context']['operation'] );
		self::assertSame( array( 'pull', 'release' ), $this->events );
	}

	#[DataProvider( 'stop_codes' )]
	public function test_an_ack_refused_for_the_store_pauses_pulls_too( int $status, string $code ): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 1 ) ) );
		$this->ack_responses = array( self::response( $status, self::json( array( 'message' => 'No.', 'error_code' => $code ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->pull()->run();

		self::assertCount( 1, $this->acks );
		self::assertSame( self::NOW + 1 + SyncPull::PAUSE_SECONDS, $this->options[ PullScheduler::OPTION_RETRY_AT ] );
	}

	/**
	 * Pull failures that only wait for the next interval.
	 *
	 * @return array<string, array{array<string, mixed>|\stdClass}>
	 */
	public static function other_pull_failures(): array {
		return array(
			'network'      => array( new \stdClass() ),
			'server error' => array( self::response( 500, '{"message":"Oops","error_code":"internal_server_error"}' ) ),
			'rate limited' => array( self::response( 429, '{"message":"Slow","error_code":"rate_limited"}', array( 'retry-after' => '600' ) ) ),
			'invalid'      => array( self::response( 200, '{"changes":"none"}' ) ),
		);
	}

	#[DataProvider( 'other_pull_failures' )]
	public function test_other_pull_failures_wait_for_the_next_interval( mixed $failure ): void {
		$this->pull_response = $failure;

		$this->pull()->run();

		self::assertArrayNotHasKey( PullScheduler::OPTION_RETRY_AT, $this->options );
		self::assertArrayHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertSame( array( 'pull', 'release' ), $this->events );
		self::assertCount( 1, $this->entries );
		self::assertSame( 'WooNyx core call failed.', $this->entries[0]['message'] );
	}

	// ------------------------------------------------------------------ failures

	public function test_an_exception_is_logged_by_class_and_the_lock_released(): void {
		$this->pull_response = self::response( 200, self::pulled( self::updates( 1 ) ) );
		$this->date_failure  = new \RuntimeException( 'secret detail of کفش' );

		$this->pull()->run();

		self::assertSame( array(), $this->acks );
		self::assertNull( $this->lock_row );
		self::assertSame( 'release', end( $this->events ) );
		self::assertSame( array( 'error:The pull failed.' ), $this->logged() );
		self::assertSame(
			array(
				'exception' => 'RuntimeException',
				'source'    => 'woonyx',
			),
			$this->entries[0]['context']
		);
		self::assertStringNotContainsString( 'secret detail', self::json( $this->entries ) );
	}

	public function test_runs_one_after_another_each_take_the_lock(): void {
		$this->pull()->run();
		$this->pull()->run();

		self::assertSame( array( 'pull', 'release', 'pull', 'release' ), $this->events );
	}

	public function test_the_hooks_are_the_schedulers(): void {
		self::assertSame( 'woonyx_sync_pull', PullScheduler::HOOK );
		self::assertSame( 'woonyx_sync_pull_now', PullScheduler::NOW_HOOK );
		self::assertSame( 20, OutboxClient::LIMIT );
	}
}
