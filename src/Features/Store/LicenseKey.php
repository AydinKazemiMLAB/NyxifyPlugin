<?php
/**
 * A license key entered by the owner.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

/**
 * A well-formed license key, `WN-XXXXXX-XXXXXX-XXXXXX-XXXXXX` (contract §1.1), normalized the way the core's
 * `LicenseKeyCodec` does: whitespace removed, upper case. It is sent once in the activation request and never
 * stored, logged or printed; only `prefix()` may be shown.
 */
final class LicenseKey {

	/**
	 * `WN` + four groups of six base32 characters.
	 */
	private const PATTERN = '/^WN(-[A-Z2-7]{6}){4}$/D';

	/**
	 * Length of the non-secret display prefix (`WN-ABCDEF`), as in the core.
	 */
	private const PREFIX_LENGTH = 9;

	/**
	 * Normalized key.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $value Normalized, well-formed key.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Reads a key from form input (unslashed and sanitized here).
	 *
	 * @param mixed $raw Raw request value, e.g. `$_POST['woonyx_license_key'] ?? null`.
	 * @return self|null Null when the value is missing or not a well-formed key.
	 */
	public static function from_input( $raw ): ?self {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return self::parse( sanitize_text_field( wp_unslash( $raw ) ) );
	}

	/**
	 * Normalizes and checks a key; letter case and whitespace are ignored.
	 *
	 * @param string $raw Key as typed or pasted.
	 * @return self|null Null when it is not a well-formed key.
	 */
	public static function parse( string $raw ): ?self {
		// \p{Z} also covers non-breaking spaces copied from web pages (Python's \s, used by the core, does too).
		$normalized = preg_replace( '/[\s\p{Z}]+/u', '', $raw );
		if ( ! is_string( $normalized ) ) {
			// Invalid UTF-8.
			return null;
		}

		$normalized = strtoupper( $normalized );
		if ( 1 !== preg_match( self::PATTERN, $normalized ) ) {
			return null;
		}

		return new self( $normalized );
	}

	/**
	 * The full key, for the activation request body only.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * The non-secret part that may be shown to the owner (`WN-ABCDEF`).
	 *
	 * @return string
	 */
	public function prefix(): string {
		return substr( $this->value, 0, self::PREFIX_LENGTH );
	}

	/**
	 * Keeps the key out of var_dump() and print_r() output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'prefix' => $this->prefix() );
	}
}
