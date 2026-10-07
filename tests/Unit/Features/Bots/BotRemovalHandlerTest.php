<?php
/**
 * Tests for the bots' "Remove" button's handler.
 *
 * @package WooNyx
 */

declare(strict_types=1);

namespace WooNyx\Tests\Unit\Features\Bots;

use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use WooNyx\Features\Bots\BotRemovalHandler;
use WooNyx\Shared\Http\CoreError;
use WooNyx\Tests\Unit\TestCase;

/**
 * Capability and nonce first, then the platform check, the removal, the notice and the redirect.
 *
 * The stubbed wp_safe_redirect() throws, so handle() stops before its exit.
 */
final class BotRemovalHandlerTest extends TestCase {

	private const SETTINGS_URL = 'https://shop.example.com/wp-admin/admin.php?page=woonyx';

	/**
	 * In-memory transients: name => value.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Platforms the remove callable was called with.
	 *
	 * @var list<string>
	 */
	private array $removals = array();

	/**
	 * What the remove callable returns.
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

		$_POST = array( 'woonyx_platform' => 'telegram' );

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'current_user_can' )->alias( fn( string $capability ): bool => 'manage_woocommerce' === $capability && $this->can_manage );
		Functions\when( 'check_admin_referer' )->alias(
			function ( string $action, string $name ): int {
				if ( 'woonyx_remove_bot' !== $action || '_woonyx_nonce' !== $name || ! $this->nonce_valid ) {
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

	private function handler(): BotRemovalHandler {
		return new BotRemovalHandler(
			function ( string $platform ): ?CoreError {
				$this->removals[] = $platform;
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

		self::assertNotFalse( has_action( 'admin_post_woonyx_remove_bot', array( $handler, 'handle' ) ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		$this->can_manage = false;

		$this->expectExceptionMessage( 'wp_die' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->removals );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_without_a_valid_nonce_nothing_happens(): void {
		$this->nonce_valid = false;

		$this->expectExceptionMessage( 'nonce' );

		try {
			$this->handler()->handle();
		} finally {
			self::assertSame( array(), $this->removals );
			self::assertSame( array(), $this->transients );
		}
	}

	public function test_a_removal_redirects_with_a_success_notice(): void {
		$_POST['woonyx_platform'] = 'rubika';

		$this->press();

		self::assertSame( array( 'rubika' ), $this->removals );
		self::assertSame( 'success', $this->notice()['type'] );
		self::assertSame( BotRemovalHandler::REMOVED, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	public function test_a_failed_removal_redirects_with_its_error(): void {
		$this->result = new CoreError( 'bot_not_found', 404, 'req-0001-abcd' );

		$this->press();

		self::assertSame( array( 'telegram' ), $this->removals );
		self::assertSame( 'error', $this->notice()['type'] );
		self::assertSame( 'bot_not_found', $this->notice()['code'] );
		self::assertSame( 404, $this->notice()['status'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function bad_platforms(): array {
		return array(
			'missing' => array( null ),
			'unknown' => array( 'eitaa' ),
			'array'   => array( array( 'telegram' ) ),
			'path'    => array( 'telegram/../admins/7' ),
		);
	}

	#[DataProvider( 'bad_platforms' )]
	public function test_an_unknown_platform_sends_nothing( mixed $platform ): void {
		if ( null === $platform ) {
			unset( $_POST['woonyx_platform'] );
		} else {
			$_POST['woonyx_platform'] = $platform;
		}

		$this->press();

		self::assertSame( array(), $this->removals );
		self::assertSame( CoreError::INVALID_PAYLOAD, $this->notice()['code'] );
		self::assertSame( self::SETTINGS_URL, $this->redirect );
	}
}
