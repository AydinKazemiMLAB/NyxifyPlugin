<?php
/**
 * Collecting catalog changes.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

/**
 * Listens to product, variation, stock and product category changes and adds the changed ids to the request's
 * PendingChanges, which writes them to the queue at `shutdown` and schedules a flush.
 *
 * - Variations are queued as their parent product (the core receives a variable product with all its variations).
 * - Other post types, revisions and autosaves (post type `revision`) and `auto-draft` posts (the placeholder WordPress
 *   creates when "Add new" opens) are ignored.
 * - A permanently deleted product is queued for deletion (`before_delete_post`, while the post can still be read); a
 *   deleted variation queues its parent.
 * - A deleted category is queued for deletion; its products (WooCommerce moves them to the default category without
 *   saving them) and its child categories (moved to its parent) are queued as changed.
 */
final class ChangeCollector {

	private const PRODUCT_TAXONOMY = 'product_cat';

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
	 * Hooks into WordPress and WooCommerce.
	 */
	public function register(): void {
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'save_post_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( $this, 'post_changed' ) );
		}

		add_action( 'woocommerce_product_set_stock', array( $this, 'stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'stock_changed' ) );
		add_action( 'before_delete_post', array( $this, 'post_deleting' ) );
		add_action( 'created_' . self::PRODUCT_TAXONOMY, array( $this, 'category_changed' ) );
		add_action( 'edited_' . self::PRODUCT_TAXONOMY, array( $this, 'category_changed' ) );
		add_action( 'pre_delete_term', array( $this, 'category_deleting' ), 10, 2 );
		add_action( 'delete_' . self::PRODUCT_TAXONOMY, array( $this, 'category_deleted' ), 10, 4 );
	}

	/**
	 * A product or variation was created, saved, trashed or restored, or its stock status changed.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_changed( $post_id ): void {
		$product_id = self::product_of( PendingChanges::to_id( $post_id ) );
		if ( null !== $product_id ) {
			$this->add( CatalogQueue::PRODUCT, array( $product_id ) );
		}
	}

	/**
	 * A product's or variation's stock quantity changed.
	 *
	 * @param mixed $product The product or variation object.
	 */
	public function stock_changed( $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->post_changed( $product->get_id() );
		}
	}

	/**
	 * A post is about to be deleted permanently.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_deleting( $post_id ): void {
		$post_id = PendingChanges::to_id( $post_id );
		if ( 'product' === self::post_type( $post_id ) ) {
			if ( null !== self::product_of( $post_id ) ) {
				$this->add( CatalogQueue::PRODUCT_DELETE, array( $post_id ) );
			}
			return;
		}

		$this->post_changed( $post_id );
	}

	/**
	 * A product category was created or edited.
	 *
	 * @param mixed $term_id Term id.
	 */
	public function category_changed( $term_id ): void {
		$this->add( CatalogQueue::CATEGORY, array( $term_id ) );
	}

	/**
	 * A term is about to be deleted: a product category's children will be moved to its parent without an edit hook.
	 *
	 * @param mixed $term_id  Term id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function category_deleting( $term_id, $taxonomy ): void {
		$term_id = PendingChanges::to_id( $term_id );
		if ( self::PRODUCT_TAXONOMY !== $taxonomy || $term_id < 1 ) {
			return;
		}

		$children = get_terms(
			array(
				'taxonomy'   => self::PRODUCT_TAXONOMY,
				'parent'     => $term_id,
				'fields'     => 'ids',
				'hide_empty' => false,
			)
		);
		if ( is_array( $children ) ) {
			$this->add( CatalogQueue::CATEGORY, $children );
		}
	}

	/**
	 * A product category was deleted.
	 *
	 * @param mixed $term_id      Term id.
	 * @param mixed $tt_id        Term taxonomy id.
	 * @param mixed $deleted_term The deleted term.
	 * @param mixed $object_ids   Ids of the products it held.
	 */
	public function category_deleted( $term_id, $tt_id = 0, $deleted_term = null, $object_ids = array() ): void {
		$this->add( CatalogQueue::CATEGORY_DELETE, array( $term_id ) );
		if ( is_array( $object_ids ) ) {
			$this->add( CatalogQueue::PRODUCT, $object_ids );
		}
	}

	/**
	 * Adds ids of one kind for the current site.
	 *
	 * @param string       $kind One of CatalogQueue::KINDS.
	 * @param array<mixed> $ids  Ids; anything but a positive integer is ignored.
	 */
	private function add( string $kind, array $ids ): void {
		$this->changes->add( $kind, $ids );
	}

	/**
	 * The product a post stands for: the post itself for a product, its parent for a variation; null for anything else
	 * and for `auto-draft` posts.
	 *
	 * @param int $post_id Post id.
	 */
	private static function product_of( int $post_id ): ?int {
		$type = self::post_type( $post_id );
		if ( 'product_variation' === $type ) {
			$post_id = (int) wp_get_post_parent_id( $post_id );
			$type    = self::post_type( $post_id );
		}

		if ( 'product' !== $type || 'auto-draft' === get_post_status( $post_id ) ) {
			return null;
		}

		return $post_id;
	}

	/**
	 * A post's type; revisions and autosaves have `revision`.
	 *
	 * @param int $post_id Post id.
	 * @return string|null Null for a missing post.
	 */
	private static function post_type( int $post_id ): ?string {
		if ( $post_id < 1 ) {
			return null;
		}

		$type = get_post_type( $post_id );

		return is_string( $type ) ? $type : null;
	}
}
