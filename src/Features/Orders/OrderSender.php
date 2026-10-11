<?php
/**
 * The core's order status endpoint.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Orders;

use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;

/**
 * Sends order updates (contract §3, `POST /orders/webhooks/status`), each a new signed call. A successful answer must be
 * `{"affected": int}`: one that doesn't match becomes an `invalid_response` failure carrying the 2xx status (the core
 * did accept the request).
 */
final class OrderSender {

	public const PATH_STATUS = '/orders/webhooks/status';

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
	 * Changed or deleted orders: `{"orders": [...]}` -> `affected` (the core's orders matched).
	 *
	 * @param array[] $orders Orders as OrderPayloadBuilder builds them.
	 *
	 * @phpstan-param list<array<string, mixed>> $orders
	 */
	public function status( array $orders ): CoreResponse {
		$response = $this->client->post( self::PATH_STATUS, array( 'orders' => $orders ) );

		if ( ! $response->is_success() || is_int( $response->data['affected'] ?? null ) ) {
			return $response;
		}

		return CoreResponse::failure( new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
	}
}
