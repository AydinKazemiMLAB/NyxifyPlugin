<?php
/**
 * An in-memory catalog queue for the flush tests.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use Nyxify\Features\Catalog\CatalogQueue;
use Nyxify\Features\Catalog\Queue;

/**
 * The semantics of CatalogQueue's SQL (its tests pin the statements) on an array: rows keyed by kind and id, claims by
 * token, dirty marks for rows queued again while claimed, attempts, abandoned claims released after
 * CatalogQueue::CLAIM_TIMEOUT_SECONDS.
 */
final class InMemoryQueue implements Queue {

	/**
	 * Rows: "kind:id" => row.
	 *
	 * @var array<string, array{kind: string, id: int, queued_at: int, claim: string|null, claimed_at: int|null, dirty: int, attempts: int}>
	 */
	public array $rows = array();

	/**
	 * Order of insertion (stands in for queued_at).
	 *
	 * @var int
	 */
	private int $sequence = 0;

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable $clock Current Unix time.
	 *
	 * @phpstan-param callable(): int $clock
	 */
	public function __construct( callable $clock ) {
		$this->clock = $clock;
	}

	/**
	 * Adds rows with attempts already made.
	 *
	 * @param string          $kind     Kind.
	 * @param array<int, int> $attempts Id => failed attempts so far.
	 */
	public function seed( string $kind, array $attempts ): void {
		$this->enqueue( $kind, array_keys( $attempts ) );
		foreach ( $attempts as $id => $count ) {
			$this->rows[ $kind . ':' . $id ]['attempts'] = $count;
		}
	}

	/**
	 * Rows as "kind:id" => [claimed, dirty, attempts], sorted.
	 *
	 * @return array<string, array{0: bool, 1: int, 2: int}>
	 */
	public function state(): array {
		$state = array();
		foreach ( $this->rows as $key => $row ) {
			$state[ $key ] = array( null !== $row['claim'], $row['dirty'], $row['attempts'] );
		}
		ksort( $state );

		return $state;
	}

	public function enqueue( string $kind, array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				continue;
			}

			$key = $kind . ':' . $id;
			if ( ! isset( $this->rows[ $key ] ) ) {
				$this->rows[ $key ] = array(
					'kind'       => $kind,
					'id'         => $id,
					'queued_at'  => ++$this->sequence,
					'claim'      => null,
					'claimed_at' => null,
					'dirty'      => 0,
					'attempts'   => 0,
				);
			} elseif ( null !== $this->rows[ $key ]['claim'] ) {
				$this->rows[ $key ]['dirty'] = 1;
			}
		}

		return true;
	}

	public function claim( string $kind, int $limit, string $token ): array {
		$now = ( $this->clock )();

		foreach ( $this->rows as $key => $row ) {
			if ( null !== $row['claimed_at'] && $row['claimed_at'] < $now - CatalogQueue::CLAIM_TIMEOUT_SECONDS ) {
				$this->rows[ $key ]['claim']      = null;
				$this->rows[ $key ]['claimed_at'] = null;
			}
		}

		$free = array_filter( $this->rows, static fn( array $row ): bool => $kind === $row['kind'] && null === $row['claim'] );
		uasort( $free, static fn( array $a, array $b ): int => array( $a['queued_at'], $a['id'] ) <=> array( $b['queued_at'], $b['id'] ) );

		$claimed = array();
		foreach ( array_slice( $free, 0, $limit, true ) as $key => $row ) {
			$this->rows[ $key ]    = array_merge(
				$row,
				array(
					'claim'      => $token,
					'claimed_at' => $now,
					'dirty'      => 0,
				)
			);
			$claimed[ $row['id'] ] = $row['attempts'];
		}

		return $claimed;
	}

	public function complete( string $token ): bool {
		foreach ( $this->rows as $key => $row ) {
			if ( $token !== $row['claim'] ) {
				continue;
			}

			if ( 0 === $row['dirty'] ) {
				unset( $this->rows[ $key ] );
				continue;
			}

			$this->rows[ $key ] = array_merge(
				$row,
				array(
					'claim'      => null,
					'claimed_at' => null,
					'dirty'      => 0,
					'attempts'   => 0,
				)
			);
		}

		return true;
	}

	public function release( string $token, array $ids ): bool {
		foreach ( $this->rows as $key => $row ) {
			if ( $token === $row['claim'] && in_array( $row['id'], $ids, true ) ) {
				$this->rows[ $key ]['claim']      = null;
				$this->rows[ $key ]['claimed_at'] = null;
			}
		}

		return true;
	}

	/**
	 * Releases the claimed rows with one more attempt; rows that reached MAX_ATTEMPTS are dropped.
	 *
	 * @param string $token Claim token.
	 * @return int Rows dropped.
	 */
	public function retry( string $token ) {
		$dropped = 0;
		foreach ( $this->rows as $key => $row ) {
			if ( $token !== $row['claim'] ) {
				continue;
			}

			if ( $row['attempts'] + 1 >= CatalogQueue::MAX_ATTEMPTS ) {
				unset( $this->rows[ $key ] );
				++$dropped;
				continue;
			}

			$this->rows[ $key ] = array_merge(
				$row,
				array(
					'claim'      => null,
					'claimed_at' => null,
					'dirty'      => 0,
					'attempts'   => $row['attempts'] + 1,
				)
			);
		}

		return $dropped;
	}

	public function drop( string $token ): bool {
		$this->rows = array_filter( $this->rows, static fn( array $row ): bool => $token !== $row['claim'] );

		return true;
	}

	public function clear(): bool {
		$this->rows = array();

		return true;
	}

	public function has_unclaimed(): bool {
		foreach ( $this->rows as $row ) {
			if ( null === $row['claim'] ) {
				return true;
			}
		}

		return false;
	}
}
