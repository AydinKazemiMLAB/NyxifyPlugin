<?php
/**
 * Tests for PendingChanges.
 *
 * The writing itself (one INSERT per kind in sending order, no read, failures, multisite, races) is tested through the
 * collectors in ChangeCollectorTest and OrderCollectorTest.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\FlushScheduler;
use Nyxify\Features\Catalog\PendingChanges;
use Nyxify\Features\Catalog\Queue;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\TestCase;

/**
 * Registration, the kinds and ids accepted, and the order kinds written before the catalog kinds.
 */
final class PendingChangesTest extends TestCase {

	private const NOW = 1791892800;

	/**
	 * The site's queue.
	 *
	 * @var InMemoryQueue
	 */
	private InMemoryQueue $queue;

	protected function setUp(): void {
		parent::setUp();

		$this->queue = new InMemoryQueue( static fn(): int => self::NOW );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->justReturn( 1 );
	}

	private function pending(): PendingChanges {
		$queue = $this->queue;

		return new PendingChanges(
			new FlushScheduler( static fn(): int => self::NOW ),
			new Logger( Mockery::mock( 'WC_Logger_Interface' ) ),
			static fn(): bool => true,
			static fn(): Queue => $queue
		);
	}

	public function test_writes_at_shutdown_before_action_scheduler(): void {
		$pending = $this->pending();

		$pending->register();

		self::assertSame( 0, PendingChanges::SHUTDOWN_PRIORITY );
		self::assertSame( 0, has_action( 'shutdown', array( $pending, 'write' ) ) );
	}

	public function test_unknown_kinds_and_bad_ids_are_ignored(): void {
		$pending = $this->pending();

		$pending->add( 'orders', array( 1 ) );
		$pending->add( 'Order', array( 2 ) );
		$pending->add( 'order', array( 3, '4', 0, -1, '5x', 1.5, null, true ) );
		$pending->write();

		self::assertSame( array( 'order:3', 'order:4' ), array_keys( $this->queue->rows ) );
	}

	public function test_kinds_are_written_in_sending_order_whatever_order_they_were_added_in(): void {
		$pending = $this->pending();

		$pending->add( 'category_delete', array( 1 ) );
		$pending->add( 'product', array( 2 ) );
		$pending->add( 'order_delete', array( 3 ) );
		$pending->add( 'category', array( 4 ) );
		$pending->add( 'product_delete', array( 5 ) );
		$pending->add( 'order', array( 6 ) );
		$pending->write();

		self::assertSame( array( 'order:6', 'order_delete:3', 'category:4', 'product:2', 'product_delete:5', 'category_delete:1' ), array_keys( $this->queue->rows ) );
	}

	/**
	 * Hook arguments and the ids read from them.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function ids(): array {
		return array(
			'int'              => array( 42, 42 ),
			'digits'           => array( '42', 42 ),
			'zero'             => array( 0, 0 ),
			'negative'         => array( -3, 0 ),
			'negative string'  => array( '-3', 0 ),
			'float'            => array( 4.0, 0 ),
			'digits and text'  => array( '4a', 0 ),
			'empty string'     => array( '', 0 ),
			'null'             => array( null, 0 ),
			'bool'             => array( true, 0 ),
			'array'            => array( array( 4 ), 0 ),
			'object'           => array( new \stdClass(), 0 ),
			'spaces around it' => array( ' 4 ', 0 ),
		);
	}

	/**
	 * An id from a hook argument.
	 *
	 * @param mixed $value    Hook argument.
	 * @param int   $expected Id.
	 */
	#[DataProvider( 'ids' )]
	public function test_to_id( $value, int $expected ): void {
		self::assertSame( $expected, PendingChanges::to_id( $value ) );
	}
}
