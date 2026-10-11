<?php
/**
 * Tests for OrderCollector.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Orders;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\ChangeCollector;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PendingChanges;
use Nyxify\Features\Catalog\Queue;
use Nyxify\Features\Orders\OrderCollector;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\Features\Catalog\InMemoryQueue;
use Nyxify\Tests\Unit\TestCase;

/**
 * The collector over orders that are strict mocks (only `get_id()`, `get_type()` and the marker can be read, so a
 * test fails if the collector touches anything else), the request's PendingChanges writing to an in-memory queue, and
 * Action Scheduler functions that keep pending actions in memory.
 */
final class OrderCollectorTest extends TestCase {

	private const NOW = 1791892800;

	/**
	 * Orders wc_get_order() returns: id => order.
	 *
	 * @var array<int, MockInterface>
	 */
	private array $orders = array();

	/**
	 * Ids passed to wc_get_order().
	 *
	 * @var list<mixed>
	 */
	private array $reads = array();

	/**
	 * Post types: id => type.
	 *
	 * @var array<int, string>
	 */
	private array $post_types = array(
		5  => 'shop_order',
		7  => 'shop_order',
		20 => 'product',
		21 => 'shop_order_placehold',
		22 => 'shop_order_refund',
		23 => 'page',
	);

	/**
	 * Pending flushes: list of [timestamp, hook, args, group, unique].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $scheduled = array();

	/**
	 * Whether the site has a readable connection.
	 *
	 * @var bool
	 */
	private bool $connected = true;

	/**
	 * The site's queue.
	 *
	 * @var InMemoryQueue
	 */
	private InMemoryQueue $queue;

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->queue  = new InMemoryQueue( static fn(): int => self::NOW );
		$this->orders = array(
			5  => self::order( 5 ),
			6  => self::order( 6 ),
			7  => self::order( 7, 'shop_order', '' ),
			8  => self::order( 8, 'shop_order', array( '1' ) ),
			9  => self::order( 9, 'shop_subscription' ),
			10 => self::refund( 10 ),
		);

