<?php
/**
 * One pull of the changes the core couldn't push.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

use WooNyx\Features\Catalog\RetryPolicy;
use WooNyx\Features\Store\StatusService;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\CoreResponse;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * The Action Scheduler actions `woonyx_sync_pull` and `woonyx_sync_pull_now`: pulls up to 20 changes, applies them in
 * the core's order, then acknowledges them with the products' dates (contract §5.2).
 *
 * - Not connected, paused (OPTION_RETRY_AT) or another run holding the lock: nothing happens.
 * - Changes are applied one by one; none is started once BUDGET_SECONDS have passed. Those not started stay
 *   unacknowledged and come back once the core's claim expires.
 * - After the changes, WooCommerce's deferred product sync runs (a variation edit saves its parent only at
 *   `shutdown`), then each applied change's product date is read anew: the product's, the parent's for a variation,
 *   in the product webhook's format. The webhooks themselves go out after the run, so the ack comes first.
 * - The ack is sent once more after a network error or a 5xx. A pull or ack refused with one of RetryPolicy's stop
 *   codes pauses pulls for PAUSE_SECONDS; other failures wait for the next interval. Nothing is kept locally.
 * - A full answer (20 changes, all started) schedules the next pull at once.
 * - Logs carry counts, change ids, actions, error codes and request ids; never a payload, a product's content, a
 *   message or a link. Never throws: a failure is logged by its class, and the lock is released.
 */
final class SyncPull {

	/**
	 * No change is started after this many seconds of a run (Action Scheduler gives an action about 30).
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * How long pulls stop after the core refused the store, in seconds.
	 */
	public const PAUSE_SECONDS = 3600;

	public const OPERATION_PULL = 'sync/outbox';

	public const OPERATION_ACK = 'sync/outbox/ack';

	/**
	 * The core's outbox.
	 *
	 * @var OutboxClient
	 */
	private OutboxClient $client;

	/**
	 * Applies one change.
	 *
	 * @var ChangeApplier
	 */
	private ChangeApplier $applier;

	/**
	 * One run at a time.
	 *
	 * @var PullLock
	 */
	private PullLock $lock;

	/**
	 * Schedules pulls and keeps the pause.
	 *
	 * @var PullScheduler
	 */
	private PullScheduler $scheduler;

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
	 * Runs WooCommerce's deferred product sync.
	 *
	 * @var callable(): void
	 */
	private $finish_products;

	/**
	 * A product's `date_modified_gmt` as the product webhook sends it, or null when it is gone.
	 *
	 * @var callable(int): ?string
	 */
	private $product_date;

	/**
	 * Constructor.
	 *
	 * @param OutboxClient  $client          The core's outbox.
	 * @param ChangeApplier $applier         Applies one change.
	 * @param PullLock      $lock            One run at a time.
	 * @param PullScheduler $scheduler       Schedules pulls and keeps the pause.
	 * @param Logger        $logger          Safe logger.
	 * @param callable      $is_connected    Whether the site has a readable connection.
	 * @param callable|null $clock           Current Unix time; time() by default.
	 * @param callable|null $finish_products Runs WooCommerce's deferred product sync; finish_products() by default.
	 * @param callable|null $product_date    A product's date; product_date() by default.
	 *
	 * @phpstan-param callable(): bool $is_connected
	 * @phpstan-param (callable(): int)|null $clock
	 * @phpstan-param (callable(): void)|null $finish_products
	 * @phpstan-param (callable(int): ?string)|null $product_date
	 */
	public function __construct( OutboxClient $client, ChangeApplier $applier, PullLock $lock, PullScheduler $scheduler, Logger $logger, callable $is_connected, ?callable $clock = null, ?callable $finish_products = null, ?callable $product_date = null ) {
		$this->client          = $client;
		$this->applier         = $applier;
		$this->lock            = $lock;
		$this->scheduler       = $scheduler;
		$this->logger          = $logger;
		$this->is_connected    = $is_connected;
		$this->clock           = $clock ?? static fn(): int => time();
		$this->finish_products = $finish_products ?? array( self::class, 'finish_products' );
		$this->product_date    = $product_date ?? array( self::class, 'product_date' );
	}

	/**
	 * The pull of this site.
	 */
	public static function for_site(): self {
		$store = new SecretStore( Encryptor::for_site() );

		return new self(
			OutboxClient::for_site(),
			ChangeApplier::for_site(),
			new PullLock(),
			new PullScheduler(),
			new Logger(),
			static fn(): bool => null !== $store->get_connection()
		);
	}

	/**
	 * The actions' callback.
	 */
	public static function run_for_site(): void {
		try {
			self::for_site()->run();
		} catch ( \Throwable $e ) {
			self::log_exception( new Logger(), $e );
		}
	}

	/**
	 * Saves the parents of the variations changed so far (WooCommerce does it at `shutdown`).
	 */
	public static function finish_products(): void {
		if ( class_exists( 'WC_Post_Data' ) ) {
			\WC_Post_Data::do_deferred_product_sync();
		}
	}

	/**
	 * A product's `date_modified_gmt`, read anew, as WooCommerce's REST API and the product webhook give it.
	 *
	 * @param int $product_id Product id.
	 */
	public static function product_date( int $product_id ): ?string {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$date = wc_rest_prepare_date_response( $product->get_date_modified() );

		return is_string( $date ) ? $date : null;
	}

