<?php
/**
 * A bot admin of the store.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Bots;

/**
 * One item of `GET /bots/admins` (`AdminView`, contract §4.1), read defensively.
 *
 * Names, username and messenger user id are personal data: shown to the shop manager on the settings page only,
 * never logged or cached, and kept out of var_dump() and print_r() output.
 */
final class BotAdmin {

	/**
	 * Lowercase identifiers, as the core's enums.
	 */
	private const PLATFORM_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * The core's id of the admin (used to remove them).
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Messenger (`telegram`, `bale`, `rubika`).
	 *
	 * @var string
	 */
	public readonly string $platform;

	/**
	 * The admin's id on the messenger.
	 *
	 * @var string
	 */
	public readonly string $messenger_user_id;

	/**
	 * First name, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $first_name;

	/**
	 * Last name, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $last_name;

	/**
	 * Messenger username, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $username;

	/**
	 * Constructor.
	 *
	 * @param int         $id                Admin id.
	 * @param string      $platform          Messenger.
	 * @param string      $messenger_user_id Id on the messenger.
	 * @param string|null $first_name        First name.
	 * @param string|null $last_name         Last name.
	 * @param string|null $username          Username.
	 */
	private function __construct( int $id, string $platform, string $messenger_user_id, ?string $first_name, ?string $last_name, ?string $username ) {
		$this->id                = $id;
		$this->platform          = $platform;
		$this->messenger_user_id = $messenger_user_id;
		$this->first_name        = $first_name;
		$this->last_name         = $last_name;
		$this->username          = $username;
	}

	/**
	 * An admin from the core's answer, or null when a field is missing or has the wrong type.
	 *
	 * @param mixed $data One decoded item of `GET /bots/admins`.
	 */
	public static function from_response( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$id                = $data['id'] ?? null;
		$platform          = $data['messenger_platform'] ?? null;
		$messenger_user_id = $data['messenger_user_id'] ?? null;
		$first_name        = $data['first_name'] ?? null;
		$last_name         = $data['last_name'] ?? null;
		$username          = $data['username'] ?? null;

		if ( ! is_int( $id ) || $id < 1
			|| ! is_string( $platform ) || 1 !== preg_match( self::PLATFORM_PATTERN, $platform )
			|| ! is_string( $messenger_user_id ) || '' === $messenger_user_id
			|| ! self::is_optional_string( $first_name ) || ! self::is_optional_string( $last_name )
			|| ! self::is_optional_string( $username )
		) {
			return null;
		}

		return new self( $id, $platform, $messenger_user_id, $first_name, $last_name, $username );
	}

	/**
	 * First and last name joined, or '' when both are empty.
	 */
	public function full_name(): string {
		return trim( trim( (string) $this->first_name ) . ' ' . trim( (string) $this->last_name ) );
	}

	/**
	 * Keeps the personal data out of var_dump() and print_r() output.
	 *
	 * @return array<string, int|string>
	 */
	public function __debugInfo(): array {
		return array(
			'id'       => $this->id,
			'platform' => $this->platform,
		);
	}

	/**
	 * Whether a value is a string or null.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true string|null $value
	 */
	private static function is_optional_string( $value ): bool {
		return null === $value || is_string( $value );
	}
}