		Functions\when( 'wc_get_order' )->alias(
			function ( $id ) {
				$this->reads[] = $id;
				return $this->orders[ $id ] ?? false;
			}
		);
		Functions\when( 'get_post_type' )->alias( fn( int $id ) => $this->post_types[ $id ] ?? false );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'as_get_scheduled_actions' )->alias( fn(): array => array_keys( $this->scheduled ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args, string $group, bool $unique ): int {
				$this->scheduled[] = array( $timestamp, $hook, $args, $group, $unique );
				return count( $this->scheduled );
			}
		);
	}

	/**
	 * An order that can only tell its id, type and marker.
	 *
	 * @param int    $id     Id.
	 * @param string $type   Order type.
	 * @param mixed  $marker Value of `_nyxify_order`.
	 * @return MockInterface&\WC_Order
	 */
	private static function order( int $id, string $type = 'shop_order', $marker = '1' ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->allows( 'get_id' )->andReturn( $id );
		$order->allows( 'get_type' )->andReturn( $type );
		$order->allows( 'get_meta' )->with( '_nyxify_order' )->andReturn( $marker );

		return $order;
	}

	/**
	 * A refund (WooCommerce's refunds aren't WC_Order objects).
	 *
	 * @param int $id Id.
	 * @return MockInterface
	 */
	private static function refund( int $id ) {
		$refund = Mockery::mock( 'WC_Order_Refund' );
		$refund->allows( 'get_id' )->andReturn( $id );
		$refund->allows( 'get_type' )->andReturn( 'shop_order_refund' );

		return $refund;
	}

	private function pending( ?Queue $queue = null ): PendingChanges {
		$queue ??= $this->queue;

		return new PendingChanges(
			new FlushScheduler( static fn(): int => self::NOW ),
			new Logger( $this->recording_logger() ),
			fn(): bool => $this->connected,
			static fn(): Queue => $queue
		);
	}

	/**
	 * The queued rows, sorted, as "kind:id".
	 *
	 * @return list<string>
	 */
	private function queued(): array {
		$keys = array_keys( $this->queue->rows );
		sort( $keys );

		return $keys;
	}

	public function test_registers_every_hook(): void {
		$collector = new OrderCollector( $this->pending() );
		$collector->register();

		foreach ( array( 'woocommerce_new_order', 'woocommerce_update_order', 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_order_refunded' ) as $hook ) {
			self::assertSame( 10, has_action( $hook, array( $collector, 'order_changed' ) ), $hook );
		}
		self::assertSame( 10, has_action( 'woocommerce_order_status_changed', array( $collector, 'status_changed' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_before_delete_order', array( $collector, 'order_deleting' ) ) );
		self::assertSame( 10, has_action( 'trashed_post', array( $collector, 'post_changed' ) ) );
		self::assertSame( 10, has_action( 'untrashed_post', array( $collector, 'post_changed' ) ) );
		self::assertSame( 10, has_action( 'before_delete_post', array( $collector, 'post_deleting' ) ) );
		self::assertFalse( has_action( 'shutdown' ) );
	}

	/**
	 * Hook arguments and what is queued and read for each.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: list<string>, 3: list<int>}>
	 */
	public static function changed_orders(): array {
		return array(
			'nyxify order passed by the hook'  => array( 5, 'same', array( 'order:5' ), array() ),
			'id as a string'                   => array( '5', 'same', array( 'order:5' ), array() ),
			'nyxify order read'                => array( 5, 'none', array( 'order:5' ), array( 5 ) ),
			'another order passed'             => array( 5, 'other', array( 'order:5' ), array( 5 ) ),
			'refund id as the second argument' => array( 5, 'refund id', array( 'order:5' ), array( 5 ) ),
			'previous status as the second'    => array( 5, 'status', array( 'order:5' ), array( 5 ) ),
			'order not created by nyxify'      => array( 7, 'same', array(), array() ),
			'marker that is not a scalar'      => array( 8, 'same', array(), array() ),
			'another order type'               => array( 9, 'same', array(), array() ),
			'a refund'                         => array( 10, 'none', array(), array( 10 ) ),
			'missing order'                    => array( 99, 'none', array(), array( 99 ) ),
			'zero'                             => array( 0, 'none', array(), array() ),
			'negative'                         => array( -5, 'none', array(), array() ),
			'not a number'                     => array( '5abc', 'none', array(), array() ),
			'null'                             => array( null, 'none', array(), array() ),
		);
	}

	/**
	 * The second argument a hook passes.
	 *
	 * @param mixed  $order_id Order id argument.
	 * @param string $passed   What the hook passes besides the id.
	 * @return mixed
	 */
	private function passed( $order_id, string $passed ) {
		switch ( $passed ) {
			case 'same':
				return $this->orders[ (int) $order_id ];
			case 'other':
				return $this->orders[6];
			case 'refund id':
				return 10;
			case 'status':
				return 'wc-processing';
			default:
				return null;
		}
	}

	/**
	 * An order created, saved, trashed, restored or refunded.
	 *
	 * @param mixed        $order_id Hook argument.
	 * @param string       $passed   What the hook passes besides the id.
	 * @param list<string> $expected Queued rows.
	 * @param list<int>    $reads    Ids read with wc_get_order().
	 */
	#[DataProvider( 'changed_orders' )]
	public function test_a_changed_order( $order_id, string $passed, array $expected, array $reads ): void {
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->order_changed( $order_id, $this->passed( $order_id, $passed ) );
		$pending->write();

		self::assertSame( $expected, $this->queued() );
		self::assertSame( $reads, $this->reads );
	}

	public function test_a_hook_passing_only_the_id_reads_the_order(): void {
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->order_changed( 5 );
		$pending->write();

		self::assertSame( array( 'order:5' ), $this->queued() );
		self::assertSame( array( 5 ), $this->reads );
	}

	public function test_a_status_change_uses_the_order_the_hook_passes(): void {
		$pending   = $this->pending();
		$collector = new OrderCollector( $pending );

		$collector->status_changed( 5, 'pending', 'processing', $this->orders[5] );
		$collector->status_changed( 7, 'pending', 'processing', $this->orders[7] );
		$pending->write();

		self::assertSame( array( 'order:5' ), $this->queued() );
		self::assertSame( array(), $this->reads );
	}

	/**
	 * Orders about to be deleted and what is queued and read for each.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: list<string>, 3: list<int>}>
	 */
	public static function deleted_orders(): array {
		return array(
			'nyxify order passed by the hook' => array( 5, 'same', array( 'order_delete:5' ), array() ),
			'nyxify order read'               => array( 5, 'none', array( 'order_delete:5' ), array( 5 ) ),
			'order not created by nyxify'     => array( 7, 'same', array(), array() ),
			'another order type'              => array( 9, 'same', array(), array() ),
			'a refund'                        => array( 10, 'none', array(), array( 10 ) ),
			'missing order'                   => array( 99, 'none', array(), array( 99 ) ),
			'not a number'                    => array( 'x', 'none', array(), array() ),
		);
	}

	/**
	 * An order about to be deleted permanently (`woocommerce_before_delete_order`).
	 *
	 * @param mixed        $order_id Hook argument.
	 * @param string       $passed   What the hook passes besides the id.
	 * @param list<string> $expected Queued rows.
	 * @param list<int>    $reads    Ids read with wc_get_order().
	 */
	#[DataProvider( 'deleted_orders' )]
	public function test_a_deleted_order( $order_id, string $passed, array $expected, array $reads ): void {
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->order_deleting( $order_id, $this->passed( $order_id, $passed ) );
		$pending->write();

		self::assertSame( $expected, $this->queued() );
		self::assertSame( $reads, $this->reads );
	}

	/**
	 * Posts trashed, restored or deleted, and the order behind each (posts storage).
	 *
	 * @return array<string, array{0: mixed, 1: list<int>, 2: bool}>
	 */
	public static function posts(): array {
		return array(
			'nyxify order'                => array( 5, array( 5 ), true ),
			'id as a string'              => array( '5', array( 5 ), true ),
			'order not created by nyxify' => array( 7, array( 7 ), false ),
			'product'                     => array( 20, array(), false ),
			'hpos placeholder'            => array( 21, array(), false ),
			'refund post'                 => array( 22, array(), false ),
			'page'                        => array( 23, array(), false ),
			'missing post'                => array( 99, array(), false ),
			'zero'                        => array( 0, array(), false ),
			'not a number'                => array( 'x', array(), false ),
		);
	}

	/**
	 * A post trashed or restored from the posts list.
	 *
	 * @param mixed     $post_id Hook argument.
	 * @param list<int> $reads   Ids read with wc_get_order().
	 * @param bool      $queued  Whether the order is queued.
	 */
	#[DataProvider( 'posts' )]
	public function test_a_trashed_or_restored_post( $post_id, array $reads, bool $queued ): void {
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->post_changed( $post_id );
		$pending->write();

		self::assertSame( $queued ? array( 'order:5' ) : array(), $this->queued() );
		self::assertSame( $reads, $this->reads );
	}

	/**
	 * A post deleted from the posts list.
	 *
	 * @param mixed     $post_id Hook argument.
	 * @param list<int> $reads   Ids read with wc_get_order().
	 * @param bool      $queued  Whether the order is queued for deletion.
	 */
	#[DataProvider( 'posts' )]
	public function test_a_deleted_post( $post_id, array $reads, bool $queued ): void {
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->post_deleting( $post_id );
		$pending->write();

		self::assertSame( $queued ? array( 'order_delete:5' ) : array(), $this->queued() );
		self::assertSame( $reads, $this->reads );
	}

	public function test_every_hook_of_one_change_queues_the_order_once(): void {
		$pending   = $this->pending();
		$collector = new OrderCollector( $pending );

		$collector->order_changed( 5, $this->orders[5] );
		$collector->status_changed( 5, 'pending', 'processing', $this->orders[5] );
		$collector->order_changed( 5, 10 );
		$collector->post_changed( 5 );
		$pending->write();

		self::assertSame( array( 'order:5' ), $this->queued() );
		self::assertCount( 1, $this->scheduled );
	}

	public function test_orders_and_catalog_changes_of_one_request_are_written_together_orders_first(): void {
		$pending = $this->pending();
		$orders  = new OrderCollector( $pending );
		$catalog = new ChangeCollector( $pending );

		$catalog->post_changed( 20 );
		$orders->order_deleting( 6, $this->orders[6] );
		$orders->order_changed( 5, $this->orders[5] );
		$pending->write();

		$rows = $this->queue->rows;
		uasort( $rows, static fn( array $a, array $b ): int => $a['queued_at'] <=> $b['queued_at'] );
		self::assertSame( array( 'order:5', 'order_delete:6', 'product:20' ), array_keys( $rows ) );
		self::assertSame( array( array( self::NOW, 'nyxify_catalog_flush', array(), 'nyxify', false ) ), $this->scheduled );
		self::assertSame( array(), $this->entries );
	}

	public function test_without_a_connection_nothing_is_queued_or_scheduled(): void {
		$this->connected = false;
		$pending         = $this->pending();

		( new OrderCollector( $pending ) )->order_changed( 5, $this->orders[5] );
		$pending->write();

		self::assertSame( array(), $this->queue->rows );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_nothing_but_the_type_and_the_marker_is_read_from_an_order(): void {
		$order = Mockery::mock( 'WC_Order' );
		$order->expects( 'get_id' )->twice()->andReturn( 5 );
		$order->expects( 'get_type' )->twice()->andReturn( 'shop_order' );
		$order->expects( 'get_meta' )->twice()->with( '_nyxify_order' )->andReturn( '1' );
		$order->shouldNotReceive( 'get_data', 'get_meta_data', 'get_billing_first_name', 'get_billing_phone', 'get_billing_email', 'get_shipping_address_1', 'get_customer_note', 'get_payment_method', 'get_checkout_payment_url', 'get_items', 'get_status', 'get_total' );
		$pending   = $this->pending();
		$collector = new OrderCollector( $pending );

		$collector->order_changed( 5, $order );
		$collector->order_deleting( 5, $order );
		$pending->write();

		self::assertSame( array( 'order:5', 'order_delete:5' ), $this->queued() );
		self::assertSame( array(), $this->entries );
	}

	/**
	 * Which of the two requests writes first.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function write_orders(): array {
		return array(
			'first collected writes last'  => array( true ),
			'first collected writes first' => array( false ),
		);
	}

	/**
	 * Race: two requests change overlapping orders at once and write in either order; every id of both is queued,
	 * one flush is scheduled and the second request finds it pending.
	 *
	 * @param bool $reverse Whether the request that collected first writes last.
	 */
	#[DataProvider( 'write_orders' )]
	public function test_race_two_requests_writing_at_once_keep_every_order( bool $reverse ): void {
		$first_pending  = $this->pending();
		$second_pending = $this->pending();
		$first          = new OrderCollector( $first_pending );
		$second         = new OrderCollector( $second_pending );

		$first->order_changed( 5, $this->orders[5] );
		$second->order_changed( 6, $this->orders[6] );
		$second->order_changed( 5, $this->orders[5] );
		$first->order_deleting( 6, $this->orders[6] );

		$writers = $reverse ? array( $second_pending, $first_pending ) : array( $first_pending, $second_pending );
		foreach ( $writers as $writer ) {
			$writer->write();
		}

		self::assertSame( array( 'order:5', 'order:6', 'order_delete:6' ), $this->queued() );
		self::assertCount( 1, $this->scheduled );
	}

	public function test_race_an_order_changed_while_a_flush_holds_it_is_marked_dirty_not_lost(): void {
		$this->queue->enqueue( 'order', array( 5 ) );
		$this->queue->claim( 'order', 100, str_repeat( 'a', 32 ) );
		$pending = $this->pending();

		( new OrderCollector( $pending ) )->order_changed( 5, $this->orders[5] );
		$pending->write();

		self::assertSame( array( 'order:5' => array( true, 1, 0 ) ), $this->queue->state() );
		self::assertCount( 1, $this->scheduled );
	}

	/**
	 * A WooCommerce logger that records into $entries.
	 *
	 * @return \WC_Logger_Interface&MockInterface
	 */
	private function recording_logger() {
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

		return $wc_logger;
	}
}
