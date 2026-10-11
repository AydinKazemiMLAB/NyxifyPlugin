<?php
/**
 * The "Sync now" button's handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Features\Catalog;

use Nyxify\Features\Settings\SettingsPage;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Admin\AdminAction;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Logging\Logger;

/**
 * Handles `admin-post.php?action=nyxify_catalog_sync`: checks the capability and nonce, asks the core for a full
 * catalog sync (`POST /catalog/sync`), forgets the cached status and redirects back to the settings page with a
 * one-time notice. A sync the core accepted (`scheduled` true) or one already running also deletes the plan-limit
 * notice: the sync brings the core's count up to date.
 */
final class CatalogSyncHandler {

	/**
	 * Admin action name of the button.
	 */
	public const ACTION = 'catalog_sync';

	/**
	 * Success code: the core scheduled a full sync.
	 */
	public const STARTED = 'catalog_sync_started';

	/**
	 * Success code: a full sync is already running.
	 */
	public const RUNNING = 'catalog_sync_running';

	/**
	 * Operation name in the logs.
	 */
	public const OPERATION = 'catalog/sync';

	/**
	 * Asks the core for a full sync: `() => CoreResponse`, e.g. CatalogSender::request_sync().
	 *
	 * @var callable(): CoreResponse
	 */
	private $request;

	/**
	 * Safe logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param callable $request Asks the core for a full sync; its checked answer.
	 * @param Logger   $logger  Safe logger.
	 *
	 * @phpstan-param callable(): CoreResponse $request
	 */
	public function __construct( callable $request, Logger $logger ) {
		$this->request = $request;
		$this->logger  = $logger;
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

		$response = ( $this->request )();
		$error    = $response->error;

		if ( null !== $error ) {
			// Without a connection nothing was sent; the page explains that state itself.
			if ( ! in_array( $error->code, array( CoreError::NOT_ACTIVATED, CoreError::CONNECTION_UNREADABLE ), true ) ) {
				$this->logger->core_error( self::OPERATION, $error );
			}
			Notices::push( Notice::error( $error ) );
		} else {
			$scheduled = true === $response->data['scheduled'];
			$this->logger->info(
				$scheduled ? 'Full catalog sync requested.' : 'A full catalog sync is already running.',
				array( 'request_id' => $response->request_id )
			);
			PlanLimitNotice::clear();
			Notices::push( Notice::success( $scheduled ? self::STARTED : self::RUNNING ) );
		}

		StatusService::clear();

		wp_safe_redirect( SettingsPage::url() );
		exit;
	}
}
