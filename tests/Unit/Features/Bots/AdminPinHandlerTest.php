<?php
/**
 * Tests for the admin PIN buttons' handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Bots\AdminPinHandler;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the revoke flag from the button, the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class AdminPinHandlerTest extends TestCase {

	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=woonyx';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Revoke flags the issue callable was called with.
	 *
	 * @var list<bool>
	 */
	private array $issued = array();

	/**
	 * What the issue callable returns.
	 *
	 * @var CoreError|null
	 */
	private ?CoreError $result = null;

	/**
	 * Where wp_safe_redirect() was asked to go.
	 *
	 * @var string|null
	 */
	private ?string $redirect = null;

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

	protected function setUp(): void {
		parent::setUp();

		$_POST = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'woonyx_issue_admin_pin' !== $action || '_woonyx_nonce' !== $name || ! $this->nonce_valid ) {
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
		Functions\when( 'get_current_user_id' )->justReturn( 5 );
		Functions\when( 'admin_url' )->alias( fn( string $path ): string => 'https://shop.example.com/wp-admin/' . $path );
		Functions\when( 'set_transient' )->alias(
			function ( string $name, $value ): bool {
				$this->transients[ $name ] = $value;
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

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	private function handler(): AdminPinHandler {
		return new AdminPinHandler(
			function ( bool $revoke_admins ): ?CoreError {
				$this->issued[] = $revoke_admins;
				return $this->result;
			}
		);
	}

	/**
	 * Runs the handler up to its redirect.
	 */
	private function press(): void {
		try {
			$this->handler()->handle();
			self::fail( 'handle() returned instead of redirecting.' );
		} catch ( \RuntimeException $e ) {
			self::assertSame( 'redirect', $e->getMessage() );
		}
	}

	/**
	 * The notice waiting for user 5.
	 *
	 * @return array<string, mixed>
	 */
	private function notice(): array {
		$notice = $this->transients['woonyx_notice_5'] ?? null;
		self::assertIsArray( $notice );

		return $notice;
	}

	public function test_register_hooks_the_admin_post_action(): void {
		$handler = $this->handler();

		$handler->register();

		self::assertNotFalse( has_action( 'admin_post_woonyx_issue_admin_pin', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->issued );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->issued );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_new_pin(): void {
		$this->press();

		self::assertSame( array( false ), $this->issued );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( AdminPinHandler::ISSUED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_new_pin_and_log_out_every_admin(): void {
		$_POST['woonyx_revoke_admins'] = '1';

		$this->press();

		self::assertSame( array( true ), $this->issued );
		self::assertSame( AdminPinHandler::ISSUED_REVOKED, $this->notice()['code'] );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function other_values(): array {
		return array(
			'zero'  => array( '0' ),
			'empty' => array( '' ),
			'true'  => array( 'true' ),
			'array' => array( array( '1' ) ),
			'1 sp'  => array( '1 ' ),
		);
	}

	#[DataProvider( 'other_values' )]
	public function test_only_the_exact_flag_revokes( mixed $value ): void {
		$_POST['woonyx_revoke_admins'] = $value;

		$this->press();

		self::assertSame( array( false ), $this->issued );
		self::assertSame( AdminPinHandler::ISSUED, $this->notice()['code'] );
	}

	public function test_a_failure_redirects_with_its_error(): void {
		$this->result = new CoreError( CoreError::PIN_NOT_SAVED, 200, 'req-0001-abcd' );

		$this->press();

		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( CoreError::PIN_NOT_SAVED, $this->notice()['code'] );
		self::assertSame( 'req-0001-abcd', $this->notice()['request_id'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}
}
