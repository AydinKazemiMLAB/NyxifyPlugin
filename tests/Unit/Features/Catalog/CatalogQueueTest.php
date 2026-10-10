<?php
/**
 * Tests for the catalog queue.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Catalog\CatalogQueue;
use WooNyx\Tests\Unit\EmulatesPrepare;
use WooNyx\Tests\Unit\TestCase;

/**
 * The exact SQL of every operation over a mocked $wpdb: each one a single statement (or a short sequence on rows the
 * caller has claimed), never a read before a write.
 */
final class CatalogQueueTest extends TestCase {

	use EmulatesPrepare;

	private const TABLE = 'wp_woonyx_catalog_queue';
	private const TOKEN = '0123456789abcdef0123456789abcdef';
	private const NOW   = 1791892800; // 2026-10-13 12:00:00 UTC.

	/**
	 * Queries sent through $wpdb->query(), get_results() and get_var(), in order.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * What the next $wpdb->query() calls return, in order (an int when empty).
	 *
	 * @var list<int|false>
	 */
	private array $query_results = array();

	/**
	 * What $wpdb->get_results() returns.
	 *
	 * @var list<object>
	 */
	private array $rows = array();

	/**
	 * What $wpdb->get_var() returns.
	 *
	 * @var string|null
	 */
	private ?string $var = null;

