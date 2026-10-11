<?php
/**
 * The result of an admin action, shown once on the next page.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Admin;

use Nyxify\Shared\Http\CoreError;

/**
 * A success code (e.g. `activated`) or a failure's error code with its HTTP status, request id and invalid field
 * names; never a message from the core, a payload or a secret.
 */
final class Notice {

	public const SUCCESS = 'success';

	public const ERROR = 'error';

	private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

	/**
	 * SUCCESS or ERROR.
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Success or error code.
	 *
	 * @var string
	 */
	public readonly string $code;

	/**
	 * HTTP status of a failure, or 0.
	 *
	 * @var int
	 */
	public readonly int $status;

	/**
	 * Request id of a failure ('' when none).
	 *
	 * @var string
	 */
	public readonly string $request_id;

	/**
	 * Invalid fields of a `422 validation_error`.
	 *
	 * @var list<string>
	 */
	public readonly array $fields;

	/**
	 * Constructor.
	 *
	 * @param string   $type       SUCCESS or ERROR.
	 * @param string   $code       Code.
	 * @param int      $status     HTTP status or 0.
	 * @param string   $request_id Request id.
	 * @param string[] $fields     Invalid fields.
	 *
	 * @phpstan-param list<string> $fields
	 */
	private function __construct( string $type, string $code, int $status = 0, string $request_id = '', array $fields = array() ) {
		$this->type       = $type;
		$this->code       = $code;
		$this->status     = $status;
		$this->request_id = $request_id;
		$this->fields     = $fields;
	}

	/**
	 * A success.
	 *
	 * @param string $code What succeeded, e.g. `activated`.
	 */
	public static function success( string $code ): self {
		return new self( self::SUCCESS, $code );
	}

	/**
	 * A failure.
	 *
	 * @param CoreError $error The failure.
	 */
	public static function error( CoreError $error ): self {
		return new self( self::ERROR, $error->code, $error->status, $error->request_id, $error->fields );
	}

	/**
	 * The notice as stored (scalars and a list of strings only).
	 *
	 * @return array{type: string, code: string, status: int, request_id: string, fields: list<string>}
	 */
	public function to_array(): array {
		return array(
			'type'       => $this->type,
			'code'       => $this->code,
			'status'     => $this->status,
			'request_id' => $this->request_id,
			'fields'     => $this->fields,
		);
	}

	/**
	 * A stored notice, or null when the value is not one.
	 *
	 * @param mixed $data Stored value.
	 */
	public static function from_array( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$type       = $data['type'] ?? null;
		$code       = $data['code'] ?? null;
		$status     = $data['status'] ?? 0;
		$request_id = $data['request_id'] ?? '';
		$fields     = $data['fields'] ?? array();

		if ( ! in_array( $type, array( self::SUCCESS, self::ERROR ), true )
			|| ! is_string( $code ) || 1 !== preg_match( self::CODE_PATTERN, $code )
			|| ! is_int( $status ) || ! is_string( $request_id ) || ! is_array( $fields )
		) {
			return null;
		}

		return new self( $type, $code, $status, $request_id, array_values( array_filter( $fields, 'is_string' ) ) );
	}
}
