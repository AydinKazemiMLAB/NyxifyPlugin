<?php
/**
 * The shop's bot appearance settings on the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Reads the settings and saves one part at a time (`GET`/`PATCH /appearance`, contract §4.3), both signed.
 *
 * - A part sent replaces the stored one whole; null takes the shop back to the bots' defaults.
 * - The bot admins change the same document from the bot, so a form is sent with the version it was loaded on. On
 *   `409 appearance_version_conflict` the settings are read again: when the form's part is still as the form loaded
 *   it (someone changed another part), it is sent once more on the new version; otherwise the conflict is returned and
 *   nothing is saved.
 * - The values are the shop's texts and contact details: logs carry the operation, the part, the version, the error
 *   code, the status and the request id only.
 * - Without a readable connection nothing is sent or logged.
 */
final class AppearanceService {

	public const OPERATION_GET = 'appearance/get';

	public const OPERATION_UPDATE = 'appearance/update';

	public const VERSION_CONFLICT = 'appearance_version_conflict';

	private const PATH = '/appearance';

	private const PART_PATTERN = '/^[a-z][a-z_]{0,31}$/D';

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
	 * The settings (`GET /appearance`), not cached.
	 */
	public function get(): AppearanceView|CoreError {
		$response = $this->client->get( self::PATH );
		if ( ! $response->is_success() ) {
			return $this->fail( self::OPERATION_GET, $response->error );
		}

		$view = AppearanceView::from_response( $response->data );

		return $view ?? $this->fail( self::OPERATION_GET, new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
	}

	/**
	 * Saves one part (`PATCH /appearance`).
	 *
	 * @param string                    $part    Part name, e.g. `contact`.
	 * @param array<string, mixed>|null $value   The part, or null for the bots' defaults.
	 * @param int                       $version The version the form was loaded on.
	 * @param string                    $hash    The part's hash as the form loaded it (AppearanceView::hash()).
	 * @return CoreError|null Null when the part was saved.
	 */
	public function save( string $part, ?array $value, int $version, string $hash ): ?CoreError {
		if ( 1 !== preg_match( self::PART_PATTERN, $part ) || $version < 0 ) {
			return new CoreError( CoreError::INVALID_PAYLOAD );
		}

		$response = $this->patch( $part, $value, $version );
		if ( ! $response->is_success() && self::VERSION_CONFLICT === $response->error->code ) {
			$current = $this->get();
			if ( $current instanceof CoreError ) {
				return $current;
			}

			if ( ! hash_equals( $current->hash( $part ), $hash ) ) {
				return $this->fail( self::OPERATION_UPDATE, $response->error );
			}

			$response = $this->patch( $part, $value, $current->version );
		}

		if ( ! $response->is_success() ) {
			return $this->fail( self::OPERATION_UPDATE, $response->error );
		}

		$saved = $response->data['version'] ?? null;
		$this->logger->info(
			'Appearance saved.',
			array(
				'part'       => $part,
				'version'    => is_int( $saved ) ? $saved : null,
				'request_id' => $response->request_id,
			)
		);

		return null;
	}

	/**
	 * Sends one part on a version.
	 *
	 * @param string                    $part    Part name.
	 * @param array<string, mixed>|null $value   The part, or null.
	 * @param int                       $version Version.
	 */
	private function patch( string $part, ?array $value, int $version ): CoreResponse {
		return $this->client->patch(
			self::PATH,
			array(
				'version'  => $version,
				'settings' => array( $part => $value ),
			)
		);
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
