<?php
/**
 * The core's outbox endpoints.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Shared\Http\CoreResponse;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Pulls the changes the core couldn't push and acknowledges them (contract §5.2), each a new signed call.
 *
 * - A successful answer is checked against the endpoint's response: one that doesn't match becomes an
 *   `invalid_response` failure carrying the 2xx status. A pull answer with one malformed change is refused whole, so
 *   nothing of it is applied; its changes come back when their claim expires.
 * - Nothing is logged here (the run logs counts, ids, codes and request ids): answers carry products' content.
 */
final class OutboxClient {

	public const PATH_PULL = '/sync/outbox';

	public const PATH_ACK = '/sync/outbox/ack';

	/**
	 * Changes asked for per pull (the core allows 1–100).
	 */
	public const LIMIT = 20;

	/**
	 * The most results the core takes in one ack.
	 */
	public const MAX_RESULTS = 100;

	/**
	 * Core client.
	 *
	 * @var CoreClient
	 */
	private CoreClient $client;

	/**
	 * Constructor.
	 *
	 * @param CoreClient $client Core client.
	 */
	public function __construct( CoreClient $client ) {
		$this->client = $client;
	}

	/**
	 * The client of this site.
	 */
	public static function for_site(): self {
		return new self( new CoreClient( new SecretStore( Encryptor::for_site() ), CoreClient::configured_base_url() ) );
	}

	/**
	 * Claims up to LIMIT pending changes (`GET /sync/outbox?limit=20`) -> `{"changes": [...], "claimed_until": ...}`.
	 *
	 * @return PullAnswer|CoreError The changes (none when nothing is pending), or why the pull failed.
	 */
	public function pull(): PullAnswer|CoreError {
		$response = $this->client->get( self::PATH_PULL, array( 'limit' => self::LIMIT ) );
		if ( ! $response->is_success() ) {
			return $response->error;
		}

		$changes = self::changes( $response->data );
		if ( null === $changes ) {
			return new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id );
		}

		return new PullAnswer( $changes, $response->request_id );
	}

	/**
	 * Reports the outcome of pulled changes (`POST /sync/outbox/ack`, `{"results": [...]}`) -> `affected`, the results
	 * the core recorded (those still claimed by the plugin).
	 *
	 * @param ChangeResult[] $results 1 to MAX_RESULTS results.
	 *
	 * @phpstan-param list<ChangeResult> $results
	 */
	public function ack( array $results ): CoreResponse {
		if ( array() === $results || count( $results ) > self::MAX_RESULTS ) {
			return CoreResponse::failure( new CoreError( CoreError::INVALID_PAYLOAD ) );
		}

		$response = $this->client->post(
			self::PATH_ACK,
			array( 'results' => array_map( static fn( ChangeResult $result ): array => $result->to_ack(), $results ) )
		);

		if ( ! $response->is_success() || ( is_int( $response->data['affected'] ?? null ) && $response->data['affected'] >= 0 ) ) {
			return $response;
		}

		return CoreResponse::failure( new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
	}

	/**
	 * The changes of a pull answer, or null when it isn't `{"changes": [valid changes], "claimed_until": string|null}`.
	 *
	 * @param array<array-key, mixed> $data Decoded answer.
	 * @return list<PulledChange>|null
	 */
	private static function changes( array $data ): ?array {
		$items         = $data['changes'] ?? null;
		$claimed_until = $data['claimed_until'] ?? null;

		if ( ! is_array( $items ) || ! array_is_list( $items ) || ! array_key_exists( 'claimed_until', $data )
			|| ! ( null === $claimed_until || is_string( $claimed_until ) )
		) {
			return null;
		}

		$changes = array();
		foreach ( $items as $item ) {
			$change = PulledChange::from_response( $item );
			if ( null === $change ) {
				return null;
			}
			$changes[] = $change;
		}

		return $changes;
	}
}
