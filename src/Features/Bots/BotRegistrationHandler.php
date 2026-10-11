<?php
/**
 * The bot token forms' handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Bots;

use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;

/**
 * Handles `admin-post.php?action=nyxify_register_bot` (adding a bot, or replacing the platform's bot): checks the
 * capability and nonce, reads the platform and the token, sends the token once and redirects back to the settings
 * page with a one-time notice. The token is never stored, logged or put in the redirect.
 */
final class BotRegistrationHandler {

	/**
	 * Admin action name of the forms.
	 */
	public const ACTION = 'register_bot';

	/**
	 * Name of the hidden platform field.
	 */
	public const FIELD_PLATFORM = 'nyxify_platform';

	/**
	 * Name of the token field.
	 */
	public const FIELD_TOKEN = 'nyxify_bot_token';

	/**
	 * Success code of the notice.
	 */
	public const REGISTERED = 'bot_registered';

	/**
	 * Registers the token: `( string $platform, BotToken ) => ?CoreError`, e.g. BotService::register().
	 *
	 * @var callable(string, BotToken): ?CoreError
	 */
	private $register;

	/**
	 * Constructor.
	 *
	 * @param callable $register Registers the token; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(string, BotToken): ?CoreError $register
	 */
	public function __construct( callable $register ) {
		$this->register = $register;
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . AdminAction::hook_name( self::ACTION ), array( $this, 'handle' ) );
	}

	/**
	 * Handles a form and redirects (never returns).
	 */
	public function handle(): void {
		AdminAction::verify( self::ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The nonce is checked by verify(); BotPlatform::from_input() and BotToken::from_input() unslash and validate (a token must not be altered by sanitizing).
		$platform = BotPlatform::from_input( $_POST[ self::FIELD_PLATFORM ] ?? null );
		$token    = BotToken::from_input( $_POST[ self::FIELD_TOKEN ] ?? null );
		// phpcs:enable

		if ( null === $platform ) {
			$error = new CoreError( CoreError::INVALID_PAYLOAD );
		} elseif ( null === $token ) {
			$error = new CoreError( CoreError::BOT_TOKEN_FORMAT );
		} else {
			$error = ( $this->register )( $platform, $token );
		}

		Notices::push( null === $error ? Notice::success( self::REGISTERED ) : Notice::error( $error ) );

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
