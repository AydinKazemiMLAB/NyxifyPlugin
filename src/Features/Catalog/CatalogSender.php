<?php
/**
 * The core's catalog endpoints.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

use Nyxify\Shared\Http\CoreClient;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Options\SecretStore;
use Nyxify\Shared\Security\Encryptor;

/**
 * Sends catalog changes and the full-sync request (contract §2), each a new signed call. A successful answer is
 * checked against the endpoint's response: one that doesn't match becomes an `invalid_response` failure carrying the
 * 2xx status (the core did accept the request).
 */
final class CatalogSender {

	public const PATH_PRODUCTS          = '/catalog/webhooks/products';
	public const PATH_PRODUCTS_DELETE   = '/catalog/webhooks/products/delete';
	public const PATH_CATEGORIES        = '/catalog/webhooks/categories';
	public const PATH_CATEGORIES_DELETE = '/catalog/webhooks/categories/delete';
	public const PATH_SYNC              = '/catalog/sync';

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
	 * The sender of this site.
	 */
	public static function for_site(): self {
		return new self( new CoreClient( new SecretStore( Encryptor::for_site() ), CoreClient::configured_base_url() ) );
	}

	/**
	 * Changed products: `{"items": [{"product": {...}, "variations": [...]}]}` -> `applied`, `skipped_stale`,
	 * `skipped_plan_limit`.
	 *
	 * @param array[] $items Items as PayloadBuilder builds them.
	 *
	 * @phpstan-param list<array<string, mixed>> $items
	 */
	public function products( array $items ): CoreResponse {
		return self::checked(
			$this->client->post( self::PATH_PRODUCTS, array( 'items' => $items ) ),
			static fn( array $data ): bool => is_int( $data['applied'] ?? null )
				&& self::is_id_list( $data['skipped_stale'] ?? null )
				&& self::is_id_list( $data['skipped_plan_limit'] ?? null )
		);
	}

	/**
	 * Permanently deleted products: `{"product_ids": [...]}` -> `affected`.
	 *
	 * @param int[] $ids Product ids.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function delete_products( array $ids ): CoreResponse {
		return self::affected( $this->client->post( self::PATH_PRODUCTS_DELETE, array( 'product_ids' => $ids ) ) );
	}

	/**
	 * Changed categories: `{"items": [...]}` -> `affected`.
	 *
	 * @param array[] $items Categories as PayloadBuilder builds them.
	 *
	 * @phpstan-param list<array<string, mixed>> $items
	 */
	public function categories( array $items ): CoreResponse {
		return self::affected( $this->client->post( self::PATH_CATEGORIES, array( 'items' => $items ) ) );
	}

	/**
	 * Deleted categories: `{"category_ids": [...]}` -> `affected`.
	 *
	 * @param int[] $ids Term ids.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function delete_categories( array $ids ): CoreResponse {
		return self::affected( $this->client->post( self::PATH_CATEGORIES_DELETE, array( 'category_ids' => $ids ) ) );
	}

	/**
	 * Asks for a full sync (empty body) -> `scheduled` (false while one is running).
	 */
	public function request_sync(): CoreResponse {
		return self::checked(
			$this->client->post( self::PATH_SYNC ),
			static fn( array $data ): bool => is_bool( $data['scheduled'] ?? null )
		);
	}

	/**
	 * An answer checked for `affected`.
	 *
	 * @param CoreResponse $response Answer.
	 */
	private static function affected( CoreResponse $response ): CoreResponse {
		return self::checked( $response, static fn( array $data ): bool => is_int( $data['affected'] ?? null ) );
	}

	/**
	 * A successful answer whose data passes the check, or an `invalid_response` failure with its status.
	 *
	 * @param CoreResponse $response Answer.
	 * @param callable     $valid    Check of the data.
	 *
	 * @phpstan-param callable(array<array-key, mixed>): bool $valid
	 */
	private static function checked( CoreResponse $response, callable $valid ): CoreResponse {
		if ( ! $response->is_success() || $valid( $response->data ) ) {
			return $response;
		}

		return CoreResponse::failure( new CoreError( CoreError::INVALID_RESPONSE, $response->status, $response->request_id ) );
	}

	/**
	 * Whether a value is a list of positive integers.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_id_list( $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return false;
			}
		}

		return true;
	}
}
