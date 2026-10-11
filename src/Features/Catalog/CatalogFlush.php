<?php
/**
 * Sending the queued order and catalog changes.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

use Nyxify\Features\Orders\OrderPayloadBuilder;
use Nyxify\Features\Orders\OrderSender;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * The Action Scheduler action `nyxify_catalog_flush`: sends the queue, kind by kind in CatalogQueue::KINDS order
 * (orders, order deletes, categories, products, product deletes, category deletes), each kind in its own requests, in
 * batches, for at most BUDGET_SECONDS per run.
 *
 * - Not connected: the queue is dropped. Before the backoff's not-before time: the run reschedules itself for it and
 *   sends nothing.
 * - Each batch is claimed with a new token, built (orders, order deletes, products and categories), sent, then:
 *   - delivered (also a 2xx whose answer doesn't match, logged as a warning): completed; ids queued again while it was
 *     sending stay queued; the backoff is forgotten; a product batch's plan-limit skips go to PlanLimitNotice;
 *   - RetryPolicy::RETRY: back to the queue with one more attempt (dropped and logged after the fifth failure), the
 *     backoff set, a flush scheduled for it; the run stops;
 *   - RetryPolicy::STOP: the whole queue dropped (orders and catalog), the status cache cleared; the run stops;
 *   - RetryPolicy::DROP: that batch dropped (a 422 logs its fields' names, never values); the run goes on.
 * - Ids a batch had no room for go back to the queue unchanged. A flush is scheduled at the end while rows remain.
 * - Logs carry kinds, counts, error codes, request ids and, for orders, the order ids; never a field of an object.
 */
final class CatalogFlush {

	/**
	 * A run stops claiming new batches after this many seconds (Action Scheduler gives an action about 30).
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * Ids per catalog delete batch (the core accepts 1–500).
	 */
	public const DELETE_LIMIT = 500;

	private const SENT = 'sent';

	/**
	 * Operation names in the logs, by kind.
	 *
	 * @var array<string, string>
	 */
	private const OPERATIONS = array(
		CatalogQueue::ORDER           => 'orders/status',
		CatalogQueue::ORDER_DELETE    => 'orders/status',
		CatalogQueue::CATEGORY        => 'catalog/categories',
		CatalogQueue::PRODUCT         => 'catalog/products',
		CatalogQueue::PRODUCT_DELETE  => 'catalog/products/delete',
		CatalogQueue::CATEGORY_DELETE => 'catalog/categories/delete',
	);

	/**
	 * The site's queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Builds products and categories.
	 *
	 * @var PayloadBuilder
	 */
	private PayloadBuilder $builder;

	/**
	 * Sends the catalog to the core.
	 *
	 * @var CatalogSender
	 */
	private CatalogSender $sender;

	/**
	 * Builds orders and order deletes.
	 *
	 * @var OrderPayloadBuilder
	 */
	private OrderPayloadBuilder $order_builder;

	/**
	 * Sends orders to the core.
	 *
	 * @var OrderSender
	 */
	private OrderSender $order_sender;

	/**
	 * Schedules flushes and keeps the backoff.
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
	 * Whether the site has a readable connection.
	 *
	 * @var callable(): bool
	 */
	private $is_connected;

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Told how many products a delivered product batch skipped for the plan's limit (0 included).
	 *
	 * @var callable(int): void
	 */
	private $plan_limit;

