<?php
/**
 * Tests for PullScheduler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Sync;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Tests\Unit\TestCase;

/**
 * The recurring and immediate pulls over a stubbed Action Scheduler that keeps its actions in memory, and the pause
 * over an in-memory wp_options.
 */
final class PullSchedulerTest extends TestCase {

	private const NOW = 1_791_000_000;

	/**
	 * Scheduled actions: hook => status (pending or running).
	 *
	 * @var array<string, string>
	 */
	private array $actions = array();

	/**
	 * Calls that scheduled or cancelled something, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = array();

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();

		Functions\when( 'as_has_scheduled_action' )->alias(
			function ( string $hook, $args, string $group ): bool {
				self::assertNull( $args );
				self::assertSame( 'woonyx', $group );
				return isset( $this->actions[ $hook ] );
			}
		);
		Functions\when( 'as_get_scheduled_actions' )->alias(
			function ( array $query, string $format ): array {
				self::assertSame( 'ids', $format );
				self::assertSame( 'woonyx', $query['group'] );
				self::assertSame( 'pending', $query['status'] );
				return 'pending' === ( $this->actions[ $query['hook'] ] ?? '' ) ? array( 5 ) : array();
			}
		);
		Functions\when( 'as_schedule_recurring_action' )->alias(
			function ( int $timestamp, int $interval, string $hook, array $args, string $group, bool $unique ): int {
				$this->calls[]          = sprintf( 'recurring %s at %d every %d %s unique:%s', $hook, $timestamp, $interval, $group, $unique ? 'y' : 'n' );
				$this->actions[ $hook ] = 'pending';
				self::assertSame( array(), $args );
				return 3;
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args, string $group, bool $unique ): int {
				$this->calls[]          = sprintf( 'async %s %s unique:%s', $hook, $group, $unique ? 'y' : 'n' );
				$this->actions[ $hook ] = 'pending';
				self::assertSame( array(), $args );
				return 4;
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args, string $group ): void {
				$this->calls[] = 'unschedule ' . $hook . ' ' . $group;
				unset( $this->actions[ $hook ] );
				self::assertSame( array(), $args );
			}
		);
		Functions\when( 'get_option' )->alias( fn( string $name, $default_value = false ) => $this->options[ $name ] ?? $default_value );
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value, $autoload = null ): bool {
				self::assertFalse( $autoload );
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
	}

	private function scheduler(): PullScheduler {
		return new PullScheduler( static fn(): int => self::NOW );
	}

	public function test_a_connected_site_gets_the_recurring_pull_from_now_every_five_minutes(): void {
		self::assertTrue( $this->scheduler()->ensure( true ) );

		self::assertSame( array( 'recurring woonyx_sync_pull at ' . self::NOW . ' every 300 woonyx unique:y' ), $this->calls );
	}

	/**
	 * States of an existing recurring pull.
	 *
	 * @return array<string, array{string}>
	 */
	public static function existing_states(): array {
		return array(
			'pending' => array( 'pending' ),
			'running' => array( 'running' ),
		);
	}

	#[DataProvider( 'existing_states' )]
	public function test_an_existing_recurring_pull_is_kept( string $state ): void {
		$this->actions[ PullScheduler::HOOK ] = $state;

		self::assertFalse( $this->scheduler()->ensure( true ) );
		self::assertSame( array(), $this->calls );
	}

	public function test_a_pending_immediate_pull_doesnt_hide_a_missing_recurring_one(): void {
		$this->actions[ PullScheduler::NOW_HOOK ] = 'pending';

		self::assertTrue( $this->scheduler()->ensure( true ) );
		self::assertArrayHasKey( PullScheduler::HOOK, $this->actions );
	}

	public function test_a_site_not_connected_loses_both_pulls(): void {
		$this->actions = array(
			PullScheduler::HOOK     => 'pending',
			PullScheduler::NOW_HOOK => 'pending',
		);

		self::assertFalse( $this->scheduler()->ensure( false ) );

		self::assertSame( array( 'unschedule woonyx_sync_pull woonyx', 'unschedule woonyx_sync_pull_now woonyx' ), $this->calls );
		self::assertSame( array(), $this->actions );
	}

	public function test_a_site_not_connected_without_pulls_cancels_nothing(): void {
		self::assertFalse( $this->scheduler()->ensure( false ) );

		self::assertSame( array(), $this->calls );
	}

	public function test_schedule_now_queues_one_pull_on_its_own_hook(): void {
		self::assertTrue( $this->scheduler()->schedule_now() );

		self::assertSame( array( 'async woonyx_sync_pull_now woonyx unique:n' ), $this->calls );
	}

	public function test_schedule_now_skips_while_one_is_pending(): void {
		$this->actions[ PullScheduler::NOW_HOOK ] = 'pending';

		self::assertFalse( $this->scheduler()->schedule_now() );
		self::assertSame( array(), $this->calls );
	}

	public function test_schedule_now_ignores_a_running_pull_and_the_recurring_one(): void {
		$this->actions = array(
			PullScheduler::HOOK     => 'pending',
			PullScheduler::NOW_HOOK => 'running',
		);

		self::assertTrue( $this->scheduler()->schedule_now() );
	}

	public function test_the_pause_is_kept_without_autoload_and_cleared(): void {
		$scheduler = $this->scheduler();
		self::assertSame( 0, $scheduler->retry_at() );

		$scheduler->set_retry_at( self::NOW + 3600 );
		self::assertSame( self::NOW + 3600, $scheduler->retry_at() );
		self::assertSame( self::NOW + 3600, $this->options[ PullScheduler::OPTION_RETRY_AT ] );

		PullScheduler::clear_retry_at();
		self::assertSame( 0, $scheduler->retry_at() );
		self::assertSame( array(), $this->options );
	}

	/**
	 * Stored pauses that read as none.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unusable_pauses(): array {
		return array(
			'text'     => array( 'soon' ),
			'negative' => array( -5 ),
			'array'    => array( array( 1 ) ),
		);
	}

	#[DataProvider( 'unusable_pauses' )]
	public function test_an_unusable_pause_reads_as_none( mixed $value ): void {
		$this->options[ PullScheduler::OPTION_RETRY_AT ] = $value;

		self::assertSame( 0, $this->scheduler()->retry_at() );
	}

	public function test_unschedule_all_cancels_both_hooks(): void {
		PullScheduler::unschedule_all();

		self::assertSame( array( 'unschedule woonyx_sync_pull woonyx', 'unschedule woonyx_sync_pull_now woonyx' ), $this->calls );
	}
}
