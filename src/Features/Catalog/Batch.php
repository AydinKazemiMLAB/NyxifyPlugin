<?php
/**
 * One batch of objects to send.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Logging\Logger;

/**
 * What a builder (PayloadBuilder, Orders\OrderPayloadBuilder) made of a list of claimed ids: the objects to send, the
 * ids they stand for, the ids with nothing to send (missing, not sendable, too large or failed: done with) and the ids
 * left for the next flush (over the batch's size).
 */
final class Batch {

	/**
	 * Objects per batch (the core accepts 1–100 catalog items and 1–100 orders).
	 */
	public const MAX_ITEMS = 100;

	/**
	 * A batch stops before its body passes this size (the core accepts 10 MB).
	 */
	public const BATCH_BYTES = 4 * 1024 * 1024;

	/**
	 * An object over this size is skipped.
	 */
	public const MAX_ITEM_BYTES = 9 * 1024 * 1024;

	/**
	 * Objects to send, in the order of the ids.
	 *
	 * @var list<array<string, mixed>>
	 */
	public readonly array $items;

	/**
	 * Ids of the objects in $items.
	 *
	 * @var list<int>
	 */
	public readonly array $built;

	/**
	 * Ids with nothing to send.
	 *
	 * @var list<int>
	 */
	public readonly array $skipped;

	/**
	 * Ids not reached: to release for the next flush.
	 *
	 * @var list<int>
	 */
	public readonly array $left;

	/**
	 * Size of the body (`{"items":[…]}`, `{"orders":[…]}`) in bytes, as the client encodes it.
	 *
	 * @var int
	 */
	public readonly int $bytes;

	/**
	 * Constructor.
	 *
	 * @param array[] $items   Objects to send.
	 * @param int[]   $built   Their ids.
	 * @param int[]   $skipped Ids with nothing to send.
	 * @param int[]   $left    Ids not reached.
	 * @param int     $bytes   Body size.
	 *
	 * @phpstan-param list<array<string, mixed>> $items
	 * @phpstan-param list<int> $built
	 * @phpstan-param list<int> $skipped
	 * @phpstan-param list<int> $left
	 */
	public function __construct( array $items, array $built, array $skipped, array $left, int $bytes ) {
		$this->items   = $items;
		$this->built   = $built;
		$this->skipped = $skipped;
		$this->left    = $left;
		$this->bytes   = $bytes;
	}

	/**
	 * Builds objects in the order of the ids until the batch is full: at most MAX_ITEMS objects, stopping before the
	 * body passes BATCH_BYTES (the ids not reached are left for the next flush). An object over BATCH_BYTES goes alone;
	 * one over MAX_ITEM_BYTES, one that can't be encoded and one whose building throws are skipped with a warning that
	 * carries the kind, the id and the size or exception class, never the object.
	 *
	 * @param string                                     $key    The body's list key (`items`, `orders`).
	 * @param string                                     $kind   Queue kind, for the logs.
	 * @param int[]                                      $ids    Ids, in sending order.
	 * @param callable(int): (array<string, mixed>|null) $build  Builds one object; null when there is nothing to send.
	 * @param Logger                                     $logger Safe logger.
	 *
	 * @phpstan-param list<int> $ids
	 */
	public static function collect( string $key, string $kind, array $ids, callable $build, Logger $logger ): self {
		$items   = array();
		$built   = array();
		$skipped = array();
		$left    = array();
		$bytes   = strlen( '{"' . $key . '":[]}' );

		$skip = static function ( string $message, int $id, array $context = array() ) use ( $logger, $kind ): void {
			$logger->warning(
				$message,
				array(
					'kind' => $kind,
					'id'   => $id,
				) + $context
			);
		};

		foreach ( $ids as $index => $id ) {
			if ( count( $items ) >= self::MAX_ITEMS || $bytes > self::BATCH_BYTES ) {
				$left = array_slice( $ids, $index );
				break;
			}

			try {
				$item = $build( $id );
			} catch ( \Throwable $e ) {
				$skip( 'An object could not be built; it is skipped.', $id, array( 'exception' => get_class( $e ) ) );
				$skipped[] = $id;
				continue;
			}

			if ( null === $item ) {
				$skipped[] = $id;
				continue;
			}

			$json = wp_json_encode( $item, CoreClient::JSON_FLAGS );
			if ( ! is_string( $json ) ) {
				$skip( 'An object could not be encoded; it is skipped.', $id );
				$skipped[] = $id;
				continue;
			}

			$size = strlen( $json );
			if ( $size > self::MAX_ITEM_BYTES ) {
				$skip( 'An object is too large to send; it is skipped.', $id, array( 'bytes' => $size ) );
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

		return new self( $items, $built, $skipped, $left, $bytes );
	}
}
