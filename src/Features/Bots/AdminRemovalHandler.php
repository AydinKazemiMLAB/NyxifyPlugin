<?php
/**
 * The bot admins' "Remove" button's handler.
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
 * Handles `admin-post.php?action=woonyx_revoke_admin`: checks the capability and nonce, reads the admin's id, removes
 * their admin access and redirects back to the settings page with a one-time notice (which never names the admin).
 */
final class AdminRemovalHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'revoke_admin';

	/**
	 * Name of the hidden admin id field.
	 */
	public const FIELD_ADMIN_ID = 'woonyx_admin_id';

	/**
	 * Success code of the notice.
	 */
	public const REVOKED = 'admin_revoked';

	/**
	 * A positive id in decimal, without a sign, leading zeros or spaces.
	 */
	private const ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

	/**
	 * Removes the admin: `( int $id ) => ?CoreError`, e.g. AdminService::revoke().
	 *
	 * @var callable(int): ?CoreError
	 */
	private $revoke;

	/**
	 * Constructor.
	 *
	 * @param callable $revoke Removes the admin; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(int): ?CoreError $revoke
	 */
	public function __construct( callable $revoke ) {
		$this->revoke = $revoke;
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The nonce is checked by verify(); admin_id() unslashes and accepts only a positive decimal id.
		$id = self::admin_id( $_POST[ self::FIELD_ADMIN_ID ] ?? null );

		$error = null === $id ? new CoreError( CoreError::INVALID_PAYLOAD ) : ( $this->revoke )( $id );

		Notices::push( null === $error ? Notice::success( self::REVOKED ) : Notice::error( $error ) );

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}

	/**
	 * The posted admin id, or null unless it is a positive decimal integer.
	 *
	 * @param mixed $value Raw posted value.
	 */
	private static function admin_id( $value ): ?int {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = wp_unslash( $value );
		if ( 1 !== preg_match( self::ID_PATTERN, $value ) ) {
			return null;
		}

		return (int) $value;
	}
}
