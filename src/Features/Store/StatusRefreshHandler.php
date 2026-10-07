<?php
/**
 * The "Check connection" button's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Features\Settings\SettingsPage;
use WooNyx\Shared\Admin\AdminAction;

/**
 * Handles `admin-post.php?action=woonyx_refresh_status`: checks the capability and nonce, forgets the cached status
 * and redirects to the settings page, which then asks the core again.
 */
final class StatusRefreshHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'refresh_status';

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

		StatusService::clear();

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
