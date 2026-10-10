<?php
/**
 * Building the catalog objects the core receives.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Logging\Logger;

/**
 * Builds products (with their variations) and product categories as WooCommerce REST v3 objects, through
 * WooCommerce's own controllers with a `view`-context request: the objects the core's full sync reads. The controllers'
 * prepare methods check no permission, so a flush without a user builds the same objects as the REST API. Objects are
 * read from the database at building time, so a retry always sends the current data.
 *
 * - Only the fields the core reads leave the shop (the whitelists below): `meta_data` (other plugins' data, possibly
 *   personal or secret) never does. Products are also requested with `_fields`, which spares WooCommerce the rest.
 * - A product goes as an item `{"product": {...}, "variations": [...]}`. A variable product's item carries the complete
 *   list of its variations, of every status; over MAX_VARIATIONS the key is left out (the core keeps the variations it
 *   has) and a warning is logged. Other types have no `variations` key (the core keeps what it has).
 * - Missing objects, variations queued as products and `auto-draft` products have nothing to send.
 * - A batch holds at most MAX_ITEMS objects and stops before passing BATCH_BYTES; the ids not reached are left for the
 *   next flush. An object over BATCH_BYTES goes alone, one over MAX_ITEM_BYTES is skipped with a warning.
 * - Logs carry kinds, ids, counts and exception classes, never an object's content.
 */
final class PayloadBuilder {

	/**
	 * Product fields the core reads (its `WooProduct`).
	 *
	 * @var list<string>
	 */
	public const PRODUCT_FIELDS = array( 'id', 'name', 'slug', 'type', 'status', 'catalog_visibility', 'permalink', 'sku', 'price', 'regular_price', 'sale_price', 'on_sale', 'purchasable', 'manage_stock', 'stock_quantity', 'backorders_allowed', 'stock_status', 'description', 'short_description', 'images', 'categories', 'attributes', 'date_created_gmt', 'date_modified_gmt' );

	/**
	 * Variation fields the core reads (its `WooVariation`).
	 *
	 * @var list<string>
	 */
	public const VARIATION_FIELDS = array( 'id', 'sku', 'status', 'description', 'permalink', 'price', 'regular_price', 'sale_price', 'on_sale', 'purchasable', 'manage_stock', 'stock_quantity', 'backorders_allowed', 'stock_status', 'image', 'attributes', 'menu_order', 'date_modified_gmt' );

	/**
	 * Category fields the core reads (its `WooCategory`).
	 *
	 * @var list<string>
	 */
	public const CATEGORY_FIELDS = array( 'id', 'name', 'slug', 'parent', 'description', 'image', 'menu_order', 'count' );

	/**
	 * Objects per batch (the core accepts 1–100 items).
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Variations per product the core accepts (more fail the whole batch).
	 */
	public const MAX_VARIATIONS = 1000;

	/**
	 * A batch stops before its body passes this size (the core accepts 10 MB).
	 */
	public const BATCH_BYTES = 4 * 1024 * 1024;

	/**
	 * An object over this size is skipped.
	 */
	public const MAX_ITEM_BYTES = 9 * 1024 * 1024;

	/**
	 * Size of an empty body, `{"items":[]}`.
	 */
	private const EMPTY_BODY_BYTES = 12;

	private const CATEGORY_TAXONOMY = 'product_cat';

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Creates a controller of the class given.
	 *
	 * @var callable(class-string): object
	 */
	private $controllers;

	/**
	 * Creates a GET request with the parameters given.
	 *
	 * @var callable(array<string, string>): \WP_REST_Request
	 */
	private $requests;

	/**
	 * Controllers created so far, by class.
	 *
	 * @var array<class-string, object>
	 */
	private array $instances = array();

