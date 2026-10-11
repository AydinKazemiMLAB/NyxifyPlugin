<?php
/**
 * The "Rotate secret" button's handler.
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
 * Handles `admin-post.php?action=nyxify_rotate_secret`: checks the capability and nonce, rotates the shared secret and
 * redirects back to the settings page with a one-time notice.
 */
final class SecretRotationHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'rotate_secret';

	/**
	 * Success code of the notice.
	 */
	public const ROTATED = 'secret_rotated';

	/**
	 * Rotates the secret: `() => ?CoreError`, e.g. SecretRotator::rotate().
	 *
	 * @var callable(): ?CoreError
	 */
	private $rotate;

	/**
	 * Constructor.
	 *
	 * @param callable $rotate Rotates the secret; null on success, the error otherwise.
	 *
	 * @phpstan-param callable(): ?CoreError $rotate
	 */
	public function __construct( callable $rotate ) {
		$this->rotate = $rotate;
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

		$error = ( $this->rotate )();

		Notices::push( null === $error ? Notice::success( self::ROTATED ) : Notice::error( $error ) );

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
