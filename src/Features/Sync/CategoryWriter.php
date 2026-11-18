<?php
/**
 * Writing a pulled category change to WooCommerce.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Sync;

/**
 * Applies `category.create|update|delete` through WooCommerce's own category controller (`/wc/v3/products/categories`),
 * with the dispatcher and the current user of the ChangeApplier that owns it.
 *
 * - `category.create` -> `POST`, unless a category already carries the change's `_nyxify_outbox_id` (term meta): then
 *   that one is reported and nothing is created. The meta is written right after a create, so a repeated pull never
 *   makes a second category. The answer carries the new category's id.
 * - `category.update` -> `PUT /{id}`; a category WooCommerce doesn't know fails with `category_not_found`.
 * - `category.delete` -> `DELETE /{id}?force=true` (a category has no trash). The default category (option
 *   `default_product_cat`) is refused with `default_category_protected`, as WooCommerce would refuse it; one already
 *   gone counts as deleted. WooCommerce moves the products left without a category to the default one later, by
 *   itself.
 */
final class CategoryWriter {

	public const ROUTE = '/wc/v3/products/categories';

	/**
	 * The error code of a category the core must not try to delete again.
	 */
	public const DEFAULT_PROTECTED = 'default_category_protected';

	/**
	 * The error code of a category WooCommerce doesn't know.
	 */
	public const NOT_FOUND = 'category_not_found';

	/**
	 * WooCommerce's code for a category that doesn't exist.
	 */
	private const TERM_INVALID = 'woocommerce_rest_term_invalid';

	private const TAXONOMY = 'product_cat';

	/**
	 * Sends one REST request in-process: method, route and body -> status and data.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(string, string, array<string, mixed>): array{int, mixed}
	 */
	private \Closure $dispatch;

	/**
	 * Constructor.
	 *
	 * @param \Closure $dispatch Sends one REST request.
	 *
	 * @phpstan-param \Closure(string, string, array<string, mixed>): array{int, mixed} $dispatch
	 */
	public function __construct( \Closure $dispatch ) {
		$this->dispatch = $dispatch;
	}

	/**
	 * Applies a checked category change.
	 *
	 * @param PulledChange         $change The change.
	 * @param array<string, mixed> $body   Its request body (the filter's).
	 */
	public function write( PulledChange $change, array $body ): ChangeResult {
		switch ( $change->action ) {
			case PulledChange::CATEGORY_CREATE:
				return $this->create( $change, $body );
			case PulledChange::CATEGORY_UPDATE:
				return $this->update( $change, $body );
			default:
				return $this->delete( $change );
		}
	}

	/**
	 * A create: the category the outbox id already made, or a new one.
	 *
	 * @param PulledChange         $change The change.
	 * @param array<string, mixed> $body   Its request body.
	 */
	private function create( PulledChange $change, array $body ): ChangeResult {
		$existing = self::created_category( $change->id );
		if ( null !== $existing ) {
			return ChangeResult::success( $change->id, null, $existing );
		}

		[ $status, $data ] = ( $this->dispatch )( 'POST', self::ROUTE, $body );
		if ( $status < 200 || $status >= 300 ) {
			return RestError::failure( $change->id, $data, $status );
		}

		$created = is_array( $data ) && is_int( $data['id'] ?? null ) && $data['id'] > 0 ? $data['id'] : null;
		if ( null === $created ) {
			return ChangeResult::failure( $change->id, ChangeResult::WOOCOMMERCE_ERROR, 'WooCommerce answered without a category id.' );
		}

		update_term_meta( $created, PayloadFilter::OUTBOX_META_KEY, (string) $change->id );

		return ChangeResult::success( $change->id, null, $created );
	}

	/**
	 * An update of one category.
	 *
	 * @param PulledChange         $change The change.
	 * @param array<string, mixed> $body   Its request body.
	 */
	private function update( PulledChange $change, array $body ): ChangeResult {
		$id                = (int) $change->woo_category_id;
		[ $status, $data ] = ( $this->dispatch )( 'PUT', self::ROUTE . '/' . $id, $body );

		if ( $status >= 200 && $status < 300 ) {
			return ChangeResult::success( $change->id, null, $id );
		}

		$code = RestError::code( $data );

		return RestError::failure( $change->id, $data, $status, self::TERM_INVALID === $code ? self::NOT_FOUND : $code );
	}

	/**
	 * A delete for good of one category.
	 *
	 * @param PulledChange $change The change.
	 */
	private function delete( PulledChange $change ): ChangeResult {
		$id = (int) $change->woo_category_id;

		if ( (int) get_option( 'default_product_cat', 0 ) === $id ) {
			return ChangeResult::failure( $change->id, self::DEFAULT_PROTECTED, 'The default category cannot be deleted.' );
		}

		[ $status, $data ] = ( $this->dispatch )( 'DELETE', self::ROUTE . '/' . $id, array( 'force' => true ) );

		if ( ( $status >= 200 && $status < 300 ) || self::TERM_INVALID === RestError::code( $data ) ) {
			return ChangeResult::success( $change->id, null, $id );
		}

		return RestError::failure( $change->id, $data, $status );
	}

	/**
	 * The category a create already made: one carrying its outbox id as term meta.
	 *
	 * @param int $change_id Outbox entry id.
	 */
	private static function created_category( int $change_id ): ?int {
		$ids = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
				'number'     => 1,
				'meta_key'   => PayloadFilter::OUTBOX_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only for a pulled create, at most a few a run.
				'meta_value' => (string) $change_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		$id = is_array( $ids ) ? ( $ids[0] ?? null ) : null;

		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}
}
