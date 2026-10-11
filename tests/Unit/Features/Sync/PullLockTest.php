<?php
/**
 * Tests for PullLock.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Sync;

use Mockery;
use Nyxify\Features\Sync\PullLock;
use Nyxify\Tests\Unit\EmulatesPrepare;
use Nyxify\Tests\Unit\TestCase;

/**
 * The lock over a mocked $wpdb that keeps the one option row in memory and runs the lock's three statements as MySQL
 * would (INSERT IGNORE, UPDATE ... WHERE value, DELETE ... WHERE value).
 */
final class PullLockTest extends TestCase {

	use EmulatesPrepare;

	private const NOW = 1_791_000_000;

	/**
	 * The lock row's value, or null when there is none.
	 *
	 * @var string|null
	 */
	private ?string $row = null;

	/**
	 * Current Unix time.
	 *
	 * @var int
	 */
	private int $now = self::NOW;

	/**
	 * Every statement run.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	/**
	 * Replaces the SELECT's answer (a race with another run), or null.
	 *
	 * @var (\Closure(): ?string)|null
	 */
	private ?\Closure $read = null;

	protected function setUp(): void {
		parent::setUp();

		$wpdb          = Mockery::mock( \wpdb::class );
		$wpdb->options = 'wp_2_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn( string $query, ...$args ): string => self::prepare_query( $query, ...$args ) );
		$wpdb->shouldReceive( 'query' )->andReturnUsing( fn( string $query ): int => $this->execute( $query ) );
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( string $query ): ?string {
				$this->queries[] = $query;
				self::assertSame( "SELECT option_value FROM wp_2_options WHERE option_name = 'nyxify_sync_lock'", $query );
				return null !== $this->read ? ( $this->read )() : $this->row;
			}
		);
		$GLOBALS['wpdb'] = $wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test's database.
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * Runs one of the lock's statements on the in-memory row.
	 *
	 * @param string $query SQL.
	 * @return int Affected rows.
	 */
	private function execute( string $query ): int {
		$this->queries[] = $query;

		if ( 1 === preg_match( "/^INSERT IGNORE INTO wp_2_options \\(option_name, option_value, autoload\\) VALUES \\('nyxify_sync_lock', '([^']+)', 'no'\\)$/", $query, $m ) ) {
			if ( null !== $this->row ) {
				return 0;
			}
			$this->row = $m[1];
			return 1;
		}

		if ( 1 === preg_match( "/^UPDATE wp_2_options SET option_value = '([^']+)' WHERE option_name = 'nyxify_sync_lock' AND option_value = '([^']+)'$/", $query, $m ) ) {
			if ( $this->row !== $m[2] ) {
				return 0;
			}
			$this->row = $m[1];
			return 1;
		}

		if ( 1 === preg_match( "/^DELETE FROM wp_2_options WHERE option_name = 'nyxify_sync_lock' AND option_value = '([^']+)'$/", $query, $m ) ) {
			if ( $this->row !== $m[1] ) {
				return 0;
			}
			$this->row = null;
			return 1;
		}

		self::fail( 'Unexpected query: ' . $query );
	}

	private function lock(): PullLock {
		return new PullLock( fn(): int => $this->now );
	}

	public function test_a_free_lock_is_taken_with_a_token_and_an_expiry(): void {
		$value = $this->lock()->acquire();

		self::assertNotNull( $value );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}:' . ( self::NOW + PullLock::TTL_SECONDS ) . '$/', $value );
		self::assertSame( $value, $this->row );
		self::assertCount( 1, $this->queries );
	}

	public function test_a_held_lock_is_not_taken(): void {
		$first      = $this->lock()->acquire();
		$this->now += PullLock::TTL_SECONDS - 1;

		self::assertNull( $this->lock()->acquire() );
		self::assertSame( $first, $this->row );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		$first      = $this->lock()->acquire();
		$this->now += PullLock::TTL_SECONDS;

		$second = $this->lock()->acquire();

		self::assertNotNull( $second );
		self::assertNotSame( $first, $second );
		self::assertSame( $second, $this->row );
	}

	public function test_a_malformed_lock_counts_as_expired(): void {
		$this->row = 'garbage';

		$value = $this->lock()->acquire();

		self::assertNotNull( $value );
		self::assertSame( $value, $this->row );
	}

	public function test_of_two_runs_taking_over_an_expired_lock_only_one_wins(): void {
		$this->row = str_repeat( 'a', 32 ) . ':' . ( self::NOW - 1 );
		// The other run's UPDATE lands between this run's SELECT and its UPDATE.
		$this->read = function (): ?string {
			$read      = $this->row;
			$this->row = str_repeat( 'b', 32 ) . ':' . ( self::NOW + PullLock::TTL_SECONDS );
			return $read;
		};

		self::assertNull( $this->lock()->acquire() );
		self::assertSame( str_repeat( 'b', 32 ) . ':' . ( self::NOW + PullLock::TTL_SECONDS ), $this->row );
	}

	public function test_a_lock_gone_between_insert_and_read_is_not_taken(): void {
		$this->row  = str_repeat( 'a', 32 ) . ':' . ( self::NOW + 100 );
		$this->read = static fn(): ?string => null;

		self::assertNull( $this->lock()->acquire() );
		self::assertCount( 2, $this->queries );
	}

	public function test_release_frees_the_lock(): void {
		$lock  = $this->lock();
		$value = $lock->acquire();
		self::assertNotNull( $value );

		$lock->release( $value );

		self::assertNull( $this->row );
		self::assertNotNull( $this->lock()->acquire() );
	}

	public function test_release_leaves_a_lock_taken_over_by_another_run(): void {
		$first = $this->lock()->acquire();
		self::assertNotNull( $first );
		$this->now += PullLock::TTL_SECONDS + 5;
		$second     = $this->lock()->acquire();

		$this->lock()->release( $first );

		self::assertSame( $second, $this->row );
	}
}
