<?php
/**
 * The bots' admin PIN.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Bots;

use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Issues a new admin PIN (contract §4.1): `POST /bots/admin-pin`, signed, with no body or `{"revoke_admins": true}`
 * to also remove every bot admin. The core keeps only a hash; the PIN is saved here encrypted (SecretStore) before
 * success is reported, and the settings page is the only place it is ever shown.
 *
 * - A new PIN replaces the old one on the core, so when the new one can't be saved the old one is deleted here too
 *   (it no longer works) and the owner is asked to issue another.
 * - The PIN is never logged, put in a notice, a URL or a cache; logs carry the operation, error code, status, the
 *   revoke flag and the request id only.
 * - Without a readable connection nothing is sent or logged.
 */
final class AdminPinIssuer {

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'bots/admin-pin';

	private const PATH = '/bots/admin-pin';

	private const PIN_PATTERN = '/^\d{6}$/D';

	/**
	 * Core client.
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Where the PIN is saved.
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
	 * @param CoreClient  $client Core client.
	 * @param SecretStore $store  Where the PIN is saved.
	 * @param Logger      $logger Safe logger.
	 */
	public function __construct( CoreClient $client, SecretStore $store, Logger $logger ) {
		$this->client = $client;
		$this->store  = $store;
		$this->logger = $logger;
	}

	/**
	 * The issuer of this site.
	 */
	public static function for_site(): self {
		$store = new SecretStore( Encryptor::for_site() );

		return new self( new CoreClient( $store, CoreClient::configured_base_url() ), $store, new Logger() );
	}

	/**
	 * Issues a new PIN and saves it.
	 *
	 * @param bool $revoke_admins Whether to also remove admin access from everyone who has it.
	 * @return CoreError|null Null when the new PIN is saved.
	 */
	public function issue( bool $revoke_admins ): ?CoreError {
		$response = $this->client->post( self::PATH, $revoke_admins ? array( 'revoke_admins' => true ) : null );
		if ( ! $response->is_success() ) {
			return $this->fail( $response->error );
		}

		$pin = $response->data['pin'] ?? null;
		if ( ! is_string( $pin ) || 1 !== preg_match( self::PIN_PATTERN, $pin ) ) {
			// The core may have replaced the PIN anyway; the stored one is kept, issuing again fixes either case.
			return $this->fail( new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
		}

		if ( ! $this->store->save_admin_pin( $pin ) ) {
			// The previous PIN stopped working on the core; don't keep showing it.
			$this->store->delete_admin_pin();

			return $this->fail( new CoreError( CoreError::PIN_NOT_SAVED, $response->status, $response->request_id ) );
		}

		$this->logger->info(
			'Admin PIN issued.',
			array(
				'revoke_admins' => $revoke_admins,
				'request_id'    => $response->request_id,
			)
		);

		return null;
	}

	/**
	 * Logs a failure (unless nothing was sent for lack of a connection) and returns it.
	 *
	 * @param CoreError $error The failure.
	 */
	private function fail( CoreError $error ): CoreError {
		if ( ! in_array( $error->code, array( CoreError::NOT_ACTIVATED, CoreError::CONNECTION_UNREADABLE ), true ) ) {
			$this->logger->core_error( self::OPERATION, $error );
		}

		return $error;
	}
}
