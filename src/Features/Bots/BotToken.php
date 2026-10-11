<?php
/**
 * A bot token entered by the owner.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Bots;

/**
 * A bot token as the messenger gave it: printable ASCII without spaces, at most 300 characters (the core's limit).
 * Only the surrounding whitespace of a paste is removed; the token is not otherwise sanitized, since
 * `sanitize_text_field()` drops `%xx` sequences and could change it. The core checks it with the platform.
 *
 * It is sent once in `POST /bots` and never stored, logged, cached, printed or put in a URL.
 */
final class BotToken {

	/**
	 * Printable ASCII (no space), 1 to 300 characters.
	 */
	private const PATTERN = '/^[\x21-\x7E]{1,300}$/D';

	/**
	 * The token.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $value Well-formed token.
	 */
	private function __construct( string $value ) {
		$this->value = $value;
	}

	/**
	 * Reads a token from form input (unslashed here).
	 *
	 * @param mixed $raw Raw request value, e.g. `$_POST['nyxify_bot_token'] ?? null`.
	 * @return self|null Null when the value is missing or not a well-formed token.
	 */
	public static function from_input( $raw ): ?self {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		return self::parse( wp_unslash( $raw ) );
	}

	/**
	 * Trims and checks a token.
	 *
	 * @param string $raw Token as typed or pasted.
	 * @return self|null Null when it is not a well-formed token.
	 */
	public static function parse( string $raw ): ?self {
		// \p{Z} also covers non-breaking spaces copied from a messenger or a web page.
		$trimmed = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw );
		if ( ! is_string( $trimmed ) || 1 !== preg_match( self::PATTERN, $trimmed ) ) {
			// Invalid UTF-8, or not a token.
			return null;
		}

		return new self( $trimmed );
	}

	/**
	 * The token, for the registration request body only.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Keeps the token out of var_dump() and print_r() output.
	 *
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array( 'token' => '(hidden)' );
	}
}
