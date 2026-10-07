<?php
/**
 * The store's bot admins on the core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Bots;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Lists the store's bot admins and removes one (contract §4.1), both signed.
 *
 * - A bot admin is a messenger user who sent the admin PIN to one of the store's bots. Removing one takes away their
 *   admin access only; the current PIN still works for them.
 * - Names, usernames and messenger ids are personal data: they exist only in memory while the settings page renders.
 *   Logs carry the operation, error code, status and request id only.
 * - Without a readable connection nothing is sent or logged (the settings page explains that state itself).
 */
final class AdminService {

	public const OPERATION_LIST = 'bots/admins/list';

	public const OPERATION_REVOKE = 'bots/admins/revoke';

	private const PATH = '/bots/admins';

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
	 * The service of this site.
	 */
	public static function for_site(): self {
		return new self( new CoreClient( new SecretStore( Encryptor::for_site() ), CoreClient::configured_base_url() ), new Logger() );
	}

	/**
	 * The store's bot admins (`GET /bots/admins`), not cached.
	 *
	 * @return list<BotAdmin>|CoreError
	 */
	public function list(): array|CoreError {
		$response = $this->client->get( self::PATH );
		if ( ! $response->is_success() ) {
			return $this->fail( self::OPERATION_LIST, $response->error );
		}

		$admins = self::admins( $response->data );
		if ( null === $admins ) {
			return $this->fail( self::OPERATION_LIST, new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
		}

		return $admins;
	}

	/**
	 * Takes away a bot admin's access (`DELETE /bots/admins/{id}`).
	 *
	 * @param int $id The core's id of the admin (BotAdmin::$id).
	 * @return CoreError|null Null when the core removed them; `customer_not_found` when they weren't an admin (any
	 *                        more).
	 */
	public function revoke( int $id ): ?CoreError {
		if ( $id < 1 ) {
			return new CoreError( CoreError::INVALID_PAYLOAD );
		}

		$response = $this->client->delete( self::PATH . '/' . $id );
		if ( ! $response->is_success() ) {
			return $this->fail( self::OPERATION_REVOKE, $response->error );
		}

		$this->logger->info( 'Bot admin removed.', array( 'request_id' => $response->request_id ) );

		return null;
	}

	/**
	 * The admins of the answer, or null when it isn't a list of valid admins.
	 *
	 * @param array<array-key, mixed> $data Decoded answer.
	 * @return list<BotAdmin>|null
	 */
	private static function admins( array $data ): ?array {
		if ( ! array_is_list( $data ) ) {
			return null;
		}

		$admins = array();
		foreach ( $data as $item ) {
			$admin = BotAdmin::from_response( $item );
			if ( null === $admin ) {
				return null;
			}
			$admins[] = $admin;
		}

		return $admins;
	}

	/**
	 * Logs a failure (unless nothing was sent for lack of a connection) and returns it.
	 *
	 * @param string    $operation Operation name.
	 * @param CoreError $error     The failure.
	 */
	private function fail( string $operation, CoreError $error ): CoreError {
		if ( ! in_array( $error->code, array( CoreError::NOT_ACTIVATED, CoreError::CONNECTION_UNREADABLE ), true ) ) {
			$this->logger->core_error( $operation, $error );
		}

		return $error;
	}
}
