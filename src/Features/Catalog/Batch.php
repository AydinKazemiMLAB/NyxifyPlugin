<?php
/**
 * One batch of catalog objects to send.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

/**
 * What PayloadBuilder made of a list of claimed ids: the objects to send, the ids they stand for, the ids with nothing
 * to send (missing, not sendable, too large or failed: done with) and the ids left for the next flush (over the batch's
 * size).
 */
final class Batch {

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
	 * Size of `{"items":[…]}` in bytes, as the client encodes it.
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
}