	/**
	 * Mocked database.
	 *
	 * @var \wpdb&MockInterface
	 */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->wpdb = Mockery::mock( \wpdb::class );
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$this->wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( string $query ) {
				$this->queries[] = $query;
				return array() === $this->query_results ? 1 : array_shift( $this->query_results );
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( string $query ): array {
				$this->queries[] = $query;
				return $this->rows;
			}
		);
		$this->wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $query ): ?string {
				$this->queries[] = $query;
				return $this->var;
			}
		);
	}

	private function queue(): CatalogQueue {
		return new CatalogQueue( $this->wpdb, self::TABLE, static fn(): int => self::NOW );
	}

	/**
	 * A row of get_results().
	 *
	 * @param string $object_id Object id, as MySQL returns it.
	 * @param string $attempts  Attempts, as MySQL returns it.
	 */
	private static function row( string $object_id, string $attempts ): object {
		return (object) array(
			'object_id' => $object_id,
			'attempts'  => $attempts,
		);
	}

	public function test_kinds_are_in_sending_order(): void {
		self::assertSame( array( 'order', 'order_delete', 'category', 'product', 'product_delete', 'category_delete' ), CatalogQueue::KINDS );
		self::assertTrue( CatalogQueue::is_kind( 'product' ) );
		self::assertTrue( CatalogQueue::is_kind( 'order' ) );
		self::assertTrue( CatalogQueue::is_kind( 'order_delete' ) );
		self::assertFalse( CatalogQueue::is_kind( 'Product' ) );
		self::assertFalse( CatalogQueue::is_kind( 'orders' ) );
	}

	public function test_enqueue_is_one_insert_that_marks_claimed_rows_dirty_and_reads_nothing(): void {
		self::assertTrue( $this->queue()->enqueue( CatalogQueue::PRODUCT, array( 42, 7, 42 ) ) );

		self::assertSame(
			array(
				'INSERT INTO `wp_woonyx_catalog_queue` (kind, object_id, queued_at) VALUES '
				. "('product', 42, '2026-10-13 12:00:00'), ('product', 7, '2026-10-13 12:00:00') "
				. 'ON DUPLICATE KEY UPDATE dirty = IF(claim IS NULL, dirty, 1)',
			),
			$this->queries
		);
		$this->wpdb->shouldNotHaveReceived( 'get_results' );
		$this->wpdb->shouldNotHaveReceived( 'get_var' );
	}

	public function test_enqueue_ignores_ids_that_are_not_positive_integers(): void {
		self::assertTrue( $this->queue()->enqueue( CatalogQueue::CATEGORY, array( 0, -3, '5', 5.0, null, 9 ) ) );

		self::assertCount( 1, $this->queries );
		self::assertStringContainsString( "VALUES ('category', 9, '2026-10-13 12:00:00') ON", $this->queries[0] );
	}

	public function test_enqueue_without_ids_sends_nothing(): void {
		self::assertTrue( $this->queue()->enqueue( CatalogQueue::PRODUCT_DELETE, array() ) );
		self::assertTrue( $this->queue()->enqueue( CatalogQueue::PRODUCT_DELETE, array( 0 ) ) );

		self::assertSame( array(), $this->queries );
	}

	public function test_enqueue_splits_into_chunks_of_500_rows(): void {
		self::assertTrue( $this->queue()->enqueue( CatalogQueue::PRODUCT, range( 1, 1200 ) ) );

		self::assertCount( 3, $this->queries );
		self::assertSame( 500, substr_count( $this->queries[0], "('product', " ) );
		self::assertSame( 500, substr_count( $this->queries[1], "('product', " ) );
		self::assertSame( 200, substr_count( $this->queries[2], "('product', " ) );
		self::assertStringContainsString( "('product', 501, ", $this->queries[1] );
		self::assertStringContainsString( "('product', 1200, '2026-10-13 12:00:00') ON DUPLICATE KEY UPDATE", $this->queries[2] );
	}

	public function test_enqueue_reports_a_failed_statement_and_still_tries_the_rest(): void {
		$this->query_results = array( false, 1 );

		self::assertFalse( $this->queue()->enqueue( CatalogQueue::PRODUCT, range( 1, 501 ) ) );
		self::assertCount( 2, $this->queries );
	}

	public function test_enqueue_refuses_an_unknown_kind(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->queue()->enqueue( 'orders', array( 1 ) );
	}

	public function test_claim_releases_abandoned_claims_then_claims_the_oldest_rows_and_reads_them(): void {
		$this->query_results = array( 0, 2 );
		$this->rows          = array( self::row( '7', '0' ), self::row( '42', '3' ) );

		self::assertSame(
			array(
				7  => 0,
				42 => 3,
			),
			$this->queue()->claim( CatalogQueue::PRODUCT, 100, self::TOKEN )
		);

		self::assertSame(
			array(
				"UPDATE `wp_woonyx_catalog_queue` SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim IS NOT NULL AND claimed_at < '2026-10-13 11:45:00'",
				"UPDATE `wp_woonyx_catalog_queue` SET claim = '0123456789abcdef0123456789abcdef', claimed_at = '2026-10-13 12:00:00', dirty = 0 WHERE kind = 'product' AND claim IS NULL ORDER BY queued_at, object_id LIMIT 100",
				"SELECT object_id, attempts FROM `wp_woonyx_catalog_queue` WHERE claim = '0123456789abcdef0123456789abcdef' ORDER BY queued_at, object_id",
			),
			$this->queries
		);
	}

	public function test_claim_with_nothing_to_claim_reads_nothing(): void {
		$this->query_results = array( 0, 0 );

		self::assertSame( array(), $this->queue()->claim( CatalogQueue::CATEGORY, 100, self::TOKEN ) );
		self::assertCount( 2, $this->queries );
	}

	public function test_a_failed_claim_returns_nothing(): void {
		$this->query_results = array( 0, false );

		self::assertSame( array(), $this->queue()->claim( CatalogQueue::CATEGORY, 100, self::TOKEN ) );
		self::assertCount( 2, $this->queries );
	}

	public function test_claim_skips_malformed_rows(): void {
		$this->query_results = array( 0, 2 );
		$this->rows          = array( (object) array( 'object_id' => '8' ), self::row( '9', '1' ) );

		self::assertSame( array( 9 => 1 ), $this->queue()->claim( CatalogQueue::PRODUCT, 2, self::TOKEN ) );
	}

	public function test_claim_with_a_zero_limit_sends_nothing(): void {
		self::assertSame( array(), $this->queue()->claim( CatalogQueue::PRODUCT, 0, self::TOKEN ) );
		self::assertSame( array(), $this->queries );
	}

	/**
	 * Tokens that never reach the SQL.
	 *
	 * @return array<string, array{string}>
	 */
	public static function bad_tokens(): array {
		return array(
			'empty'      => array( '' ),
			'too short'  => array( '0123456789abcdef' ),
			'upper case' => array( '0123456789ABCDEF0123456789ABCDEF' ),
			'quote'      => array( "0123456789abcdef0123456789abcde'" ),
			'too long'   => array( self::TOKEN . '0' ),
		);
	}

	#[DataProvider( 'bad_tokens' )]
	public function test_a_malformed_token_is_refused_before_any_query( string $token ): void {
		$operations = array(
			fn() => $this->queue()->claim( CatalogQueue::PRODUCT, 1, $token ),
			fn() => $this->queue()->complete( $token ),
			fn() => $this->queue()->release( $token, array( 1 ) ),
			fn() => $this->queue()->retry( $token ),
			fn() => $this->queue()->drop( $token ),
		);

		foreach ( $operations as $operation ) {
			try {
				$operation();
				self::fail( 'The token was accepted.' );
			} catch ( \InvalidArgumentException $e ) {
				self::assertSame( 'Invalid catalog queue claim token.', $e->getMessage() );
			}
		}

		self::assertSame( array(), $this->queries );
	}

	public function test_claim_refuses_an_unknown_kind(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->queue()->claim( 'orders', 1, self::TOKEN );
	}

	public function test_complete_deletes_clean_rows_and_releases_dirty_ones(): void {
		self::assertTrue( $this->queue()->complete( self::TOKEN ) );

		self::assertSame(
			array(
				"DELETE FROM `wp_woonyx_catalog_queue` WHERE claim = '0123456789abcdef0123456789abcdef' AND dirty = 0",
				"UPDATE `wp_woonyx_catalog_queue` SET claim = NULL, claimed_at = NULL, dirty = 0, attempts = 0 WHERE claim = '0123456789abcdef0123456789abcdef'",
			),
			$this->queries
		);
	}

	public function test_complete_reports_a_failed_statement(): void {
		$this->query_results = array( 3, false );

		self::assertFalse( $this->queue()->complete( self::TOKEN ) );
	}

	public function test_release_returns_only_the_given_claimed_ids(): void {
		self::assertTrue( $this->queue()->release( self::TOKEN, array( 5, 6, 5, 0 ) ) );

		self::assertSame(
			array( "UPDATE `wp_woonyx_catalog_queue` SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim = '0123456789abcdef0123456789abcdef' AND object_id IN (5, 6)" ),
			$this->queries
		);
	}

	public function test_release_without_ids_sends_nothing(): void {
		self::assertTrue( $this->queue()->release( self::TOKEN, array() ) );
		self::assertSame( array(), $this->queries );
	}

	public function test_retry_counts_an_attempt_drops_rows_at_the_limit_and_releases_the_rest(): void {
		$this->query_results = array( 4, 1, 3 );

		self::assertSame( 1, $this->queue()->retry( self::TOKEN ) );

		self::assertSame(
			array(
				"UPDATE `wp_woonyx_catalog_queue` SET attempts = attempts + 1 WHERE claim = '0123456789abcdef0123456789abcdef'",
				"DELETE FROM `wp_woonyx_catalog_queue` WHERE claim = '0123456789abcdef0123456789abcdef' AND attempts >= 5",
				"UPDATE `wp_woonyx_catalog_queue` SET claim = NULL, claimed_at = NULL, dirty = 0 WHERE claim = '0123456789abcdef0123456789abcdef'",
			),
			$this->queries
		);
	}

	public function test_retry_reports_a_failed_statement(): void {
		$this->query_results = array( 4, false, 4 );

		self::assertFalse( $this->queue()->retry( self::TOKEN ) );
	}

	public function test_drop_deletes_the_claimed_rows(): void {
		self::assertTrue( $this->queue()->drop( self::TOKEN ) );

		self::assertSame( array( "DELETE FROM `wp_woonyx_catalog_queue` WHERE claim = '0123456789abcdef0123456789abcdef'" ), $this->queries );
	}

	public function test_clear_deletes_every_row(): void {
		self::assertTrue( $this->queue()->clear() );

		self::assertSame( array( 'DELETE FROM `wp_woonyx_catalog_queue`' ), $this->queries );
	}

	public function test_clear_reports_a_failure(): void {
		$this->query_results = array( false );

		self::assertFalse( $this->queue()->clear() );
	}

	public function test_a_query_prepare_could_not_build_never_reaches_the_database(): void {
		$wpdb = Mockery::mock( \wpdb::class );
		$wpdb->shouldReceive( 'prepare' )->andReturnNull();
		$wpdb->shouldNotReceive( 'query' );
		$queue = new CatalogQueue( $wpdb, self::TABLE, static fn(): int => self::NOW );

		self::assertFalse( $queue->clear() );
		self::assertFalse( $queue->enqueue( CatalogQueue::PRODUCT, array( 1 ) ) );
		self::assertFalse( $queue->retry( self::TOKEN ) );
	}

	public function test_has_unclaimed(): void {
		self::assertFalse( $this->queue()->has_unclaimed() );

		$this->var = '1';
		self::assertTrue( $this->queue()->has_unclaimed() );

		self::assertSame( 'SELECT 1 FROM `wp_woonyx_catalog_queue` WHERE claim IS NULL LIMIT 1', $this->queries[0] );
	}

	public function test_new_tokens_are_32_lowercase_hex_characters_and_differ(): void {
		$first  = CatalogQueue::new_token();
		$second = CatalogQueue::new_token();

		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $first );
		self::assertNotSame( $first, $second );
	}

	public function test_for_site_uses_the_current_sites_prefix(): void {
		$this->wpdb->prefix  = 'wp_3_';
		$GLOBALS['wpdb']     = $this->wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
		$this->query_results = array( 1 );

		try {
			CatalogQueue::for_site()->clear();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}

		self::assertSame( array( 'DELETE FROM `wp_3_woonyx_catalog_queue`' ), $this->queries );
	}
}
