<?php
/**
 * The "Check connection" button's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Features\Store;

use WooNyx\Features\Settings\SettingsPage;
use WooNyx\Features\Sync\PullScheduler;
use WooNyx\Shared\Admin\AdminAction;

/**
 * Handles `admin-post.php?action=woonyx_refresh_status`: checks the capability and nonce, forgets the cached status,
 * ends the pulls' pause and asks for a pull at once, then redirects to the settings page, which asks the core again.
 */
final class StatusRefreshHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'refresh_status';

	/**
	 * Ends the pulls' pause and schedules a pull.
	 *
	 * @var callable(): void
	 */
	private $resume_pulls;

	/**
	 * Constructor.
	 *
	 * @param callable|null $resume_pulls Ends the pulls' pause and schedules a pull; resume_pulls() by default.
	 *
	 * @phpstan-param (callable(): void)|null $resume_pulls
	 */
	public function __construct( ?callable $resume_pulls = null ) {
		$this->resume_pulls = $resume_pulls ?? array( self::class, 'resume_pulls' );
	}

	/**
	 * Ends the pulls' pause and schedules one pull at once.
	 */
	public static function resume_pulls(): void {
		PullScheduler::clear_retry_at();
		( new PullScheduler() )->schedule_now();
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

		StatusService::clear();
		( $this->resume_pulls )();

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
