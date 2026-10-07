<?php
/**
 * The admin PIN buttons' handler.
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
 * Handles `admin-post.php?action=woonyx_issue_admin_pin` from its two buttons ("New PIN", and "New PIN and log out
 * every admin", whose form carries `woonyx_revoke_admins=1`): checks the capability and nonce, issues the PIN and
 * redirects back to the settings page with a one-time notice. The notice never holds the PIN.
 */
final class AdminPinHandler {

	/**
	 * Admin action name of both buttons.
	 */
	public const ACTION = 'issue_admin_pin';

	/**
	 * Name of the hidden field of the "log out every admin" button.
	 */
	public const FIELD_REVOKE = 'woonyx_revoke_admins';

	/**
	 * Success code of a new PIN.
	 */
	public const ISSUED = 'admin_pin_issued';

	/**
	 * Success code of a new PIN with every admin logged out.
	 */
	public const ISSUED_REVOKED = 'admin_pin_issued_revoked';

	/**
	 * Issues the PIN: `( bool $revoke_admins ) => ?CoreError`, e.g. AdminPinIssuer::issue().
	 *
	 * @var callable(bool): ?CoreError
	 */
	private $issue;

	/**
	 * Constructor.
	 *
	 * @param callable $issue Issues the PIN; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(bool): ?CoreError $issue
	 */
	public function __construct( callable $issue ) {
		$this->issue = $issue;
	}

	/**
	 * Registers the handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . AdminAction::hook_name( self::ACTION ), array( $this, 'handle' ) );
	}

	/**
	 * Handles a button and redirects (never returns).
	 */
	public function handle(): void {
		AdminAction::verify( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The nonce is checked by verify(); only the exact value '1' counts.
		$revoke = isset( $_POST[ self::FIELD_REVOKE ] ) && '1' === $_POST[ self::FIELD_REVOKE ];

		$error = ( $this->issue )( $revoke );

		if ( null === $error ) {
			Notices::push( Notice::success( $revoke ? self::ISSUED_REVOKED : self::ISSUED ) );
		} else {
			Notices::push( Notice::error( $error ) );
		}

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
