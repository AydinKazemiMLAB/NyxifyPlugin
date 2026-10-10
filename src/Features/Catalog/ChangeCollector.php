<?php
/**
 * Collecting catalog changes.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

use WooNyx\Shared\Logging\Logger;
use WooNyx\Shared\Options\SecretStore;
use WooNyx\Shared\Security\Encryptor;

/**
 * Listens to product, variation, stock and product category changes and keeps the changed ids in memory, per site and
 * kind; at `shutdown` (priority 0, before Action Scheduler's async runner at 10 sees whether an action is due) it
 * writes each kind with one CatalogQueue::enqueue() and schedules a flush. A save costs no query of its own.
 *
 * - Variations are queued as their parent product (the core receives a variable product with all its variations).
 * - Other post types, revisions and autosaves (post type `revision`) and `auto-draft` posts (the placeholder WordPress
 *   creates when "Add new" opens) are ignored.
 * - A permanently deleted product is queued for deletion (`before_delete_post`, while the post can still be read); a
 *   deleted variation queues its parent.
 * - A deleted category is queued for deletion; its products (WooCommerce moves them to the default category without
 *   saving them) and its child categories (moved to its parent) are queued as changed.
 * - Nothing is queued on a site without a readable connection; the core's full sync after the activation catches up.
 */
final class ChangeCollector {

	/**
	 * Priority of the `shutdown` callback.
	 */
	public const SHUTDOWN_PRIORITY = 0;

	private const PRODUCT_TAXONOMY = 'product_cat';

	/**
	 * Schedules the flush.
	 *
	 * @var FlushScheduler
	 */
	private FlushScheduler $scheduler;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Whether the current site has a readable connection.
	 *
	 * @var callable(): bool
	 */
	private $is_connected;

	/**
	 * The current site's queue.
	 *
	 * @var callable(): Queue
	 */
	private $queue;

	/**
	 * Changed ids: site id => kind => object id => true.
	 *
	 * @var array<int, array<string, array<int, true>>>
	 */
	private array $changes = array();

	/**
	 * Constructor.
	 *
	 * @param FlushScheduler $scheduler    Schedules the flush.
	 * @param Logger         $logger       Safe logger.
	 * @param callable|null  $is_connected Whether the current site has a readable connection; read from the site's
	 *                                     SecretStore by default.
	 * @param callable|null  $queue        The current site's queue; CatalogQueue::for_site() by default.
	 *
	 * @phpstan-param (callable(): bool)|null $is_connected
	 * @phpstan-param (callable(): Queue)|null $queue
	 */
	public function __construct( FlushScheduler $scheduler, Logger $logger, ?callable $is_connected = null, ?callable $queue = null ) {
		$this->scheduler    = $scheduler;
		$this->logger       = $logger;
		$this->is_connected = $is_connected ?? static fn(): bool => null !== ( new SecretStore( Encryptor::for_site() ) )->get_connection();
		$this->queue        = $queue ?? array( CatalogQueue::class, 'for_site' );
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
		add_action( 'shutdown', array( $this, 'write' ), self::SHUTDOWN_PRIORITY );
	}

	/**
	 * A product or variation was created, saved, trashed or restored, or its stock status changed.
	 *
	 * @param mixed $post_id Post id.
	 */
	public function post_changed( $post_id ): void {
		$product_id = self::product_of( self::to_id( $post_id ) );
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
		$post_id = self::to_id( $post_id );
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
		$term_id = self::to_id( $term_id );
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
	 * Writes the collected ids to each site's queue and schedules a flush there; never throws.
	 */
	public function write(): void {
		$changes       = $this->changes;
		$this->changes = array();

		$current = get_current_blog_id();

		foreach ( $changes as $site_id => $kinds ) {
			$switched = $site_id !== $current && is_multisite();
			if ( $switched ) {
				switch_to_blog( $site_id );
			}

			try {
				$this->write_site( $kinds );
			} catch ( \Throwable $e ) {
				$this->log_exception( $e );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}
	}

	/**
	 * Writes one site's ids, kind by kind, and schedules a flush if anything was queued.
	 *
	 * @param array<string, array<int, true>> $kinds Kind => object id => true.
	 */
	private function write_site( array $kinds ): void {
		if ( ! ( $this->is_connected )() ) {
			return;
		}

		$queue  = ( $this->queue )();
		$queued = false;

		foreach ( CatalogQueue::KINDS as $kind ) {
			if ( empty( $kinds[ $kind ] ) ) {
				continue;
			}

			if ( $queue->enqueue( $kind, array_keys( $kinds[ $kind ] ) ) ) {
				$queued = true;
				continue;
			}

			$this->logger->warning(
				'Catalog changes could not be queued.',
				array(
					'kind'  => $kind,
					'count' => count( $kinds[ $kind ] ),
				)
			);
		}

		if ( $queued ) {
			$this->scheduler->schedule();
		}
	}

	/**
	 * Adds ids of one kind for the current site.
	 *
	 * @param string       $kind One of CatalogQueue::KINDS.
	 * @param array<mixed> $ids  Ids; anything but a positive integer is ignored.
	 */
	private function add( string $kind, array $ids ): void {
		$site_id = get_current_blog_id();

		foreach ( $ids as $id ) {
			$id = self::to_id( $id );
			if ( $id > 0 ) {
				$this->changes[ $site_id ][ $kind ][ $id ] = true;
			}
		}
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

	/**
	 * An id from a hook argument; 0 when it isn't a positive integer.
	 *
	 * @param mixed $value Hook argument.
	 */
	private static function to_id( $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Logs an unexpected failure by its class only (a message could carry data).
	 *
	 * @param \Throwable $e The failure.
	 */
	private function log_exception( \Throwable $e ): void {
		try {
			$this->logger->error( 'Catalog changes could not be queued.', array( 'exception' => get_class( $e ) ) );
		} catch ( \Throwable $ignored ) {
			// Logging must not break the request's end either.
			unset( $ignored );
		}
	}
}
