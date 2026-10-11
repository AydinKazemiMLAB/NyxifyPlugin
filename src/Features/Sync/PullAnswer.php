<?php
/**
 * The core's answer to a pull.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Sync;

/**
 * The changes of a valid `GET /sync/outbox` answer, oldest first and claimed by the plugin for the core's claim time
 * (300 s), and the request id to match the core's logs.
 */
final class PullAnswer {

	/**
	 * The pulled changes, in the core's order.
	 *
	 * @var list<PulledChange>
	 */
	public readonly array $changes;

	/**
	 * The core's X-Request-ID (or the one the plugin sent).
	 *
	 * @var string
	 */
	public readonly string $request_id;

	/**
	 * Constructor.
	 *
	 * @param PulledChange[] $changes    The pulled changes.
	 * @param string         $request_id Request id.
	 *
	 * @phpstan-param list<PulledChange> $changes
	 */
	public function __construct( array $changes, string $request_id ) {
		$this->changes    = $changes;
		$this->request_id = $request_id;
	}
}
