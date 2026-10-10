<?php
/**
 * Tests for ChangeCollector.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\CatalogQueue;
use WooNyx\Features\Catalog\ChangeCollector;
use WooNyx\Features\Catalog\FlushScheduler;
use WooNyx\Features\Catalog\PendingChanges;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Tests\Unit\EmulatesPrepare;
use WooNyx\Tests\Unit\TestCase;

/**
 * The collector and the request's PendingChanges over in-memory posts, a mocked $wpdb that applies the queue's INSERT … ON DUPLICATE KEY UPDATE to
 * in-memory tables (one per site), Action Scheduler functions that keep pending actions in memory, and a logger that
 * records its entries.
 */
final class ChangeCollectorTest extends TestCase {

	use EmulatesPrepare;

	private const NOW = 1791892800;

	private const INSERT_PATTERN = '/^INSERT INTO `([a-z0-9_]+)` \\(kind, object_id, queued_at\\) VALUES (.+) ON DUPLICATE KEY UPDATE dirty = IF\\(claim IS NULL, dirty, 1\\)$/';

	/**
	 * Posts: id => [type, status, parent].
	 *
	 * @var array<int, array{0: string, 1: string, 2: int}>
	 */
	private array $posts = array();

	/**
	 * Queue tables: table => "kind:id" => row.
	 *
	 * @var array<string, array<string, array{claim: string|null, dirty: int}>>
	 */
	private array $tables = array();

	/**
	 * Every query sent to $wpdb->query().
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Whether $wpdb->query() fails.
	 *
	 * @var bool
	 */
	private bool $query_fails = false;

	/**
	 * Pending flushes: site id => list of [timestamp, hook, args, group, unique].
	 *
	 * @var array<int, list<array<int, mixed>>>
	 */
	private array $scheduled = array();

	/**
	 * Arguments of each as_get_scheduled_actions() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $pending_queries = array();

	/**
	 * Whether each site has a readable connection: site id => bool.
	 *
	 * @var array<int, bool>
	 */
	private array $connected = array(
		1 => true,
		2 => true,
	);

	/**
	 * The current site, and the sites switched from.
	 *
	 * @var list<int>
	 */
	private array $site_stack = array( 1 );

	/**
	 * Arguments of each get_terms() call.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $term_queries = array();

	/**
	 * Log entries.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	/**
	 * Each collector's PendingChanges, by the collector's object id.
	 *
	 * @var array<int, PendingChanges>
	 */
	private array $pending = array();

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->posts = array(
			5  => array( 'product', 'publish', 0 ),
			6  => array( 'product', 'draft', 0 ),
			7  => array( 'product_variation', 'publish', 5 ),
			8  => array( 'product', 'trash', 0 ),
			9  => array( 'product', 'auto-draft', 0 ),
			10 => array( 'product_variation', 'private', 9 ),
			11 => array( 'product_variation', 'publish', 0 ),
			12 => array( 'revision', 'inherit', 5 ),
			13 => array( 'page', 'publish', 0 ),
			14 => array( 'post', 'publish', 0 ),
			15 => array( 'shop_order', 'wc-processing', 0 ),
		);

