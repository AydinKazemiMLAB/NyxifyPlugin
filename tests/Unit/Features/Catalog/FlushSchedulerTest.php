<?php
/**
 * Tests for FlushScheduler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WooNyx\Features\Catalog\FlushScheduler;
use WooNyx\Tests\Unit\TestCase;

/**
 * The scheduler over Action Scheduler functions that keep pending actions in memory, and in-memory options.
 */
final class FlushSchedulerTest extends TestCase {

	private const NOW = 1791892800;

	/**
	 * Pending flushes: list of [timestamp, hook, args, group, unique].
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $pending = array();

	/**
	 * Arguments of each as_get_scheduled_actions() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $pending_queries = array();

	/**
	 * Arguments of each as_unschedule_all_actions() call.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $unscheduled = array();

	/**
	 * What as_schedule_single_action() returns.
	 *
	 * @var int
	 */
	private int $action_id = 42;

	/**
	 * In-memory options: name => [value, autoload].
	 *
	 * @var array<string, array{0: mixed, 1: bool|null}>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ][0] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, ?bool $autoload = null ): bool {
				$this->options[ $name ] = array( $value, $autoload );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	/**
	 * Stands in for Action Scheduler's functions.
	 */
	private function load_action_scheduler(): void {
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $args, string $format ): array {
				$this->pending_queries[] = array( $args, $format );
				return array_keys( $this->pending );
			}
		);
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $timestamp, string $hook, array $args, string $group, bool $unique ): int {
				if ( $this->action_id > 0 ) {
					$this->pending[] = array( $timestamp, $hook, $args, $group, $unique );
				}
				return $this->action_id;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args, string $group ): void {
				$this->unscheduled[] = array( $hook, $args, $group );
			}
		);
	}

	private function scheduler(): FlushScheduler {
		return new FlushScheduler( static fn(): int => self::NOW );
	}

	public function test_schedules_a_flush_for_now_when_none_is_pending(): void {
		$this->load_action_scheduler();

		self::assertTrue( $this->scheduler()->schedule() );

		self::assertSame( array( array( self::NOW, 'woonyx_catalog_flush', array(), 'woonyx', false ) ), $this->pending );
		self::assertSame(
			array(
				array(
					array(
						'hook'     => 'woonyx_catalog_flush',
						'group'    => 'woonyx',
						'status'   => 'pending',
						'per_page' => 1,
						'orderby'  => 'none',
					),
					'ids',
				),
			),
			$this->pending_queries
		);
	}

	public function test_schedules_nothing_while_a_flush_is_pending(): void {
		$this->load_action_scheduler();
		$scheduler = $this->scheduler();
		$scheduler->schedule();

		self::assertFalse( $scheduler->schedule() );
		self::assertFalse( $scheduler->schedule() );

		self::assertCount( 1, $this->pending );
	}

	public function test_a_running_flush_does_not_count_as_pending(): void {
		$this->load_action_scheduler();
		$scheduler = $this->scheduler();
		$scheduler->schedule();
		// Action Scheduler marks the action in-progress when it starts it: it no longer matches status "pending".
		$this->pending = array();

		self::assertTrue( $scheduler->schedule() );
		self::assertCount( 1, $this->pending );
	}

	public function test_a_failed_scheduling_is_reported(): void {
		$this->load_action_scheduler();
		$this->action_id = 0;

		self::assertFalse( $this->scheduler()->schedule() );
	}

	/**
	 * Stored not-before times and when the flush is due.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function retry_times(): array {
		return array(
			'later than now' => array( self::NOW + 300, self::NOW + 300 ),
			'as a string'    => array( (string) ( self::NOW + 900 ), self::NOW + 900 ),
			'passed'         => array( self::NOW - 60, self::NOW ),
			'not a number'   => array( 'soon', self::NOW ),
			'negative'       => array( -5, self::NOW ),
			'an array'       => array( array( self::NOW + 60 ), self::NOW ),
		);
	}

	/**
	 * The flush is due at the later of now and the backoff's not-before time.
	 *
	 * @param mixed $stored   Stored option value.
	 * @param int   $expected Due time.
	 */
	#[DataProvider( 'retry_times' )]
	public function test_a_flush_waits_for_the_backoff( $stored, int $expected ): void {
		$this->load_action_scheduler();
		$this->options[ FlushScheduler::OPTION_RETRY_AT ] = array( $stored, false );

		$this->scheduler()->schedule();

		self::assertSame( $expected, $this->pending[0][0] );
	}

	public function test_retry_at_is_zero_when_unset(): void {
		self::assertSame( 0, $this->scheduler()->retry_at() );
	}

	public function test_retry_at_is_stored_without_autoload_and_cleared(): void {
		$scheduler = $this->scheduler();

		$scheduler->set_retry_at( self::NOW + 60 );

		self::assertSame( array( self::NOW + 60, false ), $this->options[ FlushScheduler::OPTION_RETRY_AT ] );
		self::assertSame( self::NOW + 60, $scheduler->retry_at() );

		$scheduler->clear_retry_at();

		self::assertSame( array(), $this->options );
		self::assertSame( 0, $scheduler->retry_at() );
	}

	public function test_unschedule_all_cancels_the_sites_flushes(): void {
		$this->load_action_scheduler();

		FlushScheduler::unschedule_all();

		self::assertSame( array( array( 'woonyx_catalog_flush', array(), 'woonyx' ) ), $this->unscheduled );
	}

	public function test_has_pending(): void {
		$this->load_action_scheduler();
		$scheduler = $this->scheduler();

		self::assertFalse( $scheduler->has_pending() );
		$scheduler->schedule();
		self::assertTrue( $scheduler->has_pending() );
	}

	/**
	 * Without WooCommerce, Action Scheduler isn't loaded. In its own process: Brain Monkey's stubs of the other tests
	 * stay declared.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_without_action_scheduler_nothing_is_scheduled(): void {
		$scheduler = $this->scheduler();

		self::assertFalse( FlushScheduler::is_available() );
		self::assertFalse( $scheduler->schedule() );
		self::assertFalse( $scheduler->has_pending() );
		FlushScheduler::unschedule_all();
		self::assertFalse( function_exists( 'as_unschedule_all_actions' ) );
	}
}
