<?php
/**
 * Tests for the "Check connection" button's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Store;

use Brain\Monkey\Functions;
use WooNyx\Features\Store\StatusRefreshHandler;
use WooNyx\Features\Store\StatusService;
use WooNyx\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the cache is cleared and the page reloaded.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class StatusRefreshHandlerTest extends TestCase {

	/**
	 * In-memory transients.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array( StatusService::TRANSIENT => array( 'cached' ) );

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

	protected function setUp(): void {
		parent::setUp();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'woonyx_refresh_status' !== $action || '_woonyx_nonce' !== $name || ! $this->nonce_valid ) {
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
		Functions\when( 'delete_transient' )->alias(
			function ( string $name ): bool {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->alias(
			function ( string $location ): void {
				$this->redirect = $location;
				throw new \RuntimeException( 'redirect' );
			}
		);
		Functions\expect( 'wp_remote_request' )->never();
	}

	private function handle(): string {
		try {
			( new StatusRefreshHandler() )->handle();
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}

		return 'returned';
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = new StatusRefreshHandler();

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_woonyx_refresh_status', array( $handler, 'handle' ) ) );
	}

	public function test_the_cache_is_cleared_and_the_page_reloaded(): void {
		self::assertSame( 'redirect', $this->handle() );

		self::assertSame( array(), $this->transients );
		self::assertSame( 'https://shop.example.com/wp-admin/admin.php?page=woonyx', $this->redirect );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		self::assertSame( 'wp_die', $this->handle() );
		self::assertArrayHasKey( StatusService::TRANSIENT, $this->transients );
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		self::assertSame( 'nonce', $this->handle() );
		self::assertArrayHasKey( StatusService::TRANSIENT, $this->transients );
	}
}
