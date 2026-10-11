<?php
/**
 * The changes collected during one request.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * The ids the collectors (ChangeCollector for the catalog, Orders\OrderCollector for orders) gather during a request,
 * kept in memory per site and kind. At `shutdown` (priority 0, before Action Scheduler's async runner at 10 sees
 * whether an action is due) each site's ids are written with one Queue::enqueue() per kind, in sending order, and one
 * flush is scheduled. A save costs no query of its own. Nothing is queued on a site without a readable connection (the
 * ids are dropped): the core's full sync after the activation catches up.
 */
final class PendingChanges {

	/**
	 * Priority of the `shutdown` callback.
	 */
	public const SHUTDOWN_PRIORITY = 0;

	/**
	 * Schedules the flush.
	 *
	 * @var FlushScheduler
	 */
	private FlushScheduler $scheduler;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Whether the current site has a readable connection.
	 *
	 * @var callable(): bool
	 */
	private $is_connected;

	/**
	 * The current site's queue.
	 *
	 * @var callable(): Queue
	 */
	private $queue;

	/**
	 * Changed ids: site id => kind => object id => true.
	 *
	 * @var array<int, array<string, array<int, true>>>
	 */
	private array $changes = array();

	/**
	 * Constructor.
	 *
	 * @param FlushScheduler $scheduler    Schedules the flush.
	 * @param Logger         $logger       Safe logger.
	 * @param callable|null  $is_connected Whether the current site has a readable connection; read from the site's
	 *                                     SecretStore by default.
	 * @param callable|null  $queue        The current site's queue; CatalogQueue::for_site() by default.
	 *
	 * @phpstan-param (callable(): bool)|null $is_connected
	 * @phpstan-param (callable(): Queue)|null $queue
	 */
	public function __construct( FlushScheduler $scheduler, Logger $logger, ?callable $is_connected = null, ?callable $queue = null ) {
		$this->scheduler    = $scheduler;
		$this->logger       = $logger;
		$this->is_connected = $is_connected ?? static fn(): bool => null !== ( new SecretStore( Encryptor::for_site() ) )->get_connection();
		$this->queue        = $queue ?? array( CatalogQueue::class, 'for_site' );
	}

	/**
	 * Writes the collected ids at the end of the request.
	 */
	public function register(): void {
		add_action( 'shutdown', array( $this, 'write' ), self::SHUTDOWN_PRIORITY );
	}

	/**
	 * Adds ids of one kind for the current site.
	 *
	 * @param string       $kind One of CatalogQueue::KINDS; any other kind is ignored.
	 * @param array<mixed> $ids  Ids; anything but a positive integer is ignored.
	 */
	public function add( string $kind, array $ids ): void {
		if ( ! CatalogQueue::is_kind( $kind ) ) {
			return;
		}

		$site_id = get_current_blog_id();

		foreach ( $ids as $id ) {
			$id = self::to_id( $id );
			if ( $id > 0 ) {
				$this->changes[ $site_id ][ $kind ][ $id ] = true;
			}
		}
	}

	/**
	 * Writes the collected ids to each site's queue and schedules a flush there; never throws.
	 */
	public function write(): void {
		$changes       = $this->changes;
		$this->changes = array();

		$current = get_current_blog_id();

		foreach ( $changes as $site_id => $kinds ) {
			$switched = $site_id !== $current && is_multisite();
			if ( $switched ) {
				switch_to_blog( $site_id );
			}

			try {
				$this->write_site( $kinds );
			} catch ( \Throwable $e ) {
				$this->log_exception( $e );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * An id from a hook argument; 0 when it isn't a positive integer.
	 *
	 * @param mixed $value Hook argument.
	 */
	public static function to_id( $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Writes one site's ids, kind by kind, and schedules a flush if anything was queued.
	 *
	 * @param array<string, array<int, true>> $kinds Kind => object id => true.
	 */
	private function write_site( array $kinds ): void {
		if ( ! ( $this->is_connected )() ) {
			return;
		}

		$queue  = ( $this->queue )();
		$queued = false;

		foreach ( CatalogQueue::KINDS as $kind ) {
			if ( empty( $kinds[ $kind ] ) ) {
				continue;
			}

			if ( $queue->enqueue( $kind, array_keys( $kinds[ $kind ] ) ) ) {
				$queued = true;
				continue;
			}

			$this->logger->warning(
				'Changes could not be queued.',
				array(
					'kind'  => $kind,
					'count' => count( $kinds[ $kind ] ),
				)
			);
		}

		if ( $queued ) {
			$this->scheduler->schedule();
		}
	}

	/**
	 * Logs an unexpected failure by its class only (a message could carry data).
	 *
	 * @param \Throwable $e The failure.
	 */
	private function log_exception( \Throwable $e ): void {
		try {
			$this->logger->error( 'Changes could not be queued.', array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// Logging must not break the request's end either.
			unset( $ignored );
		}
	}
}
