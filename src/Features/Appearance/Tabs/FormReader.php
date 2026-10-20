<?php
/**
 * Reading a posted bot appearance form.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Appearance\Tabs;

use Nyxify\Features\Appearance\TabInput;

/**
 * Collects a form's fields after normalizing (to put back into the form) and the fields that failed their checks,
 * while a tab builds its part.
 */
final class FormReader {

	/**
	 * The tab's posted fields.
	 *
	 * @var array<array-key, mixed>
	 */
	private array $input;

	/**
	 * Fields after normalizing, by field key.
	 *
	 * @var array<string, string>
	 */
	private array $values = array();

	/**
	 * Error codes by field key.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * Constructor.
	 *
	 * @param array<array-key, mixed> $input The tab's posted fields.
	 */
	public function __construct( array $input ) {
		$this->input = $input;
	}

	/**
	 * Reads a field and keeps its normalized value.
	 *
	 * @param string                 $key  Dotted key.
	 * @param callable(mixed):string $read Normalizes the posted value, e.g. `Fields::line(...)`.
	 */
	public function read( string $key, callable $read ): string {
		$this->values[ $key ] = $read( Fields::posted( $this->input, $key ) );

		return $this->values[ $key ];
	}

	/**
	 * Replaces a field's value as put back into the form, e.g. once split from another field.
	 *
	 * @param string $key   Dotted key.
	 * @param string $value The value.
	 */
	public function keep( string $key, string $value ): void {
		$this->values[ $key ] = $value;
	}

	/**
	 * Marks a field as failed (its first error stays).
	 *
	 * @param string $key  Dotted key.
	 * @param string $code Error code.
	 */
	public function fail( string $key, string $code ): void {
		$this->errors[ $key ] ??= $code;
	}

	/**
	 * A text in each language (`<key>.fa`, `<key>.en`) as the part takes it: the languages given, or null when both
	 * are empty.
	 *
	 * @param string                         $key     Dotted key of the text.
	 * @param callable(mixed):string         $read    Normalizes a posted value.
	 * @param callable(string):(string|null) $problem The error code of a text that isn't empty, or null when it is fine.
	 * @return array<string, string>|null
	 */
	public function localized( string $key, callable $read, callable $problem ): ?array {
		$texts = array();
		foreach ( Fields::LANGUAGES as $language ) {
			$field = $key . '.' . $language;
			$text  = $this->read( $field, $read );
			if ( '' === $text ) {
				continue;
			}
			$code = $problem( $text );
			if ( null !== $code ) {
				$this->fail( $field, $code );
			}
			$texts[ $language ] = $text;
		}

		return array() === $texts ? null : $texts;
	}

	/**
	 * The form as read, with its part (an empty part keeps the bots' defaults).
	 *
	 * @param array<array-key, mixed> $part The part built from the fields.
	 */
	public function input( array $part ): TabInput {
		return new TabInput( $this->values, array() === $part ? null : $part, $this->errors );
	}
}