	/**
	 * Constructor.
	 *
	 * @param Queue               $queue         The site's queue.
	 * @param PayloadBuilder      $builder       Builds products and categories.
	 * @param CatalogSender       $sender        Sends the catalog to the core.
	 * @param OrderPayloadBuilder $order_builder Builds orders and order deletes.
	 * @param OrderSender         $order_sender  Sends orders to the core.
	 * @param FlushScheduler      $scheduler     Schedules flushes and keeps the backoff.
	 * @param Logger              $logger        Safe logger.
	 * @param callable            $is_connected  Whether the site has a readable connection.
	 * @param callable|null       $clock         Current Unix time; time() by default.
	 * @param callable|null       $plan_limit    Told the plan-limit skips of each delivered product batch; nothing by
	 *                                           default.
	 *
	 * @phpstan-param callable(): bool $is_connected
	 * @phpstan-param (callable(): int)|null $clock
	 * @phpstan-param (callable(int): void)|null $plan_limit
	 */
	public function __construct( Queue $queue, PayloadBuilder $builder, CatalogSender $sender, OrderPayloadBuilder $order_builder, OrderSender $order_sender, FlushScheduler $scheduler, Logger $logger, callable $is_connected, ?callable $clock = null, ?callable $plan_limit = null ) {
		$this->queue         = $queue;
		$this->builder       = $builder;
		$this->sender        = $sender;
		$this->order_builder = $order_builder;
		$this->order_sender  = $order_sender;
		$this->scheduler     = $scheduler;
		$this->logger        = $logger;
		$this->is_connected  = $is_connected;
		$this->clock         = $clock ?? static fn(): int => time();
		$this->plan_limit    = $plan_limit ?? static function (): void {};
	}

	/**
	 * The flush of this site.
	 */
	public static function for_site(): self {
		$store  = new SecretStore( Encryptor::for_site() );
		$client = new CoreClient( $store, CoreClient::configured_base_url() );
		$logger = new Logger();

		return new self(
			CatalogQueue::for_site(),
			new PayloadBuilder( $logger ),
			new CatalogSender( $client ),
			new OrderPayloadBuilder( $logger ),
			new OrderSender( $client ),
			new FlushScheduler(),
			$logger,
			static fn(): bool => null !== $store->get_connection(),
			null,
			static function ( int $count ): void {
				PlanLimitNotice::record( $count );
			}
		);
	}

	/**
	 * The action's callback.
	 */
	public static function run_for_site(): void {
		self::for_site()->run();
	}

	/**
	 * One run; never throws (a failure is logged by its class and the batch being sent is retried).
	 */
	public function run(): void {
		$token    = null;
		$current  = '';
		$failures = 1;

		try {
			if ( ! ( $this->is_connected )() ) {
				$this->queue->clear();
				$this->scheduler->clear_retry_at();
				return;
			}

			if ( $this->scheduler->retry_at() > ( $this->clock )() ) {
				$this->scheduler->schedule();
				return;
			}

			$deadline = ( $this->clock )() + self::BUDGET_SECONDS;

			foreach ( CatalogQueue::KINDS as $kind ) {
				while ( ( $this->clock )() < $deadline ) {
					$current = $kind;
					$token   = CatalogQueue::new_token();
					$claimed = $this->queue->claim( $kind, self::limit( $kind ), $token );
					if ( array() === $claimed ) {
						$token = null;
						break;
					}

					$failures = self::failures( $claimed );
					$outcome  = $this->send( $kind, $token, $claimed );
					$token    = null;

					if ( RetryPolicy::RETRY === $outcome || RetryPolicy::STOP === $outcome ) {
						return;
					}
				}
			}

			if ( $this->queue->has_unclaimed() ) {
				$this->scheduler->schedule();
			}
		} catch ( \Throwable $e ) {
			$this->log_exception( $e );
			if ( null !== $token ) {
				$this->back_off( $token, $failures, null, $current );
			}
		}//end try
	}

	/**
	 * Builds and sends one claimed batch and applies the outcome.
	 *
	 * @param string          $kind    Queue kind.
	 * @param string          $token   Claim token.
	 * @param array<int, int> $claimed Claimed id => failed attempts so far.
	 * @return string SENT, or a RetryPolicy outcome.
	 */
	private function send( string $kind, string $token, array $claimed ): string {
		$ids = array_keys( $claimed );

		if ( CatalogQueue::PRODUCT_DELETE === $kind ) {
			return $this->apply( $kind, $token, $this->sender->delete_products( $ids ), $ids, $claimed );
		}

		if ( CatalogQueue::CATEGORY_DELETE === $kind ) {
			return $this->apply( $kind, $token, $this->sender->delete_categories( $ids ), $ids, $claimed );
		}

		$batch = $this->build( $kind, $ids );
		if ( array() !== $batch->left ) {
			$this->queue->release( $token, $batch->left );
			$claimed = array_diff_key( $claimed, array_flip( $batch->left ) );
		}

		if ( array() === $batch->items ) {
			$this->queue->complete( $token );
			return self::SENT;
		}

		return $this->apply( $kind, $token, $this->deliver( $kind, $batch->items ), $batch->built, $claimed );
	}

