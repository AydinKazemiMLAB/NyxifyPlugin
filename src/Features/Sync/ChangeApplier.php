<?php
/**
 * Writing a pulled change to WooCommerce.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Sync;

use WooNyx\Features\Store\RestKeys;
use WooNyx\Shared\Logging\Logger;

/**
 * Applies one pulled change through WooCommerce's own REST controllers, in-process (`rest_do_request()`), as the user
 * who owns the plugin's REST key: the user the core's push writes as, so permissions, validation, image downloads and
 * hooks (the catalog webhooks included) are the same as for a push.
 *
 * - `product.create` -> `POST /wc/v3/products`, unless a product (in any status, the trash included) already carries
 *   the change's `_woonyx_outbox_id`: then that product is reported and nothing is created.
 * - `product.update` -> `PUT /wc/v3/products/{id}`; `product.delete` -> `DELETE /wc/v3/products/{id}` without
 *   `force` (the trash); one already in the trash or gone counts as deleted.
 * - `variation.update` -> `PUT /wc/v3/products/{pid}/variations/{vid}`.
 * - Errors as the core's push reports them: WooCommerce's code when the core can take it (else `woocommerce_error`)
 *   and its message with tags stripped and every link replaced (an image download error carries the signed link).
 * - While a change is applied, every HTTP request's timeout is capped at DOWNLOAD_TIMEOUT_SECONDS (WordPress gives an
 *   image download 300 s). The cap is removed and the previous current user restored afterwards, whatever happened.
 * - Logs carry the change's id and action and an exception's class only, never a payload, a message or a link.
 */
final class ChangeApplier {

	/**
	 * The longest wait for one HTTP request (an image download) while a change is applied, in seconds.
	 */
	public const DOWNLOAD_TIMEOUT_SECONDS = 15;

	public const ROUTE_PRODUCTS = '/wc/v3/products';

	/**
	 * Answers to a delete meaning the product is already in the trash, or gone.
	 *
	 * @var list<string>
	 */
	public const DELETE_DONE_CODES = array( 'woocommerce_rest_already_trashed', 'woocommerce_rest_product_invalid_id' );

	/**
	 * The message of a change the plugin failed on (the exception's own message may hold anything).
	 */
	public const PLUGIN_ERROR_MESSAGE = 'The plugin failed while applying the change.';

	public const REST_USER_MISSING_MESSAGE = "The plugin's WooCommerce REST key or its user is missing: activate the store again.";

	private const LINK_PATTERN = '#\b[a-z][a-z0-9+.-]*://[^\s<>"\']+#i';

	private const LINK = '[link]';

	/**
	 * Fields check.
	 *
	 * @var PayloadFilter
	 */
	private PayloadFilter $filter;

	/**
	 * The plugin's REST key.
	 *
	 * @var RestKeys
	 */
	private RestKeys $keys;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Sends one REST request in-process: method, route and body -> status and data.
	 *
	 * @var \Closure
	 * @phpstan-var \Closure(string, string, array<string, mixed>): array{int, mixed}
	 */
	private \Closure $dispatch;

	/**
	 * The REST key's user, once read (the applier lives for one run).
	 *
	 * @var int|null
	 */
	private ?int $user_id = null;

	/**
	 * Whether the REST key's user was read.
	 *
	 * @var bool
	 */
	private bool $user_read = false;

	/**
	 * Constructor.
	 *
	 * @param PayloadFilter $filter   Fields check.
	 * @param RestKeys      $keys     The plugin's REST key.
	 * @param Logger        $logger   Safe logger.
	 * @param \Closure|null $dispatch Sends one REST request; rest_do_request() when null.
	 *
	 * @phpstan-param (\Closure(string, string, array<string, mixed>): array{int, mixed})|null $dispatch
	 */
	public function __construct( PayloadFilter $filter, RestKeys $keys, Logger $logger, ?\Closure $dispatch = null ) {
		$this->filter   = $filter;
		$this->keys     = $keys;
		$this->logger   = $logger;
		$this->dispatch = $dispatch ?? static function ( string $method, string $route, array $body ): array {
			$request = new \WP_REST_Request( $method, $route );
			$request->set_body_params( $body );
			$response = rest_do_request( $request );

			return array( $response->get_status(), $response->get_data() );
		};
	}

	/**
	 * The applier of this site, for one run.
	 */
	public static function for_site(): self {
		return new self( new PayloadFilter(), new RestKeys(), new Logger() );
	}

