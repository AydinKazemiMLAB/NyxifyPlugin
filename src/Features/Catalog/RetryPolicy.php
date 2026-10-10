<?php
/**
 * What a failed catalog send leads to.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Catalog;

use WooNyx\Shared\Http\CoreClient;
use WooNyx\Shared\Http\CoreError;

/**
 * Sorts a failed send into one of three outcomes and gives the backoff before the next attempt.
 *
 * - RETRY: no answer (network error, timeout), a redirect, an unusable core address, 408, 429 and 5xx. The batch goes
 *   back to the queue with one more attempt; the next try waits 1, 5, 15, then 60 minutes, or the core's
 *   `Retry-After` when longer (at most 60 minutes).
 * - STOP: the core won't take the store's data now (no subscription, store not active, a refused signature) or the
 *   connection is gone. The whole queue is dropped: the full sync catches up once the store is served again.
 * - DROP: any other refusal (422, 413, ...): sending the same batch again would fail the same way, so only it is
 *   dropped.
 */
final class RetryPolicy {

	public const RETRY = 'retry';
	public const STOP  = 'stop';
	public const DROP  = 'drop';

	/**
	 * Backoff after the 1st, 2nd, 3rd and 4th failure, in seconds (the 5th drops the row).
	 *
	 * @var list<int>
	 */
	public const DELAYS = array( 60, 300, 900, 3600 );

	/**
	 * Error codes that stop sending and drop the queue.
	 *
	 * @var list<string>
	 */
	public const STOP_CODES = array(
		'no_active_subscription',
		'store_not_active',
		'invalid_signature_headers',
		'request_timestamp_expired',
		'invalid_request_signature',
		'replayed_request',
		CoreError::NOT_ACTIVATED,
		CoreError::CONNECTION_UNREADABLE,
	);

	/**
	 * Error statuses retried like a missing answer.
	 *
	 * @var list<int>
	 */
	public const RETRY_STATUSES = array( 408, 429 );

	/**
	 * The outcome of a failure.
	 *
	 * @param CoreError $error The failure.
	 * @return string RETRY, STOP or DROP.
	 */
	public static function outcome( CoreError $error ): string {
		if ( in_array( $error->code, self::STOP_CODES, true ) ) {
			return self::STOP;
		}

		if ( 0 === $error->status ) {
			return CoreError::INVALID_PAYLOAD === $error->code ? self::DROP : self::RETRY;
		}

		if ( $error->status >= 500 || ( $error->status >= 300 && $error->status < 400 ) || in_array( $error->status, self::RETRY_STATUSES, true ) ) {
			return self::RETRY;
		}

		return self::DROP;
	}

	/**
	 * Seconds to wait before the next attempt.
	 *
	 * @param int      $failures    Failed sends of the batch's most-tried row, this one included (1 or more).
	 * @param int|null $retry_after The core's Retry-After in seconds, or null.
	 */
	public static function delay( int $failures, ?int $retry_after = null ): int {
		$index = max( 0, min( count( self::DELAYS ), $failures ) - 1 );

		return min( CoreClient::MAX_RETRY_AFTER_SECONDS, max( self::DELAYS[ $index ], (int) $retry_after ) );
	}
}
