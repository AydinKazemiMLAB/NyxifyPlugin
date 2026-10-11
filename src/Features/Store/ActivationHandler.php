<?php
/**
 * The license form's handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Store;

use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;

/**
 * Handles `admin-post.php?action=nyxify_activate`: checks the capability and nonce, reads the license key, activates
 * the store and redirects back to the settings page with a one-time notice. The key never goes into the redirect, a
 * notice or a log.
 */
final class ActivationHandler {

	/**
	 * Admin action name of the license form.
	 */
	public const ACTION = 'activate';

	/**
	 * Name of the license key field.
	 */
	public const FIELD = 'nyxify_license_key';

	/**
	 * Success code of the notice.
	 */
	public const ACTIVATED = 'activated';

	/**
	 * Activates the store: `( LicenseKey, int $user_id ) => ?CoreError`, e.g. Activator::activate().
	 *
	 * @var callable(LicenseKey, int): ?CoreError
	 */
	private $activate;

	/**
	 * Constructor.
	 *
	 * @param callable $activate Activates the store; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(LicenseKey, int): ?CoreError $activate
	 */
	public function __construct( callable $activate ) {
		$this->activate = $activate;
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . AdminAction::hook_name( self::ACTION ), array( $this, 'handle' ) );
	}

	/**
	 * Handles the form and redirects (never returns).
	 */
	public function handle(): void {
		AdminAction::verify( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The nonce is checked by verify(); LicenseKey::from_input() unslashes, sanitizes and validates.
		$license = LicenseKey::from_input( $_POST[ self::FIELD ] ?? null );

		$error = null === $license
			? new CoreError( CoreError::LICENSE_KEY_FORMAT )
			: ( $this->activate )( $license, get_current_user_id() );

		Notices::push( null === $error ? Notice::success( self::ACTIVATED ) : Notice::error( $error ) );

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
