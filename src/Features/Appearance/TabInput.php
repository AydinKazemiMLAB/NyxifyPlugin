<?php
/**
 * A bot appearance form as read from a request.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance;

/**
 * The form's fields after normalizing (to put back into the form), the part to send, and the fields that failed the
 * tab's checks.
 */
final class TabInput {

	/**
	 * Fields after normalizing, by field key.
	 *
	 * @var array<string, string>
	 */
	public readonly array $values;

	/**
	 * The part to send, or null for the bots' defaults; a map by id (`category_style`) has integer keys in PHP.
	 *
	 * @var array<array-key, mixed>|null
	 */
	public readonly ?array $part;

	/**
	 * Error codes by field key; nothing is sent while there are any.
	 *
	 * @var array<string, string>
	 */
	public readonly array $errors;

	/**
	 * Constructor.
	 *
	 * @param array<string, string>        $values Fields after normalizing.
	 * @param array<array-key, mixed>|null $part   The part to send.
	 * @param array<string, string>        $errors Error codes by field key.
	 */
	public function __construct( array $values, ?array $part, array $errors ) {
		$this->values = $values;
		$this->part   = $part;
		$this->errors = $errors;
	}
}
