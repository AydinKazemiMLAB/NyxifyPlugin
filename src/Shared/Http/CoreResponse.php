<?php
/**
 * The result of a call to the core.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Http;

/**
 * Either the decoded JSON of a 2xx answer (an object, a list such as `GET /bots`, or nothing for a 204), or a
 * CoreError.
 */
final class CoreResponse {

	/**
	 * HTTP status, or 0 when no response came back.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Decoded JSON of a successful answer (object or list); empty for a 204 and on failure.
	 *
	 * @var array<array-key, mixed>
	 */
	public readonly array $data;

	/**
	 * The core's X-Request-ID (or the one the plugin sent).
	 *
	 * @var string
	 */
	public readonly string $request_id;

	/**
	 * Why the call failed, or null on success.
	 *
	 * @var CoreError|null
	 */
	public readonly ?CoreError $error;

	/**
	 * Constructor.
	 *
	 * @param int                     $status     HTTP status or 0.
	 * @param array<array-key, mixed> $data    Decoded body.
	 * @param string                  $request_id Request id.
	 * @param CoreError|null          $error      Failure, or null.
	 */
	private function __construct( int $status, array $data, string $request_id, ?CoreError $error ) {
		$this->status     = $status;
		$this->data       = $data;
		$this->request_id = $request_id;
		$this->error      = $error;
	}

	/**
	 * A successful answer.
	 *
	 * @param int                     $status     HTTP status (2xx).
	 * @param array<array-key, mixed> $data    Decoded JSON.
	 * @param string                  $request_id Request id.
	 */
	public static function success( int $status, array $data, string $request_id ): self {
		return new self( $status, $data, $request_id, null );
	}

	/**
	 * A failed call.
	 *
	 * @param CoreError $error Why it failed.
	 */
	public static function failure( CoreError $error ): self {
		return new self( $error->status, array(), $error->request_id, $error );
	}

	/**
	 * Whether the core answered with a 2xx status and a JSON body (or an empty 204).
	 *
	 * @phpstan-assert-if-true null $this->error
	 * @phpstan-assert-if-false CoreError $this->error
	 */
	public function is_success(): bool {
		return null === $this->error;
	}
}
