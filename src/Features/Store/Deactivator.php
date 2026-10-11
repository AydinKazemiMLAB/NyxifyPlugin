<?php
/**
 * Telling the core the plugin was deactivated.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Features\Catalog\CatalogQueue;
use WooNyx\Features\Catalog\FlushScheduler;
use WooNyx\Features\Catalog\PlanLimitNotice;
use WooNyx\Features\Catalog\Schema;
use WooNyx\Features\Sync\PullLock;
use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Runs from the plugin's deactivation hook (contract §1.4): sends the signed `POST /store/deactivate` (empty body) and,
 * once the core confirms, deletes the connection, the plugin's REST key and the cached status. Reconnecting then needs
 * the license key again.
 *
 * - Not connected (or the connection can't be read): nothing is sent and nothing is deleted.
 * - Any failure (network, an error from the core): everything is kept and only the error code is logged.
 * - Deactivation is never blocked: nothing is thrown, and the requests are short. WordPress saves the list of active
 *   plugins only after this hook, so a request that outlived PHP's time limit would leave the plugin active.
 * - Network deactivation: every site that is connected and doesn't keep the plugin active on its own, within a time
 *   budget; sites left over keep their connection (logged).
 * - Catalog changes still queued, and the pending flushes, are dropped on every site where the plugin stops, whatever
 *   the core answers: the plugin no longer sends them, and the core's full sync after the next activation catches up.
 *   The pulls are cancelled there too, with their pause and lock.
 */
final class Deactivator {

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'store/deactivate';

	/**
	 * Timeout of one request, in seconds (shorter than an ordinary call: the owner waits on the plugins page).
	 */
	public const TIMEOUT_SECONDS = 10;

	/**
	 * How long a network deactivation may spend on requests in all, in seconds.
	 */
	public const NETWORK_BUDGET_SECONDS = 30;

	private const PATH = '/store/deactivate';

	/**
	 * Core client (signs with the connection in $store).
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Where the connection is kept.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * The plugin's REST keys.
	 *
	 * @var RestKeys
	 */
	private RestKeys $rest_keys;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Current time in seconds.
	 *
	 * @var callable(): float
	 */
	private $clock;

	/**
	 * Drops the current site's queued catalog changes.
	 *
	 * @var callable(): void
	 */
	private $stop_catalog;

	/**
	 * Constructor.
	 *
	 * @param CoreClient    $client       Core client.
	 * @param SecretStore   $store        Where the connection is kept.
	 * @param RestKeys      $rest_keys    The plugin's REST keys.
	 * @param Logger        $logger       Safe logger.
	 * @param callable|null $clock        Current time in seconds; microtime( true ) by default.
	 * @param callable|null $stop_catalog Drops the current site's queued catalog changes; stop_site_catalog() by default.
	 *
	 * @phpstan-param (callable(): float)|null $clock
	 * @phpstan-param (callable(): void)|null $stop_catalog
	 */
	public function __construct( CoreClient $client, SecretStore $store, RestKeys $rest_keys, Logger $logger, ?callable $clock = null, ?callable $stop_catalog = null ) {
		$this->client       = $client;
		$this->store        = $store;
		$this->rest_keys    = $rest_keys;
		$this->logger       = $logger;
		$this->clock        = $clock ?? static fn(): float => microtime( true );
		$this->stop_catalog = $stop_catalog ?? array( self::class, 'stop_site_catalog' );
	}

	/**
	 * Cancels the current site's pending catalog flushes and pulls, forgets their backoff, pause and lock and the
	 * plan-limit notice, and empties its queue, if its table was ever created.
	 */
	public static function stop_site_catalog(): void {
		FlushScheduler::unschedule_all();
		( new FlushScheduler() )->clear_retry_at();
		PlanLimitNotice::clear();
		PullScheduler::unschedule_all();
		PullScheduler::clear_retry_at();
		delete_option( PullLock::OPTION );

		if ( Schema::is_installed() ) {
			CatalogQueue::for_site()->clear();
		}
	}

	/**
	 * The deactivator of this site (its options are read at call time, so it also serves the sites of a network).
	 */
	public static function for_site(): self {
		$store = new SecretStore( Encryptor::for_site() );

		return new self( new CoreClient( $store, CoreClient::configured_base_url() ), $store, new RestKeys(), new Logger() );
	}

