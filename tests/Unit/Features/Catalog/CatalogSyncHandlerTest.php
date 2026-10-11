<?php
/**
 * Tests for the "Sync now" button's handler.
 *
 * @package Nyxify
 */

declare(strict_types=1);

namespace Nyxify\Tests\Unit\Features\Catalog;

use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Nyxify\Features\Catalog\CatalogSyncHandler;
use Nyxify\Features\Catalog\PlanLimitNotice;
use Nyxify\Features\Store\StatusService;
use Nyxify\Shared\Admin\Notice;
use Nyxify\Shared\Admin\Notices;
use Nyxify\Shared\Http\CoreError;
use Nyxify\Shared\Http\CoreResponse;
use Nyxify\Shared\Logging\Logger;
use Nyxify\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the request, the notice, the caches and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class CatalogSyncHandlerTest extends TestCase {

	/**
	 * In-memory wp_options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Whether the current user may manage WooCommerce.
	 *
	 * @var bool
	 */
	private bool $can_manage = true;

	/**
	 * Whether the nonce is valid.
	 *
	 * @var bool
	 */
	private bool $nonce_valid = true;

	/**
	 * Where wp_safe_redirect() was asked to go.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

	/**
	 * Calls of the request callable.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * Log entries: level, message, context.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	private array $entries = array();

	protected function setUp(): void {
		parent::setUp();

		$this->options    = array(
			PlanLimitNotice::OPTION => array(
				'count' => 4,
				'time'  => 1_791_000_000,
			),
		);
		$this->transients = array( StatusService::TRANSIENT => array( 'cached' ) );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'nyxify_catalog_sync' !== $action || '_nyxify_nonce' !== $name || ! $this->nonce_valid ) {
					throw new \RuntimeException( 'nonce' );
				}
				return 1;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function (): void {
				throw new \RuntimeException( 'wp_die' );
			}
		);
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $name ): bool {
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): void {
				$this->redirect = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
	}

	private function handler( CoreResponse $response ): CatalogSyncHandler {
		$wc_logger = Mockery::mock( 'WC_Logger_Interface' );
		$wc_logger->shouldReceive( 'log' )->andReturnUsing(
			function ( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		);

		return new CatalogSyncHandler(
			function () use ( $response ): CoreResponse {
				++$this->requests;
				return $response;
			},
			new Logger( $wc_logger )
		);
	}

	private function handle( CoreResponse $response ): string {
		try {
			$this->handler( $response )->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return 'returned';
	}

	/**
	 * The notice waiting for the user's next page.
	 */
	private function notice(): ?Notice {
		return Notice::from_array( $this->transients[ Notices::TRANSIENT_PREFIX . '5' ] ?? null );
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = $this->handler( CoreResponse::success( 202, array( 'scheduled' => true ), 'req-1' ) );

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_nyxify_catalog_sync', array( $handler, 'handle' ) ) );
	}

	public function test_a_scheduled_sync(): void {
		self::assertSame( 'redirect', $this->handle( CoreResponse::success( 202, array( 'scheduled' => true ), 'req-core-0003' ) ) );

		$notice = $this->notice();
		self::assertNotNull( $notice );
		self::assertSame( 1, $this->requests );
		self::assertSame( CatalogSyncHandler::STARTED, $notice->code );
		self::assertSame( Notice::SUCCESS, $notice->type );
		self::assertArrayNotHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertSame( array(), $this->options );
		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=nyxify', $this->redirect );
		self::assertSame( 'Full catalog sync requested.', $this->entries[0]['message'] );
		self::assertSame( 'req-core-0003', $this->entries[0]['context']['request_id'] );
	}

	public function test_a_sync_already_running(): void {
		self::assertSame( 'redirect', $this->handle( CoreResponse::success( 202, array( 'scheduled' => false ), 'req-core-0004' ) ) );

		self::assertSame( CatalogSyncHandler::RUNNING, $this->notice()?->code );
		self::assertArrayNotHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertSame( array(), $this->options );
		self::assertSame( 'A full catalog sync is already running.', $this->entries[0]['message'] );
	}

	/**
	 * Failures and whether they are logged.
	 *
	 * @return array<string, array{0: CoreError, 1: string|null}>
	 */
	public static function failures(): array {
		return array(
			'subscription lapsed' => array( new CoreError( 'no_active_subscription', 403, 'req-core-0005' ), 'warning' ),
			'server error'        => array( new CoreError( 'internal_error', 500, 'req-core-0006' ), 'error' ),
			'network error'       => array( new CoreError( CoreError::NETWORK_ERROR ), 'error' ),
			'unexpected answer'   => array( new CoreError( CoreError::INVALID_RESPONSE, 202, 'req-core-0007' ), 'warning' ),
			'not activated'       => array( new CoreError( CoreError::NOT_ACTIVATED ), null ),
			'unreadable'          => array( new CoreError( CoreError::CONNECTION_UNREADABLE ), null ),
		);
	}

	/**
	 * A failure: its notice, the status cache cleared, the plan-limit notice kept.
	 *
	 * @param CoreError   $error The failure.
	 * @param string|null $level Log level, or null when nothing is logged.
	 */
	#[DataProvider( 'failures' )]
	public function test_a_failure( CoreError $error, ?string $level ): void {
		self::assertSame( 'redirect', $this->handle( CoreResponse::failure( $error ) ) );

		$notice = $this->notice();
		self::assertNotNull( $notice );
		self::assertSame( Notice::ERROR, $notice->type );
		self::assertSame( $error->code, $notice->code );
		self::assertSame( $error->status, $notice->status );
		self::assertSame( $error->request_id, $notice->request_id );
		self::assertArrayNotHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertArrayHasKey( PlanLimitNotice::OPTION, $this->options );
		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=nyxify', $this->redirect );

		if ( null === $level ) {
			self::assertSame( array(), $this->entries );
			return;
		}

		self::assertCount( 1, $this->entries );
		self::assertSame( $level, $this->entries[0]['level'] );
		self::assertSame( 'catalog/sync', $this->entries[0]['context']['operation'] );
		self::assertSame( $error->code, $this->entries[0]['context']['error_code'] );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		self::assertSame( 'wp_die', $this->handle( CoreResponse::success( 202, array( 'scheduled' => true ), 'req-1' ) ) );
		self::assertSame( 0, $this->requests );
		self::assertArrayHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertArrayHasKey( PlanLimitNotice::OPTION, $this->options );
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		self::assertSame( 'nonce', $this->handle( CoreResponse::success( 202, array( 'scheduled' => true ), 'req-1' ) ) );
		self::assertSame( 0, $this->requests );
		self::assertArrayHasKey( StatusService::TRANSIENT, $this->transients );
		self::assertArrayHasKey( PlanLimitNotice::OPTION, $this->options );
	}
}