	/**
	 * Constructor.
	 *
	 * @param Logger        $logger      Safe logger.
	 * @param callable|null $controllers Creates a controller of the class given; `new $class()` by default.
	 * @param callable|null $requests    Creates a GET request with the parameters given; a WP_REST_Request by default.
	 *
	 * @phpstan-param (callable(class-string): object)|null $controllers
	 * @phpstan-param (callable(array<string, string>): \WP_REST_Request)|null $requests
	 */
	public function __construct( Logger $logger, ?callable $controllers = null, ?callable $requests = null ) {
		$this->logger      = $logger;
		$this->controllers = $controllers ?? static fn( string $class_name ): object => new $class_name();
		$this->requests    = $requests ?? array( self::class, 'new_request' );
	}

	/**
	 * A GET request with the parameters given.
	 *
	 * @param array<string, string> $params Parameters.
	 */
	public static function new_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * A batch of products.
	 *
	 * @param int[] $ids Product ids, in sending order.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function products( array $ids ): Batch {
		return $this->batch( CatalogQueue::PRODUCT, $ids, fn( int $id ): ?array => $this->product( $id ) );
	}

	/**
	 * A batch of product categories.
	 *
	 * @param int[] $ids Term ids, in sending order.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function categories( array $ids ): Batch {
		return $this->batch( CatalogQueue::CATEGORY, $ids, fn( int $id ): ?array => $this->category( $id ) );
	}

	/**
	 * Builds objects in order until the batch is full.
	 *
	 * @param string                                     $kind  Queue kind, for the logs.
	 * @param int[]                                      $ids   Ids.
	 * @param callable(int): (array<string, mixed>|null) $build Builds one object; null when there is nothing to send.
	 *
	 * @phpstan-param list<int> $ids
	 */
	private function batch( string $kind, array $ids, callable $build ): Batch {
		$items   = array();
		$built   = array();
		$skipped = array();
		$left    = array();
		$bytes   = self::EMPTY_BODY_BYTES;

		foreach ( $ids as $index => $id ) {
			if ( count( $items ) >= self::MAX_ITEMS || $bytes > self::BATCH_BYTES ) {
				$left = array_slice( $ids, $index );
				break;
			}

			try {
				$item = $build( $id );
			} catch ( \Throwable $e ) {
				$this->skip( 'A catalog object could not be built; it is skipped.', $kind, $id, array( 'exception' => get_class( $e ) ) );
				$skipped[] = $id;
				continue;
			}

			if ( null === $item ) {
				$skipped[] = $id;
				continue;
			}

			$json = wp_json_encode( $item, CoreClient::JSON_FLAGS );
			if ( ! is_string( $json ) ) {
				$this->skip( 'A catalog object could not be encoded; it is skipped.', $kind, $id );
				$skipped[] = $id;
				continue;
			}

			$size = strlen( $json );
			if ( $size > self::MAX_ITEM_BYTES ) {
				$this->skip( 'A catalog object is too large to send; it is skipped.', $kind, $id, array( 'bytes' => $size ) );
				$skipped[] = $id;
				continue;
			}

			$size += array() === $items ? 0 : 1;
			if ( array() !== $items && $bytes + $size > self::BATCH_BYTES ) {
				$left = array_slice( $ids, $index );
				break;
			}

			$items[] = $item;
			$built[] = $id;
			$bytes  += $size;
		}//end foreach

		return new Batch( $items, $built, $skipped, $left, $bytes );
	}