	/**
	 * The deactivation hook: never throws.
	 *
	 * @param bool   $network_wide    Whether the plugin is being deactivated for the whole network.
	 * @param string $plugin_basename The plugin's basename, e.g. `woonyx/woonyx.php`.
	 */
	public static function run( bool $network_wide, string $plugin_basename ): void {
		try {
			self::for_site()->handle( $network_wide, $plugin_basename );
		} catch ( \Throwable $e ) {
			self::log_exception( new Logger(), $e );
		}
	}

	/**
	 * Deactivates this site's store, or every connected site's on a network deactivation; never throws.
	 *
	 * @param bool   $network_wide    Whether the plugin is being deactivated for the whole network.
	 * @param string $plugin_basename The plugin's basename.
	 */
	public function handle( bool $network_wide, string $plugin_basename ): void {
		$network = false;

		try {
			$network = $network_wide && is_multisite();
			if ( $network ) {
				$this->deactivate_network( $plugin_basename );
			} else {
				$this->deactivate();
			}
		} catch ( \Throwable $e ) {
			self::log_exception( $this->logger, $e );
		}

		// A network deactivation stops each site's catalog inside its loop.
		if ( ! $network ) {
			$this->stop_catalog();
		}
	}

	/**
	 * Deactivates the current site's store.
	 *
	 * @param int $timeout Seconds.
	 * @return bool Whether the core confirmed and the local data was removed.
	 */
	public function deactivate( int $timeout = self::TIMEOUT_SECONDS ): bool {
		$connection = $this->store->get_connection();
		if ( null === $connection ) {
			return false;
		}

		$response = $this->client->post( self::PATH, null, $timeout );
		if ( ! $response->is_success() ) {
			$this->logger->core_error( self::OPERATION, $response->error );
			return false;
		}

		$this->store->delete_connection();
		StatusService::clear();
		$this->delete_rest_key();

		$this->logger->info(
			'Store deactivated.',
			array(
				'store_id'   => $connection->store_id,
				'request_id' => $response->request_id,
			)
		);

		return true;
	}

	/**
	 * Deactivates the store of every connected site that doesn't keep the plugin active on its own.
	 *
	 * @param string $plugin_basename The plugin's basename.
	 */
	private function deactivate_network( string $plugin_basename ): void {
		$deadline = ( $this->clock )() + self::NETWORK_BUDGET_SECONDS;
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );

			try {
				if ( self::keeps_plugin_active( $plugin_basename ) ) {
					continue;
				}

				$this->stop_catalog();

				if ( null === $this->store->get_connection() ) {
					continue;
				}

				$remaining = (int) floor( $deadline - ( $this->clock )() );
				if ( $remaining < 1 ) {
					$this->logger->warning( 'No time left to deactivate this site\'s store; its connection is kept.', array( 'site_id' => $site_id ) );
					continue;
				}

				$this->deactivate( min( self::TIMEOUT_SECONDS, $remaining ) );
			} finally {
				restore_current_blog();
			}//end try
		}//end foreach
	}

	/**
	 * Whether the current site keeps the plugin active on its own (a plugin activated on a site before it was
	 * network-activated stays active there after a network deactivation).
	 *
	 * @param string $plugin_basename The plugin's basename.
	 */
	private static function keeps_plugin_active( string $plugin_basename ): bool {
		$active = get_option( 'active_plugins', array() );

		return is_array( $active ) && in_array( $plugin_basename, $active, true );
	}

	/**
	 * Drops the current site's queued catalog changes; a failure is logged and never stops the deactivation.
	 */
	private function stop_catalog(): void {
		try {
			( $this->stop_catalog )();
		} catch ( \Throwable $e ) {
			self::log_exception( $this->logger, $e );
		}
	}

	/**
	 * Deletes the plugin's REST key; its id is kept when the delete fails, so a later activation or the uninstall can
	 * still remove it.
	 */
	private function delete_rest_key(): void {
		$key_id = $this->rest_keys->current_id();
		if ( null === $key_id ) {
			return;
		}

		if ( ! $this->rest_keys->delete( $key_id ) ) {
			$this->logger->warning( 'A WooNyx REST key could not be deleted.', array( 'key_id' => $key_id ) );
			return;
		}

		$this->rest_keys->forget();
	}

	/**
	 * Logs an unexpected failure by its class only (a message could carry data).
	 *
	 * @param Logger     $logger Safe logger.
	 * @param \Throwable $e      The failure.
	 */
	private static function log_exception( Logger $logger, \Throwable $e ): void {
		try {
			$logger->error( 'The deactivation cleanup failed.', array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// Logging must not break the deactivation either.
			unset( $ignored );
		}
	}
}
