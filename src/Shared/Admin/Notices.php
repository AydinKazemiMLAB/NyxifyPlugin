<?php
/**
 * One-time notices of the plugin's admin actions.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Shared\Admin;

/**
 * After an action the handler redirects back to the settings page (Post/Redirect/Get); its result waits for one minute
 * in a per-user transient, so nothing about it goes into the URL, and is shown once.
 */
final class Notices {

	/**
	 * Name of a user's notice transient, followed by the user id (the uninstall deletes them by this prefix).
	 */
	public const TRANSIENT_PREFIX = 'nyxify_notice_';

	/**
	 * Seconds a notice waits for the next page.
	 */
	public const LIFETIME = 60;

	/**
	 * Keeps a notice for the current user's next page (replacing any waiting one).
	 *
	 * @param Notice $notice The notice.
	 */
	public static function push( Notice $notice ): void {
		$name = self::transient_name();
		if ( null !== $name ) {
			set_transient( $name, $notice->to_array(), self::LIFETIME );
		}
	}

	/**
	 * The current user's waiting notice, removed once read.
	 */
	public static function take(): ?Notice {
		$name = self::transient_name();
		if ( null === $name ) {
			return null;
		}

		$data = get_transient( $name );
		if ( false === $data ) {
			return null;
		}

		delete_transient( $name );

		return Notice::from_array( $data );
	}

	/**
	 * The transient of the current user, or null when nobody is logged in.
	 */
	private static function transient_name(): ?string {
		$user_id = get_current_user_id();

		return $user_id > 0 ? self::TRANSIENT_PREFIX . $user_id : null;
	}
}