	/**
	 * A product item, `{"product": {...}, "variations": [...]}` (`variations` for variable products only), or null when
	 * there is nothing to send.
	 *
	 * @param int $id Product id.
	 * @return array<string, mixed>|null
	 */
	private function product( int $id ): ?array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product || $product->is_type( 'variation' ) || 'auto-draft' === $product->get_status() ) {
			return null;
		}

		$controller = $this->controller( \WC_REST_Products_Controller::class );
		$request    = $this->request( array( '_fields' => implode( ',', self::PRODUCT_FIELDS ) ) );
		$item       = array( 'product' => self::only( self::data( $controller->prepare_object_for_response( $product, $request ) ), self::PRODUCT_FIELDS ) );

		if ( $product->is_type( 'variable' ) ) {
			$variations = $this->variations( $id );
			if ( null !== $variations ) {
				$item['variations'] = $variations;
			}
		}

		return $item;
	}

	/**
	 * Every variation of a product, of every status, by menu order; null when there are too many to send.
	 *
	 * @param int $product_id Product id.
	 * @return list<array<string, mixed>>|null
	 */
	private function variations( int $product_id ): ?array {
		$ids = get_posts(
			array(
				'post_parent' => $product_id,
				'post_type'   => 'product_variation',
				'post_status' => 'any',
				'fields'      => 'ids',
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);

		if ( count( $ids ) > self::MAX_VARIATIONS ) {
			$this->logger->warning(
				'A product has more variations than the core accepts; its variations are not sent.',
				array(
					'product_id' => $product_id,
					'count'      => count( $ids ),
				)
			);
			return null;
		}

		$variations = array();
		foreach ( $ids as $variation_id ) {
			$variation = wc_get_product( (int) $variation_id );
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}

			$controller   = $this->controller( \WC_REST_Product_Variations_Controller::class );
			$variations[] = self::only( self::data( $controller->prepare_object_for_response( $variation, $this->request() ) ), self::VARIATION_FIELDS );
		}

		return $variations;
	}

	/**
	 * A product category, or null when it doesn't exist.
	 *
	 * @param int $id Term id.
	 * @return array<string, mixed>|null
	 */
	private function category( int $id ): ?array {
		$term = get_term( $id, self::CATEGORY_TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$controller = $this->controller( \WC_REST_Product_Categories_Controller::class );

		return self::only( self::data( $controller->prepare_item_for_response( $term, $this->request() ) ), self::CATEGORY_FIELDS );
	}

	/**
	 * The controller of a class, created once.
	 *
	 * @template T of object
	 * @param string $class_name Controller class.
	 * @phpstan-param class-string<T> $class_name
	 * @return T
	 *
	 * @throws \UnexpectedValueException When the factory returns something else.
	 */
	private function controller( string $class_name ): object {
		if ( ! isset( $this->instances[ $class_name ] ) ) {
			$this->instances[ $class_name ] = ( $this->controllers )( $class_name );
		}

		$controller = $this->instances[ $class_name ];
		if ( ! $controller instanceof $class_name ) {
			throw new \UnexpectedValueException( 'Not a controller of the class asked for.' );
		}

		return $controller;
	}

	/**
	 * A `view`-context GET request.
	 *
	 * @param array<string, string> $params Other parameters.
	 */
	private function request( array $params = array() ): \WP_REST_Request {
		return ( $this->requests )( array( 'context' => 'view' ) + $params );
	}

	/**
	 * The data of a controller's response.
	 *
	 * @param mixed $response Response.
	 * @return array<array-key, mixed>
	 *
	 * @throws \UnexpectedValueException When it isn't a response holding an object.
	 */
	private static function data( $response ): array {
		$data = $response instanceof \WP_REST_Response ? $response->get_data() : null;
		if ( ! is_array( $data ) ) {
			throw new \UnexpectedValueException( 'The controller returned no object.' );
		}

		return $data;
	}

	/**
	 * The whitelisted fields of an object, in the whitelist's order; fields it lacks stay out.
	 *
	 * @param array<array-key, mixed> $data   Object.
	 * @param string[]                $fields Whitelist.
	 * @return array<string, mixed>
	 */
	private static function only( array $data, array $fields ): array {
		$item = array();
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$item[ $field ] = $data[ $field ];
			}
		}

		return $item;
	}

	/**
	 * Logs a skipped object by kind and id.
	 *
	 * @param string               $message Message.
	 * @param string               $kind    Queue kind.
	 * @param int                  $id      Object id.
	 * @param array<string, mixed> $context More context.
	 */
	private function skip( string $message, string $kind, int $id, array $context = array() ): void {
		$this->logger->warning(
			$message,
			array(
				'kind' => $kind,
				'id'   => $id,
			) + $context
		);
	}
}
