<?php
/**
 * The store's bots on the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Bots;

use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Lists, registers and removes the store's bots (contract §4.1), all signed.
 *
 * - One bot per platform: registering a token for a platform that has a bot replaces it. The core checks the token
 *   with the platform before storing it and sets the webhook in the background (`pending` until then).
 * - The token is sent once and never logged; logs carry the operation, error code, status, platform and request id
 *   only, never the bot's names or the core's `last_error`.
 * - Without a readable connection nothing is sent or logged (the settings page explains that state itself).
 */
final class BotService {

	public const OPERATION_LIST = 'bots/list';

	public const OPERATION_REGISTER = 'bots/register';

	public const OPERATION_REMOVE = 'bots/remove';

	private const PATH = '/bots';

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
	 * The store's bots (`GET /bots`), not cached.
	 *
	 * @return list<Bot>|CoreError
	 */
	public function list(): array|CoreError {
		$response = $this->client->get( self::PATH );
		if ( ! $response->is_success() ) {
			return $this->fail( self::OPERATION_LIST, $response->error );
		}

		$bots = self::bots( $response->data );
		if ( null === $bots ) {
			return $this->fail( self::OPERATION_LIST, new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
		}

		return $bots;
	}

	/**
	 * Connects a bot token on a platform, replacing the store's bot there (`POST /bots`).
	 *
	 * The core asks the platform about the token first, so this uses the long timeout. Its answer (the bot, `pending`)
	 * isn't needed: the settings page lists the bots again after the redirect.
	 *
	 * @param string   $platform A known platform (BotPlatform).
	 * @param BotToken $token    The token, sent once.
	 * @return CoreError|null Null when the core accepted the token.
	 */
	public function register( string $platform, BotToken $token ): ?CoreError {
		if ( ! BotPlatform::is_known( $platform ) ) {
			return new CoreError( CoreError::INVALID_PAYLOAD );
		}

		$response = $this->client->post(
			self::PATH,
			array(
				'platform' => $platform,
				'token'    => $token->value(),
			),
			CoreClient::LONG_TIMEOUT_SECONDS
		);

		return $this->done( self::OPERATION_REGISTER, 'Bot registered.', $platform, $response );
	}

	/**
	 * Removes the store's bot on a platform (`DELETE /bots/{platform}`).
	 *
	 * @param string $platform A known platform (BotPlatform).
	 * @return CoreError|null Null when the core removed it.
	 */
	public function remove( string $platform ): ?CoreError {
		if ( ! BotPlatform::is_known( $platform ) ) {
			return new CoreError( CoreError::INVALID_PAYLOAD );
		}

		$response = $this->client->delete( self::PATH . '/' . $platform );

		return $this->done( self::OPERATION_REMOVE, 'Bot removed.', $platform, $response );
	}

	/**
	 * Logs the result of a change and returns its error, if any.
	 *
	 * @param string       $operation Operation name.
	 * @param string       $message   Success message.
	 * @param string       $platform  Platform.
	 * @param CoreResponse $response  The core's answer.
	 */
	private function done( string $operation, string $message, string $platform, CoreResponse $response ): ?CoreError {
		if ( ! $response->is_success() ) {
			return $this->fail( $operation, $response->error );
		}

		$this->logger->info(
			$message,
			array(
				'platform'   => $platform,
				'request_id' => $response->request_id,
			)
		);

		return null;
	}

	/**
	 * The bots of the answer, or null when it isn't a list of valid bots.
	 *
	 * @param array<array-key, mixed> $data Decoded answer.
	 * @return list<Bot>|null
	 */
	private static function bots( array $data ): ?array {
		if ( ! array_is_list( $data ) ) {
			return null;
		}

		$bots = array();
		foreach ( $data as $item ) {
			$bot = Bot::from_response( $item );
			if ( null === $bot ) {
				return null;
			}
			$bots[] = $bot;
		}

		return $bots;
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