		Functions\when( 'get_post_type' )->alias( fn( int $id ) => isset( $this->posts[ $id ] ) ? $this->posts[ $id ][0] : false );
		Functions\when( 'get_post_status' )->alias( fn( int $id ) => isset( $this->posts[ $id ] ) ? $this->posts[ $id ][1] : false );
		Functions\when( 'wp_get_post_parent_id' )->alias( fn( int $id ) => isset( $this->posts[ $id ] ) ? $this->posts[ $id ][2] : false );
		Functions\when( 'get_terms' )->alias(
			function ( array $args ): array {
				$this->term_queries[] = $args;
				return array( 23, '24' );
			}
		);
		Functions\when( 'get_current_blog_id' )->alias( fn(): int => $this->site() );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'switch_to_blog' )->alias(
			function ( int $site_id ): bool {
				$this->site_stack[] = $site_id;
				$this->wpdb->prefix = $this->prefix();
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function (): bool {
				array_pop( $this->site_stack );
				$this->wpdb->prefix = $this->prefix();
				return true;
			}
		);
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args, string $format ): array {
				$this->pending_queries[] = $args;
				self::assertSame( 'ids', $format );
				return array_keys( $this->scheduled[ $this->site() ] ?? array() );
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args, string $group, bool $unique ): int {
				$this->scheduled[ $this->site() ][] = array( $timestamp, $hook, $args, $group, $unique );
				return 100 + count( $this->scheduled[ $this->site() ] );
			}
		);

		$this->wpdb         = Mockery::mock( \wpdb::class );
		$this->wpdb->prefix = $this->prefix();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing( fn( string $query ) => $this->run_query( $query ) );
		$this->wpdb->shouldNotReceive( 'get_results', 'get_var', 'get_row', 'get_col' );
	}

	private function site(): int {
		return $this->site_stack[ count( $this->site_stack ) - 1 ];
	}

	private function prefix(): string {
		return 1 === $this->site() ? 'wp_' : 'wp_' . $this->site() . '_';
	}

	/**
	 * Applies an INSERT … ON DUPLICATE KEY UPDATE of the queue to the in-memory tables, as MySQL does.
	 *
	 * @param string $query Prepared query.
	 * @return int|false Rows affected.
	 */
	private function run_query( string $query ) {
		$this->queries[] = $query;
		if ( $this->query_fails ) {
			return false;
		}

		if ( 1 !== preg_match( self::INSERT_PATTERN, $query, $insert ) ) {
			self::fail( 'Not the queue INSERT: ' . $query );
		}
		preg_match_all( "/\\('([a-z_]+)', (\\d+), '2026-10-13 12:00:00'\\)/", $insert[2], $rows, PREG_SET_ORDER );
		self::assertCount( substr_count( $insert[2], '(' ), $rows );

		$affected = 0;
		foreach ( $rows as $row ) {
			$key = $row[1] . ':' . $row[2];
			if ( ! isset( $this->tables[ $insert[1] ][ $key ] ) ) {
				$this->tables[ $insert[1] ][ $key ] = array(
					'claim' => null,
					'dirty' => 0,
				);
				++$affected;
			} elseif ( null !== $this->tables[ $insert[1] ][ $key ]['claim'] ) {
				$this->tables[ $insert[1] ][ $key ]['dirty'] = 1;
				$affected                                   += 2;
			}
		}

		return $affected;
	}

	private function collector(): ChangeCollector {
		$pending   = new PendingChanges(
			new FlushScheduler( static fn(): int => self::NOW ),
			new Logger( $this->recording_logger() ),
			fn(): bool => $this->connected[ $this->site() ] ?? false,
			fn(): CatalogQueue => new CatalogQueue( $this->wpdb, $this->wpdb->prefix . 'woonyx_catalog_queue', static fn(): int => self::NOW )
		);
		$collector = new ChangeCollector( $pending );

		$this->pending[ spl_object_id( $collector ) ] = $pending;

		return $collector;
	}

	/**
	 * The end of the request a collector belongs to: its PendingChanges writes.
	 *
	 * @param ChangeCollector $collector A collector made by collector().
	 */
	private function write( ChangeCollector $collector ): void {
		$this->pending[ spl_object_id( $collector ) ]->write();
	}

	/**
	 * The queued rows of a site's table, sorted, as "kind:id".
	 *
	 * @param string $prefix Table prefix.
	 * @return list<string>
	 */
	private function queued( string $prefix = 'wp_' ): array {
		$keys = array_keys( $this->tables[ $prefix . 'woonyx_catalog_queue' ] ?? array() );
		sort( $keys );

		return $keys;
	}

	public function test_registers_every_hook(): void {
		$collector = $this->collector();
		$collector->register();

		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'save_post_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'trashed_post', 'untrashed_post' ) as $hook ) {
			self::assertSame( 10, has_action( $hook, array( $collector, 'post_changed' ) ), $hook );
		}
		self::assertSame( 10, has_action( 'woocommerce_product_set_stock', array( $collector, 'stock_changed' ) ) );
		self::assertSame( 10, has_action( 'woocommerce_variation_set_stock', array( $collector, 'stock_changed' ) ) );
		self::assertSame( 10, has_action( 'before_delete_post', array( $collector, 'post_deleting' ) ) );
		self::assertSame( 10, has_action( 'created_product_cat', array( $collector, 'category_changed' ) ) );
		self::assertSame( 10, has_action( 'edited_product_cat', array( $collector, 'category_changed' ) ) );
		self::assertSame( 10, has_action( 'pre_delete_term', array( $collector, 'category_deleting' ) ) );
		self::assertSame( 10, has_action( 'delete_product_cat', array( $collector, 'category_deleted' ) ) );
		self::assertFalse( has_action( 'shutdown' ) );
	}

	/**
	 * Changed posts and the product queued for each.
	 *
	 * @return array<string, array{0: mixed, 1: list<string>}>
	 */
	public static function changed_posts(): array {
		return array(
			'published product'          => array( 5, array( 'product:5' ) ),
			'draft product'              => array( 6, array( 'product:6' ) ),
			'trashed product'            => array( 8, array( 'product:8' ) ),
			'id as a string'             => array( '5', array( 'product:5' ) ),
			'variation -> parent'        => array( 7, array( 'product:5' ) ),
			'auto-draft product'         => array( 9, array() ),
			'variation of an auto-draft' => array( 10, array() ),
			'variation without a parent' => array( 11, array() ),
			'revision or autosave'       => array( 12, array() ),
			'page'                       => array( 13, array() ),
			'post'                       => array( 14, array() ),
			'order'                      => array( 15, array() ),
			'missing post'               => array( 99, array() ),
			'zero'                       => array( 0, array() ),
			'negative'                   => array( -5, array() ),
			'not a number'               => array( '5abc', array() ),
			'null'                       => array( null, array() ),
		);
	}

	/**
	 * A saved, trashed or restored post, or a stock status change.
	 *
	 * @param mixed        $post_id  Hook argument.
	 * @param list<string> $expected Queued rows.
	 */
	#[DataProvider( 'changed_posts' )]
	public function test_a_changed_post_queues_its_product( $post_id, array $expected ): void {
		$collector = $this->collector();

		$collector->post_changed( $post_id );
		$this->write( $collector );

		self::assertSame( $expected, $this->queued() );
	}

	/**
	 * A stock quantity change, by the object WooCommerce passes.
	 *
	 * @param mixed        $post_id  Id the object returns.
	 * @param list<string> $expected Queued rows.
	 */
	#[DataProvider( 'changed_posts' )]
	public function test_a_stock_change_queues_its_product( $post_id, array $expected ): void {
		$product   = new class( $post_id ) {
			/**
			 * Id.
			 *
			 * @var mixed
			 */
			private $id;

			/**
			 * Constructor.
			 *
			 * @param mixed $id Id.
			 */
			public function __construct( $id ) {
				$this->id = $id;
			}

			/**
			 * Id.
			 *
			 * @return mixed
			 */
			public function get_id() {
				return $this->id;
			}
		};
		$collector = $this->collector();

		$collector->stock_changed( $product );
		$this->write( $collector );

		self::assertSame( $expected, $this->queued() );
	}

	public function test_a_stock_change_without_a_product_object_queues_nothing(): void {
		$collector = $this->collector();

		$collector->stock_changed( 5 );
		$collector->stock_changed( new \stdClass() );
		$this->write( $collector );

		self::assertSame( array(), $this->queries );
	}

	/**
	 * Posts deleted permanently and what is queued for each.
	 *
	 * @return array<string, array{0: mixed, 1: list<string>}>
	 */
	public static function deleted_posts(): array {
		return array(
			'product'            => array( 5, array( 'product_delete:5' ) ),
			'trashed product'    => array( 8, array( 'product_delete:8' ) ),
			'variation'          => array( 7, array( 'product:5' ) ),
			'auto-draft product' => array( 9, array() ),
			'auto-draft variant' => array( 10, array() ),
			'revision'           => array( 12, array() ),
			'page'               => array( 13, array() ),
			'order'              => array( 15, array() ),
			'missing post'       => array( 99, array() ),
			'not a number'       => array( 'x', array() ),
		);
	}

	/**
	 * A post about to be deleted.
	 *
	 * @param mixed        $post_id  Hook argument.
	 * @param list<string> $expected Queued rows.
	 */
	#[DataProvider( 'deleted_posts' )]
	public function test_a_deleted_post( $post_id, array $expected ): void {
		$collector = $this->collector();

		$collector->post_deleting( $post_id );
		$this->write( $collector );

		self::assertSame( $expected, $this->queued() );
	}

	public function test_a_created_or_edited_category_is_queued(): void {
		$collector = $this->collector();

		$collector->category_changed( 22 );
		$collector->category_changed( '31' );
		$collector->category_changed( 0 );
		$this->write( $collector );

		self::assertSame( array( 'category:22', 'category:31' ), $this->queued() );
	}

	public function test_a_deleted_category_queues_its_deletion_and_its_products(): void {
		$collector = $this->collector();

		$collector->category_deleted( 22, 40, null, array( 5, '6', 0, 'x' ) );
		$this->write( $collector );

		self::assertSame( array( 'category_delete:22', 'product:5', 'product:6' ), $this->queued() );
	}

	public function test_a_deleted_category_without_products_queues_its_deletion_only(): void {
		$collector = $this->collector();

		$collector->category_deleted( 22, 40, null, null );
		$this->write( $collector );

		self::assertSame( array( 'category_delete:22' ), $this->queued() );
	}

	public function test_deleting_a_category_queues_its_children(): void {
		$collector = $this->collector();

		$collector->category_deleting( 22, 'product_cat' );
		$this->write( $collector );

		self::assertSame(
			array(
				array(
					'taxonomy'   => 'product_cat',
					'parent'     => 22,
					'fields'     => 'ids',
					'hide_empty' => false,
				),
			),
			$this->term_queries
		);
		self::assertSame( array( 'category:23', 'category:24' ), $this->queued() );
	}

	public function test_deleting_a_term_of_another_taxonomy_reads_and_queues_nothing(): void {
		$collector = $this->collector();

		$collector->category_deleting( 22, 'category' );
		$collector->category_deleting( 0, 'product_cat' );
		$this->write( $collector );

		self::assertSame( array(), $this->term_queries );
		self::assertSame( array(), $this->queries );
	}

	public function test_one_insert_per_kind_in_sending_order_and_one_flush_scheduled(): void {
		$collector = $this->collector();

		$collector->category_deleted( 30 );
		$collector->post_deleting( 8 );
		$collector->post_changed( 5 );
		$collector->post_changed( 7 );
		$collector->post_changed( 6 );
		$collector->category_changed( 22 );
		$this->write( $collector );

		self::assertCount( 4, $this->queries );
		self::assertStringContainsString( "VALUES ('category', 22, '2026-10-13 12:00:00') ON", $this->queries[0] );
		self::assertStringContainsString( "VALUES ('product', 5, '2026-10-13 12:00:00'), ('product', 6, '2026-10-13 12:00:00') ON", $this->queries[1] );
		self::assertStringContainsString( "VALUES ('product_delete', 8, '2026-10-13 12:00:00') ON", $this->queries[2] );
		self::assertStringContainsString( "VALUES ('category_delete', 30, '2026-10-13 12:00:00') ON", $this->queries[3] );
		self::assertSame( array( 1 => array( array( self::NOW, 'woonyx_catalog_flush', array(), 'woonyx', false ) ) ), $this->scheduled );
		self::assertSame( array(), $this->entries );
	}

	public function test_nothing_collected_writes_and_schedules_nothing(): void {
		$this->write( $this->collector() );

		self::assertSame( array(), $this->queries );
		self::assertSame( array(), $this->pending_queries );
	}

	public function test_a_second_write_sends_nothing_again(): void {
		$collector = $this->collector();
		$collector->post_changed( 5 );
		$this->write( $collector );

		$this->write( $collector );

		self::assertCount( 1, $this->queries );
	}

	public function test_without_a_connection_nothing_is_queued_or_scheduled_and_the_ids_are_dropped(): void {
		$this->connected[1] = false;
		$collector          = $this->collector();
		$collector->post_changed( 5 );
		$this->write( $collector );

		$this->connected[1] = true;
		$this->write( $collector );

		self::assertSame( array(), $this->queries );
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_failed_write_is_logged_by_kind_and_count_and_schedules_nothing(): void {
		$this->query_fails = true;
		$collector         = $this->collector();
		$collector->post_changed( 5 );
		$collector->post_changed( 6 );
		$collector->category_changed( 22 );

		$this->write( $collector );

		self::assertSame(
			array(
				array(
					'level'   => 'warning',
					'message' => 'Changes could not be queued.',
					'context' => array(
						'kind'   => 'category',
						'count'  => 1,
						'source' => 'woonyx',
					),
				),
				array(
					'level'   => 'warning',
					'message' => 'Changes could not be queued.',
					'context' => array(
						'kind'   => 'product',
						'count'  => 2,
						'source' => 'woonyx',
					),
				),
			),
			$this->entries
		);
		self::assertSame( array(), $this->scheduled );
	}

	public function test_a_failure_is_logged_by_class_and_never_thrown(): void {
		$pending = new PendingChanges(
			new FlushScheduler(),
			new Logger( $this->recording_logger() ),
			static fn(): bool => true,
			static function (): CatalogQueue {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A test exception that must not reach the log.
				throw new \RuntimeException( 'Shop secret 1234' );
			}
		);
		( new ChangeCollector( $pending ) )->post_changed( 5 );

		$pending->write();

		self::assertCount( 1, $this->entries );
		self::assertSame( 'error', $this->entries[0]['level'] );
		self::assertSame( \RuntimeException::class, $this->entries[0]['context']['exception'] );
		self::assertStringNotContainsString( '1234', (string) json_encode( $this->entries ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_ids_collected_on_another_site_go_to_that_sites_queue(): void {
		$collector = $this->collector();
		$collector->post_changed( 5 );
		switch_to_blog( 2 );
		$collector->post_changed( 6 );
		$collector->category_changed( 22 );
		restore_current_blog();

		$this->write( $collector );

		self::assertSame( array( 'product:5' ), $this->queued( 'wp_' ) );
		self::assertSame( array( 'category:22', 'product:6' ), $this->queued( 'wp_2_' ) );
		self::assertCount( 1, $this->scheduled[1] );
		self::assertCount( 1, $this->scheduled[2] );
		self::assertSame( array( 1 ), $this->site_stack );
		self::assertSame( 'wp_', $this->wpdb->prefix );
	}

	public function test_a_site_without_a_connection_does_not_stop_the_others(): void {
		$this->connected[1] = false;
		$collector          = $this->collector();
		$collector->post_changed( 5 );
		switch_to_blog( 2 );
		$collector->post_changed( 6 );
		restore_current_blog();

		$this->write( $collector );

		self::assertSame( array(), $this->queued( 'wp_' ) );
		self::assertSame( array( 'product:6' ), $this->queued( 'wp_2_' ) );
	}

	public function test_on_a_single_site_install_nothing_is_switched(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'switch_to_blog' )->never();
		$collector = $this->collector();
		$collector->post_changed( 5 );

		$this->write( $collector );

		self::assertSame( array( 'product:5' ), $this->queued() );
	}

	public function test_no_flush_is_scheduled_while_one_is_pending(): void {
		$this->scheduled[1] = array( array( self::NOW - 5, 'woonyx_catalog_flush', array(), 'woonyx', false ) );
		$collector          = $this->collector();
		$collector->post_changed( 5 );

		$this->write( $collector );

		self::assertSame( array( 'product:5' ), $this->queued() );
		self::assertCount( 1, $this->scheduled[1] );
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
	 * Race 1: two requests collect overlapping ids at the same time and write in either order. Each write is one
	 * INSERT … ON DUPLICATE KEY UPDATE per kind and no read, so neither can overwrite the other's ids; one flush is
	 * scheduled, the second request finds it pending.
	 *
	 * @param bool $reverse Whether the request that collected first writes last.
	 */
	#[DataProvider( 'write_orders' )]
	public function test_race_two_requests_writing_at_once_keep_every_id( bool $reverse ): void {
		$first  = $this->collector();
		$second = $this->collector();

		$first->post_changed( 5 );
		$second->post_changed( 6 );
		$first->post_changed( 7 );
		$second->post_changed( 5 );
		$second->post_changed( 8 );
		$first->category_changed( 22 );
		$second->category_changed( 31 );

		$writers = $reverse ? array( $second, $first ) : array( $first, $second );
		foreach ( $writers as $writer ) {
			$this->write( $writer );
		}

		self::assertSame( array( 'category:22', 'category:31', 'product:5', 'product:6', 'product:8' ), $this->queued() );
		self::assertCount( 4, $this->queries );
		foreach ( $this->queries as $query ) {
			self::assertStringStartsWith( 'INSERT INTO `wp_woonyx_catalog_queue`', $query );
		}
		self::assertCount( 1, $this->scheduled[1] );
		self::assertCount( 2, $this->pending_queries );
	}

	public function test_race_an_id_queued_while_a_flush_holds_it_is_marked_dirty_not_lost(): void {
		$this->tables['wp_woonyx_catalog_queue'] = array(
			'product:5' => array(
				'claim' => str_repeat( 'a', 32 ),
				'dirty' => 0,
			),
		);
		$collector                               = $this->collector();
		$collector->post_changed( 5 );
		$collector->post_changed( 6 );

		$this->write( $collector );

		self::assertSame(
			array(
				'product:5' => array(
					'claim' => str_repeat( 'a', 32 ),
					'dirty' => 1,
				),
				'product:6' => array(
					'claim' => null,
					'dirty' => 0,
				),
			),
			$this->tables['wp_woonyx_catalog_queue']
		);
		self::assertCount( 1, $this->scheduled[1] );
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
