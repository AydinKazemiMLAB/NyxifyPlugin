<?php
/**
 * The messengers a store's bots run on.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Bots;

/**
 * The core's `MessengerPlatform` values (contract §4.1), their labels and the public link of a bot.
 *
 * Values the core may add later are shown as they are; only the known ones are accepted from a form.
 */
final class BotPlatform {

	public const TELEGRAM = 'telegram';

	public const BALE = 'bale';

	public const RUBIKA = 'rubika';

	/**
	 * Every platform, in the order the settings page shows them.
	 *
	 * @var list<string>
	 */
	public const ALL = array( self::TELEGRAM, self::BALE, self::RUBIKA );

	/**
	 * Where a bot's public page lives, by platform (Rubika has none the plugin relies on).
	 */
	private const LINKS = array(
		self::TELEGRAM => 'https://t.me/',
		self::BALE     => 'https://ble.ir/',
	);

	/**
	 * A bot username that is safe to put in a link (Telegram and Bale use letters, digits and underscores).
	 */
	private const USERNAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

	/**
	 * Whether a value is one of the known platforms.
	 *
	 * @param mixed $value Value.
	 * @phpstan-assert-if-true 'telegram'|'bale'|'rubika' $value
	 */
	public static function is_known( $value ): bool {
		return in_array( $value, self::ALL, true );
	}

	/**
	 * Reads a platform from form input.
	 *
	 * @param mixed $raw Raw request value, e.g. `$_POST['woonyx_platform'] ?? null`.
	 * @return string|null Null when it isn't a known platform.
	 */
	public static function from_input( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$platform = wp_unslash( $raw );

		return self::is_known( $platform ) ? $platform : null;
	}

	/**
	 * The platform's name for the owner; an unknown value as it is.
	 *
	 * @param string $platform Platform.
	 */
	public static function label( string $platform ): string {
		$labels = array(
			self::TELEGRAM => __( 'Telegram', 'woonyx' ),
			self::BALE     => __( 'Bale', 'woonyx' ),
			self::RUBIKA   => __( 'Rubika', 'woonyx' ),
		);

		return $labels[ $platform ] ?? $platform;
	}

	/**
	 * The bot's public link, or null when the platform has none or the username isn't usable in one.
	 *
	 * @param string $platform Platform.
	 * @param string $username Bot username, with or without a leading `@`.
	 */
	public static function bot_url( string $platform, string $username ): ?string {
		$base     = self::LINKS[ $platform ] ?? null;
		$username = ltrim( $username, '@' );

		if ( null === $base || 1 !== preg_match( self::USERNAME_PATTERN, $username ) ) {
			return null;
		}

		return $base . $username;
	}
}
