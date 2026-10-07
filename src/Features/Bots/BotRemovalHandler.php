<?php
/**
 * The bots' "Remove" button's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Bots;

use WooNyx\Features\Settings\SettingsPage;
use WooNyx\Shared\Admin\AdminAction;
use WooNyx\Shared\Admin\Notice;
use WooNyx\Shared\Admin\Notices;
use WooNyx\Shared\Http\CoreError;

/**
 * Handles `admin-post.php?action=woonyx_remove_bot`: checks the capability and nonce, reads the platform, removes its
 * bot and redirects back to the settings page with a one-time notice.
 */
final class BotRemovalHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'remove_bot';

	/**
	 * Name of the hidden platform field.
	 */
	public const FIELD_PLATFORM = 'woonyx_platform';

	/**
	 * Success code of the notice.
	 */
	public const REMOVED = 'bot_removed';

	/**
	 * Removes the bot: `( string $platform ) => ?CoreError`, e.g. BotService::remove().
	 *
	 * @var callable(string): ?CoreError
	 */
	private $remove;

	/**
	 * Constructor.
	 *
	 * @param callable $remove Removes the platform's bot; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(string): ?CoreError $remove
	 */
	public function __construct( callable $remove ) {
		$this->remove = $remove;
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . AdminAction::hook_name( self::ACTION ), array( $this, 'handle' ) );
	}

	/**
	 * Handles the button and redirects (never returns).
	 */
	public function handle(): void {
		AdminAction::verify( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The nonce is checked by verify(); BotPlatform::from_input() unslashes and accepts only the known platforms.
		$platform = BotPlatform::from_input( $_POST[ self::FIELD_PLATFORM ] ?? null );

		$error = null === $platform ? new CoreError( CoreError::INVALID_PAYLOAD ) : ( $this->remove )( $platform );

		Notices::push( null === $error ? Notice::success( self::REMOVED ) : Notice::error( $error ) );

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
