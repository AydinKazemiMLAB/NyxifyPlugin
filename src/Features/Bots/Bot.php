<?php
/**
 * A bot of the store, as the core describes it.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Bots;

/**
 * One item of `GET /bots` (`BotView`, contract §4.1), read defensively. The token is never part of it.
 *
 * Status values the core may add later are kept as text; `last_error` is the core's English free text (up to 500
 * characters, the token kept out by the core) and is shown escaped, never logged.
 */
final class Bot {

	/**
	 * Waiting for the core to set the webhook.
	 */
	public const PENDING = 'pending';

	/**
	 * Webhook set; the bot answers.
	 */
	public const ACTIVE = 'active';

	/**
	 * Setting up failed; `last_error` says why (retried hourly, up to 5 times).
	 */
	public const ERROR = 'error';

	/**
	 * Lowercase identifiers, as the core's enums.
	 */
	private const VALUE_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/D';

	/**
	 * Messenger (`telegram`, `bale`, `rubika`).
	 *
	 * @var string
	 */
	public readonly string $platform;

	/**
	 * The bot's username, or null when the platform gave none.
	 *
	 * @var string|null
	 */
	public readonly ?string $username;

	/**
	 * The bot's display name, or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $name;

	/**
	 * PENDING, ACTIVE, ERROR or a value added later.
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Whether the webhook is set.
	 *
	 * @var bool
	 */
	public readonly bool $webhook_active;

	/**
	 * Why setting up failed (the core's text), or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $last_error;

	/**
	 * When the webhook was set (the core's naive UTC ISO string), or null.
	 *
	 * @var string|null
	 */
	public readonly ?string $deployed_at;

	/**
	 * Constructor.
	 *
	 * @param string      $platform       Messenger.
	 * @param string|null $username       Username.
	 * @param string|null $name           Display name.
	 * @param string      $status         Status.
	 * @param bool        $webhook_active Whether the webhook is set.
	 * @param string|null $last_error     Last error.
	 * @param string|null $deployed_at    Deployment date.
	 */
	private function __construct( string $platform, ?string $username, ?string $name, string $status, bool $webhook_active, ?string $last_error, ?string $deployed_at ) {
		$this->platform       = $platform;
		$this->username       = $username;
		$this->name           = $name;
		$this->status         = $status;
		$this->webhook_active = $webhook_active;
		$this->last_error     = $last_error;
		$this->deployed_at    = $deployed_at;
	}

	/**
	 * A bot from the core's answer, or null when a field is missing or has the wrong type.
	 *
	 * @param mixed $data One decoded item of `GET /bots` (or the answer of `POST /bots`).
	 */
	public static function from_response( $data ): ?self {
		if ( ! is_array( $data ) ) {
			return null;
		}

		$platform       = $data['platform'] ?? null;
		$username       = $data['username'] ?? null;
		$name           = $data['name'] ?? null;
		$status         = $data['status'] ?? null;
		$webhook_active = $data['webhook_active'] ?? null;
		$last_error     = $data['last_error'] ?? null;
		$deployed_at    = $data['deployed_at'] ?? null;

		if ( ! self::is_value( $platform ) || ! self::is_value( $status ) || ! is_bool( $webhook_active )
			|| ! self::is_optional_string( $username ) || ! self::is_optional_string( $name )
			|| ! self::is_optional_string( $last_error ) || ! self::is_optional_string( $deployed_at )
		) {
			return null;
		}

		return new self( $platform, $username, $name, $status, $webhook_active, $last_error, $deployed_at );
	}

	/**
	 * Whether a value is an enum-like identifier.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true string $value
	 */
	private static function is_value( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::VALUE_PATTERN, $value );
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
