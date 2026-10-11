<?php
/**
 * Tests for RetryPolicy.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\RetryPolicy;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Tests\Unit\TestCase;

/**
 * Outcomes of failed sends and the backoff.
 */
final class RetryPolicyTest extends TestCase {

	/**
	 * Failures and their outcome.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string}>
	 */
	public static function failures(): array {
		return array(
			'network error or timeout' => array( CoreError::NETWORK_ERROR, 0, RetryPolicy::RETRY ),
			'unusable core address'    => array( CoreError::INVALID_BASE_URL, 0, RetryPolicy::RETRY ),
			'redirect'                 => array( CoreError::UNEXPECTED_REDIRECT, 302, RetryPolicy::RETRY ),
			'status 500'               => array( 'internal_error', 500, RetryPolicy::RETRY ),
			'502 from a proxy'         => array( CoreError::UNKNOWN_ERROR, 502, RetryPolicy::RETRY ),
			'status 503'               => array( 'service_unavailable', 503, RetryPolicy::RETRY ),
			'status 504'               => array( CoreError::UNKNOWN_ERROR, 504, RetryPolicy::RETRY ),
			'status 408'               => array( CoreError::UNKNOWN_ERROR, 408, RetryPolicy::RETRY ),
			'status 429'               => array( 'rate_limited', 429, RetryPolicy::RETRY ),
			'no subscription'          => array( 'no_active_subscription', 402, RetryPolicy::STOP ),
			'store not active'         => array( 'store_not_active', 403, RetryPolicy::STOP ),
			'bad signature headers'    => array( 'invalid_signature_headers', 401, RetryPolicy::STOP ),
			'timestamp expired'        => array( 'request_timestamp_expired', 401, RetryPolicy::STOP ),
			'bad signature'            => array( 'invalid_request_signature', 401, RetryPolicy::STOP ),
			'replayed request'         => array( 'replayed_request', 401, RetryPolicy::STOP ),
			'not activated'            => array( CoreError::NOT_ACTIVATED, 0, RetryPolicy::STOP ),
			'connection unreadable'    => array( CoreError::CONNECTION_UNREADABLE, 0, RetryPolicy::STOP ),
			'validation error'         => array( 'validation_error', 422, RetryPolicy::DROP ),
			'body too large'           => array( 'request_too_large', 413, RetryPolicy::DROP ),
			'bad request'              => array( CoreError::UNKNOWN_ERROR, 400, RetryPolicy::DROP ),
			'not found'                => array( 'not_found', 404, RetryPolicy::DROP ),
			'forbidden by a firewall'  => array( CoreError::UNKNOWN_ERROR, 403, RetryPolicy::DROP ),
			'unencodable payload'      => array( CoreError::INVALID_PAYLOAD, 0, RetryPolicy::DROP ),
		);
	}

	/**
	 * Each failure's outcome.
	 *
	 * @param string $code     Error code.
	 * @param int    $status   HTTP status.
	 * @param string $expected Outcome.
	 */
	#[DataProvider( 'failures' )]
	public function test_outcome( string $code, int $status, string $expected ): void {
		self::assertSame( $expected, RetryPolicy::outcome( new CoreError( $code, $status, 'req-1' ) ) );
	}

	/**
	 * Failures so far, Retry-After and the delay.
	 *
	 * @return array<string, array{0: int, 1: int|null, 2: int}>
	 */
	public static function delays(): array {
		return array(
			'first failure'                  => array( 1, null, 60 ),
			'second failure'                 => array( 2, null, 300 ),
			'third failure'                  => array( 3, null, 900 ),
			'fourth failure'                 => array( 4, null, 3600 ),
			'later failures'                 => array( 7, null, 3600 ),
			'zero is treated as the first'   => array( 0, null, 60 ),
			'shorter Retry-After is ignored' => array( 2, 30, 300 ),
			'longer Retry-After wins'        => array( 1, 120, 120 ),
			'Retry-After 0'                  => array( 1, 0, 60 ),
			'Retry-After capped at an hour'  => array( 1, 7200, 3600 ),
		);
	}

	/**
	 * The backoff: 1, 5, 15, 60 minutes, or a longer Retry-After, at most an hour.
	 *
	 * @param int      $failures    Failures so far, this one included.
	 * @param int|null $retry_after Retry-After seconds.
	 * @param int      $expected    Delay.
	 */
	#[DataProvider( 'delays' )]
	public function test_delay( int $failures, ?int $retry_after, int $expected ): void {
		self::assertSame( $expected, RetryPolicy::delay( $failures, $retry_after ) );
	}
}
