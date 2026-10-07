<?php
/**
 * Shared secret rotation.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\Connection;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Replaces the shared secret (contract §1.2): sends `POST /store/secret/rotate` signed with the current secret and an
 * empty body, and saves the new secret before anything signs with it.
 *
 * - The core keeps the secret that signed the rotation valid until the new one is first used (or its grace period
 *   ends), so a lost answer or a failed save keeps the old secret here and the owner simply rotates again.
 * - The secrets are never logged; only codes, statuses, the store id and request ids.
 */
final class SecretRotator {

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'store/secret/rotate';

	private const PATH = '/store/secret/rotate';

	/**
	 * Core client.
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Where the connection is saved.
	 *
	 * @var SecretStore
	 */
	private SecretStore $store;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param CoreClient  $client Core client (signs with the connection in $store).
	 * @param SecretStore $store  Where the connection is saved.
	 * @param Logger      $logger Safe logger.
	 */
	public function __construct( CoreClient $client, SecretStore $store, Logger $logger ) {
		$this->client = $client;
		$this->store  = $store;
		$this->logger = $logger;
	}

	/**
	 * The rotator of this site.
	 */
	public static function for_site(): self {
		$store = new SecretStore( Encryptor::for_site() );

		return new self( new CoreClient( $store, CoreClient::configured_base_url() ), $store, new Logger() );
	}

	/**
	 * Rotates the secret.
	 *
	 * @return CoreError|null Null when the new secret is saved.
	 */
	public function rotate(): ?CoreError {
		// The store id the request is signed with; the core's answer carries only the new secret.
		$current = $this->store->get_connection();
		if ( null === $current ) {
			// Nothing is sent; not worth a log entry (the button is only shown while connected).
			return new CoreError( $this->store->has_unreadable_connection() ? CoreError::CONNECTION_UNREADABLE : CoreError::NOT_ACTIVATED );
		}

		$response = $this->client->post( self::PATH );
		if ( ! $response->is_success() ) {
			return $this->fail( $response->error );
		}

		$connection = self::connection( $current->store_id, $response->data );
		if ( null === $connection ) {
			return $this->fail( new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
		}

		if ( ! $this->store->save_connection( $connection ) ) {
			return $this->fail( new CoreError( CoreError::SECRET_NOT_SAVED, $response->status, $response->request_id ) );
		}

		StatusService::clear();

		$this->logger->info(
			'Shared secret rotated.',
			array(
				'store_id'   => $connection->store_id,
				'request_id' => $response->request_id,
			)
		);

		return null;
	}

	/**
	 * The connection with the new secret, or null when the answer has none in the core's format.
	 *
	 * @param string                  $store_id The store id (unchanged by a rotation).
	 * @param array<array-key, mixed> $data     Decoded answer.
	 */
	private static function connection( string $store_id, array $data ): ?Connection {
		$shared_secret = $data['shared_secret'] ?? null;
		if ( ! is_string( $shared_secret ) ) {
			return null;
		}

		try {
			return new Connection( $store_id, $shared_secret );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}
	}

	/**
	 * Logs a failure and returns it.
	 *
	 * @param CoreError $error The failure.
	 */
	private function fail( CoreError $error ): CoreError {
		$this->logger->core_error( self::OPERATION, $error );

		return $error;
	}
}