	/**
	 * Builds a batch of a kind that sends objects.
	 *
	 * @param string $kind Queue kind: order, order_delete, product or category.
	 * @param int[]  $ids  Claimed ids, in order.
	 *
	 * @phpstan-param list<int> $ids
	 */
	private function build( string $kind, array $ids ): Batch {
		switch ( $kind ) {
			case CatalogQueue::ORDER:
				return $this->order_builder->orders( $ids );
			case CatalogQueue::ORDER_DELETE:
				return $this->order_builder->deleted( $ids );
			case CatalogQueue::PRODUCT:
				return $this->builder->products( $ids );
			default:
				return $this->builder->categories( $ids );
		}
	}

	/**
	 * Sends a built batch to its endpoint.
	 *
	 * @param string  $kind  Queue kind: order, order_delete, product or category.
	 * @param array[] $items Objects to send.
	 *
	 * @phpstan-param list<array<string, mixed>> $items
	 */
	private function deliver( string $kind, array $items ): CoreResponse {
		switch ( $kind ) {
			case CatalogQueue::ORDER:
			case CatalogQueue::ORDER_DELETE:
				return $this->order_sender->status( $items );
			case CatalogQueue::PRODUCT:
				return $this->sender->products( $items );
			default:
				return $this->sender->categories( $items );
		}
	}

	/**
	 * Applies the core's answer to a sent batch.
	 *
	 * @param string          $kind     Queue kind.
	 * @param string          $token    Claim token.
	 * @param CoreResponse    $response The core's answer.
	 * @param int[]           $sent     Ids of the objects or deletes sent.
	 * @param array<int, int> $claimed  Ids still claimed => failed attempts so far.
	 * @return string SENT, or a RetryPolicy outcome.
	 *
	 * @phpstan-param list<int> $sent
	 */
	private function apply( string $kind, string $token, CoreResponse $response, array $sent, array $claimed ): string {
		$error = $response->error;

		if ( null === $error ) {
			$this->delivered( $token );
			$this->log_delivery( $kind, $sent, $response );
			return self::SENT;
		}

		if ( CoreError::INVALID_RESPONSE === $error->code && $error->status >= 200 && $error->status < 300 ) {
			$this->delivered( $token );
			$this->logger->warning(
				'The core accepted changes, but its answer was unexpected.',
				self::batch_context( $kind, $sent ) + array( 'request_id' => $error->request_id )
			);
			return self::SENT;
		}

		$this->logger->core_error( self::OPERATIONS[ $kind ], $error );
		$outcome = RetryPolicy::outcome( $error );

		if ( RetryPolicy::RETRY === $outcome ) {
			$this->back_off( $token, self::failures( $claimed ), $error->retry_after, $kind );
		} elseif ( RetryPolicy::STOP === $outcome ) {
			$this->queue->clear();
			$this->scheduler->clear_retry_at();
			StatusService::clear();
			$this->logger->warning( 'The core refuses the store; every queued change was dropped.', array( 'kind' => $kind ) );
		} else {
			$this->queue->drop( $token );
			$this->logger->warning(
				'The core refused a batch of changes; it was dropped.',
				self::batch_context( $kind, $sent ) + array( 'fields' => implode( ',', $error->fields ) )
			);
		}

		return $outcome;
	}

