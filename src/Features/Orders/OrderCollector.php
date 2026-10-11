<?php
/**
 * Collecting order changes.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Orders;

use Nyxify\Features\Catalog\CatalogQueue;
use Nyxify\Features\Catalog\PendingChanges;

/**
 * Listens to changes of the orders the core created (those with the `_nyxify_order` meta) and adds their ids to the
 * request's PendingChanges. Orders the core didn't create are never queued: they hold customers' personal data and
 * are none of the core's business. Only the id is kept; the order is read again when it is sent.
 *
 * - Every save (`woocommerce_new_order`, `woocommerce_update_order`), status change, trash, restore and refund queues
 *   the order. HPOS trashes an order with a direct update (no save hook) and fires `woocommerce_untrash_order` before
 *   the status is restored: the order is read when it is sent, so its current status goes.
 * - `woocommerce_before_delete_order` (the order and its meta still readable) queues it for deletion.
 * - Shops on the posts storage trash, restore and delete orders from the posts list without WooCommerce's order hooks:
 *   `trashed_post`, `untrashed_post` and `before_delete_post` for `shop_order` posts cover them.
 * - A refund is never queued itself (`woocommerce_order_refunded` passes its parent).
 */
final class OrderCollector {

	/**
	 * The meta the core's checkout puts on its orders.
	 */
	public const MARKER = '_nyxify_order';

	private const ORDER_TYPE = 'shop_order';

	/**
	 * The request's changes.
	 *
	 * @var PendingChanges
	 */
	private PendingChanges $changes;

	/**
	 * Constructor.
	 *
	 * @param PendingChanges $changes The request's changes.
	 */
	public function __construct( PendingChanges $changes ) {
		$this->changes = $changes;
	}

	/**
	 * Hooks into WooCommerce and WordPress.
	 */
	public function register(): void {
		add_action( 'woocommerce_new_order', array( $this, 'order_changed' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'order_changed' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'status_changed' ), 10, 4 );
		add_action( 'woocommerce_trash_order', array( $this, 'order_changed' ) );
		add_action( 'woocommerce_untrash_order', array( $this, 'order_changed' ) );
		add_action( 'woocommerce_order_refunded', array( $this, 'order_changed' ) );
		add_action( 'woocommerce_before_delete_order', array( $this, 'order_deleting' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'post_changed' ) );
		add_action( 'untrashed_post', array( $this, 'post_changed' ) );
		add_action( 'before_delete_post', array( $this, 'post_deleting' ) );
	}

	/**
	 * An order was created, saved, trashed, restored or refunded.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    The order, when the hook passes it (anything else is ignored).
	 */
	public function order_changed( $order_id, $order = null ): void {
		$order_id = PendingChanges::to_id( $order_id );
		if ( self::is_nyxify_order( self::order( $order_id, $order ) ) ) {
			$this->changes->add( CatalogQueue::ORDER, array( $order_id ) );
		}
	}

	/**
	 * An order's status changed.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $from     Previous status.
	 * @param mixed $to       New status.
	 * @param mixed $order    The order.
	 */
	public function status_changed( $order_id, $from = '', $to = '', $order = null ): void {
		$this->order_changed( $order_id, $order );
	}

	/**
	 * An order is about to be deleted permanently.
	 *
	 * @param mixed $order_id Order id.
	 * @param mixed $order    The order, when the hook passes it.
	 */
	public function order_deleting( $order_id, $order = null ): void {
		$order_id = PendingChanges::to_id( $order_id );
		if ( self::is_nyxify_order( self::order( $order_id, $order ) ) ) {
			$this->changes->add( CatalogQueue::ORDER_DELETE, array( $order_id ) );
		}
	}

	/**
	 * A post was trashed or restored (posts storage).
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_changed( $post_id ): void {
		$post_id = PendingChanges::to_id( $post_id );
		if ( self::is_order_post( $post_id ) ) {
			$this->order_changed( $post_id );
		}
	}

	/**
	 * A post is about to be deleted permanently (posts storage).
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_deleting( $post_id ): void {
		$post_id = PendingChanges::to_id( $post_id );
		if ( self::is_order_post( $post_id ) ) {
			$this->order_deleting( $post_id );
		}
	}

	/**
	 * Whether an order was created by the core: an order (not a refund) carrying the marker.
	 *
	 * @param mixed $order The order.
	 */
	public static function is_nyxify_order( $order ): bool {
		if ( ! $order instanceof \WC_Order || self::ORDER_TYPE !== $order->get_type() ) {
			return false;
		}

		$marker = $order->get_meta( self::MARKER );

		return is_scalar( $marker ) && '' !== (string) $marker;
	}

	/**
	 * The order a hook is about: the object it passed when that is the order with this id, otherwise read.
	 *
	 * @param int   $order_id Order id.
	 * @param mixed $order    The object the hook passed, if any.
	 * @return mixed The order, or whatever wc_get_order() returns for a missing one.
	 */
	private static function order( int $order_id, $order ) {
		if ( $order_id < 1 ) {
			return null;
		}

		if ( $order instanceof \WC_Order && $order->get_id() === $order_id ) {
			return $order;
		}

		return wc_get_order( $order_id );
	}

	/**
	 * Whether a post is an order of the posts storage (HPOS's placeholders and copies of other types are ignored).
	 *
	 * @param int $post_id Post id.
	 */
	private static function is_order_post( int $post_id ): bool {
		return $post_id > 0 && self::ORDER_TYPE === get_post_type( $post_id );
	}
}
