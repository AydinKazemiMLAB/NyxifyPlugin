<?php
/**
 * The catalog queue's operations.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

/**
 * What the collector and the flush need from the queue (CatalogQueue keeps it in a table; the tests keep it in memory
 * with the same semantics). Each operation is atomic per row; see CatalogQueue.
 */
interface Queue {

	/**
	 * Queues ids; a row a flush has claimed is marked dirty and stays queued after that flush completes.
	 *
	 * @param string       $kind One of CatalogQueue::KINDS.
	 * @param array<mixed> $ids  Object ids; anything but a positive integer is ignored.
	 * @return bool Whether every write succeeded.
	 */
	public function enqueue( string $kind, array $ids ): bool;

	/**
	 * Claims up to $limit unclaimed rows of a kind, oldest first, after releasing abandoned claims.
	 *
	 * @param string $kind  One of CatalogQueue::KINDS.
	 * @param int    $limit Most rows to claim.
	 * @param string $token Claim token (CatalogQueue::new_token()).
	 * @return array<int, int> Claimed object id => failed attempts so far, oldest first.
	 */
	public function claim( string $kind, int $limit, string $token ): array;

	/**
	 * Deletes the claimed rows that weren't queued again meanwhile and releases the others (attempts reset).
	 *
	 * @param string $token Claim token.
	 * @return bool Whether it succeeded.
	 */
	public function complete( string $token ): bool;

	/**
	 * Releases claimed rows unchanged (not sent in this batch).
	 *
	 * @param string       $token Claim token.
	 * @param array<mixed> $ids   Object ids to release.
	 * @return bool Whether it succeeded.
	 */
	public function release( string $token, array $ids ): bool;

	/**
	 * Releases the claimed rows with one more failed attempt; rows that reached CatalogQueue::MAX_ATTEMPTS are dropped.
	 *
	 * @param string $token Claim token.
	 * @return int|false Rows dropped, or false on failure.
	 */
	public function retry( string $token );

	/**
	 * Deletes the claimed rows.
	 *
	 * @param string $token Claim token.
	 * @return bool Whether it succeeded.
	 */
	public function drop( string $token ): bool;

	/**
	 * Deletes every row.
	 *
	 * @return bool Whether it succeeded.
	 */
	public function clear(): bool;

	/**
	 * Whether any row waits unclaimed.
	 */
	public function has_unclaimed(): bool;
}
