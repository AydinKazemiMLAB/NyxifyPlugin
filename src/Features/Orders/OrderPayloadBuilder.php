<?php
/**
 * Building the order updates the core receives.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Orders;

use Nyxify\Features\Catalog\Batch;
use Nyxify\Features\Catalog\CatalogQueue;
use Nyxify\Shared\Logging\Logger;

/**
 * Builds the orders the core created as a short whitelist of WooCommerce REST v3 order fields, read with the order's
 * own getters in the formats of the REST API (`wc_format_decimal()` with the shop's price decimals,
 * `wc_rest_prepare_date_response()` for GMT dates). Orders are personal data: nothing else is read, so billing,
 * shipping, customer, contact, notes, payment details, the payment link and `meta_data` are never built, let alone sent.
 * Orders are read at building time, so a retry always sends the current data.
 *
 * - Missing orders, refunds, other order types and orders without the `_nyxify_order` meta have nothing to send.
 * - A deleted order goes as `{"id": N, "status": "trash"}`, without `date_modified_gmt`, so the core always applies it.
 * - Batches as Batch::collect(): at most 100 orders and about 4 MB a request; logs carry kinds, ids, sizes and
 *   exception classes, never a field.
 */
final class OrderPayloadBuilder {

	/**
	 * Order fields sent (the core's `WooOrder`, `payment_url` excluded), in this order.
	 *
	 * @var list<string>
	 */
	public const ORDER_FIELDS = array( 'id', 'status', 'number', 'currency', 'total', 'date_created_gmt', 'date_modified_gmt', 'date_paid_gmt', 'line_items' );

	/**
	 * Line item fields sent (the core's `WooOrderLineItem`), in this order.
	 *
	 * @var list<string>
	 */
	public const LINE_ITEM_FIELDS = array( 'name', 'product_id', 'variation_id', 'quantity', 'total' );

	/**
	 * The body's list key.
	 */
	private const BODY_KEY = 'orders';

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Safe logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * A batch of changed orders.
	 *
	 * @param int[] $ids Order ids, in sending order.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function orders( array $ids ): Batch {
		return Batch::collect( self::BODY_KEY, CatalogQueue::ORDER, $ids, fn( int $id ): ?array => $this->order( $id ), $this->logger );
	}

	/**
	 * A batch of deleted orders: `{"id": N, "status": "trash"}` each, without a lookup.
	 *
	 * @param int[] $ids Order ids, in sending order.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public function deleted( array $ids ): Batch {
		return Batch::collect(
			self::BODY_KEY,
			CatalogQueue::ORDER_DELETE,
			$ids,
			static fn( int $id ): array => array(
				'id'     => $id,
				'status' => 'trash',
			),
			$this->logger
		);
	}

	/**
	 * An order's fields, or null when there is nothing to send.
	 *
	 * @param int $id Order id.
	 * @return array<string, mixed>|null
	 */
	private function order( int $id ): ?array {
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || ! OrderCollector::is_nyxify_order( $order ) ) {
			return null;
		}

		$decimals = wc_get_price_decimals();

		return array(
			'id'                => $order->get_id(),
			'status'            => $order->get_status(),
			'number'            => (string) $order->get_order_number(),
			'currency'          => $order->get_currency(),
			'total'             => wc_format_decimal( $order->get_total(), $decimals ),
			// The REST API's *_gmt fields use $utc = true (the default); false gives the shop's local time.
			'date_created_gmt'  => wc_rest_prepare_date_response( $order->get_date_created() ),
			'date_modified_gmt' => wc_rest_prepare_date_response( $order->get_date_modified() ),
			'date_paid_gmt'     => wc_rest_prepare_date_response( $order->get_date_paid() ),
			'line_items'        => self::line_items( $order, $decimals ),
		);
	}

	/**
	 * The order's product lines.
	 *
	 * @param \WC_Order $order    The order.
	 * @param int       $decimals The shop's price decimals.
	 * @return list<array<string, mixed>>
	 */
	private static function line_items( \WC_Order $order, int $decimals ): array {
		$lines = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$lines[] = array(
				'name'         => $item->get_name(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'quantity'     => $item->get_quantity(),
				'total'        => wc_format_decimal( $item->get_total(), $decimals ),
			);
		}

		return $lines;
	}
}
