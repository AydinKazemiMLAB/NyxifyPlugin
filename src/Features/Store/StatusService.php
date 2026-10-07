<?php
/**
 * The store's status, from the core with a short cache.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;

/**
 * `GET /store/status` (signed, contract §1.3) for the settings page, cached for five minutes in a transient. Only
 * successes are cached; activation, rotation, deactivation and the "Check connection" button clear the cache.
 */
final class StatusService {

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'store/status';

	/**
	 * Transient holding the last status (no secrets).
	 */
	public const TRANSIENT = 'woonyx_store_status';

	/**
	 * Seconds a status is reused.
	 */
	public const CACHE_SECONDS = 300;

	private const PATH = '/store/status';

	/**
	 * Core client.
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param CoreClient $client Core client.
	 * @param Logger     $logger Safe logger.
	 */
	public function __construct( CoreClient $client, Logger $logger ) {
		$this->client = $client;
		$this->logger = $logger;
	}

	/**
	 * The status: cached when fresh, otherwise fetched (and cached when it succeeds).
	 *
	 * @return StoreStatus|CoreError The status, or why it couldn't be fetched (already logged).
	 */
	public function get(): StoreStatus|CoreError {
		$cached = StoreStatus::from_array( get_transient( self::TRANSIENT ) );
		if ( null !== $cached ) {
			return $cached;
		}

		$response = $this->client->get( self::PATH );
		if ( ! $response->is_success() ) {
			// Without a connection nothing was sent; the page explains that state itself.
			if ( ! in_array( $response->error->code, array( CoreError::NOT_ACTIVATED, CoreError::CONNECTION_UNREADABLE ), true ) ) {
				$this->logger->core_error( self::OPERATION, $response->error );
			}

			return $response->error;
		}

		$status = StoreStatus::from_response( $response->data, time() );
		if ( null === $status ) {
			$error = new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id );
			$this->logger->core_error( self::OPERATION, $error );

			return $error;
		}

		set_transient( self::TRANSIENT, $status->to_array(), self::CACHE_SECONDS );

		return $status;
	}

	/**
	 * Forgets the cached status, so the next page load asks the core.
	 */
	public static function clear(): void {
		delete_transient( self::TRANSIENT );
	}
}