	/**
	 * One run; never throws.
	 */
	public function run(): void {
		try {
			if ( ! ( $this->is_connected )() || $this->scheduler->retry_at() > ( $this->clock )() ) {
				return;
			}

			$lock = $this->lock->acquire();
			if ( null === $lock ) {
				$this->logger->debug( 'Another pull is running.' );
				return;
			}

			try {
				$this->pull();
			} finally {
				$this->lock->release( $lock );
			}
		} catch ( \Throwable $e ) {
			self::log_exception( $this->logger, $e );
		}
	}

	/**
	 * Pulls, applies and acknowledges (under the lock).
	 */
	private function pull(): void {
		$answer = $this->client->pull();
		if ( $answer instanceof CoreError ) {
			$this->failed( self::OPERATION_PULL, $answer );
			return;
		}

		if ( array() === $answer->changes ) {
			$this->logger->debug( 'Nothing to pull.', array( 'request_id' => $answer->request_id ) );
			return;
		}

		$deadline = ( $this->clock )() + self::BUDGET_SECONDS;
		$results  = array();
		foreach ( $answer->changes as $change ) {
			if ( ( $this->clock )() >= $deadline ) {
				break;
			}
			$results[] = $this->applier->apply( $change );
		}

		if ( array() === $results ) {
			return;
		}

		( $this->finish_products )();
		$results = $this->dated( $results );

		$ack         = $this->ack( $results );
		$not_started = count( $answer->changes ) - count( $results );

		$this->log_run( $answer, $results, $ack );

		if ( OutboxClient::LIMIT === count( $answer->changes ) && 0 === $not_started && $ack->is_success() ) {
			$this->scheduler->schedule_now();
		}
	}

	/**
	 * The results with each applied change's product date, read once per product.
	 *
	 * @param ChangeResult[] $results Results.
	 * @return list<ChangeResult>
	 *
	 * @phpstan-param list<ChangeResult> $results
	 */
	private function dated( array $results ): array {
		$dates = array();

		foreach ( $results as $index => $result ) {
			if ( ! $result->success || null === $result->woo_product_id ) {
				continue;
			}

			$product_id = $result->woo_product_id;
			if ( ! array_key_exists( $product_id, $dates ) ) {
				$dates[ $product_id ] = ( $this->product_date )( $product_id );
			}
			$results[ $index ] = $result->with_date( $dates[ $product_id ] );
		}

		return $results;
	}

	/**
	 * Sends the ack, once more after a network error or a 5xx.
	 *
	 * @param ChangeResult[] $results Results (at least one).
	 *
	 * @phpstan-param list<ChangeResult> $results
	 */
	private function ack( array $results ): CoreResponse {
		$response = $this->client->ack( $results );
		if ( ! $response->is_success() && self::is_transient( $response->error ) ) {
			$this->logger->core_error( self::OPERATION_ACK, $response->error );
			$response = $this->client->ack( $results );
		}

		if ( ! $response->is_success() ) {
			$this->failed( self::OPERATION_ACK, $response->error );
		}

		return $response;
	}

	/**
	 * Logs a failed call and pauses pulls when the core refuses the store.
	 *
	 * @param string    $operation Operation name.
	 * @param CoreError $error     The failure.
	 */
	private function failed( string $operation, CoreError $error ): void {
		$this->logger->core_error( $operation, $error );

		if ( in_array( $error->code, RetryPolicy::STOP_CODES, true ) ) {
			$this->scheduler->set_retry_at( ( $this->clock )() + self::PAUSE_SECONDS );
			StatusService::clear();
			$this->logger->warning( 'The core refuses the store; pulls pause for an hour.', array( 'error_code' => $error->code ) );
		}
	}

	/**
	 * Logs a run: one warning per refused change (id, action, code) and a summary.
	 *
	 * @param PullAnswer     $answer  The pull's answer.
	 * @param ChangeResult[] $results Results of the changes started.
	 * @param CoreResponse   $ack     The ack's answer.
	 *
	 * @phpstan-param list<ChangeResult> $results
	 */
	private function log_run( PullAnswer $answer, array $results, CoreResponse $ack ): void {
		$actions = array();
		foreach ( $answer->changes as $change ) {
			$actions[ $change->id ] = $change->action;
		}

		$failed = 0;
		foreach ( $results as $result ) {
			if ( $result->success ) {
				continue;
			}

			++$failed;
			$this->logger->warning(
				'A pulled change was not applied.',
				array(
					'change_id'  => $result->id,
					'action'     => $actions[ $result->id ] ?? '',
					'error_code' => (string) $result->error_code,
				)
			);
		}

		$context = array(
			'pulled'         => count( $answer->changes ),
			'applied'        => count( $results ) - $failed,
			'failed'         => $failed,
			'not_started'    => count( $answer->changes ) - count( $results ),
			'request_id'     => $answer->request_id,
			'ack_request_id' => $ack->request_id,
		);
		if ( $ack->is_success() ) {
			$context['affected'] = (int) $ack->data['affected'];
		}

		$this->logger->info( 'Pulled changes applied.', $context );
	}

	/**
	 * Whether a failure may pass on its own (no answer, or a 5xx).
	 *
	 * @param CoreError $error The failure.
	 */
	private static function is_transient( CoreError $error ): bool {
		return CoreError::NETWORK_ERROR === $error->code || $error->status >= 500;
	}

	/**
	 * Logs an unexpected failure by its class only (a message could carry data).
	 *
	 * @param Logger     $logger Safe logger.
	 * @param \Throwable $e      The failure.
	 */
	private static function log_exception( Logger $logger, \Throwable $e ): void {
		try {
			$logger->error( 'The pull failed.', array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// Logging must not fail the action either.
			unset( $ignored );
		}
	}
}