	/**
	 * Applies a change.
	 *
	 * @param PulledChange $change The change.
	 */
	public function apply( PulledChange $change ): ChangeResult {
		$body = $this->filter->check( $change );
		if ( $body instanceof ChangeResult ) {
			return $body;
		}

		$user_id = $this->user_id();
		if ( null === $user_id ) {
			return ChangeResult::failure( $change->id, ChangeResult::REST_USER_MISSING, self::REST_USER_MISSING_MESSAGE );
		}

		$previous_user = get_current_user_id();
		wp_set_current_user( $user_id );
		add_filter( 'http_request_args', array( $this, 'cap_timeout' ), PHP_INT_MAX );

		try {
			return $this->write( $change, $body );
		} catch ( \Throwable $exception ) {
			$this->logger->error(
				'Applying a pulled change failed.',
				array(
					'change_id' => $change->id,
					'action'    => $change->action,
					'exception' => get_class( $exception ),
				)
			);

			return ChangeResult::failure( $change->id, ChangeResult::PLUGIN_ERROR, self::PLUGIN_ERROR_MESSAGE );
		} finally {
			remove_filter( 'http_request_args', array( $this, 'cap_timeout' ), PHP_INT_MAX );
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Caps an HTTP request's timeout at DOWNLOAD_TIMEOUT_SECONDS (`http_request_args`, only while a change is applied).
	 *
	 * @param mixed $args Request arguments.
	 * @return mixed
	 */
	public function cap_timeout( $args ) {
		if ( is_array( $args ) && ( ! is_numeric( $args['timeout'] ?? null ) || (float) $args['timeout'] > self::DOWNLOAD_TIMEOUT_SECONDS ) ) {
			$args['timeout'] = self::DOWNLOAD_TIMEOUT_SECONDS;
		}

		return $args;
	}

	/**
	 * Sends the change's request and reads WooCommerce's answer.
	 *
	 * @param PulledChange         $change The change (checked).
	 * @param array<string, mixed> $body   Its request body.
	 */
	private function write( PulledChange $change, array $body ): ChangeResult {
		$is_create = PulledChange::PRODUCT_CREATE === $change->action;

		if ( $is_create ) {
			$existing = self::created_product( $change->id );
			if ( null !== $existing ) {
				$this->logger->info(
					'Pulled create found already applied.',
					array(
						'change_id'  => $change->id,
						'product_id' => $existing,
					)
				);

				return ChangeResult::success( $change->id, $existing );
			}
		}

		[ $method, $route ] = self::request_of( $change );
		[ $status, $data ]  = ( $this->dispatch )( $method, $route, $body );

		if ( $status >= 200 && $status < 300 ) {
			if ( ! $is_create ) {
				return ChangeResult::success( $change->id, $change->woo_product_id );
			}

			$created = is_array( $data ) && is_int( $data['id'] ?? null ) && $data['id'] > 0 ? $data['id'] : null;

			return null !== $created
				? ChangeResult::success( $change->id, $created )
				: ChangeResult::failure( $change->id, ChangeResult::WOOCOMMERCE_ERROR, 'WooCommerce answered without a product id.' );
		}

		$code = self::error_code( $data );
		if ( PulledChange::PRODUCT_DELETE === $change->action && in_array( $code, self::DELETE_DONE_CODES, true ) ) {
			return ChangeResult::success( $change->id, $change->woo_product_id );
		}

		return ChangeResult::failure( $change->id, $code, self::error_message( $data, $status ) );
	}

	/**
	 * The method and route of a checked change.
	 *
	 * @param PulledChange $change The change.
	 * @return array{string, string}
	 */
	private static function request_of( PulledChange $change ): array {
		$product = self::ROUTE_PRODUCTS . '/' . (int) $change->woo_product_id;

		switch ( $change->action ) {
			case PulledChange::PRODUCT_CREATE:
				return array( 'POST', self::ROUTE_PRODUCTS );
			case PulledChange::PRODUCT_DELETE:
				return array( 'DELETE', $product );
			case PulledChange::VARIATION_UPDATE:
				return array( 'PUT', $product . '/variations/' . (int) $change->woo_variation_id );
			default:
				return array( 'PUT', $product );
		}
	}

	/**
	 * The product a create already made: one carrying its outbox id, in any status, the trash included.
	 *
	 * @param int $change_id Outbox entry id.
	 */
	private static function created_product( int $change_id ): ?int {
		$ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'any', 'trash' ),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_key'               => PayloadFilter::OUTBOX_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only for a pulled create, at most a few a run.
				'meta_value'             => (string) $change_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		$id = $ids[0] ?? null;

		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	/**
	 * The REST key's user, read once; null when the key, its row or the user is gone.
	 */
	private function user_id(): ?int {
		if ( ! $this->user_read ) {
			$user_id         = $this->keys->user_id();
			$this->user_id   = null !== $user_id && false !== get_userdata( $user_id ) ? $user_id : null;
			$this->user_read = true;
		}

		return $this->user_id;
	}

	/**
	 * WooCommerce's error code when the core can take it, else `woocommerce_error`.
	 *
	 * @param mixed $data The error answer.
	 */
	private static function error_code( $data ): string {
		$code = is_array( $data ) ? ( $data['code'] ?? null ) : null;

		return is_string( $code ) && 1 === preg_match( ChangeResult::CODE_PATTERN, $code ) ? $code : ChangeResult::WOOCOMMERCE_ERROR;
	}

	/**
	 * WooCommerce's message without tags and links, or one naming the status when it has none.
	 *
	 * @param mixed $data   The error answer.
	 * @param int   $status Its HTTP status.
	 */
	private static function error_message( $data, int $status ): string {
		$message = is_array( $data ) && is_string( $data['message'] ?? null ) ? $data['message'] : '';
		$message = (string) preg_replace( self::LINK_PATTERN, self::LINK, wp_strip_all_tags( $message ) );
		$message = trim( (string) preg_replace( '/\s+/u', ' ', $message ) );

		return '' !== $message ? $message : sprintf( 'WooCommerce answered with HTTP %d.', $status );
	}
}
