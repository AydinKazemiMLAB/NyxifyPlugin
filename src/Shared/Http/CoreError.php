<?php
/**
 * A failed call to the core.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Shared\Http;

/**
 * Why a call failed: the core's `error_code`, or one of the plugin's own codes below when no usable answer came back.
 *
 * The core's `message` and the raw body are deliberately not kept: the owner sees a translated message per code
 * (ErrorMessages) and logs carry only the code, the HTTP status and the request id.
 */
final class CoreError {

	/**
	 * A signed call while the store has no connection (the owner hasn't activated it).
	 */
	public const NOT_ACTIVATED = 'not_activated';

	/**
	 * A connection is stored but can't be decrypted (salts or WOONYX_ENCRYPTION_KEY changed): activate again.
	 */
	public const CONNECTION_UNREADABLE = 'connection_unreadable';

	/**
	 * The core address is malformed, or plain http outside a local or development environment.
	 */
	public const INVALID_BASE_URL = 'invalid_base_url';

	/**
	 * The payload couldn't be encoded as JSON.
	 */
	public const INVALID_PAYLOAD = 'invalid_payload';

	/**
	 * No HTTP response: DNS, TLS, connection refused, timeout.
	 */
	public const NETWORK_ERROR = 'network_error';

	/**
	 * The core answered with a redirect, which is never followed.
	 */
	public const UNEXPECTED_REDIRECT = 'unexpected_redirect';

	/**
	 * A successful status without a JSON object or list (an empty body is only accepted with 204).
	 */
	public const INVALID_RESPONSE = 'invalid_response';

	/**
	 * An error status without a usable `error_code` (e.g. an HTML page from a proxy).
	 */
	public const UNKNOWN_ERROR = 'unknown_error';

	private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

	/**
	 * Error code.
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * HTTP status, or 0 when the request wasn't sent or no response came back.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Request id to match the core's logs ('' when the request wasn't sent).
	 *
	 * @var string
	 */
	public readonly string $request_id;

	/**
	 * Invalid fields of a `422 validation_error` (e.g. `body.site_url`); never their values.
	 *
	 * @var list<string>
	 */
	public readonly array $fields;

	/**
	 * Constructor.
	 *
	 * @param string   $code       Error code; anything unexpected becomes UNKNOWN_ERROR.
	 * @param int      $status     HTTP status or 0.
	 * @param string   $request_id Request id.
	 * @param string[] $fields     Invalid fields of a validation error.
	 *
	 * @phpstan-param list<string> $fields
	 */
	public function __construct( string $code, int $status = 0, string $request_id = '', array $fields = array() ) {
		$this->code       = 1 === preg_match( self::CODE_PATTERN, $code ) ? $code : self::UNKNOWN_ERROR;
		$this->status     = $status;
		$this->request_id = $request_id;
		$this->fields     = $fields;
	}
}