	/**
	 * Completes a delivered batch and forgets the backoff.
	 *
	 * @param string $token Claim token.
	 */
	private function delivered( string $token ): void {
		$this->queue->complete( $token );
		$this->scheduler->clear_retry_at();
	}

	/**
	 * Logs a delivery (counts, and the ids of orders) and passes a product batch's plan-limit skips on.
	 *
	 * @param string       $kind     Queue kind.
	 * @param int[]        $sent     Ids of the objects or deletes sent.
	 * @param CoreResponse $response The core's answer.
	 *
	 * @phpstan-param list<int> $sent
	 */
	private function log_delivery( string $kind, array $sent, CoreResponse $response ): void {
		$context = self::batch_context( $kind, $sent ) + array( 'request_id' => $response->request_id );

		if ( CatalogQueue::PRODUCT === $kind ) {
			$skipped_limit = count( (array) $response->data['skipped_plan_limit'] );
			$context      += array(
				'applied'            => (int) $response->data['applied'],
				'skipped_stale'      => count( (array) $response->data['skipped_stale'] ),
				'skipped_plan_limit' => $skipped_limit,
			);

			if ( $skipped_limit > 0 ) {
				StatusService::clear();
			}
			( $this->plan_limit )( $skipped_limit );
		} else {
			$context['affected'] = (int) $response->data['affected'];
		}

		$this->logger->info( 'Changes sent.', $context );
	}

	/**
	 * A batch in the logs: its kind and size, and for orders their ids (a catalog batch can hold hundreds of ids).
	 *
	 * @param string $kind Queue kind.
	 * @param int[]  $sent Ids of the objects or deletes sent.
	 * @return array<string, string|int>
	 *
	 * @phpstan-param list<int> $sent
	 */
	private static function batch_context( string $kind, array $sent ): array {
		$context = array(
			'kind'  => $kind,
			'count' => count( $sent ),
		);

		if ( CatalogQueue::ORDER === $kind || CatalogQueue::ORDER_DELETE === $kind ) {
			$context['ids'] = implode( ',', $sent );
		}

		return $context;
	}

	/**
	 * Puts a failed batch back with one more attempt, sets the backoff and schedules a flush for it.
	 *
	 * @param string   $token       Claim token.
	 * @param int      $failures    Failed sends of the batch's most-tried row, this one included.
	 * @param int|null $retry_after The core's Retry-After in seconds, or null.
	 * @param string   $kind        Queue kind, for the logs.
	 */
	private function back_off( string $token, int $failures, ?int $retry_after, string $kind ): void {
		$dropped = $this->queue->retry( $token );
		if ( is_int( $dropped ) && $dropped > 0 ) {
			$this->logger->warning(
				'Changes were dropped after repeated failures.',
				array(
					'kind'  => $kind,
					'count' => $dropped,
				)
			);
		}

		$this->scheduler->set_retry_at( ( $this->clock )() + RetryPolicy::delay( $failures, $retry_after ) );
		$this->scheduler->schedule();
	}

	/**
	 * Failed sends of a batch's most-tried row once this send failed too.
	 *
	 * @param array<int, int> $claimed Claimed id => failed attempts so far.
	 */
	private static function failures( array $claimed ): int {
		return ( array() === $claimed ? 0 : max( $claimed ) ) + 1;
	}

	/**
	 * Ids per batch of a kind: 500 for catalog deletes, 100 for the others (orders and order deletes included: the
	 * core accepts 1–100 orders).
	 *
	 * @param string $kind Queue kind.
	 */
	private static function limit( string $kind ): int {
		return CatalogQueue::PRODUCT_DELETE === $kind || CatalogQueue::CATEGORY_DELETE === $kind ? self::DELETE_LIMIT : Batch::MAX_ITEMS;
	}

	/**
	 * Logs an unexpected failure by its class only (a message could carry data).
	 *
	 * @param \Throwable $e The failure.
	 */
	private function log_exception( \Throwable $e ): void {
		try {
			$this->logger->error( 'The flush failed.', array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// Logging must not fail the action either.
			unset( $ignored );
		}
	}
}
